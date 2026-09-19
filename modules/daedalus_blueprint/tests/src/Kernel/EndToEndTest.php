<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

// cspell:ignore categry Newz
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\BlueprintSerializerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * End-to-end tests simulating AI-style multi-round conversations.
 *
 * Each test models a realistic AI interaction: submit blueprint JSON, get
 * feedback (errors or warnings), fix issues, and resubmit until success.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class EndToEndTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
    'taxonomy',
  ];

  /**
   * The serializer.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializerInterface
   */
  protected BlueprintSerializerInterface $serializer;

  /**
   * The deserializer.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializerInterface
   */
  protected BlueprintDeserializerInterface $deserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');

    // Create vocabulary.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    // Multi-value entity reference field.
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Tags',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['tags' => 'tags'],
        ],
      ],
    ])->save();

    // Single-value entity reference field.
    FieldStorageConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Category',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['tags' => 'tags'],
        ],
      ],
    ])->save();

    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
  }

  /**
   * Creates a taxonomy term.
   *
   * @param string $name
   *   The term name.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   The created term.
   */
  protected function createTerm(string $name): Term {
    $term = Term::create(['vid' => 'tags', 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Tests multi-round: structural errors, fix, semantic errors, fix, success.
   *
   * Round 1: Unknown field name → structural failure.
   * Round 2: Fix field name, reference nonexistent entity → semantic failure.
   * Round 3: Fix reference → success.
   */
  public function testStructuralThenSemanticThenSuccess(): void {
    $this->createTerm('Technology');

    // Round 1: Submit with misspelled field name.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test Article',
        'field_categry' => ['_label' => 'Technology'],
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertSame('fields.field_categry', $errors[0]->getPath());
    // Context includes available fields for AI to self-correct.
    $this->assertNotEmpty($errors[0]->getContext()['available']);

    // Round 2: Fix field name, but reference a nonexistent entity.
    $data['fields'] = [
      'name' => 'Test Article',
      'field_category' => ['target_id' => 99999],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());

    $errors = $result->getErrors();
    $has_ref_error = FALSE;
    foreach ($errors as $error) {
      if ($error->getCode() === 'REFERENCE_NOT_FOUND') {
        $has_ref_error = TRUE;
      }
    }
    $this->assertTrue($has_ref_error);

    // Round 3: Fix reference with correct _label.
    $data['fields'] = [
      'name' => 'Test Article',
      'field_category' => ['_label' => 'Technology'],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('hydrated', $result->getPhase());
    $this->assertNotNull($result->getEntity());

    // Verify entity state.
    $entity = $result->getEntity();
    $this->assertSame('Test Article', $entity->label());
  }

  /**
   * Tests create → serialize → update round-trip.
   *
   * Round 1: Create entity with fields.
   * Round 2: Serialize, modify fields, deserialize back.
   */
  public function testCreateThenSerializeAndUpdate(): void {
    // Referenced terms must exist (references resolve by label, never create).
    $this->createTerm('Alpha');
    $this->createTerm('Beta');
    $this->createTerm('Gamma');

    // Round 1: Create entity.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Original Title',
        'field_subtitle' => 'Original subtitle',
        'field_tags' => [
          ['_label' => 'Alpha'],
          ['_label' => 'Beta'],
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $entity->save();

    // Serialize the saved entity.
    $serialized = $this->serializer->serialize($entity);

    $this->assertSame('entity_test', $serialized['entity_type']);
    $this->assertSame('entity_test', $serialized['bundle']);
    $this->assertArrayHasKey('_root_entity', $serialized);
    $this->assertSame('Original Title', $serialized['fields']['name']);
    $this->assertSame('Original subtitle', $serialized['fields']['field_subtitle']);
    $this->assertCount(2, $serialized['fields']['field_tags']);
    $this->assertSame('Alpha', $serialized['fields']['field_tags'][0]['_label']);
    $this->assertSame('Beta', $serialized['fields']['field_tags'][1]['_label']);

    // Round 2: Modify and deserialize back (update).
    $serialized['fields']['name'] = 'Updated Title';
    $serialized['fields']['field_subtitle'] = 'Updated subtitle';
    $serialized['fields']['field_tags'][] = ['_label' => 'Gamma'];

    $update_result = $this->deserializer->deserialize($serialized);
    $this->assertTrue($update_result->isSuccess());

    $updated_entity = $update_result->getEntity();
    $this->assertSame('Updated Title', $updated_entity->label());
    $this->assertSame('Updated subtitle', $updated_entity->get('field_subtitle')->value);
    $this->assertCount(3, $updated_entity->get('field_tags'));
  }

  /**
   * Tests warning round-trip: stale label → get warning → fix → clean.
   *
   * Round 1: Submit with stale _label alongside target_id → success with
   *   LABEL_MISMATCH warning.
   * Round 2: Fix _label to match actual → success with no warnings.
   */
  public function testWarningRoundTrip(): void {
    $term = $this->createTerm('News');

    // Round 1: Submit with stale _label.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
        'field_category' => [
          'target_id' => (int) $term->id(),
          '_label' => 'Newz',
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());

    // Warning present.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());

    // Save and serialize to get the correct label.
    $entity = $result->getEntity();
    $entity->save();
    $serialized = $this->serializer->serialize($entity);

    // AI sees the correct _label in serialized output.
    $this->assertSame('News', $serialized['fields']['field_category']['_label']);

    // Round 2: Resubmit with corrected _label.
    $data['_root_entity'] = $serialized['_root_entity'];
    $data['fields']['field_category']['_label'] = 'News';

    $result2 = $this->deserializer->deserialize($data);
    $this->assertTrue($result2->isSuccess());
    $this->assertEmpty($result2->getWarnings());
  }

  /**
   * Tests validate-only mode catches structural errors cheaply.
   *
   * Round 1: validate_only with bad fields → structural failure.
   * Round 2: validate_only with good fields → validated (no entity loaded).
   * Round 3: Full submit → success with entity.
   */
  public function testValidateOnlyThenFullSubmit(): void {
    // Round 1: Validate-only with unknown field.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
        'field_nonexistent' => 'value',
      ],
    ];

    $result = $this->deserializer->deserialize($data, ['validate_only' => TRUE]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());

    // Round 2: Fix and validate-only again.
    $data['fields'] = [
      'name' => 'Test',
      'field_subtitle' => 'A subtitle',
    ];

    $result = $this->deserializer->deserialize($data, ['validate_only' => TRUE]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());

    // Round 3: Full submit.
    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('hydrated', $result->getPhase());
    $this->assertNotNull($result->getEntity());
    $this->assertSame('A subtitle', $result->getEntity()->get('field_subtitle')->value);
  }

  /**
   * Tests multiple errors collected in one response for AI to fix all at once.
   *
   * Submits JSON with multiple field issues — all errors returned in single
   * response so the AI can fix them in one pass.
   */
  public function testMultipleErrorsCollectedTogether(): void {
    // Submit with multiple structural errors.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
        'field_nonexistent1' => 'value',
        'field_nonexistent2' => 'value',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());

    // Both errors collected — AI can fix all at once.
    $errors = $result->getErrors();
    $this->assertCount(2, $errors);
    $paths = array_map(fn($e) => $e->getPath(), $errors);
    $this->assertContains('fields.field_nonexistent1', $paths);
    $this->assertContains('fields.field_nonexistent2', $paths);
  }

  /**
   * Tests full conversation: schema → create → update → verify.
   *
   * Models the typical AI workflow: inspect schema first, then use it
   * to construct valid blueprints.
   */
  public function testSchemaGuidedCreateAndUpdate(): void {
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $this->createTerm('Existing Tag');

    // Step 1: AI inspects the schema.
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');
    $this->assertArrayHasKey('fields', $schema);
    $this->assertArrayHasKey('name', $schema['fields']);
    $this->assertArrayHasKey('field_subtitle', $schema['fields']);
    $this->assertArrayHasKey('field_tags', $schema['fields']);

    // Step 2: AI creates entity using schema knowledge.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Schema-guided entity',
        'field_subtitle' => 'Created from schema',
        'field_tags' => [
          ['_label' => 'Existing Tag'],
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $entity->save();

    // Step 3: AI serializes to see what was created.
    $serialized = $this->serializer->serialize($entity);
    $this->assertSame('Schema-guided entity', $serialized['fields']['name']);
    $this->assertSame('Created from schema', $serialized['fields']['field_subtitle']);

    // Step 4: AI updates with minor change.
    $serialized['fields']['field_subtitle'] = 'Updated from schema';

    $update_result = $this->deserializer->deserialize($serialized);
    $this->assertTrue($update_result->isSuccess());
    $this->assertSame('Updated from schema', $update_result->getEntity()->get('field_subtitle')->value);
  }

  /**
   * Tests a stale _label on update warns while resolving by target_id.
   *
   * Round 1: reference an existing term by _label.
   * Round 2: serialize, change _label but keep target_id → LABEL_MISMATCH.
   */
  public function testStaleLabelOnUpdateWarns(): void {
    // Round 1: reference an existing term by _label.
    $this->createTerm('Brand New Tag');
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Stale label test',
        'field_tags' => [
          ['_label' => 'Brand New Tag'],
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $entity->save();

    // Round 2: Serialize, then update with stale _label.
    $serialized = $this->serializer->serialize($entity);
    $this->assertSame('Brand New Tag', $serialized['fields']['field_tags'][0]['_label']);

    // Simulate AI providing stale label.
    $serialized['fields']['field_tags'][0]['_label'] = 'Old Tag Name';

    $update_result = $this->deserializer->deserialize($serialized);
    $this->assertTrue($update_result->isSuccess());

    // Warning about stale label.
    $warnings = $update_result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
    $this->assertStringContainsString('Old Tag Name', $warnings[0]->getMessage());
    $this->assertStringContainsString('Brand New Tag', $warnings[0]->getMessage());
  }

  /**
   * Tests entity not found error for update with bad _root_entity.
   */
  public function testEntityNotFoundThenCreate(): void {
    // Round 1: Try to update nonexistent entity.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => 99999],
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $this->assertSame('ENTITY_NOT_FOUND', $result->getErrors()[0]->getCode());

    // Round 2: Remove _root_entity to create instead.
    unset($data['_root_entity']);

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());
    $this->assertNotNull($result->getEntity());
    $this->assertTrue($result->getEntity()->isNew());
  }

}
