<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the legacy layout-block migration end to end.
 *
 * Covers LayoutBlockToSectionTransform through the real store walkers and
 * the presave safety net, plus the daedalus_layout post_update sequence (orphaned
 * block_content GC, bundle retirement). The daedalus_stylish module rides along for
 * the style-map key migration; daedalus_stylish already reuses this module's kernel
 * test base, so the test-time coupling runs in both directions.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class LayoutBlockMigrationTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   *
   * The retirement update rewrites the per-display `block_config` TPS, an
   * old shape whose schema mapping is retired (block icons live on the
   * block TYPE now); this test plants and asserts that raw old shape,
   * exactly what a pre-migration site carries, so this display's saves
   * skip schema checking.
   */
  protected static $configSchemaCheckerExclusions = [
    'core.entity_view_display.entity_test.entity_test.default',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Persists an entity carrying the pre-migration shape.
   *
   * A syncing save bypasses the presave safety net (and core's inline block
   * machinery), which is exactly how pre-migration data exists on a site
   * the update hooks have not run on yet.
   */
  protected function createLegacyEntity(array $sections, array $values = []): EntityTest {
    $entity = EntityTest::create($values + [
      OverridesSectionStorage::FIELD_NAME => $sections,
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();
    return $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
  }

  /**
   * Runs the host entity walker to completion.
   */
  protected function walkHosts(): void {
    /** @var \Drupal\daedalus_layout\ComponentTransform\HostEntityWalker $walker */
    $walker = $this->container->get('daedalus_layout.host_entity_walker');
    $sandbox = [];
    do {
      $walker->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
  }

  /**
   * The walker converts nested legacy layout blocks, deepest-first.
   */
  public function testWalkerConvertsLayoutBlocksAtDepth(): void {
    $inner_section_uuid = '11111111-1111-1111-1111-111111111111';
    $deep_section_uuid = '22222222-2222-2222-2222-222222222222';
    $outer_component_uuid = '33333333-3333-3333-3333-333333333333';
    $deep_component_uuid = '44444444-4444-4444-4444-444444444444';
    $field_block_uuid = '55555555-5555-5555-5555-555555555555';
    $leaf_uuid = '66666666-6666-6666-6666-666666666666';

    // Depth 2: a layout block inside a layout block, with a leaf inline
    // block at the bottom and a field block remapped onto the legacy
    // root-entity context beside it.
    $deep_section = $this->createSection('layout_onecol', [
      $this->createBasicBlockComponent($leaf_uuid),
    ], $deep_section_uuid);
    $deep_layout_block = $this->createLayoutBlockComponent([$deep_section], $deep_component_uuid);

    $field_block = new SectionComponent($field_block_uuid, 'content', [
      'id' => 'field_block:entity_test:entity_test:name',
      'label' => 'Name',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'daedalus_layout.root_entity', 'view_mode' => 'view_mode'],
    ]);
    $inner_section = $this->createSection('layout_onecol', [$deep_layout_block, $field_block], $inner_section_uuid);
    $outer_layout_block = $this->createLayoutBlockComponent([$inner_section], $outer_component_uuid, 'content', 'My Hero');

    $entity = $this->createLegacyEntity([$this->createSection('layout_onecol', [$outer_layout_block])]);
    $persisted = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->getComponent($outer_component_uuid);
    $this->assertSame('inline_block:layout_block', $persisted->get('configuration')['id'], 'The legacy shape persisted untouched through the syncing save.');

    $this->walkHosts();

    $entity = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();

    // The outer wrapper became a container carrying the section's identity.
    $container = $sections[0]->getComponent($inner_section_uuid);
    $configuration = $container->get('configuration');
    $this->assertSame(SectionBlock::PLUGIN_ID, $configuration['id']);
    $this->assertSame(SectionBlock::CONTEXT_MAPPING, $configuration['context_mapping']);
    $embedded = SectionBlock::embeddedSection($container);
    $this->assertSame($inner_section_uuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame('My Hero', $embedded->getLayoutSettings()['label'], 'A custom admin label migrated to layout_settings.label.');

    // Deepest-first: the nested layout block converted inside the embedded
    // tree, and the shipped default label did not migrate.
    $deep_container = $embedded->getComponent($deep_section_uuid);
    $deep_embedded = SectionBlock::embeddedSection($deep_container);
    $this->assertSame($deep_section_uuid, $deep_embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
    // Layout plugin defaults contribute an empty label; the shipped default
    // admin label must not have migrated into it.
    $this->assertSame('', $deep_embedded->getLayoutSettings()['label'] ?? '');

    // The field block came off the legacy root-entity context.
    $migrated_field_block = $embedded->getComponent($field_block_uuid);
    $this->assertSame(
      ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
      $migrated_field_block->get('configuration')['context_mapping'],
    );

    // The walker's save ran the inline-block presave machinery: the leaf
    // block persisted and its usage row points at the ROOT host.
    $leaf_configuration = $deep_embedded->getComponent($leaf_uuid)->get('configuration');
    $this->assertNotEmpty($leaf_configuration['block_revision_id'], 'The serialized leaf inline block was saved during the walk.');
    $leaf = $this->entityTypeManager->getStorage('block_content')->loadRevision($leaf_configuration['block_revision_id']);
    $usage = $this->container->get('inline_block.usage')->getUsage($leaf->id());
    $this->assertNotFalse($usage, 'The leaf has a usage row.');
    $this->assertSame('entity_test', $usage->layout_entity_type);
    $this->assertSame((string) $entity->id(), (string) $usage->layout_entity_id);
  }

  /**
   * The presave safety net converges stragglers on ordinary saves.
   */
  public function testPresaveNetConvertsOnSave(): void {
    $section_uuid = '77777777-7777-7777-7777-777777777777';
    $component = $this->createLayoutBlockComponent([
      $this->createSection('layout_onecol', [], $section_uuid),
    ]);
    $entity = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => [$this->createSection('layout_onecol', [$component])],
    ]);
    $entity->save();

    $entity = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $container = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->getComponent($section_uuid);
    $this->assertSame(SectionBlock::PLUGIN_ID, $container->get('configuration')['id']);
  }

  /**
   * Style-map entries migrate from the wrapper block key to the section key.
   */
  public function testStyleMapMigration(): void {
    $outer_uuid = '88888888-8888-8888-8888-888888888888';
    $outer_section_uuid = '99999999-9999-9999-9999-999999999999';
    $variant_uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $variant_section_uuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    $outer = $this->createLayoutBlockComponent([
      $this->createSection('layout_onecol', [], $outer_section_uuid),
    ], $outer_uuid);
    $variant_block = $this->createLayoutBlockComponent([
      $this->createSection('layout_onecol', [], $variant_section_uuid),
    ], $variant_uuid);

    $entity = $this->createLegacyEntity(
      [$this->createSection('layout_onecol', [$outer, $variant_block])],
      [
        'daedalus_stylish_styles' => json_encode([
          "block:$outer_uuid" => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']],
          "block:$outer_uuid::.inner" => ['default' => ['gap' => '1rem']],
          // A pre-existing container-key entry (reachable through the AI
          // grammar even pre-migration) wins per property on merge.
          "container:$outer_section_uuid" => ['default' => ['color' => 'green']],
          // A single-colon block-field key has no post-migration element.
          "block:$outer_uuid:layout_builder__layout" => ['default' => ['margin' => '0']],
          "block:$variant_uuid" => ['variant' => 'some_variant'],
        ]),
      ],
    );

    $this->walkHosts();

    $entity = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $map = $entity->get('daedalus_stylish_styles')->first()->getStyleMap();

    $this->assertSame(
      ['default' => ['color' => 'green'], 'hover' => ['color' => 'blue']],
      $map["container:$outer_section_uuid"],
      'Conditions merged; the container entry won the conflicting property.',
    );
    $this->assertSame(['default' => ['gap' => '1rem']], $map["container:$outer_section_uuid::.inner"]);
    $this->assertSame(['variant' => 'some_variant'], $map["container:$variant_section_uuid"], 'A variant reference moved wholesale.');
    $this->assertArrayNotHasKey("block:$outer_uuid", $map);
    $this->assertArrayNotHasKey("block:$outer_uuid::.inner", $map);
    $this->assertArrayNotHasKey("block:$variant_uuid", $map);
    $this->assertArrayHasKey("block:$outer_uuid:layout_builder__layout", $map, 'Block-field keys of the wrapper stay behind inert.');
  }

  /**
   * Odd legacy shapes convert without data loss.
   */
  public function testEdgeShapes(): void {
    // Two sections in one layout block (Count(1,1) predates some data):
    // both survive as nested containers of a one-column wrapper.
    $multi_uuid = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    $first_uuid = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
    $second_uuid = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';
    $multi = $this->createLayoutBlockComponent([
      $this->createSection('layout_onecol', [], $first_uuid),
      $this->createSection('layout_onecol', [], $second_uuid),
    ], $multi_uuid);

    // An unresolvable layout block (no serialized copy, dangling revision
    // id) becomes a valid, empty container.
    $broken_uuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
    $broken = new SectionComponent($broken_uuid, 'content', [
      'id' => 'inline_block:layout_block',
      'label' => 'Layout Block',
      'label_display' => FALSE,
      'block_revision_id' => 999999,
    ]);

    // Two components sharing copied configuration duplicate the embedded
    // section uuid; the second occurrence re-mints.
    $dupe_section_uuid = '12121212-1212-1212-1212-121212121212';
    $dupe_a = $this->createLayoutBlockComponent([
      $this->createSection('layout_onecol', [], $dupe_section_uuid),
    ], '13131313-1313-1313-1313-131313131313');
    $dupe_b = new SectionComponent('14141414-1414-1414-1414-141414141414', 'content', $dupe_a->get('configuration'));

    $entity = $this->createLegacyEntity([
      $this->createSection('layout_onecol', [$multi, $broken, $dupe_a, $dupe_b]),
    ]);

    $this->walkHosts();

    $entity = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $section = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
    $components = $section->getComponents();
    $this->assertCount(4, $components);

    $by_plugin = array_filter($components, fn (SectionComponent $component) => SectionBlock::isSectionComponent($component));
    $this->assertCount(4, $by_plugin, 'Every legacy component became a container.');

    // The multi-section wrapper nests both original sections.
    $wrapper = NULL;
    foreach ($components as $component) {
      $embedded = SectionBlock::embeddedSection($component);
      $nested_uuids = array_map(
        fn (SectionComponent $nested) => $nested->getUuid(),
        array_values($embedded->getComponents()),
      );
      if (in_array($first_uuid, $nested_uuids, TRUE)) {
        $wrapper = $embedded;
      }
    }
    $this->assertNotNull($wrapper, 'The multi-section layout block wrapped its sections.');
    $this->assertSame($first_uuid, SectionBlock::embeddedSection($wrapper->getComponent($first_uuid))->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame($second_uuid, SectionBlock::embeddedSection($wrapper->getComponent($second_uuid))->getThirdPartySetting('daedalus_layout', 'uuid'));

    // Every container (including the unresolvable one, now an empty
    // one-column section) hydrates without throwing.
    foreach ($components as $component) {
      $this->assertNotNull(SectionBlock::embeddedSection($component));
    }

    // The duplicated pair diverged: one kept the section uuid, one re-minted.
    $container_uuids = array_map(fn (SectionComponent $component) => $component->getUuid(), array_values($components));
    $this->assertContains($dupe_section_uuid, $container_uuids);
    $this->assertCount(4, array_unique($container_uuids), 'Copied configuration no longer shares identity.');
  }

  /**
   * The GC post update deletes orphaned layout_block entities and rows.
   */
  public function testGcDeletesOrphanedLayoutBlocks(): void {
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $usage = $this->container->get('inline_block.usage');

    // Old-model persisted state: a saved layout_block entity referenced by
    // revision id, holding a saved leaf block whose usage row points at the
    // layout_block entity (the old immediate-parent host).
    $leaf = BlockContent::create([
      'type' => 'basic',
      'info' => 'Leaf',
      'reusable' => FALSE,
      'body' => ['value' => 'Leaf body'],
    ]);
    $leaf->save();
    $leaf_component_uuid = '21212121-2121-2121-2121-212121212121';
    $inner_section = $this->createSection('layout_onecol', [
      new SectionComponent($leaf_component_uuid, 'content', [
        'id' => 'inline_block:basic',
        'label' => 'Leaf',
        'label_display' => FALSE,
        'block_revision_id' => $leaf->getRevisionId(),
      ]),
    ], '23232323-2323-2323-2323-232323232323');

    $layout_block = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Test layout block',
      'reusable' => FALSE,
      'layout_builder__layout' => [$inner_section],
    ]);
    $layout_block->setSyncing(TRUE);
    $layout_block->save();

    $component = new SectionComponent('24242424-2424-2424-2424-242424242424', 'content', [
      'id' => 'inline_block:layout_block',
      'label' => 'Layout Block',
      'label_display' => FALSE,
      'block_revision_id' => $layout_block->getRevisionId(),
    ]);
    $entity = $this->createLegacyEntity([$this->createSection('layout_onecol', [$component])]);

    $usage->addUsage($layout_block->id(), $entity);
    $usage->addUsage($leaf->id(), $layout_block);

    $this->walkHosts();

    // The leaf's usage row re-pointed at the root host during the walk.
    $leaf_usage = $usage->getUsage($leaf->id());
    $this->assertNotFalse($leaf_usage, 'The leaf still has a usage row.');
    $this->assertSame('entity_test', $leaf_usage->layout_entity_type);
    $this->assertSame((string) $entity->id(), (string) $leaf_usage->layout_entity_id);
    // The orphan still exists: the transform never deletes it inline.
    $this->assertNotNull($block_storage->loadUnchanged($layout_block->id()));

    require_once $this->container->get('extension.list.module')->getPath('daedalus_layout') . '/daedalus_layout.post_update.php';
    $sandbox = [];
    do {
      daedalus_layout_post_update_0003_layout_block_gc($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $block_storage->resetCache();
    $this->assertNull($block_storage->loadUnchanged($layout_block->id()), 'The orphaned layout_block entity was deleted.');
    $this->assertFalse($usage->getUsage($layout_block->id()), 'Its usage rows went with it.');
    $this->assertNotNull($block_storage->loadUnchanged($leaf->id()), 'The leaf block survived.');
    $this->assertNotEmpty($usage->getUsage($leaf->id()));
  }

  /**
   * The GC keeps standalone roots: reusable and region-placed entities.
   *
   * A layout_block placed in a theme region (e.g. a site footer) or marked
   * reusable is live standalone content under the current model, not a
   * nested-model orphan; deleting it would leave block.block.* placements
   * pointing at nothing. The retirement update must then also keep the
   * bundle, displays, and field, or the survivors' layout data would purge.
   */
  public function testGcKeepsRegionPlacedAndReusableLayoutBlocks(): void {
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $usage = $this->container->get('inline_block.usage');
    $this->container->get('theme_installer')->install(['stark']);

    // The product case: a reusable footer placed in a theme region.
    $footer = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Footer',
      'reusable' => TRUE,
      'layout_builder__layout' => [$this->createSection('layout_onecol', [$this->createBasicBlockComponent()])],
    ]);
    $footer->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
    Block::create([
      'id' => 'stark_footer',
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => 'block_content:' . $footer->uuid(),
      'settings' => ['label' => 'Footer'],
    ])->save();

    // Placement-only survivor: placed while reusable, later flipped back to
    // non-reusable, so ONLY the block-config reference protects it.
    $placed_only = BlockContent::create(['type' => 'layout_block', 'info' => 'Placed only', 'reusable' => TRUE]);
    $placed_only->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
    Block::create([
      'id' => 'stark_placed_only',
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => 'block_content:' . $placed_only->uuid(),
      'settings' => ['label' => 'Placed only'],
    ])->save();
    $placed_only->setNonReusable();
    $placed_only->save();

    // Reusable-only survivor: no placement, the reusable flag protects it.
    $detached = BlockContent::create(['type' => 'layout_block', 'info' => 'Detached reusable', 'reusable' => TRUE]);
    $detached->save();

    // A genuine nested-model orphan with a usage row: still deleted.
    $orphan = BlockContent::create(['type' => 'layout_block', 'info' => 'Orphan', 'reusable' => FALSE]);
    $orphan->setSyncing(TRUE);
    $orphan->save();
    $host = $this->createLegacyEntity([$this->createSection('layout_onecol')]);
    $usage->addUsage($orphan->id(), $host);

    require_once $this->container->get('extension.list.module')->getPath('daedalus_layout') . '/daedalus_layout.post_update.php';
    $sandbox = [];
    do {
      daedalus_layout_post_update_0003_layout_block_gc($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $block_storage->resetCache();
    $this->assertNotNull($block_storage->loadUnchanged($footer->id()), 'The region-placed reusable footer survived the GC.');
    $this->assertNotNull($block_storage->loadUnchanged($placed_only->id()), 'A non-reusable entity referenced by block config survived the GC.');
    $this->assertNotNull($block_storage->loadUnchanged($detached->id()), 'An unplaced reusable entity survived the GC.');
    $this->assertNull($block_storage->loadUnchanged($orphan->id()), 'The orphaned layout_block entity was still deleted.');
    $this->assertFalse($usage->getUsage($orphan->id()), 'The orphan usage rows went with it.');
    $this->assertNotNull(Block::load('stark_footer'), 'The footer placement still points at a live entity.');

    // The retirement update keeps the survivors' supporting config.
    daedalus_layout_post_update_0004_remove_daedalus_edit_layout_block();
    $this->assertNotNull(BlockContentType::load('layout_block'), 'The bundle stays while entities survive.');
    $this->assertNotNull($this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('block_content.layout_block.default'), 'The view display stays.');
    $this->assertNotNull(FieldConfig::load('block_content.layout_block.layout_builder__layout'), 'The field config stays.');
    $this->assertNotNull(FieldStorageConfig::load('block_content.layout_builder__layout'), 'The field storage stays; no layout data purge.');
    $this->assertNotNull($block_storage->loadUnchanged($footer->id()), 'The footer itself survived retirement.');
  }

  /**
   * The retirement post update strips Add panel references and the bundle.
   */
  public function testRemoveLayoutBlockModulePostUpdate(): void {
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $display->setThirdPartySetting('daedalus_layout', 'promoted_blocks', [
      'inline_block:layout_block' => 'inline_block:layout_block',
      'inline_block:basic' => 'inline_block:basic',
    ]);
    $display->setThirdPartySetting('daedalus_layout', 'block_config', [
      'icon' => [
        'inline_block:layout_block' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/layout.svg',
        'inline_block:basic' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg',
      ],
    ]);
    $display->save();

    require_once $this->container->get('extension.list.module')->getPath('daedalus_layout') . '/daedalus_layout.post_update.php';
    daedalus_layout_post_update_0004_remove_daedalus_edit_layout_block();

    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');
    $this->assertSame(
      ['inline_block:basic' => 'inline_block:basic'],
      $display->getThirdPartySetting('daedalus_layout', 'promoted_blocks'),
    );
    $this->assertSame(
      ['icon' => ['inline_block:basic' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg']],
      $display->getThirdPartySetting('daedalus_layout', 'block_config'),
    );

    $this->assertNull(BlockContentType::load('layout_block'), 'The bundle is gone.');
    $this->assertNull($this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('block_content.layout_block.default'));
    $this->assertNull(FieldConfig::load('block_content.layout_block.layout_builder__layout'), 'Field config cascaded with the bundle.');
    $this->assertNull(FieldStorageConfig::load('block_content.layout_builder__layout'), 'The storage auto-deleted with its last field.');

    // The icon migration runs after retirement (post updates order by name):
    // the surviving icon lands on its block type and the retired
    // block_config setting leaves the display.
    daedalus_layout_post_update_0005_block_icons_to_block_types();
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');
    $this->assertNull($display->getThirdPartySetting('daedalus_layout', 'block_config'));
    $this->assertSame('/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg', BlockContentType::load('basic')->getThirdPartySetting('daedalus_layout', 'icon'));
  }

  /**
   * The icon migration moves display block_config icons onto block types.
   */
  public function testBlockIconsToBlockTypesPostUpdate(): void {
    // A block type whose icon was already configured on the type: the
    // migration must never overwrite it.
    $this->createBlockContentType('pinned', 'Pinned');
    BlockContentType::load('pinned')
      ->setThirdPartySetting('daedalus_layout', 'icon', 'media:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
      ->save();

    $display_storage = $this->entityTypeManager->getStorage('entity_view_display');
    $display = $display_storage->load('entity_test.entity_test.default');
    $display->setThirdPartySetting('daedalus_layout', 'promoted_blocks', [
      'inline_block:basic' => 'inline_block:basic',
    ]);
    $display->setThirdPartySetting('daedalus_layout', 'block_config', [
      'icon' => [
        'inline_block:basic' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg',
        'inline_block:pinned' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/layout.svg',
        // No bundle entity to carry an icon: dropped, the Add panel renders
        // its default icon.
        'system_powered_by_block' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/layout.svg',
        'inline_block:no_such_bundle' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/layout.svg',
      ],
    ]);
    $display->save();

    require_once $this->container->get('extension.list.module')->getPath('daedalus_layout') . '/daedalus_layout.post_update.php';
    daedalus_layout_post_update_0005_block_icons_to_block_types();

    $this->assertSame('/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg', BlockContentType::load('basic')->getThirdPartySetting('daedalus_layout', 'icon'), 'The display icon migrated onto its block type verbatim.');
    $this->assertSame('media:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', BlockContentType::load('pinned')->getThirdPartySetting('daedalus_layout', 'icon'), 'An icon configured on the type survived the migration.');

    $display = $display_storage->loadUnchanged('entity_test.entity_test.default');
    $this->assertNull($display->getThirdPartySetting('daedalus_layout', 'block_config'), 'The retired block_config setting left the display.');
    $this->assertSame(['inline_block:basic' => 'inline_block:basic'], $display->getThirdPartySetting('daedalus_layout', 'promoted_blocks'), 'Promoted blocks stay per-display config.');
  }

}
