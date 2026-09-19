<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Nested-storage semantics of moving blocks at various nesting levels.
 *
 * Exercises the storage primitives a block move is made of — component
 * lookup, section resolution, removal/insertion, cache invalidation, and
 * bubbling — through NestedAwareSectionStorage over embedded-section
 * containers. These are the same primitives daedalus_blueprint's move_element
 * op drives through the DaedalusLayoutAdapter; the browser-driven move gesture is
 * covered end to end in NestedLayoutBehaviorTest.
 */
#[CoversClass(\Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class NestedStorageMoveBlockTest extends NestedStorageKernelTestBase {

  /**
   * Tests reordering blocks within the same section at root level.
   *
   * Scenario: User reorders blocks within a single section at root.
   */
  public function testReorderBlocksWithinSameSectionAtRoot(): void {
    // Create section with 3 blocks.
    $block1_uuid = $this->uuid->generate();
    $block1 = $this->createBasicBlockComponent($block1_uuid);
    $block2_uuid = $this->uuid->generate();
    $block2 = $this->createBasicBlockComponent($block2_uuid);
    $block3_uuid = $this->uuid->generate();
    $block3 = $this->createBasicBlockComponent($block3_uuid);

    $section_uuid = $this->uuid->generate();
    $section = $this->createSection('layout_onecol', [$block1, $block2, $block3], $section_uuid);

    $entity = $this->createTestEntity([$section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial order (by weight).
    $retrieved_section = $storage->getSectionByUuid($section_uuid);
    $components = $retrieved_section->getComponents();
    $component_uuids = array_keys($components);
    $this->assertCount(3, $component_uuids);
    $this->assertEquals($block1_uuid, $component_uuids[0]);
    $this->assertEquals($block2_uuid, $component_uuids[1]);
    $this->assertEquals($block3_uuid, $component_uuids[2]);

    // Move block1 to after block3 (move to end).
    $component_to_move = $storage->getComponent($block1_uuid);
    $source_section = $storage->getSectionFor($block1_uuid);

    // Remove from source.
    $source_section->removeComponent($block1_uuid);

    // Insert after block3.
    $source_section->insertAfterComponent($block3_uuid, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify new order: block2, block3, block1.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_section = $reloaded_storage->getSectionByUuid($section_uuid);
    $reloaded_components = $reloaded_section->getComponents();
    $reloaded_uuids = array_keys($reloaded_components);

    $this->assertCount(3, $reloaded_uuids);
    $this->assertEquals($block2_uuid, $reloaded_uuids[0]);
    $this->assertEquals($block3_uuid, $reloaded_uuids[1]);
    $this->assertEquals($block1_uuid, $reloaded_uuids[2]);
  }

  /**
   * Tests moving a block between different sections at root level.
   *
   * Scenario: User moves a block from section 1 to section 2.
   */
  public function testMoveBlockBetweenSectionsAtRoot(): void {
    // Create two sections with blocks.
    $block1_uuid = $this->uuid->generate();
    $block1 = $this->createBasicBlockComponent($block1_uuid);
    $block2_uuid = $this->uuid->generate();
    $block2 = $this->createBasicBlockComponent($block2_uuid);

    $section1_uuid = $this->uuid->generate();
    $section1 = $this->createSection('layout_onecol', [$block1, $block2], $section1_uuid);

    $block3_uuid = $this->uuid->generate();
    $block3 = $this->createBasicBlockComponent($block3_uuid);

    $section2_uuid = $this->uuid->generate();
    $section2 = $this->createSection('layout_onecol', [$block3], $section2_uuid);

    $entity = $this->createTestEntity([$section1, $section2]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial state.
    $sec1 = $storage->getSectionByUuid($section1_uuid);
    $sec2 = $storage->getSectionByUuid($section2_uuid);
    $this->assertCount(2, $sec1->getComponents());
    $this->assertCount(1, $sec2->getComponents());

    // Move block1 from section1 to section2.
    $component_to_move = $storage->getComponent($block1_uuid);
    $source_section = $storage->getSectionFor($block1_uuid);
    $dest_section = $storage->getSectionByUuid($section2_uuid);

    // Remove from source.
    $source_section->removeComponent($block1_uuid);

    // Set region and insert at destination.
    $component_to_move->setRegion('content');
    $dest_section->insertAfterComponent($block3_uuid, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_sec1 = $reloaded_storage->getSectionByUuid($section1_uuid);
    $reloaded_sec2 = $reloaded_storage->getSectionByUuid($section2_uuid);

    $this->assertCount(1, $reloaded_sec1->getComponents());
    $this->assertCount(2, $reloaded_sec2->getComponents());

    // Verify section1 only has block2.
    $sec1_uuids = array_keys($reloaded_sec1->getComponents());
    $this->assertEquals($block2_uuid, $sec1_uuids[0]);

    // Verify section2 has block3 and block1.
    $sec2_uuids = array_keys($reloaded_sec2->getComponents());
    $this->assertEquals($block3_uuid, $sec2_uuids[0]);
    $this->assertEquals($block1_uuid, $sec2_uuids[1]);
  }

  /**
   * Tests moving a block from root level into a nested section.
   *
   * Scenario: User moves a block from root level into a nested section.
   */
  public function testMoveBlockFromRootToNestedSection(): void {
    // Create a nested section wrapped in a container component.
    $nested_block_uuid = $this->uuid->generate();
    $nested_block = $this->createBasicBlockComponent($nested_block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$nested_block], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    // Create root section with the container and another block.
    $root_block_uuid = $this->uuid->generate();
    $root_block = $this->createBasicBlockComponent($root_block_uuid);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [$container, $root_block], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial state.
    $root_sec = $storage->getSectionByUuid($root_section_uuid);
    $nested_sec = $storage->getSectionByUuid($nested_section_uuid);
    $this->assertCount(2, $root_sec->getComponents());
    $this->assertCount(1, $nested_sec->getComponents());

    // Move root_block into the nested section.
    $component_to_move = $storage->getComponent($root_block_uuid);
    $source_section = $storage->getSectionFor($root_block_uuid);
    $dest_section = $storage->getSectionByUuid($nested_section_uuid);

    // Remove from root.
    $source_section->removeComponent($root_block_uuid);

    // Insert into nested section.
    $component_to_move->setRegion('content');
    $dest_section->insertAfterComponent($nested_block_uuid, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_root_sec = $reloaded_storage->getSectionByUuid($root_section_uuid);
    $reloaded_nested_sec = $reloaded_storage->getSectionByUuid($nested_section_uuid);

    // Root section should only have the container (uuid == section uuid).
    $this->assertCount(1, $reloaded_root_sec->getComponents());
    $root_sec_uuids = array_keys($reloaded_root_sec->getComponents());
    $this->assertEquals($nested_section_uuid, $root_sec_uuids[0]);

    // Nested section should have both blocks.
    $this->assertCount(2, $reloaded_nested_sec->getComponents());
    $nested_sec_uuids = array_keys($reloaded_nested_sec->getComponents());
    $this->assertEquals($nested_block_uuid, $nested_sec_uuids[0]);
    $this->assertEquals($root_block_uuid, $nested_sec_uuids[1]);

    // Verify TreeIndex is updated.
    $tree_index = $reloaded_storage->getTreeIndex();
    $moved_block_path = $tree_index->getPath($root_block_uuid);
    // Path: root_delta -> section_uuid (container) -> local delta 0 -> block.
    $this->assertEquals([0, $nested_section_uuid, 0, $root_block_uuid], $moved_block_path);
  }

  /**
   * Tests moving a block from a nested section to root level.
   *
   * Scenario: User moves a block from inside a nested section to root level.
   */
  public function testMoveBlockFromNestedSectionToRoot(): void {
    // Create a nested section with blocks, wrapped in a container.
    $nested_block1_uuid = $this->uuid->generate();
    $nested_block1 = $this->createBasicBlockComponent($nested_block1_uuid);
    $nested_block2_uuid = $this->uuid->generate();
    $nested_block2 = $this->createBasicBlockComponent($nested_block2_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$nested_block1, $nested_block2], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [$container], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial state.
    $nested_sec = $storage->getSectionByUuid($nested_section_uuid);
    $root_sec = $storage->getSectionByUuid($root_section_uuid);
    $this->assertCount(2, $nested_sec->getComponents());
    $this->assertCount(1, $root_sec->getComponents());

    // Move nested_block2 to root level.
    $component_to_move = $storage->getComponent($nested_block2_uuid);
    $source_section = $storage->getSectionFor($nested_block2_uuid);
    $dest_section = $storage->getSectionByUuid($root_section_uuid);

    // Remove from nested section.
    $source_section->removeComponent($nested_block2_uuid);

    // Insert into root section.
    $component_to_move->setRegion('content');
    $dest_section->insertAfterComponent($nested_section_uuid, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_nested_sec = $reloaded_storage->getSectionByUuid($nested_section_uuid);
    $reloaded_root_sec = $reloaded_storage->getSectionByUuid($root_section_uuid);

    // Nested section should only have nested_block1.
    $this->assertCount(1, $reloaded_nested_sec->getComponents());
    $nested_sec_uuids = array_keys($reloaded_nested_sec->getComponents());
    $this->assertEquals($nested_block1_uuid, $nested_sec_uuids[0]);

    // Root section should have the container and nested_block2.
    $this->assertCount(2, $reloaded_root_sec->getComponents());
    $root_sec_uuids = array_keys($reloaded_root_sec->getComponents());
    $this->assertEquals($nested_section_uuid, $root_sec_uuids[0]);
    $this->assertEquals($nested_block2_uuid, $root_sec_uuids[1]);

    // Verify TreeIndex is updated.
    $tree_index = $reloaded_storage->getTreeIndex();
    $moved_block_path = $tree_index->getPath($nested_block2_uuid);
    // Path should now be at root level: [root_delta, block_uuid].
    $this->assertEquals([0, $nested_block2_uuid], $moved_block_path);
  }

  /**
   * Tests moving a block between two different nested sections.
   *
   * Scenario: User moves a block from one nested section to another.
   */
  public function testMoveBlockBetweenDifferentNestedSections(): void {
    // Create first nested section with blocks.
    $nested1_block1_uuid = $this->uuid->generate();
    $nested1_block1 = $this->createBasicBlockComponent($nested1_block1_uuid);
    $nested1_block2_uuid = $this->uuid->generate();
    $nested1_block2 = $this->createBasicBlockComponent($nested1_block2_uuid);

    $nested1_section_uuid = $this->uuid->generate();
    $nested1_section = $this->createSection('layout_onecol', [$nested1_block1, $nested1_block2], $nested1_section_uuid);
    $container1 = $this->createNestedSectionComponent($nested1_section);

    // Create second nested section with one block.
    $nested2_block1_uuid = $this->uuid->generate();
    $nested2_block1 = $this->createBasicBlockComponent($nested2_block1_uuid);

    $nested2_section_uuid = $this->uuid->generate();
    $nested2_section = $this->createSection('layout_onecol', [$nested2_block1], $nested2_section_uuid);
    $container2 = $this->createNestedSectionComponent($nested2_section, 'content', 1);

    // Root section with both containers.
    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [$container1, $container2], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial state.
    $nested1_sec = $storage->getSectionByUuid($nested1_section_uuid);
    $nested2_sec = $storage->getSectionByUuid($nested2_section_uuid);
    $this->assertCount(2, $nested1_sec->getComponents());
    $this->assertCount(1, $nested2_sec->getComponents());

    // Move nested1_block2 from the first nested section to the second.
    $component_to_move = $storage->getComponent($nested1_block2_uuid);
    $source_section = $storage->getSectionFor($nested1_block2_uuid);
    $dest_section = $storage->getSectionByUuid($nested2_section_uuid);

    // Remove from source nested section.
    $source_section->removeComponent($nested1_block2_uuid);

    // Insert into destination nested section.
    $component_to_move->setRegion('content');
    $dest_section->insertAfterComponent($nested2_block1_uuid, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_nested1_sec = $reloaded_storage->getSectionByUuid($nested1_section_uuid);
    $reloaded_nested2_sec = $reloaded_storage->getSectionByUuid($nested2_section_uuid);

    // First nested section should only have nested1_block1.
    $this->assertCount(1, $reloaded_nested1_sec->getComponents());
    $nested1_uuids = array_keys($reloaded_nested1_sec->getComponents());
    $this->assertEquals($nested1_block1_uuid, $nested1_uuids[0]);

    // Second nested section should have nested2_block1 and nested1_block2.
    $this->assertCount(2, $reloaded_nested2_sec->getComponents());
    $nested2_uuids = array_keys($reloaded_nested2_sec->getComponents());
    $this->assertEquals($nested2_block1_uuid, $nested2_uuids[0]);
    $this->assertEquals($nested1_block2_uuid, $nested2_uuids[1]);

    // Verify TreeIndex is updated for moved block.
    $tree_index = $reloaded_storage->getTreeIndex();
    $moved_block_path = $tree_index->getPath($nested1_block2_uuid);
    // Path should now be inside the second container.
    $this->assertEquals([0, $nested2_section_uuid, 0, $nested1_block2_uuid], $moved_block_path);
  }

  /**
   * Tests moving a block into a newly created section at depth 2.
   *
   * Scenario: User drops a block into a section dropzone inside a nested
   * section, creating a new deeper section (a fresh container) and placing
   * the block there.
   */
  public function testMoveBlockToNewSectionInsideNestedSection(): void {
    // Create a nested section containing a block.
    $nested_block_uuid = $this->uuid->generate();
    $nested_block = $this->createBasicBlockComponent($nested_block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$nested_block], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    // Create root section with the container and another block.
    $root_block_uuid = $this->uuid->generate();
    $root_block = $this->createBasicBlockComponent($root_block_uuid);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [$container, $root_block], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // Remove the block from the root section.
    $component_to_move = $storage->getComponent($root_block_uuid);
    $source_section = $storage->getSectionFor($root_block_uuid);
    $source_section->removeComponent($root_block_uuid);

    // Create a new section holding the block and nest it INSIDE the existing
    // nested section: a value move plus a fresh container, no storage
    // conversion.
    $new_section_uuid = $this->uuid->generate();
    $new_section = $this->createSection('layout_onecol', [], $new_section_uuid);
    $component_to_move->setRegion($new_section->getDefaultRegion());
    $new_section->appendComponent($component_to_move);

    $dest_section = $storage->getSectionByUuid($nested_section_uuid);
    $dest_section->appendComponent($this->createNestedSectionComponent($new_section));

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify.
    $reloaded_storage = $this->reloadStorage($entity);

    // Root section should only have the container.
    $reloaded_root_sec = $reloaded_storage->getSectionByUuid($root_section_uuid);
    $this->assertCount(1, $reloaded_root_sec->getComponents());

    // The new section lives at depth 2 and holds the moved block.
    $reloaded_new_section = $reloaded_storage->getSectionByUuid($new_section_uuid);
    $this->assertNotNull($reloaded_new_section);
    $this->assertCount(1, $reloaded_new_section->getComponents());
    $new_sec_uuids = array_keys($reloaded_new_section->getComponents());
    $this->assertEquals($root_block_uuid, $new_sec_uuids[0]);

    // The moved block's path is three containers deep.
    $this->assertEquals(
      [0, $nested_section_uuid, 0, $new_section_uuid, 0, $root_block_uuid],
      $reloaded_storage->getPath($root_block_uuid),
    );
  }

  /**
   * Tests moving a block to the beginning of a section.
   *
   * Scenario: User moves a block to be first in a section.
   */
  public function testMoveBlockToBeginningOfSection(): void {
    // Create section with 3 blocks.
    $block1_uuid = $this->uuid->generate();
    $block1 = $this->createBasicBlockComponent($block1_uuid);
    $block2_uuid = $this->uuid->generate();
    $block2 = $this->createBasicBlockComponent($block2_uuid);
    $block3_uuid = $this->uuid->generate();
    $block3 = $this->createBasicBlockComponent($block3_uuid);

    $section_uuid = $this->uuid->generate();
    $section = $this->createSection('layout_onecol', [$block1, $block2, $block3], $section_uuid);

    $entity = $this->createTestEntity([$section]);
    $storage = $this->getNestedStorage($entity);

    // Move block3 to the beginning (before block1).
    $component_to_move = $storage->getComponent($block3_uuid);
    $source_section = $storage->getSectionFor($block3_uuid);

    // Remove from current position.
    $source_section->removeComponent($block3_uuid);

    // Insert at beginning (using insertComponent with index 0).
    $source_section->insertComponent(0, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify new order: block3, block1, block2.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_section = $reloaded_storage->getSectionByUuid($section_uuid);
    // getComponentsByRegion() sorts by weight, as Layout Builder renders.
    $reloaded_components = $reloaded_section->getComponentsByRegion('content');
    $reloaded_uuids = array_keys($reloaded_components);

    $this->assertCount(3, $reloaded_uuids);
    $this->assertEquals($block3_uuid, $reloaded_uuids[0]);
    $this->assertEquals($block1_uuid, $reloaded_uuids[1]);
    $this->assertEquals($block2_uuid, $reloaded_uuids[2]);
  }

  /**
   * Tests moving a nested section preserves its content.
   *
   * Scenario: User moves a nested section (via its container component) to a
   * different root section, and all nested content is preserved. Under the
   * embedded-section model this is a pure component move: the section value
   * rides inside the container's configuration.
   */
  public function testMoveNestedSectionPreservesContent(): void {
    // Create a nested section with content.
    $deeply_nested_block_uuid = $this->uuid->generate();
    $deeply_nested_block = $this->createBasicBlockComponent($deeply_nested_block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$deeply_nested_block], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    // Create two root sections.
    $root_block_uuid = $this->uuid->generate();
    $root_block = $this->createBasicBlockComponent($root_block_uuid);

    $root_section1_uuid = $this->uuid->generate();
    $root_section1 = $this->createSection('layout_onecol', [$container], $root_section1_uuid);

    $root_section2_uuid = $this->uuid->generate();
    $root_section2 = $this->createSection('layout_onecol', [$root_block], $root_section2_uuid);

    $entity = $this->createTestEntity([$root_section1, $root_section2]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial TreeIndex: one identity for the nested section.
    $tree_index = $storage->getTreeIndex();
    $this->assertTrue($tree_index->isSection($nested_section_uuid));
    $this->assertFalse($tree_index->isBlock($nested_section_uuid));
    $this->assertTrue($tree_index->isBlock($deeply_nested_block_uuid));

    // Move the container from section1 to section2, addressed by the
    // SECTION uuid (component uuid == section uuid).
    $component_to_move = $storage->getContainerComponent($nested_section_uuid);
    $this->assertNotNull($component_to_move);
    $source_section = $storage->getSectionByUuid($root_section1_uuid);
    $dest_section = $storage->getSectionByUuid($root_section2_uuid);

    // Remove from source.
    $source_section->removeComponent($nested_section_uuid);

    // Insert into destination.
    $dest_section->insertAfterComponent($root_block_uuid, $component_to_move);

    // Invalidate cache and bubble.
    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify nested content is preserved.
    $reloaded_storage = $this->reloadStorage($entity);

    // Section1 should be empty.
    $reloaded_sec1 = $reloaded_storage->getSectionByUuid($root_section1_uuid);
    $this->assertCount(0, $reloaded_sec1->getComponents());

    // Section2 should have root_block and the container.
    $reloaded_sec2 = $reloaded_storage->getSectionByUuid($root_section2_uuid);
    $this->assertCount(2, $reloaded_sec2->getComponents());

    // Verify nested content is still accessible.
    $reloaded_tree_index = $reloaded_storage->getTreeIndex();
    $this->assertTrue($reloaded_tree_index->isSection($nested_section_uuid));
    $this->assertTrue($reloaded_tree_index->isBlock($deeply_nested_block_uuid));

    // Verify the deeply nested block is in the correct location.
    $reloaded_nested_section = $reloaded_storage->getSectionByUuid($nested_section_uuid);
    $this->assertCount(1, $reloaded_nested_section->getComponents());
    $nested_block_uuids = array_keys($reloaded_nested_section->getComponents());
    $this->assertEquals($deeply_nested_block_uuid, $nested_block_uuids[0]);

    // Verify updated path for the nested section (delta 0 in its container).
    $this->assertEquals([1, $nested_section_uuid, 0], $reloaded_tree_index->getPath($nested_section_uuid));

    // Verify updated path for the deeply nested block.
    $this->assertEquals(
      [1, $nested_section_uuid, 0, $deeply_nested_block_uuid],
      $reloaded_tree_index->getPath($deeply_nested_block_uuid),
    );
  }

}
