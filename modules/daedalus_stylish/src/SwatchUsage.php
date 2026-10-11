<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * The daedalus_swatch_usage table: which entities reference which swatches.
 *
 * ValueUsageBase's var() twin. A swatch is referenced as
 * var(--swatch-{name}) anywhere a value string appears, self-delimiting
 * and property-blind, so every scalar in the three stores is scanned the
 * same way, skin TOKENS included: a token value has no property, and a
 * var() needs none. Unlike variants (machine name or uuid, two lookup
 * forms), a swatch has exactly ONE reference form: the var() custom
 * property tail IS the machine name, and the swatch column stores it
 * verbatim.
 */
class SwatchUsage extends ValueUsageBase {

  /**
   * The usage table name.
   */
  public const TABLE = 'daedalus_swatch_usage';

  /**
   * The table's material column.
   */
  public const COLUMN = 'swatch';

  /**
   * {@inheritdoc}
   *
   * A var() means the same thing under every property.
   */
  protected function scanValue(string $property, string $value): array {
    return self::valueSwatches($value);
  }

  /**
   * {@inheritdoc}
   *
   * Token values are scalars or condition-keyed scalar maps inside the
   * stored records ({scale: {name: {value, ...}}}), one level flatter
   * than style entries.
   */
  protected function scanTokens(array $tokens): array {
    $names = [];
    foreach (SkinTokensItem::statedValues($tokens) as $scale_values) {
      foreach ($scale_values as $value) {
        foreach (is_array($value) ? $value : [$value] as $conditioned) {
          if (is_scalar($conditioned)) {
            array_push($names, ...self::valueSwatches((string) $conditioned));
          }
        }
      }
    }
    return $names;
  }

  /**
   * The swatch names one CSS value references (the scan direction).
   *
   * Reads through the one value-level grammar (ValueUsageBase::VAR_PATTERN)
   * and the one namespace rule (StyleGuide::swatchReference()), so a var()
   * outside the reserved namespace never counts and a name is extracted
   * exactly the way validation and delivery would resolve it.
   *
   * @return string[]
   *   The referenced names, verbatim as the value spells them.
   */
  public static function valueSwatches(string $value): array {
    if (!str_contains($value, 'var(')) {
      return [];
    }
    preg_match_all(self::VAR_PATTERN, $value, $matches);
    $names = [];
    foreach ($matches[1] as $variable) {
      $name = StyleGuide::swatchReference($variable);
      if ($name !== NULL) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * Rewrites one swatch's var() references in a CSS value to a literal.
   *
   * The write direction of the value-level grammar
   * (ValueUsageBase::rewriteVariable()), bound to the swatch namespace
   * rule: the swatch's custom property derives through
   * StyleGuide::swatchVariable(), the same way delivery spells it.
   */
  public static function rewriteValue(string $value, string $machine_name, string $literal): string {
    return static::rewriteVariable($value, StyleGuide::swatchVariable($machine_name), $literal);
  }

}
