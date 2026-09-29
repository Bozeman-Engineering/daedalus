<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations update methods.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsUpdateTest extends BlueprintKernelTestBase {

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
   * Tests updateElement on an LB inline block.
   */
  public function testUpdateElementLbInlineBlock(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Original Heading',
      'field_body' => ['value' => '<p>Original</p>', 'format' => 'plain_text'],
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'My Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_heading' => 'Updated Heading',
      'field_body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify block_content was updated by extracting from the component.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $updated_component = reset($components);
    $updated_block = $daedalus_layout_adapter->extractBlockContent($updated_component);

    $this->assertEquals('Updated Heading', $updated_block->get('field_heading')->value);
    $this->assertEquals('<p>Updated</p>', $updated_block->get('field_body')->value);
  }

  /**
   * Tests updateElement preserves sibling components.
   */
  public function testUpdateElementLbSiblingsUntouched(): void {
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
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Update only comp-1.
    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_heading' => 'Updated Block 1',
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify sibling is untouched.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $comp_map = [];
    foreach ($updated_section->getComponents() as $comp) {
      $comp_map[$comp->getUuid()] = $comp;
    }

    $block2_updated = $daedalus_layout_adapter->extractBlockContent($comp_map['9bc043d4-072e-d2ff-9909-5b1e2e3c9826']);
    $this->assertEquals('Block 2', $block2_updated->get('field_heading')->value);
  }

  /**
   * Tests updateElement on a nonexistent UUID.
   */
  public function testUpdateElementNotFound(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->updateElement($entity, 'c68c9561-d36d-b4fc-77ac-50039f354800', [
      'field_heading' => 'Test',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('ELEMENT_NOT_FOUND', $errors[0]->getCode());
  }

  /**
   * Tests updateElement with unknown block field.
   */
  public function testUpdateElementUnknownBlockField(): void {
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

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_nonexistent' => 'value',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('UNKNOWN_PROPERTY', $errors[0]->getCode());
    $this->assertEquals('properties.field_nonexistent', $errors[0]->getPath());
    $this->assertArrayHasKey('available', $errors[0]->getContext());
  }

  /**
   * Tests updateElement updates the label.
   */
  public function testUpdateElementUpdatesLabel(): void {
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
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Old Label'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'label' => 'New Label',
      'field_heading' => 'Hello',
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify label was updated in component configuration.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $updated_component = reset($components);
    $config = $updated_component->get('configuration');
    $this->assertEquals('New Label', $config['label']);
  }

  /**
   * The placement's display settings write on every inline block.
   *
   * The label_display and view_mode keys are core's own block plugin
   * settings, read out of every inline block component; update_element
   * takes them without a managed-keys declaration and targets them like
   * any property.
   */
  public function testUpdateElementPlacementConfiguration(): void {
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
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Label'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'label_display' => '0',
      'view_mode' => 'default',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame([
      ['uuid' => 'faf95c53-35e7-74e1-184e-041152031df4', 'field_name' => 'label_display'],
      ['uuid' => 'faf95c53-35e7-74e1-184e-041152031df4', 'field_name' => 'view_mode'],
    ], $result->getTargets());

    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $config = reset($components)->get('configuration');
    $this->assertSame('0', $config['label_display']);
    $this->assertSame('default', $config['view_mode']);
    $this->assertSame('Label', $config['label'], 'The label is untouched.');
  }

  /**
   * Tests updateElement on a paragraph entity.
   */
  public function testUpdateElementParagraph(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createRevField('field_text', 'text_long', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity([
      'name' => 'FAQ Section',
      'field_heading' => 'Original Heading',
      'field_text' => ['value' => '<p>Original text</p>', 'format' => 'plain_text'],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'My Page',
      'field_paragraphs' => [['entity' => $para]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $para_entity = $entity->get('field_paragraphs')->first()->entity;
    $para_uuid = $para_entity->uuid();

    $result = $this->operations->updateElement($entity, $para_uuid, [
      'field_heading' => 'Updated Heading',
      'field_text' => ['value' => '<p>Updated text</p>', 'format' => 'plain_text'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify paragraph was updated in memory.
    $updated_para = $entity->get('field_paragraphs')->first()->entity;
    $this->assertEquals('Updated Heading', $updated_para->get('field_heading')->value);
    $this->assertEquals('<p>Updated text</p>', $updated_para->get('field_text')->value);
  }

  /**
   * Tests updateElement on paragraph preserves siblings.
   */
  public function testUpdateElementParagraphSiblingsUntouched(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity([
      'name' => 'Para 1',
      'field_heading' => 'Heading 1',
    ]);
    $para2 = $this->createRevEntity([
      'name' => 'Para 2',
      'field_heading' => 'Heading 2',
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $para1_uuid = $entity->get('field_paragraphs')->get(0)->entity->uuid();

    // Update only para 1.
    $result = $this->operations->updateElement($entity, $para1_uuid, [
      'field_heading' => 'Updated 1',
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify sibling is untouched.
    $para2_entity = $entity->get('field_paragraphs')->get(1)->entity;
    $this->assertEquals('Heading 2', $para2_entity->get('field_heading')->value);
  }

  /**
   * Tests updateElement on nested paragraph.
   */
  public function testUpdateElementNestedParagraph(): void {
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
      'field_heading' => 'Child Heading',
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
    $parent_entity = $entity->get('field_paragraphs')->first()->entity;
    $child_entity = $parent_entity->get('field_items')->first()->entity;
    $child_uuid = $child_entity->uuid();

    $result = $this->operations->updateElement($entity, $child_uuid, [
      'field_heading' => 'Updated Child',
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify nested child was updated.
    $updated_child = $entity->get('field_paragraphs')->first()->entity
      ->get('field_items')->first()->entity;
    $this->assertEquals('Updated Child', $updated_child->get('field_heading')->value);
  }

  /**
   * Tests updateElement with unknown paragraph field.
   */
  public function testUpdateElementParagraphUnknownField(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity(['name' => 'Para', 'field_heading' => 'Hi']);
    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $para]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $para_uuid = $entity->get('field_paragraphs')->first()->entity->uuid();

    $result = $this->operations->updateElement($entity, $para_uuid, [
      'field_nonexistent' => 'value',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertEquals('properties.field_nonexistent', $errors[0]->getPath());
  }

  /**
   * Tests updateElement returns unsaved entity.
   */
  public function testUpdateElementReturnsUnsavedEntity(): void {
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

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_heading' => 'Updated',
    ]);

    $this->assertTrue($result->isSuccess());

    // Reload from storage — should still have original value.
    // Reset both entity caches: entity_test (for the root entity) and
    // block_content (since we modified the block object in memory).
    $this->entityTypeManager->getStorage('entity_test')->resetCache([$entity->id()]);
    $this->entityTypeManager->getStorage('block_content')->resetCache();
    $reloaded = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $reloaded_section = $reloaded->get('layout_builder__layout')->first()->section;
    $components = $reloaded_section->getComponents();
    $reloaded_component = reset($components);
    $reloaded_block = $daedalus_layout_adapter->extractBlockContent($reloaded_component);
    $this->assertEquals('Original', $reloaded_block->get('field_heading')->value);
  }

  /**
   * Tests updateFields sets top-level entity fields.
   */
  public function testUpdateFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Original Sub',
      'field_count' => 10,
    ]);

    $result = $this->operations->updateFields($entity, [
      'name' => 'Updated',
      'field_subtitle' => 'Updated Sub',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());
    $this->assertEquals('Updated', $entity->label());
    $this->assertEquals('Updated Sub', $entity->get('field_subtitle')->value);
    // Untouched field stays the same.
    $this->assertEquals(10, $entity->get('field_count')->value);
  }

  /**
   * Tests updateFields rejects unknown fields.
   */
  public function testUpdateFieldsUnknownField(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->updateFields($entity, [
      'field_nonexistent' => 'value',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertEquals('fields.field_nonexistent', $errors[0]->getPath());
  }

  /**
   * Tests updateFields rejects entity_reference_revisions fields.
   */
  public function testUpdateFieldsRejectsErrField(): void {
    $this->enableEntityReferenceRevisions();
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->updateFields($entity, [
      'field_paragraphs' => [['type' => 'entity_test_rev']],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('FIELD_TYPE_NOT_ALLOWED', $errors[0]->getCode());
    $this->assertStringContainsString('entity_reference_revisions', $errors[0]->getMessage());
  }

  /**
   * Tests updateFields rejects layout_section fields.
   */
  public function testUpdateFieldsRejectsLayoutField(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
    ]);
    $entity->save();

    $result = $this->operations->updateFields($entity, [
      'layout_builder__layout' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('FIELD_TYPE_NOT_ALLOWED', $errors[0]->getCode());
    $this->assertStringContainsString('layout_section', $errors[0]->getMessage());
  }

  /**
   * Tests updateFields returns unsaved entity.
   */
  public function testUpdateFieldsReturnsUnsavedEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Original Sub',
    ]);

    $result = $this->operations->updateFields($entity, [
      'field_subtitle' => 'Updated Sub',
    ]);

    $this->assertTrue($result->isSuccess());

    // Reload from storage — should still have original value.
    $reloaded = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $this->assertEquals('Original Sub', $reloaded->get('field_subtitle')->value);
  }

  /**
   * Tests updateElement on non-inline block returns error.
   */
  public function testUpdateElementNonInlineBlock(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'ed939717-cc05-fbd1-619e-ec86816e3045',
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_branding_block',
          'label' => 'Site branding',
          'provider' => 'system',
        ],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'ed939717-cc05-fbd1-619e-ec86816e3045', [
      'field_heading' => 'Test',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertEquals('NOT_INLINE_BLOCK', $errors[0]->getCode());
  }

  /**
   * Tests updateElement clears block_revision_id after modification.
   */
  public function testUpdateElementClearsBlockRevisionId(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Hello',
    ]);
    $block->save();

    // Use block_revision_id config (saved block).
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

    // Verify block_revision_id is set initially.
    $initial_section = $entity->get('layout_builder__layout')->first()->section;
    $initial_components = $initial_section->getComponents();
    $initial_comp = reset($initial_components);
    $initial_config = $initial_comp->get('configuration');
    $this->assertArrayHasKey('block_revision_id', $initial_config);

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_heading' => 'Updated',
    ]);

    $this->assertTrue($result->isSuccess());

    // After update, block_revision_id should be cleared and block_serialized
    // set.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $updated_components = $updated_section->getComponents();
    $updated_comp = reset($updated_components);
    $config = $updated_comp->get('configuration');
    $this->assertArrayNotHasKey('block_revision_id', $config);
    $this->assertArrayHasKey('block_serialized', $config);
  }

}
