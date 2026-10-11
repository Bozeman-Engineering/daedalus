<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_config\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\Dispatch\ContentBlueprintBackend;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;
use Drupal\daedalus_blueprint_config\ConfigOperationsInterface;
use Drupal\daedalus_blueprint_config\ConfigSerializer;
use Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests config entity blueprint operations against real entity types.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint_config')]
class ConfigEntityOperationsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'image',
    'filter',
    'daedalus_blueprint',
    'daedalus_blueprint_config',
  ];

  /**
   * The config operations service.
   *
   * @var \Drupal\daedalus_blueprint_config\ConfigOperationsInterface
   */
  protected ConfigOperationsInterface $operations;

  /**
   * The config serializer.
   *
   * @var \Drupal\daedalus_blueprint_config\ConfigSerializer
   */
  protected ConfigSerializer $serializer;

  /**
   * The backend resolver.
   *
   * @var \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver
   */
  protected BlueprintBackendResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['image', 'filter', 'system']);

    $this->setUpCurrentUser([], ['administer image styles', 'administer filters']);

    $this->operations = $this->container->get('daedalus_blueprint_config.operations');
    $this->serializer = $this->container->get('daedalus_blueprint_config.serializer');
    $this->resolver = $this->container->get('daedalus_blueprint.backend_resolver');
  }

  /**
   * Tests backend resolver dispatches correctly for config entities.
   */
  public function testBackendResolverDispatch(): void {
    $backend = $this->resolver->resolve('image_style');
    $this->assertInstanceOf(ConfigBlueprintBackend::class, $backend);
    $this->assertTrue($backend->supports('image_style'));
    $this->assertTrue($backend->supports('filter_format'));
    $this->assertFalse($backend->supports('node'));
    $this->assertFalse($backend->requiresWorkspace());

    // The entity door: a config entity nothing hosts is this backend's.
    $style = ImageStyle::load('large');
    $this->assertTrue($backend->supportsEntity($style));
    $this->assertSame($backend, $this->resolver->resolveFor($style));
  }

  /**
   * The entity door declines what the host resolver hosts.
   *
   * A Layout Builder enabled display is a page document stored as config:
   * the config backend yields it to the page backend although the type
   * level door (resolve by type id) stays the config backend's, and a
   * display without Layout Builder is still config.
   */
  public function testEntityDoorYieldsTemplate(): void {
    $this->enableModules(['layout_discovery', 'layout_builder']);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'user',
      'bundle' => 'user',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    EntityViewMode::create(['id' => 'user.compact', 'targetEntityType' => 'user', 'label' => 'Compact'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'user',
      'bundle' => 'user',
      'mode' => 'compact',
      'status' => TRUE,
    ])->save();

    $resolver = $this->container->get('daedalus_blueprint.backend_resolver');
    $backend = $this->container->get('daedalus_blueprint_config.backend');
    $template = EntityViewDisplay::load('user.user.default');
    $this->assertFalse($backend->supportsEntity($template), 'The config backend declines a template.');
    $this->assertInstanceOf(ContentBlueprintBackend::class, $resolver->resolveFor($template));
    $plain = EntityViewDisplay::load('user.user.compact');
    $this->assertTrue($backend->supportsEntity($plain));
    $this->assertSame($backend, $resolver->resolveFor($plain));
    $this->assertSame($backend, $resolver->resolve('entity_view_display'));
  }

  /**
   * Tests serializing an image style entity.
   */
  public function testSerializeImageStyle(): void {
    $style = ImageStyle::load('large');
    $this->assertNotNull($style, 'Default large image style should exist from config install.');

    $blueprint = $this->serializer->serialize($style);

    $this->assertSame('image_style', $blueprint['entity_type']);
    $this->assertSame('large', $blueprint['id']);
    $this->assertArrayHasKey('label', $blueprint);
    $this->assertArrayHasKey('plugin_collections', $blueprint);
    $this->assertArrayHasKey('effects', $blueprint['plugin_collections']);
  }

  /**
   * Tests serialize summary for an image style.
   */
  public function testSerializeSummaryImageStyle(): void {
    $style = ImageStyle::load('large');
    $summary = $this->serializer->serializeSummary($style);

    $this->assertSame('image_style', $summary['entity_type']);
    $this->assertSame('large', $summary['id']);
    $this->assertContains('effects', $summary['plugin_collections']);
  }

  /**
   * Tests creating an image style from a blueprint.
   */
  public function testCreateImageStyle(): void {
    $blueprint = [
      'entity_type' => 'image_style',
      'id' => 'test_style',
      'properties' => [
        'name' => 'test_style',
        'label' => 'Test Style',
      ],
      'plugin_collections' => [
        'effects' => [
          [
            'id' => 'image_scale',
            'configuration' => [
              'width' => 200,
              'height' => 200,
              'upscale' => FALSE,
            ],
          ],
        ],
      ],
    ];

    $result = $this->operations->createEntity($blueprint);
    $this->assertInstanceOf(ConfigBlueprintResult::class, $result);
    $this->assertTrue($result->isSuccess(), 'Create should succeed: ' . print_r($result->toArray(), TRUE));

    $entity = $result->getEntity();
    $this->assertSame('test_style', $entity->id());
    $this->assertSame('Test Style', $entity->label());

    // Verify plugin was added.
    $effects = $entity->getEffects();
    $this->assertCount(1, $effects);

    $effect = $effects->getIterator()->current();
    $this->assertSame('image_scale', $effect->getPluginId());
  }

  /**
   * Tests updating properties on a config entity.
   */
  public function testUpdateProperties(): void {
    $style = ImageStyle::load('large');

    $result = $this->operations->updateProperties($style, [
      'label' => 'Large (Modified)',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('Large (Modified)', $result->getEntity()->label());
  }

  /**
   * Tests adding a plugin to a collection.
   */
  public function testAddPlugin(): void {
    $style = ImageStyle::create([
      'name' => 'add_test',
      'label' => 'Add Test',
    ]);
    $style->save();

    $result = $this->operations->addPlugin($style, 'effects', [
      'id' => 'image_rotate',
      'configuration' => [
        'degrees' => 90,
        'random' => FALSE,
        'bgcolor' => '',
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $effects = $result->getEntity()->getEffects();
    $this->assertCount(1, $effects);

    $effect = $effects->getIterator()->current();
    $this->assertSame('image_rotate', $effect->getPluginId());
  }

  /**
   * Tests removing a plugin from a collection.
   */
  public function testRemovePlugin(): void {
    $style = ImageStyle::create([
      'name' => 'remove_test',
      'label' => 'Remove Test',
    ]);
    $style->addImageEffect([
      'id' => 'image_rotate',
      'data' => ['degrees' => 90, 'random' => FALSE, 'bgcolor' => ''],
    ]);
    $style->save();

    // Reload to get clean state.
    $style = ImageStyle::load('remove_test');
    $effects = $style->getEffects();
    $this->assertCount(1, $effects);

    $instance_id = $effects->getIterator()->key();
    $result = $this->operations->removePlugin($style, 'effects', $instance_id);

    $this->assertTrue($result->isSuccess());
    $this->assertFalse($result->getEntity()->getEffects()->has($instance_id));
  }

  /**
   * Tests error handling for unknown plugin collection.
   */
  public function testUnknownCollectionError(): void {
    $style = ImageStyle::load('large');
    $result = $this->operations->addPlugin($style, 'nonexistent', ['id' => 'foo']);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertSame('unknown_collection', $errors[0]->getCode());
  }

  /**
   * Tests error handling for removing non-existent plugin instance.
   */
  public function testRemoveNonExistentPlugin(): void {
    $style = ImageStyle::load('large');
    $result = $this->operations->removePlugin($style, 'effects', 'fake-uuid');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('plugin_not_found', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests batch operations on a config entity.
   */
  public function testBatchOperations(): void {
    $style = ImageStyle::create([
      'name' => 'batch_test',
      'label' => 'Batch Test',
    ]);
    $style->save();

    $result = $this->operations->batchOperations($style, [
      [
        'op' => 'update_properties',
        'properties' => ['label' => 'Batch Modified'],
      ],
      [
        'op' => 'add_plugin',
        'collection' => 'effects',
        'plugin' => [
          'id' => 'image_scale',
          'configuration' => ['width' => 100, 'height' => 100, 'upscale' => FALSE],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertSame('Batch Modified', $entity->label());
    $this->assertCount(1, $entity->getEffects());
  }

  /**
   * Tests batch operations with unknown operation returns error.
   */
  public function testBatchUnknownOperation(): void {
    $style = ImageStyle::load('large');
    $result = $this->operations->batchOperations($style, [
      ['op' => 'totally_fake'],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('unknown_operation', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests round-trip serialize/deserialize preserves data.
   */
  public function testRoundTrip(): void {
    $style = ImageStyle::load('large');
    $blueprint = $this->serializer->serialize($style);

    // Modify and re-create.
    $blueprint['id'] = 'round_trip_test';
    $blueprint['properties']['name'] = 'round_trip_test';
    $blueprint['properties']['label'] = 'Round Trip Test';

    $result = $this->operations->createEntity($blueprint);
    $this->assertTrue($result->isSuccess());

    $new_entity = $result->getEntity();
    $new_entity->save();

    // Serialize again.
    $blueprint2 = $this->serializer->serialize($new_entity);
    $this->assertSame('round_trip_test', $blueprint2['id']);
    $this->assertSame('Round Trip Test', $blueprint2['label']);
    $this->assertCount(
      count($blueprint['plugin_collections']['effects']),
      $blueprint2['plugin_collections']['effects'],
    );
  }

  /**
   * Tests the backend via the interface (polymorphic dispatch).
   */
  public function testBackendInterfaceOperations(): void {
    $backend = $this->resolver->resolve('image_style');
    $this->assertNotNull($backend);

    // Load via backend.
    $entity = $backend->load('image_style', 'large');
    $this->assertNotNull($entity);
    $this->assertSame('large', $entity->id());

    // Serialize via backend.
    $serialized = $backend->serialize($entity);
    $this->assertSame('image_style', $serialized['entity_type']);

    // Summary via backend.
    $summary = $backend->serializeSummary($entity);
    $this->assertArrayHasKey('plugin_collections', $summary);

    // Schema via backend.
    $schema = $backend->getSchema('image_style');
    $this->assertNotEmpty($schema);

    // Validate via backend.
    $validate_result = $backend->validate([
      'entity_type' => 'image_style',
      'id' => 'validate_test',
      'properties' => ['name' => 'validate_test', 'label' => 'Validate Test'],
    ]);
    $this->assertTrue($validate_result->isSuccess());
  }

  /**
   * Tests persist via backend saves config entity.
   */
  public function testBackendPersist(): void {
    $backend = $this->resolver->resolve('image_style');

    $result = $backend->create([
      'entity_type' => 'image_style',
      'id' => 'persist_test',
      'properties' => ['name' => 'persist_test', 'label' => 'Persist Test'],
    ]);

    $this->assertTrue($result->isSuccess());
    $backend->persist($result->getEntity(), TRUE);

    // Verify it was saved.
    $loaded = ImageStyle::load('persist_test');
    $this->assertNotNull($loaded);
    $this->assertSame('Persist Test', $loaded->label());
  }

  /**
   * Tests loading a non-existent entity returns NULL.
   */
  public function testLoadNonExistent(): void {
    $backend = $this->resolver->resolve('image_style');
    $entity = $backend->load('image_style', 'does_not_exist');
    $this->assertNull($entity);
  }

}
