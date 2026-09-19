<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Nested-storage semantics of moving sections at various nesting levels.
 *
 * Under the embedded-section model a depth crossing is a VALUE move: a root
 * section becomes nested by wrapping the same Section object in a container
 * component, and a nested section becomes a root row by lifting the value
 * out. Identity (the daedalus_layout uuid) survives every crossing; there is no
 * storage conversion.
 */
#[CoversClass(\Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class NestedStorageMoveSectionTest extends NestedStorageKernelTestBase {

  /**
   * Tests reordering sections at root level (move down).
   *
   * Scenario: User moves section 1 to after section 3 at root level.
   */
  public function testReorderSectionsAtRootMoveDown(): void {
    // Create 3 sections at root.
    $section1_uuid = $this->uuid->generate();
    $section1 = $this->createSection('layout_onecol', [], $section1_uuid);
    $section2_uuid = $this->uuid->generate();
    $section2 = $this->createSection('layout_onecol', [], $section2_uuid);
    $section3_uuid = $this->uuid->generate();
    $section3 = $this->createSection('layout_onecol', [], $section3_uuid);

    $entity = $this->createTestEntity([$section1, $section2, $section3]);
    $storage = $this->getNestedStorage($entity);

    // Verify initial order: section1, section2, section3.
    $this->assertCount(3, $storage->getSections());
    $sections = $storage->getSections();
    $this->assertEquals($section1_uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertEquals($section2_uuid, $sections[1]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertEquals($section3_uuid, $sections[2]->getThirdPartySetting('daedalus_layout', 'uuid'));

    // Move section1 to after section3 (move to end).
    $section = $storage->getSectionByUuid($section1_uuid);
    $source_section_list = $storage->getSectionsFor($section1_uuid);

    $source_delta = $this->findSectionDeltaInList($source_section_list, $section1_uuid);
    $this->assertEquals(0, $source_delta);
    $source_section_list->removeSection($source_delta);
    $source_section_list->appendSection($section);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify new order: section2, section3, section1.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded = $reloaded_storage->getSections();
    $this->assertCount(3, $reloaded);
    $this->assertEquals($section2_uuid, $reloaded[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertEquals($section3_uuid, $reloaded[1]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertEquals($section1_uuid, $reloaded[2]->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests reordering sections at root level (move up).
   *
   * Scenario: User moves section 3 to before section 1 at root level.
   */
  public function testReorderSectionsAtRootMoveUp(): void {
    $section1_uuid = $this->uuid->generate();
    $section1 = $this->createSection('layout_onecol', [], $section1_uuid);
    $section2_uuid = $this->uuid->generate();
    $section2 = $this->createSection('layout_onecol', [], $section2_uuid);
    $section3_uuid = $this->uuid->generate();
    $section3 = $this->createSection('layout_onecol', [], $section3_uuid);

    $entity = $this->createTestEntity([$section1, $section2, $section3]);
    $storage = $this->getNestedStorage($entity);

    // Move section3 to the beginning.
    $section = $storage->getSectionByUuid($section3_uuid);
    $source_section_list = $storage->getSectionsFor($section3_uuid);
    $source_delta = $this->findSectionDeltaInList($source_section_list, $section3_uuid);
    $this->assertEquals(2, $source_delta);
    $source_section_list->removeSection($source_delta);
    $source_section_list->insertSection(0, $section);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify new order: section3, section1, section2.
    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded = $reloaded_storage->getSections();
    $this->assertCount(3, $reloaded);
    $this->assertEquals($section3_uuid, $reloaded[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertEquals($section1_uuid, $reloaded[1]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertEquals($section2_uuid, $reloaded[2]->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests reordering sibling nested sections inside one parent section.
   *
   * A nested section's order among its siblings is its container
   * component's weight; reordering is a component reorder, not a list
   * operation.
   */
  public function testReorderNestedSectionsWithinParent(): void {
    $nested1_uuid = $this->uuid->generate();
    $nested1 = $this->createSection('layout_onecol', [], $nested1_uuid);
    $nested2_uuid = $this->uuid->generate();
    $nested2 = $this->createSection('layout_onecol', [], $nested2_uuid);

    $parent_section_uuid = $this->uuid->generate();
    $parent_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested1, 'content', 0),
      $this->createNestedSectionComponent($nested2, 'content', 1),
    ], $parent_section_uuid);

    $entity = $this->createTestEntity([$parent_section]);
    $storage = $this->getNestedStorage($entity);

    // Initial order by weight: nested1, nested2.
    $parent = $storage->getSectionByUuid($parent_section_uuid);
    $ordered = array_keys($parent->getComponentsByRegion('content'));
    $this->assertEquals([$nested1_uuid, $nested2_uuid], $ordered);

    // Swap the container weights.
    $storage->getContainerComponent($nested1_uuid)->setWeight(2);
    $storage->getContainerComponent($nested2_uuid)->setWeight(1);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    $reloaded_storage = $this->reloadStorage($entity);
    $reloaded_parent = $reloaded_storage->getSectionByUuid($parent_section_uuid);
    $reloaded_order = array_keys($reloaded_parent->getComponentsByRegion('content'));
    $this->assertEquals([$nested2_uuid, $nested1_uuid], $reloaded_order);
  }

  /**
   * Tests moving a section from root level into another section.
   *
   * Scenario: User drags a whole root section into a sibling section. The
   * same Section object is wrapped in a container component; identity and
   * content survive.
   */
  public function testMoveSectionFromRootToNested(): void {
    $block_uuid = $this->uuid->generate();
    $block = $this->createBasicBlockComponent($block_uuid);

    $root_section1_uuid = $this->uuid->generate();
    $root_section1 = $this->createSection('layout_onecol', [], $root_section1_uuid);

    $root_section2_uuid = $this->uuid->generate();
    $root_section2 = $this->createSection('layout_onecol', [$block], $root_section2_uuid);

    $entity = $this->createTestEntity([$root_section1, $root_section2]);
    $storage = $this->getNestedStorage($entity);
    $this->assertCount(2, $storage->getSections());

    // Lift section2 out of the root list and nest it inside section1.
    $section = $storage->getSectionByUuid($root_section2_uuid);
    $root_list = $storage->getSectionsFor($root_section2_uuid);
    $delta = $this->findSectionDeltaInList($root_list, $root_section2_uuid);
    $root_list->removeSection($delta);

    $dest_section = $storage->getSectionByUuid($root_section1_uuid);
    $dest_section->appendComponent($this->createNestedSectionComponent($section));

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify: one root section, section2 nested inside it.
    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertCount(1, $reloaded_storage->getSections());

    $reloaded_nested = $reloaded_storage->getSectionByUuid($root_section2_uuid);
    $this->assertNotNull($reloaded_nested);
    $this->assertEquals(
      [0, $root_section2_uuid, 0],
      $reloaded_storage->getPath($root_section2_uuid),
    );

    // The section kept its content across the depth crossing.
    $this->assertCount(1, $reloaded_nested->getComponents());
    $this->assertEquals(
      [0, $root_section2_uuid, 0, $block_uuid],
      $reloaded_storage->getPath($block_uuid),
    );
  }

  /**
   * Tests moving a nested section to root level.
   *
   * Scenario: User promotes a nested section to a root row. The embedded
   * value is lifted into the root list and its container removed (a
   * container holds exactly one section; emptying it removes it).
   */
  public function testMoveSectionFromNestedToRoot(): void {
    $block_uuid = $this->uuid->generate();
    $block = $this->createBasicBlockComponent($block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$block], $nested_section_uuid);

    $root_section_uuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested_section),
    ], $root_section_uuid);

    $entity = $this->createTestEntity([$root_section]);
    $storage = $this->getNestedStorage($entity);
    $this->assertCount(1, $storage->getSections());

    // Lift the nested section out: remove it from its embedded list (which
    // removes the container) and append the same Section object to root.
    $section = $storage->getSectionByUuid($nested_section_uuid);
    $embedded_list = $storage->getSectionsFor($nested_section_uuid);
    $embedded_list->removeSection(0);
    $storage->getRootStorage()->appendSection($section);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify: two root sections, identity preserved.
    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertCount(2, $reloaded_storage->getSections());
    $this->assertEquals([1], $reloaded_storage->getPath($nested_section_uuid));

    // The container component is gone from the old parent.
    $reloaded_root = $reloaded_storage->getSectionByUuid($root_section_uuid);
    $this->assertCount(0, $reloaded_root->getComponents());

    // Content survived the promotion.
    $this->assertEquals([1, $block_uuid], $reloaded_storage->getPath($block_uuid));
  }

  /**
   * Tests moving a nested section between two different parent sections.
   *
   * Scenario: User drags a nested section from one parent into another. A
   * pure component move: the container carries the section value.
   */
  public function testMoveSectionBetweenParents(): void {
    $block_uuid = $this->uuid->generate();
    $block = $this->createBasicBlockComponent($block_uuid);

    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [$block], $nested_section_uuid);

    $root_section1_uuid = $this->uuid->generate();
    $root_section1 = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested_section),
    ], $root_section1_uuid);

    $root_section2_uuid = $this->uuid->generate();
    $root_section2 = $this->createSection('layout_onecol', [], $root_section2_uuid);

    $entity = $this->createTestEntity([$root_section1, $root_section2]);
    $storage = $this->getNestedStorage($entity);

    // Move the container component between the parents, addressed by the
    // SECTION uuid (component uuid == section uuid).
    $container = $storage->getContainerComponent($nested_section_uuid);
    $this->assertNotNull($container);
    $storage->getSectionByUuid($root_section1_uuid)->removeComponent($nested_section_uuid);
    $storage->getSectionByUuid($root_section2_uuid)->appendComponent($container);

    $storage->invalidateCache();
    $storage->bubbleChangesToRoot();
    $entity->set('layout_builder__layout', $storage->getSections());
    $entity->save();

    // Reload and verify the new location and preserved content.
    $reloaded_storage = $this->reloadStorage($entity);
    $this->assertEquals(
      [1, $nested_section_uuid, 0],
      $reloaded_storage->getPath($nested_section_uuid),
    );
    $this->assertEquals(
      [1, $nested_section_uuid, 0, $block_uuid],
      $reloaded_storage->getPath($block_uuid),
    );
    $this->assertCount(0, $reloaded_storage->getSectionByUuid($root_section1_uuid)->getComponents());
  }

}
