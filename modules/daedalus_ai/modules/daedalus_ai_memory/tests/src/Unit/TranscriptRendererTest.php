<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\ai\Service\HostnameFilter;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Context\ContextBlocksEvent;
use Drupal\daedalus_ai\Provider\CacheBreakpointsInterface;
use Drupal\daedalus_ai\Provider\MidConversationSystemInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\Rendering;
use Drupal\daedalus_ai_memory\Transcript\ToolCall;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_ai_memory\Transcript\TranscriptRenderer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the renderer per capability set: floor, breakpoints only, full.
 *
 * The same segment renders to the same roles, texts and tool calls at the
 * floor and with breakpoints only (the plan is the only difference), and
 * at the full set the per-turn blocks stay text on the user message (the
 * one form, on every provider), the wrapper's reminder stays a system
 * message in place, and the assistant turns' opaque content rides the plan
 * by index, only under the provider and model pair that produced it. What
 * is load-bearing: a provider that declares nothing gets a request every
 * provider accepts, nothing a producer stated is lost at any tier, and a
 * model switch never replays a signature another model signed.
 */
#[Group('daedalus_ai_memory')]
class TranscriptRendererTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // ToolsPropertyResult filters every string argument through the ai
    // module's hostname filter from its constructor; an identity filter
    // keeps the rendered arguments what the transcript holds.
    $filter = $this->createMock(HostnameFilter::class);
    $filter->method('filterText')->willReturnArgument(0);
    $container = new ContainerBuilder();
    $container->set('ai.hostname_filter_service', $filter);
    \Drupal::setContainer($container);
  }

  /**
   * The floor: text everywhere, an empty plan.
   */
  public function testFloor(): void {
    $rendering = (new TranscriptRenderer())->render(self::transcript(), new \stdClass(), 'any-model', RenderingPlan::TTL_1H);

    $this->assertTrue($rendering->plan()->isEmpty(), 'A provider declaring nothing is asked for nothing.');
    $this->assertFloorMessages($rendering);
  }

  /**
   * Breakpoints only: the floor's messages, a plan with the breakpoints.
   *
   * The TTL asked for is honored when the model supports it, else the
   * first supported one; the breakpoints are the prefix and the tail.
   */
  public function testBreakpointsOnly(): void {
    $provider = new class() implements CacheBreakpointsInterface {

      /**
       * {@inheritdoc}
       */
      public function supportedCacheTtls(string $model_id): array {
        return $model_id === 'long-cache' ? [RenderingPlan::TTL_5M, RenderingPlan::TTL_1H] : [RenderingPlan::TTL_5M];
      }

    };
    $renderer = new TranscriptRenderer();

    $rendering = $renderer->render(self::transcript(), $provider, 'short-cache', RenderingPlan::TTL_1H);
    $this->assertFloorMessages($rendering);
    $this->assertSame(RenderingPlan::TTL_5M, $rendering->plan()->prefixTtl(), 'A TTL the model does not honor falls back to one it does.');
    $this->assertSame([7 => RenderingPlan::TTL_5M], $rendering->plan()->breakpoints(), 'One breakpoint, on the last rendered message.');
    $this->assertSame([], $rendering->plan()->opaque());

    $rendering = $renderer->render(self::transcript(), $provider, 'long-cache', RenderingPlan::TTL_1H);
    $this->assertSame(RenderingPlan::TTL_1H, $rendering->plan()->prefixTtl());
    $this->assertSame([7 => RenderingPlan::TTL_1H], $rendering->plan()->breakpoints());

    $floor = (new TranscriptRenderer())->render(self::transcript(), new \stdClass(), 'short-cache');
    $this->assertSame(
      array_map(static fn (ChatMessage $m): array => $m->toArray(), $floor->messages()),
      array_map(static fn (ChatMessage $m): array => $m->toArray(), $rendering->messages()),
      'The messages are the floor\'s, byte for byte; only the plan differs.',
    );
  }

  /**
   * The full set: blocks on the user message, system in place, opaque.
   */
  public function testFull(): void {
    $rendering = (new TranscriptRenderer())->render(self::transcript(), self::fullProvider(), 'claude-x', RenderingPlan::TTL_1H);
    $messages = $rendering->messages();

    $this->assertSame(
      ['user', 'assistant', 'tool', 'tool', 'user', 'assistant', 'system', 'assistant'],
      array_map(static fn (ChatMessage $m): string => $m->getRole(), $messages),
    );

    $floor = (new TranscriptRenderer())->render(self::transcript(), new \stdClass(), 'claude-x');
    $this->assertSame($floor->messages()[0]->getText(), $messages[0]->getText(), 'Every per-turn block is text on the user message at the full set as at the floor.');
    $this->assertSame('Reminder: use page_edit.', $messages[6]->getText(), 'The wrapper\'s reminder is a system message in place.');

    $plan = $rendering->plan();
    $this->assertSame([1 => ['sig' => 'one'], 7 => ['sig' => 'two']], $plan->opaque(), 'Opaque content rides by the assistant message\'s index.');
    $this->assertSame(RenderingPlan::TTL_1H, $plan->prefixTtl());
    $this->assertSame([7 => RenderingPlan::TTL_1H], $plan->breakpoints());

    $this->assertToolCalls($messages[1]);
    $this->assertSame('c1', $messages[2]->getToolsId());
    $this->assertSame('c2', $messages[3]->getToolsId());
  }

  /**
   * Opaque content is replayed only under the pair that produced it.
   *
   * The fixture's content was produced by "full" on "claude-x": another
   * model on the same provider, another provider on the same model, and
   * content with no pair recorded (a record from before the pair was kept)
   * all leave the plan without it, while the messages stay the same.
   */
  public function testOpaqueBoundToPair(): void {
    $renderer = new TranscriptRenderer();
    $bound = $renderer->render(self::transcript(), self::fullProvider(), 'claude-x');
    $this->assertSame([1 => ['sig' => 'one'], 7 => ['sig' => 'two']], $bound->plan()->opaque());

    $other_model = $renderer->render(self::transcript(), self::fullProvider(), 'claude-y');
    $this->assertSame([], $other_model->plan()->opaque(), 'Another model on the same provider replays nothing.');
    $this->assertSame(
      array_map(static fn (ChatMessage $m): array => $m->toArray(), $bound->messages()),
      array_map(static fn (ChatMessage $m): array => $m->toArray(), $other_model->messages()),
      'The messages are the same; only the plan withholds the content.',
    );

    $other_provider = $renderer->render(self::transcript(), self::fullProvider('other'), 'claude-x');
    $this->assertSame([], $other_provider->plan()->opaque(), 'Another provider on the same model replays nothing.');

    $transcript = new Transcript();
    $segment = $transcript->openSegment();
    $transcript->appendUserTurn('Make the hero bolder');
    $segment->append(Entry::assistant('thought', [], ['sig' => 'unbound']));
    $unbound = $renderer->render($transcript, self::fullProvider(), 'claude-x');
    $this->assertSame([], $unbound->plan()->opaque(), 'Content with no pair recorded is never replayed.');
    $this->assertSame(['user', 'assistant'], array_map(static fn (ChatMessage $m): string => $m->getRole(), $unbound->messages()));
  }

  /**
   * A segment opens with its carry-over, then the skills, then the turn.
   *
   * The turn's files land on the current user message and nowhere else,
   * at the floor and at the full set alike.
   */
  public function testSegmentOpenWithCarryOverAndFiles(): void {
    $transcript = self::transcript();
    $transcript->openSegment('The user wants a warm landing page; design was loaded.');
    $transcript->appendUserTurn('Continue with the footer', [
      new Block(BlockKind::Print, '<d-page>v2</d-page>'),
      new Block(BlockKind::Skills, '- design: the design skill'),
    ]);
    $image = new ImageFile('png!', 'image/png', 'hero.png');
    $renderer = new TranscriptRenderer();

    $floor = $renderer->render($transcript, new \stdClass(), 'any', RenderingPlan::TTL_5M, [$image]);
    $this->assertCount(1, $floor->messages(), 'The closed segment is not rendered.');
    $message = $floor->messages()[0];
    $this->assertSame('user', $message->getRole());
    $this->assertSame(
      TranscriptRenderer::blockText(new Block(BlockKind::CarryOver, 'The user wants a warm landing page; design was loaded.'))
      . "\n\n" . TranscriptRenderer::blockText(new Block(BlockKind::Skills, '- design: the design skill'))
      . "\n\n" . TranscriptRenderer::blockText(new Block(BlockKind::Print, '<d-page>v2</d-page>'))
      . "\n\nContinue with the footer",
      $message->getText(),
    );
    $this->assertSame([$image], $message->getFiles());
    $this->assertSame($message, $floor->last());

    $full = $renderer->render($transcript, self::fullProvider(), 'claude-x', RenderingPlan::TTL_5M, [$image]);
    $this->assertSame(['user'], array_map(static fn (ChatMessage $m): string => $m->getRole(), $full->messages()));
    $this->assertSame($message->getText(), $full->messages()[0]->getText(), 'The full set renders the same user message as the floor.');
    $this->assertSame([$image], $full->messages()[0]->getFiles());
    $this->assertSame([0 => RenderingPlan::TTL_5M], $full->plan()->breakpoints());
  }

  /**
   * The block text is the spine's wrapper and header over the body.
   */
  public function testBlockText(): void {
    $this->assertSame(
      "<system-reminder>\nCurrent page (as of this message):\nnode (ID: 12).\n</system-reminder>",
      TranscriptRenderer::blockText(new Block(BlockKind::CurrentPage, 'node (ID: 12).')),
    );
    foreach (BlockKind::cases() as $kind) {
      $text = TranscriptRenderer::blockText(new Block($kind, 'body'));
      $this->assertStringStartsWith("<system-reminder>\n", $text);
      $this->assertStringEndsWith("\nbody\n</system-reminder>", $text);
    }
  }

  /**
   * Refusals: files without a user turn, no open segment, a non-file.
   */
  public function testRefusals(): void {
    $renderer = new TranscriptRenderer();
    $transcript = new Transcript();
    try {
      $renderer->render($transcript, new \stdClass(), 'any');
      $this->fail('A transcript with no open segment must be refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('no open segment', $e->getMessage());
    }

    $transcript->openSegment();
    try {
      $renderer->render($transcript, new \stdClass(), 'any', RenderingPlan::TTL_5M, [new ImageFile('x', 'image/png', 'x.png')]);
      $this->fail('Files with no user turn to carry them must be refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('no user turn', $e->getMessage());
    }

    $this->expectException(\InvalidArgumentException::class);
    $renderer->render($transcript, new \stdClass(), 'any', RenderingPlan::TTL_5M, ['not a file']);
  }

  /**
   * The event keeps what producers state, in order, and the turn's facts.
   */
  public function testContextBlocksEvent(): void {
    $event = new ContextBlocksEvent(['current_route' => '/node/12', 'attachments' => ['hero.png']], 'runner-1', TRUE);
    $this->assertSame(['current_route' => '/node/12', 'attachments' => ['hero.png']], $event->context());
    $this->assertSame('runner-1', $event->conversationId());
    $this->assertTrue($event->opensSegment());
    $this->assertSame([], $event->blocks());
    $this->assertSame([], $event->files());

    $selection = new Block(BlockKind::Selection, '- Element 1: the hero');
    $print = new Block(BlockKind::Print, '<d-page/>');
    $file = new ImageFile('png!', 'image/png', 'hero.png');
    $event->addBlock($selection);
    $event->addBlock($print);
    $event->addFile($file);
    $this->assertSame([$selection, $print], $event->blocks(), 'Stated order is kept; the renderer orders by kind.');
    $this->assertSame([$file], $event->files());
    $this->assertSame(ContextBlocksEvent::EVENT_NAME, 'daedalus_ai.context_blocks');
  }

  /**
   * The floor's messages for self::transcript().
   */
  private function assertFloorMessages(Rendering $rendering): void {
    $messages = $rendering->messages();
    $this->assertSame(
      ['user', 'assistant', 'tool', 'tool', 'user', 'assistant', 'user', 'assistant'],
      array_map(static fn (ChatMessage $m): string => $m->getRole(), $messages),
      'A system entry renders as a user message at the floor; nothing else changes role.',
    );
    $this->assertSame(
      implode("\n\n", [
        TranscriptRenderer::blockText(new Block(BlockKind::Skills, '- design: the design skill')),
        TranscriptRenderer::blockText(new Block(BlockKind::CurrentPage, 'node (ID: 12).')),
        TranscriptRenderer::blockText(new Block(BlockKind::Print, '<d-page>...</d-page>')),
        TranscriptRenderer::blockText(new Block(BlockKind::Selection, '- Element 1: the hero')),
        TranscriptRenderer::blockText(new Block(BlockKind::Attachments, '- File 1: hero.png (image/png, 1 KB): the image is on this message.')),
        'Make the hero bolder',
      ]),
      $messages[0]->getText(),
      'Every block is text on the user message, in rendering order whatever order it was stated in, the user\'s words last.',
    );
    $this->assertSame([], $messages[0]->getFiles(), 'No bytes come from the transcript.');
    $this->assertToolCalls($messages[1]);
    $this->assertSame('schema text', $messages[2]->getText());
    $this->assertSame('c1', $messages[2]->getToolsId());
    $this->assertSame('c2', $messages[3]->getToolsId());
    $this->assertSame('Screenshot(s) from the tool results above:', $messages[4]->getText());
    $this->assertSame('I will edit.', $messages[5]->getText());
    $this->assertNull($messages[5]->getTools());
    $this->assertSame("<system-reminder>\nReminder: use page_edit.\n</system-reminder>", $messages[6]->getText());
    $this->assertSame('done', $messages[7]->getText());
    $this->assertSame($messages[7], $rendering->last());
  }

  /**
   * The assistant's calls are drupal/ai tool outputs with id, name, args.
   */
  private function assertToolCalls(ChatMessage $message): void {
    $this->assertSame('', $message->getText());
    $this->assertSame([
      [
        'id' => 'c1',
        'type' => 'function',
        'function' => ['name' => 'eb_read_schema', 'arguments' => '{"entity_type":"node","bundle":"page"}'],
      ],
      [
        'id' => 'c2',
        'type' => 'function',
        'function' => ['name' => 'eb_skill', 'arguments' => '{"skill":"design","args":""}'],
      ],
    ], $message->getRenderedTools());
  }

  /**
   * One segment of a conversation: a turn with blocks and two legs.
   *
   * The blocks are stated out of rendering order on purpose. The opaque
   * content was produced by the full provider ("full") on "claude-x".
   */
  private static function transcript(): Transcript {
    $transcript = new Transcript();
    $segment = $transcript->openSegment();
    $transcript->appendUserTurn('Make the hero bolder', [
      new Block(BlockKind::Attachments, '- File 1: hero.png (image/png, 1 KB): the image is on this message.'),
      new Block(BlockKind::Print, '<d-page>...</d-page>'),
      new Block(BlockKind::Selection, '- Element 1: the hero'),
      new Block(BlockKind::CurrentPage, 'node (ID: 12).'),
      new Block(BlockKind::Skills, '- design: the design skill'),
    ]);
    $segment->append(Entry::assistant('', [
      new ToolCall('c1', 'eb_read_schema', ['entity_type' => 'node', 'bundle' => 'page']),
      new ToolCall('c2', 'eb_skill', ['skill' => 'design', 'args' => '']),
    ], ['sig' => 'one'], 'full__claude-x'));
    $segment->append(Entry::toolResult('schema text', 'c1', 'schema:node.page'));
    $segment->append(Entry::toolResult('doctrine', 'c2', 'skill:design'));
    $segment->append(Entry::user('Screenshot(s) from the tool results above:', [], FALSE));
    $segment->append(Entry::assistant('I will edit.'));
    $segment->append(Entry::system('Reminder: use page_edit.'));
    $segment->append(Entry::assistant('done', [], ['sig' => 'two'], 'full__claude-x'));
    return $transcript;
  }

  /**
   * A provider plugin declaring every rendering capability.
   *
   * @param string $plugin_id
   *   The plugin id it answers, "full" by default.
   */
  private static function fullProvider(string $plugin_id = 'full'): object {
    return new class($plugin_id) implements CacheBreakpointsInterface, MidConversationSystemInterface, OpaqueContentInterface {

      public function __construct(private readonly string $pluginId) {
      }

      /**
       * The plugin id, as a provider plugin answers it.
       */
      public function getPluginId(): string {
        return $this->pluginId;
      }

      /**
       * {@inheritdoc}
       */
      public function supportedCacheTtls(string $model_id): array {
        return [RenderingPlan::TTL_5M, RenderingPlan::TTL_1H];
      }

      /**
       * {@inheritdoc}
       */
      public function supportsMidConversationSystem(string $model_id): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function opaqueContentOf(OutputInterface $output): ?array {
        return NULL;
      }

    };
  }

}
