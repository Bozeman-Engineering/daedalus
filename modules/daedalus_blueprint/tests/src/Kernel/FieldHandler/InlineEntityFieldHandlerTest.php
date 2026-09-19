<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\daedalus_blueprint\BlueprintSchemaBuilder;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler;
use Drupal\daedalus_blueprint\Validator\StructuralValidator;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the InlineEntityFieldHandler.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(InlineEntityFieldHandler::class)]
#[CoversClass(FieldHandlerManager::class)]
#[CoversClass(StructuralValidator::class)]
#[CoversClass(BlueprintSchemaBuilder::class)]
#[Group('daedalus_blueprint')]
class InlineEntityFieldHandlerTest extends BlueprintKernelTestBase {

  /**
   * The handler under test.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler
   */
  protected InlineEntityFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableEntityReferenceRevisions();

    // Create fields on entity_test_rev for inline entity content.
    $this->createRevField('field_heading', 'string', 'entity_test_rev', ['max_length' => 255]);
    $this->createRevField('field_body', 'text_long');

    // Create a multi-value entity_reference_revisions field on entity_test.
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
      ['entity_test_rev'],
    );

    // Create a single-value entity_reference_revisions field on entity_test.
    $this->createEntityReferenceRevisionsField(
      'field_featured_paragraph',
      'entity_test',
      'entity_test',
      'entity_test_rev',
      ['entity_test_rev'],
      1,
    );

    $this->handler = $this->container->get('daedalus_blueprint.field_handler.inline_entity');
  }

  /**
   * Tests that the handler is resolved for entity_reference_revisions fields.
   */
  public function testHandlerResolution(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->get('field_paragraphs')->getFieldDefinition();
    $handler = $this->fieldHandlerManager->getHandler($definition);
    $this->assertInstanceOf(InlineEntityFieldHandler::class, $handler);
  }

  /**
   * Tests serialization of a multi-value inline entity field.
   */
  public function testSerializeMultiValue(): void {
    $rev1 = $this->createRevEntity([
      'name' => 'Paragraph 1',
      'field_heading' => 'Hello',
      'field_body' => [
        'value' => '<p>World</p>',
        'format' => 'plain_text',
      ],
    ]);
    $rev2 = $this->createRevEntity([
      'name' => 'Paragraph 2',
      'field_heading' => 'Goodbye',
    ]);

    $entity = $this->createTestEntity([
      'field_paragraphs' => [
        ['entity' => $rev1],
        ['entity' => $rev2],
      ],
    ]);

    // Reload to get fresh field data.
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $definition = $entity->getFieldDefinition('field_paragraphs');
    $result = $this->handler->serialize($entity->get('field_paragraphs'), $definition);

    $this->assertIsArray($result);
    $this->assertCount(2, $result);

    // First item.
    $this->assertEquals((int) $rev1->id(), $result[0]['target_id']);
    $this->assertEquals($rev1->uuid(), $result[0]['uuid']);
    $this->assertEquals('entity_test_rev', $result[0]['type']);
    $this->assertArrayHasKey('fields', $result[0]);
    $this->assertEquals('Hello', $result[0]['fields']['field_heading']);
    $this->assertArrayHasKey('field_body', $result[0]['fields']);

    // Second item.
    $this->assertEquals((int) $rev2->id(), $result[1]['target_id']);
    $this->assertEquals('Goodbye', $result[1]['fields']['field_heading']);
  }

  /**
   * Tests serialization of a single-value inline entity field.
   */
  public function testSerializeSingleValue(): void {
    $rev = $this->createRevEntity([
      'name' => 'Featured',
      'field_heading' => 'Featured Section',
    ]);

    $entity = $this->createTestEntity([
      'field_featured_paragraph' => ['entity' => $rev],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $definition = $entity->getFieldDefinition('field_featured_paragraph');
    $result = $this->handler->serialize($entity->get('field_featured_paragraph'), $definition);

    // Single-value returns object, not array of objects.
    $this->assertIsArray($result);
    $this->assertArrayHasKey('target_id', $result);
    $this->assertEquals((int) $rev->id(), $result['target_id']);
    $this->assertEquals('entity_test_rev', $result['type']);
    $this->assertEquals('Featured Section', $result['fields']['field_heading']);
  }

  /**
   * Tests serialization of an empty field.
   */
  public function testSerializeEmpty(): void {
    $entity = $this->createTestEntity();

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $definition = $entity->getFieldDefinition('field_paragraphs');
    $result = $this->handler->serialize($entity->get('field_paragraphs'), $definition);

    $this->assertNull($result);
  }

  /**
   * Tests deserialization of new inline entities (create).
   */
  public function testDeserializeCreate(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'entity_test_rev',
        'fields' => [
          'field_heading' => 'New Paragraph',
          'field_body' => [
            'value' => '<p>Content</p>',
            'format' => 'plain_text',
          ],
        ],
      ],
      [
        'type' => 'entity_test_rev',
        'fields' => [
          'field_heading' => 'Second Paragraph',
        ],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $result_value = $result->getValue();
    $this->assertIsArray($result_value);
    $this->assertCount(2, $result_value);

    // First entity.
    $this->assertArrayHasKey('entity', $result_value[0]);
    $entity1 = $result_value[0]['entity'];
    $this->assertTrue($entity1->isNew());
    $this->assertEquals('entity_test_rev', $entity1->bundle());
    $this->assertEquals('New Paragraph', $entity1->get('field_heading')->value);
    $this->assertEquals('<p>Content</p>', $entity1->get('field_body')->value);

    // Second entity.
    $entity2 = $result_value[1]['entity'];
    $this->assertTrue($entity2->isNew());
    $this->assertEquals('Second Paragraph', $entity2->get('field_heading')->value);
  }

  /**
   * Tests deserialization of existing inline entities (update).
   */
  public function testDeserializeUpdate(): void {
    $rev = $this->createRevEntity([
      'name' => 'Existing',
      'field_heading' => 'Old Heading',
    ]);
    $rev_id = (int) $rev->id();

    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'target_id' => $rev_id,
        'fields' => [
          'field_heading' => 'Updated Heading',
        ],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $result_value = $result->getValue();
    $this->assertCount(1, $result_value);

    $entity = $result_value[0]['entity'];
    $this->assertFalse($entity->isNew());
    $this->assertEquals($rev_id, (int) $entity->id());
    $this->assertEquals('Updated Heading', $entity->get('field_heading')->value);
  }

  /**
   * Tests deserialization of a single-value field.
   */
  public function testDeserializeSingleValue(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_featured_paragraph');

    $value = [
      'type' => 'entity_test_rev',
      'fields' => [
        'field_heading' => 'Featured',
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $result_value = $result->getValue();
    $this->assertArrayHasKey('entity', $result_value);
    $this->assertEquals('Featured', $result_value['entity']->get('field_heading')->value);
  }

  /**
   * Tests deserialization of empty/null values.
   */
  public function testDeserializeEmpty(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $result = $this->handler->deserialize(NULL, $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertNull($result->getValue());

    $result = $this->handler->deserialize([], $definition);
    $this->assertFalse($result->hasErrors());

    $result = $this->handler->deserialize('', $definition);
    $this->assertFalse($result->hasErrors());
  }

  /**
   * Tests deserialization error for nonexistent target_id.
   */
  public function testDeserializeNonexistentTargetId(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'target_id' => 99999,
        'fields' => ['field_heading' => 'Test'],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('REFERENCE_NOT_FOUND', $errors[0]->getCode());
    $this->assertEquals('fields.field_paragraphs[0]', $errors[0]->getPath());
  }

  /**
   * Tests deserialization error for invalid bundle type.
   */
  public function testDeserializeInvalidBundle(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'nonexistent_bundle',
        'fields' => ['field_heading' => 'Test'],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('INVALID_INLINE_BUNDLE', $errors[0]->getCode());
    $this->assertEquals('fields.field_paragraphs[0].type', $errors[0]->getPath());
    $this->assertArrayHasKey('available_bundles', $errors[0]->getContext());
  }

  /**
   * Tests deserialization error for missing type on new entity.
   */
  public function testDeserializeMissingType(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'fields' => ['field_heading' => 'Test'],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('INVALID_FIELD_FORMAT', $errors[0]->getCode());
  }

  /**
   * Tests deserialization error for non-array item.
   */
  public function testDeserializeInvalidItemFormat(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      'just a string',
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertEquals('INVALID_FIELD_FORMAT', $errors[0]->getCode());
    $this->assertEquals('fields.field_paragraphs[0]', $errors[0]->getPath());
  }

  /**
   * Tests deserialization error for unknown nested field.
   */
  public function testDeserializeUnknownNestedField(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'entity_test_rev',
        'fields' => [
          'nonexistent_field' => 'Test',
        ],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertEquals('fields.field_paragraphs[0].fields.nonexistent_field', $errors[0]->getPath());
    $this->assertArrayHasKey('available', $errors[0]->getContext());
  }

  /**
   * Tests deserialization with mixed create and update items.
   */
  public function testDeserializeMixedCreateUpdate(): void {
    $existing = $this->createRevEntity([
      'name' => 'Existing',
      'field_heading' => 'Original',
    ]);
    $existing_id = (int) $existing->id();

    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'target_id' => $existing_id,
        'fields' => ['field_heading' => 'Updated'],
      ],
      [
        'type' => 'entity_test_rev',
        'fields' => ['field_heading' => 'Brand New'],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $result_value = $result->getValue();
    $this->assertCount(2, $result_value);

    // Updated entity.
    $this->assertEquals($existing_id, (int) $result_value[0]['entity']->id());
    $this->assertEquals('Updated', $result_value[0]['entity']->get('field_heading')->value);

    // New entity.
    $this->assertTrue($result_value[1]['entity']->isNew());
    $this->assertEquals('Brand New', $result_value[1]['entity']->get('field_heading')->value);
  }

  /**
   * Tests serialization round-trip.
   */
  public function testRoundTrip(): void {
    $rev = $this->createRevEntity([
      'name' => 'Round Trip',
      'field_heading' => 'Original Heading',
      'field_body' => [
        'value' => '<p>Original Body</p>',
        'format' => 'plain_text',
      ],
    ]);

    $entity = $this->createTestEntity([
      'field_paragraphs' => [['entity' => $rev]],
    ]);

    // Serialize.
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $definition = $entity->getFieldDefinition('field_paragraphs');
    $serialized = $this->handler->serialize($entity->get('field_paragraphs'), $definition);

    // Modify serialized data.
    $serialized[0]['fields']['field_heading'] = 'Modified Heading';

    // Deserialize.
    $result = $this->handler->deserialize($serialized, $definition);

    $this->assertFalse($result->hasErrors());
    $result_value = $result->getValue();
    $this->assertCount(1, $result_value);

    $updated_entity = $result_value[0]['entity'];
    $this->assertEquals('Modified Heading', $updated_entity->get('field_heading')->value);
    $this->assertEquals('<p>Original Body</p>', $updated_entity->get('field_body')->value);
  }

  /**
   * Tests that multiple errors across items are all collected.
   */
  public function testDeserializeMultipleErrors(): void {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'test'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'target_id' => 99999,
        'fields' => ['field_heading' => 'Test'],
      ],
      [
        'type' => 'nonexistent_bundle',
        'fields' => ['field_heading' => 'Test'],
      ],
    ];

    $result = $this->handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(2, $errors);
    $this->assertEquals('REFERENCE_NOT_FOUND', $errors[0]->getCode());
    $this->assertEquals('fields.field_paragraphs[0]', $errors[0]->getPath());
    $this->assertEquals('INVALID_INLINE_BUNDLE', $errors[1]->getCode());
    $this->assertEquals('fields.field_paragraphs[1].type', $errors[1]->getPath());
  }

  /**
   * Tests structural validation of inline entity fields.
   */
  public function testStructuralValidation(): void {
    $validator = $this->container->get('daedalus_blueprint.structural_validator');

    // Valid inline entity.
    $errors = $validator->validate([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_paragraphs' => [
          [
            'type' => 'entity_test_rev',
            'fields' => ['field_heading' => 'Test'],
          ],
        ],
      ],
    ]);
    $this->assertEmpty($errors, 'Valid inline entity should pass structural validation.');

    // Invalid bundle.
    $errors = $validator->validate([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_paragraphs' => [
          [
            'type' => 'fake_bundle',
            'fields' => ['field_heading' => 'Test'],
          ],
        ],
      ],
    ]);
    $this->assertNotEmpty($errors);
    $this->assertEquals('INVALID_INLINE_BUNDLE', $errors[0]->getCode());
    $this->assertEquals('fields.field_paragraphs[0].type', $errors[0]->getPath());
  }

  /**
   * Tests structural validation of unknown fields within inline entities.
   */
  public function testStructuralValidationUnknownNestedField(): void {
    $validator = $this->container->get('daedalus_blueprint.structural_validator');

    $errors = $validator->validate([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_paragraphs' => [
          [
            'type' => 'entity_test_rev',
            'fields' => ['nonexistent_field' => 'Test'],
          ],
        ],
      ],
    ]);

    $this->assertNotEmpty($errors);
    $this->assertEquals('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertStringContainsString('field_paragraphs[0].fields.nonexistent_field', $errors[0]->getPath());
  }

  /**
   * Tests structural validation accepts update items (target_id, no type).
   */
  public function testStructuralValidationUpdateItem(): void {
    $validator = $this->container->get('daedalus_blueprint.structural_validator');

    // Update item with target_id — should pass structural validation even
    // without type, since bundle is determined at Phase B.
    $errors = $validator->validate([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_paragraphs' => [
          [
            'target_id' => 1,
            'fields' => ['field_heading' => 'Updated'],
          ],
        ],
      ],
    ]);
    $this->assertEmpty($errors);
  }

  /**
   * Tests structural validation rejects items missing both target_id and type.
   */
  public function testStructuralValidationMissingTargetIdAndType(): void {
    $validator = $this->container->get('daedalus_blueprint.structural_validator');

    $errors = $validator->validate([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_paragraphs' => [
          [
            'fields' => ['field_heading' => 'Test'],
          ],
        ],
      ],
    ]);

    $this->assertNotEmpty($errors);
    $this->assertEquals('INVALID_FIELD_FORMAT', $errors[0]->getCode());
  }

  /**
   * Tests structural validation rejects unknown keys in inline entities.
   */
  public function testStructuralValidationUnknownKeys(): void {
    $validator = $this->container->get('daedalus_blueprint.structural_validator');

    $errors = $validator->validate([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'field_paragraphs' => [
          [
            'type' => 'entity_test_rev',
            'unknown_key' => 'value',
            'fields' => ['field_heading' => 'Test'],
          ],
        ],
      ],
    ]);

    $this->assertNotEmpty($errors);
    $this->assertEquals('INVALID_FIELD_FORMAT', $errors[0]->getCode());
    $this->assertContains('unknown_key', $errors[0]->getContext()['unknown']);
  }

  /**
   * Tests schema builder includes inline entity bundle schemas.
   */
  public function testSchemaBuilderInlineEntity(): void {
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $this->assertArrayHasKey('field_paragraphs', $schema['fields']);
    $field_schema = $schema['fields']['field_paragraphs'];

    $this->assertEquals('entity_reference_revisions', $field_schema['type']);
    $this->assertEquals('inline', $field_schema['strategy']);
    $this->assertEquals('entity_test_rev', $field_schema['target_type']);
    $this->assertEquals(-1, $field_schema['cardinality']);

    // Should include target bundle schemas.
    $this->assertArrayHasKey('target_bundles', $field_schema);
    $this->assertArrayHasKey('entity_test_rev', $field_schema['target_bundles']);

    $bundle_schema = $field_schema['target_bundles']['entity_test_rev'];
    $this->assertArrayHasKey('label', $bundle_schema);
    $this->assertArrayHasKey('fields', $bundle_schema);
    $this->assertArrayHasKey('field_heading', $bundle_schema['fields']);
    $this->assertArrayHasKey('field_body', $bundle_schema['fields']);
    $this->assertEquals('string', $bundle_schema['fields']['field_heading']['type']);
    $this->assertEquals('text_long', $bundle_schema['fields']['field_body']['type']);
  }

  /**
   * Tests schema builder for single-value inline entity field.
   */
  public function testSchemaBuilderSingleValueInlineEntity(): void {
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $this->assertArrayHasKey('field_featured_paragraph', $schema['fields']);
    $field_schema = $schema['fields']['field_featured_paragraph'];

    $this->assertEquals('entity_reference_revisions', $field_schema['type']);
    $this->assertEquals('inline', $field_schema['strategy']);
    // Single-value: no cardinality key.
    $this->assertArrayNotHasKey('cardinality', $field_schema);
  }

}
