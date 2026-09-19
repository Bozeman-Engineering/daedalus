<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel\Form;

use Drupal\daedalus_layout\TreeIndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for section removal via the nested storage API.
 *
 * Exercises the storage operations core's RemoveSectionForm triggers:
 * removeSection($delta) against the (possibly scoped) storage. For a nested
 * section, removing the only entry of its embedded list removes the
 * container component itself: an empty container is not a state.
 */
#[CoversClass(\Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class RemoveSectionFormTest extends NestedStorageKernelTestBase {

  /**
   * Tests removing a section at root level.
   */
  public function testRemoveSectionAtRootLevel(): void {
    $section1_uuid = $this->uuid->generate();
    $section1 = $this->createSection('layout_onecol', [], $section1_uuid);
    $section2_uuid = $this->uuid->generate();
    $section2 = $this->createSection('layout_onecol', [], $section2_uuid);

    $entity = $this->createTestEntity([$section1, $section2]);
    $storage = $this->getNestedStorage($entity);

    $this->assertCount(2, $storage->getSections());

    // Remove section1 the way RemoveSectionForm does: by delta on the list
    // containing it.
    $section_list = $storage->getSectionsFor($section1_uuid);
    $delta = $this->findSectionDeltaInList($section_list, $section1_uuid);
    $section_list->removeSection($delta);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertCount(1, $reloaded_storage->getSections());
    $this->assertNull($reloaded_storage->getSectionByUuid($section1_uuid));
    $this->assertNotNull($reloaded_storage->getSectionByUuid($section2_uuid));
  }

  /**
   * Tests removing a nested section removes its container.
   *
   * Core's RemoveSectionForm submits removeSection($delta) against the
   * scoped storage; for a nested section that empties the embedded list,
   * which removes the container component from its parent.
   */
  public function testRemoveNestedSectionRemovesContainer(): void {
    $nested_block_uuid = $this->uuid->generate();
    $nested_block = $this->createBasicBlockComponent($nested_block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$nested_block], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);

    $root_block_uuid = $this->uuid->generate();
    $root_block = $this->createBasicBlockComponent($root_block_uuid);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [$container, $root_block], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // The scoped view core forms get from the route enhancer: the nested
    // section is delta localDelta (always 0) of its own scope.
    $context = $storage->getNestedContext($nested_section_uuid);
    $this->assertSame($nested_section_uuid, $context['sectionUuid']);
    $this->assertSame(0, $context['localDelta']);
    $scoped = $storage->forSection($context['sectionUuid']);
    $this->assertCount(1, $scoped->getSections());

    // RemoveSectionForm's submit.
    $scoped->removeSection(0);

    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);

    // The nested section, its container, and its content are gone.
    $this->assertNull($reloaded_storage->getSectionByUuid($nested_section_uuid));
    $this->assertNull($reloaded_storage->getContainerComponent($nested_section_uuid));
    $this->assertNull($reloaded_storage->getComponent($nested_block_uuid));

    // The parent section and its other content survive.
    $reloaded_root = $reloaded_storage->getSectionByUuid($root_section_uuid);
    $this->assertCount(1, $reloaded_root->getComponents());
    $this->assertNotNull($reloaded_storage->getComponent($root_block_uuid));
  }

  /**
   * Tests removing a root section that contains blocks removes them too.
   */
  public function testRemoveSectionWithBlocks(): void {
    $block1_uuid = $this->uuid->generate();
    $block1 = $this->createBasicBlockComponent($block1_uuid);
    $block2_uuid = $this->uuid->generate();
    $block2 = $this->createBasicBlockComponent($block2_uuid);

    $section_uuid = $this->uuid->generate();
    $section = $this->createSection('layout_onecol', [$block1, $block2], $section_uuid);

    $other_section_uuid = $this->uuid->generate();
    $other_section = $this->createSection('layout_onecol', [], $other_section_uuid);

    $entity = $this->createTestEntity([$section, $other_section]);
    $storage = $this->getNestedStorage($entity);

    $this->assertNotNull($storage->getComponent($block1_uuid));

    $section_list = $storage->getSectionsFor($section_uuid);
    $delta = $this->findSectionDeltaInList($section_list, $section_uuid);
    $section_list->removeSection($delta);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertCount(1, $reloaded_storage->getSections());
    $this->assertNull($reloaded_storage->getSectionByUuid($section_uuid));
    $this->assertNull($reloaded_storage->getComponent($block1_uuid));
    $this->assertNull($reloaded_storage->getComponent($block2_uuid));
  }

  /**
   * Tests removing a nested section that itself contains a deeper section.
   *
   * The whole subtree rides inside the container's configuration, so
   * removing the depth-1 container drops the depth-2 section with it.
   */
  public function testRemoveNestedSectionRemovesDeeperSubtree(): void {
    $deep_block_uuid = $this->uuid->generate();
    $deep_block = $this->createBasicBlockComponent($deep_block_uuid);

    $deep_section_uuid = $this->uuid->generate();
    $deep_section = $this->createSection('layout_onecol', [$deep_block], $deep_section_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($deep_section),
    ], $nested_section_uuid);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested_section),
    ], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);

    // The whole chain is indexed.
    $this->assertEquals(
      [0, $nested_section_uuid, 0, $deep_section_uuid, 0, $deep_block_uuid],
      $storage->getPath($deep_block_uuid),
    );

    // Remove the depth-1 section through its scope.
    $storage->forSection($nested_section_uuid)->removeSection(0);

    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertNull($reloaded_storage->getSectionByUuid($nested_section_uuid));
    $this->assertNull($reloaded_storage->getSectionByUuid($deep_section_uuid));
    $this->assertNull($reloaded_storage->getComponent($deep_block_uuid));
    $this->assertCount(0, $reloaded_storage->getSectionByUuid($root_section_uuid)->getComponents());
  }

  /**
   * Tests that TreeIndex is properly updated after section removal.
   */
  public function testTreeIndexUpdatesAfterSectionRemoval(): void {
    $section_uuids = [];
    $sections = [];
    for ($i = 0; $i < 3; $i++) {
      $uuid = $this->uuid->generate();
      $section_uuids[] = $uuid;
      $sections[] = $this->createSection('layout_onecol', [], $uuid);
    }

    $entity = $this->createTestEntity($sections);
    $storage = $this->getNestedStorage($entity);

    $tree_index = $storage->getTreeIndex();
    $this->assertCount(3, $tree_index->getAllOfType(TreeIndexInterface::TYPE_SECTION));

    // Remove the middle section.
    $uuid_to_remove = $section_uuids[1];
    $section_list = $storage->getSectionsFor($uuid_to_remove);
    $delta = $this->findSectionDeltaInList($section_list, $uuid_to_remove);
    $section_list->removeSection($delta);

    $storage->invalidateCache();
    $updated_tree_index = $storage->getTreeIndex();

    $this->assertCount(2, $updated_tree_index->getAllOfType(TreeIndexInterface::TYPE_SECTION));
    $this->assertFalse($updated_tree_index->isSection($uuid_to_remove));

    // The remaining sections re-index at their new deltas.
    $this->assertEquals([0], $updated_tree_index->getPath($section_uuids[0]));
    $this->assertEquals([1], $updated_tree_index->getPath($section_uuids[2]));

    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertCount(2, $reloaded_storage->getSections());
    $this->assertNull($reloaded_storage->getSectionByUuid($uuid_to_remove));
  }

}
