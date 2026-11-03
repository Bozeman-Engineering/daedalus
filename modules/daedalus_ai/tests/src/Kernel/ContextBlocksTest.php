<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\Context\AttachmentsBlockProducer;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Context\ContextBlocksEvent;
use Drupal\daedalus_ai\Context\CurrentPageBlockProducer;
use Drupal\daedalus_ai\Context\PrintBlockProducer;
use Drupal\daedalus_ai\Context\SelectionBlockProducer;
use Drupal\daedalus_ai\ElementSelectionResolver;
use Drupal\daedalus_ai\EventSubscriber\ContextProtocolSubscriber;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_ai_memory\Transcript\TranscriptRenderer;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Drupal\daedalus_blueprint_ai\EventSubscriber\WorkflowSubscriber;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Pins the block producers: what each states on the turn's context event.
 *
 * The memory runner dispatches one ContextBlocksEvent per user turn with
 * the context the chat client sent; the producers answer with blocks of a
 * kind, bodies only, and the transcript renderer owns the header, the
 * <system-reminder> wrapper and the placement (TranscriptRendererTest pins
 * those). This class pins the bodies and the producers' refusals:
 *
 * - CurrentPageBlockProducer: the host line (a page, a template, a saved
 *   blueprint), the host-level refusals in advance, the view mode.
 * - PrintBlockProducer: the routed host's page-language print (HostPrinter's
 *   read of its staged host), a template headed by its note, an empty
 *   host's honest note, a page the language cannot spell printed whole
 *   with its reading notes first.
 * - SelectionBlockProducer: the numbered selection with each element's
 *   styling subject and address, what it wears and where it was placed
 *   from. The selection-scope safety invariant rests on this block and
 *   the protocol ContextProtocolSubscriber puts on the system prompt.
 * - AttachmentsBlockProducer: this turn's files named once and delivered
 *   once by kind (a small textual file inlined, a large one deferred to
 *   view_file, an image and a PDF as the turn's files), a name the store
 *   does not answer ignored with a notice, and the name stated for a
 *   lane-saved file being the lane's clean filename (the handle the
 *   client echoes), its original spelling not resolving.
 * - WorkflowSubscriber (daedalus_blueprint_ai): the skills catalog stated
 *   on the turn that opens a segment only, subscribed by the event's name,
 *   and the Workflow section on the system prompt.
 * - ContextProtocolSubscriber: the Selection Scope and Context Tags
 *   protocols appended when the event's tokens carry page context, the
 *   loop-count notice stripped always.
 *
 * Two invariants are load-bearing and shared with the model-neutral
 * resolvers (ContextResolverTest): ACCESS (an entity the current user
 * cannot view never surfaces: RouteEntityResolver answers NULL and
 * ElementSelectionResolver falls back to key-vocabulary descriptions that
 * carry no entity metadata), and that a turn with nothing to state states
 * nothing (no empty block).
 *
 * The producers are built directly from a fresh RouteEntityResolver /
 * ElementSelectionResolver per scenario (element_resolver NULL, the
 * realistic CI fallback), mirroring ContextResolverTest; daedalus_ai is NOT
 * enabled (its info.yml drags the AI stack), its classes and the ai value
 * objects autoload through core's PHPUnit bootstrap.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class ContextBlocksTest extends KernelTestBase {

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
    // RouteEntityResolver delegates to daedalus_ui's derivation spine, so
    // daedalus_ui and its kernel-test dependency chain (see
    // DaedalusAiKernelTestBase for the chain's rationale) are required.
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    // The attachment store holds a conversation's files on file.usage.
    'file',
    'daedalus_ui',
  ];

  /**
   * The notices the attachments producer logged, interpolated.
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
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user', 'filter', 'text']);

    // Routes must be built so path.validator can resolve /entity_test/{id} to
    // the entity.entity_test.canonical route the resolvers key on.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * CurrentPageBlockProducer: the host line, the access net, the no-op.
   *
   * A viewable routed entity yields the host line with the view mode; an
   * unviewable entity leaks nothing but keeps the non-entity view mode;
   * an empty context states nothing. The renderer's read of the block is
   * pinned once here, so the body and the Context Tags protocol's lead
   * words meet.
   */
  public function testCurrentPageBlockAccessNetAndNoop(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();

    $event = $this->dispatch($this->currentPageProducer(), ['view_mode' => 'teaser', 'current_route' => $path]);
    $block = $this->onlyBlock($event, BlockKind::CurrentPage);
    $this->assertSame(sprintf(
      'entity_test (ID: %s, UUID: %s, type: entity_test, title: "Routed Page"). View mode: "teaser".',
      $entity->id(),
      $entity->uuid(),
    ), $block->text);
    $this->assertSame(
      "<system-reminder>\nCurrent page (as of this message):\n" . $block->text . "\n</system-reminder>",
      TranscriptRenderer::blockText($block),
      'The renderer heads the body with the Current page tag the protocol names.',
    );

    // Access net: a restricted user cannot view the entity, so the private
    // label never reaches the model; the non-entity view mode still does.
    $private = $this->createEntity('Secret Page');
    $this->setUpCurrentUser([], []);
    $denied = $this->dispatch($this->currentPageProducer(), [
      'view_mode' => 'teaser',
      'current_route' => '/entity_test/' . $private->id(),
    ]);
    $this->assertSame('View mode: "teaser".', $this->onlyBlock($denied, BlockKind::CurrentPage)->text, 'An unviewable entity is never surfaced; the view mode survives.');

    // Empty context: no view mode, no route -> nothing stated.
    $empty = $this->dispatch($this->currentPageProducer(), []);
    $this->assertSame([], $empty->blocks(), 'An empty context states nothing.');
  }

  /**
   * A template route names the template, prints it headed, and gates.
   *
   * Core's manage-display layout route (field_ui) derives a display host:
   * the host line names the template and what editing it reaches, the
   * print's first line says it is a template whose field blocks show
   * sample content, and a user who cannot administer the display gets
   * neither.
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

    $event = $this->dispatch($this->currentPageProducer(), ['view_mode' => 'default', 'current_route' => $path]);
    $this->assertSame(sprintf(
      'the default template for entity_test entity_tests (a template, not a page: entity_view_display ID: entity_test.entity_test.default, UUID: %s). Editing it changes every entity_test entity_test without a layout of its own; its field blocks show sample content, never a real entity\'s. View mode: "default".',
      $display->uuid(),
    ), $this->onlyBlock($event, BlockKind::CurrentPage)->text);

    $print = $this->dispatch($this->printProducer(), ['current_route' => $path]);
    $this->assertSame(
      PrintBlockProducer::TEMPLATE_NOTE . "\n\n(the template is empty: no layout content yet)",
      $this->onlyBlock($print, BlockKind::Print)->text,
      'A template print opens with the template note and an empty template prints the honest note.',
    );

    // Access net: viewing entities is not administering their display.
    $this->setUpCurrentUser([], ['view test entity']);
    $denied = $this->dispatch($this->currentPageProducer(), ['current_route' => $path]);
    $this->assertSame([], $denied->blocks(), 'A template the user cannot administer is never surfaced.');
    $denied_print = $this->dispatch($this->printProducer(), ['current_route' => $path]);
    $this->assertSame([], $denied_print->blocks(), 'A template the user cannot administer is never printed.');
  }

  /**
   * A page rendering its template says so on the host line.
   *
   * The root verdict RouteEntityResolver::describe() carries explains a
   * host-level refusal in advance: a page of a non-overridable bundle
   * names the template its layout belongs to and where it is edited.
   */
  public function testHostLineStatesTheTemplate(): void {
    $this->setUpCurrentUser([], [], TRUE);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $entity = $this->createEntity('Plain Page');

    $event = $this->dispatch($this->currentPageProducer(), ['current_route' => '/entity_test/' . $entity->id()]);
    $identity = sprintf('entity_test (ID: %s, UUID: %s, type: entity_test, title: "Plain Page")', $entity->id(), $entity->uuid());
    $this->assertSame(
      $identity . '.'
      . ' The layout of this entity_test belongs to the template entity_view_display entity_test.entity_test.default, which it renders but does not own.'
      . ' Edit this entity_test\'s own fields here; edit its layout on the template: run the operation against entity_view_display entity_test.entity_test.default.',
      $this->onlyBlock($event, BlockKind::CurrentPage)->text,
    );
  }

  /**
   * A blueprint route names the blueprint and what editing it reaches.
   *
   * The blueprint's canonical route is its canvas: the host line says it
   * is a saved blueprint, not a page, what it is for and that editing it
   * changes what every later placement lands, with its field blocks the
   * subject's.
   */
  public function testBlueprintRouteNamesTheBlueprint(): void {
    $this->enableModules(['options', 'image', 'media', 'daedalus_blueprint_library']);
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

    $event = $this->dispatch($this->currentPageProducer(), [
      'view_mode' => 'full',
      'current_route' => $blueprint->toUrl()->toString(),
    ]);
    $this->assertSame(sprintf(
      'the block blueprint "Card" for entity_test entity_tests (a saved blueprint, not a page: blueprint ID: %s, UUID: %s). Editing it changes the blueprint every later placement lands; placements already on pages are independent copies. Its field blocks are the entity_test entity_test\'s fields and show sample content, never a real entity\'s. View mode: "full".',
      $blueprint->id(),
      $blueprint->uuid(),
    ), $this->onlyBlock($event, BlockKind::CurrentPage)->text);
  }

  /**
   * ContextProtocolSubscriber: the protocols by token, the loop strip always.
   *
   * The Selection Scope + Context Tags protocol is the safety contract. It
   * is appended after the agent's own prompt when the event's tokens carry
   * page context (TokenPropagationSubscriber sets them from what this
   * request captured or a sub-agent inherited), and omitted entirely
   * without; the loop-count notice is stripped unconditionally.
   */
  public function testSelectionScopeProtocolAndLoopStrip(): void {
    $subscriber = new ContextProtocolSubscriber();

    $prompt = new BuildSystemPromptEvent('You are the page builder agent.', 'daedalus', [
      'current_entity_context' => ['entity_type' => 'node', 'entity_id' => 7],
      'current_view_mode' => 'full',
    ]);
    $subscriber->onBuildSystemPrompt($prompt);
    $result = $prompt->getSystemPrompt();
    $this->assertSame('You are the page builder agent.' . "\n\n" . ContextProtocolSubscriber::PROTOCOLS, $result, 'The protocols follow the agent identity.');
    $this->assertStringContainsString('## Selection Scope', $result);
    $this->assertStringContainsString('## Context Tags', $result);
    $this->assertStringContainsString('"Current page" tag', $result);
    $this->assertStringContainsString('"Current page print" tag', $result);
    $this->assertStringContainsString('"ACTIVE SELECTION" tag', $result);
    $this->assertStringContainsString('eb_read_schema', $result, 'The protocol names the real tool, not a stale alias.');
    $this->assertStringContainsString("Violating scope destroys the user's work.", $result);
    $this->assertStringContainsString('The newest print in the conversation IS the page\'s current state', $result, 'Blocks accumulate: the protocol points at the newest print.');

    // A view mode alone (an unviewable entity's turn) still carries the
    // protocols: the tags it teaches ride that turn too.
    $view_mode_only = new BuildSystemPromptEvent('Base.', 'daedalus', ['current_view_mode' => 'full']);
    $subscriber->onBuildSystemPrompt($view_mode_only);
    $this->assertStringContainsString('## Selection Scope', $view_mode_only->getSystemPrompt());

    // No context at all: the protocols are omitted, the loop-count notice
    // still goes.
    $noop = new BuildSystemPromptEvent("You are an agent.\n\nThis is the 3rd time that this agent has been run.\n", 'daedalus', []);
    $subscriber->onBuildSystemPrompt($noop);
    $this->assertSame('You are an agent.', $noop->getSystemPrompt(), 'No context: no protocol, and the loop-count notice is stripped.');
  }

  /**
   * SelectionBlockProducer: the numbered list, the access net, the no-ops.
   *
   * The numbered selection is stated for resolved spine keys; when the
   * routed entity is unviewable the resolver falls back to key-vocabulary
   * descriptions that leak no entity metadata (the safety net); an empty
   * or all-unresolvable selection list states nothing.
   */
  public function testSelectionBlockAccessNetAndNoop(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();
    $block_uuid = '11111111-1111-1111-1111-111111111111';

    $selections = [
      ['key' => 'block:' . $block_uuid, 'label' => 'Block', 'number' => 1],
      ['key' => 'field:' . $entity->uuid() . ':name', 'label' => 'Name', 'number' => 2],
      // The block's link part targeted (the chips' wire: the owner key
      // plus the target's selector): the AI sees the address.
      [
        'key' => 'block:' . $block_uuid,
        'selector' => '.text-block-link',
        'label' => 'Block › every link',
        'number' => 3,
      ],
      // The two styling facts on one block: the same part as the subject
      // facet, written at the block's own rule (a same-key address): the
      // AI sees the subject line reading at the address line.
      [
        'key' => 'block:' . $block_uuid,
        'selector' => '.text-block-link',
        'address' => ['key' => 'block:' . $block_uuid, 'selector' => '.text-block-link'],
        'label' => 'Block › every link',
        'number' => 4,
      ],
    ];
    $event = $this->dispatch($this->selectionProducer(), ['current_route' => $path, 'selected_elements' => $selections]);
    $text = $this->onlyBlock($event, BlockKind::Selection)->text;
    $this->assertStringStartsWith('- Element 1: Block (UUID: ' . $block_uuid . ')', $text);
    $this->assertStringContainsString('Routed Page', $text, 'The viewable root entity contextualizes the block fallback.');
    $this->assertStringContainsString('- Element 2: Field "name" on this page', $text);
    $this->assertStringContainsString("- Element 3: Block (UUID: $block_uuid)", $text);
    $this->assertStringContainsString("- Element 4: Block (UUID: $block_uuid)", $text);
    $this->assertStringContainsString("\n  Styling subject: the rule \".text-block-link\" under it (style-map key block:$block_uuid::.text-block-link): styling acts on every element the rule matches, not on this element alone.\n", $text, 'A subject facet with no address shows the AI the rule styling acts at.');
    $this->assertStringEndsWith(
      "\n  Styling subject: the rule \".text-block-link\" under it (style-map key block:$block_uuid::.text-block-link): the elements whose styles the user is reading; styling acts at the address below, not here.\n"
      . "  Styling address: styling acts at the rule \".text-block-link\" on this element (style-map key block:$block_uuid::.text-block-link): every element it matches, this one included.",
      $text,
      'A subject facet with an address prints both facts: the subject reads, the address writes.',
    );
    $this->assertSame(1, substr_count($text, 'Styling address:'), 'Only the element carrying an address prints an address line.');

    // Access net: the restricted user cannot view the routed entity, so the
    // root resolves to NULL and every description degrades to the client-
    // supplied key vocabulary; the private label never reaches the model.
    $private = $this->createEntity('Secret Page');
    $this->setUpCurrentUser([], []);
    $denied = $this->dispatch($this->selectionProducer(), [
      'current_route' => '/entity_test/' . $private->id(),
      'selected_elements' => [
        ['key' => 'field:' . $private->uuid() . ':name', 'label' => 'Name', 'number' => 1],
      ],
    ]);
    $denied_text = $this->onlyBlock($denied, BlockKind::Selection)->text;
    $this->assertStringNotContainsString('Secret Page', $denied_text, 'An unviewable entity leaks no metadata.');
    $this->assertSame('- Element 1: Field "name" (parent UUID: ' . $private->uuid() . ')', $denied_text, 'The description carries the client-supplied key parts only.');

    // A selection list that resolves to nothing (unknown key vocabulary)
    // states nothing, as does an empty list.
    $this->setUpCurrentUser([], [], TRUE);
    $bogus = $this->dispatch($this->selectionProducer(), [
      'current_route' => $path,
      'selected_elements' => [['key' => 'bogus', 'number' => 1]],
    ]);
    $this->assertSame([], $bogus->blocks(), 'An all-unresolvable selection list states nothing.');
    $empty = $this->dispatch($this->selectionProducer(), ['current_route' => $path, 'selected_elements' => []]);
    $this->assertSame([], $empty->blocks(), 'No selections states nothing.');
  }

  /**
   * SelectionBlockProducer renders wear and placement provenance.
   *
   * The resolver's per-element facts (`materials`, `placed_from`) render as
   * their own indented lines under the element in a fixed order (Wears,
   * Placed from), each only when present: the render contract the model
   * reads. The Wears line carries the resolver's clauses verbatim, one per
   * wearing with its origin, never values. No Guidance line: what an
   * element kind is for is the brief's and the schema's to teach once. A
   * canned resolver keeps the line shapes the subject; the facts'
   * derivation is ContextResolverTest's.
   */
  public function testSelectionRendersMaterialsAndProvenance(): void {
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
    $event = $this->dispatch(new SelectionBlockProducer($this->routeResolver(), $resolver), [
      'selected_elements' => [
        ['key' => 'container:s1', 'number' => 1],
        ['key' => 'block:b1', 'number' => 2],
        ['key' => 'container:s2', 'selector' => 'a', 'number' => 3],
      ],
    ]);
    $this->assertSame(
      "- Element 1: Section (UUID: s1)\n"
      . "  Wears: container_lg \"Large (1024px)\" (width=\"lg\"); row \"Row\" via card_left \"Card Left\"\n"
      . "  Placed from: hero \"Hero\"\n"
      . "- Element 2: Block (UUID: b1)\n"
      . "- Element 3: Section (UUID: s2)\n"
      . "  Styling subject: the rule \"a\" under it (style-map key container:s2::a): styling acts on every element the rule matches, not on this element alone.\n"
      . "  Wears: link \"Link\" (class=\"link\")",
      $this->onlyBlock($event, BlockKind::Selection)->text,
    );
  }

  /**
   * PrintBlockProducer: the print, the access net, the no-op.
   *
   * The routed page's dialect print is the live page-language projection;
   * an entity with no layout states the honest empty-page note rather
   * than nothing, so the model never mistakes a bare page for missing
   * context. The access net rides RouteEntityResolver: an unviewable page
   * states nothing at all (its content must never reach the model).
   */
  public function testPrintBlockAccessNetAndNoop(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->createEntity('Routed Page');
    $path = '/entity_test/' . $entity->id();

    $event = $this->dispatch($this->printProducer(), ['current_route' => $path]);
    $block = $this->onlyBlock($event, BlockKind::Print);
    $this->assertSame('(the page is empty: no layout content yet)', $block->text, 'A layoutless page prints the honest empty-page note, without a template note.');
    $this->assertStringStartsWith("<system-reminder>\nCurrent page print (as of this message;", TranscriptRenderer::blockText($block), 'The renderer heads the print with the tag the protocol names.');

    // Access net: an unviewable page states nothing.
    $private = $this->createEntity('Secret Page');
    $this->setUpCurrentUser([], []);
    $denied = $this->dispatch($this->printProducer(), ['current_route' => '/entity_test/' . $private->id()]);
    $this->assertSame([], $denied->blocks(), 'An unviewable page prints nothing.');

    // No route in context: nothing stated.
    $bare = $this->dispatch($this->printProducer(), []);
    $this->assertSame([], $bare->blocks(), 'No routed page states nothing.');
  }

  /**
   * A page the language cannot spell whole prints whole, its notes first.
   *
   * A two-column section (a layout the site refuses to create, so built
   * on the storage) holding an undeclared block whose multi-value field
   * has no spelling. The block body is the print as the model reads it:
   * the reading notes once (the layout sentence teaching at: {ref,
   * position, region} and both refusal codes, the unprinted-field
   * sentence), a blank line, then the document with its read-only facts
   * (data-layout and data-regions on the section, data-region and
   * data-unprinted on the block). There is no fallback and no error: the
   * read is total.
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
    $card = BlockContent::create([
      'type' => 'card',
      'info' => 'Card',
      'reusable' => FALSE,
      'field_card_items' => ['Plans', 'Pricing'],
    ]);
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

    $event = $this->dispatch($this->printProducer(), ['current_route' => '/entity_test/' . $entity->id()]);
    $text = $this->onlyBlock($event, BlockKind::Print)->text;
    $this->assertStringStartsWith("Reading notes:\n- A <d-container> carrying data-layout is a section on that layout rather than the container", $text, 'The reading notes head the print.');
    $this->assertStringContainsString('An insert or move into such a section names the region as at: {ref, position, region}; afterbegin or beforeend on it without a region is refused (REGION_REQUIRED), a region anywhere else is refused too (REGION_NOT_APPLICABLE), and nothing creates such a section.', $text, 'The layout sentence teaches the region address and both refusals.');
    $this->assertStringContainsString("\n- data-unprinted names fields the element holds that the page language cannot spell: a set on the element keeps them, a replace rebuilds the element and loses them.\n\n<d-container", $text, 'The unprinted-field sentence closes the notes; a blank line separates them from the document.');
    $this->assertStringNotContainsString('data-partial names', $text, 'No partial field, no partial-field sentence.');
    $this->assertSame(1, substr_count($text, 'Reading notes:'), 'The notes head the print once.');
    $this->assertMatchesRegularExpression('/\n\n<d-container( id="[^"]*")?( data-can="[^"]*")? data-layout="layout_twocol_section" data-regions="first second">\n  <d-block id="' . preg_quote($card_uuid, '/') . '"( data-can="[^"]*")? data-region="first" data-unprinted="field_card_items" type="card" \/>\n<\/d-container>$/', $text, 'The document prints whole with its read-only facts and closes the body.');
    $this->assertStringNotContainsString('Plans', $text, 'The unspellable value is named, never printed.');
    $this->assertStringNotContainsString('Error', $text);
  }

  /**
   * AttachmentsBlockProducer: every file named, each delivered by kind, once.
   */
  public function testAttachmentsBlockShapeAndDeliveryByKind(): void {
    $this->setUpCurrentUser(['name' => 'owner']);
    $attachments = $this->attachmentsStore();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    $png = "\x89PNG\r\n\x1a\nfake";
    $pdf = '%PDF-1.4 fake';
    $csv = str_repeat("a,b,c\n", (int) ceil((AttachmentsBlockProducer::INLINE_CEILING + 1) / 6));
    $this->attach($attachments, 'hero.svg', 'image/svg+xml', $svg, 'thread-a');
    $this->attach($attachments, 'photo.png', 'image/png', $png, 'thread-a');
    $this->attach($attachments, 'brief.pdf', 'application/pdf', $pdf, 'thread-a');
    $this->attach($attachments, 'big.csv', 'text/csv', $csv, 'thread-a');
    $this->attach($attachments, 'clip.mp3', 'audio/mpeg', 'not audio', 'thread-a');

    $names = ['hero.svg', 'photo.png', 'brief.pdf', 'big.csv', 'clip.mp3'];
    $event = $this->dispatch($this->attachmentsProducer($attachments), ['attachments' => $names], 'thread-a');
    $text = $this->onlyBlock($event, BlockKind::Attachments)->text;
    $this->assertStringStartsWith("<system-reminder>\nATTACHMENTS (the user attached the following file(s) to this message; address a file by its name, and view_file(\"<name>\") re-opens it later):\n- File 1:", TranscriptRenderer::blockText($event->blocks()[0]), 'The renderer heads the block with the handle rule.');

    // A small textual file is inlined in a named fence.
    $this->assertStringStartsWith(
      "- File 1: hero.svg (image/svg+xml, " . strlen($svg) . " bytes), inlined:\n<file name=\"hero.svg\">\n" . $svg . "\n</file>\n",
      $text,
    );
    // A raster image rides the turn as an ImageFile, named in the block.
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
    $this->assertStringEndsWith("- File 5: clip.mp3 (audio/mpeg, 9 bytes): not a kind you can read.", $text);
    $this->assertStringNotContainsString('not audio', $text);

    $files = $event->files();
    $this->assertCount(2, $files, 'Only the image and the PDF ride the turn as files.');
    $this->assertInstanceOf(ImageFile::class, $files[0]);
    $this->assertSame($png, $files[0]->getBinary());
    $this->assertSame('image/png', $files[0]->getMimeType());
    $this->assertSame('photo.png', $files[0]->getFilename());
    $this->assertInstanceOf(DocumentFile::class, $files[1]);
    $this->assertSame($pdf, $files[1]->getBinary());
    $this->assertSame('application/pdf', $files[1]->getMimeType());
    $this->assertSame('brief.pdf', $files[1]->getFilename());
    $this->assertSame([], $this->logged, 'Every name resolved; nothing to notice.');

    // No attachments, or none as a list: nothing stated, no notice.
    foreach ([[], ['attachments' => []], ['attachments' => 'hero.svg']] as $context) {
      $bare = $this->dispatch($this->attachmentsProducer($attachments), $context, 'thread-a');
      $this->assertSame([], $bare->blocks());
      $this->assertSame([], $bare->files());
    }
    $this->assertSame([], $this->logged);
  }

  /**
   * A name the store does not answer is ignored with a notice, never guessed.
   */
  public function testUnknownAttachmentNamesAreIgnoredWithNotice(): void {
    $owner = $this->setUpCurrentUser(['name' => 'owner']);
    $attachments = $this->attachmentsStore();
    $this->attach($attachments, 'hero.svg', 'image/svg+xml', '<svg/>', 'thread-a');
    $other = $this->createUser([], 'other');
    $this->container->get('current_user')->setAccount($other);
    $this->attach($attachments, 'theirs.svg', 'image/svg+xml', '<svg id="theirs"/>', 'thread-a');
    $this->container->get('current_user')->setAccount($owner);

    // An unknown name, another thread's name, another user's file, and a
    // non-string entry: each a notice, nothing stated.
    foreach ([
      ['thread-a', ['missing.svg']],
      ['thread-b', ['hero.svg']],
      ['thread-a', ['theirs.svg']],
      ['thread-a', [7]],
    ] as [$thread, $names]) {
      $event = $this->dispatch($this->attachmentsProducer($attachments), ['attachments' => $names], $thread);
      $this->assertSame([], $event->blocks());
      $this->assertSame([], $event->files());
    }
    $this->assertSame([
      'Attachment missing.svg is not a file the current user holds in thread thread-a; ignored.',
      'Attachment hero.svg is not a file the current user holds in thread thread-b; ignored.',
      'Attachment theirs.svg is not a file the current user holds in thread thread-a; ignored.',
      'Attachment 7 is not a file the current user holds in thread thread-a; ignored.',
    ], $this->logged);

    // A run without a thread id resolves nothing.
    $this->logged = [];
    $event = $this->dispatch($this->attachmentsProducer($attachments), ['attachments' => ['hero.svg']], '');
    $this->assertSame([], $event->blocks());
    $this->assertSame(['Attachments hero.svg arrived on a run without a thread id; ignored.'], $this->logged);

    // A known name beside an unknown one: the known file is File 1, the
    // unknown is noticed.
    $this->logged = [];
    $event = $this->dispatch($this->attachmentsProducer($attachments), ['attachments' => ['ghost.png', 'hero.svg']], 'thread-a');
    $text = $this->onlyBlock($event, BlockKind::Attachments)->text;
    $this->assertSame("- File 1: hero.svg (image/svg+xml, 6 bytes), inlined:\n<file name=\"hero.svg\">\n<svg/>\n</file>", $text);
    $this->assertSame(['Attachment ghost.png is not a file the current user holds in thread thread-a; ignored.'], $this->logged);
  }

  /**
   * The block states the lane's clean name, and that name resolves.
   *
   * The attach route answers the name the store holds a lane-saved file
   * under (the lane's canonical filename), the chat client echoes it back
   * as contexts.attachments, and the block's "File N:" line states the
   * same spelling: the one the model repeats into view_file(). A macOS
   * screenshot carries a NARROW NO-BREAK SPACE (U+202F) before "PM", which
   * the model normalised to a plain space on the live site; the lane dashes
   * it, so the block never carries the byte. The screenshot is exported as
   * PDF because this container has no media type and the lane's accepted
   * set is the textual kinds (FileLaneTest pins the png); the original
   * spelling is not a name the thread holds.
   */
  public function testAttachmentsBlockStatesTheLanesCleanName(): void {
    $this->setUpCurrentUser(['name' => 'owner']);
    $attachments = $this->attachmentsStore();
    $pdf = '%PDF-1.4 fake';
    $original = "Screenshot 2026-10-27 at 4.17.44\u{202F}PM.pdf";
    $file = $this->container->get('daedalus_ui.file_lane')->fromBytes($original, 'application/pdf', $pdf, Attachments::DIRECTORY);
    $name = $attachments->attach($file, 'thread-a');
    $this->assertSame('Screenshot-2026-10-27-at-4.17.44-PM.pdf', $name);

    $event = $this->dispatch($this->attachmentsProducer($attachments), ['attachments' => [$name]], 'thread-a');
    $text = $this->onlyBlock($event, BlockKind::Attachments)->text;
    $this->assertSame("- File 1: Screenshot-2026-10-27-at-4.17.44-PM.pdf (application/pdf, " . strlen($pdf) . " bytes): the document is on this message; it cannot be re-opened later, so read what you need now.", $text);
    $this->assertStringNotContainsString("\u{202F}", $text);
    $files = $event->files();
    $this->assertCount(1, $files);
    $this->assertInstanceOf(DocumentFile::class, $files[0]);
    $this->assertSame($pdf, $files[0]->getBinary());
    $this->assertSame('Screenshot-2026-10-27-at-4.17.44-PM.pdf', $files[0]->getFilename());
    $this->assertSame([], $this->logged, 'The echoed name resolved.');

    $event = $this->dispatch($this->attachmentsProducer($attachments), ['attachments' => [$original]], 'thread-a');
    $this->assertSame([], $event->blocks());
    $this->assertSame([], $event->files());
    $this->assertSame(['Attachment ' . $original . ' is not a file the current user holds in thread thread-a; ignored.'], $this->logged);
  }

  /**
   * WorkflowSubscriber: the catalog on the segment-opening turn, by name.
   *
   * The skills block is stated only when the turn opens a segment, lists
   * every skill the resolver answers as "- id: description", and states
   * nothing without skills. The subscription names daedalus_ai's event by
   * its name (daedalus_blueprint_ai does not depend on daedalus_ai), so the
   * name is pinned against the event's constant here, where both load. The
   * Workflow section on the system prompt names the same list in its
   * skills rule and rides whether or not the site registers a skill: the
   * loop governs the page tools regardless.
   */
  public function testSkillsBlockOpensSegmentOnly(): void {
    $this->assertArrayHasKey(ContextBlocksEvent::EVENT_NAME, WorkflowSubscriber::getSubscribedEvents(), 'The subscriber names the event the runner dispatches.');

    $skills = [
      'design' => ['description' => 'Design a page or section.', 'instructions' => '...', 'reframe' => TRUE],
      'skins' => ['description' => 'Change the skin.', 'instructions' => '...', 'reframe' => FALSE],
    ];
    $subscriber = new WorkflowSubscriber($this->skillsResolver($skills));

    $opening = $this->dispatch($subscriber, [], 'thread-a', TRUE);
    $block = $this->onlyBlock($opening, BlockKind::Skills);
    $this->assertSame("- design: Design a page or section.\n- skins: Change the skin.", $block->text);
    $this->assertSame("<system-reminder>\nThe following skills are available for use with the Skill tool:\n" . $block->text . "\n</system-reminder>", TranscriptRenderer::blockText($block), 'The renderer heads the catalog with the line the obligation refers to.');

    $later = $this->dispatch($subscriber, [], 'thread-a', FALSE);
    $this->assertSame([], $later->blocks(), 'A turn inside a segment restates no catalog.');

    $none = $this->dispatch(new WorkflowSubscriber($this->skillsResolver([])), [], 'thread-a', TRUE);
    $this->assertSame([], $none->blocks(), 'No skills, no block.');

    $prompt = new BuildSystemPromptEvent('Base.', 'daedalus', []);
    $subscriber->onBuildSystemPrompt($prompt);
    $this->assertSame('Base.' . "\n" . WorkflowSubscriber::WORKFLOW, $prompt->getSystemPrompt(), 'The Workflow section follows the prompt.');
    $this->assertStringContainsString('## Workflow', $prompt->getSystemPrompt());
    $this->assertStringContainsString('BLOCKING REQUIREMENT', $prompt->getSystemPrompt(), 'The skills obligation is the section\'s first rule.');
    $this->assertStringContainsString('present the plan and wait for the go-ahead', $prompt->getSystemPrompt(), 'The approval rule lives here, on the always-on layer.');
    $this->assertStringNotContainsString('## Skills', $prompt->getSystemPrompt(), 'The skills obligation has no section of its own.');
    $without_skills = new BuildSystemPromptEvent('Base.', 'daedalus', []);
    (new WorkflowSubscriber($this->skillsResolver([])))->onBuildSystemPrompt($without_skills);
    $this->assertSame($prompt->getSystemPrompt(), $without_skills->getSystemPrompt(), 'The loop rides without a skill registered.');
  }

  /**
   * Dispatches the event to one producer and answers it.
   *
   * @param object $producer
   *   The producer (its onContextBlocks() is called directly).
   * @param array $context
   *   The turn's context.
   * @param string $conversation
   *   The conversation id.
   * @param bool $opens_segment
   *   Whether the turn opens a segment.
   */
  private function dispatch(object $producer, array $context, string $conversation = 'thread-a', bool $opens_segment = FALSE): ContextBlocksEvent {
    $event = new ContextBlocksEvent($context, $conversation, $opens_segment);
    $producer->onContextBlocks($event);
    return $event;
  }

  /**
   * The one block of a kind the event carries.
   */
  private function onlyBlock(ContextBlocksEvent $event, BlockKind $kind): Block {
    $blocks = $event->blocks();
    $this->assertCount(1, $blocks, 'Exactly one block is stated.');
    $this->assertSame($kind, $blocks[0]->kind);
    return $blocks[0];
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
   * Builds a fresh CurrentPageBlockProducer.
   */
  private function currentPageProducer(): CurrentPageBlockProducer {
    return new CurrentPageBlockProducer($this->routeResolver());
  }

  /**
   * Builds a fresh SelectionBlockProducer (element_resolver NULL fallback).
   */
  private function selectionProducer(): SelectionBlockProducer {
    return new SelectionBlockProducer(
      $this->routeResolver(),
      new ElementSelectionResolver($this->container->get('daedalus_blueprint.host_resolver'), NULL),
    );
  }

  /**
   * Builds a fresh PrintBlockProducer over the container's real printer.
   *
   * The host printer is the container's real one (the generated catalog,
   * the node tree builder, the stamper, the printer behind it), so the
   * scenario exercises the live page-language print, not a fixture.
   */
  private function printProducer(): PrintBlockProducer {
    return new PrintBlockProducer(
      $this->routeResolver(),
      $this->container->get('daedalus_blueprint.storage_handler'),
      $this->container->get('daedalus_blueprint.host_printer'),
    );
  }

  /**
   * Builds a fresh AttachmentsBlockProducer over a store and the collector.
   */
  private function attachmentsProducer(Attachments $attachments): AttachmentsBlockProducer {
    $test = $this;
    $logger = new class($test) extends AbstractLogger {

      public function __construct(private ContextBlocksTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->test->record((string) $level, (string) $message, $context);
      }

    };
    return new AttachmentsBlockProducer($attachments, $logger);
  }

  /**
   * Records a logged notice, interpolated the way the channel would print it.
   */
  public function record(string $level, string $message, array $context): void {
    $this->assertSame('notice', $level, 'The producer only ever notices.');
    $replacements = [];
    foreach ($context as $key => $value) {
      $replacements[$key] = (string) $value;
    }
    $this->logged[] = strtr($message, $replacements);
  }

  /**
   * The attachment store over core's file usage and the alias map.
   */
  private function attachmentsStore(): Attachments {
    return new Attachments(
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
   * Saves a file with the given bytes and holds it for a thread.
   */
  private function attach(Attachments $attachments, string $name, string $mime, string $bytes, string $thread): FileInterface {
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
    $attachments->attach($file, $thread);
    return $file;
  }

  /**
   * A canned skills resolver answering the given definitions.
   */
  private function skillsResolver(array $skills): SkillsResolverInterface {
    return new class($skills) implements SkillsResolverInterface {

      public function __construct(private readonly array $skills) {}

      /**
       * {@inheritdoc}
       */
      public function getSkills(): array {
        return $this->skills;
      }

      /**
       * {@inheritdoc}
       */
      public function getSkill(string $id): ?array {
        return $this->skills[$id] ?? NULL;
      }

    };
  }

  /**
   * Builds a fresh RouteEntityResolver (fresh per-request cache).
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

}
