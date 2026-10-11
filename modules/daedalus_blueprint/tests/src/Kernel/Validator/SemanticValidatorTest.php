<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Validator;

use Drupal\field\Entity\FieldConfig;
use Drupal\daedalus_blueprint\Validator\SemanticValidator;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the SemanticValidator (Phase B validation).
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(SemanticValidator::class)]
#[Group('daedalus_blueprint')]
class SemanticValidatorTest extends BlueprintKernelTestBase {

  /**
   * The semantic validator under test.
   *
   * @var \Drupal\daedalus_blueprint\Validator\SemanticValidator
   */
  protected SemanticValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = $this->container->get('daedalus_blueprint.semantic_validator');
  }

  /**
   * Tests field access allowed for accessible fields.
   */
  public function testFieldAccessAllowed(): void {
    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    $errors = $this->validator->validateFieldAccess(
      $entity,
      ['name', 'field_subtitle', 'field_count'],
    );
    $this->assertEmpty($errors);
  }

  /**
   * Tests field access denied via entity_test's state-based field access.
   *
   * The entity_test module's hook_entity_field_access checks state for
   * 'views_field_access_test-field' and requires 'view test entity field'
   * permission for all operations on that field.
   */
  public function testFieldAccessDenied(): void {
    // A user who can write entity_test content but lacks the field-view
    // permission (the base test user is an admin, which would pass).
    $this->setUpCurrentUser([], ['administer entity_test content']);

    // Use entity_test's state-based field access control to restrict a field.
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    $errors = $this->validator->validateFieldAccess(
      $entity,
      ['name', 'field_subtitle', 'field_count'],
    );
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame('fields.field_subtitle', $errors[0]->getPath());
    $this->assertStringContainsString('field_subtitle', $errors[0]->getMessage());
  }

  /**
   * Tests field access check skips nonexistent fields gracefully.
   */
  public function testFieldAccessSkipsNonexistentFields(): void {
    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    $errors = $this->validator->validateFieldAccess(
      $entity,
      ['name', 'nonexistent_field'],
    );
    $this->assertEmpty($errors);
  }

  /**
   * Tests constraint validation for full create reports all violations.
   */
  public function testConstraintValidationFullCreate(): void {
    // Create a required field to trigger a violation.
    $this->createField('field_required', 'string', ['max_length' => 255]);
    $field_config = FieldConfig::loadByName(
      'entity_test',
      'entity_test',
      'field_required',
    );
    $field_config->setRequired(TRUE)->save();

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);
    // Don't set field_required — should trigger a constraint violation.
    $errors = $this->validator->validateConstraints(
      $entity,
      ['name'],
      [],
      TRUE,
    );

    // Should report the violation even though field_required wasn't submitted,
    // because isFullCreate = TRUE.
    $has_required_violation = FALSE;
    foreach ($errors as $error) {
      if ($error->getCode() === 'CONSTRAINT_VIOLATION'
        && str_contains($error->getPath(), 'field_required')) {
        $has_required_violation = TRUE;
        break;
      }
    }
    $this->assertTrue($has_required_violation, 'Full create should report violations on all fields.');
  }

  /**
   * Tests constraint validation for update only reports submitted fields.
   */
  public function testConstraintValidationScopedToSubmittedFields(): void {
    // Create a required field.
    $this->createField('field_required', 'string', ['max_length' => 255]);
    $field_config = FieldConfig::loadByName(
      'entity_test',
      'entity_test',
      'field_required',
    );
    $field_config->setRequired(TRUE)->save();

    // Create entity with the required field empty (entity_test allows this).
    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    // Validate with isFullCreate = FALSE and only 'name' submitted.
    $errors = $this->validator->validateConstraints(
      $entity,
      ['name'],
      [],
      FALSE,
    );

    // Should NOT report violation on field_required since it wasn't submitted.
    foreach ($errors as $error) {
      $this->assertStringNotContainsString(
        'field_required',
        $error->getPath(),
        'Scoped update should not report violations on non-submitted fields.',
      );
    }
  }

  /**
   * Tests constraint validation skips fields with existing handler errors.
   */
  public function testConstraintValidationSkipsErrorFields(): void {
    // Create a required field.
    $this->createField('field_required', 'string', ['max_length' => 255]);
    $field_config = FieldConfig::loadByName(
      'entity_test',
      'entity_test',
      'field_required',
    );
    $field_config->setRequired(TRUE)->save();

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    // Simulate that field_required already has a handler error.
    $errors = $this->validator->validateConstraints(
      $entity,
      ['name', 'field_required'],
      ['field_required'],
      TRUE,
    );

    // Should not report violation on field_required (already has handler
    // error).
    foreach ($errors as $error) {
      $this->assertStringNotContainsString(
        'field_required',
        $error->getPath(),
        'Should not duplicate violations for fields with handler errors.',
      );
    }
  }

  /**
   * Tests constraint validation returns no errors for valid entity.
   */
  public function testConstraintValidationNoErrors(): void {
    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    $errors = $this->validator->validateConstraints(
      $entity,
      ['name'],
      [],
      TRUE,
    );
    $this->assertEmpty($errors);
  }

  /**
   * Tests CONSTRAINT_VIOLATION error includes message.
   */
  public function testConstraintViolationIncludesMessage(): void {
    // Create a required field and submit it as empty in a full create.
    $this->createField('field_required', 'string', ['max_length' => 255]);
    $field_config = FieldConfig::loadByName(
      'entity_test',
      'entity_test',
      'field_required',
    );
    $field_config->setRequired(TRUE)->save();

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'Test']);

    $errors = $this->validator->validateConstraints(
      $entity,
      ['name', 'field_required'],
      [],
      TRUE,
    );

    $violation_errors = array_filter(
      $errors,
      fn($error) => $error->getCode() === 'CONSTRAINT_VIOLATION'
        && str_contains($error->getPath(), 'field_required'),
    );
    $this->assertNotEmpty($violation_errors, 'Should report CONSTRAINT_VIOLATION for required empty field.');

    $violation = reset($violation_errors);
    $this->assertNotEmpty($violation->getMessage(), 'Violation should have a message.');
    $this->assertStringStartsWith('fields.', $violation->getPath());
  }

  /**
   * Tests end-to-end: ACCESS_DENIED_ENTITY through deserializer.
   *
   * Host-level access is the capability resolver's host access policy,
   * asked at the root of the whole-document path for a create as for an
   * update; the deserializer reports its refusal at entity_type.
   */
  public function testDeserializerReturnsAccessDeniedEntity(): void {
    // Switch to a user without entity_test permissions.
    $this->setUpCurrentUser([], []);

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_ENTITY', $errors[0]->getCode());
    $this->assertSame('entity_type', $errors[0]->getPath());
    $this->assertStringContainsString('cannot create', $errors[0]->getMessage());

    $entity = $this->createTestEntity(['name' => 'Test']);
    $result = $deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => ['name' => 'Renamed'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('ACCESS_DENIED_ENTITY', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('cannot update', $result->getErrors()[0]->getMessage());
  }

  /**
   * Tests end-to-end: ACCESS_DENIED_FIELD through deserializer.
   */
  public function testDeserializerReturnsAccessDeniedField(): void {
    // A user who can write entity_test content but lacks the field-view
    // permission (the base test user is an admin, which would pass).
    $this->setUpCurrentUser([], ['administer entity_test content']);

    // Restrict access to field_subtitle via entity_test's state mechanism.
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
        'field_subtitle' => 'Restricted',
      ],
    ];

    $result = $deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());

    $access_errors = array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'ACCESS_DENIED_FIELD',
    );
    $this->assertNotEmpty($access_errors);
    $access_error = reset($access_errors);
    $this->assertSame('fields.field_subtitle', $access_error->getPath());
  }

  /**
   * Tests end-to-end: CONSTRAINT_VIOLATION through deserializer.
   */
  public function testDeserializerReturnsConstraintViolation(): void {
    // Create a required field.
    $this->createField('field_required', 'string', ['max_length' => 255]);
    $field_config = FieldConfig::loadByName(
      'entity_test',
      'entity_test',
      'field_required',
    );
    $field_config->setRequired(TRUE)->save();

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    // Full create with field_required empty — should trigger violation.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());

    $violation_errors = array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'CONSTRAINT_VIOLATION',
    );
    $this->assertNotEmpty($violation_errors, 'Should report CONSTRAINT_VIOLATION.');
  }

  /**
   * Tests that update scoping works through the deserializer.
   *
   * Pre-existing constraint violations on non-submitted fields should not
   * be reported.
   */
  public function testDeserializerUpdateScopingExcludesPreExisting(): void {
    // Create a required field.
    $this->createField('field_required', 'string', ['max_length' => 255]);
    $field_config = FieldConfig::loadByName(
      'entity_test',
      'entity_test',
      'field_required',
    );
    $field_config->setRequired(TRUE)->save();

    // Create an entity (bypassing validation — entity_test allows this).
    $entity = $this->createTestEntity([
      'name' => 'Test',
      'field_required' => 'Has value',
    ]);

    // Clear the required field value directly in the database by updating
    // the entity. We need the entity to exist but with a violation.
    $entity->set('field_required', NULL);
    $entity->save();

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    // Update only name — should NOT report field_required violation.
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'name' => 'Updated name',
      ],
    ];

    $result = $deserializer->deserialize($data);

    // Should succeed — field_required violation should be scoped out.
    $this->assertTrue(
      $result->isSuccess(),
      'Update should not report violations on non-submitted fields. Errors: '
        . print_r(array_map(fn($e) => $e->toArray(), $result->getErrors()), TRUE),
    );
  }

}
