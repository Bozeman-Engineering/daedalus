<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * The daedalus_animation_usage table: which entities wear which animations.
 *
 * ValueUsageBase's ident twin. An animation is referenced as a bare
 * keyframes name in a property-scoped slot (`animation-name:
 * motion-{name}`, or a name component of the `animation` shorthand)
 * rather than a self-delimiting var(), so the scan is property-aware:
 * KeyframesNames::inValue() finds the names a value carries for its
 * property and StyleGuide::animationReference() keeps the ones in the
 * motion namespace (a theme's own keyframes is no site animation). Skin
 * TOKENS are not a source: a token value has no property, so it cannot
 * carry a keyframes name the spine can read (the property-scoped slot
 * decision). The animation column stores the machine name verbatim as the
 * value's motion-{name} ident spells it.
 */
class AnimationUsage extends ValueUsageBase {

  /**
   * The usage table name.
   */
  public const TABLE = 'daedalus_animation_usage';

  /**
   * The table's material column.
   */
  public const COLUMN = 'animation';

  /**
   * {@inheritdoc}
   */
  protected function scanValue(string $property, string $value): array {
    return self::valueAnimations($property, $value);
  }

  /**
   * The animation machine names one CSS value references (the scan direction).
   *
   * Reads through the one property-scoped grammar (KeyframesNames) and the
   * one namespace rule (StyleGuide::animationReference()), so a name
   * outside the motion namespace (a theme's own keyframes) never counts
   * and a name is extracted exactly the way validation and delivery would
   * resolve it. A value for any other property references nothing.
   *
   * @return string[]
   *   The referenced machine names, verbatim as the value spells them.
   */
  public static function valueAnimations(string $property, string $value): array {
    $names = [];
    foreach (KeyframesNames::inValue($property, $value) as $ident) {
      $name = StyleGuide::animationReference($ident);
      if ($name !== NULL) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * Rewrites one animation's references in a CSS value to `none`.
   *
   * The write direction of the value-level grammar, the delete-in-use
   * batch's one substitution rule: every name component spelling the
   * animation (StyleGuide::animationName()) becomes `none` in place, in
   * whichever position the property's grammar puts it, and everything else
   * in the value (other names, times, easings, the comma structure) is
   * preserved byte for byte (KeyframesNames::replaceName()). `none` is the
   * honest residue: the element keeps its timing declarations and simply
   * animates nothing, exactly what a stale reference would have rendered.
   */
  public static function rewriteValue(string $property, string $value, string $machine_name): string {
    return KeyframesNames::replaceName($property, $value, StyleGuide::animationName($machine_name), 'none');
  }

}
