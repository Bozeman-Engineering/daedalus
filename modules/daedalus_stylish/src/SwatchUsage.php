<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

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
   * The var() reference scan, shared with the validator's reference checks.
   *
   * The ONE value-level grammar (checkTokenReferences() spells the same
   * expression): every custom property a var() opens with, fallbacks and
   * whitespace tolerated. valueSwatches() and rewriteValue() are the two
   * directions of this grammar: the scan reads references out of a value,
   * the rewrite writes them away.
   */
  protected const VAR_PATTERN = '/var\(\s*(--[A-Za-z0-9_-]+)/';

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
   * Token values are scalars or condition-keyed scalar maps ({scale:
   * {name: value}}), one level flatter than style entries.
   */
  protected function scanTokens(array $tokens): array {
    $names = [];
    foreach ($tokens as $scale_names) {
      if (!is_array($scale_names)) {
        continue;
      }
      foreach ($scale_names as $value) {
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
   * Reads through the one value-level grammar (VAR_PATTERN) and the one
   * namespace rule (StyleGuide::swatchReference()), so a var() outside the
   * reserved namespace never counts and a name is extracted exactly the
   * way validation and delivery would resolve it.
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
   * The write direction of the value-level grammar, the delete-in-use
   * batch's one substitution rule: every var() whose custom property is
   * the swatch's variable is replaced WHOLE (fallback included, since the
   * reference is gone, not unresolved) by the literal, and var()
   * expressions referencing anything else are preserved byte-for-byte,
   * their fallbacks rewritten recursively (a fallback may itself reference
   * the swatch). Unbalanced parentheses end the scan and the remainder
   * passes through verbatim: a malformed value is the author's, never
   * silently reshaped.
   */
  public static function rewriteValue(string $value, string $machine_name, string $literal): string {
    if (!str_contains($value, 'var(')) {
      return $value;
    }
    $variable = StyleGuide::swatchVariable($machine_name);
    $result = '';
    $offset = 0;
    $length = strlen($value);
    while (($position = strpos($value, 'var(', $offset)) !== FALSE) {
      $depth = 0;
      $close = NULL;
      for ($i = $position + 3; $i < $length; $i++) {
        if ($value[$i] === '(') {
          $depth++;
        }
        elseif ($value[$i] === ')' && --$depth === 0) {
          $close = $i;
          break;
        }
      }
      if ($close === NULL) {
        break;
      }
      $inner = substr($value, $position + 4, $close - $position - 4);
      $result .= substr($value, $offset, $position - $offset);
      $head = trim(explode(',', $inner, 2)[0]);
      $result .= $head === $variable
        ? $literal
        : 'var(' . self::rewriteValue($inner, $machine_name, $literal) . ')';
      $offset = $close + 1;
    }
    return $result . substr($value, $offset);
  }

}
