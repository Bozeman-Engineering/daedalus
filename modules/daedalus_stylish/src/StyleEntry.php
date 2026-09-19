<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * Pure reads over one style entry: the shape a map key's value takes.
 *
 * A style entry is a condition-keyed map of property => value (with the
 * optional `variants` reference list on base entries). The two walks that
 * need entry arithmetic (detach's materialization in
 * DaedalusStylishOperationHandler, the variant capture in VariantCapture) share
 * these so the merge semantics have one home, the server twin of
 * mergeEntry() / stripVariants() in js/src/variant-scope.js.
 */
final class StyleEntry {

  /**
   * Merges two style entries, the winner's values taking precedence.
   *
   * Condition maps merge per property; a scalar or list slot (the
   * `variants` reference list) is taken wholesale from whichever entry
   * carries it, the winner first.
   */
  public static function merge(array $winner, array $loser): array {
    $merged = array_merge($loser, $winner);
    foreach ($winner as $condition => $values) {
      if (is_array($values) && !array_is_list($values) && isset($loser[$condition]) && is_array($loser[$condition]) && !array_is_list($loser[$condition])) {
        $merged[$condition] = array_merge($loser[$condition], $values);
      }
    }
    return $merged;
  }

  /**
   * An entry's own layers: the condition maps without the reference list.
   *
   * Overrides-only is what a capture carries for the element it walks
   * (references fold or travel as refs by their own rule, never as layers).
   */
  public static function stripVariants(array $entry): array {
    unset($entry['variants']);
    return $entry;
  }

}
