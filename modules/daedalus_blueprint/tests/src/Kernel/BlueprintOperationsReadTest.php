<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations read methods.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsReadTest extends BlueprintKernelTestBase {

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
   * Tests getBlueprint delegates to serializer.
   */
  public function testGetBlueprint(): void {
    $entity = $this->createTestEntity([
      'name' => 'Test Page',
      'field_subtitle' => 'A subtitle',
    ]);

    $result = $this->operations->getBlueprint($this->host($entity));

    $this->assertEquals('entity_test', $result['entity_type']);
    $this->assertEquals('entity_test', $result['bundle']);
    $this->assertArrayHasKey('_root_entity', $result);
    $this->assertArrayHasKey('fields', $result);
    $this->assertEquals('Test Page', $result['fields']['name']);
    $this->assertEquals('A subtitle', $result['fields']['field_subtitle']);
  }

  /**
   * Tests getBlueprintSummary delegates to serializer.
   */
  public function testGetBlueprintSummary(): void {
    $entity = $this->createTestEntity([
      'name' => 'Test Page',
      'field_description' => [
        'value' => '<p>Long text</p>',
        'format' => 'plain_text',
      ],
    ]);

    $result = $this->operations->getBlueprintSummary($this->host($entity));

    $this->assertEquals('entity_test', $result['entity_type']);
    $this->assertArrayHasKey('fields', $result);
    // Summary should have name but not text_long field.
    $this->assertArrayHasKey('name', $result['fields']);
    $this->assertArrayNotHasKey('field_description', $result['fields']);
  }

  /**
   * Tests getComponent delegates to serializer.
   */
  public function testGetComponentReturnsNull(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->getElement($this->host($entity), 'c68c9561-d36d-b4fc-77ac-50039f354800');

    $this->assertNull($result);
  }

  /**
   * Tests getComponent on an LB inline block returns block_fields.
   */
  public function testGetComponentLbBlock(): void {
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
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'My Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->getElement($this->host($entity), 'faf95c53-35e7-74e1-184e-041152031df4');

    $this->assertNotNull($result);
    $this->assertEquals('faf95c53-35e7-74e1-184e-041152031df4', $result['uuid']);
    $this->assertEquals('text_section', $result['block_type']);
    $this->assertArrayHasKey('block_fields', $result);
    $this->assertEquals('Hello', $result['block_fields']['field_heading']);
  }

  /**
   * Tests createEntity delegates to deserializer.
   */
  public function testCreateEntity(): void {
    $blueprint = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Created via operations',
        'field_subtitle' => 'Sub',
      ],
    ];

    $result = $this->operations->createEntity($blueprint);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertEquals('Created via operations', $entity->label());
    $this->assertEquals('Sub', $entity->get('field_subtitle')->value);
    // Entity should be unsaved.
    $this->assertTrue($entity->isNew());
  }

  /**
   * Tests createEntity returns errors for invalid blueprint.
   */
  public function testCreateEntityWithErrors(): void {
    $blueprint = [
      'entity_type' => 'nonexistent_type',
      'bundle' => 'page',
      'fields' => [],
    ];

    $result = $this->operations->createEntity($blueprint);

    $this->assertFalse($result->isSuccess());
    $this->assertNotEmpty($result->getErrors());
  }

}
