<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config;

/**
 * Shared metadata for config entity blueprint operations.
 *
 * Provides system property filtering and immutable property detection used
 * across serialization, schema building, and validation.
 */
trait ConfigEntityMetadataTrait {

  /**
   * System properties excluded from blueprint operations.
   */
  protected static array $systemProperties = [
    'uuid',
    '_core',
    'dependencies',
    'langcode',
  ];

  /**
   * Gets immutable properties for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string[]
   *   Immutable property names.
   */
  protected function getImmutableProperties(string $entity_type_id): array {
    $immutable = ['id', 'uuid'];

    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $constraints = $entity_type->getConstraints();
    if (isset($constraints['ImmutableProperties'])) {
      $constraint_data = $constraints['ImmutableProperties'];
      $constraint_properties = $constraint_data['properties'] ?? (is_array($constraint_data) ? array_values($constraint_data) : []);
      $immutable = array_unique(array_merge($immutable, $constraint_properties));
    }

    return array_values($immutable);
  }

}
