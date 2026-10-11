<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

// cspell:ignore headinng
use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations::batchOperations().
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsBatchTest extends BlueprintKernelTestBase {

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
   * Tests batch with empty operations array returns success.
   */
  public function testBatchEmptyOperations(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->batchOperations($this->host($entity), []);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());
    $this->assertEquals('Test', $entity->get('name')->value);
  }

  /**
   * Tests batch with a single operation behaves like direct call.
   */
  public function testBatchSingleOperation(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Original',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'Updated'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $updated_block = $daedalus_layout_adapter->extractBlockContent(reset($components));
    $this->assertEquals('Updated', $updated_block->get('field_heading')->value);
  }

  /**
   * Tests batch with multiple update_element operations all succeeding.
   */
  public function testBatchAllSucceed(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Heading 1',
      'field_body' => ['value' => '<p>Body 1</p>', 'format' => 'plain_text'],
    ]);

    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Heading 2',
      'field_body' => ['value' => '<p>Body 2</p>', 'format' => 'plain_text'],
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

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'Updated Heading 1'],
      ],
      [
        'op' => 'update_element',
        'element' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
        'properties' => [
          'field_heading' => 'Updated Heading 2',
          'field_body' => ['value' => '<p>Updated Body 2</p>', 'format' => 'plain_text'],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify both blocks were updated.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();

    $block1_updated = $daedalus_layout_adapter->extractBlockContent($components['faf95c53-35e7-74e1-184e-041152031df4']);
    $this->assertEquals('Updated Heading 1', $block1_updated->get('field_heading')->value);

    $block2_updated = $daedalus_layout_adapter->extractBlockContent($components['9bc043d4-072e-d2ff-9909-5b1e2e3c9826']);
    $this->assertEquals('Updated Heading 2', $block2_updated->get('field_heading')->value);
    $this->assertEquals('<p>Updated Body 2</p>', $block2_updated->get('field_body')->value);
  }

  /**
   * Tests structural errors are collected across all operations.
   */
  public function testBatchStructuralErrorsCollected(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Original',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: typo in field name.
    // Operation 1: valid (succeeds).
    // Operation 2: non-existent component UUID.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_headinng' => 'Typo'],
      ],
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'Valid'],
      ],
      [
        'op' => 'update_element',
        'element' => 'c68c9561-d36d-b4fc-77ac-50039f354800',
        'properties' => ['field_heading' => 'Missing'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(2, $errors);

    // Error from operation 0.
    $this->assertEquals(0, $errors[0]->getOperationIndex());
    $this->assertEquals('UNKNOWN_PROPERTY', $errors[0]->getCode());
    $this->assertStringContainsString('field_headinng', $errors[0]->getMessage());

    // Error from operation 2.
    $this->assertEquals(2, $errors[1]->getOperationIndex());
    $this->assertEquals('ELEMENT_NOT_FOUND', $errors[1]->getCode());

    // Verify entity was not mutated (operation 1 succeeded but was rolled
    // back).
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $restored_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $restored_section->getComponents();
    $restored_block = $daedalus_layout_adapter->extractBlockContent(reset($components));
    $this->assertEquals('Original', $restored_block->get('field_heading')->value);
  }

  /**
   * Tests semantic failure rolls back all previously successful operations.
   */
  public function testBatchSemanticFailureRollback(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section', [
      'max_length' => 10,
    ]);
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Short',
      'field_body' => ['value' => '<p>Body 1</p>', 'format' => 'plain_text'],
    ]);

    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Also Short',
      'field_body' => ['value' => '<p>Body 2</p>', 'format' => 'plain_text'],
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
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: valid update (succeeds).
    // Operation 1: field_heading exceeds max_length (semantic failure).
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text']],
      ],
      [
        'op' => 'update_element',
        'element' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
        'properties' => ['field_heading' => 'This heading is way too long for the field'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('semantic', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertGreaterThanOrEqual(1, count($errors));
    $this->assertEquals(1, $errors[0]->getOperationIndex());
    $this->assertEquals('CONSTRAINT_VIOLATION', $errors[0]->getCode());

    // Verify operation 0's changes were rolled back.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $restored_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $restored_section->getComponents();
    $restored_block1 = $daedalus_layout_adapter->extractBlockContent($components['faf95c53-35e7-74e1-184e-041152031df4']);
    $this->assertEquals('<p>Body 1</p>', $restored_block1->get('field_body')->value);
  }

  /**
   * Tests inter-operation dependency with add + move.
   *
   * An add_element with a client-provided UUID followed by a move_element
   * referencing that UUID should both succeed.
   */
  public function testBatchInterOperationDependency(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $existing_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Existing',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $existing_block, 'Existing Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: add_element with client UUID, placed after the existing
    // block through the anchor grammar.
    // Operation 1: move_element referencing the new UUID, before the
    // existing block.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'location' => ['anchor' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b', 'placement' => 'after'],
        'uuid' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'block_type' => 'text_section',
        'properties' => ['field_heading' => 'New Heading', 'label' => 'New Block'],
      ],
      [
        'op' => 'move_element',
        'element' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'location' => ['anchor' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b', 'placement' => 'before'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify new component is first, existing is second.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponentsByRegion('content');
    $uuids = array_map(fn($c) => $c->getUuid(), $components);
    $this->assertEquals(['395e49a2-99ca-4616-714b-4c7026d49122', 'a0bd9075-abce-9a83-7b79-d4db72ff887b'], array_values($uuids));

    // Verify the new block's content.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $new_block = $daedalus_layout_adapter->extractBlockContent($components['395e49a2-99ca-4616-714b-4c7026d49122']);
    $this->assertEquals('New Heading', $new_block->get('field_heading')->value);
  }

  /**
   * Tests batch with mixed operation types.
   *
   * Combines update_element ops on blocks, a remove_element, and a root
   * update_element in one batch.
   */
  public function testBatchMixedOperationTypes(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 1 Heading',
    ]);

    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 2 Heading',
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
      'name' => 'Original Title',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->batchOperations($this->host($entity), [
      // Update block 1's heading.
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'Updated Block 1'],
      ],
      // Remove block 2.
      [
        'op' => 'remove_element',
        'element' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
      ],
      // Update the entity's title.
      [
        'op' => 'update_element',
        'properties' => ['name' => 'Updated Title'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify block 1 was updated.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $this->assertCount(1, $components);
    $this->assertArrayHasKey('faf95c53-35e7-74e1-184e-041152031df4', $components);

    $updated_block = $daedalus_layout_adapter->extractBlockContent($components['faf95c53-35e7-74e1-184e-041152031df4']);
    $this->assertEquals('Updated Block 1', $updated_block->get('field_heading')->value);

    // Verify block 2 was removed.
    $this->assertArrayNotHasKey('9bc043d4-072e-d2ff-9909-5b1e2e3c9826', $components);

    // Verify entity title was updated.
    $this->assertEquals('Updated Title', $entity->get('name')->value);
  }

  /**
   * Tests batch with an invalid operation type.
   */
  public function testBatchInvalidOperationType(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'delete_everything',
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals(0, $errors[0]->getOperationIndex());
    $this->assertEquals('INVALID_OPERATION', $errors[0]->getCode());
  }

  /**
   * Tests operation_index appears in error toArray output.
   */
  public function testBatchErrorOperationIndexInToArray(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'update_element', 'properties' => ['name' => 'Valid']],
      ['op' => 'update_element', 'properties' => ['nonexistent_field' => 'Bad']],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertEquals(1, $errors[0]->getOperationIndex());

    // Verify toArray includes operation_index.
    $error_array = $errors[0]->toArray();
    $this->assertArrayHasKey('operation_index', $error_array);
    $this->assertEquals(1, $error_array['operation_index']);
  }

  /**
   * Tests batch with update_element op (new unified API).
   */
  public function testBatchUpdateElement(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Sub',
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => $entity->uuid(),
        'properties' => [
          'name' => 'Updated',
          'field_subtitle' => 'New Sub',
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertEquals('Updated', $entity->label());
    $this->assertEquals('New Sub', $entity->get('field_subtitle')->value);
  }

  /**
   * Tests batch with update_element on an LB component.
   */
  public function testBatchUpdateElementLbComponent(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Original',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'Batch Updated'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $updated_block = $daedalus_layout_adapter->extractBlockContent(reset($components));
    $this->assertEquals('Batch Updated', $updated_block->get('field_heading')->value);
  }

  /**
   * Tests batch with remove_element op.
   */
  public function testBatchRemoveElement(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Hello',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '474ec695-fe40-d152-f511-3878a0d103ef',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'remove_element',
        'element' => '474ec695-fe40-d152-f511-3878a0d103ef',
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertCount(0, $updated_section->getComponents());
  }

  /**
   * Tests every semantic failure in the batch is reported in one round.
   *
   * Ops after the first semantic failure keep executing on the doomed
   * entity purely for diagnostics: independent failures each surface with
   * their own operation index, successes in between still roll back.
   */
  public function testBatchAllSemanticErrorsReported(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section', [
      'max_length' => 10,
    ]);
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Short',
      'field_body' => ['value' => '<p>Body 1</p>', 'format' => 'plain_text'],
    ]);

    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Also Short',
      'field_body' => ['value' => '<p>Body 2</p>', 'format' => 'plain_text'],
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
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: semantic failure (heading exceeds max_length).
    // Operation 1: valid update (succeeds on the doomed entity).
    // Operation 2: independent semantic failure on the other block.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'This heading is way too long for the field'],
      ],
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text']],
      ],
      [
        'op' => 'update_element',
        'element' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
        'properties' => ['field_heading' => 'Another heading that is far too long'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('semantic', $result->getPhase());

    $errors = $result->getErrors();
    $indexes = array_map(fn($error) => $error->getOperationIndex(), $errors);
    $this->assertContains(0, $indexes);
    $this->assertContains(2, $indexes);
    $this->assertNotContains(1, $indexes);
    foreach ($errors as $error) {
      $this->assertEquals('CONSTRAINT_VIOLATION', $error->getCode());
    }

    // Everything rolled back, including operation 1's success.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $restored_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $restored_section->getComponents();
    $restored_block1 = $daedalus_layout_adapter->extractBlockContent($components['faf95c53-35e7-74e1-184e-041152031df4']);
    $this->assertEquals('Short', $restored_block1->get('field_heading')->value);
    $this->assertEquals('<p>Body 1</p>', $restored_block1->get('field_body')->value);
    $restored_block2 = $daedalus_layout_adapter->extractBlockContent($components['9bc043d4-072e-d2ff-9909-5b1e2e3c9826']);
    $this->assertEquals('Also Short', $restored_block2->get('field_heading')->value);
  }

  /**
   * Tests a mixed-phase batch reports every operation's own errors.
   *
   * Ops are independent gestures: one op's structural failure says nothing
   * about another op's semantic findings (dependent-op shadows are the
   * taint suppression's job). Withholding semantic errors whenever any
   * structural error exists would let a downstream shadow mask the
   * upstream cause, so both ride the one round, and the batch's phase is
   * 'structural' as the earliest phase any op failed in.
   */
  public function testBatchMixedPhaseErrorsAllReported(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section', [
      'max_length' => 10,
    ]);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Short',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: structural failure (unknown property).
    // Operation 1: semantic failure (heading exceeds max_length).
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_headinng' => 'Typo'],
      ],
      [
        'op' => 'update_element',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'properties' => ['field_heading' => 'This heading is way too long for the field'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(2, $errors);
    $this->assertEquals(0, $errors[0]->getOperationIndex());
    $this->assertEquals('UNKNOWN_PROPERTY', $errors[0]->getCode());
    $this->assertEquals(1, $errors[1]->getOperationIndex());
    $this->assertEquals('CONSTRAINT_VIOLATION', $errors[1]->getCode());
  }

  /**
   * Tests cascade errors from a semantically failed mint are suppressed.
   *
   * An add_element that fails semantic validation never places its minted
   * uuid; a later operation referencing that uuid fails only as a shadow
   * of the earlier failure, so its errors are suppressed rather than
   * presented as real.
   */
  public function testBatchSemanticMintCascadeSuppressed(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section', [
      'max_length' => 10,
    ]);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Existing',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Existing Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: add_element minting a uuid, semantic failure (heading
    // exceeds max_length) so the component is never placed.
    // Operation 1: update_element referencing the never-minted uuid.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'location' => ['anchor' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b', 'placement' => 'after'],
        'uuid' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'block_type' => 'text_section',
        'properties' => ['field_heading' => 'This heading is way too long for the field', 'label' => 'New Block'],
      ],
      [
        'op' => 'update_element',
        'element' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'properties' => ['field_heading' => 'Short'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    // Operation 1's ELEMENT_NOT_FOUND is structural; were it presented as
    // real, structural priority would flip the phase. Suppression keeps the
    // one real (semantic) failure as the whole story.
    $this->assertEquals('semantic', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals(0, $errors[0]->getOperationIndex());
    $this->assertEquals('CONSTRAINT_VIOLATION', $errors[0]->getCode());
  }

  /**
   * Tests cascade errors from a structurally failed mint are suppressed.
   */
  public function testBatchStructuralMintCascadeSuppressed(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Existing',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Existing Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: add_element with an unknown block type (structural),
    // minting a uuid that never comes to exist.
    // Operation 1: update_element referencing that uuid.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'location' => ['anchor' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b', 'placement' => 'after'],
        'uuid' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'block_type' => 'no_such_type',
        'properties' => ['field_heading' => 'Hello'],
      ],
      [
        'op' => 'update_element',
        'element' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'properties' => ['field_heading' => 'Updated'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals(0, $errors[0]->getOperationIndex());
    $this->assertEquals('INVALID_BLOCK_TYPE', $errors[0]->getCode());
  }

  /**
   * Tests taint propagates through a suppressed operation's own mints.
   *
   * A suppressed dependent operation that would itself mint identity
   * taints that identity too, so cascades stay silent at any depth.
   */
  public function testBatchTransitiveCascadeSuppressed(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section', [
      'max_length' => 10,
    ]);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Existing',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Existing Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Operation 0: semantic failure, never mints its uuid.
    // Operation 1: duplicates the never-minted element, minting a second
    // uuid (fails, suppressed, taints its own mint).
    // Operation 2: references operation 1's mint (fails, suppressed).
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'location' => ['anchor' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b', 'placement' => 'after'],
        'uuid' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'block_type' => 'text_section',
        'properties' => ['field_heading' => 'This heading is way too long for the field', 'label' => 'New Block'],
      ],
      [
        'op' => 'duplicate_element',
        'element' => '395e49a2-99ca-4616-714b-4c7026d49122',
        'uuid' => '8d24b8a1-06a1-45cb-91e4-2a9ab2a08915',
      ],
      [
        'op' => 'update_element',
        'element' => '8d24b8a1-06a1-45cb-91e4-2a9ab2a08915',
        'properties' => ['field_heading' => 'Short'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('semantic', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals(0, $errors[0]->getOperationIndex());
    $this->assertEquals('CONSTRAINT_VIOLATION', $errors[0]->getCode());
  }

  /**
   * Tests batch update_element with null element falls back to entity UUID.
   */
  public function testBatchUpdateElementNullElement(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Sub',
    ]);

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'update_element',
        'properties' => [
          'field_subtitle' => 'Updated via batch no element',
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertEquals('Updated via batch no element', $entity->get('field_subtitle')->value);
  }

}
