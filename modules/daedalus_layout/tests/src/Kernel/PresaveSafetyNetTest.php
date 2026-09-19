<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\layout_builder\Section;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\block_content\Entity\BlockContent;
use Drupal\daedalus_layout_transform_test\TransformLog;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the presave safety net of the migration spine.
 *
 * The batched walkers migrate persisted stores, but an editing session
 * staged in tempstore before the update ran writes its stale layout back
 * through a normal entity save. The net (daedalus_layout_entity_presave() ->
 * PresaveSafetyNet) must converge those layouts at save time, before
 * layout_builder's inline block machinery persists any block_serialized
 * replacement, and must leave syncing saves alone.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class PresaveSafetyNetTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The test transform writes marker keys (migrated_from, annotated) that no
   * block plugin schema declares, and the display test persists them on a
   * config entity; real transforms write real plugin configuration.
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The migration target of the test transform must be a real inline block
    // derivative: layout_builder's presave machinery only persists serialized
    // blocks of instantiable inline_block plugins.
    $this->createBlockContentType('modern', 'Modern Block');
    TransformLog::reset();
  }

  /**
   * Builds the section a stale editing session would write back.
   *
   * A legacy component carrying its block copy serialized (the tempstore
   * shape for a never-saved inline block) plus a clean component.
   */
  protected function staleSection(string $uuid_legacy, string $uuid_clean, BlockContent $copy): Section {
    return $this->createSection('layout_onecol', [
      new SectionComponent($uuid_legacy, 'content', [
        'id' => 'inline_block:legacy',
        'label' => 'Stale legacy',
        'label_display' => FALSE,
        'block_revision_id' => NULL,
        'block_uuid' => NULL,
        'block_serialized' => serialize($copy),
      ]),
      new SectionComponent($uuid_clean, 'content', [
        'id' => 'system_powered_by_block',
      ]),
    ]);
  }

  /**
   * A normal host save converges a stale layout and persists the new block.
   */
  public function testStaleHostSaveConverges(): void {
    $uuid_legacy = '10000000-0000-0000-0000-000000000001';
    $uuid_clean = '20000000-0000-0000-0000-000000000002';
    $copy = BlockContent::create([
      'type' => 'modern',
      'info' => 'Stale copy',
      'reusable' => FALSE,
    ]);

    // The stale session writing back: a plain save, nothing special.
    $entity = EntityTest::create([
      'name' => 'Stale host',
      OverridesSectionStorage::FIELD_NAME => [$this->staleSection($uuid_legacy, $uuid_clean, $copy)],
    ]);
    $entity->save();

    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $entity = EntityTest::load($entity->id());
    $configs = [];
    foreach ($entity->get(OverridesSectionStorage::FIELD_NAME)->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $configs[$component->getUuid()] = $component->get('configuration');
      }
    }

    // The component migrated in place during the save: rewritten plugin ID,
    // chained annotation, preserved UUID and unrelated configuration, and
    // the host was available to the transform.
    $this->assertSame('inline_block:modern', $configs[$uuid_legacy]['id']);
    $this->assertSame('Stale legacy', $configs[$uuid_legacy]['label']);
    $this->assertSame('Stale host', $configs[$uuid_legacy]['migrated_from']);
    $this->assertTrue($configs[$uuid_legacy]['annotated']);
    $this->assertSame('system_powered_by_block', $configs[$uuid_clean]['id'], 'The clean component is untouched.');
    $this->assertSame(["legacy:$uuid_legacy", "annotator:$uuid_legacy"], TransformLog::$calls);

    // The net ran before the inline block machinery: the block_serialized
    // replacement written by the transform was saved and referenced within
    // the same host save.
    $this->assertNotEmpty($configs[$uuid_legacy]['block_revision_id']);
    $this->assertNull($configs[$uuid_legacy]['block_serialized']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block = $block_storage->loadRevision($configs[$uuid_legacy]['block_revision_id']);
    $this->assertSame('Migrated copy', $block->label());
    $this->assertFalse($block->isNew());
  }

  /**
   * A syncing save must round-trip a stale layout verbatim.
   */
  public function testSyncingSaveIsLeftAlone(): void {
    $uuid_legacy = '30000000-0000-0000-0000-000000000003';
    $uuid_clean = '40000000-0000-0000-0000-000000000004';
    $copy = BlockContent::create([
      'type' => 'modern',
      'info' => 'Stale copy',
      'reusable' => FALSE,
    ]);

    $entity = EntityTest::create([
      'name' => 'Synced host',
      OverridesSectionStorage::FIELD_NAME => [$this->staleSection($uuid_legacy, $uuid_clean, $copy)],
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();

    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $entity = EntityTest::load($entity->id());
    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $component = reset($sections)->getComponent($uuid_legacy);
    $configuration = $component->get('configuration');
    $this->assertSame('inline_block:legacy', $configuration['id']);
    $this->assertNotEmpty($configuration['block_serialized']);
    $this->assertSame([], TransformLog::$calls);
    $block_count = $this->entityTypeManager->getStorage('block_content')->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(0, (int) $block_count, 'No block_content entity was saved during the syncing save.');
  }

  /**
   * A normal display save converges a stale default layout.
   */
  public function testStaleDefaultLayoutSaveConverges(): void {
    $uuid_legacy = '50000000-0000-0000-0000-000000000005';
    $display_id = 'entity_test.entity_test.default';
    $storage = $this->entityTypeManager->getStorage('entity_view_display');

    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $storage->loadUnchanged($display_id);
    $display->appendSection($this->createSection('layout_onecol', [
      new SectionComponent($uuid_legacy, 'content', [
        'id' => 'inline_block:legacy',
        'label' => 'Stale default',
      ]),
    ]));
    $display->save();

    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $storage->loadUnchanged($display_id);
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $configs = [];
    foreach ($display->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $configs[$component->getUuid()] = $component->get('configuration');
      }
    }
    $this->assertSame('inline_block:modern', $configs[$uuid_legacy]['id']);
    $this->assertSame('Stale default', $configs[$uuid_legacy]['label']);
    $this->assertTrue($configs[$uuid_legacy]['annotated']);
  }

}
