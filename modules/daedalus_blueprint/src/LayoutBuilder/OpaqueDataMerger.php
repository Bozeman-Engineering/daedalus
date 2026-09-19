<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

/**
 * Merges opaque data according to managed keys rules.
 *
 * Implements the preserve-by-default / managed-keys-override merge strategy
 * used across all opaque data scopes (layout_settings, third_party_settings,
 * additional, configuration).
 *
 * Managed keys follow the same PATCH contract as fields
 * (see FieldHandlerBase::deserialize()):
 * - Present in incoming with a real value: incoming value used.
 * - Present in incoming but empty (NULL, '', []): cleared.
 * - Absent from incoming: preserved from previous state.
 *
 * Non-managed keys are always preserved from previous state; the AI never
 * sees or touches them.
 */
class OpaqueDataMerger {

  /**
   * Merges opaque data with managed keys semantics.
   *
   * @param array $previous
   *   The previous opaque data (e.g., component additional, section
   *   third_party_settings). Empty array for creates.
   * @param array $incoming
   *   The incoming opaque data from the AI's JSON.
   * @param array $managed_keys
   *   The managed keys for this scope from ManagedKeysRegistry. An
   *   associative array keyed by key name, each containing a definition
   *   array (label, description, schema).
   *
   * @return array
   *   The merged result: non-managed keys preserved from previous,
   *   managed keys patched from incoming (omission = preserve,
   *   explicit empty = clear).
   */
  public function merge(array $previous, array $incoming, array $managed_keys): array {
    $result = [];

    // Step 1: Preserve all non-managed keys from previous state.
    foreach ($previous as $key => $value) {
      if (!isset($managed_keys[$key])) {
        $result[$key] = $value;
      }
    }

    // Step 2: Patch AI-managed keys from incoming JSON, mirroring the field
    // contract (FieldHandlerBase::deserialize()): an omitted key keeps its
    // previous value, an explicit empty value clears it.
    foreach ($managed_keys as $key => $definition) {
      if (array_key_exists($key, $incoming)) {
        if (!static::isEmptyValue($incoming[$key])) {
          $result[$key] = $incoming[$key];
        }
      }
      elseif (array_key_exists($key, $previous)) {
        $result[$key] = $previous[$key];
      }
    }

    return $result;
  }

  /**
   * Patches managed key values onto an existing configuration array.
   *
   * The same PATCH contract as merge(), for callers that mutate a live
   * configuration array (e.g. updateElement) rather than rebuilding a scope:
   * an explicit empty value (NULL, '', []) removes the key, any other value
   * is set, keys not in $incoming are untouched. Callers are responsible for
   * passing only managed keys in $incoming.
   *
   * @param array $config
   *   The existing configuration array.
   * @param array $incoming
   *   Managed key values from the AI's JSON.
   *
   * @return array
   *   The patched configuration.
   */
  public function patch(array $config, array $incoming): array {
    foreach ($incoming as $key => $value) {
      if (static::isEmptyValue($value)) {
        unset($config[$key]);
      }
      else {
        $config[$key] = $value;
      }
    }
    return $config;
  }

  /**
   * Determines whether a value means "clear" under the PATCH contract.
   *
   * The same strict empty predicate fields use
   * (FieldHandlerBase::deserialize()): exactly NULL, '', and []. Falsy
   * values like 0, '0', and FALSE are real values.
   *
   * @param mixed $value
   *   The incoming value.
   *
   * @return bool
   *   TRUE when the value clears the key.
   */
  public static function isEmptyValue(mixed $value): bool {
    return $value === NULL || $value === '' || $value === [];
  }

  /**
   * Merges an opaque data scope that may be omitted or malformed in incoming.
   *
   * An absent or non-array scope is treated as an empty declaration, which
   * under the PATCH contract preserves all previous data. This is a
   * convenience for callers reading the scope straight out of incoming JSON.
   *
   * @param array $previous
   *   The previous opaque data. Empty array for creates.
   * @param mixed $incoming
   *   The incoming scope from the AI's JSON, or NULL/non-array when the scope
   *   key was absent.
   * @param array $managed_keys
   *   The managed keys for this scope from ManagedKeysRegistry.
   *
   * @return array
   *   The merge() result.
   */
  public function mergeOptionalScope(array $previous, mixed $incoming, array $managed_keys): array {
    return $this->merge($previous, is_array($incoming) ? $incoming : [], $managed_keys);
  }

}
