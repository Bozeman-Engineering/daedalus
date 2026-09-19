<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\Validator;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;

/**
 * Semantic validator for config entity blueprints.
 *
 * Phase B: checks access control and runs entity constraint validation.
 * Only executes after structural validation passes.
 */
class ConfigSemanticValidator {

  /**
   * Constructs a ConfigSemanticValidator.
   *
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The shared blueprint access checker.
   */
  public function __construct(
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * Validates a config entity semantically.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity to validate (already hydrated from blueprint).
   * @param bool $is_new
   *   Whether this is a new entity being created.
   *
   * @return array{errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   Associative array with 'errors' and 'warnings' keys.
   */
  public function validate(ConfigEntityInterface $entity, bool $is_new = FALSE): array {
    $errors = [];
    $warnings = [];

    $errors = array_merge($errors, $this->validateAccess($entity, $is_new));

    $constraint_result = $this->validateConstraints($entity);
    $errors = array_merge($errors, $constraint_result['errors']);
    $warnings = array_merge($warnings, $constraint_result['warnings']);

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates access control.
   *
   * Delegates to the shared access checker, which is fail-closed: a neutral
   * access result counts as denied. Public so plugin-collection operations can
   * gate themselves on update access without relying on the calling tool.
   *
   * Create access for the otherwise-ungated config types (entity displays,
   * field config) is supplied by daedalus_blueprint_config_entity_create_access()
   * — an admin allow-list plus a permission — so fail-closed here means those
   * types are deniable by default and reachable only when opted in.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity.
   * @param bool $is_new
   *   Whether creating or updating.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Access errors.
   */
  public function validateAccess(ConfigEntityInterface $entity, bool $is_new): array {
    $operation = $is_new ? 'create' : 'update';

    $allowed = $is_new
      ? $this->accessChecker->createAccess($entity->getEntityTypeId(), $entity->bundle())
      : $this->accessChecker->entityAccess($entity, 'update');

    if (!$allowed) {
      return [
        new BlueprintError(
          path: '',
          code: 'access_denied',
          message: "Access denied: cannot $operation " . $entity->getEntityTypeId() . '.',
        ),
      ];
    }

    return [];
  }

  /**
   * Validates entity constraints via typed data.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity to validate.
   *
   * @return array{errors: \Drupal\daedalus_blueprint\Result\BlueprintError[], warnings: \Drupal\daedalus_blueprint\Result\BlueprintWarning[]}
   *   Associative array with 'errors' and 'warnings' keys.
   */
  protected function validateConstraints(ConfigEntityInterface $entity): array {
    $errors = [];
    $warnings = [];

    try {
      // Calculate dependencies before validation so that constraints checking
      // config dependencies (e.g., RequiredConfigDependencies on FieldConfig)
      // can find the required dependencies.
      if (method_exists($entity, 'calculateDependencies')) {
        $entity->calculateDependencies();
      }
    }
    catch (\Throwable) {
      // Some entities (e.g., display entities with unresolvable field
      // references) may fail during dependency calculation. This is non-fatal
      // for validation.
    }

    try {
      $typed_data = $entity->getTypedData();
      $violations = $typed_data->validate();
    }
    catch (\Throwable $e) {
      // Third-party constraint validators may not handle config entities
      // (e.g., WSE assumes ContentEntityInterface). Treat as a warning since
      // the entity itself is likely valid.
      $warnings[] = new BlueprintWarning(
        path: '',
        code: 'constraint_validation_unavailable',
        message: 'Constraint validation could not be performed: ' . $e->getMessage(),
      );
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    foreach ($violations as $violation) {
      $errors[] = new BlueprintError(
        path: $violation->getPropertyPath(),
        code: 'constraint_violation',
        message: (string) $violation->getMessage(),
      );
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

}
