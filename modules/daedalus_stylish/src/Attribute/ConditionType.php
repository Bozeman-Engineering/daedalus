<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The ConditionType attribute.
 *
 * A condition type provides named CONDITIONS — the WHEN axis of a style
 * entry: "hover", "narrow", "dark". Each condition maps to a CSS
 * wrapper the printer applies around the condition's declarations: a
 * pseudo-class suffix on the target selector, or an at-rule the rule nests
 * inside (@container, @media). Condition names are the vocabulary the style
 * map stores under, the validator accepts, and the AI schema documents —
 * one plugin powers all three.
 *
 * Every condition lives on one AXIS (width, scheme, motion, state): axes
 * are the compound-condition lanes — a style entry may key on an "a+b"
 * compound holding at most one condition per axis ("narrow+dark"), exactly
 * as variants compose across variant axes. Conditions sharing an axis are
 * alternatives, never combinables.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ConditionType extends AttributeBase {

  /**
   * Constructs a new ConditionType instance.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The condition type's human label ("Container breakpoint").
   * @param string $axis
   *   The axis this type's conditions live on ("width", "scheme", "motion",
   *   "state"). A compound condition holds at most one condition per axis,
   *   and the canonical part order is axis-declaration order — the order
   *   axes first appear across the (weight, id)-ordered condition types.
   * @param int $weight
   *   Order among condition types. When two types declare the same condition
   *   name, the first in (weight, id) order owns it.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $axis,
    public readonly int $weight = 0,
  ) {}

}
