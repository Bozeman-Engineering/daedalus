<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Validator;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Phase B semantic validator for entity blueprints.
 *
 * Handles permission checks (entity-level and field-level access) and
 * constraint validation with scoping to submitted fields. Used by the
 * BlueprintDeserializer after structural validation passes and the entity
 * is loaded or created.
 */
class SemanticValidator {

  /**
   * Constructs a SemanticValidator.
   *
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The blueprint access checker.
   */
  public function __construct(
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * Validates entity-level create or edit access.
   *
   * Checks whether the acting user can create (for new entities) or update
   * (for existing entities) the given entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to check access on.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Entity access errors. Empty if access is granted.
   */
  public function validateEntityAccess(FieldableEntityInterface $entity): array {
    $operation = $entity->isNew() ? 'create' : 'update';

    if (!$this->accessChecker->entityAccess($entity, $operation)) {
      $entity_type = $entity->getEntityTypeId();
      $bundle = $entity->bundle();
      return [
        new BlueprintError(
          path: 'entity_type',
          code: 'ACCESS_DENIED_ENTITY',
          message: "Access denied: cannot $operation $entity_type:$bundle.",
          hint: 'The current user lacks the required permission.',
        ),
      ];
    }

    return [];
  }

  /**
   * Validates field-level edit access for submitted fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose fields are being set.
   * @param array $field_names
   *   The submitted field machine names.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Field access errors. Empty if all fields are accessible.
   */
  public function validateFieldAccess(FieldableEntityInterface $entity, array $field_names): array {
    $errors = [];

    foreach ($field_names as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      if (!$this->accessChecker->fieldEditable($entity->get($field_name))) {
        $errors[] = new BlueprintError(
          path: "fields.$field_name",
          code: 'ACCESS_DENIED_FIELD',
          message: "Access denied: cannot edit field '$field_name'.",
          hint: 'The current user lacks permission to modify this field.',
        );
      }
    }

    return $errors;
  }

  /**
   * Runs entity constraint validation, scoped to submitted fields.
   *
   * For full entity creation (no _root_entity), reports all violations since
   * the caller is responsible for the entire document. For updates, only
   * reports violations on submitted fields to avoid surfacing pre-existing
   * issues that the caller did not cause.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The hydrated entity to validate.
   * @param array $submitted_field_names
   *   Field names that were in the submitted payload.
   * @param array $error_field_names
   *   Field names that already have handler errors (skip violations for these
   *   to avoid duplicate error reporting).
   * @param bool $is_full_create
   *   TRUE for full blueprint create (report all violations), FALSE for
   *   scoped updates (only report violations on submitted fields).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Constraint violation errors.
   */
  public function validateConstraints(
    FieldableEntityInterface $entity,
    array $submitted_field_names,
    array $error_field_names,
    bool $is_full_create = FALSE,
  ): array {
    $errors = [];
    $violations = $entity->validate();

    foreach ($violations as $violation) {
      $property_path = $violation->getPropertyPath();
      $field_name = explode('.', $property_path)[0];

      // Skip violations on fields that already have handler errors.
      if (in_array($field_name, $error_field_names, TRUE)) {
        continue;
      }

      // For updates, only report violations on submitted fields.
      if (!$is_full_create && !in_array($field_name, $submitted_field_names, TRUE)) {
        continue;
      }

      $entity_label = $entity->getEntityTypeId() . ':' . $entity->bundle() . ':' . $entity->uuid();
      $errors[] = new BlueprintError(
        path: "fields.$property_path",
        code: 'CONSTRAINT_VIOLATION',
        message: "Constraint violation on $entity_label field '$field_name': " . (string) $violation->getMessage(),
      );
    }

    return $errors;
  }

}
