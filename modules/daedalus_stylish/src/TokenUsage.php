<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * The daedalus_token_usage table: which entities reference which tokens.
 *
 * ValueUsageBase's twin for the material that lives on the skin. A token
 * delivers as a custom property (StyleGuide::tokenVariable(),
 * --{prefix}-{name}) and is referenced as var() of it anywhere a value
 * string appears, self-delimiting and property-blind, so every scalar in
 * the three stores is scanned the same way, skin TOKENS included (a token
 * may reference another token of its skin). The token column stores the
 * custom property verbatim as the var() spells it ("--space-4"), never
 * resolved to a scale and name: which scale a variable belongs to is the
 * guide's answer (the scale's prefix), and the guide can change while the
 * saved values do not, so a resolved row would go stale where a verbatim
 * one stays a pure function of the values. The one namespace the table
 * leaves out is the swatch namespace ("--swatch-"), reserved before any
 * scale speaks: a var() of it is a swatch reference (SwatchUsage), never
 * a token's. Every other custom property is tracked, a theme's own
 * included; such a row simply matches no token's variable.
 *
 * Lookups (the usage count behind the palette card, the delete-in-use
 * work list) key on the token's derived variable, which the token manager
 * resolves through the guide (TokenManager::variable()).
 */
class TokenUsage extends ValueUsageBase {

  /**
   * The usage table name.
   */
  public const TABLE = 'daedalus_token_usage';

  /**
   * The table's material column.
   */
  public const COLUMN = 'token';

  /**
   * {@inheritdoc}
   *
   * A var() means the same thing under every property.
   */
  protected function scanValue(string $property, string $value): array {
    return self::valueVariables($value);
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
            array_push($names, ...self::valueVariables((string) $conditioned));
          }
        }
      }
    }
    return $names;
  }

  /**
   * The custom properties one CSS value references outside the swatch namespace.
   *
   * Reads through the one value-level grammar (ValueUsageBase::VAR_PATTERN)
   * and leaves the swatch namespace to SwatchUsage
   * (StyleGuide::swatchReference()), so a variable is a swatch reference
   * or a token reference, never both.
   *
   * @return string[]
   *   The custom properties, leading dashes included, verbatim as the
   *   value spells them.
   */
  public static function valueVariables(string $value): array {
    if (!str_contains($value, 'var(')) {
      return [];
    }
    preg_match_all(self::VAR_PATTERN, $value, $matches);
    return array_values(array_filter(
      $matches[1],
      static fn (string $variable): bool => StyleGuide::swatchReference($variable) === NULL,
    ));
  }

  /**
   * Respells one custom property's var() references in a CSS value.
   *
   * The rename's rewrite: the variable inside every var() that opens with
   * it becomes the new variable and nothing else moves, the fallback
   * included (the reference is not gone, it has a new name). The scan's
   * grammar guarantees the match is the whole property name: what follows
   * it is the expression's comma or its closing parenthesis.
   */
  public static function renameValue(string $value, string $variable, string $replacement): string {
    if (!str_contains($value, 'var(')) {
      return $value;
    }
    return preg_replace(
      '/(var\(\s*)' . preg_quote($variable, '/') . '(?=\s*[,)])/',
      '${1}' . $replacement,
      $value,
    );
  }

}
