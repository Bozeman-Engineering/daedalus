<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\BlueprintRequirements;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionListInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the start: one spine verb for a page's first document.
 *
 * A page's first document has one source, picked once at creation. The
 * verb's two answers: starts() is the offer asked for page scope (the
 * rooted, fitting page blueprints for a bundle's host), and apply() lands
 * a start on the unsaved entity so ONE save lands entity and document:
 * a page blueprint's whole document (its tree with fresh identity and no
 * provenance stamp, each inline block saved once with no orphan, its
 * page-level styles re-addressed to the page's root, the template seed
 * declined), blank (core's marker, persisted, read as no sections), and
 * template (an empty field that seeds on its first read, as before). On
 * a locked bundle, whose pages own only the slots the template places,
 * the starts are fills: a page blueprint of slot sections lands them in
 * the page's own document, blank and template fill nothing, and a whole
 * page document does not fit. And the refusals, each at the start path:
 * a saved entity, a host with no document door, an unknown id, a block
 * blueprint, an empty one, one that does not fit.
 */
#[CoversClass(PageStart::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PageStartTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The styling module gives the blueprint type and the node bundle their
   * style maps (a page-level style is the half of the document the start
   * must carry); the unbound test module withdraws the suite's dimension
   * bindings so the fixture writes literal gaps and colors under law.
   */
  protected static $modules = ['node', 'daedalus_stylish', 'daedalus_stylish_unbound_test'];

  /**
   * The uuid of the node page template's one section.
   */
  protected string $templateSection;

  /**
   * The uuid of the locked plain template's one section, holding the hero slot.
   */
  protected string $lockedSection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('daedalus_variant');
    // The style ops validate through the materials roster, whose stylish
    // provider reads every material storage on each build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);

    // The subject: node pages with a body, Layout Builder enabled with
    // overrides (so each page may own its document and core derives the
    // bundle's field blocks) and a style map.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Body',
    ])->save();
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable();
    $display->save();
    // Core's enabling added a default section of the display's fields;
    // the template below is built explicitly, one section.
    $display->removeAllSections()->save();
    $this->container->get('daedalus_stylish.field_manager')->enable('node', 'page');

    // A bundle whose pages share the template's layout: Layout Builder on,
    // overrides off, so its pages are rendered inside the template and own
    // only what they hold there (the override field is on every enabled
    // bundle), never a whole document. Its template places one slot
    // (hero) of the site's two: a page of it fills hero and nothing else.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'plain',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder();
    $display->save();
    // Core's enabling added a default section of the display's fields;
    // the locked template below is built explicitly, one section.
    $display->removeAllSections()->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
    $locked = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity(LayoutBuilderEntityViewDisplay::load('node.plain.default'));
    $this->lockedSection = $this->container->get('uuid')->generate();
    $this->assertBatchSuccess($this->operations()->batchOperations($locked, [
      [
        'op' => 'add_section',
        'uuid' => $this->lockedSection,
        'layout' => 'layout_onecol',
        'location' => ['anchor' => $locked->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'plugin_id' => SlotBlock::PLUGIN_ID . ':hero',
        'location' => ['anchor' => $this->lockedSection, 'placement' => 'inside'],
      ],
    ]));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($locked);

    // The text block type's one field: absent in the blueprint, filled by
    // the start (sample_fields on, core's generator as the last rung).
    $this->createBlockField('field_heading', 'text', 'string');

    // The page template: one section holding a plugin block, built through
    // the ops on the display host and committed, so a page that follows
    // its template shows the block and a page that declined the seed does
    // not.
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $template = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $this->templateSection = $this->container->get('uuid')->generate();
    $this->assertBatchSuccess($this->operations()->batchOperations($template, [
      [
        'op' => 'add_section',
        'uuid' => $this->templateSection,
        'layout' => 'layout_onecol',
        'location' => ['anchor' => $template->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => $this->templateSection, 'placement' => 'inside'],
      ],
    ]));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($template);
  }

  /**
   * The start verb.
   */
  protected function start(): PageStart {
    return $this->container->get('daedalus_blueprint.page_start');
  }

  /**
   * The host an unsaved entity of a bundle would have.
   */
  protected function bundleHost(string $entity_type, string $bundle): HostInterface {
    $host = $this->container->get('daedalus_blueprint.page_hosts')->bundleHost($entity_type, $bundle);
    $this->assertInstanceOf(HostInterface::class, $host);
    return $host;
  }

  /**
   * A onecol container holding children in its content region.
   */
  protected function containerOf(array $children): array {
    return [
      'kind' => 'container',
      'layout_id' => 'layout_onecol',
      'children' => [['kind' => 'region', 'region' => 'content', 'children' => $children]],
    ];
  }

  /**
   * The landing page blueprint: two roots, two inline blocks, root styles.
   *
   * Written the way a page capture writes a blueprint (the whole-document
   * copy on the blueprint's own host as a system batch, both halves),
   * then committed: a text block with its heading absent, the subject's
   * body field and a basic block across two containers, and the page's
   * own root entries (a background and the gap between sections) keyed
   * under the blueprint's uuid, as a saved document keys them.
   */
  protected function createLandingBlueprint(): Blueprint {
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Landing',
      'machine_name' => 'landing',
      'description' => 'A landing page: an intro, the body, a closing band.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'page',
      'status' => 1,
    ]);
    $host = $this->host($blueprint);
    $result = $this->container->get('daedalus_blueprint.document_instantiator')->instantiateDocument($host, [
      $this->containerOf([
        ['kind' => 'block', 'block_type' => 'text', 'label' => 'Intro'],
        ['kind' => 'block', 'field' => 'body'],
      ]),
      $this->containerOf([
        ['kind' => 'block', 'block_type' => 'basic'],
      ]),
    ], [
      'entity:' . $host->uuid() => ['default' => ['background-color' => 'aliceblue']],
      'entity:' . $host->uuid() . '::> *' => ['default' => ['gap' => '3rem']],
    ], [
      'indexed' => TRUE,
      'batch' => ['system' => TRUE, 'admitted' => TRUE],
    ]);
    $this->assertBatchSuccess($result);
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    return $blueprint;
  }

  /**
   * A page blueprint of slot fills for the locked bundle: one section per slot.
   *
   * Written as a capture of a locked page writes one (the whole-document
   * copy on the blueprint's own host): each root carries the slot it
   * fills, holding a text block with its heading absent.
   *
   * @param string $machine_name
   *   The blueprint's machine name.
   * @param string[] $slots
   *   The slots its roots fill, in order.
   */
  protected function createFillBlueprint(string $machine_name, array $slots): Blueprint {
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
      'description' => 'Fills of the plain template\'s slots.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'plain',
      'status' => 1,
    ]);
    $host = $this->host($blueprint);
    $this->assertBatchSuccess($this->container->get('daedalus_blueprint.document_instantiator')->instantiateDocument($host, array_map(fn (string $slot): array => [
      'slot' => $slot,
    ] + $this->containerOf([
      ['kind' => 'block', 'block_type' => 'text', 'label' => ucfirst($slot)],
    ]), $slots), [], [
      'indexed' => TRUE,
      'batch' => ['system' => TRUE, 'admitted' => TRUE],
    ]));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    return $blueprint;
  }

  /**
   * The raw section list of an entity's override field, as stored.
   *
   * Read off the field, not through the host, so no door seeds it: the
   * blank marker shows here, which the host's reads hide.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections.
   */
  protected function rawSections(FieldableEntityInterface $entity): array {
    $list = $entity->get(ContentHost::LAYOUT_FIELD);
    $this->assertInstanceOf(SectionListInterface::class, $list);
    return $list->getSections();
  }

  /**
   * A node reloaded from storage, uncached.
   */
  protected function reload(Node $node): Node {
    $fresh = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->assertInstanceOf(Node::class, $fresh);
    return $fresh;
  }

  /**
   * The block plugin ids placed in a list of sections, in document order.
   *
   * @return string[]
   *   The plugin ids.
   */
  protected function pluginIds(array $sections): array {
    $ids = [];
    foreach ($sections as $section) {
      foreach ($section->getComponents() as $component) {
        $ids[] = $component->getPluginId();
      }
    }
    return $ids;
  }

  /**
   * The ids of every block_content entity in storage.
   *
   * @return int[]
   *   The ids, sorted.
   */
  protected function blockIds(): array {
    $ids = array_map('intval', array_values($this->container->get('entity_type.manager')->getStorage('block_content')->getQuery()->accessCheck(FALSE)->execute()));
    sort($ids);
    return $ids;
  }

  /**
   * Asserts a refusal: one error, its code at the start path, nothing staged.
   */
  protected function assertRefused(BlueprintResult $result, string $code, HostInterface $host): void {
    $this->assertFalse($result->isSuccess(), sprintf('Expected %s.', $code));
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame($code, $errors[0]->getCode());
    $this->assertSame(PageStart::PATH, $errors[0]->getPath());
    $entity = $host->fields();
    if ($entity !== NULL && $entity->isNew() && $host->hasDocument()) {
      $this->assertSame([], $this->rawSections($entity), 'Nothing was staged on the refused host.');
    }
  }

  /**
   * A start from a page blueprint lands the whole document in one save.
   */
  public function testStartFromPageBlueprint(): void {
    $blueprint = $this->createLandingBlueprint();
    $blueprint_sections = $this->rawSections($this->loadBlueprint('landing'));
    $this->assertCount(2, $blueprint_sections, 'The blueprint holds its two roots.');
    $record = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Blueprint, 'landing');
    $this->assertSame([
      'entity:' . $blueprint->uuid() => ['default' => ['background-color' => 'aliceblue']],
      'entity:' . $blueprint->uuid() . '::> *' => ['default' => ['gap' => '3rem']],
    ], $record->styles, 'The record carries the page-level styles beside the tree, keyed under the blueprint\'s uuid.');
    $this->assertCount(2, $record->tree);
    $blocks_before = $this->blockIds();
    $this->assertCount(2, $blocks_before, 'The blueprint saved its own two inline blocks.');

    $node = Node::create(['type' => 'page', 'title' => 'Started']);
    $host = $this->host($node);
    $result = $this->start()->apply($host, 'landing');
    $this->assertBatchSuccess($result);
    $this->assertSame($host, $result->getHost());
    $this->assertTrue($node->isNew(), 'The start persists nothing.');
    $this->assertSame($blocks_before, $this->blockIds(), 'No inline block is saved before the page is.');
    $this->assertCount(2, $this->rawSections($node), 'The document stands on the unsaved entity.');

    // ONE save lands entity and document: the two inline blocks are saved
    // once each, every one referenced by the page and recorded as its
    // usage, so no block is orphaned and none is shared with the
    // blueprint.
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $this->assertFalse($node->isNew());
    $new_blocks = array_values(array_diff($this->blockIds(), $blocks_before));
    $this->assertCount(2, $new_blocks, 'Each inline block was saved exactly once.');
    $usage = $this->container->get('database')->select('inline_block_usage', 'u')
      ->fields('u', ['block_content_id'])
      ->condition('layout_entity_type', 'node')
      ->condition('layout_entity_id', $node->id())
      ->execute()->fetchCol();
    $usage = array_map('intval', $usage);
    sort($usage);
    $this->assertSame($new_blocks, $usage, 'The page\'s usage names its two blocks and nothing else.');

    $fresh = $this->reload($node);
    $sections = $this->rawSections($fresh);
    $this->assertCount(2, $sections, 'Both roots landed.');
    $block_storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $referenced = [];
    foreach ($sections as $section) {
      $this->assertNull($section->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'), 'A started page is not a placement: no root carries a provenance stamp.');
      $this->assertNotContains($section->getThirdPartySetting('daedalus_layout', 'uuid'), array_map(static fn (Section $s) => $s->getThirdPartySetting('daedalus_layout', 'uuid'), $blueprint_sections), 'Fresh identity: no root keeps the blueprint\'s uuid.');
      foreach ($section->getComponents() as $component) {
        $configuration = $component->get('configuration');
        if (isset($configuration['block_revision_id'])) {
          $referenced[] = (int) $block_storage->loadRevision($configuration['block_revision_id'])->id();
        }
      }
    }
    sort($referenced);
    $this->assertSame($new_blocks, $referenced, 'The page\'s components reference exactly the blocks the save created.');
    $this->assertSame(['inline_block:text', 'field_block:node:page:body', 'inline_block:basic'], $this->pluginIds($sections), 'The blueprint\'s document, with the body field resolved against the page bundle; the template\'s block never seeded under it.');
    $text = $block_storage->loadRevision($sections[0]->getComponents()[array_key_first($sections[0]->getComponents())]->get('configuration')['block_revision_id']);
    $this->assertTrue($text->get('field_heading')->isEmpty(), 'A saved blueprint\'s empty field is present (a stub), so it copies empty: the sample fill is for ABSENT fields, a shipped file\'s or a skeleton capture\'s.');

    // The page-level styles landed on the page's own root, re-addressed
    // from the blueprint's uuid to the page's, and nothing of the
    // blueprint's identity survives in the map.
    $map = $this->host($fresh)->styleMap();
    $this->assertSame([
      'entity:' . $node->uuid() => ['default' => ['background-color' => 'aliceblue']],
      'entity:' . $node->uuid() . '::> *' => ['default' => ['gap' => '3rem']],
    ], $map);
    foreach (array_keys($map) as $key) {
      $this->assertStringNotContainsString($blueprint->uuid(), $key);
    }
    $this->assertCount(2, $this->host($fresh)->ownSections(), 'The reloaded page reads its own document, not a seed.');
  }

  /**
   * A shipped page blueprint starts a page the same way: the file lane.
   *
   * A file's page-level keys are bare (`entity:`, the shipper's spelling)
   * and its block nodes carry no fields, so the start re-addresses the
   * styles to the page's root by key kind and sample-populates the absent
   * heading, as a placement of the same file would.
   */
  public function testStartFromShippedPageBlueprint(): void {
    $this->enableModules(['daedalus_blueprint_library_start_test']);
    $this->container->get('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $record = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Blueprint, 'shipped_landing');
    $this->assertSame([
      'entity:' => ['default' => ['background-color' => 'honeydew']],
      'entity:::> *' => ['default' => ['gap' => '2rem']],
    ], $record->styles, 'A file\'s page-level keys are bare.');
    $this->assertSame(['shipped_landing'], array_keys($this->start()->starts($this->bundleHost('node', 'page'))));
    // The placement fill's ladder stops at the site's answer (a default
    // value or a configured generator; core's type-level invention is not
    // a placement rung), so the site answers for the absent heading.
    FieldConfig::loadByName('block_content', 'text', 'field_heading')->setDefaultValue([['value' => 'Your heading here']])->save();

    $node = Node::create(['type' => 'page', 'title' => 'Shipped']);
    $host = $this->host($node);
    $this->assertBatchSuccess($this->start()->apply($host, 'shipped_landing'));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);

    $fresh = $this->reload($node);
    $sections = $this->rawSections($fresh);
    $this->assertCount(1, $sections);
    $this->assertNull($sections[0]->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'));
    $this->assertSame(['inline_block:text', 'inline_block:basic'], $this->pluginIds($sections));
    $this->assertSame([
      'entity:' . $node->uuid() => ['default' => ['background-color' => 'honeydew']],
      'entity:' . $node->uuid() . '::> *' => ['default' => ['gap' => '2rem']],
    ], $this->host($fresh)->styleMap(), 'The bare keys re-address to the page\'s root.');
    $components = array_values($sections[0]->getComponents());
    $text = $this->container->get('entity_type.manager')->getStorage('block_content')->loadRevision($components[0]->get('configuration')['block_revision_id']);
    $this->assertSame('Your heading here', $text->get('field_heading')->value, 'An absent field sample-populated at the start, as a placement\'s does.');
  }

  /**
   * Blank writes core's marker: persisted, and read as no sections.
   */
  public function testBlank(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Blank']);
    $host = $this->host($node);
    $result = $this->start()->apply($host, PageStart::BLANK);
    $this->assertTrue($result->isSuccess());
    $this->assertSame($host, $result->getHost());
    $sections = $this->rawSections($node);
    $this->assertCount(1, $sections);
    $this->assertSame('layout_builder_blank', $sections[0]->getLayoutId(), 'Core\'s own marker: started, and empty.');

    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $fresh = $this->reload($node);
    $sections = $this->rawSections($fresh);
    $this->assertCount(1, $sections, 'The marker persisted with the one save.');
    $this->assertSame('layout_builder_blank', $sections[0]->getLayoutId());
    $fresh_host = $this->host($fresh);
    $this->assertSame([], $fresh_host->ownSections(), 'A blank page reads as no sections.');
    $this->assertSame([], $fresh_host->sections());
    $this->assertSame([], $fresh_host->styleMap(), 'Nothing of the template seeded under the marker.');
    $this->assertSame([], $this->pluginIds($this->rawSections($this->reload($node))), 'The template\'s block never arrived.');
  }

  /**
   * Template leaves the field empty, and the first read seeds it.
   */
  public function testTemplate(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Templated']);
    $host = $this->host($node);
    $result = $this->start()->apply($host, PageStart::TEMPLATE);
    $this->assertTrue($result->isSuccess());
    $this->assertSame([], $this->rawSections($node), 'Template does nothing to the field.');

    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $fresh = $this->reload($node);
    $this->assertSame([], $this->rawSections($fresh), 'An empty field is saved: the page follows its template.');
    $seeded = $this->host($fresh)->ownSections();
    $this->assertCount(1, $seeded, 'The first read of the document seeds it from the template.');
    $this->assertSame(['system_powered_by_block'], $this->pluginIds($seeded));
    $this->assertNotSame($this->templateSection, $seeded[0]->getThirdPartySetting('daedalus_layout', 'uuid'), 'The seed derives the page\'s own identity.');

    // The plain bundle's pages render the template they do not own:
    // template leaves them as they are too, and is the one reserved start
    // a surface offers them (blank opens no page of their own).
    $plain = $this->bundleHost('node', 'plain');
    $this->assertTrue($plain->hasDocument(), 'The locked bundle carries the override field: its pages own what they hold inside the template.');
    $this->assertTrue($this->start()->takesStart($plain), 'A locked page takes a start: the fills of its template\'s slots.');
    $this->assertTrue($this->start()->templateOffered($plain), 'The locked template holds a document to follow.');
    $this->assertFalse($this->start()->blankOffered($plain), 'Blank is no start to offer a locked page: its render is the template\'s whatever it fills.');
    $this->assertTrue($this->start()->blankOffered($this->bundleHost('node', 'page')), 'An overridable page has an empty page of its own to open.');
    $this->assertTrue($this->start()->apply($plain, PageStart::TEMPLATE)->isSuccess());
    $this->assertSame([], $this->rawSections($plain->fields()), 'Template fills nothing.');
  }

  /**
   * A locked bundle's page starts as a set of slot fills, or as nothing.
   *
   * The offer judges the fit one way more on a locked host: every root of
   * the page blueprint fills a slot the template places (the whole page
   * document and a fill of an unplaced slot are not offered, and apply()
   * refuses them BLUEPRINT_DOES_NOT_FIT naming the slots). A fitting
   * blueprint lands its roots as the page's slot sections, tagged at
   * birth, under the lock, with fresh identity and no provenance, in the
   * one save; blank writes nothing (never core's marker: a locked page's
   * field is its fills or nothing).
   */
  public function testStartOnLockedBundleFillsSlots(): void {
    $this->createFillBlueprint('hero_fill', ['hero']);
    $this->createFillBlueprint('aside_fill', ['aside']);
    $this->createFillBlueprint('both_fill', ['hero', 'aside']);
    $this->createBlueprint([
      'label' => 'Whole page',
      'machine_name' => 'whole_page',
      'description' => 'A page document needing nothing of its host.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'plain',
    ]);
    $plain = $this->bundleHost('node', 'plain');
    $this->assertSame(['hero_fill'], array_keys($this->start()->starts($plain)), 'Offered: the fills of the slots the template places, nothing whole, nothing filling an unplaced slot.');
    $this->assertSame(['aside_fill', 'both_fill', 'hero_fill', 'whole_page'], array_keys($this->start()->starts($this->bundleHost('node', 'page'))), 'An overridable bundle is offered every fitting page blueprint; a fill is a page document like any other there.');

    $node = Node::create(['type' => 'plain', 'title' => 'Filled']);
    $host = $this->host($node);
    $blocks_before = $this->blockIds();
    $result = $this->start()->apply($host, 'hero_fill');
    $this->assertBatchSuccess($result);
    $this->assertSame($host, $result->getHost());
    $this->assertTrue($node->isNew(), 'The start persists nothing.');
    $sections = $this->rawSections($node);
    $this->assertCount(1, $sections, 'The fill stands on the unsaved entity.');
    $this->assertSame('hero', LayoutSerializer::slotOf($sections[0]), 'Tagged with the slot it fills at birth.');
    $this->assertNull($sections[0]->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'), 'A started page is not a placement.');

    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $fresh = $this->reload($node);
    $this->assertCount(1, array_diff($this->blockIds(), $blocks_before), 'The fill\'s text block was saved once, with the page.');
    $fresh_host = $this->host($fresh);
    $own = $fresh_host->ownSections();
    $this->assertCount(1, $own, 'The reloaded page owns its fill and nothing else: never seeded, never narrowed.');
    $this->assertSame('hero', LayoutSerializer::slotOf($own[0]));
    $this->assertSame(['inline_block:text'], $this->pluginIds($own));
    $this->assertCount(2, $fresh_host->sections(), 'The render is the template, then the fill.');
    $this->assertSame($this->lockedSection, $fresh_host->sections()[0]->getThirdPartySetting('daedalus_layout', 'uuid'));

    // Blank fills nothing, and writes no marker.
    $blank = Node::create(['type' => 'plain', 'title' => 'Blank']);
    $blank_host = $this->host($blank);
    $this->assertTrue($this->start()->apply($blank_host, PageStart::BLANK)->isSuccess());
    $this->assertSame([], $this->rawSections($blank), 'Nothing written: a locked page\'s field is its fills or nothing.');
    $this->container->get('daedalus_blueprint.storage_handler')->commit($blank_host);
    $this->assertSame([], $this->rawSections($this->reload($blank)));
    $this->assertSame([], $this->host($this->reload($blank))->ownSections());

    // What is not a set of fills of the placed slots does not fit.
    foreach (['whole_page', 'aside_fill', 'both_fill'] as $start) {
      $refused_host = $this->host(Node::create(['type' => 'plain', 'title' => 'Refused']));
      $result = $this->start()->apply($refused_host, $start);
      $this->assertRefused($result, 'BLUEPRINT_DOES_NOT_FIT', $refused_host);
      $this->assertSame(['blueprint' => $start, 'subject' => 'node:plain', 'slots' => ['hero']], $result->getErrors()[0]->getContext());
      $this->assertStringContainsString('owns only the slots its template places (hero)', $result->getErrors()[0]->getMessage());
    }
  }

  /**
   * The starts a bundle's host is offered: the offer, asked for page scope.
   */
  public function testStarts(): void {
    $this->createLandingBlueprint();
    $this->createBlueprint([
      'label' => 'Whole page',
      'machine_name' => 'whole_page',
      'description' => 'A page document needing nothing of its host.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ]);
    $this->createBlueprint([
      'label' => 'Uncomposed page',
      'machine_name' => 'uncomposed_page',
      'description' => 'A page blueprint composed from scratch, no root yet.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ], []);
    $this->createBlueprint([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'A block blueprint any host can hold.',
    ]);

    $starts = $this->start()->starts($this->bundleHost('node', 'page'));
    $this->assertSame(['landing', 'whole_page'], array_keys($starts), 'Page scope, a root, fits: keyed by id, by label.');
    $this->assertSame('Landing', $starts['landing']->label);
    $this->assertSame(['whole_page'], array_keys($this->start()->starts($this->bundleHost('entity_test', 'entity_test'))), 'The body field is not placeable on entity_test, so the landing page is not a start there.');
    $this->assertSame([], array_keys($this->start()->starts($this->bundleHost('node', 'plain'))), 'A locked bundle is offered fills of its template\'s slots alone: a whole page document is no start there.');
  }

  /**
   * The order: favorites first, then the rest, each run by label.
   *
   * The roster's order is provider, then entity id, which means nothing
   * to an editor; the verb answers the one order every offering surface
   * shows (the chooser's cards, the schema's starts): the site's
   * favorites (the record's own default) ahead of the rest, and each run
   * by label, natural ("Band 2" before "Band 10") and case-insensitive
   * ("alpha" before "Zed"). The fixture's machine names are chosen so the
   * roster's order (by entity id, the order written here) is neither.
   */
  public function testStartsOrder(): void {
    $page = [
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ];
    $this->createBlueprint(['label' => 'Zed', 'machine_name' => 'zed', 'favorite' => TRUE] + $page);
    $this->createBlueprint(['label' => 'Band 10', 'machine_name' => 'band_ten'] + $page);
    $this->createBlueprint(['label' => 'alpha', 'machine_name' => 'alpha', 'favorite' => TRUE] + $page);
    $this->createBlueprint(['label' => 'Band 2', 'machine_name' => 'band_two'] + $page);
    $this->createBlueprint(['label' => 'Aside', 'machine_name' => 'aside'] + $page);

    $starts = $this->start()->starts($this->bundleHost('node', 'page'));
    $this->assertSame(['alpha', 'zed', 'aside', 'band_two', 'band_ten'], array_keys($starts), 'Favorites first, then the rest, each by label, natural and case-insensitive.');
    $this->assertTrue($starts['alpha']->favorite);
    $this->assertFalse($starts['aside']->favorite);

    // The order is the verb's, not a surface's: the schema's projection
    // keeps it as handed.
    $this->assertSame(['alpha', 'zed', 'aside', 'band_two', 'band_ten'], array_keys($this->container->get('daedalus_blueprint.materials_schema')->starts($starts)));
  }

  /**
   * Each refusal: its code, at the start path, and nothing staged.
   */
  public function testRefusals(): void {
    $this->createLandingBlueprint();
    $uncomposed = $this->createBlueprint([
      'label' => 'Uncomposed page',
      'machine_name' => 'uncomposed_page',
      'description' => 'A page blueprint composed from scratch, no root yet.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ], []);
    $this->createBlueprint([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'A block blueprint any host can hold.',
    ]);

    // A saved entity never takes a start, whichever it is: a whole layout
    // is written only at creation.
    $saved = Node::create(['type' => 'page', 'title' => 'Saved']);
    $saved->save();
    $saved_host = $this->host($saved);
    $result = $this->start()->apply($saved_host, 'landing');
    $this->assertRefused($result, 'START_ON_EXISTING_ENTITY', $saved_host);
    $this->assertSame(['entity_type' => 'node', 'entity_id' => $saved->id()], $result->getErrors()[0]->getContext());
    $this->assertStringContainsString('place_blueprint', $result->getErrors()[0]->getHint());
    $this->assertRefused($this->start()->apply($saved_host, PageStart::BLANK), 'START_ON_EXISTING_ENTITY', $saved_host);
    $this->assertRefused($this->start()->apply($saved_host, PageStart::TEMPLATE), 'START_ON_EXISTING_ENTITY', $saved_host);
    $this->assertSame([], $this->rawSections($this->reload($saved)), 'The saved page is untouched.');

    // A host with no document door has nowhere to put a start.
    $doorless = $this->host(EntityTestRev::create());
    $this->assertFalse($doorless->hasDocument());
    $this->assertRefused($this->start()->apply($doorless, PageStart::BLANK), 'NO_LAYOUT', $doorless);
    $this->assertRefused($this->start()->apply($doorless, 'landing'), 'NO_LAYOUT', $doorless);

    // An unknown id names what is available: the two words and the page
    // blueprints offered for this host.
    $host = $this->host(Node::create(['type' => 'page', 'title' => 'New']));
    $result = $this->start()->apply($host, 'does_not_exist');
    $this->assertRefused($result, 'BLUEPRINT_NOT_FOUND', $host);
    $this->assertSame('structural', $result->getPhase());
    $this->assertSame(['blank', 'template', 'landing'], $result->getErrors()[0]->getContext()['available']);

    // A block blueprint is never a start: the corrective names its door.
    $result = $this->start()->apply($host, 'band');
    $this->assertRefused($result, 'BLUEPRINT_WRONG_SCOPE', $host);
    $this->assertSame(['blueprint' => 'band', 'scope' => 'block'], $result->getErrors()[0]->getContext());
    $this->assertStringContainsString('place_blueprint', $result->getErrors()[0]->getHint());

    // An empty page blueprint starts nothing; the corrective names its
    // canvas through the page tools' address.
    $result = $this->start()->apply($host, 'uncomposed_page');
    $this->assertRefused($result, 'BLUEPRINT_EMPTY', $host);
    $this->assertSame(['blueprint' => 'uncomposed_page'], $result->getErrors()[0]->getContext());
    $this->assertStringContainsString('entity_type: blueprint and entity_id: ' . $uncomposed->id(), $result->getErrors()[0]->getHint());

    // A page blueprint placing a field the host lacks does not fit.
    $test_host = $this->host(EntityTest::create());
    $result = $this->start()->apply($test_host, 'landing');
    $this->assertRefused($result, 'BLUEPRINT_DOES_NOT_FIT', $test_host);
    $context = $result->getErrors()[0]->getContext();
    $this->assertSame('landing', $context['blueprint']);
    $this->assertSame('entity_test:entity_test', $context['subject']);
    $this->assertSame(['body'], $context['missing'][BlueprintRequirements::FIELDS]);
    $this->assertStringContainsString('does not fit this entity_test:entity_test', $result->getErrors()[0]->getMessage());
    $this->assertTrue($host->fields()->isNew(), 'No refusal saved anything.');
  }

  /**
   * A locked page saved as a blueprint is its fills, a page blueprint of slots.
   *
   * The whole-document capture reads the host's OWN document
   * (NodeTreeBuilder::buildOwn()): on a locked page the slot sections it
   * fills, each root tagged with its slot, never the template it is
   * rendered inside (the owner's). So an editor authors a fill blueprint
   * from a page they filled, and the locked bundle is offered it as a
   * start.
   */
  public function testCaptureOfLockedPageIsItsFills(): void {
    $node = Node::create(['type' => 'plain', 'title' => 'Filled']);
    $node->save();
    $host = $this->host($this->reload($node));
    $door = NULL;
    $walk = static function (array $nodes) use (&$walk, &$door): void {
      foreach ($nodes as $node) {
        if (($node['kind'] ?? NULL) === 'block' && ($node['slot'] ?? NULL) === 'hero') {
          $door = $node['uuid'];
        }
        $walk($node['children'] ?? []);
      }
    };
    $walk($this->container->get('daedalus_blueprint.node_tree_builder')->build($host));
    $this->assertNotNull($door, 'The template\'s hero door is in the page\'s tree.');
    $this->assertBatchSuccess($this->operations()->batchOperations($host, [
      [
        'op' => 'add_element',
        'block_type' => 'text',
        'properties' => ['field_heading' => 'Hello'],
        'location' => ['anchor' => $door, 'placement' => 'inside'],
      ],
    ]));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($host);
    $host = $this->host($this->reload($node));
    $this->assertCount(2, $host->sections(), 'The render: the template, then the fill.');
    $this->assertCount(1, $host->ownSections());

    $this->assertBatchSuccess($this->operations()->batchOperations($host, [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $host->uuid(),
        'label' => 'Hero fill',
        'description' => 'What this page put in its hero slot.',
      ],
    ]));
    $saved = $this->container->get('entity_type.manager')->getStorage('blueprint')->loadByProperties(['label' => 'Hero fill']);
    $this->assertCount(1, $saved);
    $saved = reset($saved);
    $this->assertSame(BlueprintInterface::SCOPE_PAGE, $saved->getScope());
    $this->assertSame(['node', 'plain'], [$saved->getSubjectType(), $saved->getSubjectBundle()]);
    $layout = $this->container->get('daedalus_blueprint_library.discovery')->getBlueprint($saved->getMachineName())['layout'];
    $this->assertCount(1, $layout, 'The fill alone; the template is the owner\'s, never captured: ' . json_encode($layout));
    $this->assertSame('hero', $layout[0]['slot']);
    $this->assertSame(['inline_block:text'], $this->pluginIds($this->host($saved)->ownSections()));
    $this->assertContains($saved->getMachineName(), array_keys($this->start()->starts($this->bundleHost('node', 'plain'))), 'The locked bundle is offered the fill as a start.');
  }

}
