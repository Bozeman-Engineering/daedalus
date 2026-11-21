<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The emit gate: the one judgment of text bound for a style element.
 *
 * Everything the CSS printers write that began as stored text (a
 * declaration value, a condition's at-rule, a selector with its attribute
 * strings, a font family name) passes here first, and what the gate
 * refuses is dropped, never repaired. The gate is charset and structure
 * hygiene, not vocabulary (the validator owns vocabulary), and it holds
 * for text no validator ever saw: a map saved around the spine, imported
 * content, a variant kept in config.
 *
 * What it admits:
 * - no `;`, `{`, `}`, `<` or `>` and no control character but tab, line
 *   feed and carriage return, anywhere, strings included (a selector
 *   keeps `>`, its child combinator; the selector grammar prints every
 *   such character of an attribute value as a hex escape, so a string
 *   never needs one raw);
 * - every string closed on its own line, every `(` and `[` closed by its
 *   own partner in order, nothing closed that was not opened;
 * - no backslash and no comment outside a string (either would let text
 *   read differently to the browser than it reads here);
 * - `url()` only in the two spellings the module itself writes: the
 *   media token (`url('media:<uuid>')`, quotes free, as ValueGrammar and
 *   MediaToken spell it) and the resolved file the media resolver puts in
 *   its place (`url("/path")`, double-quoted, a path on this site, never
 *   another host), wherever the call stands, `var()` and `calc()`
 *   included; and no other function that fetches what its argument names
 *   (LOADERS).
 *
 * Pure string logic with a byte-locked client twin
 * (daedalus_ui/js/edit_mode/materials/emit-gate.js): every character the
 * gate reads is ASCII, so bytes here and UTF-16 units there answer alike.
 * EmitGateTest's battery (daedalus_ui/tests/js/emit-gate-cases.mjs) locks
 * the two. Change this class and the twin in the same commit.
 */
final class EmitGate {

  /**
   * The characters no admitted text carries.
   */
  private const FORBIDDEN = '/[;{}<>\x00-\x08\x0B\x0C\x0E-\x1F]/';

  /**
   * The same, for a selector: `>` is its child combinator.
   */
  private const FORBIDDEN_IN_SELECTOR = '/[;{}<\x00-\x08\x0B\x0C\x0E-\x1F]/';

  /**
   * A media token standing at the offset.
   */
  private const MEDIA_TOKEN_AT = '/\Gurl\([ \t\n\r]*(["\']?)media:[0-9A-Za-z-]+\1[ \t\n\r]*\)/i';

  /**
   * A resolved file URL standing at the offset: a path on this site.
   */
  private const RESOLVED_URL_AT = '/\Gurl\("\/(?![\/\\\\])[^"\'()\\\\ \t\n\r]*"\)/i';

  /**
   * The functions, beside url(), that fetch what their argument names.
   */
  private const LOADERS = ['image-set', '-webkit-image-set', 'image', 'src'];

  /**
   * Whether text may print as a value, a condition or a name.
   */
  public static function admits(string $text): bool {
    return !preg_match(self::FORBIDDEN, $text) && self::balanced($text);
  }

  /**
   * Whether text may print as a selector.
   */
  public static function admitsSelector(string $text): bool {
    return !preg_match(self::FORBIDDEN_IN_SELECTOR, $text) && self::balanced($text);
  }

  /**
   * Whether the text's strings, brackets and calls all close as they open.
   */
  private static function balanced(string $text): bool {
    $open = [];
    $length = strlen($text);
    $at = 0;
    while ($at < $length) {
      $char = $text[$at];
      if ($char === '"' || $char === "'") {
        $at = self::stringEnd($text, $at);
        if ($at === NULL) {
          return FALSE;
        }
        continue;
      }
      if ($char === '\\' || ($char === '/' && ($text[$at + 1] ?? '') === '*')) {
        return FALSE;
      }
      if ($char === '(') {
        $start = $at;
        while ($start > 0 && preg_match('/[A-Za-z0-9_-]/', $text[$start - 1])) {
          $start--;
        }
        $name = strtolower(substr($text, $start, $at - $start));
        if ($name === 'url') {
          if (!preg_match(self::MEDIA_TOKEN_AT, $text, $match, 0, $start) && !preg_match(self::RESOLVED_URL_AT, $text, $match, 0, $start)) {
            return FALSE;
          }
          $at = $start + strlen($match[0]);
          continue;
        }
        if (in_array($name, self::LOADERS, TRUE)) {
          return FALSE;
        }
        $open[] = ')';
      }
      elseif ($char === '[') {
        $open[] = ']';
      }
      elseif (($char === ')' || $char === ']') && array_pop($open) !== $char) {
        return FALSE;
      }
      $at++;
    }
    return $open === [];
  }

  /**
   * The offset just past the string opening at an offset, or NULL.
   *
   * NULL when the string does not close: at the end of the text or at a
   * line break (an escaped one continues the string, as in CSS).
   */
  private static function stringEnd(string $text, int $at): ?int {
    $quote = $text[$at];
    $length = strlen($text);
    for ($at++; $at < $length; $at++) {
      $char = $text[$at];
      if ($char === '\\') {
        $at++;
      }
      elseif ($char === "\n" || $char === "\r") {
        return NULL;
      }
      elseif ($char === $quote) {
        return $at + 1;
      }
    }
    return NULL;
  }

}
