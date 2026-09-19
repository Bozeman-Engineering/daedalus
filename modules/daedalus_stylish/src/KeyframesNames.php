<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * Finds the keyframes names a motion value references.
 *
 * The property-scoped half of the animation reference grammar. A keyframes
 * name is a bare custom-ident, and a bare ident means different things per
 * property (anchor-name, container-name and transition-property take
 * idents too), so the materials spine's generic style slot never scans for
 * one; consumers that KNOW the property is animation-name or the animation
 * shorthand ask here, then resolve each name through
 * StyleGuide::animationReference() (a motion-{name} ident is a site
 * animation, anything else a theme's own keyframes).
 *
 * Two grammars, one per property:
 * - animation-name: a comma list of names; each component is a name unless
 *   it is `none`, a CSS-wide keyword, or not an ident (a var() reference,
 *   garbage the group validator refuses).
 * - animation: a comma list of layers; per CSS Animations, a keyword that
 *   is valid for another longhand (easing, direction, fill mode, play
 *   state, `infinite`, `auto`, `none`) belongs to that longhand, and the
 *   first remaining ident is the layer's name. Times, counts and
 *   functions (cubic-bezier(), steps(), var()) are never names.
 * Quoted strings are names too (spec: <keyframes-name> = <custom-ident> |
 * <string>), returned without their quotes.
 *
 * PHP is authoritative; js/src/keyframes-names.js is its client twin, and
 * KeyframesNamesTest locks the two over tests/js/keyframes-cases.mjs.
 * Change them together. The rewrite direction (replaceName()) is
 * server-only: deletion rewrites references in a batch, and the client
 * never rewrites a value it did not author.
 */
final class KeyframesNames {

  /**
   * The properties whose values carry keyframes names.
   */
  public const PROPERTIES = ['animation-name', 'animation'];

  /**
   * CSS-wide keywords: whole-value pass-throughs, never names.
   */
  private const CSS_WIDE_KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer'];

  /**
   * Keywords the animation shorthand's other longhands claim first.
   *
   * Those are animation-timing-function, animation-direction,
   * animation-fill-mode, animation-play-state, animation-iteration-count
   * (`infinite`) and animation-duration (`auto`), plus `none`, which is never a
   * reference whichever longhand wins it.
   */
  private const SHORTHAND_KEYWORDS = [
    'none',
    'ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end',
    'normal', 'reverse', 'alternate', 'alternate-reverse',
    'forwards', 'backwards', 'both',
    'running', 'paused',
    'infinite', 'auto',
  ];

  /**
   * The custom-ident shape (PropertyGroupBase::validateIdent()'s grammar).
   */
  private const IDENT = '/^-?[A-Za-z_][A-Za-z0-9_-]*$/';

  /**
   * The keyframes names a value references, in order, deduplicated.
   *
   * @param string $property
   *   The CSS property the value is written for.
   * @param string $value
   *   The value as written.
   *
   * @return string[]
   *   The names; [] for any property outside PROPERTIES.
   */
  public static function inValue(string $property, string $value): array {
    if (!in_array($property, self::PROPERTIES, TRUE)) {
      return [];
    }
    $names = [];
    foreach (self::split($value, ',') as $layer) {
      $name = $property === 'animation-name'
        ? self::nameComponent(trim($layer), [...self::CSS_WIDE_KEYWORDS, 'none'])
        : self::shorthandName($layer);
      if ($name !== NULL && !in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * Rewrites one keyframes name in a motion value, in place.
   *
   * The write direction of the grammar inValue() reads (the swatch usage's
   * scan/rewrite pairing at ident scope): every name component equal to
   * $name, in whichever position the property's grammar puts it, becomes
   * $replacement, and every other byte of the value (other names, times,
   * easings, whitespace, the comma structure, a malformed remainder)
   * passes through verbatim. A quoted spelling of the name rewrites too:
   * it names the same keyframes. Values for properties outside PROPERTIES
   * return unchanged.
   *
   * @param string $property
   *   The CSS property the value is written for.
   * @param string $value
   *   The value as written.
   * @param string $name
   *   The keyframes name to rewrite, as it delivers (`motion-fade_in`).
   * @param string $replacement
   *   What the name becomes (`none`).
   */
  public static function replaceName(string $property, string $value, string $name, string $replacement): string {
    if (!in_array($property, self::PROPERTIES, TRUE)) {
      return $value;
    }
    $result = '';
    foreach (self::tokenize($value, ',') as [$layer, $separator]) {
      $result .= ($property === 'animation-name'
        ? self::replaceComponent($layer, [...self::CSS_WIDE_KEYWORDS, 'none'], $name, $replacement)
        : self::replaceShorthandName($layer, $name, $replacement)) . $separator;
    }
    return $result;
  }

  /**
   * Rewrites the name component of one animation shorthand layer.
   *
   * The first token that is a name (shorthandName()'s rule) is the layer's
   * name; it rewrites when it matches and every token, matching or not,
   * keeps its place and its whitespace.
   */
  private static function replaceShorthandName(string $layer, string $name, string $replacement): string {
    $result = '';
    $found = FALSE;
    foreach (self::tokenize($layer, " \t\n\r\f") as [$token, $separator]) {
      if (!$found && self::nameComponent($token, [...self::CSS_WIDE_KEYWORDS, ...self::SHORTHAND_KEYWORDS]) !== NULL) {
        $found = TRUE;
        $token = self::replaceComponent($token, [...self::CSS_WIDE_KEYWORDS, ...self::SHORTHAND_KEYWORDS], $name, $replacement);
      }
      $result .= $token . $separator;
    }
    return $result;
  }

  /**
   * Rewrites one component when it reads as the given name.
   *
   * Surrounding whitespace is kept: the component is a comma-list piece
   * (`animation-name`) or a whitespace-free token (the shorthand), and in
   * both cases the bytes around the name are the author's.
   */
  private static function replaceComponent(string $component, array $excluded, string $name, string $replacement): string {
    preg_match('/^(\s*)(.*?)(\s*)$/s', $component, $matches);
    return self::nameComponent($matches[2], $excluded) === $name
      ? $matches[1] . $replacement . $matches[3]
      : $component;
  }

  /**
   * The name component of one animation shorthand layer, or NULL.
   */
  private static function shorthandName(string $layer): ?string {
    foreach (self::split($layer, " \t\n\r\f") as $token) {
      $name = self::nameComponent($token, [...self::CSS_WIDE_KEYWORDS, ...self::SHORTHAND_KEYWORDS]);
      if ($name !== NULL) {
        return $name;
      }
    }
    return NULL;
  }

  /**
   * Reads one token as a keyframes name, or NULL when it is not one.
   *
   * @param string $token
   *   A trimmed token.
   * @param string[] $excluded
   *   Lowercase keywords that are not names in this position.
   */
  private static function nameComponent(string $token, array $excluded): ?string {
    if ($token === '') {
      return NULL;
    }
    if (preg_match('/^(["\'])(.*)\1$/s', $token, $matches)) {
      return $matches[2] === '' ? NULL : $matches[2];
    }
    if (in_array(strtolower($token), $excluded, TRUE) || !preg_match(self::IDENT, $token)) {
      return NULL;
    }
    return $token;
  }

  /**
   * Splits a value at top-level separators.
   *
   * Parenthesized groups and quoted strings stay intact, so function
   * notation keeps its inner commas and spaces and a quoted name keeps its
   * whitespace. Empty pieces are dropped: the group validator owns list
   * hygiene, this only finds names.
   *
   * @param string $value
   *   The value.
   * @param string $separators
   *   The separator characters, each one splitting.
   *
   * @return string[]
   *   The pieces, untrimmed.
   */
  private static function split(string $value, string $separators): array {
    return array_values(array_filter(
      array_column(self::tokenize($value, $separators), 0),
      static fn (string $piece): bool => $piece !== '',
    ));
  }

  /**
   * Tokenizes a value at top-level separators, keeping every byte.
   *
   * The lossless walk behind split() (which drops empty pieces) and the
   * rewrite (which must reassemble the value verbatim): each pair is a
   * piece and the separator character that ended it ('' for the last),
   * so concatenating the pairs in order reproduces the input exactly.
   *
   * @return array<int, array{string, string}>
   *   [piece, separator] pairs, pieces untrimmed and possibly empty.
   */
  private static function tokenize(string $value, string $separators): array {
    $pairs = [];
    $current = '';
    $depth = 0;
    $quote = '';
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($quote !== '') {
        if ($char === $quote) {
          $quote = '';
        }
      }
      elseif ($char === '"' || $char === "'") {
        $quote = $char;
      }
      elseif ($char === '(') {
        $depth++;
      }
      elseif ($char === ')') {
        $depth = max(0, $depth - 1);
      }
      elseif ($depth === 0 && str_contains($separators, $char)) {
        $pairs[] = [$current, $char];
        $current = '';
        continue;
      }
      $current .= $char;
    }
    $pairs[] = [$current, ''];
    return $pairs;
  }

}
