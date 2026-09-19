<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\BlockContentTypeInterface;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\block_content\Event\BlockContentGetDependencyEvent;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\InlineBlockEntityOperations as CoreInlineBlockEntityOperations;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the inline-block lifecycle across embedded section depths.
 *
 * Under the sections-all-the-way-down model there is no entity-save cascade
 * reaching nested inline blocks: the root entity's presave is the one save
 * event for the whole tree. This covers the depth-aware walker end to end:
 * post-order presave saves (block_serialized -> block_revision_id at every
 * depth, propagated up into the stored root sections), usage rows pointing
 * at the ROOT host, on-save GC of removed nested blocks, host-delete plus
 * cron GC, access dependency resolution to the root host, and defaults
 * display dependency calculation/removal recursion.
 *
 * This is the new-shape replacement for the old-model
 * testInlineBlockEntityOperationsIntegration coverage (nested inline blocks
 * were invisible to core's walker until the daedalus_layout walker existed).
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class InlineBlockLifecycleTest extends NestedStorageKernelTestBase {

  /**
   * Builds a depth-2 nested layout and returns it with its identities.
   *
   * Shape: root section holding a root-level inline block and a container
   * (depth 1) whose embedded section holds an inline block and another
   * container (depth 2) whose embedded section holds an inline block.
   *
   * @return array{
   *   entity: \Drupal\entity_test\Entity\EntityTest,
   *   root_block_uuid: string,
   *   mid_container_uuid: string,
   *   mid_block_uuid: string,
   *   deep_container_uuid: string,
   *   deep_block_uuid: string,
   *   }
   */
  protected function buildDepthTwoEntity(): array {
    $deep_block = $this->createBasicBlockComponent();
    $deep_section = $this->createSection('layout_onecol', [$deep_block]);
    $deep_container = $this->createNestedSectionComponent($deep_section);

    $mid_block = $this->createBasicBlockComponent();
    $mid_section = $this->createSection('layout_onecol', [$mid_block, $deep_container]);
    $mid_container = $this->createNestedSectionComponent($mid_section);

    $root_block = $this->createBasicBlockComponent();
    $root_section = $this->createSection('layout_onecol', [$root_block, $mid_container]);

    $entity = $this->createTestEntity([$root_section]);

    return [
      'entity' => $entity,
      'root_block_uuid' => $root_block->getUuid(),
      'mid_container_uuid' => $mid_container->getUuid(),
      'mid_block_uuid' => $mid_block->getUuid(),
      'deep_container_uuid' => $deep_container->getUuid(),
      'deep_block_uuid' => $deep_block->getUuid(),
    ];
  }

  /**
   * Reads a component's raw stored configuration at any depth.
   *
   * Walks the PERSISTED shape (embedded Section::toArray() values inside
   * container component configuration), which is what proves the post-order
   * write-back reached storage.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The freshly loaded host entity.
   * @param string[] $container_path
   *   Container component uuids from the root section down.
   * @param string $component_uuid
   *   The component whose configuration to read.
   *
   * @return array
   *   The component configuration.
   */
  protected function storedComponentConfiguration(EntityTest $entity, array $container_path, string $component_uuid): array {
    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $section_array = $sections[0]->toArray();
    foreach ($container_path as $container_uuid) {
      $section_array = $section_array['components'][$container_uuid]['configuration'][SectionBlock::SECTION_KEY];
      $this->assertIsArray($section_array, "Container $container_uuid embeds a section value.");
    }
    $this->assertArrayHasKey($component_uuid, $section_array['components']);
    return $section_array['components'][$component_uuid]['configuration'];
  }

  /**
   * The usage row for a block content id.
   */
  protected function usageRow(int|string $block_content_id): object|false {
    return \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')
      ->condition('block_content_id', $block_content_id)
      ->execute()
      ->fetchObject();
  }

  /**
   * Presave saves nested inline blocks and propagates config to storage.
   */
  public function testPresaveWalkerSavesNestedInlineBlocks(): void {
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];

    $root_config = $this->storedComponentConfiguration($entity, [], $ids['root_block_uuid']);
    $mid_config = $this->storedComponentConfiguration($entity, [$ids['mid_container_uuid']], $ids['mid_block_uuid']);
    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);

    foreach (['root' => $root_config, 'mid' => $mid_config, 'deep' => $deep_config] as $depth => $configuration) {
      $this->assertNotEmpty($configuration['block_revision_id'], "The $depth inline block was saved and its revision id reached the stored root sections.");
      $this->assertEmpty($configuration['block_serialized'], "The $depth inline block no longer rides serialized.");
    }

    // Three distinct persisted block_content entities.
    $revision_ids = [
      $root_config['block_revision_id'],
      $mid_config['block_revision_id'],
      $deep_config['block_revision_id'],
    ];
    $this->assertCount(3, array_unique($revision_ids));

    // Usage rows point at the ROOT host at every depth.
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    foreach ($revision_ids as $revision_id) {
      $block = $block_storage->loadRevision($revision_id);
      $this->assertNotNull($block);
      $row = $this->usageRow($block->id());
      $this->assertNotFalse($row, 'A usage row exists.');
      $this->assertSame('entity_test', $row->layout_entity_type);
      $this->assertSame((string) $entity->id(), (string) $row->layout_entity_id, 'The usage row points at the root host.');
    }
  }

  /**
   * On-save GC of removed nested blocks, where core has that path at all.
   *
   * Core's removeUnusedForEntityOnSave() early-returns for every content
   * entity (ContentEntityBase implements RevisionableInterface), so the
   * on-save GC path exists only for config-entity hosts: defaults displays.
   * This asserts BOTH sides at depth: the content-entity host keeps the
   * removed block (exact core parity with root-level behavior; cleanup is
   * host delete plus cron), and the defaults display deletes it on save via
   * the depth-aware removed-revision diff.
   */
  public function testRemovedNestedInlineBlockOnSaveGc(): void {
    // Content-entity host: the removed nested block survives the save, as
    // it does for root-level blocks in core.
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];
    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $deep_block_id = $block_storage->loadRevision($deep_config['block_revision_id'])->id();

    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $mid_component = $sections[0]->getComponent($ids['mid_container_uuid']);
    $mid_section = Section::fromArray($mid_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $deep_component = $mid_section->getComponent($ids['deep_container_uuid']);
    $deep_section = Section::fromArray($deep_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $deep_section->removeComponent($ids['deep_block_uuid']);
    $deep_configuration = $deep_component->get('configuration');
    $deep_configuration[SectionBlock::SECTION_KEY] = $deep_section->toArray();
    $deep_component->setConfiguration($deep_configuration);
    $mid_configuration = $mid_component->get('configuration');
    $mid_configuration[SectionBlock::SECTION_KEY] = $mid_section->toArray();
    $mid_component->setConfiguration($mid_configuration);
    $entity->save();

    $block_storage->resetCache();
    $this->assertNotNull($block_storage->load($deep_block_id), 'Content-entity hosts keep removed blocks on save (core parity); host delete plus cron cleans up.');

    // Defaults display host: the on-save GC path runs and must see the
    // removal at depth 2.
    $display_ids = $this->buildDefaultsDisplay();
    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');
    $display_mid = $display->getSections()[0]->getComponent($display_ids['mid_container_uuid']);
    $display_mid_section = Section::fromArray($display_mid->get('configuration')[SectionBlock::SECTION_KEY]);
    $display_deep = $display_mid_section->getComponent($display_ids['deep_container_uuid']);
    $display_deep_section = Section::fromArray($display_deep->get('configuration')[SectionBlock::SECTION_KEY]);
    $display_block_id = $block_storage->loadRevision($display_deep_section->getComponent($display_ids['inline_uuid'])->get('configuration')['block_revision_id'])->id();

    $display_deep_section->removeComponent($display_ids['inline_uuid']);
    $display_deep_configuration = $display_deep->get('configuration');
    $display_deep_configuration[SectionBlock::SECTION_KEY] = $display_deep_section->toArray();
    $display_deep->setConfiguration($display_deep_configuration);
    $display_mid_configuration = $display_mid->get('configuration');
    $display_mid_configuration[SectionBlock::SECTION_KEY] = $display_mid_section->toArray();
    $display_mid->setConfiguration($display_mid_configuration);
    $display->save();

    $block_storage->resetCache();
    $this->assertNull($block_storage->load($display_block_id), 'The defaults display deleted the removed depth-2 inline block on save.');
    $this->assertFalse($this->usageRow($display_block_id), 'Its usage row is gone.');
  }

  /**
   * Deleting the host unhooks all rows; cron GC deletes the blocks.
   */
  public function testHostDeleteAndCronGc(): void {
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];

    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);
    $mid_config = $this->storedComponentConfiguration($entity, [$ids['mid_container_uuid']], $ids['mid_block_uuid']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $deep_block_id = $block_storage->loadRevision($deep_config['block_revision_id'])->id();
    $mid_block_id = $block_storage->loadRevision($mid_config['block_revision_id'])->id();

    $entity->delete();

    // Rows point at the root host, so one removeByLayoutEntity() covers the
    // whole tree: rows remain but are marked unused (host columns nulled).
    foreach ([$deep_block_id, $mid_block_id] as $block_id) {
      $row = $this->usageRow($block_id);
      $this->assertNotFalse($row);
      $this->assertNull($row->layout_entity_id, 'The row is marked unused after host delete.');
    }

    // The cron GC path (hook_cron -> removeUnused) deletes the blocks and
    // their rows.
    \Drupal::classResolver(CoreInlineBlockEntityOperations::class)->removeUnused();
    $block_storage->resetCache();
    foreach ([$deep_block_id, $mid_block_id] as $block_id) {
      $this->assertNull($block_storage->load($block_id), 'Cron GC deleted the orphaned nested block.');
      $this->assertFalse($this->usageRow($block_id), 'Cron GC deleted the usage row.');
    }
  }

  /**
   * A nested inline block's access dependency resolves to the root host.
   */
  public function testAccessDependencyResolvesToRootHost(): void {
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];

    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $deep_block = $block_storage->loadRevision($deep_config['block_revision_id']);

    $event = new BlockContentGetDependencyEvent($deep_block);
    \Drupal::service('event_dispatcher')->dispatch($event, 'block_content.get_dependency');

    $dependency = $event->getAccessDependency();
    $this->assertNotNull($dependency, 'A dependency was resolved: the usage row points at the root and the depth-aware section walk found the revision.');
    $this->assertInstanceOf(EntityTest::class, $dependency);
    $this->assertSame($entity->id(), $dependency->id());
  }

  /**
   * Builds a defaults display carrying a depth-2 nested layout.
   *
   * Root section: a container (depth 1) embedding a section with a static
   * plugin block and another container (depth 2) embedding a section with an
   * inline block.
   *
   * @return array{
   *   display: \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay,
   *   mid_container_uuid: string,
   *   deep_container_uuid: string,
   *   inline_uuid: string,
   *   static_uuid: string,
   *   }
   */
  protected function buildDefaultsDisplay(): array {
    $block_content = BlockContent::create([
      'type' => 'basic',
      'info' => 'Defaults basic block',
      'reusable' => FALSE,
    ]);
    $inline_uuid = $this->uuid->generate();
    $inline = new SectionComponent($inline_uuid, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic block',
      // Strict config schema: label_display is a string on the defaults
      // path, never a boolean.
      'label_display' => '0',
      'view_mode' => 'full',
      'block_serialized' => serialize($block_content),
    ]);

    $deep_section = $this->createSection('layout_onecol', [$inline]);
    $deep_container = $this->createNestedSectionComponent($deep_section);

    $static_uuid = $this->uuid->generate();
    $static = new SectionComponent($static_uuid, 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]);

    $mid_section = $this->createSection('layout_onecol', [$static, $deep_container]);
    $mid_container = $this->createNestedSectionComponent($mid_section);

    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$mid_container]));
    $display->save();

    return [
      'display' => $display,
      'mid_container_uuid' => $mid_container->getUuid(),
      'deep_container_uuid' => $deep_container->getUuid(),
      'inline_uuid' => $inline_uuid,
      'static_uuid' => $static_uuid,
    ];
  }

  /**
   * Defaults display dependencies include nested component dependencies.
   *
   * Also exercises the presave walker on the DEFAULTS storage: saving the
   * display persists the nested inline block.
   */
  public function testDefaultsDisplayCalculateDependencies(): void {
    $ids = $this->buildDefaultsDisplay();

    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');

    // The walker ran for the defaults storage: the nested inline block was
    // saved and its revision id written back through both containers.
    $mid_component = $display->getSections()[0]->getComponent($ids['mid_container_uuid']);
    $mid_section = Section::fromArray($mid_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $deep_component = $mid_section->getComponent($ids['deep_container_uuid']);
    $deep_section = Section::fromArray($deep_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $inline_configuration = $deep_section->getComponent($ids['inline_uuid'])->get('configuration');
    $this->assertNotEmpty($inline_configuration['block_revision_id'], 'The nested inline block in the defaults display was saved.');

    $dependencies = $display->calculateDependencies()->getDependencies();
    $this->assertContains('block_content.type.basic', $dependencies['config'] ?? [], 'A dependency of a depth-2 component reaches the display through the container aggregation.');
    $this->assertContains('system', $dependencies['module'] ?? [], 'The depth-1 static plugin block provider is a display dependency.');
  }

  /**
   * Dependency removal prunes surgically at depth.
   *
   * A removed dependency used by one nested component removes THAT
   * component, not the containers above it (core's blanket walk would drop
   * the whole depth-1 container, because containers aggregate their
   * subtree's dependencies).
   */
  public function testDefaultsDisplayOnDependencyRemovalPrunesSurgically(): void {
    $ids = $this->buildDefaultsDisplay();

    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');

    $basic_type = BlockContentType::load('basic');
    $this->assertInstanceOf(BlockContentTypeInterface::class, $basic_type);
    $changed = $display->onDependencyRemoval([
      'config' => [$basic_type->getConfigDependencyName() => $basic_type],
      'content' => [],
      'module' => [],
      'theme' => [],
    ]);
    $this->assertTrue($changed);

    // Both containers survive; only the inline block is gone.
    $mid_component = $display->getSections()[0]->getComponent($ids['mid_container_uuid']);
    $mid_section = Section::fromArray($mid_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $this->assertArrayHasKey($ids['static_uuid'], $mid_section->getComponents(), 'The unrelated depth-1 block survives.');
    $deep_component = $mid_section->getComponent($ids['deep_container_uuid']);
    $deep_section = Section::fromArray($deep_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $this->assertArrayNotHasKey($ids['inline_uuid'], $deep_section->getComponents(), 'The offending depth-2 component was pruned.');

    // The pruned tree no longer carries the removed dependency.
    $dependencies = $display->calculateDependencies()->getDependencies();
    $this->assertNotContains('block_content.type.basic', $dependencies['config'] ?? []);
  }

  /**
   * A nested section whose layout goes away goes away WITH its container.
   */
  public function testDefaultsDisplayOnDependencyRemovalRemovesContainerWithLayout(): void {
    $this->enableModules(['layout_test']);

    $inline_uuid = $this->uuid->generate();
    $static = new SectionComponent($inline_uuid, 'top', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]);
    $embedded = $this->createSection('layout_test_1col', [$static]);
    $container = $this->createNestedSectionComponent($embedded);

    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$container]));

    $changed = $display->onDependencyRemoval([
      'config' => [],
      'content' => [],
      'module' => ['layout_test'],
      'theme' => [],
    ]);
    $this->assertTrue($changed);

    // The embedded layout's removal removes the nested section, and with it
    // the container component (an empty container is not a state); the
    // root section itself survives, its own layout being unaffected.
    $sections = $display->getSections();
    $root_section = end($sections);
    $this->assertArrayNotHasKey($container->getUuid(), $root_section->getComponents(), 'The container went away with its embedded layout.');
  }

}
