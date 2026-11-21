<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;

/**
 * Shared reference resolution logic for entity reference and file handlers.
 *
 * Provides target_id and label-based resolution with bundle validation,
 * label mismatch warnings, and similar-label suggestions.
 *
 * Resolution is access-aware by construction: an id, a uuid or a label
 * resolves only to an entity the actor may view, and one the actor may not
 * view answers exactly as one that does not exist. There is deliberately no
 * access-blind path for an actor, so no caller can resolve to, or learn of,
 * an entity outside the actor's access. A caller acting for the site (the
 * `system` option) names no actor, and an id or uuid resolves unjudged.
 *
 * Using classes must have $this->entityTypeManager, $this->logger, and
 * $this->accessChecker properties.
 */
trait ReferenceResolverTrait {

  /**
   * Resolves a reference by target_id.
   *
   * Loads the entity, validates bundle constraints, and checks for label
   * mismatch warnings.
   *
   * @param int $target_id
   *   The target entity ID.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $path
   *   The error path.
   * @param string|null $provided_label
   *   The _label from the input (for mismatch warning), or NULL.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Collected warnings (passed by reference).
   * @param array $options
   *   The deserialization options (`system`, `actor`): who the reference is
   *   resolved for.
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The resolved ['target_id' => N] or an error.
   */
  protected function resolveByTargetId(
    int $target_id,
    string $target_type,
    ?array $target_bundles,
    string $path,
    ?string $provided_label = NULL,
    array &$warnings = [],
    array $options = [],
  ): array|BlueprintError {
    $entity = $this->entityTypeManager->getStorage($target_type)->load($target_id);

    if ($entity === NULL || !$this->referenceViewable($entity, $options)) {
      $this->logger->notice('Reference not found: @type @id at @path', [
        '@type' => $target_type,
        '@id' => $target_id,
        '@path' => $path,
      ]);
      return new BlueprintError(
        path: $path,
        code: 'REFERENCE_NOT_FOUND',
        message: "No {$target_type} entity found with ID {$target_id}.",
        hint: 'Verify the entity exists.',
      );
    }

    if ($target_bundles && !in_array($entity->bundle(), $target_bundles, TRUE)) {
      return new BlueprintError(
        path: $path,
        code: 'REFERENCE_NOT_FOUND',
        message: "Entity {$target_type} {$target_id} has bundle '{$entity->bundle()}' which is not allowed.",
        context: ['allowed_bundles' => $target_bundles],
        hint: 'The referenced entity must be one of the allowed bundles.',
      );
    }

    // Check for label mismatch warning. The warning echoes the entity's actual
    // label; the actor may view the target (asked above), and a caller acting
    // for the site names no reader to keep it from.
    if ($provided_label !== NULL) {
      $actual_label = (string) $entity->label();
      if ($provided_label !== $actual_label) {
        $this->logger->info('Label mismatch at @path: provided "@provided", actual "@actual" for @type @id', [
          '@path' => $path,
          '@provided' => $provided_label,
          '@actual' => $actual_label,
          '@type' => $target_type,
          '@id' => $target_id,
        ]);
        $warnings[] = new BlueprintWarning(
          $path,
          'LABEL_MISMATCH',
          "Resolved by target_id {$target_id}, but _label '{$provided_label}' does not match actual label '{$actual_label}'.",
        );
      }
    }

    $id = $entity->id();
    return ['target_id' => is_numeric($id) ? (int) $id : $id];
  }

  /**
   * Resolves a reference by uuid.
   *
   * The spelling a template ships with: a display's value blocks name
   * their references by uuid, since config export carries no serial ids
   * and default content resolves uuids across sites.
   *
   * @param string $uuid
   *   The target entity's uuid.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $path
   *   The error path.
   * @param array $options
   *   The deserialization options (`system`, `actor`): who the reference is
   *   resolved for.
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The resolved ['target_id' => N] or an error.
   */
  protected function resolveByUuid(
    string $uuid,
    string $target_type,
    ?array $target_bundles,
    string $path,
    array $options = [],
  ): array|BlueprintError {
    $entities = $this->entityTypeManager->getStorage($target_type)->loadByProperties(['uuid' => $uuid]);
    $entity = reset($entities);
    if ($entity === FALSE || !$this->referenceViewable($entity, $options)) {
      $this->logger->notice('Reference not found: @type uuid @uuid at @path', [
        '@type' => $target_type,
        '@uuid' => $uuid,
        '@path' => $path,
      ]);
      return new BlueprintError(
        path: $path,
        code: 'REFERENCE_NOT_FOUND',
        message: "No {$target_type} entity found with uuid {$uuid}.",
        hint: 'Verify the entity exists.',
      );
    }

    if ($target_bundles && !in_array($entity->bundle(), $target_bundles, TRUE)) {
      return new BlueprintError(
        path: $path,
        code: 'REFERENCE_NOT_FOUND',
        message: "Entity {$target_type} {$uuid} has bundle '{$entity->bundle()}' which is not allowed.",
        context: ['allowed_bundles' => $target_bundles],
        hint: 'The referenced entity must be one of the allowed bundles.',
      );
    }

    $id = $entity->id();
    return ['target_id' => is_numeric($id) ? (int) $id : $id];
  }

  /**
   * Whether a reference may resolve to an entity: the actor may view it.
   *
   * The one question both the id and the uuid road ask before they answer
   * anything about the entity (its id, its bundle, its label), so an entity
   * outside the actor's access reads as absent.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the reference names.
   * @param array $options
   *   The deserialization options: `system` (TRUE names no actor, nothing
   *   is judged) and `actor` (an account; the current user when absent).
   *
   * @return bool
   *   TRUE when the reference may resolve to the entity.
   */
  protected function referenceViewable(EntityInterface $entity, array $options): bool {
    if (!empty($options['system'])) {
      return TRUE;
    }
    $actor = $options['actor'] ?? NULL;
    return $this->accessChecker->entityAccess($entity, 'view', $actor instanceof AccountInterface ? $actor : NULL);
  }

  /**
   * Resolves a reference by label.
   *
   * Queries for an exact match. On multiple matches, returns
   * REFERENCE_AMBIGUOUS. On zero matches, returns REFERENCE_NOT_FOUND with
   * similar labels.
   *
   * @param string $label
   *   The label to search for.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $path
   *   The error path.
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The resolved reference or an error.
   */
  protected function resolveByLabel(
    string $label,
    string $target_type,
    ?array $target_bundles,
    string $path,
  ): array|BlueprintError {
    $entity_type_definition = $this->entityTypeManager->getDefinition($target_type);
    $label_key = $entity_type_definition->getKey('label');
    $bundle_key = $entity_type_definition->getKey('bundle');

    if (!$label_key) {
      return new BlueprintError(
        path: $path,
        code: 'REFERENCE_NOT_FOUND',
        message: "Entity type '{$target_type}' does not have a label field for _label resolution.",
        hint: 'Use target_id instead.',
      );
    }

    $matches = $this->queryViewableByLabel($target_type, $label, $target_bundles, $label_key, $bundle_key);

    if (count($matches) === 1) {
      $entity = reset($matches);
      $id = $entity->id();
      return ['target_id' => is_numeric($id) ? (int) $id : $id];
    }

    if (count($matches) > 1) {
      $candidates = [];
      foreach ($matches as $match) {
        $candidates[] = [
          'target_id' => is_numeric($match->id()) ? (int) $match->id() : $match->id(),
          'label' => (string) $match->label(),
          'bundle' => $match->bundle(),
        ];
      }
      return new BlueprintError(
        path: $path,
        code: 'REFERENCE_AMBIGUOUS',
        message: "Multiple {$target_type} entities match _label '{$label}'.",
        context: ['matches' => $candidates],
        hint: 'Use target_id to specify the exact entity.',
      );
    }

    // Zero matches.
    $similar = $this->findSimilarLabels($label, $target_type, $target_bundles, $label_key, $bundle_key);

    return new BlueprintError(
      path: $path,
      code: 'REFERENCE_NOT_FOUND',
      message: "No {$target_type} entity found with label '{$label}'.",
      context: !empty($similar) ? ['similar' => $similar] : [],
      hint: !empty($similar)
        ? 'Did you mean one of the similar labels? Or use target_id.'
        : 'Verify the label or use target_id.',
    );
  }

  /**
   * Loads entities matching a label, honoring the current user's view access.
   *
   * The single label-query primitive: there is deliberately no access-blind
   * variant. The lookup runs with access checking enabled so entities the
   * current user cannot view are never returned — keeping label resolution from
   * resolving to, or reporting, entities outside the user's access.
   *
   * @param string $target_type
   *   The target entity type.
   * @param string $label
   *   The label to match.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $label_key
   *   The label field name.
   * @param string|false $bundle_key
   *   The bundle field name, or FALSE if no bundle key.
   * @param string $match_operator
   *   The label condition operator: '=' for exact resolution, 'CONTAINS' for
   *   similar-label suggestions.
   * @param int|null $range
   *   Optional result limit, or NULL for no limit.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The viewable matching entities, keyed by entity ID.
   */
  protected function queryViewableByLabel(
    string $target_type,
    string $label,
    ?array $target_bundles,
    string $label_key,
    string|false $bundle_key,
    string $match_operator = '=',
    ?int $range = NULL,
  ): array {
    $storage = $this->entityTypeManager->getStorage($target_type);
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition($label_key, $label, $match_operator);

    if ($target_bundles && $bundle_key) {
      $query->condition($bundle_key, $target_bundles, 'IN');
    }
    if ($range !== NULL) {
      $query->range(0, $range);
    }

    $ids = $query->execute();
    if (!$ids) {
      return [];
    }

    // accessCheck(TRUE) filters at the DB layer for entity types that integrate
    // query access (e.g. node grants), but not all types do — for those it is a
    // no-op. Re-check each candidate with entity-level view access, the
    // authoritative and uniform check (the same one the serializer uses), so
    // the access-aware invariant holds regardless of query-access support.
    return array_filter(
      $storage->loadMultiple($ids),
      fn($entity) => $this->accessChecker->entityAccess($entity, 'view'),
    );
  }

  /**
   * Finds entities with labels similar to the given label.
   *
   * Only labels of entities the current user can view are returned (the query
   * primitive is access-checked).
   *
   * @param string $label
   *   The label to search for.
   * @param string $target_type
   *   The target entity type.
   * @param array|null $target_bundles
   *   Allowed bundles, or NULL for any.
   * @param string $label_key
   *   The label field name.
   * @param string|false $bundle_key
   *   The bundle field name, or FALSE if no bundle key.
   *
   * @return string[]
   *   Similar labels (up to 5).
   */
  protected function findSimilarLabels(string $label, string $target_type, ?array $target_bundles, string $label_key, string|false $bundle_key): array {
    $entities = $this->queryViewableByLabel(
      $target_type, $label, $target_bundles, $label_key, $bundle_key, 'CONTAINS', 5,
    );
    $similar = [];
    foreach ($entities as $entity) {
      $similar[] = (string) $entity->label();
    }
    return $similar;
  }

}
