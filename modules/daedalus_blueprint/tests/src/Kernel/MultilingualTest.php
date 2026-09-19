<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

// cspell:ignore actualizado Cuerpo modificado Noticias Subtítulo Título
use Drupal\daedalus_blueprint\BlueprintDeserializer;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests multilingual support in the serializer and deserializer.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSerializer::class)]
#[CoversClass(BlueprintDeserializer::class)]
#[Group('daedalus_blueprint')]
class MultilingualTest extends BlueprintKernelTestBase {

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
    'language',
    'content_translation',
  ];

  /**
   * The serializer.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializer
   */
  protected BlueprintSerializer $serializer;

  /**
   * The deserializer.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializer
   */
  protected BlueprintDeserializer $deserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['language']);

    // Add Spanish language.
    ConfigurableLanguage::createFromLangcode('es')->save();

    // Create fields on entity_test_mul.
    $this->createMulField('field_subtitle', 'string', ['max_length' => 255], TRUE);
    $this->createMulField('field_body', 'text_long', [], TRUE);
    $this->createMulField('field_category', 'string', ['max_length' => 255], FALSE);

    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
  }

  /**
   * Creates a field on entity_test_mul with optional translation support.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $field_type
   *   The field type.
   * @param array $settings
   *   Field storage settings.
   * @param bool $translatable
   *   Whether the field should be translatable.
   */
  protected function createMulField(string $field_name, string $field_type, array $settings = [], bool $translatable = TRUE): void {
    $storage_config = [
      'field_name' => $field_name,
      'entity_type' => 'entity_test_mul',
      'type' => $field_type,
    ];
    if (!empty($settings)) {
      $storage_config['settings'] = $settings;
    }
    FieldStorageConfig::create($storage_config)->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'label' => ucwords(str_replace('_', ' ', str_replace('field_', '', $field_name))),
      'translatable' => $translatable,
    ])->save();
  }

  /**
   * Creates an entity_test_mul entity.
   *
   * @param array $values
   *   Field values.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The created entity.
   */
  protected function createMulEntity(array $values = []): object {
    $storage = $this->entityTypeManager->getStorage('entity_test_mul');
    $entity = $storage->create($values + [
      'name' => 'Test mul entity',
      'langcode' => 'en',
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Tests serializing the default language includes all fields.
   */
  public function testSerializeDefaultLanguage(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_subtitle' => 'English subtitle',
      'field_body' => ['value' => '<p>English body</p>', 'format' => 'plain_text'],
      'field_category' => 'News',
    ]);

    $blueprint = $this->serializer->serialize($entity);

    $this->assertSame('en', $blueprint['langcode']);
    $this->assertSame('English title', $blueprint['fields']['name']);
    $this->assertSame('English subtitle', $blueprint['fields']['field_subtitle']);
    $this->assertSame('News', $blueprint['fields']['field_category']);
  }

  /**
   * Tests serializing a specific translation via langcode parameter.
   */
  public function testSerializeTranslation(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_subtitle' => 'English subtitle',
      'field_body' => ['value' => '<p>English body</p>', 'format' => 'plain_text'],
      'field_category' => 'News',
    ]);

    // Add Spanish translation.
    $entity->addTranslation('es', [
      'name' => 'Título en español',
      'field_subtitle' => 'Subtítulo en español',
      'field_body' => ['value' => '<p>Cuerpo en español</p>', 'format' => 'plain_text'],
    ]);
    $entity->save();

    $blueprint = $this->serializer->serialize($entity, 'es');

    // Should output the Spanish langcode.
    $this->assertSame('es', $blueprint['langcode']);

    // Translatable fields should have Spanish values.
    $this->assertSame('Título en español', $blueprint['fields']['name']);
    $this->assertSame('Subtítulo en español', $blueprint['fields']['field_subtitle']);

    // Non-translatable fields should NOT be included.
    $this->assertArrayNotHasKey('field_category', $blueprint['fields']);
  }

  /**
   * Tests serializing default language includes non-translatable fields.
   */
  public function testSerializeDefaultLanguageIncludesAllFields(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_category' => 'News',
    ]);

    // Serialize with explicit 'en' langcode (same as default).
    $blueprint = $this->serializer->serialize($entity, 'en');

    // Non-translatable fields should still be included for default language.
    $this->assertSame('News', $blueprint['fields']['field_category']);
  }

  /**
   * Tests serializing without langcode parameter uses entity's language.
   */
  public function testSerializeWithoutLangcodeUsesEntityLanguage(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_category' => 'News',
    ]);

    $blueprint = $this->serializer->serialize($entity);

    $this->assertSame('en', $blueprint['langcode']);
    $this->assertSame('English title', $blueprint['fields']['name']);
    $this->assertSame('News', $blueprint['fields']['field_category']);
  }

  /**
   * Tests _root_entity always references the base entity.
   */
  public function testSerializeTranslationRootEntityReferencesBase(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_subtitle' => 'English subtitle',
    ]);

    $entity->addTranslation('es', [
      'name' => 'Título en español',
      'field_subtitle' => 'Subtítulo en español',
    ]);
    $entity->save();

    $blueprint = $this->serializer->serialize($entity, 'es');

    // _root_entity should reference the same entity ID regardless of
    // translation.
    $this->assertSame((int) $entity->id(), $blueprint['_root_entity']['value']);
  }

  /**
   * Tests deserializing a blueprint with langcode creates a translation.
   */
  public function testDeserializeCreatesTranslation(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_subtitle' => 'English subtitle',
      'field_category' => 'News',
    ]);

    $data = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'langcode' => 'es',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'name' => 'Título en español',
        'field_subtitle' => 'Subtítulo en español',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue(
      $result->isSuccess(),
      'Deserialize with translation should succeed. Errors: '
        . print_r(array_map(fn($e) => $e->toArray(), $result->getErrors()), TRUE),
    );

    $result_entity = $result->getEntity();

    // The result entity should be the Spanish translation.
    $this->assertSame('es', $result_entity->language()->getId());
    $this->assertSame('Título en español', $result_entity->get('name')->value);
    $this->assertSame('Subtítulo en español', $result_entity->get('field_subtitle')->value);
  }

  /**
   * Tests deserializing updates an existing translation.
   */
  public function testDeserializeUpdatesExistingTranslation(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_subtitle' => 'English subtitle',
    ]);
    $entity->addTranslation('es', [
      'name' => 'Título original',
      'field_subtitle' => 'Subtítulo original',
    ]);
    $entity->save();

    $data = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'langcode' => 'es',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'name' => 'Título actualizado',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());

    $result_entity = $result->getEntity();
    $this->assertSame('es', $result_entity->language()->getId());
    $this->assertSame('Título actualizado', $result_entity->get('name')->value);
    // Non-submitted field retains original translation value.
    $this->assertSame('Subtítulo original', $result_entity->get('field_subtitle')->value);
  }

  /**
   * Tests non-translatable fields are skipped for non-default translations.
   */
  public function testDeserializeTranslationSkipsNonTranslatableFields(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_category' => 'News',
    ]);

    $data = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'langcode' => 'es',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'name' => 'Título en español',
        'field_category' => 'Noticias',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue(
      $result->isSuccess(),
      'Should succeed even when non-translatable fields are submitted. Errors: '
        . print_r(array_map(fn($e) => $e->toArray(), $result->getErrors()), TRUE),
    );

    $result_entity = $result->getEntity();
    $this->assertSame('Título en español', $result_entity->get('name')->value);

    // Non-translatable field should NOT have been changed on the translation.
    // Check the default language entity — field_category should still be
    // 'News'.
    $base_entity = $result_entity->getUntranslated();
    $this->assertSame('News', $base_entity->get('field_category')->value);
  }

  /**
   * Tests deserialization with default langcode works normally.
   */
  public function testDeserializeWithDefaultLangcodeWorksNormally(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_category' => 'News',
    ]);

    $data = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'langcode' => 'en',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'name' => 'Updated English title',
        'field_category' => 'Sports',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());

    $result_entity = $result->getEntity();
    $this->assertSame('en', $result_entity->language()->getId());
    $this->assertSame('Updated English title', $result_entity->get('name')->value);
    // Non-translatable fields should be applied for default language.
    $this->assertSame('Sports', $result_entity->get('field_category')->value);
  }

  /**
   * Tests round-trip: serialize translation → deserialize back.
   */
  public function testRoundTripTranslation(): void {
    $entity = $this->createMulEntity([
      'name' => 'English title',
      'field_subtitle' => 'English subtitle',
      'field_body' => ['value' => '<p>English body</p>', 'format' => 'plain_text'],
      'field_category' => 'News',
    ]);
    $entity->addTranslation('es', [
      'name' => 'Título en español',
      'field_subtitle' => 'Subtítulo en español',
      'field_body' => ['value' => '<p>Cuerpo en español</p>', 'format' => 'plain_text'],
    ]);
    $entity->save();

    // Serialize the Spanish translation.
    $blueprint = $this->serializer->serialize($entity, 'es');

    $this->assertSame('es', $blueprint['langcode']);
    $this->assertSame('Título en español', $blueprint['fields']['name']);
    $this->assertArrayNotHasKey('field_category', $blueprint['fields']);

    // Modify a field.
    $blueprint['fields']['name'] = 'Título modificado';

    // Deserialize back.
    $result = $this->deserializer->deserialize($blueprint);
    $this->assertTrue(
      $result->isSuccess(),
      'Round-trip translation deserialization should succeed. Errors: '
        . print_r(array_map(fn($e) => $e->toArray(), $result->getErrors()), TRUE),
    );

    $result_entity = $result->getEntity();
    $this->assertSame('es', $result_entity->language()->getId());
    $this->assertSame('Título modificado', $result_entity->get('name')->value);
    $this->assertSame('Subtítulo en español', $result_entity->get('field_subtitle')->value);
  }

  /**
   * Tests deserialization without langcode creates entity in default language.
   */
  public function testDeserializeWithoutLangcodeUsesDefault(): void {
    $data = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'fields' => [
        'name' => 'New entity',
        'field_subtitle' => 'A subtitle',
        'field_category' => 'News',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $this->assertTrue($result->isSuccess());

    $entity = $result->getEntity();
    $this->assertSame('New entity', $entity->get('name')->value);
    $this->assertSame('A subtitle', $entity->get('field_subtitle')->value);
    $this->assertSame('News', $entity->get('field_category')->value);
  }

}
