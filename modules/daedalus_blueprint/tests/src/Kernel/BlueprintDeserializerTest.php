<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintDeserializer;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the BlueprintDeserializer service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintDeserializer::class)]
#[Group('daedalus_blueprint')]
class BlueprintDeserializerTest extends BlueprintKernelTestBase {

  /**
   * The deserializer under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializer
   */
  protected BlueprintDeserializer $deserializer;

  /**
   * The serializer for round-trip tests.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializer
   */
  protected BlueprintSerializer $serializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Tests creating a new entity from a blueprint.
   */
  public function testDeserializeCreatesNewEntity(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Created via blueprint',
        'field_subtitle' => 'A subtitle',
        'field_count' => 10,
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('hydrated', $result->getPhase());
    $this->assertEmpty($result->getErrors());

    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertSame('entity_test', $entity->getEntityTypeId());
    $this->assertSame('entity_test', $entity->bundle());
    $this->assertSame('Created via blueprint', $entity->get('name')->value);
    $this->assertSame('A subtitle', $entity->get('field_subtitle')->value);
    $this->assertSame(10, (int) $entity->get('field_count')->value);

    // Entity should NOT be saved yet.
    $this->assertNull($entity->id());
  }

  /**
   * Tests updating an existing entity via _root_entity.
   */
  public function testDeserializeUpdatesExistingEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original name',
      'field_subtitle' => 'Original subtitle',
      'field_email' => 'original@example.com',
    ]);
    $entity_id = (int) $entity->id();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => $entity_id],
      'fields' => [
        'name' => 'Updated name',
        'field_subtitle' => 'Updated subtitle',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $updated = $result->getEntity();

    // Submitted fields are updated.
    $this->assertSame('Updated name', $updated->get('name')->value);
    $this->assertSame('Updated subtitle', $updated->get('field_subtitle')->value);

    // Non-submitted fields retain their original values.
    $this->assertSame('original@example.com', $updated->get('field_email')->value);
  }

  /**
   * Tests round-trip: serialize → deserialize → compare field values.
   */
  public function testRoundTrip(): void {
    $entity = $this->createTestEntity([
      'name' => 'Round-trip entity',
      'field_subtitle' => 'A subtitle',
      'field_count' => 99,
      'field_email' => 'round@trip.com',
      'field_published' => TRUE,
      'field_tags_text' => ['Tag1', 'Tag2'],
      'field_description' => [
        'value' => '<p>Description</p>',
        'format' => 'plain_text',
      ],
      'field_body' => [
        'value' => '<p>Body content</p>',
        'format' => 'plain_text',
        'summary' => 'Summary',
      ],
    ]);

    // Serialize the entity.
    $blueprint = $this->serializer->serialize($entity);

    // Create a new entity from the serialized blueprint (remove _root_entity).
    unset($blueprint['_root_entity']);
    $result = $this->deserializer->deserialize($blueprint);

    $this->assertTrue($result->isSuccess(), 'Round-trip deserialization failed: ' . print_r($result->getErrors(), TRUE));

    $new_entity = $result->getEntity();

    // Compare field values.
    $this->assertSame('Round-trip entity', $new_entity->get('name')->value);
    $this->assertSame('A subtitle', $new_entity->get('field_subtitle')->value);
    $this->assertSame(99, (int) $new_entity->get('field_count')->value);
    $this->assertSame('round@trip.com', $new_entity->get('field_email')->value);
    $this->assertTrue((bool) $new_entity->get('field_published')->value);
    $this->assertSame('<p>Description</p>', $new_entity->get('field_description')->value);
    $this->assertSame('plain_text', $new_entity->get('field_description')->format);
    $this->assertSame('<p>Body content</p>', $new_entity->get('field_body')->value);
    $this->assertSame('plain_text', $new_entity->get('field_body')->format);
    $this->assertSame('Summary', $new_entity->get('field_body')->summary);

    // Multi-value field.
    $tags = [];
    foreach ($new_entity->get('field_tags_text') as $item) {
      $tags[] = $item->value;
    }
    $this->assertSame(['Tag1', 'Tag2'], $tags);
  }

  /**
   * Tests serialize → modify → deserialize update workflow.
   */
  public function testRoundTripUpdate(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Original subtitle',
      'field_count' => 5,
    ]);

    // Serialize.
    $blueprint = $this->serializer->serialize($entity);

    // Modify.
    $blueprint['fields']['name'] = 'Modified';
    $blueprint['fields']['field_count'] = 10;

    // Deserialize back (with _root_entity for update).
    $result = $this->deserializer->deserialize($blueprint);

    $this->assertTrue($result->isSuccess());
    $updated = $result->getEntity();
    $this->assertSame('Modified', $updated->get('name')->value);
    $this->assertSame(10, (int) $updated->get('field_count')->value);
    $this->assertSame('Original subtitle', $updated->get('field_subtitle')->value);
  }

  /**
   * Tests validate_only mode returns validated result without entity.
   */
  public function testValidateOnlyMode(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data, ['validate_only' => TRUE]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
    $this->assertEmpty($result->getErrors());
  }

  /**
   * Tests that structural errors return structural failure.
   */
  public function testStructuralErrorsReturnFailure(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'nonexistent_field' => 'value',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertNull($result->getEntity());

    $errors = $result->getErrors();
    $this->assertNotEmpty($errors);
    $this->assertSame('UNKNOWN_FIELD', $errors[0]->getCode());
  }

  /**
   * Tests that _root_entity with nonexistent entity returns error.
   */
  public function testEntityNotFound(): void {
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

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ENTITY_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame('_root_entity', $errors[0]->getPath());
  }

  /**
   * Tests that the deserialized entity is not saved.
   */
  public function testDeserializeReturnsUnsavedEntity(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Unsaved test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNull($entity->id());
    $this->assertTrue($entity->isNew());
  }

  /**
   * Tests that only submitted fields are changed on update.
   */
  public function testPartialUpdatePreservesUnsubmittedFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Keep this',
      'field_subtitle' => 'Keep this too',
      'field_count' => 42,
      'field_email' => 'keep@example.com',
    ]);
    $entity_id = (int) $entity->id();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => $entity_id],
      'fields' => [
        'field_count' => 100,
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $updated = $result->getEntity();

    // Only field_count changed.
    $this->assertSame(100, (int) $updated->get('field_count')->value);

    // Everything else preserved.
    $this->assertSame('Keep this', $updated->get('name')->value);
    $this->assertSame('Keep this too', $updated->get('field_subtitle')->value);
    $this->assertSame('keep@example.com', $updated->get('field_email')->value);
  }

  /**
   * Tests that empty fields map still creates a valid entity.
   */
  public function testDeserializeWithEmptyFields(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertTrue($entity->isNew());
  }

  /**
   * Tests deserialization with no fields key at all.
   */
  public function testDeserializeWithNoFieldsKey(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertTrue($entity->isNew());
  }

  /**
   * Tests a payload repeating one component uuid on two nodes is refused.
   */
  public function testLayoutRepeatedComponentUuidRefused(): void {
    $this->setUpLayoutPayloads();
    $uuid = '5a1c7de1-9d0c-4f7e-8f57-8f6c0e0a2ab1';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Repeated component uuid'],
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => ['content' => [$this->headingComponent($uuid, 'First')]],
        ],
        [
          'layout_id' => 'layout_onecol',
          'regions' => ['content' => [$this->headingComponent($uuid, 'Second')]],
        ],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors, 'Only the repeat errors; the first occurrence claims the uuid.');
    $this->assertSame('layout_builder__layout[1].regions.content[0].uuid', $errors[0]->getPath());
  }

  /**
   * Tests a payload repeating one section uuid on two nodes is refused.
   */
  public function testLayoutRepeatedSectionUuidRefused(): void {
    $this->setUpLayoutPayloads();
    $uuid = '0b9de2c4-6a4f-4c3b-9d8e-1f2a3b4c5d6e';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Repeated section uuid'],
      'layout_builder__layout' => [
        ['layout_id' => 'layout_onecol', 'uuid' => $uuid, 'regions' => []],
        ['layout_id' => 'layout_onecol', 'uuid' => $uuid, 'regions' => []],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors);
    $this->assertSame('layout_builder__layout[1].uuid', $errors[0]->getPath());
  }

  /**
   * Tests section and component uuids share one payload namespace.
   *
   * Containers bridge the two namespaces (component uuid == section uuid),
   * so a component claiming a uuid a section node already claimed is a
   * repeat.
   */
  public function testLayoutSectionUuidReusedByComponentRefused(): void {
    $this->setUpLayoutPayloads();
    $uuid = '7c2e4f61-3b5a-4d8c-9e0f-2a1b3c4d5e6f';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Cross-namespace repeat'],
      'layout_builder__layout' => [
        ['layout_id' => 'layout_onecol', 'uuid' => $uuid, 'regions' => []],
        [
          'layout_id' => 'layout_onecol',
          'regions' => ['content' => [$this->headingComponent($uuid, 'Bridge')]],
        ],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors);
    $this->assertSame('layout_builder__layout[1].regions.content[0].uuid', $errors[0]->getPath());
  }

  /**
   * Enables Layout Builder and a block type for layout payload tests.
   */
  protected function setUpLayoutPayloads(): void {
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    // enableModules() rebuilt the container; re-fetch the services.
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Builds an inline-block component node for layout payloads.
   *
   * @param string $uuid
   *   The component uuid.
   * @param string $heading
   *   The heading text (also the label).
   *
   * @return array
   *   The component node.
   */
  protected function headingComponent(string $uuid, string $heading): array {
    return [
      'uuid' => $uuid,
      'block_type' => 'text_section',
      'label' => $heading,
      'block_fields' => ['field_heading' => $heading],
    ];
  }

  /**
   * Tests that toArray on the result provides expected structure.
   */
  public function testResultToArray(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $array = $result->toArray();

    $this->assertTrue($array['success']);
    $this->assertSame('hydrated', $array['phase']);
    $this->assertSame('entity_test', $array['entity_type']);
    $this->assertSame('entity_test', $array['bundle']);
    $this->assertArrayNotHasKey('errors', $array);
  }

  /**
   * Tests error result toArray includes structured errors.
   */
  public function testErrorResultToArray(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => 99999],
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $array = $result->toArray();

    $this->assertFalse($array['success']);
    $this->assertSame('semantic', $array['phase']);
    $this->assertNotEmpty($array['errors']);
    $this->assertSame('ENTITY_NOT_FOUND', $array['errors'][0]['code']);
  }

}
