<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the operation handler registry fall-through in batch operations.
 *
 * The daedalus_blueprint_test module registers a 'test_set_name' operation
 * handler; these tests exercise dispatch, batch rollback participation,
 * the merged valid-operations list, and guidance exposure.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
#[CoversClass(BlueprintOperations::class)]
class BlueprintOperationsRegistryTest extends BlueprintKernelTestBase {

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
    'daedalus_blueprint_test',
  ];

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
   * A registered operation dispatches through batchOperations().
   */
  public function testRegisteredOperationDispatches(): void {
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'test_set_name', 'properties' => ['name' => 'Renamed by handler']],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('Renamed by handler', $entity->get('name')->value);
    $this->assertSame(
      [['uuid' => $entity->uuid(), 'field_name' => 'name']],
      $result->getTargets(),
    );
  }

  /**
   * String-encoded JSON parameters are decoded before the handler runs.
   */
  public function testRegisteredOperationJsonNormalization(): void {
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'test_set_name', 'properties' => '{"name": "From JSON string"}'],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('From JSON string', $entity->get('name')->value);
  }

  /**
   * A semantic failure from a registered handler rolls the batch back.
   */
  public function testRegisteredOperationSemanticFailureRollsBack(): void {
    $entity = $this->createTestEntity(['name' => 'Original']);

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'test_set_name', 'properties' => ['name' => 'Changed first']],
      ['op' => 'test_set_name', 'properties' => ['name' => 'forbidden']],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $this->assertSame('FORBIDDEN_NAME', $result->getErrors()[0]->getCode());
    $this->assertSame('Original', $entity->get('name')->value);
  }

  /**
   * A structural failure from a registered handler reports normally.
   */
  public function testRegisteredOperationStructuralFailure(): void {
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'test_set_name', 'properties' => []],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertSame('MISSING_NAME', $result->getErrors()[0]->getCode());
  }

  /**
   * Unknown operations list built-in and registered names.
   */
  public function testInvalidOperationListsRegisteredOperations(): void {
    $entity = $this->createTestEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'no_such_op'],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_OPERATION', $error->getCode());
    $valid = $error->getContext()['valid_operations'];
    $this->assertContains('update_element', $valid);
    $this->assertContains('test_set_name', $valid);
  }

  /**
   * The registry exposes operation names and per-op guidance.
   */
  public function testRegistryGuidance(): void {
    $registry = $this->container->get('daedalus_blueprint.operation_handler_registry');
    $this->assertInstanceOf(OperationHandlerRegistry::class, $registry);

    $this->assertContains('test_set_name', $registry->getOperationNames());
    $guidance = $registry->getGuidance();
    $this->assertArrayHasKey('test_set_name', $guidance);
    $this->assertStringContainsString('sets the entity name', $guidance['test_set_name']);
  }

}
