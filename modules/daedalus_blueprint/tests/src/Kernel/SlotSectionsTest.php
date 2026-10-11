<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Slot sections in the one document.
 *
 * A slot section is a host's own top-level section tagged with the id of
 * the slot it fills in the document around it (the section's third-party
 * settings under daedalus_blueprint.slot). The tag is a birth fact of
 * add_section's `slot` parameter, judged at the op door (a machine name,
 * the root anchor, at most once per document), surfaced by the
 * serializer as the top-level container's `slot` key, written by the
 * instantiator from a tree, printed and parsed by the dialect as
 * <pb-slot name="id">, and kept whole by the verbs that could break it
 * (move, duplicate, replace, set).
 *
 * Two hosts: an overridable entity_test page exercises the doors (a
 * locked page's root insert stays the template's until the editing task
 * opens it), and a locked entity_test_rev page, its own sections written
 * straight into its field, shows the read side: its template's own fill
 * of a frame slot and the page's slot sections in one tree.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(LayoutSerializer::class)]
#[CoversClass(DocumentInstantiator::class)]
#[CoversClass(DomOperationHandler::class)]
#[Group('daedalus_blueprint')]
class SlotSectionsTest extends BlueprintKernelTestBase {

  /**
   * The template section's uuid: the template's own fill of a frame slot.
   */
  protected string $templateSection;

  /**
   * The template's plugin block uuid.
   */
  protected string $templateBlock;

  /**
   * The op spine.
   */
  protected object $operations;

  /**
   * The layout serializer.
   */
  protected object $serializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->createBlockContentType('basic', 'Basic');
    $this->setUpCurrentUser([], [], TRUE);
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->serializer = $this->container->get('daedalus_blueprint.layout_serializer');

    // The overridable bundle: entity_test's pages own their whole document.
    $this->enableLayoutBuilder();

    // The locked bundle: Layout Builder on, overrides off, one template
    // section that itself fills a frame slot ("main"): another document's
    // fill, which a page's slot ids never collide with.
    $uuids = new PhpUuid();
    $this->templateSection = $uuids->generate();
    $this->templateBlock = $uuids->generate();
    $template = $this->createSection('eb_test_container', [
      [
        'uuid' => $this->templateBlock,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block'],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $this->templateSection);
    $template->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'main');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();
  }

  /**
   * The spec catalog: the test container layout, no block types, no axes.
   */
  protected function catalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'eb_test_container',
      axes: [],
      blockTypes: [],
      conditions: [],
    );
  }

  /**
   * An overridable page owning one plain section.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string}
   *   The host and its section's uuid.
   */
  protected function page(): array {
    $uuid = (new PhpUuid())->generate();
    $section = $this->createSection('eb_test_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    $entity = $this->createTestEntity(['name' => 'Page', ContentHost::LAYOUT_FIELD => [$section]]);
    return [$this->host($entity), $uuid];
  }

  /**
   * A section of a host's own, written straight into its field.
   */
  protected function ownSection(string $uuid, ?string $slot, array $components = []): Section {
    $section = $this->createSection('eb_test_container', $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    if ($slot !== NULL) {
      $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, $slot);
    }
    return $section;
  }

  /**
   * Runs ops as one batch on a host.
   */
  protected function apply(HostInterface $host, array $ops, array $options = []): BlueprintResult {
    return $this->operations->batchOperations($host, $ops, $options);
  }

  /**
   * The slot of every own section of a host, by section uuid.
   *
   * @return array<string, string|null>
   *   Section uuid to slot id.
   */
  protected function ownSlots(HostInterface $host): array {
    $slots = [];
    foreach ($host->ownSections() as $section) {
      $slots[$section->getThirdPartySetting('daedalus_layout', 'uuid')] = LayoutSerializer::slotOf($section);
    }
    return $slots;
  }

  /**
   * The first error of a refused batch: code, path and context.
   */
  protected function refusal(BlueprintResult $result): array {
    $this->assertFalse($result->isSuccess(), 'The batch was refused.');
    $error = $result->getErrors()[0];
    return [$error->getCode(), $error->getPath(), $error->getContext()];
  }

  /**
   * A slot section is born tagged and serializes as a slot-tagged top-level container.
   */
  public function testSlotSectionSerializes(): void {
    [$host, $first] = $this->page();
    $hero = (new PhpUuid())->generate();
    $block = (new PhpUuid())->generate();
    $nested = (new PhpUuid())->generate();
    $result = $this->apply($host, [
      ['op' => 'add_section', 'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'], 'uuid' => $hero, 'slot' => 'hero'],
      ['op' => 'add_element', 'location' => ['anchor' => $hero, 'placement' => 'inside'], 'uuid' => $block, 'plugin_id' => 'system_powered_by_block'],
      ['op' => 'add_section', 'location' => ['anchor' => $hero, 'placement' => 'inside'], 'uuid' => $nested],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$first => NULL, $hero => 'hero'], $this->ownSlots($host), 'The page owns its plain section and one tagged with the slot it fills.');
    $this->assertSame('hero', $host->ownSections()[1]->getThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING), 'The tag lives in the section\'s third-party settings under daedalus_blueprint.slot.');

    $tree = $this->serializer->serialize($host);
    $this->assertArrayNotHasKey('slot', $tree[0], 'A plain section carries no key at all.');
    $this->assertSame(['kind' => 'container', 'layout_id' => 'eb_test_container', 'uuid' => $hero, 'slot' => 'hero'], array_diff_key($tree[1], ['children' => 1]), 'The slot rides the top-level container right after its identity.');
    $this->assertSame([$block, $nested], array_column($tree[1]['children'], 'uuid'));
    $this->assertArrayNotHasKey('slot', $tree[1]['children'][1], 'A nested section never carries one.');
    $this->assertSame('hero', $this->serializer->serializeSummary($host)[1]['slot'], 'The summary carries the slot: it is identity, not content.');

    // The locked page: a reader sees the template's own fill of a frame
    // slot, then the page's slot sections, one tree; a slot setting on a
    // nested section is residue and never prints; the page may fill a
    // "main" of its own template beside the template's "main" (another
    // document's).
    $page_main = (new PhpUuid())->generate();
    $page_hero = (new PhpUuid())->generate();
    $inner = $this->ownSection((new PhpUuid())->generate(), 'inner');
    $own_hero = $this->ownSection($page_hero, 'hero');
    $own_hero->appendComponent($this->container->get('daedalus_blueprint.daedalus_layout_adapter')->createSectionComponent($inner, 'content'));
    $locked = $this->host($this->createRevEntity(['name' => 'Locked', ContentHost::LAYOUT_FIELD => [$this->ownSection($page_main, 'main'), $own_hero]]));
    $tree = $this->serializer->serialize($locked);
    $this->assertSame([$this->templateSection, $page_main, $page_hero], array_column($tree, 'uuid'));
    $this->assertSame(['main', 'main', 'hero'], array_column($tree, 'slot'), 'The template fills the frame\'s main; the page fills its template\'s main and hero.');
    $this->assertSame('container', $tree[2]['children'][0]['kind']);
    $this->assertArrayNotHasKey('slot', $tree[2]['children'][0], 'A nested section\'s slot setting is residue.');
  }

  /**
   * The op door: a machine-name id, the root anchor, at most once per document.
   */
  public function testOpDoorJudgesTheSlot(): void {
    [$host, $first] = $this->page();
    $root = ['anchor' => $host->uuid(), 'placement' => 'inside'];
    $hero = (new PhpUuid())->generate();
    $this->assertTrue($this->apply($host, [['op' => 'add_section', 'location' => $root, 'uuid' => $hero, 'slot' => 'hero']])->isSuccess());

    // The same id again on this document is refused, naming the section
    // that fills it, and the batch leaves the document as it was.
    [$code, $path, $context] = $this->refusal($this->apply($host, [['op' => 'add_section', 'location' => $root, 'slot' => 'hero']]));
    $this->assertSame(['BLUEPRINT_SLOT_DUPLICATE', 'slot', ['slot' => 'hero', 'section' => $hero]], [$code, $path, $context]);
    $this->assertSame([$first => NULL, $hero => 'hero'], $this->ownSlots($host));
    [$code] = $this->refusal($this->apply($host, [
      ['op' => 'add_section', 'location' => $root, 'slot' => 'aside'],
      ['op' => 'add_section', 'location' => $root, 'slot' => 'aside'],
    ]));
    $this->assertSame('BLUEPRINT_SLOT_DUPLICATE', $code, 'Within one batch too.');
    $this->assertSame([$first => NULL, $hero => 'hero'], $this->ownSlots($host));

    // The id is a machine name.
    [$code, $path] = $this->refusal($this->apply($host, [['op' => 'add_section', 'location' => $root, 'slot' => 'Hero band']]));
    $this->assertSame(['INVALID_SLOT', 'slot'], [$code, $path]);
    [$code] = $this->refusal($this->apply($host, [['op' => 'add_section', 'location' => $root, 'slot' => '']]));
    $this->assertSame('INVALID_SLOT', $code);

    // A slot section lands at the root only: nested inside a section, or
    // beside one, is refused before anything is written.
    [$code, $path] = $this->refusal($this->apply($host, [['op' => 'add_section', 'location' => ['anchor' => $hero, 'placement' => 'inside'], 'slot' => 'aside']]));
    $this->assertSame(['BLUEPRINT_SLOT_NESTED', 'location'], [$code, $path]);
    $this->assertSame([$first => NULL, $hero => 'hero'], $this->ownSlots($host));

    // Duplicating a slot section would fill its slot twice.
    [$code, $path, $context] = $this->refusal($this->apply($host, [['op' => 'duplicate_element', 'element' => $hero]]));
    $this->assertSame(['BLUEPRINT_SLOT_DUPLICATE', 'element', ['slot' => 'hero', 'section' => $hero]], [$code, $path, $context]);
    $this->assertTrue($this->apply($host, [['op' => 'duplicate_element', 'element' => $first]])->isSuccess(), 'A plain section duplicates as before.');
    $this->assertSame([NULL, NULL, 'hero'], array_values($this->ownSlots($host)));

    // A slot section moves within the root flow and nowhere else.
    [$code, $path] = $this->refusal($this->apply($host, [['op' => 'move_element', 'element' => $hero, 'location' => ['anchor' => $first, 'placement' => 'inside']]]));
    $this->assertSame(['BLUEPRINT_SLOT_NESTED', 'location'], [$code, $path]);
    $this->assertTrue($this->apply($host, [['op' => 'move_element', 'element' => $hero, 'location' => ['anchor' => $first, 'placement' => 'before']]])->isSuccess(), 'Reordering among top-level sections is fine.');
    $this->assertSame(['hero', NULL, NULL], array_values($this->ownSlots($host)));
    $this->assertTrue($this->apply($host, [['op' => 'move_element', 'element' => $hero, 'location' => $root]])->isSuccess(), 'And so is moving last.');
    $this->assertSame([NULL, NULL, 'hero'], array_values($this->ownSlots($host)));
    $nested = (new PhpUuid())->generate();
    $this->assertTrue($this->apply($host, [['op' => 'add_section', 'location' => ['anchor' => $first, 'placement' => 'inside'], 'uuid' => $nested]])->isSuccess());
    [$code] = $this->refusal($this->apply($host, [['op' => 'move_element', 'element' => $hero, 'location' => ['anchor' => $nested, 'placement' => 'before']]]));
    $this->assertSame('BLUEPRINT_SLOT_NESTED', $code, 'Beside a nested section is nested.');

    // Removing the section frees the slot.
    $this->assertTrue($this->apply($host, [['op' => 'remove_element', 'element' => $hero]])->isSuccess());
    $again = (new PhpUuid())->generate();
    $this->assertTrue($this->apply($host, [['op' => 'add_section', 'location' => $root, 'uuid' => $again, 'slot' => 'hero']])->isSuccess(), 'A removed slot section leaves its slot free.');

    // Beside a top-level section is the root flow too; beside a nested
    // one is not.
    $aside = (new PhpUuid())->generate();
    $this->assertTrue($this->apply($host, [['op' => 'add_section', 'location' => ['anchor' => $again, 'placement' => 'before'], 'uuid' => $aside, 'slot' => 'aside']])->isSuccess());
    $this->assertSame([NULL, NULL, 'aside', 'hero'], array_values($this->ownSlots($host)));
    [$code] = $this->refusal($this->apply($host, [['op' => 'add_section', 'location' => ['anchor' => $nested, 'placement' => 'after'], 'slot' => 'deep']]));
    $this->assertSame('BLUEPRINT_SLOT_NESTED', $code);
    [$code] = $this->refusal($this->apply($host, [['op' => 'add_section', 'location' => ['anchor' => $again, 'placement' => 'after'], 'slot' => 'aside']]));
    $this->assertSame('BLUEPRINT_SLOT_DUPLICATE', $code, 'The fill rule holds beside a section as at the root.');
    $this->assertSame([NULL, NULL, 'aside', 'hero'], array_values($this->ownSlots($host)));
  }

  /**
   * The instantiator writes a tree's slot onto the root's add_section.
   */
  public function testInstantiatorWritesTheSlot(): void {
    [$host] = $this->page();
    $instantiator = $this->container->get('daedalus_blueprint.document_instantiator');
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'slot' => 'hero',
        'children' => [
          ['kind' => 'block', 'plugin_id' => 'system_powered_by_block'],
          ['kind' => 'container', 'layout_id' => 'eb_test_container', 'children' => []],
        ],
      ],
      ['kind' => 'container', 'layout_id' => 'eb_test_container', 'children' => []],
    ];
    $expansion = $instantiator->expand($host, $tree, ['anchor' => $host->uuid(), 'placement' => 'inside'], ['indexed' => TRUE]);
    $this->assertSame([], array_map('strval', $expansion->errors));
    $this->assertSame(['add_section', 'add_element', 'add_section', 'add_section'], array_column($expansion->operations, 'op'));
    $this->assertSame('hero', $expansion->operations[0]['slot'], 'The root\'s add_section carries the slot.');
    $this->assertArrayNotHasKey('slot', $expansion->operations[2], 'A nested section carries none.');
    $this->assertArrayNotHasKey('slot', $expansion->operations[3], 'A plain root carries none.');
    $result = $this->apply($host, $expansion->operations);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([NULL, 'hero', NULL], array_values($this->ownSlots($host)));

    // A slot on a nested container is a grammar error at its path; a
    // slot that is not a non-empty string too.
    $expansion = $instantiator->expand($host, [
      ['kind' => 'container', 'layout_id' => 'eb_test_container', 'children' => [
        ['kind' => 'container', 'layout_id' => 'eb_test_container', 'slot' => 'inner', 'children' => []],
      ],
      ],
    ], ['anchor' => $host->uuid(), 'placement' => 'inside']);
    $this->assertSame(['nodes.children[0].slot'], array_map(static fn ($e) => $e->getPath(), $expansion->errors));
    $this->assertSame('Only a top-level section fills a slot.', $expansion->errors[0]->getMessage());
    $expansion = $instantiator->expand($host, [['kind' => 'container', 'layout_id' => 'eb_test_container', 'slot' => '', 'children' => []]], ['anchor' => $host->uuid(), 'placement' => 'inside']);
    $this->assertSame(['nodes.slot'], array_map(static fn ($e) => $e->getPath(), $expansion->errors));
  }

  /**
   * The dialect prints a slot section as <pb-slot name="id"> and parses it back.
   */
  public function testDialectRoundTrip(): void {
    $hero = (new PhpUuid())->generate();
    $block = (new PhpUuid())->generate();
    $locked = $this->host($this->createRevEntity(['name' => 'Locked', ContentHost::LAYOUT_FIELD => [
      $this->ownSection($hero, 'hero', [['uuid' => $block, 'configuration' => ['id' => 'system_powered_by_block']]]),
    ],
    ]));

    $catalog = $this->catalog();
    $printer = $this->container->get('daedalus_blueprint.dialect_printer');
    $parser = $this->container->get('daedalus_blueprint.dialect_parser');
    $print = $printer->print($this->serializer->serialize($locked), $catalog)->text;
    $expected = <<<HTML
<pb-slot id="{$this->templateSection}" name="main">
  <pb-block id="{$this->templateBlock}" plugin="system_powered_by_block" />
</pb-slot>
<pb-slot id="{$hero}" name="hero">
  <pb-block id="{$block}" plugin="system_powered_by_block" />
</pb-slot>

HTML;
    $this->assertSame($expected, $print);

    $reparse = $parser->parseDocument($print, $catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame($print, $printer->print($reparse->tree, $catalog)->text, 'print(parse(x)) == x');
    $this->assertSame('hero', $reparse->tree[1]['slot'], 'name on <pb-slot> parses as the node\'s slot.');
    $this->assertArrayNotHasKey('label', $reparse->tree[1], 'A slot section\'s name is its slot, never a label.');
    $this->assertSame($hero, $reparse->tree[1]['uuid']);

    // A slot section carrying a label prints no name of its own: the
    // slot is the word it is called by. A plain container still prints
    // its label as name.
    $labelled = [['kind' => 'container', 'layout_id' => 'eb_test_container', 'uuid' => $hero, 'label' => 'Welcome', 'slot' => 'hero', 'children' => []]];
    $this->assertSame("<pb-slot id=\"$hero\" name=\"hero\">\n</pb-slot>\n", $printer->print($labelled, $catalog)->text);
    $plain = [['kind' => 'container', 'layout_id' => 'eb_test_container', 'uuid' => $hero, 'label' => 'Welcome', 'children' => []]];
    $this->assertSame("<pb-container id=\"$hero\" name=\"Welcome\">\n</pb-container>\n", $printer->print($plain, $catalog)->text);

    // The grammar: top level only, name required, the fragment door the same.
    $result = $parser->parseDocument('<pb-container id="c"><pb-slot id="s" name="aside"></pb-slot></pb-container>', $catalog);
    $this->assertSame(['ILLEGAL_CONTAINMENT'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame(1, $result->errors[0]->line);
    $result = $parser->parseDocument('<pb-slot id="s"></pb-slot>', $catalog);
    $this->assertSame(['ILLEGAL_ATTR'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertStringContainsString('needs name=', $result->errors[0]->message);
    $result = $parser->parseDocument('<slot name="hero"></slot>', $catalog);
    $this->assertSame(['UNPREFIXED_TAG'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertStringContainsString('<pb-slot>', $result->errors[0]->message);
    $this->assertTrue($parser->parseFragment('<pb-slot id="s" name="aside"></pb-slot>', $catalog, TRUE)->isValid(), 'At the top level the fragment door takes a slot section.');
    $this->assertSame(['ILLEGAL_CONTAINMENT'], array_map(static fn ($e) => $e->code, $parser->parseFragment('<pb-slot id="s" name="aside"></pb-slot>', $catalog)->errors), 'Inside a container it does not.');
    $result = $parser->parseDocument('<pb-slot id="s" name="aside" bogus="1"></pb-slot>', $catalog);
    $this->assertSame(['ILLEGAL_ATTR'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame('bogus is not an attribute of <pb-slot>', $result->errors[0]->message);
  }

  /**
   * The DOM ops keep a slot whole: replace refills it once, set cannot rename it.
   */
  public function testDomOpsKeepTheSlot(): void {
    [$host, $first] = $this->page();
    $hero = (new PhpUuid())->generate();
    $options = ['dialect_catalog' => $this->catalog(), 'conversation' => 'conv-1'];
    $this->assertTrue($this->apply($host, [['op' => 'add_section', 'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'], 'uuid' => $hero, 'slot' => 'hero']])->isSuccess());

    // Replacing the slot section with the slot written again is one fill:
    // the old section goes, the new one carries the slot.
    $result = $this->apply($host, [
      ['op' => 'replace', 'ref' => $hero, 'html' => '<pb-slot name="hero"><pb-block plugin="system_powered_by_block" /></pb-slot>'],
    ], $options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $slots = $this->ownSlots($host);
    $this->assertSame([$first => NULL], array_slice($slots, 0, 1, TRUE));
    $this->assertSame([NULL, 'hero'], array_values($slots), 'One section fills hero.');
    $filled = array_key_last($slots);
    $this->assertNotSame($hero, $filled, 'With fresh identity.');
    $this->assertSame('system_powered_by_block', $this->serializer->serialize($host)[1]['children'][0]['plugin_id'], 'Holding the replacement\'s content.');

    // Two fragment roots claiming one slot are the duplicate, and the
    // refused batch rolls back whole.
    [$code] = $this->refusal($this->apply($host, [
      ['op' => 'replace', 'ref' => $filled, 'html' => '<pb-slot name="aside"></pb-slot><pb-slot name="aside"></pb-slot>'],
    ], $options));
    $this->assertSame('BLUEPRINT_SLOT_DUPLICATE', $code);
    $this->assertSame([NULL, 'hero'], array_values($this->ownSlots($host)), 'The refused batch rolled back.');

    // The slot id is fixed for the section's life.
    [$code, $path] = $this->refusal($this->apply($host, [['op' => 'set', 'ref' => $filled, 'attrs' => ['name' => 'aside']]], $options));
    $this->assertSame(['READONLY_ATTR', 'attrs.name'], [$code, $path]);
    $this->assertTrue($this->apply($host, [['op' => 'set', 'ref' => $first, 'attrs' => ['name' => 'Intro']]], $options)->isSuccess(), 'A plain container still renames.');

    // An insert at the root takes a slot section like a container; inside
    // a container it is refused by the grammar.
    $result = $this->apply($host, [['op' => 'insert', 'at' => ['ref' => 'root', 'position' => 'beforeend'], 'html' => '<pb-slot name="aside"></pb-slot>']], $options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([NULL, 'hero', 'aside'], array_values($this->ownSlots($host)));
    [$code] = $this->refusal($this->apply($host, [['op' => 'insert', 'at' => ['ref' => $first, 'position' => 'beforeend'], 'html' => '<pb-slot name="deep"></pb-slot>']], $options));
    $this->assertSame('ILLEGAL_CONTAINMENT', $code);
  }

}
