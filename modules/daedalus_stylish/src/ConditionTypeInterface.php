<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A condition type: named conditions and their CSS wrappers.
 *
 * Conditions are the WHEN axis of the style map: a style entry nests
 * its properties under condition keys ("default", "hover", "narrow"), and
 * each non-default condition wraps its declarations in the CSS this plugin
 * names. The reserved entry keys — "default" (always applies, no wrapper)
 * and "variants" — are never condition names and cannot be declared by a
 * plugin. What a plugin publishes is a REFERENCE: a name whose CSS the
 * site owns. A condition key may also carry a LITERAL clause the browser
 * knows, written as its own canonical CSS ("@container (min-width:
 * 500px)", ":checked"), where the enforcement mode admits one
 * (StyleMapValidator::validateCondition(); strict refuses it naming the
 * references on its axis); the one clause grammar reads both
 * (\Drupal\daedalus_blueprint\Materials\ConditionClause), and a literal
 * equal to a published wrapper is that reference.
 */
interface ConditionTypeInterface {

  /**
   * The condition type's human label.
   */
  public function label(): TranslatableMarkup;

  /**
   * The conditions this type provides.
   *
   * @return array
   *   Condition definitions keyed by condition name (the style-map key,
   *   a lowercase token):
   *   - label: (TranslatableMarkup) the condition's label in the inspector.
   *   - wrapper: (string) the CSS wrapper. A string starting with ":" is a
   *     pseudo-class suffix appended to the target selector (":hover"); a
   *     string starting with "@" is an at-rule prelude the rule nests inside
   *     ("@container (max-width: 767px)"). Thresholds are baked into the
   *     wrapper text here — plugins with configurable thresholds read them
   *     from config when building it.
   *
   *   A plugin declares nothing else: label, wrapper and (on the type) the
   *   axis. The structured facts the edit-mode canvas poses are DERIVED
   *   from the wrapper's CSS by the clause grammar (ConditionClause, and
   *   its client twin over the guide payload's wrapper strings), the same
   *   derivation a literal clause typed in a map gets: a @container width
   *   query's inclusive px edges are the band the ruler marks and "drive
   *   the canvas there" poses (a @media width query answers to the browser
   *   window, which the canvas cannot pose, so it derives none), and a
   *   single-feature "prefers-*" media query's feature and value are the
   *   preference the canvas emulation matches a forced value against.
   *
   *   Every condition is posable: a pseudo-class condition is a state the
   *   editor can force (the printers emit it with the data-force-state
   *   dual), an at-rule condition a canvas or viewport pose. Where an
   *   element sits among its siblings is never a condition: position is
   *   selector text, a qualifier of the compound it stands on
   *   (RelativeSelector's position pseudo-classes, "&:first-child",
   *   "> *:not(:last-child)").
   */
  public function getConditions(): array;

}
