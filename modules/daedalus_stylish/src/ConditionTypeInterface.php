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
 * plugin.
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
   *   - band: (array, optional) the structured twin of a width wrapper the
   *     edit-mode canvas can pose: the band's inclusive px edges as
   *     {min?: int, max?: int}, a missing min extending to zero, a missing
   *     max upward without bound. Declared only for bands the page-level
   *     query container measures (@container wrappers): the canvas stage
   *     re-evaluates those when it narrows, so the ruler marks them and
   *     "drive the canvas there" poses their midpoint. A viewport (@media)
   *     width condition declares none; it answers to the browser window,
   *     which the canvas cannot pose.
   *   - preference: (array, optional) the structured twin of a user
   *     preference wrapper: {feature: string, value: string}, the media
   *     feature and the value the wrapper tests ("prefers-color-scheme",
   *     "dark"). The canvas emulation (daedalus_ui's vector) forces a
   *     preference value per axis; the client matches the forced value
   *     against this fact, never against the wrapper text.
   *   - fact: (bool, optional) TRUE when the condition is a structural
   *     FACT rather than a posable state: it holds or not by the
   *     element's place in the markup (":first-child", ":empty"), so no
   *     editor gesture can make it true. The printers emit a fact's
   *     pseudo-class wrapper bare (no data-force-state dual), the pose
   *     model never derives it from the canvas vector or offers it as a
   *     chip pose, and the code view lights a fact's row when the
   *     selection satisfies it. Only meaningful on a pseudo-class wrapper.
   */
  public function getConditions(): array;

}
