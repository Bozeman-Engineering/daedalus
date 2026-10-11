<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_anthropic\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_ai\ElementSelectionResolver;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_ai_anthropic\EventSubscriber\CurrentEntityContextSubscriber;
use Drupal\daedalus_ai_anthropic\EventSubscriber\CurrentPageMarkupContextSubscriber;
use Drupal\daedalus_ai_anthropic\EventSubscriber\DesignPostureContextSubscriber;
use Drupal\daedalus_ai_anthropic\EventSubscriber\SelectedElementContextSubscriber;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the Anthropic context subscribers: the selection-scope safety invariant.
 *
 * The first two subscribers are what stops the AI from rewriting the whole
 * page when the user selected one element. They format the model-neutral page/
 * selection context (captured elsewhere) into Anthropic-flavoured prompt text:
 *
 * - CurrentEntityContextSubscriber prepends a "Current page" <system-reminder>
 *   to the last user message (onPassContext) and appends the Selection Scope +
 *   Context Tags protocol to the system prompt (onBuildSystemPrompt). The
 *   protocol is the behavioral contract that makes the model respect
 *   selections; losing it is a silent safety regression.
 * - SelectedElementContextSubscriber prepends a numbered "ACTIVE SELECTION"
 *   <system-reminder> describing the resolved elements.
 * - CurrentPageMarkupContextSubscriber prepends the routed page's
 *   page-language print (HostPrinter's read of its staged host) so the
 *   model's first write needs no read of the current page; a page holding
 *   anything the language cannot spell prints whole, its reading notes
 *   heading the document once.
 * - DesignPostureContextSubscriber prepends a "DESIGN POSTURE"
 *   <system-reminder>
 *   naming the user's declared posture (on brand or workshopping, the design
 *   skill's own words) from the `design_posture` context; every turn carries
 *   one in both states so a flip back to on brand lands as a signal, and an
 *   absent or unknown value reads as on brand.
 *
 * Two invariants are load-bearing and shared with the model-neutral resolvers
 * (ContextResolverTest):
 * - ACCESS: an entity the current user cannot view must never surface in the
 *   prompt. CurrentEntityContextSubscriber leans on RouteEntityResolver (a
 *   private entity resolves to NULL, so only the non-entity view_mode is
 *   injected); SelectedElementContextSubscriber leans on
 *   ElementSelectionResolver (with no viewable root entity, spine keys fall
 *   back to key-vocabulary descriptions that carry no entity metadata — the
 *   resolver never loads entities from client-supplied identifiers).
 * - DOUBLE-INJECTION GUARD: each subscriber marks its reminder and refuses to
 *   inject twice, so a re-dispatched event does not stack duplicate context.
 * - ONE LIVE BLOCK: the print and the selection are per-turn facts; on a
 *   later turn each subscriber strips its block from every earlier user
 *   message in the persisted history before injecting the current one.
 *
 * The subscribers are built directly from a fresh RouteEntityResolver /
 * ElementSelectionResolver per scenario (element_resolver NULL, the realistic
 * CI fallback), mirroring the sibling ContextResolverTest and
 * TokenPropagationSubscriberTest. daedalus_ai / daedalus_ai_anthropic are NOT enabled;
 * their subscriber, trait, and the ai/ai_agents/ai_assistant_api value objects
 * all autoload via Drupal's PHPUnit bootstrap (only services need enabling).
 *
 * The agent fixture reproduces the production first-turn state: at the moment
 * AgentRunner dispatches the pass-context event the chat history is still empty
 * and the user message lives on the ChatInput (set via setChatInput before
 * determineSolvability populates history). The trait checks history (empty),
 * then finds and mutates the message on the input. The cross-turn fixture
 * (agentWithHistory) reproduces the memory runner's restore: the persisted
 * history, injected blocks included, with the new user message appended.
 *
 * @group daedalus_ai
 * @group daedalus_ai_anthropic
 */
#[Group('daedalus_ai')]
#[Group('daedalus_ai_anthropic')]
#[RunTestsInSeparateProcesses]
class AnthropicContextSubscriberTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
    // RouteEntityResolver delegates to daedalus_ui' derivation spine, so
    // daedalus_ui and its kernel-test dependency chain (see
    // DaedalusAiKernelTestBase for the chain's rationale) are required.
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'file',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['filter', 'text']);

    // Routes must be built so path.validator can resolve /entity_test/{id} to
    // the entity.entity_test.canonical route the resolvers key on.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * CurrentEntityContextSubscriber::onPassContext: inject, access net, guard.
   *
   * One method walks the message-injection contract: a viewable routed entity
   * yields a full "Current page" reminder (with the user's original text
   * preserved); an unviewable entity leaks nothing but keeps the non-entity
   * view mode; a second dispatch does not stack a duplicate; and an empty
   * context is a no-op.
   */
  public function testCurrentEntityInjectionAccessNetAndGuard(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();

    // Full context: the "Current page" reminder is prepended and the user's
    // own text is preserved after it.
    $message = new ChatMessage('user', 'Change this heading.');
    $subscriber = $this->currentEntitySubscriber();
    $subscriber->onPassContext($this->contextEvent(
      $this->agent($message),
      ['view_mode' => 'teaser', 'current_route' => $path],
    ));
    $text = $message->getText();
    $this->assertStringContainsString('<system-reminder>', $text);
    $this->assertStringContainsString(sprintf(
      'Current page: entity_test (ID: %s, UUID: %s, type: entity_test, title: "Routed Page").',
      $entity->id(),
      $entity->uuid(),
    ), $text);
    $this->assertStringContainsString('View mode: "teaser".', $text);
    $this->assertStringEndsWith('Change this heading.', $text);

    // Double-injection guard: re-dispatching the same event on the same message
    // does not stack a second reminder.
    $subscriber->onPassContext($this->contextEvent(
      $this->agentReusing($message),
      ['view_mode' => 'teaser', 'current_route' => $path],
    ));
    $this->assertSame(1, substr_count($message->getText(), 'Current page:'), 'The current-page reminder is injected at most once.');

    // Access net: a restricted user cannot view the entity, so the private
    // label never reaches the prompt; the non-entity view mode still does.
    $private = $this->createEntity('Secret Page');
    $this->setUpCurrentUser([], []);
    $denied_message = new ChatMessage('user', 'Update it.');
    $this->currentEntitySubscriber()->onPassContext($this->contextEvent(
      $this->agent($denied_message),
      ['view_mode' => 'teaser', 'current_route' => '/entity_test/' . $private->id()],
    ));
    $denied_text = $denied_message->getText();
    $this->assertStringNotContainsString('Current page:', $denied_text, 'An unviewable entity is never surfaced.');
    $this->assertStringNotContainsString('Secret Page', $denied_text);
    $this->assertStringContainsString('View mode: "teaser".', $denied_text, 'The non-entity view mode still survives.');

    // Empty context: no view mode, no route -> the message is untouched.
    $empty_message = new ChatMessage('user', 'Just chatting.');
    $this->currentEntitySubscriber()->onPassContext($this->contextEvent(
      $this->agent($empty_message),
      [],
    ));
    $this->assertSame('Just chatting.', $empty_message->getText(), 'An empty context is a no-op.');
  }

  /**
   * A template route names the template, prints it, and gates on access.
   *
   * Core's manage-display layout route (field_ui) derives a display host:
   * the "Current page" reminder names the template and what editing it
   * reaches, the page print rides headed as a template, and a user who
   * cannot administer the display gets neither.
   */
  public function testTemplateRouteNamesTheTemplate(): void {
    $this->enableModules(['field_ui']);
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $this->container->get('router.builder')->rebuild();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $path = $this->container->get('daedalus_ui.ui')->getEditingUrl($display)->toString();

    $message = new ChatMessage('user', 'Add a hero.');
    $this->currentEntitySubscriber()->onPassContext($this->contextEvent(
      $this->agent($message),
      ['view_mode' => 'default', 'current_route' => $path],
    ));
    $text = $message->getText();
    $this->assertStringContainsString(sprintf(
      'Current page: the default template for entity_test entity_tests (a template, not a page: entity_view_display ID: entity_test.entity_test.default, UUID: %s). Editing it changes every entity_test entity_test without a layout of its own; its field blocks show sample content, never a real entity\'s.',
      $display->uuid(),
    ), $text);
    $this->assertStringEndsWith('Add a hero.', $text);

    $print_message = new ChatMessage('user', 'Add a hero.');
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent(
      $this->agent($print_message),
      ['current_route' => $path],
    ));
    $print_text = $print_message->getText();
    $this->assertStringContainsString("<system-reminder>\nCurrent page print (the template in the page language; field blocks show sample content):", $print_text);
    $this->assertStringContainsString('(the template is empty: no layout content yet)', $print_text);

    // Access net: viewing entities is not administering their display.
    $this->setUpCurrentUser([], ['view test entity']);
    $denied = new ChatMessage('user', 'Change it.');
    $this->currentEntitySubscriber()->onPassContext($this->contextEvent(
      $this->agent($denied),
      ['current_route' => $path],
    ));
    $this->assertStringNotContainsString('Current page:', $denied->getText(), 'A template the user cannot administer is never surfaced.');
  }

  /**
   * A page rendering its template says so on the current-page line.
   *
   * The root verdict RouteEntityResolver::describe() carries explains a
   * host-level refusal in advance: a page of a non-overridable bundle
   * names the template its layout belongs to and where it is edited.
   */
  public function testPlainHostLineStatesTheTemplate(): void {
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $entity = $this->createEntity('Plain Page');

    $message = new ChatMessage('user', 'Add a hero.');
    $this->currentEntitySubscriber()->onPassContext($this->contextEvent(
      $this->agent($message),
      ['current_route' => '/entity_test/' . $entity->id()],
    ));
    $text = $message->getText();
    $identity = sprintf('entity_test (ID: %s, UUID: %s, type: entity_test, title: "Plain Page")', $entity->id(), $entity->uuid());
    $line = 'Current page: ' . $identity . '.'
      . ' The layout of this entity_test belongs to the template entity_view_display entity_test.entity_test.default, which it renders but does not own.'
      . ' Edit this entity_test\'s own fields here; edit its layout on the template: run the operation against entity_view_display entity_test.entity_test.default.';
    $this->assertStringContainsString($line, $text);
    $this->assertStringEndsWith('Add a hero.', $text);
  }

  /**
   * CurrentEntityContextSubscriber::onBuildSystemPrompt: scope + loop strip.
   *
   * The Selection Scope + Context Tags protocol is the safety contract. It is
   * appended when context was captured this request OR inherited from the
   * event's tokens (the sub-agent path), and omitted entirely when there is no
   * context. The loop-count notice is stripped unconditionally.
   */
  public function testSelectionScopeProtocolAndLoopStrip(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');

    // Captured context this request -> the protocol is appended after the
    // agent's own prompt.
    $captured = $this->currentEntitySubscriber();
    $captured->onPassContext($this->contextEvent(
      $this->agent(new ChatMessage('user', 'Hi.')),
      ['view_mode' => 'default', 'current_route' => '/entity_test/' . $entity->id()],
    ));
    $prompt = $this->promptEvent('You are the page builder agent.');
    $captured->onBuildSystemPrompt($prompt);
    $result = $prompt->getSystemPrompt();
    $this->assertStringContainsString('## Selection Scope', $result);
    $this->assertStringContainsString('## Context Tags', $result);
    $this->assertStringContainsString('ACTIVE SELECTION', $result);
    $this->assertStringContainsString('eb_read_schema', $result, 'The protocol names the real tool, not a stale alias.');
    $this->assertStringContainsString("Violating scope destroys the user's work.", $result);
    $this->assertGreaterThan(
      strpos($result, 'You are the page builder agent.'),
      strpos($result, '## Selection Scope'),
      'The scope protocol is appended after the agent identity.',
    );

    // Inherited context: a fresh subscriber captured nothing, but the event
    // carries tokens (the sub-agent prompt-build path) -> still appended.
    $inherited = $this->currentEntitySubscriber();
    $inherited->onPassContext($this->contextEvent($this->agent(new ChatMessage('user', 'Hi.')), []));
    $inherited_prompt = $this->promptEvent('Base.', [
      'current_entity_context' => ['entity_type' => 'node', 'entity_id' => 7],
      'current_view_mode' => 'full',
    ]);
    $inherited->onBuildSystemPrompt($inherited_prompt);
    $this->assertStringContainsString('## Selection Scope', $inherited_prompt->getSystemPrompt(), 'Token-carried context still triggers the protocol.');

    // No context at all: the protocol is omitted, but the loop-count notice is
    // still stripped (it runs before the context guard, on every turn).
    $noop = $this->currentEntitySubscriber();
    $noop->onPassContext($this->contextEvent($this->agent(new ChatMessage('user', 'Hi.')), []));
    $noop_prompt = $this->promptEvent("You are an agent.\n\nThis is the 3rd time that this agent has been run.\n");
    $noop->onBuildSystemPrompt($noop_prompt);
    $stripped = $noop_prompt->getSystemPrompt();
    $this->assertStringNotContainsString('## Selection Scope', $stripped, 'No context -> no protocol.');
    $this->assertStringNotContainsString('time that this agent has been run', $stripped, 'The loop-count notice is stripped.');
    $this->assertSame('You are an agent.', $stripped);
  }

  /**
   * SelectedElementContextSubscriber: inject, access net, guard, no-op.
   *
   * The numbered selection block is prepended for resolved spine keys; when
   * the routed entity is unviewable the resolver falls back to key-vocabulary
   * descriptions that leak no entity metadata (the safety net); a re-dispatch
   * does not duplicate; an empty or all-unresolvable selection list is a
   * no-op.
   */
  public function testSelectedElementInjectionAccessNetAndGuard(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();
    $block_uuid = '11111111-1111-1111-1111-111111111111';

    // Viewable block + root field selection -> a numbered selection block.
    $message = new ChatMessage('user', 'Make this about dogs.');
    $subscriber = $this->selectedElementSubscriber();
    $selections = [
      ['key' => 'block:' . $block_uuid, 'label' => 'Block', 'number' => 1],
      ['key' => 'field:' . $entity->uuid() . ':name', 'label' => 'Name', 'number' => 2],
      // The block's link part targeted (the chips' wire: the owner key
      // plus the target's selector): the AI sees the address.
      ['key' => 'block:' . $block_uuid, 'selector' => '.text-block-link', 'label' => 'Block › every link', 'number' => 3],
    ];
    $subscriber->onPassContext($this->contextEvent(
      $this->agent($message),
      ['current_route' => $path, 'selected_elements' => $selections],
    ));
    $text = $message->getText();
    $this->assertStringContainsString("ACTIVE SELECTION: the user has selected the following element(s)", $text);
    $this->assertStringContainsString('- Element 1: Block (UUID: ' . $block_uuid . ')', $text);
    $this->assertStringContainsString('Routed Page', $text, 'The viewable root entity contextualizes the block fallback.');
    $this->assertStringContainsString('- Element 2: Field "name" on this page', $text);
    $this->assertStringContainsString("- Element 3: Block (UUID: $block_uuid)", $text);
    $this->assertStringContainsString("\n  Styling target: the rule \".text-block-link\" under it (style-map key block:$block_uuid::.text-block-link): styling acts on every element the rule matches, not on this element alone.\n", $text, 'A targeted part shows the AI the address styling acts at.');
    $this->assertStringEndsWith('Make this about dogs.', $text);

    // Double-injection guard.
    $subscriber->onPassContext($this->contextEvent(
      $this->agentReusing($message),
      ['current_route' => $path, 'selected_elements' => $selections],
    ));
    $this->assertSame(1, substr_count($message->getText(), 'ACTIVE SELECTION'), 'The selection block is injected at most once.');

    // Access net: the restricted user cannot view the routed entity, so the
    // root resolves to NULL and every description degrades to the client-
    // supplied key vocabulary — the private label never reaches the prompt.
    $private = $this->createEntity('Secret Page');
    $this->setUpCurrentUser([], []);
    $denied_message = new ChatMessage('user', 'Change this.');
    $this->selectedElementSubscriber()->onPassContext($this->contextEvent(
      $this->agent($denied_message),
      [
        'current_route' => '/entity_test/' . $private->id(),
        'selected_elements' => [
          ['key' => 'field:' . $private->uuid() . ':name', 'label' => 'Name', 'number' => 1],
        ],
      ],
    ));
    $denied_text = $denied_message->getText();
    $this->assertStringNotContainsString('Secret Page', $denied_text, 'An unviewable entity leaks no metadata into the prompt.');
    $this->assertStringContainsString('- Element 1: Field "name" (parent UUID: ' . $private->uuid() . ')', $denied_text, 'The description carries the client-supplied key parts only.');

    // A selection list that resolves to nothing (unknown key vocabulary) is
    // a no-op, as is an empty list.
    $this->setUpCurrentUser([], [], TRUE);
    $bogus_message = new ChatMessage('user', 'Hello.');
    $this->selectedElementSubscriber()->onPassContext($this->contextEvent(
      $this->agent($bogus_message),
      ['current_route' => $path, 'selected_elements' => [['key' => 'bogus', 'number' => 1]]],
    ));
    $this->assertSame('Hello.', $bogus_message->getText(), 'An all-unresolvable selection list is a no-op.');

    $empty_message = new ChatMessage('user', 'Hello.');
    $this->selectedElementSubscriber()->onPassContext($this->contextEvent(
      $this->agent($empty_message),
      ['current_route' => $path, 'selected_elements' => []],
    ));
    $this->assertSame('Hello.', $empty_message->getText(), 'No selections is a no-op.');
  }

  /**
   * SelectedElementContextSubscriber renders wear and placement provenance.
   *
   * The resolver's per-element facts (`materials`, `placed_from`) render as
   * their own indented lines under the element in a fixed order (Wears,
   * Placed from), each only when present: the render contract the model
   * reads. The Wears line carries the resolver's clauses verbatim, one per
   * wearing with its origin (a wearing applied here with its spelling, one
   * a composition distributed "via" it), never values. No Guidance line:
   * what an element kind is for is the brief's and the schema's to teach
   * once, never the selection block's per turn. A canned resolver keeps
   * the line shapes the subject; the facts' derivation (the stack read
   * behind the clauses) is ContextResolverTest's.
   */
  public function testSelectedElementRendersMaterialsAndProvenance(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $resolver = new class($this->container->get('daedalus_blueprint.host_resolver')) extends ElementSelectionResolver {

      /**
       * {@inheritdoc}
       */
      public function resolve(array $selectedElements, ?HostInterface $host): array {
        return [
          1 => [
            'description' => 'Section (UUID: s1)',
            'materials' => 'container_lg "Large (1024px)" (width="lg"); row "Row" via card_left "Card Left"',
            'placed_from' => 'hero "Hero"',
          ],
          2 => ['description' => 'Block (UUID: b1)'],
          3 => [
            'description' => 'Section (UUID: s2)',
            'target' => 'the rule "a" under it (style-map key container:s2::a): styling acts on every element the rule matches, not on this element alone',
            'materials' => 'link "Link" (class="link")',
          ],
        ];
      }

    };
    $subscriber = new SelectedElementContextSubscriber($this->routeResolver(), $resolver);
    $message = new ChatMessage('user', 'Update the blueprint this came from.');
    $subscriber->onPassContext($this->contextEvent($this->agent($message), [
      'selected_elements' => [
        ['key' => 'container:s1', 'number' => 1],
        ['key' => 'block:b1', 'number' => 2],
        ['key' => 'container:s2', 'selector' => 'a', 'number' => 3],
      ],
    ]));
    $this->assertStringContainsString(
      "- Element 1: Section (UUID: s1)\n"
      . "  Wears: container_lg \"Large (1024px)\" (width=\"lg\"); row \"Row\" via card_left \"Card Left\"\n"
      . "  Placed from: hero \"Hero\"\n"
      . "- Element 2: Block (UUID: b1)\n"
      . "- Element 3: Section (UUID: s2)\n"
      . "  Styling target: the rule \"a\" under it (style-map key container:s2::a): styling acts on every element the rule matches, not on this element alone.\n"
      . "  Wears: link \"Link\" (class=\"link\")\n",
      $message->getText(),
    );
    $this->assertSame(2, substr_count($message->getText(), 'Wears:'), 'An element without materials renders no Wears line.');
    $this->assertSame(1, substr_count($message->getText(), 'Styling target:'), 'An element without a target renders no Styling target line.');
    $this->assertSame(1, substr_count($message->getText(), 'Placed from:'), 'An element without provenance renders no Placed from line.');
    $this->assertStringNotContainsString('Guidance:', $message->getText(), 'The selection block carries no Guidance line: the brief and the schema teach elements.');
  }

  /**
   * A later turn holds exactly one print block and one selection block.
   *
   * The memory runner restores the persisted history (turn one's user
   * message with the blocks injected on it, the assistant's reply) and
   * appends the new user message before dispatching pass-context. Each
   * subscriber strips its own block from every earlier user message, then
   * injects the current one on the last; the user's words on the earlier
   * turn survive, the assistant's message is untouched, and a turn that
   * carries neither a route nor a selection still sheds the stale blocks.
   */
  public function testLaterTurnsHoldOneLivePrintAndSelection(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();
    $selections = [
      ['key' => 'field:' . $entity->uuid() . ':name', 'label' => 'Name', 'number' => 1],
    ];
    $context = ['current_route' => $path, 'selected_elements' => $selections];

    // Turn one: both blocks land on the first user message.
    $first = new ChatMessage('user', 'Make this about dogs.');
    $this->selectedElementSubscriber()->onPassContext($this->contextEvent($this->agent($first), $context));
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent($this->agent($first), $context));
    $this->assertSame(1, substr_count($first->getText(), 'Current page print'));
    $this->assertSame(1, substr_count($first->getText(), 'ACTIVE SELECTION'));

    // Turn two: the restored history carries turn one as injected.
    $reply = new ChatMessage('assistant', 'Done.');
    $second = new ChatMessage('user', 'Now the cats.');
    $history = [$first, $reply, $second];
    $this->selectedElementSubscriber()->onPassContext($this->contextEvent($this->agentWithHistory($history), $context));
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent($this->agentWithHistory($history), $context));
    $transcript = implode("\n", array_map(fn (ChatMessage $m) => $m->getText(), $history));
    $this->assertSame(1, substr_count($transcript, 'Current page print'), 'The history holds exactly one print block.');
    $this->assertSame(1, substr_count($transcript, 'ACTIVE SELECTION'), 'The history holds exactly one selection block.');
    $this->assertSame('Make this about dogs.', $first->getText(), 'The earlier turn keeps the user\'s words and nothing else.');
    $this->assertSame('Done.', $reply->getText(), 'The assistant message is untouched.');
    $this->assertStringContainsString('Current page print', $second->getText(), 'The live print rides the last user message.');
    $this->assertStringContainsString('ACTIVE SELECTION', $second->getText(), 'The live selection rides the last user message.');
    $this->assertStringEndsWith('Now the cats.', $second->getText());

    // Turn three, with no route and no selection: the stale blocks still go
    // and the bare message stays bare.
    $third = new ChatMessage('user', 'Thanks.');
    $history = [$second, new ChatMessage('assistant', 'Cats it is.'), $third];
    $this->selectedElementSubscriber()->onPassContext($this->contextEvent($this->agentWithHistory($history), []));
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent($this->agentWithHistory($history), []));
    $this->assertSame('Now the cats.', $second->getText(), 'A turn without a selection or a route still strips the stale blocks.');
    $this->assertSame('Thanks.', $third->getText());
  }

  /**
   * CurrentPageMarkupContextSubscriber: inject, access net, guard, no-op.
   *
   * The routed page's dialect print is the live page-language projection
   * (NodeTreeBuilder + DialectPrinter over the generated catalog); an
   * entity with no layout injects the honest empty-page note rather than
   * nothing, so the model never mistakes a bare page for missing context.
   * The access net rides RouteEntityResolver: an unviewable page injects
   * nothing at all (its content must never reach the prompt), and a
   * message that already carries the print is never double-injected.
   */
  public function testCurrentPageMarkupInjectionAccessNetAndGuard(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();

    // Routed, viewable page: the dialect print block is prepended and the
    // user's own text is preserved after it.
    $message = new ChatMessage('user', 'Restyle the hero.');
    $subscriber = $this->pageMarkupSubscriber();
    $subscriber->onPassContext($this->contextEvent(
      $this->agent($message),
      ['current_route' => $path],
    ));
    $text = $message->getText();
    $this->assertStringContainsString("<system-reminder>\nCurrent page print", $text);
    $this->assertStringContainsString('(the page is empty: no layout content yet)', $text, 'A layoutless page prints the honest empty-page note.');
    $this->assertStringEndsWith('Restyle the hero.', $text);

    // Double-injection guard.
    $subscriber->onPassContext($this->contextEvent(
      $this->agentReusing($message),
      ['current_route' => $path],
    ));
    $this->assertSame(1, substr_count($message->getText(), 'Current page print'), 'The page print is injected at most once per message.');

    // Access net: an unviewable page injects nothing, so its content never
    // reaches the prompt.
    $private = $this->createEntity('Secret Page');
    $this->setUpCurrentUser([], []);
    $denied_message = new ChatMessage('user', 'Change this.');
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent(
      $this->agent($denied_message),
      ['current_route' => '/entity_test/' . $private->id()],
    ));
    $this->assertSame('Change this.', $denied_message->getText(), 'An unviewable page injects no markup.');

    // No route in context: a no-op.
    $bare_message = new ChatMessage('user', 'Hello.');
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent(
      $this->agent($bare_message),
      [],
    ));
    $this->assertSame('Hello.', $bare_message->getText(), 'No routed page is a no-op.');
  }

  /**
   * A page the language cannot spell whole prints whole, its notes first.
   *
   * A two-column section (a layout the site refuses to create, so built
   * on the storage) holding an undeclared block whose multi-value field
   * has no spelling. The per-turn block is the header, then the print as
   * the model reads it: the reading notes once (the layout sentence
   * teaching at: {ref, position, region} and both refusal codes, the
   * unprinted-field sentence), a blank line, then the document with its
   * read-only facts (data-layout and data-regions on the section,
   * data-region and data-unprinted on the block). There is no fallback
   * and no error: the read is total.
   */
  public function testUnspellablePagePrintsWholeWithReadingNotes(): void {
    $this->enableModules(['block', 'block_content']);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'card', 'label' => 'Card', 'revision' => TRUE])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_card_items',
      'entity_type' => 'block_content',
      'type' => 'string',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_card_items',
      'entity_type' => 'block_content',
      'bundle' => 'card',
      'label' => 'Items',
    ])->save();
    $card = BlockContent::create(['type' => 'card', 'info' => 'Card', 'reusable' => FALSE, 'field_card_items' => ['Plans', 'Pricing']]);
    $card_uuid = '7a1e2b3c-0000-4000-8000-000000000021';
    $section = new Section('layout_twocol_section', [], [
      new SectionComponent($card_uuid, 'first', [
        'id' => 'inline_block:card',
        'provider' => 'layout_builder',
        'label' => '',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_serialized' => serialize($card),
        'block_revision_id' => NULL,
      ]),
    ], ['daedalus_layout' => ['uuid' => $this->container->get('uuid')->generate()]]);
    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')->create([
      'name' => 'Two columns',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $message = new ChatMessage('user', 'Fill the right column.');
    $this->pageMarkupSubscriber()->onPassContext($this->contextEvent(
      $this->agent($message),
      ['current_route' => '/entity_test/' . $entity->id()],
    ));
    $text = $message->getText();
    $this->assertStringContainsString("<system-reminder>\nCurrent page print (the page in the page language):\n\nReading notes:\n- A <pb-container> carrying data-layout is a section on that layout rather than the container", $text, 'The reading notes head the print, right under the header.');
    $this->assertStringContainsString('An insert or move into such a section names the region as at: {ref, position, region}; afterbegin or beforeend on it without a region is refused (REGION_REQUIRED), a region anywhere else is refused too (REGION_NOT_APPLICABLE), and nothing creates such a section.', $text, 'The layout sentence teaches the region address and both refusals.');
    $this->assertStringContainsString("\n- data-unprinted names fields the element holds that the page language cannot spell: a set on the element keeps them, a replace rebuilds the element and loses them.\n\n<pb-container", $text, 'The unprinted-field sentence closes the notes; a blank line separates them from the document.');
    $this->assertStringNotContainsString('data-partial names', $text, 'No partial field, no partial-field sentence.');
    $this->assertSame(1, substr_count($text, 'Reading notes:'), 'The notes head the print once.');
    $this->assertMatchesRegularExpression('/\n\n<pb-container( id="[^"]*")?( data-can="[^"]*")? data-layout="layout_twocol_section" data-regions="first second">\n  <pb-block id="' . preg_quote($card_uuid, '/') . '"( data-can="[^"]*")? data-region="first" data-unprinted="field_card_items" type="card" \/>\n<\/pb-container>\n\n<\/system-reminder>\n\nFill the right column\.$/', $text, 'The document prints whole with its read-only facts, and the user\'s words follow.');
    $this->assertStringNotContainsString('Plans', $text, 'The unspellable value is named, never printed.');
    $this->assertStringNotContainsString('Error', $text);
  }

  /**
   * DesignPostureContextSubscriber: texts, absent default, guard, one live.
   *
   * The posture block is the one per-turn block that is never a no-op: on
   * brand and workshopping each render their own text in the skill's
   * vocabulary; an absent or unknown `design_posture` reads as on brand
   * (the skill's default) rather than as no block; a re-dispatch does not
   * stack a duplicate; and across turns the history holds exactly one
   * posture block, the last user message's, so a flip from workshopping
   * back to on brand lands as a declaration instead of silence.
   */
  public function testDesignPostureInjectionDefaultAndGuard(): void {
    // On brand: the toggle-on text, the user's words preserved after it.
    $on_brand = new ChatMessage('user', 'Build a landing page.');
    $subscriber = $this->designPostureSubscriber();
    $subscriber->onPassContext($this->contextEvent(
      $this->agent($on_brand),
      ['design_posture' => 'on_brand'],
    ));
    $on_brand_text = $on_brand->getText();
    $this->assertStringStartsWith("<system-reminder>\nDESIGN POSTURE: on brand.", $on_brand_text);
    $this->assertStringContainsString("The user's On brand styling toggle is on: the design system is the boundary", $on_brand_text);
    $this->assertStringNotContainsString('workshopping', $on_brand_text);
    $this->assertStringEndsWith("</system-reminder>\n\nBuild a landing page.", $on_brand_text);

    // Workshopping: the toggle-off text.
    $workshopping = new ChatMessage('user', 'Try a different direction.');
    $this->designPostureSubscriber()->onPassContext($this->contextEvent(
      $this->agent($workshopping),
      ['design_posture' => 'workshopping'],
    ));
    $workshopping_text = $workshopping->getText();
    $this->assertStringStartsWith("<system-reminder>\nDESIGN POSTURE: workshopping.", $workshopping_text);
    $this->assertStringContainsString('The user turned On brand styling off: the materials are reference, not boundary, and new styling is on the table', $workshopping_text);
    $this->assertStringNotContainsString('on brand.', $workshopping_text);
    $this->assertStringEndsWith('Try a different direction.', $workshopping_text);

    // Absent context and an unknown value both default to on brand: a block
    // is injected every turn, never skipped.
    $absent = new ChatMessage('user', 'Hello.');
    $this->designPostureSubscriber()->onPassContext($this->contextEvent($this->agent($absent), []));
    $this->assertStringStartsWith("<system-reminder>\nDESIGN POSTURE: on brand.", $absent->getText(), 'An absent posture reads as on brand.');
    $this->assertStringEndsWith('Hello.', $absent->getText());

    $unknown = new ChatMessage('user', 'Hello.');
    $this->designPostureSubscriber()->onPassContext($this->contextEvent(
      $this->agent($unknown),
      ['design_posture' => 'off_brand'],
    ));
    $this->assertStringStartsWith("<system-reminder>\nDESIGN POSTURE: on brand.", $unknown->getText(), 'An unknown posture value reads as on brand.');

    // Double-injection guard: re-dispatching on the same message does not
    // stack a second block, even with the other posture.
    $subscriber->onPassContext($this->contextEvent(
      $this->agentReusing($on_brand),
      ['design_posture' => 'workshopping'],
    ));
    $this->assertSame(1, substr_count($on_brand->getText(), 'DESIGN POSTURE'), 'The posture block is injected at most once per message.');
    $this->assertSame($on_brand_text, $on_brand->getText(), 'The guard leaves the first injection as it was.');

    // Two turns: turn one was workshopping; turn two flips back to on brand.
    // The restored history sheds turn one's block and the on-brand
    // declaration rides the last user message, so the flip is a signal.
    $first = new ChatMessage('user', 'Workshop the hero.');
    $this->designPostureSubscriber()->onPassContext($this->contextEvent(
      $this->agent($first),
      ['design_posture' => 'workshopping'],
    ));
    $this->assertStringContainsString('DESIGN POSTURE: workshopping.', $first->getText());

    $reply = new ChatMessage('assistant', 'Here is a bold take.');
    $second = new ChatMessage('user', 'Back on brand now.');
    $history = [$first, $reply, $second];
    $this->designPostureSubscriber()->onPassContext($this->contextEvent(
      $this->agentWithHistory($history),
      ['design_posture' => 'on_brand'],
    ));
    $transcript = implode("\n", array_map(fn (ChatMessage $m) => $m->getText(), $history));
    $this->assertSame(1, substr_count($transcript, 'DESIGN POSTURE'), 'The history holds exactly one posture block.');
    $this->assertSame('Workshop the hero.', $first->getText(), 'The earlier turn keeps the user\'s words and nothing else.');
    $this->assertSame('Here is a bold take.', $reply->getText(), 'The assistant message is untouched.');
    $this->assertStringStartsWith("<system-reminder>\nDESIGN POSTURE: on brand.", $second->getText(), 'The flip back to on brand lands as a declaration on the last user message.');
    $this->assertStringEndsWith('Back on brand now.', $second->getText());
  }

  /**
   * Creates and saves an entity_test entity.
   *
   * @param string $name
   *   The entity label.
   *
   * @return \Drupal\entity_test\Entity\EntityTest
   *   The saved entity.
   */
  private function createEntity(string $name) {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => $name]);
    $entity->save();
    return $entity;
  }

  /**
   * Builds a fresh CurrentEntityContextSubscriber.
   *
   * A new instance per scenario isolates the subscriber's request-scoped
   * captured state ($currentEntity / $viewMode).
   *
   * @return \Drupal\daedalus_ai_anthropic\EventSubscriber\CurrentEntityContextSubscriber
   *   The subscriber wired to a fresh route resolver.
   */
  private function currentEntitySubscriber(): CurrentEntityContextSubscriber {
    return new CurrentEntityContextSubscriber($this->routeResolver());
  }

  /**
   * Builds a fresh SelectedElementContextSubscriber.
   *
   * @return \Drupal\daedalus_ai_anthropic\EventSubscriber\SelectedElementContextSubscriber
   *   The subscriber wired to fresh resolvers (element_resolver NULL fallback).
   */
  private function selectedElementSubscriber(): SelectedElementContextSubscriber {
    return new SelectedElementContextSubscriber(
      $this->routeResolver(),
      new ElementSelectionResolver($this->container->get('daedalus_blueprint.host_resolver'), NULL),
    );
  }

  /**
   * Builds a fresh CurrentPageMarkupContextSubscriber.
   *
   * The host printer is the container's real one (the generated catalog,
   * the node tree builder, the stamper, the printer behind it), so the
   * scenario exercises the live page-language print, not a fixture.
   *
   * @return \Drupal\daedalus_ai_anthropic\EventSubscriber\CurrentPageMarkupContextSubscriber
   *   The subscriber wired to a fresh route resolver.
   */
  private function pageMarkupSubscriber(): CurrentPageMarkupContextSubscriber {
    return new CurrentPageMarkupContextSubscriber(
      $this->routeResolver(),
      $this->container->get('daedalus_blueprint.storage_handler'),
      $this->container->get('daedalus_blueprint.host_printer'),
    );
  }

  /**
   * Builds a fresh DesignPostureContextSubscriber.
   *
   * The subscriber has no dependencies: the posture is read straight off
   * the event's context and rendered as text.
   *
   * @return \Drupal\daedalus_ai_anthropic\EventSubscriber\DesignPostureContextSubscriber
   *   The subscriber.
   */
  private function designPostureSubscriber(): DesignPostureContextSubscriber {
    return new DesignPostureContextSubscriber();
  }

  /**
   * Builds a fresh RouteEntityResolver (fresh per-request cache).
   *
   * @return \Drupal\daedalus_ai\RouteEntityResolver
   *   A resolver reading the live current_user proxy for access.
   */
  private function routeResolver(): RouteEntityResolver {
    return new RouteEntityResolver(
      $this->container->get('path.validator'),
      $this->container->get('daedalus_blueprint.access_checker'),
      $this->container->get('router.no_access_checks'),
      $this->container->get('daedalus_ui.ui'),
      $this->container->get('daedalus_blueprint.verdict_stamper'),
    );
  }

  /**
   * Builds a config agent whose last user message is the given message.
   *
   * Reproduces the production first-turn state: chat history is empty at
   * pass-context time and the user message lives on the ChatInput. The trait
   * checks history (empty), then mutates the message on the input.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $userMessage
   *   The message the subscriber should prepend context to.
   *
   * @return \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface
   *   The mocked agent.
   */
  private function agent(ChatMessage $userMessage): ConfigAiAgentInterface {
    $agent = $this->createMock(ConfigAiAgentInterface::class);
    $agent->method('getChatHistory')->willReturn([]);
    $agent->method('getChatInput')->willReturn(new ChatInput([
      new ChatMessage('system', 'System.'),
      $userMessage,
    ]));
    return $agent;
  }

  /**
   * Builds a config agent restored mid-conversation with the given history.
   *
   * Reproduces the memory runner's cross-turn restore: the persisted history
   * (earlier user messages as injected, assistant replies) with the new user
   * message last. The trait finds the last user message in the history and
   * the stripping walks the earlier ones; the ChatInput is empty as it is
   * after a restore.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The chat history, the current user message last.
   *
   * @return \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface
   *   The mocked agent.
   */
  private function agentWithHistory(array $history): ConfigAiAgentInterface {
    $agent = $this->createMock(ConfigAiAgentInterface::class);
    $agent->method('getChatHistory')->willReturn($history);
    $agent->method('getChatInput')->willReturn(new ChatInput([]));
    return $agent;
  }

  /**
   * Builds a second agent reusing an already-mutated message (guard scenario).
   *
   * A fresh agent carrying the SAME (already-injected) message object, so the
   * double-injection guard reads the marker off the message text.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $userMessage
   *   The message from a prior injection.
   *
   * @return \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface
   *   The mocked agent.
   */
  private function agentReusing(ChatMessage $userMessage): ConfigAiAgentInterface {
    return $this->agent($userMessage);
  }

  /**
   * Builds an AiAssistantPassContextToAgentEvent.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent whose last user message will be mutated.
   * @param array $context
   *   The context array (view_mode / current_route / selected_elements).
   *
   * @return \Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent
   *   The event to hand to onPassContext().
   */
  private function contextEvent(ConfigAiAgentInterface $agent, array $context): AiAssistantPassContextToAgentEvent {
    return new AiAssistantPassContextToAgentEvent($agent, $context);
  }

  /**
   * Builds a BuildSystemPromptEvent.
   *
   * @param string $systemPrompt
   *   The starting system prompt.
   * @param array $tokens
   *   Pre-existing tokens (the inherited sub-agent context, if any).
   *
   * @return \Drupal\ai_agents\Event\BuildSystemPromptEvent
   *   The event to hand to onBuildSystemPrompt().
   */
  private function promptEvent(string $systemPrompt, array $tokens = []): BuildSystemPromptEvent {
    return new BuildSystemPromptEvent($systemPrompt, 'daedalus', $tokens);
  }


  /**
   * A blueprint route names the blueprint and what editing it reaches.
   *
   * The blueprint's canonical route is its canvas: the "Current page"
   * reminder says it is a saved blueprint, not a page, what it is for and
   * that editing it changes what every later placement lands, with its
   * field blocks the subject's.
   */
  public function testBlueprintRouteNamesTheBlueprint(): void {
    $this->enableModules(['options', 'image', 'media', 'daedalus_blueprint_library']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $this->container->get('router.builder')->rebuild();
    $blueprint = $this->container->get('entity_type.manager')->getStorage('blueprint')->create([
      'type' => 'section',
      'label' => 'Card',
      'machine_name' => 'card',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
    ]);
    $blueprint->save();
    $path = $blueprint->toUrl()->toString();

    $message = new ChatMessage('user', 'Add a link.');
    $this->currentEntitySubscriber()->onPassContext($this->contextEvent(
      $this->agent($message),
      ['view_mode' => 'full', 'current_route' => $path],
    ));
    $text = $message->getText();
    $this->assertStringContainsString(sprintf(
      'Current page: the block blueprint "Card" for entity_test entity_tests (a saved blueprint, not a page: blueprint ID: %s, UUID: %s). Editing it changes the blueprint every later placement lands; placements already on pages are independent copies. Its field blocks are the entity_test entity_test\'s fields and show sample content, never a real entity\'s.',
      $blueprint->id(),
      $blueprint->uuid(),
    ), $text);
    $this->assertStringEndsWith('Add a link.', $text);
  }

}
