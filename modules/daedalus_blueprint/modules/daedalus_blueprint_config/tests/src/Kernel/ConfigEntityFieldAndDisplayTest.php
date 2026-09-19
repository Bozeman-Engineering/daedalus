<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_config\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;
use Drupal\daedalus_blueprint_config\ConfigOperationsInterface;
use Drupal\daedalus_blueprint_config\ConfigSerializer;
use Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests config entity operations for field config and display entities.
 *
 * Covers Phase 7 tier 3 (FieldStorageConfig, FieldConfig) and tier 4
 * (EntityFormDisplay, EntityViewDisplay).
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint_config')]
class ConfigEntityFieldAndDisplayTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'field_ui',
    'text',
    'filter',
    'daedalus_blueprint',
    'daedalus_blueprint_config',
  ];

  /**
   * The config operations service.
   */
  protected ConfigOperationsInterface $operations;

  /**
   * The config serializer.
   */
  protected ConfigSerializer $serializer;

  /**
   * The backend resolver.
   */
  protected BlueprintBackendResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'field', 'filter', 'node']);

    // Create a node type.
    $this->createContentType();

    $this->setUpCurrentUser(['uid' => 1], [], TRUE);

    // Config creation is fail-closed; these ungated config types (no admin
    // permission of their own) are reachable only when allow-listed. uid 1
    // satisfies the accompanying permission automatically.
    $this->config('daedalus_blueprint_config.settings')
      ->set('allowed_create_types', [
        'entity_view_display',
        'entity_form_display',
        'field_config',
        'field_storage_config',
      ])
      ->save();

    $this->operations = $this->container->get('daedalus_blueprint_config.operations');
    $this->serializer = $this->container->get('daedalus_blueprint_config.serializer');
    $this->resolver = $this->container->get('daedalus_blueprint.backend_resolver');
  }

  /**
   * Creates a content type with a text field for testing.
   */
  protected function createContentType(): void {
    $node_type = $this->container->get('entity_type.manager')
      ->getStorage('node_type')
      ->create([
        'type' => 'article',
        'name' => 'Article',
      ]);
    $node_type->save();

    // Create a field storage.
    FieldStorageConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();

    // Create a field instance.
    FieldConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
    ])->save();

    // Create form display.
    EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_body', [
      'type' => 'text_textarea',
      'weight' => 0,
    ])->save();

    // Create view display.
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_body', [
      'type' => 'text_default',
      'weight' => 0,
    ])->save();
  }

  /**
   * Tests backend resolver dispatches for field config entities.
   */
  public function testFieldConfigBackendDispatch(): void {
    $backend = $this->resolver->resolve('field_config');
    $this->assertInstanceOf(ConfigBlueprintBackend::class, $backend);
    $this->assertTrue($backend->supports('field_config'));
    $this->assertTrue($backend->supports('field_storage_config'));

    $backend2 = $this->resolver->resolve('field_storage_config');
    $this->assertInstanceOf(ConfigBlueprintBackend::class, $backend2);
  }

  /**
   * Tests serializing a FieldStorageConfig entity.
   */
  public function testSerializeFieldStorageConfig(): void {
    $field_storage = FieldStorageConfig::loadByName('node', 'field_body');
    $this->assertNotNull($field_storage);

    $blueprint = $this->serializer->serialize($field_storage);

    $this->assertSame('field_storage_config', $blueprint['entity_type']);
    $this->assertSame('node.field_body', $blueprint['id']);
    $this->assertArrayHasKey('properties', $blueprint);
    $this->assertSame('field_body', $blueprint['properties']['field_name']);
    $this->assertSame('node', $blueprint['properties']['entity_type']);
    $this->assertSame('text_long', $blueprint['properties']['type']);
    $this->assertSame(1, $blueprint['properties']['cardinality']);
  }

  /**
   * Tests serializing a FieldConfig entity.
   */
  public function testSerializeFieldConfig(): void {
    $field_config = FieldConfig::loadByName('node', 'article', 'field_body');
    $this->assertNotNull($field_config);

    $blueprint = $this->serializer->serialize($field_config);

    $this->assertSame('field_config', $blueprint['entity_type']);
    $this->assertSame('node.article.field_body', $blueprint['id']);
    $this->assertArrayHasKey('properties', $blueprint);
    $this->assertSame('field_body', $blueprint['properties']['field_name']);
    $this->assertSame('node', $blueprint['properties']['entity_type']);
    $this->assertSame('article', $blueprint['properties']['bundle']);
    $this->assertSame('Body', $blueprint['properties']['label']);
  }

  /**
   * Tests FieldStorageConfig summary serialization.
   */
  public function testFieldStorageSummary(): void {
    $field_storage = FieldStorageConfig::loadByName('node', 'field_body');
    $summary = $this->serializer->serializeSummary($field_storage);

    $this->assertSame('field_storage_config', $summary['entity_type']);
    $this->assertSame('node.field_body', $summary['id']);
    $this->assertArrayHasKey('properties', $summary);
    $this->assertContains('field_name', $summary['properties']);
    $this->assertContains('type', $summary['properties']);
  }

  /**
   * Tests creating a FieldStorageConfig via blueprint.
   */
  public function testCreateFieldStorageConfig(): void {
    $blueprint = [
      'entity_type' => 'field_storage_config',
      'id' => 'node.field_tags',
      'properties' => [
        'field_name' => 'field_tags',
        'entity_type' => 'node',
        'type' => 'string',
        'cardinality' => -1,
        'settings' => [
          'max_length' => 255,
          'is_ascii' => FALSE,
          'case_sensitive' => FALSE,
        ],
      ],
    ];

    $result = $this->operations->createEntity($blueprint);
    $this->assertInstanceOf(ConfigBlueprintResult::class, $result);
    $this->assertTrue($result->isSuccess(), 'Create should succeed: ' . print_r($result->toArray(), TRUE));

    $entity = $result->getEntity();
    $this->assertSame('node.field_tags', $entity->id());
    $this->assertSame('field_tags', $entity->get('field_name'));
    $this->assertSame('string', $entity->get('type'));
    $this->assertSame(-1, $entity->get('cardinality'));
  }

  /**
   * Tests creating a FieldConfig via blueprint.
   */
  public function testCreateFieldConfig(): void {
    // First create the field storage.
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
    ])->save();

    // Clear field definition caches so the new storage is discoverable.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    // Verify the storage is discoverable.
    $storage = FieldStorageConfig::loadByName('node', 'field_subtitle');
    $this->assertNotNull($storage, 'Field storage must be loadable before FieldConfig creation.');

    $blueprint = [
      'entity_type' => 'field_config',
      'id' => 'node.article.field_subtitle',
      'properties' => [
        'field_name' => 'field_subtitle',
        'entity_type' => 'node',
        'bundle' => 'article',
        'label' => 'Subtitle',
        'required' => TRUE,
        'field_type' => 'string',
      ],
    ];

    $result = $this->operations->createEntity($blueprint);
    $this->assertInstanceOf(ConfigBlueprintResult::class, $result);
    $this->assertTrue($result->isSuccess(), 'Create should succeed: ' . print_r($result->toArray(), TRUE));

    $entity = $result->getEntity();
    $this->assertSame('node.article.field_subtitle', $entity->id());
    $this->assertSame('Subtitle', $entity->label());
    $this->assertTrue($entity->isRequired());
  }

  /**
   * Tests updating FieldConfig properties.
   */
  public function testUpdateFieldConfig(): void {
    $field_config = FieldConfig::loadByName('node', 'article', 'field_body');

    $result = $this->operations->updateProperties($field_config, [
      'label' => 'Content Body',
      'description' => 'The main body content.',
      'required' => TRUE,
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertSame('Content Body', $entity->label());
    $this->assertSame('The main body content.', $entity->get('description'));
    $this->assertTrue($entity->isRequired());
  }

  /**
   * Tests updating FieldStorageConfig properties.
   */
  public function testUpdateFieldStorageConfig(): void {
    $field_storage = FieldStorageConfig::loadByName('node', 'field_body');

    $result = $this->operations->updateProperties($field_storage, [
      'cardinality' => -1,
      'translatable' => TRUE,
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertSame(-1, $entity->get('cardinality'));
    $this->assertTrue($entity->isTranslatable());
  }

  /**
   * Tests serializing an EntityFormDisplay.
   */
  public function testSerializeEntityFormDisplay(): void {
    $display = EntityFormDisplay::load('node.article.default');
    $this->assertNotNull($display);

    $blueprint = $this->serializer->serialize($display);

    $this->assertSame('entity_form_display', $blueprint['entity_type']);
    $this->assertSame('node.article.default', $blueprint['id']);
    $this->assertArrayHasKey('properties', $blueprint);
    $this->assertArrayHasKey('content', $blueprint['properties']);
    $this->assertArrayHasKey('field_body', $blueprint['properties']['content']);
    $this->assertSame('text_textarea', $blueprint['properties']['content']['field_body']['type']);
  }

  /**
   * Tests serializing an EntityViewDisplay.
   */
  public function testSerializeEntityViewDisplay(): void {
    $display = EntityViewDisplay::load('node.article.default');
    $this->assertNotNull($display);

    $blueprint = $this->serializer->serialize($display);

    $this->assertSame('entity_view_display', $blueprint['entity_type']);
    $this->assertSame('node.article.default', $blueprint['id']);
    $this->assertArrayHasKey('properties', $blueprint);
    $this->assertArrayHasKey('content', $blueprint['properties']);
    $this->assertArrayHasKey('field_body', $blueprint['properties']['content']);
    $this->assertSame('text_default', $blueprint['properties']['content']['field_body']['type']);
  }

  /**
   * Tests EntityFormDisplay summary.
   */
  public function testEntityFormDisplaySummary(): void {
    $display = EntityFormDisplay::load('node.article.default');
    $summary = $this->serializer->serializeSummary($display);

    $this->assertSame('entity_form_display', $summary['entity_type']);
    $this->assertSame('node.article.default', $summary['id']);
    $this->assertArrayHasKey('plugin_collections', $summary);
    $this->assertContains('widgets', $summary['plugin_collections']);
  }

  /**
   * Tests EntityViewDisplay summary.
   */
  public function testEntityViewDisplaySummary(): void {
    $display = EntityViewDisplay::load('node.article.default');
    $summary = $this->serializer->serializeSummary($display);

    $this->assertSame('entity_view_display', $summary['entity_type']);
    $this->assertSame('node.article.default', $summary['id']);
    $this->assertArrayHasKey('plugin_collections', $summary);
    $this->assertContains('formatters', $summary['plugin_collections']);
  }

  /**
   * Tests creating an EntityFormDisplay via blueprint.
   */
  public function testCreateEntityFormDisplay(): void {
    $blueprint = [
      'entity_type' => 'entity_form_display',
      'id' => 'node.article.teaser',
      'properties' => [
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'teaser',
        'status' => TRUE,
        'content' => [
          'field_body' => [
            'type' => 'text_textarea',
            'weight' => 0,
            'settings' => [
              'rows' => 5,
              'placeholder' => '',
            ],
            'third_party_settings' => [],
          ],
        ],
        'hidden' => [],
      ],
    ];

    $result = $this->operations->createEntity($blueprint);
    $this->assertInstanceOf(ConfigBlueprintResult::class, $result);
    $this->assertTrue($result->isSuccess(), 'Create should succeed: ' . print_r($result->toArray(), TRUE));

    $entity = $result->getEntity();
    $this->assertSame('node.article.teaser', $entity->id());
    $components = $entity->getComponents();
    $this->assertArrayHasKey('field_body', $components);
    $this->assertSame('text_textarea', $components['field_body']['type']);
  }

  /**
   * Tests creating an EntityViewDisplay via blueprint.
   */
  public function testCreateEntityViewDisplay(): void {
    $blueprint = [
      'entity_type' => 'entity_view_display',
      'id' => 'node.article.teaser',
      'properties' => [
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'teaser',
        'status' => TRUE,
        'content' => [
          'field_body' => [
            'type' => 'text_default',
            'weight' => 0,
            'label' => 'hidden',
            'settings' => [],
            'third_party_settings' => [],
          ],
        ],
        'hidden' => [],
      ],
    ];

    $result = $this->operations->createEntity($blueprint);
    $this->assertInstanceOf(ConfigBlueprintResult::class, $result);
    $this->assertTrue($result->isSuccess(), 'Create should succeed: ' . print_r($result->toArray(), TRUE));

    $entity = $result->getEntity();
    $this->assertSame('node.article.teaser', $entity->id());
    $components = $entity->getComponents();
    $this->assertArrayHasKey('field_body', $components);
    $this->assertSame('text_default', $components['field_body']['type']);
  }

  /**
   * Tests updating properties on an EntityFormDisplay.
   */
  public function testUpdateEntityFormDisplay(): void {
    $display = EntityFormDisplay::load('node.article.default');

    $result = $this->operations->updateProperties($display, [
      'content' => [
        'field_body' => [
          'type' => 'text_textarea',
          'weight' => 10,
          'settings' => [
            'rows' => 12,
            'placeholder' => 'Enter body text...',
          ],
          'third_party_settings' => [],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $components = $entity->getComponents();
    $this->assertSame(10, $components['field_body']['weight']);
    $this->assertSame(12, $components['field_body']['settings']['rows']);
  }

  /**
   * Tests updating properties on an EntityViewDisplay.
   */
  public function testUpdateEntityViewDisplay(): void {
    $display = EntityViewDisplay::load('node.article.default');

    $result = $this->operations->updateProperties($display, [
      'content' => [
        'field_body' => [
          'type' => 'text_default',
          'weight' => 5,
          'label' => 'above',
          'settings' => [],
          'third_party_settings' => [],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $components = $entity->getComponents();
    $this->assertSame(5, $components['field_body']['weight']);
    $this->assertSame('above', $components['field_body']['label']);
  }

  /**
   * Tests hiding a field in an EntityFormDisplay.
   */
  public function testHideFieldInFormDisplay(): void {
    $display = EntityFormDisplay::load('node.article.default');
    $components = $display->getComponents();
    $this->assertArrayHasKey('field_body', $components);

    $result = $this->operations->updateProperties($display, [
      'content' => [],
      'hidden' => ['field_body' => TRUE],
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertEmpty($entity->getComponent('field_body'));
  }

  /**
   * Tests round-trip for FieldStorageConfig.
   */
  public function testFieldStorageRoundTrip(): void {
    $field_storage = FieldStorageConfig::loadByName('node', 'field_body');
    $blueprint = $this->serializer->serialize($field_storage);

    // Create a new field storage from the blueprint.
    $blueprint['id'] = 'node.field_body_copy';
    $blueprint['properties']['field_name'] = 'field_body_copy';

    $result = $this->operations->createEntity($blueprint);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));

    $new_entity = $result->getEntity();
    $new_entity->save();

    $reloaded = FieldStorageConfig::loadByName('node', 'field_body_copy');
    $this->assertNotNull($reloaded);
    $this->assertSame('text_long', $reloaded->getType());
    $this->assertSame(1, $reloaded->getCardinality());
  }

  /**
   * Tests round-trip for FieldConfig.
   */
  public function testFieldConfigRoundTrip(): void {
    $field_config = FieldConfig::loadByName('node', 'article', 'field_body');
    $blueprint = $this->serializer->serialize($field_config);

    // Create a new field on a different bundle — need the storage first.
    $node_type = $this->container->get('entity_type.manager')
      ->getStorage('node_type')
      ->create([
        'type' => 'page',
        'name' => 'Page',
      ]);
    $node_type->save();

    // Clear caches so the existing field storage is discoverable for the new
    // bundle.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $blueprint['id'] = 'node.page.field_body';
    $blueprint['properties']['bundle'] = 'page';

    $result = $this->operations->createEntity($blueprint);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));

    $new_entity = $result->getEntity();
    $new_entity->save();

    $reloaded = FieldConfig::loadByName('node', 'page', 'field_body');
    $this->assertNotNull($reloaded);
    $this->assertSame('Body', $reloaded->label());
  }

  /**
   * Tests round-trip for EntityFormDisplay.
   */
  public function testEntityFormDisplayRoundTrip(): void {
    $display = EntityFormDisplay::load('node.article.default');
    $blueprint = $this->serializer->serialize($display);

    // Verify the serialized blueprint has the expected structure.
    $this->assertSame('entity_form_display', $blueprint['entity_type']);
    $this->assertSame('node.article.default', $blueprint['id']);
    $this->assertArrayHasKey('field_body', $blueprint['properties']['content']);

    // Modify the ID to create a new display for a different mode.
    $blueprint['id'] = 'node.article.compact';
    $blueprint['properties']['mode'] = 'compact';

    $result = $this->operations->createEntity($blueprint);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));

    $new_entity = $result->getEntity();
    $this->assertSame('node.article.compact', $new_entity->id());
    $components = $new_entity->getComponents();
    $this->assertArrayHasKey('field_body', $components);
    $this->assertSame('text_textarea', $components['field_body']['type']);
  }

  /**
   * Tests round-trip for EntityViewDisplay.
   */
  public function testEntityViewDisplayRoundTrip(): void {
    $display = EntityViewDisplay::load('node.article.default');
    $blueprint = $this->serializer->serialize($display);

    // Verify the serialized blueprint has the expected structure.
    $this->assertSame('entity_view_display', $blueprint['entity_type']);
    $this->assertSame('node.article.default', $blueprint['id']);
    $this->assertArrayHasKey('field_body', $blueprint['properties']['content']);

    $blueprint['id'] = 'node.article.compact';
    $blueprint['properties']['mode'] = 'compact';

    $result = $this->operations->createEntity($blueprint);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));

    $new_entity = $result->getEntity();
    $this->assertSame('node.article.compact', $new_entity->id());
    $components = $new_entity->getComponents();
    $this->assertArrayHasKey('field_body', $components);
    $this->assertSame('text_default', $components['field_body']['type']);
  }

  /**
   * Tests batch operations on FieldConfig.
   */
  public function testBatchOperationsOnFieldConfig(): void {
    $field_config = FieldConfig::loadByName('node', 'article', 'field_body');

    $result = $this->operations->batchOperations($field_config, [
      [
        'op' => 'update_properties',
        'properties' => ['label' => 'Article Body'],
      ],
      [
        'op' => 'update_properties',
        'properties' => ['required' => TRUE],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertSame('Article Body', $entity->label());
    $this->assertTrue($entity->isRequired());
  }

  /**
   * Tests schema generation for field_storage_config.
   */
  public function testFieldStorageConfigSchema(): void {
    $backend = $this->resolver->resolve('field_storage_config');
    $schema = $backend->getSchema('field_storage_config');

    $this->assertSame('field_storage_config', $schema['entity_type']);
    $this->assertArrayHasKey('properties', $schema);
  }

  /**
   * Tests schema generation for field_config.
   */
  public function testFieldConfigSchema(): void {
    $backend = $this->resolver->resolve('field_config');
    $schema = $backend->getSchema('field_config');

    $this->assertSame('field_config', $schema['entity_type']);
    $this->assertArrayHasKey('properties', $schema);
  }

  /**
   * Tests schema generation for entity_form_display.
   */
  public function testEntityFormDisplaySchema(): void {
    $backend = $this->resolver->resolve('entity_form_display');
    $schema = $backend->getSchema('entity_form_display');

    $this->assertSame('entity_form_display', $schema['entity_type']);
    $this->assertArrayHasKey('properties', $schema);
  }

  /**
   * Tests schema generation for entity_view_display.
   */
  public function testEntityViewDisplaySchema(): void {
    $backend = $this->resolver->resolve('entity_view_display');
    $schema = $backend->getSchema('entity_view_display');

    $this->assertSame('entity_view_display', $schema['entity_type']);
    $this->assertArrayHasKey('properties', $schema);
  }

  /**
   * Tests immutable property detection on FieldStorageConfig.
   */
  public function testImmutablePropertyOnFieldStorage(): void {
    $field_storage = FieldStorageConfig::loadByName('node', 'field_body');

    $result = $this->operations->updateProperties($field_storage, [
      'type' => 'string',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertSame('immutable_property', $errors[0]->getCode());
    $this->assertStringContainsString('type', $errors[0]->getMessage());
  }

  /**
   * Tests that persist works for field config entities.
   */
  public function testPersistFieldConfig(): void {
    $backend = $this->resolver->resolve('field_storage_config');

    // Create and persist a field storage.
    $result = $backend->create([
      'entity_type' => 'field_storage_config',
      'id' => 'node.field_persist_test',
      'properties' => [
        'field_name' => 'field_persist_test',
        'entity_type' => 'node',
        'type' => 'string',
        'cardinality' => 1,
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $backend->persist($result->getEntity(), TRUE);

    $loaded = FieldStorageConfig::loadByName('node', 'field_persist_test');
    $this->assertNotNull($loaded);
    $this->assertSame('string', $loaded->getType());
  }

  /**
   * Tests that loading entities works via backend.
   */
  public function testLoadViaBackend(): void {
    $backend = $this->resolver->resolve('field_config');

    $entity = $backend->load('field_config', 'node.article.field_body');
    $this->assertNotNull($entity);
    $this->assertSame('node.article.field_body', $entity->id());

    $null = $backend->load('field_config', 'node.article.nonexistent');
    $this->assertNull($null);
  }

  /**
   * Tests that validate-only mode works for display entities.
   */
  public function testValidateOnlyForDisplayEntity(): void {
    $backend = $this->resolver->resolve('entity_form_display');

    $result = $backend->validate([
      'entity_type' => 'entity_form_display',
      'id' => 'node.article.validate_test',
      'properties' => [
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'validate_test',
        'content' => [],
        'hidden' => [],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
  }

}
