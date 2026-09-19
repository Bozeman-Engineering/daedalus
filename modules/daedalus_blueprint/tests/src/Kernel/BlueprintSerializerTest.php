<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the BlueprintSerializer service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSerializer::class)]
#[Group('daedalus_blueprint')]
class BlueprintSerializerTest extends BlueprintKernelTestBase {

  /**
   * The serializer under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializer
   */
  protected BlueprintSerializer $serializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Tests serializing an entity with basic scalar fields.
   */
  public function testSerializeBasicEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Test page',
      'field_subtitle' => 'A subtitle',
      'field_count' => 42,
      'field_published' => TRUE,
      'field_email' => 'test@example.com',
    ]);

    $result = $this->serializer->serialize($entity);

    $this->assertSame('entity_test', $result['entity_type']);
    $this->assertSame('entity_test', $result['bundle']);
    $this->assertSame('en', $result['langcode']);

    // _root_entity present for saved entities.
    $this->assertSame('id', $result['_root_entity']['field']);
    $this->assertSame((int) $entity->id(), $result['_root_entity']['value']);

    // Fields.
    $fields = $result['fields'];
    $this->assertSame('Test page', $fields['name']);
    $this->assertSame('A subtitle', $fields['field_subtitle']);
    $this->assertSame(42, $fields['field_count']);
    $this->assertTrue((bool) $fields['field_published']);
    $this->assertSame('test@example.com', $fields['field_email']);
  }

  /**
   * Tests serializing text fields produces {value, format} objects.
   */
  public function testSerializeTextFields(): void {
    $entity = $this->createTestEntity([
      'field_description' => [
        'value' => '<p>Description text</p>',
        'format' => 'plain_text',
      ],
      'field_body' => [
        'value' => '<p>Body content</p>',
        'format' => 'plain_text',
        'summary' => 'Summary text',
      ],
    ]);

    $result = $this->serializer->serialize($entity);
    $fields = $result['fields'];

    // text_long: {value, format}.
    $this->assertSame('<p>Description text</p>', $fields['field_description']['value']);
    $this->assertSame('plain_text', $fields['field_description']['format']);

    // text_with_summary: {value, format, summary}.
    $this->assertSame('<p>Body content</p>', $fields['field_body']['value']);
    $this->assertSame('plain_text', $fields['field_body']['format']);
    $this->assertSame('Summary text', $fields['field_body']['summary']);
  }

  /**
   * Tests serializing a multi-value field produces an array.
   */
  public function testSerializeMultiValueField(): void {
    $entity = $this->createTestEntity([
      'field_tags_text' => ['Alpha', 'Beta', 'Gamma'],
    ]);

    $result = $this->serializer->serialize($entity);
    $tags = $result['fields']['field_tags_text'];

    $this->assertIsArray($tags);
    $this->assertCount(3, $tags);
    $this->assertSame(['Alpha', 'Beta', 'Gamma'], $tags);
  }

  /**
   * Tests serializing a new (unsaved) entity omits _root_entity.
   */
  public function testSerializeNewEntity(): void {
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $entity = $storage->create([
      'name' => 'Unsaved entity',
      'type' => 'entity_test',
    ]);

    $result = $this->serializer->serialize($entity);

    $this->assertSame('entity_test', $result['entity_type']);
    $this->assertSame('entity_test', $result['bundle']);
    $this->assertArrayNotHasKey('_root_entity', $result);
    $this->assertSame('Unsaved entity', $result['fields']['name']);
  }

  /**
   * Tests that empty fields carry identity-only stubs, never values.
   *
   * FULL mode is structure-complete: every processable definition has
   * a node, and a field without a value gets a form-only stub. Sparse
   * output is the summary transport's job (see
   * BlueprintSerializerSummaryTest and FormOnlyStubTest).
   */
  public function testSerializeStubsEmptyFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Minimal entity',
    ]);

    $result = $this->serializer->serialize($entity);
    $fields = $result['fields'];

    $this->assertSame('Minimal entity', $fields['name']);
    foreach (['field_subtitle', 'field_description', 'field_body', 'field_tags_text'] as $empty_field) {
      $this->assertTrue(
        FormOnlyStub::isStub($fields[$empty_field]),
        "$empty_field carries an identity-only stub.",
      );
    }
  }

  /**
   * Tests that entity key fields are excluded from the fields map.
   */
  public function testSerializeOmitsEntityKeys(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serialize($entity);
    $fields = $result['fields'];

    // Entity keys should NOT appear in fields.
    $this->assertArrayNotHasKey('id', $fields);
    $this->assertArrayNotHasKey('uuid', $fields);
    $this->assertArrayNotHasKey('type', $fields);
    $this->assertArrayNotHasKey('langcode', $fields);
    $this->assertArrayNotHasKey('default_langcode', $fields);

    // Label field SHOULD appear in fields.
    $this->assertArrayHasKey('name', $fields);
  }

  /**
   * Tests that langcode appears at the top level.
   */
  public function testSerializeIncludesLangcode(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serialize($entity);

    $this->assertArrayHasKey('langcode', $result);
    $this->assertSame('en', $result['langcode']);
  }

  /**
   * Tests that _root_entity value is an integer for numeric IDs.
   */
  public function testRootEntityValueIsInteger(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serialize($entity);

    $this->assertIsInt($result['_root_entity']['value']);
  }

}
