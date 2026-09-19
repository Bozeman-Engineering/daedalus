<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the anchor-relative placement grammar.
 *
 * The grammar {anchor, placement: before|after|inside} resolves an insertion
 * point relative to existing content. Section anchors require daedalus_layout section
 * UUIDs, so those paths are covered by the functional assembled-stack tests;
 * this kernel test covers component anchors, paragraph anchors, the root
 * entity anchor, and the grammar's error cases.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsPlacementTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperations
   */
  protected BlueprintOperations $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Refreshes the operations service from the rebuilt container.
   */
  protected function refreshOperations(): void {
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Creates an entity with one section holding two inline blocks.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity, with components 'faf95c53-35e7-74e1-184e-041152031df4'
   *   and '9bc043d4-072e-d2ff-9909-5b1e2e3c9826' in the 'content' region.
   */
  protected function createLbEntityWithTwoComponents() {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 1',
    ]);
    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 2',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'Block 1'),
      ],
      [
        'uuid' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'Block 2'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Returns the ordered component UUIDs of a region.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $region
   *   The region name.
   *
   * @return string[]
   *   Component UUIDs in weight order.
   */
  protected function regionOrder($entity, string $region): array {
    $section = $entity->get('layout_builder__layout')->first()->section;
    return array_keys($section->getComponentsByRegion($region));
  }

  /**
   * Tests placing a component after an anchor component.
   */
  public function testAnchorAfterComponent(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->addElement($entity, [
      'anchor' => 'faf95c53-35e7-74e1-184e-041152031df4',
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
      'block_fields' => ['field_heading' => 'Inserted'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      'faf95c53-35e7-74e1-184e-041152031df4',
      '7900c460-e311-ca39-03f4-74e02e35140a',
      '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
    ], $this->regionOrder($entity, 'content'));
  }

  /**
   * Tests placing a component before an anchor component.
   */
  public function testAnchorBeforeComponent(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->addElement($entity, [
      'anchor' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
      'placement' => 'before',
    ], [
      'block_type' => 'text_section',
      'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
      'block_fields' => ['field_heading' => 'Inserted'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      'faf95c53-35e7-74e1-184e-041152031df4',
      '7900c460-e311-ca39-03f4-74e02e35140a',
      '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
    ], $this->regionOrder($entity, 'content'));
  }

  /**
   * Tests that the region is derived from the anchor, not defaulted.
   */
  public function testAnchorComponentDerivesRegion(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Second region block',
    ]);

    $section = $this->createSection('layout_twocol_section', [
      [
        'uuid' => '954a3461-12eb-33d0-0e18-a5c309143f7f',
        'region' => 'second',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Second'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => '954a3461-12eb-33d0-0e18-a5c309143f7f',
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
      'block_fields' => ['field_heading' => 'Also second'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame(['954a3461-12eb-33d0-0e18-a5c309143f7f', '7900c460-e311-ca39-03f4-74e02e35140a'], $this->regionOrder($entity, 'second'));
    $this->assertSame([], $this->regionOrder($entity, 'first'));
  }

  /**
   * Tests that inside placement is rejected on a component anchor.
   */
  public function testAnchorComponentInsideRejected(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->addElement($entity, [
      'anchor' => 'faf95c53-35e7-74e1-184e-041152031df4',
      'placement' => 'inside',
    ], [
      'block_type' => 'text_section',
      'block_fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests placing a paragraph after an anchor paragraph.
   */
  public function testAnchorAfterParagraph(): void {
    $entity = $this->createParagraphEntity();
    $anchor_uuid = $entity->get('field_paragraphs')->get(0)->entity->uuid();

    $result = $this->operations->addElement($entity, [
      'anchor' => $anchor_uuid,
      'placement' => 'after',
    ], [
      'type' => 'entity_test_rev',
      'fields' => ['field_heading' => 'Inserted'],
    ]);

    $this->assertTrue($result->isSuccess());
    $items = $entity->get('field_paragraphs');
    $this->assertCount(3, $items);
    $this->assertEquals('First', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('Inserted', $items->get(1)->entity->get('field_heading')->value);
    $this->assertEquals('Second', $items->get(2)->entity->get('field_heading')->value);
  }

  /**
   * Tests placing a paragraph before an anchor paragraph.
   */
  public function testAnchorBeforeParagraph(): void {
    $entity = $this->createParagraphEntity();
    $anchor_uuid = $entity->get('field_paragraphs')->get(1)->entity->uuid();

    $result = $this->operations->addElement($entity, [
      'anchor' => $anchor_uuid,
      'placement' => 'before',
    ], [
      'type' => 'entity_test_rev',
      'fields' => ['field_heading' => 'Inserted'],
    ]);

    $this->assertTrue($result->isSuccess());
    $items = $entity->get('field_paragraphs');
    $this->assertCount(3, $items);
    $this->assertEquals('First', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('Inserted', $items->get(1)->entity->get('field_heading')->value);
    $this->assertEquals('Second', $items->get(2)->entity->get('field_heading')->value);
  }

  /**
   * Tests inside placement on a paragraph anchor targets its nested field.
   */
  public function testAnchorParagraphInside(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $this->createEntityReferenceRevisionsField(
      'field_items',
      'entity_test_rev',
      'entity_test_rev',
      'entity_test_rev',
    );

    $child = $this->createRevEntity([
      'name' => 'Child',
      'field_heading' => 'Existing Child',
    ]);
    $parent = $this->createRevEntity([
      'name' => 'Parent',
      'field_items' => [['entity' => $child]],
    ]);
    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $parent]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $parent_uuid = $entity->get('field_paragraphs')->first()->entity->uuid();

    $result = $this->operations->addElement($entity, [
      'anchor' => $parent_uuid,
      'placement' => 'inside',
      'field' => 'field_items',
    ], [
      'type' => 'entity_test_rev',
      'fields' => ['field_heading' => 'New Child'],
    ]);

    $this->assertTrue($result->isSuccess());
    $nested_items = $entity->get('field_paragraphs')->first()->entity->get('field_items');
    $this->assertCount(2, $nested_items);
    $this->assertEquals('Existing Child', $nested_items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('New Child', $nested_items->get(1)->entity->get('field_heading')->value);
  }

  /**
   * Tests inside placement on a paragraph anchor without a field errors.
   */
  public function testAnchorParagraphInsideMissingField(): void {
    $entity = $this->createParagraphEntity();
    $anchor_uuid = $entity->get('field_paragraphs')->get(0)->entity->uuid();

    $result = $this->operations->addElement($entity, [
      'anchor' => $anchor_uuid,
      'placement' => 'inside',
    ], [
      'type' => 'entity_test_rev',
      'fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('MISSING_FIELD', $result->getErrors()[0]->getCode());
    $this->assertEquals('location.field', $result->getErrors()[0]->getPath());
  }

  /**
   * Tests inside placement anchored on the root entity itself.
   */
  public function testAnchorRootEntityInside(): void {
    $entity = $this->createParagraphEntity();

    $result = $this->operations->addElement($entity, [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
      'field' => 'field_paragraphs',
    ], [
      'type' => 'entity_test_rev',
      'fields' => ['field_heading' => 'Appended'],
    ]);

    $this->assertTrue($result->isSuccess());
    $items = $entity->get('field_paragraphs');
    $this->assertCount(3, $items);
    $this->assertEquals('Appended', $items->get(2)->entity->get('field_heading')->value);
  }

  /**
   * Tests before/after placement on the root entity anchor errors.
   */
  public function testAnchorRootEntityBeforeRejected(): void {
    $entity = $this->createParagraphEntity();

    $result = $this->operations->addElement($entity, [
      'anchor' => $entity->uuid(),
      'placement' => 'before',
      'field' => 'field_paragraphs',
    ], [
      'type' => 'entity_test_rev',
      'fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests an unknown anchor UUID errors.
   */
  public function testAnchorNotFound(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->addElement($entity, [
      'anchor' => 'c68c9561-d36d-b4fc-77ac-50039f354800',
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'block_fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('ANCHOR_NOT_FOUND', $result->getErrors()[0]->getCode());
    $this->assertEquals('location.anchor', $result->getErrors()[0]->getPath());
  }

  /**
   * Tests an invalid placement keyword errors.
   */
  public function testInvalidPlacementKeyword(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->addElement($entity, [
      'anchor' => 'faf95c53-35e7-74e1-184e-041152031df4',
      'placement' => 'above',
    ], [
      'block_type' => 'text_section',
      'block_fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertEquals('INVALID_PLACEMENT', $error->getCode());
    $this->assertEquals(['before', 'after', 'inside'], $error->getContext()['valid_placements']);
  }

  /**
   * Tests a missing placement keyword errors.
   */
  public function testMissingPlacementKeyword(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->addElement($entity, [
      'anchor' => 'faf95c53-35e7-74e1-184e-041152031df4',
    ], [
      'block_type' => 'text_section',
      'block_fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests the anchor grammar through a batch add_element operation.
   */
  public function testAnchorViaBatchAddElement(): void {
    $entity = $this->createLbEntityWithTwoComponents();

    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'add_element',
        'block_type' => 'text_section',
        'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
        'location' => ['anchor' => 'faf95c53-35e7-74e1-184e-041152031df4', 'placement' => 'after'],
        'properties' => ['field_heading' => 'Batched'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([
      'faf95c53-35e7-74e1-184e-041152031df4',
      '7900c460-e311-ca39-03f4-74e02e35140a',
      '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
    ], $this->regionOrder($entity, 'content'));
  }

  /**
   * Creates an entity with two paragraphs 'First' and 'Second'.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The reloaded entity.
   */
  protected function createParagraphEntity() {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity(['name' => 'Para 1', 'field_heading' => 'First']);
    $para2 = $this->createRevEntity(['name' => 'Para 2', 'field_heading' => 'Second']);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
      ],
    ]);
    return $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
  }

}
