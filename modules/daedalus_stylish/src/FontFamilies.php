<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * Finds the font families a font-family value references.
 *
 * The property-scoped half of the font reference grammar, KeyframesNames'
 * twin for the type material. A family is a string component of a
 * font-family stack, and the spine's reference to a minted font is its
 * QUOTED name (StyleGuide::fontFamily(), the one derivation rule: the
 * compiled @font-face declares "Inter", the roster spells "Inter", and a
 * stack names "Inter"). So the reference form is the quoted component,
 * exactly as motion-{name} is the animation reference form: an unquoted
 * ident sequence (Georgia, Helvetica Neue, a generic family, a CSS-wide
 * keyword) is a theme's or the system's own family, which the spine does
 * not curate and the usage table does not count, and a function (var())
 * is never a name. Consumers that KNOW the property is font-family ask
 * here; the generic style slot never scans for a family.
 *
 * Quoted strings unescape per CSS (`\"` and `\\` inside double quotes,
 * `\'` inside single quotes), so a family the validator admitted with a
 * quote in it round-trips through the compiled spelling.
 *
 * Server-only: the client never scans or rewrites families (the Fonts tab
 * reads usage from the server, and deletion rewrites references in a
 * batch).
 */
final class FontFamilies {

  /**
   * The properties whose values carry family names.
   *
   * The longhand alone: no property group admits the font shorthand, so a
   * family can enter the spine only through font-family (and through a
   * skin token on the scale bound to it, which FontUsage reads as a
   * font-family value).
   */
  public const PROPERTIES = ['font-family'];

  /**
   * The residue of a stack whose every component named the deleted font.
   *
   * The honest residue: a stale reference to a family no @font-face
   * declares renders in the user agent's default family, which is exactly
   * what the initial value of font-family names.
   */
  public const EMPTY_STACK = 'initial';

  /**
   * The families a value references, in order, deduplicated.
   *
   * @param string $property
   *   The CSS property the value is written for.
   * @param string $value
   *   The value as written.
   *
   * @return string[]
   *   The families, unquoted and unescaped; [] for any property outside
   *   PROPERTIES.
   */
  public static function inValue(string $property, string $value): array {
    if (!in_array($property, self::PROPERTIES, TRUE)) {
      return [];
    }
    $families = [];
    foreach (self::split($value) as $component) {
      $family = self::quotedFamily($component);
      if ($family !== NULL && !in_array($family, $families, TRUE)) {
        $families[] = $family;
      }
    }
    return $families;
  }

  /**
   * Removes one family's components from a value, in place.
   *
   * The write direction of the grammar inValue() reads, the delete-in-use
   * batch's one substitution rule: every quoted component naming the
   * family leaves the stack, every other component keeps its bytes and
   * its position (the fallbacks the author wrote are what the element
   * falls back to), and a stack left with nothing becomes EMPTY_STACK.
   * Values for properties outside PROPERTIES return unchanged.
   *
   * @param string $property
   *   The CSS property the value is written for.
   * @param string $value
   *   The value as written.
   * @param string $family
   *   The family to remove, bare ("Inter").
   */
  public static function removeFamily(string $property, string $value, string $family): string {
    if (!in_array($property, self::PROPERTIES, TRUE)) {
      return $value;
    }
    $kept = [];
    $removed = FALSE;
    foreach (self::split($value) as $component) {
      if (self::quotedFamily($component) === $family) {
        $removed = TRUE;
        continue;
      }
      $kept[] = $component;
    }
    if (!$removed) {
      return $value;
    }
    if ($kept === []) {
      return self::EMPTY_STACK;
    }
    $kept[0] = ltrim($kept[0]);
    $kept[array_key_last($kept)] = rtrim($kept[array_key_last($kept)]);
    return implode(',', $kept);
  }

  /**
   * Splits a value on its top-level commas, quotes and parentheses honored.
   *
   * @return string[]
   *   The components, verbatim (their surrounding whitespace included).
   */
  private static function split(string $value): array {
    $components = [];
    $depth = 0;
    $quote = NULL;
    $start = 0;
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($quote !== NULL) {
        if ($char === '\\') {
          $i++;
        }
        elseif ($char === $quote) {
          $quote = NULL;
        }
        continue;
      }
      if ($char === '"' || $char === "'") {
        $quote = $char;
      }
      elseif ($char === '(') {
        $depth++;
      }
      elseif ($char === ')') {
        $depth = max(0, $depth - 1);
      }
      elseif ($char === ',' && $depth === 0) {
        $components[] = substr($value, $start, $i - $start);
        $start = $i + 1;
      }
    }
    $components[] = substr($value, $start);
    return $components;
  }

  /**
   * The family a quoted component names, or NULL for any other component.
   */
  private static function quotedFamily(string $component): ?string {
    $component = trim($component);
    if (strlen($component) < 2) {
      return NULL;
    }
    $quote = $component[0];
    if (($quote !== '"' && $quote !== "'") || $component[-1] !== $quote) {
      return NULL;
    }
    $inner = substr($component, 1, -1);
    // A quote closing early means the component is not one string.
    if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*' . $quote . '/', $inner)) {
      return NULL;
    }
    $family = preg_replace('/\\\\(.)/s', '$1', $inner);
    return $family === NULL || trim($family) === '' ? NULL : $family;
  }

}
