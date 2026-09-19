<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel\Form;

use Drupal\daedalus_layout\TreeIndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for block removal via the nested storage API.
 *
 * Tests block removal at various nesting levels, including nested-section
 * containers with content, and verifies proper TreeIndex updates.
 *
 * These tests exercise the same storage operations that core's
 * RemoveBlockForm triggers: getSection($delta)->removeComponent($uuid)
 * with the NestedAwareSectionStorage handling nested context.
 */
#[CoversClass(\Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class RemoveBlockFormTest extends NestedStorageKernelTestBase {

  /**
   * Tests removing a block at root level.
   *
   * Scenario: User removes a basic block from a root-level section.
   * After removal, the block should no longer exist and the TreeIndex
   * should be updated accordingly.
   */
  public function testRemoveBlockAtRootLevel(): void {
    // Create an entity with one section containing two blocks.
    $section_uuid = $this->uuid->generate();
    $block_1_uuid = $this->uuid->generate();
    $block_2_uuid = $this->uuid->generate();

    $block_1 = $this->createBasicBlockComponent($block_1_uuid);
    $block_2 = $this->createBasicBlockComponent($block_2_uuid);

    $section = $this->createSection('layout_onecol', [$block_1, $block_2], $section_uuid);
    $entity = $this->createTestEntity([$section]);

    $storage = $this->getNestedStorage($entity);

    // Verify initial state: 2 blocks in section.
    $initial_section = $storage->getSectionByUuid($section_uuid);
    $this->assertCount(2, $initial_section->getComponents());
    $this->assertNotNull($storage->getComponent($block_1_uuid));
    $this->assertNotNull($storage->getComponent($block_2_uuid));

    // Remove block_1 using the pattern from RemoveBlockForm.
    $section = $storage->getSectionFor($block_1_uuid);
    $this->assertNotNull($section);
    $section->removeComponent($block_1_uuid);

    // Bubble changes and save.
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_section = $reloaded_storage->getSectionByUuid($section_uuid);

    // Should now have 1 block.
    $this->assertCount(1, $reloaded_section->getComponents());

    // block_1 should no longer exist.
    $this->assertNull($reloaded_storage->getComponent($block_1_uuid));
    $this->assertNull($reloaded_storage->getPath($block_1_uuid));

    // block_2 should still exist.
    $this->assertNotNull($reloaded_storage->getComponent($block_2_uuid));
    $this->assertNotNull($reloaded_storage->getPath($block_2_uuid));
  }

  /**
   * Tests removing a block inside a nested section.
   *
   * Scenario: User removes a basic block that is inside a nested section.
   * The removal should survive the flush, and the nested structure should
   * remain intact (minus the removed block).
   */
  public function testRemoveBlockInsideNestedSection(): void {
    // Create nested structure: root section -> container -> nested section
    // with 2 blocks.
    $nested_block_1_uuid = $this->uuid->generate();
    $nested_block_2_uuid = $this->uuid->generate();
    $nested_block_1 = $this->createBasicBlockComponent($nested_block_1_uuid);
    $nested_block_2 = $this->createBasicBlockComponent($nested_block_2_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [
      $nested_block_1,
      $nested_block_2,
    ], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [$container], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial state.
    $this->assertNotNull($storage->getComponent($nested_block_1_uuid));
    $this->assertNotNull($storage->getComponent($nested_block_2_uuid));
    $initial_nested_section = $storage->getSectionByUuid($nested_section_uuid);
    $this->assertCount(2, $initial_nested_section->getComponents());

    // Remove nested_block_1 using the pattern from RemoveBlockForm.
    $section = $storage->getSectionFor($nested_block_1_uuid);
    $this->assertNotNull($section);
    $this->assertEquals($nested_section_uuid, $section->getThirdPartySetting('daedalus_layout', 'uuid'));
    $section->removeComponent($nested_block_1_uuid);

    // Critical: Bubble flushes the embedded section back into its container.
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_nested_section = $reloaded_storage->getSectionByUuid($nested_section_uuid);

    // Should now have 1 block in the nested section.
    $this->assertCount(1, $reloaded_nested_section->getComponents());

    // nested_block_1 should no longer exist.
    $this->assertNull($reloaded_storage->getComponent($nested_block_1_uuid));
    $this->assertNull($reloaded_storage->getPath($nested_block_1_uuid));

    // nested_block_2 should still exist.
    $this->assertNotNull($reloaded_storage->getComponent($nested_block_2_uuid));
    $this->assertNotNull($reloaded_storage->getPath($nested_block_2_uuid));

    // The container and root structure should be intact.
    $this->assertNotNull($reloaded_storage->getContainerComponent($nested_section_uuid));
    $this->assertTrue($reloaded_storage->getTreeIndex()->isSection($nested_section_uuid));
  }

  /**
   * Tests removing a nested section's container removes its content.
   *
   * Scenario: User removes a nested section (the container component). All
   * nested content should be removed along with it: the section value rides
   * inside the component's configuration.
   */
  public function testRemoveContainerWithNestedContent(): void {
    // Create nested structure with content inside the container.
    $nested_block_uuid = $this->uuid->generate();
    $nested_block = $this->createBasicBlockComponent($nested_block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$nested_block], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    // Also add a regular block at root level.
    $root_block_uuid = $this->uuid->generate();
    $root_block = $this->createBasicBlockComponent($root_block_uuid);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [
      $container,
      $root_block,
    ], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial state: 2 sections (root + nested), 2 blocks (the
    // container is NOT a block — one identity, indexed as its section).
    $tree_index = $storage->getTreeIndex();
    $this->assertCount(2, $tree_index->getAllOfType(TreeIndexInterface::TYPE_SECTION));
    $this->assertCount(2, $tree_index->getAllOfType(TreeIndexInterface::TYPE_BLOCK));

    $this->assertNotNull($storage->getComponent($nested_block_uuid));

    // Remove the nested section by removing its container component from
    // the parent section, the single lookup one-identity buys
    // (remove-section == remove-component, same uuid).
    $parent = $storage->getSectionByUuid($root_section_uuid);
    $this->assertNotNull($parent);
    $parent->removeComponent($nested_section_uuid);

    // Bubble and save.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_tree_index = $reloaded_storage->getTreeIndex();

    // Should now have only 1 section (root).
    $this->assertCount(1, $reloaded_tree_index->getAllOfType(TreeIndexInterface::TYPE_SECTION));
    // Should now have only 1 block (root_block).
    $this->assertCount(1, $reloaded_tree_index->getAllOfType(TreeIndexInterface::TYPE_BLOCK));

    // The nested section and its content should be gone.
    $this->assertNull($reloaded_storage->getSectionByUuid($nested_section_uuid));
    $this->assertNull($reloaded_storage->getPath($nested_section_uuid));
    $this->assertNull($reloaded_storage->getComponent($nested_block_uuid));
    $this->assertNull($reloaded_storage->getPath($nested_block_uuid));

    // The root block should still exist.
    $this->assertNotNull($reloaded_storage->getComponent($root_block_uuid));
    $this->assertNotNull($reloaded_storage->getPath($root_block_uuid));
  }

  /**
   * Tests that TreeIndex is properly updated after block removal.
   *
   * Verifies that after removing a block:
   * - The removed block's UUID is no longer in the index
   * - Other blocks remain correctly indexed
   * - Section indices remain accurate.
   */
  public function testTreeIndexUpdatesAfterBlockRemoval(): void {
    // Create a section with multiple blocks.
    $section_uuid = $this->uuid->generate();
    $block_uuids = [];
    $blocks = [];

    for ($i = 0; $i < 4; $i++) {
      $uuid = $this->uuid->generate();
      $block_uuids[] = $uuid;
      $blocks[] = $this->createBasicBlockComponent($uuid);
    }

    $section = $this->createSection('layout_onecol', $blocks, $section_uuid);
    $entity = $this->createTestEntity([$section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial TreeIndex state.
    $tree_index = $storage->getTreeIndex();
    $this->assertCount(4, $tree_index->getAllOfType(TreeIndexInterface::TYPE_BLOCK));
    $this->assertCount(1, $tree_index->getAllOfType(TreeIndexInterface::TYPE_SECTION));

    foreach ($block_uuids as $uuid) {
      $this->assertTrue($tree_index->isBlock($uuid), "Block $uuid should be in index");
    }

    // Remove the second block.
    $uuid_to_remove = $block_uuids[1];
    $section = $storage->getSectionFor($uuid_to_remove);
    $section->removeComponent($uuid_to_remove);

    // Invalidate cache to force TreeIndex rebuild.
    $storage->invalidateCache();

    // After structural change, TreeIndex should update.
    $updated_tree_index = $storage->getTreeIndex();

    // Should now have 3 blocks.
    $this->assertCount(3, $updated_tree_index->getAllOfType(TreeIndexInterface::TYPE_BLOCK));

    // Removed block should not be in index.
    $this->assertFalse($updated_tree_index->isBlock($uuid_to_remove));
    $this->assertNull($updated_tree_index->getPath($uuid_to_remove));

    // Other blocks should still be indexed.
    foreach ($block_uuids as $uuid) {
      if ($uuid === $uuid_to_remove) {
        continue;
      }
      $this->assertTrue($updated_tree_index->isBlock($uuid), "Block $uuid should remain in index");
      $this->assertNotNull($updated_tree_index->getPath($uuid));
    }

    // Section should still be indexed.
    $this->assertTrue($updated_tree_index->isSection($section_uuid));

    // Bubble, save, and reload to verify persistence.
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_tree_index = $reloaded_storage->getTreeIndex();

    $this->assertCount(3, $reloaded_tree_index->getAllOfType(TreeIndexInterface::TYPE_BLOCK));
    $this->assertFalse($reloaded_tree_index->isBlock($uuid_to_remove));

    foreach ($block_uuids as $uuid) {
      if ($uuid === $uuid_to_remove) {
        $this->assertNull($reloaded_storage->getComponent($uuid));
      }
      else {
        $this->assertNotNull($reloaded_storage->getComponent($uuid));
      }
    }
  }

}
