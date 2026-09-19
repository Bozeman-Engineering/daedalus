<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout_transform_test\TransformLog;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry;
use Drupal\daedalus_layout\ComponentTransform\EntityViewDisplayWalker;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the entity view display walker of the migration spine.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class EntityViewDisplayWalkerTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The test transform writes marker keys (migrated_from, annotated) that no
   * block plugin schema declares, and this test persists them on a config
   * entity; real transforms write real plugin configuration.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_layout_transform_test',
  ];

  /**
   * The ID of the Layout Builder enabled entity_test display.
   */
  protected const DIRTY_DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * The ID of the Layout Builder enabled layout_block display.
   */
  protected const CLEAN_DISPLAY_ID = 'block_content.layout_block.default';

  /**
   * Loads a display bypassing the static cache.
   */
  protected function loadDisplay(string $id): LayoutBuilderEntityViewDisplay {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged($id);
    return $display;
  }

  /**
   * Collects a display's component configurations, keyed by component UUID.
   */
  protected function componentConfigs(string $display_id): array {
    $configs = [];
    foreach ($this->loadDisplay($display_id)->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $configs[$component->getUuid()] = $component->get('configuration');
      }
    }
    return $configs;
  }

  /**
   * Walks all Layout Builder enabled displays in batches.
   */
  public function testWalkTransformsDefaultLayouts(): void {
    $uuid_legacy = '10000000-0000-0000-0000-000000000001';
    $uuid_clean = '20000000-0000-0000-0000-000000000002';
    $uuid_serialized = '30000000-0000-0000-0000-000000000003';

    // The entity_test default layout carries a legacy component, a clean
    // component, and a real inline block still in its serialized (unsaved)
    // form, proving the walker's save runs layout_builder's inline block
    // presave machinery.
    $dirty = $this->loadDisplay(static::DIRTY_DISPLAY_ID);
    $dirty->appendSection($this->createSection('layout_onecol', [
      new SectionComponent($uuid_legacy, 'content', [
        'id' => 'inline_block:legacy',
        'label' => 'Legacy default',
      ]),
      new SectionComponent($uuid_clean, 'content', [
        'id' => 'system_powered_by_block',
      ]),
      $this->createBasicBlockComponent($uuid_serialized),
    ]));
    // A syncing save keeps the serialized inline block serialized (core's
    // InlineBlockEntityOperations::handlePreSave() skips syncing saves), so
    // the walker's own save is what must persist it.
    $dirty->setSyncing(TRUE);
    $dirty->save();
    $this->assertSame(0, (int) $this->entityTypeManager->getStorage('block_content')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'basic')
      ->count()
      ->execute());

    // The layout_block display stays clean and must never be re-saved. The
    // basic block display is not Layout Builder enabled and must not even be
    // queued.
    TransformLog::reset();

    /** @var \Drupal\daedalus_layout\ComponentTransform\EntityViewDisplayWalker $walker */
    $walker = $this->container->get('daedalus_layout.entity_view_display_walker');
    $sandbox = [];
    $saved = $walker->walk($sandbox, 1);
    $this->assertLessThan(1, $sandbox['#finished'], 'One of two displays claimed, the walk resumes.');
    $this->assertSame(2, $sandbox[EntityViewDisplayWalker::SANDBOX_KEY]['total'], 'Only Layout Builder enabled displays are queued.');
    $calls = 1;
    while ($sandbox['#finished'] < 1) {
      $saved += $walker->walk($sandbox, 1);
      $calls++;
      $this->assertLessThan(10, $calls, 'The walk terminates.');
    }
    $this->assertGreaterThan(1, $calls);
    $this->assertSame(1, $saved, 'Only the dirty display was re-saved.');
    $this->assertSame([
      'entity_view_display:' . static::DIRTY_DISPLAY_ID . ':',
    ], array_values(array_filter(TransformLog::$saves, fn (string $save) => str_starts_with($save, 'entity_view_display:'))));

    // The legacy component migrated in place: rewritten plugin ID, chained
    // annotation, preserved UUID and unrelated configuration.
    $configs = $this->componentConfigs(static::DIRTY_DISPLAY_ID);
    $this->assertSame('inline_block:modern', $configs[$uuid_legacy]['id']);
    $this->assertSame('Legacy default', $configs[$uuid_legacy]['label']);
    $this->assertTrue($configs[$uuid_legacy]['annotated']);
    $this->assertSame('system_powered_by_block', $configs[$uuid_clean]['id'], 'The clean component is untouched.');

    // The display save was not a syncing save: core converted the serialized
    // inline block into a saved, usage-tracked block content revision.
    $this->assertNotEmpty($configs[$uuid_serialized]['block_revision_id']);
    $this->assertNull($configs[$uuid_serialized]['block_serialized']);
    $block_count = $this->entityTypeManager->getStorage('block_content')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'basic')
      ->count()
      ->execute();
    $this->assertSame(1, (int) $block_count);

    // The clean Layout Builder display kept its configuration.
    $clean_configs = $this->componentConfigs(static::CLEAN_DISPLAY_ID);
    foreach ($clean_configs as $configuration) {
      $this->assertNotSame('inline_block:modern', $configuration['id']);
    }
  }

  /**
   * An empty registry finishes immediately without touching anything.
   */
  public function testNoTransformsShortCircuits(): void {
    $uuid_legacy = '40000000-0000-0000-0000-000000000004';
    $dirty = $this->loadDisplay(static::DIRTY_DISPLAY_ID);
    $dirty->appendSection($this->createSection('layout_onecol', [
      new SectionComponent($uuid_legacy, 'content', [
        'id' => 'inline_block:legacy',
        'label' => 'Legacy stays',
      ]),
    ]));
    // A syncing save keeps the presave safety net (backed by the container
    // registry, which does hold the test transforms) from converging the
    // staged legacy component.
    $dirty->setSyncing(TRUE);
    $dirty->save();
    TransformLog::reset();

    $walker = new EntityViewDisplayWalker(
      $this->entityTypeManager,
      new ComponentTransformRegistry($this->container, []),
    );
    $sandbox = [];
    $this->assertSame(0, $walker->walk($sandbox));
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame([], TransformLog::$saves);
    $configs = $this->componentConfigs(static::DIRTY_DISPLAY_ID);
    $this->assertSame('inline_block:legacy', $configs[$uuid_legacy]['id']);
  }

}
