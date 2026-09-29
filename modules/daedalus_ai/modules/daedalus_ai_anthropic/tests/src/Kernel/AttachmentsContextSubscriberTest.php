<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_anthropic\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai_anthropic\EventSubscriber\AttachmentsContextSubscriber;
use Drupal\daedalus_ai_anthropic\EventSubscriber\DesignPostureContextSubscriber;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * The ATTACHMENTS block: this turn's files, named once, delivered once.
 *
 * The subscriber is built over the real attachment store (core's file
 * usage, the dialect alias map over shared tempstore) with only system,
 * user and file enabled, the way AttachmentsTest builds it; the agent is
 * the sibling AnthropicContextSubscriberTest's mock with a runner id, the
 * thread the store keys on. Three contracts are pinned: the block's shape
 * and each kind's delivery (a small textual file inlined, a large one
 * deferred to view_file, a raster image on the message as an ImageFile, a
 * PDF as a DocumentFile); a name the store does not answer for this user
 * and thread is ignored with a logged notice, never resolved by guessing;
 * and the block is never stripped from an earlier turn, unlike the
 * per-turn posture block beside it.
 */
#[CoversClass(AttachmentsContextSubscriber::class)]
#[Group('daedalus_ai')]
#[Group('daedalus_ai_anthropic')]
#[RunTestsInSeparateProcesses]
final class AttachmentsContextSubscriberTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file'];

  /**
   * The store the subscriber resolves through.
   */
  private Attachments $attachments;

  /**
   * The user attaching.
   */
  private UserInterface $owner;

  /**
   * The notices the subscriber logged, interpolated.
   *
   * @var list<string>
   */
  private array $logged = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user']);
    $this->owner = $this->setUpCurrentUser(['name' => 'owner']);
    $this->attachments = new Attachments(
      $this->container->get('file.usage'),
      new DialectAliasMap($this->container->get('tempstore.shared')),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * The block names every file and delivers each by its kind, once.
   */
  public function testBlockShapeAndDeliveryByKind(): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    $png = "\x89PNG\r\n\x1a\nfake";
    $pdf = '%PDF-1.4 fake';
    $csv = str_repeat("a,b,c\n", (int) ceil((AttachmentsContextSubscriber::INLINE_CEILING + 1) / 6));
    $this->attach('hero.svg', 'image/svg+xml', $svg, 'thread-a');
    $this->attach('photo.png', 'image/png', $png, 'thread-a');
    $this->attach('brief.pdf', 'application/pdf', $pdf, 'thread-a');
    $this->attach('big.csv', 'text/csv', $csv, 'thread-a');
    $this->attach('clip.mp3', 'audio/mpeg', 'not audio', 'thread-a');

    // The posture subscriber runs first (15 above 12), so the attachments
    // block lands outside it: attachments, posture, the user's words.
    $message = new ChatMessage('user', 'Build this.');
    $names = ['hero.svg', 'photo.png', 'brief.pdf', 'big.csv', 'clip.mp3'];
    (new DesignPostureContextSubscriber())->onPassContext($this->event($this->agent($message, 'thread-a'), ['design_posture' => 'on_brand']));
    $subscriber = $this->subscriber();
    $subscriber->onPassContext($this->event($this->agent($message, 'thread-a'), ['attachments' => $names]));

    $text = $message->getText();
    $this->assertStringStartsWith("<system-reminder>\nATTACHMENTS: the user attached the following file(s) to this message.", $text);
    $this->assertStringContainsString('view_file("<name>") re-opens it later.', $text);
    $this->assertLessThan(strpos($text, 'DESIGN POSTURE'), strpos($text, 'ATTACHMENTS'), 'The attachments block lies outside the posture block.');
    $this->assertStringEndsWith("</system-reminder>\n\nBuild this.", $text);

    // A small textual file is inlined in a named fence.
    $this->assertStringContainsString(
      "- File 1: hero.svg (image/svg+xml, " . strlen($svg) . " bytes), inlined:\n<file name=\"hero.svg\">\n" . $svg . "\n</file>\n",
      $text,
    );
    // A raster image rides the message as an ImageFile, named in the block.
    $this->assertStringContainsString("- File 2: photo.png (image/png, " . strlen($png) . " bytes): the image is on this message.\n", $text);
    // A PDF rides as a DocumentFile and the block says it is not re-openable.
    $this->assertStringContainsString("- File 3: brief.pdf (application/pdf, " . strlen($pdf) . " bytes): the document is on this message; it cannot be re-opened later, so read what you need now.\n", $text);
    // A textual file over the ceiling is deferred to view_file, not inlined.
    $this->assertStringContainsString(
      sprintf("- File 4: big.csv (text/csv, %s): larger than the 64 KB inline ceiling; read it with view_file(\"big.csv\").\n", ByteSizeMarkup::create(strlen($csv))),
      $text,
    );
    $this->assertStringNotContainsString('a,b,c', $text, 'A file over the ceiling is never inlined.');
    // An unreadable kind is named and not delivered.
    $this->assertStringContainsString("- File 5: clip.mp3 (audio/mpeg, 9 bytes): not a kind you can read.\n</system-reminder>", $text);
    $this->assertStringNotContainsString('not audio', $text);

    $images = array_values($message->getImages());
    $this->assertCount(1, $images);
    $this->assertInstanceOf(ImageFile::class, $images[0]);
    $this->assertSame($png, $images[0]->getBinary());
    $this->assertSame('image/png', $images[0]->getMimeType());
    $this->assertSame('photo.png', $images[0]->getFilename());
    $documents = array_values(array_filter($message->getFiles(), fn ($file) => $file instanceof DocumentFile));
    $this->assertCount(1, $documents);
    $this->assertSame($pdf, $documents[0]->getBinary());
    $this->assertSame('application/pdf', $documents[0]->getMimeType());
    $this->assertSame('brief.pdf', $documents[0]->getFilename());
    $this->assertCount(2, $message->getFiles(), 'Only the image and the PDF ride the message as files.');
    $this->assertSame([], $this->logged, 'Every name resolved; nothing to notice.');

    // The guard: a second dispatch on the same message (a continuation leg
    // re-sending the contexts) neither stacks a block nor re-delivers bytes.
    $subscriber->onPassContext($this->event($this->agent($message, 'thread-a'), ['attachments' => $names]));
    $this->assertSame(1, substr_count($message->getText(), 'ATTACHMENTS'));
    $this->assertCount(2, $message->getFiles());
    $this->assertSame($text, $message->getText());

    // No attachments, or none as a list: a no-op without a notice.
    $bare = new ChatMessage('user', 'Hello.');
    $this->subscriber()->onPassContext($this->event($this->agent($bare, 'thread-a'), []));
    $this->subscriber()->onPassContext($this->event($this->agent($bare, 'thread-a'), ['attachments' => []]));
    $this->subscriber()->onPassContext($this->event($this->agent($bare, 'thread-a'), ['attachments' => 'hero.svg']));
    $this->assertSame('Hello.', $bare->getText());
    $this->assertSame([], $bare->getFiles());
    $this->assertSame([], $this->logged);
  }

  /**
   * A name the store does not answer is ignored with a notice, never guessed.
   */
  public function testUnknownNamesAreIgnoredWithNotice(): void {
    $this->attach('hero.svg', 'image/svg+xml', '<svg/>', 'thread-a');
    $other = $this->createUser([], 'other');
    $this->container->get('current_user')->setAccount($other);
    $this->attach('theirs.svg', 'image/svg+xml', '<svg id="theirs"/>', 'thread-a');
    $this->container->get('current_user')->setAccount($this->owner);

    // An unknown name, another thread's name, another user's file, and a
    // non-string entry: each a notice, the message untouched.
    $message = new ChatMessage('user', 'Use it.');
    $this->subscriber()->onPassContext($this->event($this->agent($message, 'thread-a'), ['attachments' => ['missing.svg']]));
    $this->subscriber()->onPassContext($this->event($this->agent($message, 'thread-b'), ['attachments' => ['hero.svg']]));
    $this->subscriber()->onPassContext($this->event($this->agent($message, 'thread-a'), ['attachments' => ['theirs.svg']]));
    $this->subscriber()->onPassContext($this->event($this->agent($message, 'thread-a'), ['attachments' => [7]]));
    $this->assertSame('Use it.', $message->getText());
    $this->assertSame([], $message->getFiles());
    $this->assertSame([
      'Attachment missing.svg is not a file the current user holds in thread thread-a; ignored.',
      'Attachment hero.svg is not a file the current user holds in thread thread-b; ignored.',
      'Attachment theirs.svg is not a file the current user holds in thread thread-a; ignored.',
      'Attachment 7 is not a file the current user holds in thread thread-a; ignored.',
    ], $this->logged);

    // A run without a thread id resolves nothing.
    $this->logged = [];
    $this->subscriber()->onPassContext($this->event($this->agent($message, NULL), ['attachments' => ['hero.svg']]));
    $this->assertSame('Use it.', $message->getText());
    $this->assertSame(['Attachments hero.svg arrived on a run without a thread id; ignored.'], $this->logged);

    // A known name beside an unknown one: the known file is File 1, the
    // unknown is noticed.
    $this->logged = [];
    $this->subscriber()->onPassContext($this->event(
      $this->agent($message, 'thread-a'),
      ['attachments' => ['ghost.png', 'hero.svg']],
    ));
    $this->assertStringContainsString("- File 1: hero.svg (image/svg+xml, 6 bytes), inlined:\n<file name=\"hero.svg\">\n<svg/>\n</file>", $message->getText());
    $this->assertStringNotContainsString('File 2', $message->getText());
    $this->assertStringNotContainsString('theirs', $message->getText());
    $this->assertSame(['Attachment ghost.png is not a file the current user holds in thread thread-a; ignored.'], $this->logged);
  }

  /**
   * An earlier turn keeps its block while the per-turn posture block goes.
   */
  public function testEarlierBlocksSurviveLaterTurns(): void {
    $svg = '<svg id="hero"/>';
    $png = "\x89PNG fake";
    $this->attach('hero.svg', 'image/svg+xml', $svg, 'thread-a');
    $this->attach('photo.png', 'image/png', $png, 'thread-a');

    // Turn one: the posture and the attachment land on the first message.
    $first = new ChatMessage('user', 'Place the logo.');
    (new DesignPostureContextSubscriber())->onPassContext($this->event($this->agent($first, 'thread-a'), ['design_posture' => 'workshopping']));
    $this->subscriber()->onPassContext($this->event($this->agent($first, 'thread-a'), ['attachments' => ['hero.svg']]));
    $first_text = $first->getText();
    $this->assertStringContainsString('DESIGN POSTURE: workshopping.', $first_text);
    $this->assertStringContainsString("<file name=\"hero.svg\">\n" . $svg . "\n</file>", $first_text);

    // Turn two, restored from history with a new attachment: the posture
    // subscriber sheds turn one's block, the attachments subscriber sheds
    // nothing, and the new message carries its own block and image.
    $reply = new ChatMessage('assistant', 'Placed.');
    $second = new ChatMessage('user', 'Now the photo.');
    $history = [$first, $reply, $second];
    (new DesignPostureContextSubscriber())->onPassContext($this->event($this->agentWithHistory($history, 'thread-a'), ['design_posture' => 'on_brand']));
    $this->subscriber()->onPassContext($this->event($this->agentWithHistory($history, 'thread-a'), ['attachments' => ['photo.png']]));

    $this->assertStringNotContainsString('DESIGN POSTURE', $first->getText(), 'The per-turn posture block left the earlier turn.');
    $this->assertStringStartsWith("<system-reminder>\nATTACHMENTS", $first->getText(), 'The attachments block stayed on the earlier turn.');
    $this->assertStringContainsString("- File 1: hero.svg (image/svg+xml, " . strlen($svg) . " bytes), inlined:\n<file name=\"hero.svg\">\n" . $svg . "\n</file>", $first->getText());
    $this->assertStringEndsWith("</system-reminder>\n\nPlace the logo.", $first->getText());
    $this->assertSame('Placed.', $reply->getText());
    $this->assertStringContainsString('- File 1: photo.png (image/png, ' . strlen($png) . ' bytes): the image is on this message.', $second->getText());
    $this->assertStringNotContainsString('hero.svg', $second->getText(), 'The new turn names only its own files.');
    $this->assertStringEndsWith('Now the photo.', $second->getText());
    $this->assertCount(1, $second->getImages());
    $this->assertSame([], $first->getFiles());
    $transcript = implode("\n", array_map(fn (ChatMessage $m) => $m->getText(), $history));
    $this->assertSame(2, substr_count($transcript, "<system-reminder>\nATTACHMENTS"), 'Each turn keeps the block for its own files.');
    $this->assertSame(1, substr_count($transcript, 'DESIGN POSTURE'), 'The posture block is still one live copy.');

    // Turn three, no attachments: nothing is stripped, the bare message stays
    // bare.
    $third = new ChatMessage('user', 'Thanks.');
    $history = [
      $first,
      $reply,
      $second,
      new ChatMessage('assistant', 'Done.'),
      $third,
    ];
    $this->subscriber()->onPassContext($this->event($this->agentWithHistory($history, 'thread-a'), []));
    $this->assertStringStartsWith("<system-reminder>\nATTACHMENTS", $first->getText());
    $this->assertStringContainsString('photo.png', $second->getText());
    $this->assertSame('Thanks.', $third->getText());
    $this->assertSame([], $this->logged);
  }

  /**
   * Builds a fresh subscriber over the store and the collecting logger.
   */
  private function subscriber(): AttachmentsContextSubscriber {
    $test = $this;
    $logger = new class($test) extends AbstractLogger {

      public function __construct(private AttachmentsContextSubscriberTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->test->record((string) $level, (string) $message, $context);
      }

    };
    return new AttachmentsContextSubscriber($this->attachments, $logger);
  }

  /**
   * Records a logged notice, interpolated the way the channel would print it.
   */
  public function record(string $level, string $message, array $context): void {
    $this->assertSame('notice', $level, 'The subscriber only ever notices.');
    $replacements = [];
    foreach ($context as $key => $value) {
      $replacements[$key] = (string) $value;
    }
    $this->logged[] = strtr($message, $replacements);
  }

  /**
   * Saves a file with the given bytes and holds it for a thread.
   */
  private function attach(string $name, string $mime, string $bytes, string $thread): FileInterface {
    $uri = 'public://' . $name;
    file_put_contents($uri, $bytes);
    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => $mime,
      'uid' => $this->container->get('current_user')->id(),
      'status' => 0,
    ]);
    $file->save();
    $this->attachments->attach($file, $thread);
    return $file;
  }

  /**
   * A first-turn agent: empty history, the user message on the input.
   */
  private function agent(ChatMessage $user_message, ?string $runner_id): ConfigAiAgentInterface {
    $agent = $this->createMock(ConfigAiAgentInterface::class);
    $agent->method('getRunnerId')->willReturn($runner_id);
    $agent->method('getChatHistory')->willReturn([]);
    $agent->method('getChatInput')->willReturn(new ChatInput([
      new ChatMessage('system', 'System.'),
      $user_message,
    ]));
    return $agent;
  }

  /**
   * A restored agent: the persisted history, the new user message last.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The chat history.
   * @param string $runner_id
   *   The thread id the runner stamped.
   */
  private function agentWithHistory(array $history, string $runner_id): ConfigAiAgentInterface {
    $agent = $this->createMock(ConfigAiAgentInterface::class);
    $agent->method('getRunnerId')->willReturn($runner_id);
    $agent->method('getChatHistory')->willReturn($history);
    $agent->method('getChatInput')->willReturn(new ChatInput([]));
    return $agent;
  }

  /**
   * Builds the pass-context event.
   */
  private function event(ConfigAiAgentInterface $agent, array $context): AiAssistantPassContextToAgentEvent {
    return new AiAssistantPassContextToAgentEvent($agent, $context);
  }

}
