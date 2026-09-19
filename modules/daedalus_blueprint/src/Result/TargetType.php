<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Result;

/**
 * Sentinel "field name" values used on BlueprintResult targets.
 *
 * A target's `field_name` is normally a real field machine name: the field that
 * changed on the addressed entity. When a change is not about a single field --
 * a component was added or removed, or the layout was rearranged -- the
 * field_name slot instead carries one of these sentinels naming the *kind* of
 * change.
 *
 * Sentinels are underscore-prefixed, which a real Drupal field machine name can
 * never be (field names must start with a letter). Consumers use that prefix
 * (see ::isSentinel()) to tell a change-kind marker apart from a real field.
 *
 * These are a contract shared across modules: daedalus_blueprint produces them on
 * targets, and consumers (e.g. daedalus_ai's refresh pipeline) read them to decide
 * how to react. Reference these constants, not the literal strings.
 */
final class TargetType {

  /**
   * A new component was added at the target UUID.
   */
  const ADDED = '_added';

  /**
   * The component at the target UUID was removed.
   */
  const REMOVED = '_removed';

  /**
   * The layout was rearranged (sections or components reordered or moved).
   */
  const RESTRUCTURED = '_restructured';

  /**
   * Items in an entity reference (revisions) field were reordered.
   */
  const REORDERED = '_reordered';

  /**
   * Determines whether a target field_name is a sentinel rather than a field.
   *
   * @param string $field_name
   *   The target field_name.
   *
   * @return bool
   *   TRUE when the value is a change-kind sentinel (underscore-prefixed),
   *   FALSE when it is a real field machine name.
   */
  public static function isSentinel(string $field_name): bool {
    return str_starts_with($field_name, '_');
  }

}
