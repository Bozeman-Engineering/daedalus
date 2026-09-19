<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * Collects and provides managed keys for opaque data scopes.
 *
 * Managed keys determine which opaque Layout Builder data is AI-visible and
 * AI-controllable. Non-managed keys are preserved from the previous state
 * and never exposed to the AI.
 */
interface ManagedKeysRegistryInterface {

  /**
   * Gets all managed keys for an entity type and bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return array
   *   Managed keys keyed by scope. Each scope contains an associative array
   *   of key name => definition (with label, description, schema).
   *   Scopes: section_layout_settings (nested by layout plugin ID),
   *   inline_block_configuration (nested by block type).
   */
  public function getManagedKeys(string $entity_type, string $bundle): array;

  /**
   * Gets managed keys for a specific scope.
   *
   * @param string $scope
   *   The scope name (e.g., 'section_layout_settings').
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return array
   *   Managed key definitions for the scope, keyed by key name.
   */
  public function getManagedKeysForScope(string $scope, string $entity_type, string $bundle): array;

  /**
   * Checks if a specific key in a scope is managed.
   *
   * @param string $scope
   *   The scope name.
   * @param string $key
   *   The key name within the scope.
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return bool
   *   TRUE if the key is declared as AI-managed.
   */
  public function isManaged(string $scope, string $key, string $entity_type, string $bundle): bool;

}
