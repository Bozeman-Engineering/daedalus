<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_config\Kernel;

use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint_config\ConfigSchemaBuilder;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests config entity schema generation and structural validation.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint_config')]
class ConfigSchemaAndValidationTest extends KernelTestBase {

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
   * The config schema builder.
   *
   * @var \Drupal\daedalus_blueprint_config\ConfigSchemaBuilder
   */
  protected ConfigSchemaBuilder $schemaBuilder;

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

    $this->schemaBuilder = $this->container->get('daedalus_blueprint_config.schema_builder');
    $this->resolver = $this->container->get('daedalus_blueprint.backend_resolver');
  }

  /**
   * Tests schema generation for image_style.
   */
  public function testImageStyleSchema(): void {
    $schema = $this->schemaBuilder->getSchema('image_style');

    $this->assertSame('image_style', $schema['entity_type']);
    $this->assertArrayHasKey('properties', $schema);
    $this->assertArrayHasKey('plugin_collections', $schema);
    $this->assertArrayHasKey('effects', $schema['plugin_collections']);
  }

  /**
   * Tests schema generation for filter_format.
   */
  public function testFilterFormatSchema(): void {
    $schema = $this->schemaBuilder->getSchema('filter_format');

    $this->assertSame('filter_format', $schema['entity_type']);
    $this->assertArrayHasKey('properties', $schema);
    $this->assertArrayHasKey('plugin_collections', $schema);
    $this->assertArrayHasKey('filters', $schema['plugin_collections']);
  }

  /**
   * Tests structural validation catches invalid entity type.
   */
  public function testInvalidEntityType(): void {
    $backend = $this->resolver->resolve('image_style');
    $result = $backend->validate([
      'entity_type' => 'nonexistent_type',
      'id' => 'foo',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
  }

  /**
   * Tests structural validation catches missing entity type.
   */
  public function testMissingEntityType(): void {
    $backend = $this->resolver->resolve('image_style');
    $result = $backend->validate([
      'id' => 'foo',
    ]);

    $this->assertFalse($result->isSuccess());
  }

  /**
   * Tests validate-only mode returns success without hydrating.
   */
  public function testValidateOnlyMode(): void {
    $backend = $this->resolver->resolve('image_style');
    $result = $backend->validate([
      'entity_type' => 'image_style',
      'id' => 'valid_style',
      'properties' => ['name' => 'valid_style', 'label' => 'Valid Style'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
  }

  /**
   * Tests filter format serialization and operations.
   */
  public function testFilterFormatOperations(): void {
    $formats = FilterFormat::loadMultiple();
    $this->assertNotEmpty($formats, 'Should have filter formats from config install.');

    $format = reset($formats);
    $backend = $this->resolver->resolve('filter_format');

    $serialized = $backend->serialize($format);
    $this->assertSame('filter_format', $serialized['entity_type']);
    $this->assertNotEmpty($serialized['id']);
  }

  /**
   * Tests that update via backend works for config entities.
   */
  public function testUpdateViaBackend(): void {
    $backend = $this->resolver->resolve('image_style');
    $entity = $backend->load('image_style', 'large');
    $this->assertNotNull($entity);

    $result = $backend->update($entity, ['label' => 'Updated Large']);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('Updated Large', $result->getEntity()->label());
  }

}
