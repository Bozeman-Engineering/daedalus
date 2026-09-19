<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Result;

// cspell:ignore Newz
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the blueprint result value object and its error/warning companions.
 */
#[CoversClass(BlueprintResult::class)]
#[CoversClass(BlueprintError::class)]
#[CoversClass(BlueprintWarning::class)]
#[Group('daedalus_blueprint')]
class BlueprintResultTest extends UnitTestCase {

  /**
   * Tests a successful result with a hydrated entity.
   */
  public function testSuccess(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $result = BlueprintResult::success($entity);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('hydrated', $result->getPhase());
    $this->assertSame($entity, $result->getEntity());
    $this->assertEmpty($result->getErrors());
    $this->assertEmpty($result->getWarnings());
  }

  /**
   * Tests a successful result with warnings.
   */
  public function testSuccessWithWarnings(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $warning = new BlueprintWarning(
      'fields.field_tags[0]',
      'LABEL_MISMATCH',
      'Label does not match.',
    );
    $result = BlueprintResult::success($entity, [$warning]);

    $this->assertTrue($result->isSuccess());
    $this->assertCount(1, $result->getWarnings());
    $this->assertSame($warning, $result->getWarnings()[0]);
  }

  /**
   * Tests a validate-only result.
   */
  public function testValidated(): void {
    $result = BlueprintResult::validated();

    $this->assertTrue($result->isSuccess());
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
    $this->assertEmpty($result->getErrors());
  }

  /**
   * Tests a structural failure result.
   */
  public function testStructuralFailure(): void {
    $error = new BlueprintError(
      'fields.field_tags',
      'UNKNOWN_FIELD',
      'Field not found.',
      ['available_fields' => ['title', 'body']],
      'Check the field machine name.',
    );
    $result = BlueprintResult::structuralFailure([$error]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertNull($result->getEntity());
    $this->assertCount(1, $result->getErrors());
    $this->assertSame($error, $result->getErrors()[0]);
  }

  /**
   * Tests a semantic failure result.
   */
  public function testSemanticFailure(): void {
    $error = new BlueprintError(
      'fields.field_tags[1]',
      'REFERENCE_NOT_FOUND',
      "No taxonomy_term with label 'Newz' found.",
      ['similar' => ['News', 'New Releases']],
      'Check spelling.',
    );
    $warning = new BlueprintWarning(
      'fields.field_tags[0]',
      'LABEL_MISMATCH',
      'Label does not match.',
    );
    $result = BlueprintResult::semanticFailure([$error], [$warning]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $this->assertCount(1, $result->getErrors());
    $this->assertCount(1, $result->getWarnings());
  }

  /**
   * Tests toArray() for a successful result.
   */
  public function testToArraySuccess(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('id')->willReturn('42');

    $result = BlueprintResult::success($entity);
    $array = $result->toArray();

    $this->assertTrue($array['success']);
    $this->assertSame('hydrated', $array['phase']);
    $this->assertSame('node', $array['entity_type']);
    $this->assertSame('page', $array['bundle']);
    $this->assertSame('42', $array['entity_id']);
    $this->assertArrayNotHasKey('errors', $array);
    $this->assertArrayNotHasKey('warnings', $array);
  }

  /**
   * Tests toArray() for a new (unsaved) entity without an ID.
   */
  public function testToArrayNewEntity(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('id')->willReturn(NULL);

    $result = BlueprintResult::success($entity);
    $array = $result->toArray();

    $this->assertArrayNotHasKey('entity_id', $array);
    $this->assertSame('node', $array['entity_type']);
  }

  /**
   * Tests toArray() for a failure with errors and warnings.
   */
  public function testToArrayFailure(): void {
    $error = new BlueprintError(
      'fields.field_tags',
      'UNKNOWN_FIELD',
      'Field not found.',
      ['available_fields' => ['title', 'body']],
      'Check the field machine name.',
    );
    $warning = new BlueprintWarning(
      'fields.field_tags[0]',
      'LABEL_MISMATCH',
      'Label does not match.',
    );
    $result = BlueprintResult::semanticFailure([$error], [$warning]);
    $array = $result->toArray();

    $this->assertFalse($array['success']);
    $this->assertSame('semantic', $array['phase']);
    $this->assertCount(1, $array['errors']);
    $this->assertCount(1, $array['warnings']);

    $error_array = $array['errors'][0];
    $this->assertSame('fields.field_tags', $error_array['path']);
    $this->assertSame('UNKNOWN_FIELD', $error_array['code']);
    $this->assertSame('Field not found.', $error_array['message']);
    $this->assertSame(['available_fields' => ['title', 'body']], $error_array['context']);
    $this->assertSame('Check the field machine name.', $error_array['hint']);

    $warning_array = $array['warnings'][0];
    $this->assertSame('fields.field_tags[0]', $warning_array['path']);
    $this->assertSame('LABEL_MISMATCH', $warning_array['code']);
  }

  /**
   * Tests toArray() for a validate-only result.
   */
  public function testToArrayValidated(): void {
    $result = BlueprintResult::validated();
    $array = $result->toArray();

    $this->assertTrue($array['success']);
    $this->assertSame('validated', $array['phase']);
    $this->assertArrayNotHasKey('entity_type', $array);
    $this->assertArrayNotHasKey('errors', $array);
  }

  /**
   * Tests BlueprintError value object.
   */
  public function testBlueprintError(): void {
    $error = new BlueprintError(
      'fields.body.format',
      'INVALID_FIELD_FORMAT',
      'Invalid format.',
      ['expected' => 'full_html'],
      'Use a valid text format.',
    );

    $this->assertSame('fields.body.format', $error->getPath());
    $this->assertSame('INVALID_FIELD_FORMAT', $error->getCode());
    $this->assertSame('Invalid format.', $error->getMessage());
    $this->assertSame(['expected' => 'full_html'], $error->getContext());
    $this->assertSame('Use a valid text format.', $error->getHint());
  }

  /**
   * Tests BlueprintError toArray() omits empty context and hint.
   */
  public function testBlueprintErrorToArrayMinimal(): void {
    $error = new BlueprintError(
      'entity_type',
      'INVALID_ENTITY_TYPE',
      'Entity type does not exist.',
    );
    $array = $error->toArray();

    $this->assertSame('entity_type', $array['path']);
    $this->assertSame('INVALID_ENTITY_TYPE', $array['code']);
    $this->assertSame('Entity type does not exist.', $array['message']);
    $this->assertArrayNotHasKey('context', $array);
    $this->assertArrayNotHasKey('hint', $array);
  }

  /**
   * Tests BlueprintWarning value object.
   */
  public function testBlueprintWarning(): void {
    $warning = new BlueprintWarning(
      'fields.field_tags[0]',
      'LABEL_MISMATCH',
      "Resolved by target_id 5, but _label 'Newz' does not match actual label 'News'.",
    );

    $this->assertSame('fields.field_tags[0]', $warning->getPath());
    $this->assertSame('LABEL_MISMATCH', $warning->getCode());
    $this->assertSame(
      "Resolved by target_id 5, but _label 'Newz' does not match actual label 'News'.",
      $warning->getMessage(),
    );
  }

  /**
   * Tests BlueprintWarning toArray().
   */
  public function testBlueprintWarningToArray(): void {
    $warning = new BlueprintWarning(
      'fields.field_tags[0]',
      'LABEL_MISMATCH',
      'Label mismatch.',
    );
    $array = $warning->toArray();

    $this->assertSame('fields.field_tags[0]', $array['path']);
    $this->assertSame('LABEL_MISMATCH', $array['code']);
    $this->assertSame('Label mismatch.', $array['message']);
  }

  /**
   * Tests that structural failure with multiple errors collects all.
   */
  public function testMultipleErrors(): void {
    $errors = [
      new BlueprintError('entity_type', 'INVALID_ENTITY_TYPE', 'Bad type.'),
      new BlueprintError('bundle', 'INVALID_BUNDLE', 'Bad bundle.'),
      new BlueprintError('fields.foo', 'UNKNOWN_FIELD', 'Unknown field.'),
    ];
    $result = BlueprintResult::structuralFailure($errors);

    $this->assertFalse($result->isSuccess());
    $this->assertCount(3, $result->getErrors());
    $this->assertSame('INVALID_ENTITY_TYPE', $result->getErrors()[0]->getCode());
    $this->assertSame('INVALID_BUNDLE', $result->getErrors()[1]->getCode());
    $this->assertSame('UNKNOWN_FIELD', $result->getErrors()[2]->getCode());
  }

  /**
   * Tests withTargetsFrom builds targets from UUID and field names.
   */
  public function testWithTargetsFrom(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $result = BlueprintResult::success($entity)
      ->withTargetsFrom('uuid-123', ['field_heading', 'field_body']);

    $targets = $result->getTargets();
    $this->assertCount(2, $targets);
    $this->assertSame('uuid-123', $targets[0]['uuid']);
    $this->assertSame('field_heading', $targets[0]['field_name']);
    $this->assertSame('uuid-123', $targets[1]['uuid']);
    $this->assertSame('field_body', $targets[1]['field_name']);
  }

  /**
   * Tests getElementUuids extracts unique UUIDs from targets.
   */
  public function testGetComponentUuids(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $result = BlueprintResult::success($entity)
      ->withTargets([
        ['uuid' => '2ccc0362-5579-85a0-f3a6-624b8a6de818', 'field_name' => 'field_heading'],
        ['uuid' => 'de7c633e-5ca8-1342-f47f-08ec9da298e0', 'field_name' => 'field_body'],
        ['uuid' => '2ccc0362-5579-85a0-f3a6-624b8a6de818', 'field_name' => 'field_image'],
      ]);

    $uuids = $result->getElementUuids();
    $this->assertCount(2, $uuids);
    $this->assertContains('2ccc0362-5579-85a0-f3a6-624b8a6de818', $uuids);
    $this->assertContains('de7c633e-5ca8-1342-f47f-08ec9da298e0', $uuids);
  }

  /**
   * Tests toArray never serializes targets.
   *
   * Targets feed persistence and the persisted hooks object-side; the
   * response contract carries addressing through the ids alias map and
   * the entity identity keys only.
   */
  public function testToArrayWithTargets(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('page');
    $entity->method('id')->willReturn('1');

    $result = BlueprintResult::success($entity)
      ->withTargetsFrom('uuid-123', ['field_heading']);

    $array = $result->toArray();
    $this->assertArrayNotHasKey('element_uuids', $array);
  }

}
