<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_layout\DaedalusLayoutEntityHelperTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the storage spine's shared defaults->overrides upgrade helper.
 *
 * A not-yet-overridden root entity (its layout field is EMPTY) must yield an
 * OVERRIDES section storage from the render path (DaedalusLayoutEntityHelperTrait,
 * what daedalus_layout publishes to the editing UI) with the same tempstore key the
 * tempstore write path (LayoutTempstoreStrategy) resolves, seeded from the
 * display's template as a COPY with fresh identity: every element uuid is
 * derived from the page (DocumentInstantiator::derivedUuid()), so no page
 * carries a template uuid, no two pages share an element uuid, and the
 * template's styles re-key onto the seeded identity in the page's own map.
 * Before the shared helper, the render path returned the shared bundle
 * display's DEFAULTS storage and keyed the view mode as the literal 'full'
 * while the tempstore keyed the resolved display mode ('default'), so the UI
 * and the staged draft disagreed; before the instantiator, the seed was a
 * by-reference copy sharing the template's uuids.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class StorageSpineUpgradeTest extends NestedStorageKernelTestBase {

  use LegacyLayoutBlockFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'navigation',
    'twig_events',
    'daedalus_stylish',
    // The unbound site: literal paddings land as written.
    'daedalus_stylish_unbound_test',
    // Declares yaml_setting as a managed key of yaml_test_block.
    'daedalus_blueprint_test',
  ];

  /**
   * The template section's uuid on the page display.
   */
  protected const TEMPLATE_SECTION = '7e000000-0000-4000-8000-000000000001';

  /**
   * The uuid of the inline block a leg places on the template.
   */
  protected const TEMPLATE_BLOCK = '7e000000-0000-4000-8000-000000000002';

  /**
   * A test consumer of DaedalusLayoutEntityHelperTrait.
   */
  protected object $traitConsumer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The pre-migration shape: the retired layout_block bundle.
    $this->createLayoutBlockType();

    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('daedalus_stylish.field_manager')->enable('node', 'page');
    // A styled template: one section wearing a padding in the display's
    // own map, keyed by the template section's uuid.
    $template = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => self::TEMPLATE_SECTION]]);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->setThirdPartySetting('daedalus_stylish', 'styles', [
        ['key' => 'container:' . self::TEMPLATE_SECTION, 'entry' => ['default' => ['padding' => '2rem']]],
      ])
      ->save();
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $display->removeAllSections()->appendSection($template)->save();

    $this->traitConsumer = new class() {
      use DaedalusLayoutEntityHelperTrait;

      /**
       * Exposes the trait's protected storage lookup.
       */
      public function storage(EntityInterface $entity) {
        return $this->getSectionStorageForEntity($entity);
      }

    };
  }

  /**
   * An empty-layout block_content root upgrades to an overrides storage.
   */
  public function testEmptyLayoutBlockContentYieldsOverridesStorage(): void {
    $block_content = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Footer',
      'reusable' => TRUE,
    ]);
    $block_content->save();
    $this->assertTrue($block_content->get(OverridesSectionStorage::FIELD_NAME)->isEmpty());

    $this->assertUpgradedStorage($block_content, 'block_content.layout_block.default');
  }

  /**
   * An empty-layout node root upgrades to an overrides storage.
   */
  public function testEmptyLayoutNodeYieldsOverridesStorage(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Empty layout page']);
    $node->save();
    $this->assertTrue($node->get(OverridesSectionStorage::FIELD_NAME)->isEmpty());

    $this->assertUpgradedStorage($node, 'node.page.default');
  }

  /**
   * Two pages seeded from a styled template share no element uuid.
   *
   * The seed is a copy with identity derived from the page: the template's
   * uuid is on neither page, the template's style lands re-keyed in each
   * page's own map, and a page reads the same seed on every load.
   */
  public function testSeededPagesShareNoIdentity(): void {
    $first = Node::create(['type' => 'page', 'title' => 'First page']);
    $first->save();
    $second = Node::create(['type' => 'page', 'title' => 'Second page']);
    $second->save();

    $first_uuid = $this->seededSectionUuid($first);
    $second_uuid = $this->seededSectionUuid($second);
    $this->assertNotSame(self::TEMPLATE_SECTION, $first_uuid, 'The template keeps its identity to itself.');
    $this->assertNotSame(self::TEMPLATE_SECTION, $second_uuid);
    $this->assertNotSame($first_uuid, $second_uuid, 'Two pages seeded from one template share no element uuid.');
    $this->assertSame(DocumentInstantiator::derivedUuid($first->uuid(), self::TEMPLATE_SECTION), $first_uuid);
    $this->assertSame(DocumentInstantiator::derivedUuid($second->uuid(), self::TEMPLATE_SECTION), $second_uuid);

    // The template's style re-keyed onto the seeded identity, in each
    // page's own map; the template's key is nowhere on the page.
    foreach ([[$first, $first_uuid], [$second, $second_uuid]] as [$node, $uuid]) {
      $map = json_decode((string) $node->get('daedalus_stylish_styles')->value, TRUE);
      $this->assertSame(['default' => ['padding' => '2rem']], $map["container:$uuid"]);
      $this->assertArrayNotHasKey('container:' . self::TEMPLATE_SECTION, $map);
    }

    // The same page reads the same seed again: identity is a function of
    // the page and the template, not of the request.
    $this->assertSame($first_uuid, $this->seededSectionUuid(Node::load($first->id())));

    // The template itself is untouched.
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $this->assertSame(self::TEMPLATE_SECTION, $display->getSections()[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame(['container:' . self::TEMPLATE_SECTION], array_keys($this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display)->styleMap()));
  }

  /**
   * The style map door seeds the document too: one seed, either door first.
   *
   * A reader taking the map before the sections (the serializer's order)
   * used to see the stored map, empty, and then a seeded document whose
   * styles were nowhere: half a seed. The host seeds on the first read of
   * EITHER door, so the map read cold carries the template's style
   * re-keyed onto the derived identity and the sections read next are
   * the document it addresses; nothing is persisted.
   */
  public function testStyleMapReadSeedsTheDocument(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Map-first page']);
    $node->save();
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($node);
    $uuid = DocumentInstantiator::derivedUuid($node->uuid(), self::TEMPLATE_SECTION);

    $map = $host->styleMap();
    $this->assertSame(['default' => ['padding' => '2rem']], $map["container:$uuid"] ?? NULL, 'The map read first carries the seeded style under the derived key.');
    $this->assertArrayNotHasKey('container:' . self::TEMPLATE_SECTION, $map);
    $sections = $host->sections();
    $this->assertCount(1, $sections);
    $this->assertSame($uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'), 'The sections read next are the document the map addresses: one seed.');

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$node->id()]);
    $saved = Node::load($node->id());
    $this->assertTrue($saved->get(OverridesSectionStorage::FIELD_NAME)->isEmpty(), 'The seed is in memory: the saved page holds no override.');
    $this->assertTrue($saved->get('daedalus_stylish_styles')->isEmpty(), 'The saved page holds no styles.');
  }

  /**
   * A seeded inline block keeps its managed configuration and shown label.
   *
   * The template's block is placed through the display host's own op door
   * with a managed key and a visible label; the seed reads the template as
   * the lean tree (the block node carrying `configuration` and
   * `label_rendered`) and instantiates it, so the page's copy carries the
   * same placement configuration under its own identity.
   */
  public function testSeededInlineBlockKeepsManagedConfiguration(): void {
    // Editing the template is the admin's gesture.
    $this->setUpCurrentUser([], [], TRUE);
    $this->createBlockContentType('yaml_test_block', 'YAML block');
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $template = $resolver->fromEntity(LayoutBuilderEntityViewDisplay::load('node.page.default'));
    $result = $this->container->get('daedalus_blueprint.operations')->batchOperations($template, [
      [
        'op' => 'add_element',
        'uuid' => self::TEMPLATE_BLOCK,
        'block_type' => 'yaml_test_block',
        'properties' => [
          'yaml_setting' => 'From the template',
          'label' => 'Ribbon',
          'label_display' => 'visible',
        ],
        'location' => ['anchor' => self::TEMPLATE_SECTION, 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->container->get('daedalus_blueprint.storage_handler')->commit($template);

    $node = Node::create(['type' => 'page', 'title' => 'Seeded page']);
    $node->save();
    $storage = $this->traitConsumer->storage($node);
    $this->assertInstanceOf(OverridesSectionStorage::class, $storage);
    $component = $storage->getSections()[0]->getComponent(DocumentInstantiator::derivedUuid($node->uuid(), self::TEMPLATE_BLOCK));
    $configuration = $component->get('configuration');
    $this->assertSame('inline_block:yaml_test_block', $configuration['id']);
    $this->assertSame('From the template', $configuration['yaml_setting'], 'The managed key seeded with the block.');
    $this->assertSame('Ribbon', $configuration['label']);
    $this->assertSame('visible', $configuration['label_display'], 'The shown label seeded as shown.');
  }

  /**
   * An already-overridden entity keeps its own sections (no re-seeding).
   */
  public function testOverriddenEntityKeepsOwnSections(): void {
    $section = $this->createSection();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Overridden page',
      OverridesSectionStorage::FIELD_NAME => [$section],
    ]);
    $node->save();

    $storage = $this->traitConsumer->storage($node);
    $this->assertInstanceOf(OverridesSectionStorage::class, $storage);
    $this->assertCount(1, $storage->getSections());
    $this->assertSame(
      $section->getThirdPartySetting('daedalus_layout', 'uuid'),
      $storage->getSections()[0]->getThirdPartySetting('daedalus_layout', 'uuid')
    );
  }

  /**
   * The uuid of the one section the render path seeds onto an entity.
   */
  protected function seededSectionUuid(EntityInterface $entity): string {
    $storage = $this->traitConsumer->storage($entity);
    $this->assertInstanceOf(OverridesSectionStorage::class, $storage);
    $sections = $storage->getSections();
    $this->assertCount(1, $sections);
    return $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid');
  }

  /**
   * Asserts the upgraded storage shape and key agreement for a root entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A saved entity whose layout field is empty.
   * @param string $display_id
   *   The Layout Builder enabled view display config ID.
   */
  protected function assertUpgradedStorage(EntityInterface $entity, string $display_id): void {
    // The render path yields an overrides storage (the entity's own
    // document, never the shared display's), seeded with a copy of the
    // bundle display's template: as many sections, none of them carrying a
    // template uuid.
    $storage = $this->traitConsumer->storage($entity);
    $this->assertInstanceOf(OverridesSectionStorage::class, $storage);
    $display = LayoutBuilderEntityViewDisplay::load($display_id);
    $this->assertCount(count($display->getSections()), $storage->getSections());
    $template_uuids = array_map(static fn (Section $section) => $section->getThirdPartySetting('daedalus_layout', 'uuid'), $display->getSections());
    foreach ($storage->getSections() as $section) {
      $this->assertNotContains($section->getThirdPartySetting('daedalus_layout', 'uuid'), $template_uuids, 'The seed is a copy with its own identity.');
    }

    // The tempstore write path resolves the SAME storage identity: entity
    // and render-path storage produce one key in the overrides collection.
    $strategy = $this->container->get('daedalus_tempstore.strategy.layout');
    $entity_key = $strategy->getKey($entity);
    $this->assertSame($entity_key, $strategy->getKey($storage));
    $this->assertSame('layout_builder.section_storage.overrides', $strategy->getCollection($entity));
    $this->assertSame('layout_builder.section_storage.overrides', $strategy->getCollection($storage));

    // The view mode context is the RESOLVED display mode, not the requested
    // 'full' literal: these bundles have no 'full' display, so a 'full' key
    // would never match what the tempstore stores.
    $this->assertStringContainsString('.default', $entity_key);
    $this->assertStringNotContainsString('.full', $entity_key);
  }

}
