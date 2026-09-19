<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\ElementContext;
use Drupal\daedalus_blueprint\ElementLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the ElementLocator service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ElementLocator::class)]
#[Group('daedalus_blueprint')]
class ElementLocatorTest extends BlueprintKernelTestBase {

  /**
   * The component locator.
   *
   * @var \Drupal\daedalus_blueprint\ElementLocator
   */
  protected ElementLocator $locator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableEntityReferenceRevisions();

    $this->locator = $this->container->get('daedalus_blueprint.element_locator');

    // Set up ERR fields for paragraph testing.
    $this->createRevField('field_text', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    // ERR field on entity_test_rev for nested paragraphs.
    $this->createEntityReferenceRevisionsField(
      'field_children',
      'entity_test_rev',
      'entity_test_rev',
      'entity_test_rev',
    );
  }

  /**
   * Refreshes the locator from the rebuilt container.
   *
   * Call after enableLayoutBuilder() to ensure the locator uses
   * up-to-date service references.
   */
  protected function refreshLocator(): void {
    $this->locator = $this->container->get('daedalus_blueprint.element_locator');
  }

  /**
   * Tests locating a Layout Builder component by UUID.
   */
  public function testLocateLbComponent(): void {
    $this->enableLayoutBuilder();
    $this->refreshLocator();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => 'Hello', 'format' => 'plain_text'],
    ]);

    $component_uuid = 'test-comp-uuid-123';
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => $component_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'My Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $context = $this->locator->locate($entity, $component_uuid);

    $this->assertNotNull($context);
    $this->assertTrue($context->isLbComponent());
    $this->assertFalse($context->isParagraph());
    $this->assertEquals(ElementContext::TYPE_LB_COMPONENT, $context->type);
    $this->assertEquals($component_uuid, $context->target->getUuid());
    $this->assertSame($entity->id(), $context->parentEntity->id());
    $this->assertNotNull($context->section);
    $this->assertEquals(0, $context->sectionIndex);
    $this->assertEquals('layout_builder__layout[0].components[0]', $context->path);
  }

  /**
   * Tests locating a paragraph entity by UUID.
   */
  public function testLocateParagraph(): void {
    $child = $this->createRevEntity([
      'name' => 'Child paragraph',
      'field_text' => 'Some text',
    ]);

    $entity = $this->createTestEntity([
      'field_paragraphs' => [
        ['entity' => $child],
      ],
    ]);

    // Reload to get fresh references.
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $context = $this->locator->locate($entity, $child->uuid());

    $this->assertNotNull($context);
    $this->assertTrue($context->isParagraph());
    $this->assertFalse($context->isLbComponent());
    $this->assertEquals(ElementContext::TYPE_PARAGRAPH, $context->type);
    $this->assertEquals($child->uuid(), $context->target->uuid());
    $this->assertSame($entity->id(), $context->parentEntity->id());
    $this->assertEquals('field_paragraphs', $context->fieldName);
    $this->assertEquals(0, $context->delta);
    $this->assertEquals('fields.field_paragraphs[0]', $context->path);
  }

  /**
   * Tests locating a nested paragraph (child of a paragraph).
   */
  public function testLocateNestedParagraph(): void {
    $grandchild = $this->createRevEntity([
      'name' => 'Grandchild',
      'field_text' => 'Deeply nested',
    ]);

    $child = $this->createRevEntity([
      'name' => 'Child',
      'field_children' => [
        ['entity' => $grandchild],
      ],
    ]);

    $entity = $this->createTestEntity([
      'field_paragraphs' => [
        ['entity' => $child],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $context = $this->locator->locate($entity, $grandchild->uuid());

    $this->assertNotNull($context);
    $this->assertTrue($context->isParagraph());
    $this->assertEquals($grandchild->uuid(), $context->target->uuid());
    $this->assertEquals('fields.field_paragraphs[0].fields.field_children[0]', $context->path);
    $this->assertEquals('field_children', $context->fieldName);
    $this->assertEquals(0, $context->delta);
  }

  /**
   * Tests locating a second-item paragraph by UUID.
   */
  public function testLocateSecondItemParagraph(): void {
    $first = $this->createRevEntity(['name' => 'First']);
    $second = $this->createRevEntity(['name' => 'Second']);

    $entity = $this->createTestEntity([
      'field_paragraphs' => [
        ['entity' => $first],
        ['entity' => $second],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $context = $this->locator->locate($entity, $second->uuid());

    $this->assertNotNull($context);
    $this->assertEquals('fields.field_paragraphs[1]', $context->path);
    $this->assertEquals(1, $context->delta);
  }

  /**
   * Tests locating returns NULL for nonexistent UUID.
   */
  public function testLocateReturnsNullForNonexistentUuid(): void {
    $entity = $this->createTestEntity();

    $context = $this->locator->locate($entity, 'c68c9561-d36d-b4fc-77ac-50039f354800');

    $this->assertNull($context);
  }

  /**
   * Tests locating with deeply nested paragraphs (5 levels).
   */
  public function testLocateDeeplyNestedParagraph(): void {
    // Create a chain: level5 -> level4 -> level3 -> level2 -> level1 -> root.
    $level5 = $this->createRevEntity(['name' => 'Level 5']);
    $level4 = $this->createRevEntity([
      'name' => 'Level 4',
      'field_children' => [['entity' => $level5]],
    ]);
    $level3 = $this->createRevEntity([
      'name' => 'Level 3',
      'field_children' => [['entity' => $level4]],
    ]);
    $level2 = $this->createRevEntity([
      'name' => 'Level 2',
      'field_children' => [['entity' => $level3]],
    ]);
    $level1 = $this->createRevEntity([
      'name' => 'Level 1',
      'field_children' => [['entity' => $level2]],
    ]);

    $entity = $this->createTestEntity([
      'field_paragraphs' => [['entity' => $level1]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $context = $this->locator->locate($entity, $level5->uuid());

    $this->assertNotNull($context);
    $this->assertEquals(
      'fields.field_paragraphs[0].fields.field_children[0].fields.field_children[0].fields.field_children[0].fields.field_children[0]',
      $context->path,
    );
  }

  /**
   * Tests that nested layout components are not found without daedalus_layout.
   *
   * Nested layouts are an daedalus_layout feature. Without daedalus_layout, the flat
   * section search cannot traverse into nested block_content layouts,
   * so the nested component should not be found.
   */
  public function testLocateNestedLayoutComponent(): void {
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    // Enable LB overrides on block_content to allow nested layouts.
    $this->enableLayoutBuilder('block_content', 'text_section');
    $this->refreshLocator();

    // Create the nested block (innermost).
    $nested_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => 'Nested content', 'format' => 'plain_text'],
    ]);

    $nested_comp_uuid = 'nested-comp-uuid';
    $nested_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $nested_comp_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $nested_block, 'Nested'),
      ],
    ]);

    // Create the parent block that contains the nested layout.
    $parent_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'layout_builder__layout' => [$nested_section],
    ]);

    $parent_comp_uuid = 'parent-comp-uuid';
    $root_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $parent_comp_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $parent_block, 'Parent'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$root_section],
    ]);
    $entity->save();

    // Without daedalus_layout, nested layout components are not reachable.
    $context = $this->locator->locate($entity, $nested_comp_uuid);
    $this->assertNull($context);

    // The parent (root-level) component should still be found.
    $context = $this->locator->locate($entity, $parent_comp_uuid);
    $this->assertNotNull($context);
    $this->assertTrue($context->isLbComponent());
    $this->assertEquals($parent_comp_uuid, $context->target->getUuid());
  }

  /**
   * Tests locating an LB component in the second section.
   */
  public function testLocateComponentInSecondSection(): void {
    $this->enableLayoutBuilder();
    $this->refreshLocator();
    $this->createBlockContentType('text_section', 'Text Section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
    ]);
    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
    ]);

    $target_uuid = 'target-in-section-2';
    $section1 = $this->createSection('layout_onecol', [
      [
        'uuid' => 'caea751c-bbcf-3f31-3052-2bf6887bd53f',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'Block 1'),
      ],
    ]);
    $section2 = $this->createSection('layout_onecol', [
      [
        'uuid' => $target_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'Block 2'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section1, $section2],
    ]);
    $entity->save();

    $context = $this->locator->locate($entity, $target_uuid);

    $this->assertNotNull($context);
    $this->assertEquals(1, $context->sectionIndex);
    $this->assertEquals('layout_builder__layout[1].components[0]', $context->path);
  }

  /**
   * Tests locate on entity with no LB or paragraphs returns NULL.
   */
  public function testLocateOnPlainEntityReturnsNull(): void {
    $entity = $this->createTestEntity(['field_subtitle' => 'Hello']);

    $context = $this->locator->locate($entity, 'any-uuid');

    $this->assertNull($context);
  }

}
