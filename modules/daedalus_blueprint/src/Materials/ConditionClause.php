<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The condition clause grammar: the one reader of a condition key.
 *
 * A style entry's condition key is a COMPOUND of clauses, one per axis,
 * joined by "+" ("hover+narrow+dark"). A clause is one of:
 * - a REFERENCE: the name of a condition the roster publishes ("hover",
 *   "narrow", "dark"), whose CSS the site owns (a threshold change in the
 *   breakpoints settings rewrites no map, as a token reference's value is
 *   the skin's);
 * - a LITERAL the browser knows: a media or container feature query with
 *   its at-rule prefix ("@media (hover: hover)", "@container (min-width:
 *   500px)", "@media (prefers-contrast: more)"), a "@supports" test, or a
 *   pseudo-class the roster does not publish (":checked");
 * - UNKNOWN: a word that is neither ("bogus", "default", "variants"); the
 *   grammar never invents or drops anything, and whether an unknown word
 *   is an error is the validator's verdict.
 *
 * The grammar is a parser, not an explode: a literal carries spaces,
 * colons and parentheses, so the compound splits at its TOP-LEVEL "+"
 * only (outside parentheses). A literal's axis is its feature: the width
 * features ("width", "min-width", "max-width") are the "width" axis, a
 * feature a published condition's wrapper also tests shares that
 * condition's axis ("(prefers-color-scheme: light)" is "scheme" beside
 * "dark"), any other feature is its own axis by name ("hover",
 * "prefers-contrast"), a "@supports" test is "supports" and a pseudo-class
 * "state". A reference's axis is the one its plugin declares. A literal
 * spelling a published wrapper (case, spacing and quotes folded) IS that
 * reference: "@container (max-width: 767px)" reads as "narrow", so one
 * condition has one spelling in every map.
 *
 * The structured twins the canvas poses are DERIVED from a clause's CSS, for
 * references and literals alike, never declared: `band` {min?, max?} is the
 * inclusive px edges of a @container width query (a @media width query answers
 * to the browser viewport, which the canvas cannot pose, so it derives none; a
 * non-px edge derives none), `preference` {feature, value} the user preference
 * a single-feature @media "prefers-*" query tests. The client twin
 * (daedalus_ui/js/edit_mode/materials/condition-clause.js) derives the same
 * facts from the guide payload's wrapper strings; the shared battery
 * daedalus_ui/tests/js/condition-clause-cases.mjs locks both sides to one
 * record.
 *
 * Canonical order: published axes by their first appearance in the table
 * (the (weight, id)-ordered condition types), then literal axes the table
 * does not know alphabetically, then unknown words in spelled order; ties
 * keep spelled order. Validity (an unknown word, two clauses on one axis,
 * a literal on a strict site) is the validator's, never the grammar's.
 *
 * A clause record is {kind, text, form, axis, css, band, preference}:
 * `text` the clause as a key spells it (a reference's name, a literal's
 * canonical CSS), `form` how its CSS wraps a rule ("pseudo" a suffix on
 * the target, "media" / "container" / "supports" an at-rule), `css` the
 * wrapper itself (a reference's published wrapper, a literal's own text,
 * NULL for an unknown word).
 */
final class ConditionClause {

  /**
   * The delimiter joining a compound's clauses.
   */
  public const DELIMITER = '+';

  /**
   * Clause kinds.
   */
  public const REFERENCE = 'reference';
  public const LITERAL = 'literal';
  public const UNKNOWN = 'unknown';

  /**
   * Clause forms: how the clause's CSS wraps a rule.
   */
  public const PSEUDO = 'pseudo';
  public const MEDIA = 'media';
  public const CONTAINER = 'container';
  public const SUPPORTS = 'supports';

  /**
   * The axes the grammar names itself.
   */
  public const WIDTH_AXIS = 'width';
  public const STATE_AXIS = 'state';
  public const SUPPORTS_AXIS = 'supports';

  /**
   * The pseudo-class literals no stamp stands in for.
   *
   * A pseudo-class clause is posed by the force-state stamp (the printed
   * dual `:is(:x, [data-force-state~="x"])` holds while the stamp sits
   * on the element), which pretends a user action or an input state on
   * the element itself. The browser's history (`:visited`) and the URL's
   * fragment (`:target`) are not element states a stamp can pretend (the
   * real `:visited` even restricts what may be styled, so a pose would
   * show what the state never applies), so these print bare and are
   * never a chip pose. The survey's two (2026-10-23); revisable.
   */
  public const UNPOSABLE_STATES = ['visited', 'target'];

  /**
   * The width features, all on the width axis.
   */
  private const WIDTH_FEATURES = ['width', 'min-width', 'max-width'];

  /**
   * The prefix of the user preference media features.
   */
  private const PREFERENCE_PREFIX = 'prefers-';

  /**
   * Media query words that are keywords (case-insensitive, folded).
   *
   * Any other word in a query body is an identifier (a container name)
   * and keeps its case.
   */
  private const QUERY_KEYWORDS = ['and', 'or', 'not', 'only', 'all', 'screen', 'print', 'speech'];

  /**
   * Splits a compound into its clause texts, at the top-level "+" only.
   *
   * A "+" inside parentheses belongs to the literal it stands in
   * ("@supports (selector(a + b))"). Each clause is trimmed; a single
   * clause is the compound of one, and an empty compound is one empty
   * clause, so no caller ever sees nothing.
   *
   * @return string[]
   *   The clause texts, in spelled order.
   */
  public static function split(string $compound): array {
    $parts = [];
    $current = '';
    $depth = 0;
    $length = strlen($compound);
    for ($i = 0; $i < $length; $i++) {
      $char = $compound[$i];
      if ($char === '(') {
        $depth++;
      }
      elseif ($char === ')' && $depth > 0) {
        $depth--;
      }
      if ($char === self::DELIMITER && $depth === 0) {
        $parts[] = trim($current);
        $current = '';
        continue;
      }
      $current .= $char;
    }
    $parts[] = trim($current);
    return $parts;
  }

  /**
   * Parses a compound into its clause records, in spelled order.
   *
   * @param string $compound
   *   The condition key.
   * @param array $conditions
   *   The published condition table: name => {wrapper, axis, ...}
   *   (ConditionTypePluginManager::getConditions() or the client guide
   *   payload's rows; a non-array entry such as the payload's "default"
   *   string is no condition).
   *
   * @return array[]
   *   One clause record per clause.
   */
  public static function parse(string $compound, array $conditions): array {
    return array_map(static fn (string $text): array => self::clause($text, $conditions), self::split($compound));
  }

  /**
   * Reads one clause.
   *
   * @return array
   *   The clause record {kind, text, form, axis, css, band, preference}.
   */
  public static function clause(string $text, array $conditions): array {
    $text = trim($text);
    if (is_array($conditions[$text] ?? NULL)) {
      return self::reference($text, $conditions);
    }
    $read = self::read($text);
    if ($read === NULL) {
      return self::record(self::UNKNOWN, $text, NULL, NULL, NULL);
    }
    // A literal spelling a published wrapper is that reference: names are
    // preferred, so one condition has one spelling in every map.
    foreach ($conditions as $name => $definition) {
      if (!is_array($definition) || !is_string($definition['wrapper'] ?? NULL)) {
        continue;
      }
      $published = self::read($definition['wrapper']);
      if ($published !== NULL && $published['text'] === $read['text']) {
        return self::reference((string) $name, $conditions);
      }
    }
    $axis = self::literalAxis($read, $conditions);
    if ($axis === NULL) {
      return self::record(self::UNKNOWN, $text, NULL, NULL, NULL);
    }
    return self::record(self::LITERAL, $read['text'], $read['form'], $axis, $read['text']);
  }

  /**
   * The canonical spelling of a compound.
   *
   * Clauses sort into canonical order (see the class docblock) and each
   * prints as its clause text: a literal normalized, a reference and an
   * unknown word as spelled. Idempotent; never invents or drops a clause.
   */
  public static function canonical(string $compound, array $conditions): string {
    $order = array_flip(self::axisOrder($conditions));
    $sortable = [];
    foreach (self::parse($compound, $conditions) as $index => $clause) {
      $sortable[] = [self::rank($clause, $order), $index, $clause['text']];
    }
    usort($sortable, static fn (array $a, array $b): int => self::compareRanks($a[0], $b[0]) ?: $a[1] <=> $b[1]);
    return implode(self::DELIMITER, array_column($sortable, 2));
  }

  /**
   * The published axes, in first-appearance order over the table.
   *
   * The canonical compound order's spine (ConditionTypePluginManager::
   * conditionAxes() answers through here; the client twin is
   * conditionAxisOrder()).
   *
   * @return string[]
   *   The axis names, each once.
   */
  public static function axisOrder(array $conditions): array {
    $axes = [];
    foreach ($conditions as $definition) {
      if (is_array($definition) && is_string($definition['axis'] ?? NULL) && $definition['axis'] !== '') {
        $axes[$definition['axis']] = TRUE;
      }
    }
    return array_keys($axes);
  }

  /**
   * How a wrapper wraps a rule, by its prefix.
   *
   * @return string|null
   *   PSEUDO, MEDIA, CONTAINER or SUPPORTS; NULL for text of no known form.
   */
  public static function form(string $css): ?string {
    $css = trim($css);
    if (preg_match('/^@(media|container|supports)\b/i', $css, $matches)) {
      return strtolower($matches[1]);
    }
    if (str_starts_with($css, ':') && !str_starts_with($css, '::')) {
      return self::PSEUDO;
    }
    return NULL;
  }

  /**
   * The width band a wrapper's CSS poses, derived from its text.
   *
   * @return array|null
   *   {min?: int, max?: int}, the inclusive px edges of a @container width
   *   query ("(max-width: 767px)" is {max: 767}, "(min-width: 768px) and
   *   (max-width: 1023px)" is {min: 768, max: 1023}, "(width: 500px)" both
   *   edges); NULL for a @media width query (the viewport's, which the
   *   canvas cannot pose), a non-px edge, a query testing anything but
   *   width, or text of another form.
   */
  public static function band(string $css): ?array {
    $read = self::read($css);
    if ($read === NULL || $read['form'] !== self::CONTAINER) {
      return NULL;
    }
    $min = NULL;
    $max = NULL;
    foreach ($read['features'] as [$name, $value]) {
      if (!in_array($name, self::WIDTH_FEATURES, TRUE) || $value === NULL || !preg_match('/^(\d+)px$/', $value, $matches)) {
        return NULL;
      }
      $px = (int) $matches[1];
      if ($name !== 'max-width') {
        $min = $px;
      }
      if ($name !== 'min-width') {
        $max = $px;
      }
    }
    $band = [];
    if ($min !== NULL) {
      $band['min'] = $min;
    }
    if ($max !== NULL) {
      $band['max'] = $max;
    }
    return $band === [] ? NULL : $band;
  }

  /**
   * The user preference a wrapper's CSS tests, derived from its text.
   *
   * @return array|null
   *   {feature: string, value: string} for a single-feature @media query
   *   on a "prefers-*" feature with a value ("@media (prefers-color-scheme:
   *   dark)" is {feature: "prefers-color-scheme", value: "dark"}, a media
   *   type before it allowed); NULL otherwise.
   */
  public static function preference(string $css): ?array {
    $read = self::read($css);
    if ($read === NULL || $read['form'] !== self::MEDIA || count($read['features']) !== 1) {
      return NULL;
    }
    [$name, $value] = $read['features'][0];
    if ($value === NULL || !str_starts_with($name, self::PREFERENCE_PREFIX)) {
      return NULL;
    }
    return ['feature' => $name, 'value' => $value];
  }

  /**
   * The feature tests a wrapper's CSS makes, in spelled order.
   *
   * The validator's read of a literal against the wide condition tier:
   * each `(name: value)` or `(name)` test of a @media or @container query
   * as [name, value], the name and value lowercased as read() prints
   * them. A pseudo-class, a @supports test and text of no known form
   * make no feature test.
   *
   * @return array<int, array{0: string, 1: ?string}>
   *   The tests.
   */
  public static function features(string $css): array {
    return self::read($css)['features'] ?? [];
  }

  /**
   * Whether the canvas can pose a clause.
   *
   * The pose-ability line between a condition and a qualifier, asked of
   * one clause: an at-rule clause is posable when its CSS derives a band
   * (the ruler drives it) or a preference (the emulation vector matches
   * it); a `@supports` test and a viewport width are never posed; a
   * pseudo-class clause is posed by the force-state stamp, a published
   * one always (ConditionTypeInterface: every condition is posable), a
   * literal unless it names a state no stamp stands in for
   * (UNPOSABLE_STATES). The printers print a posable pseudo-class clause
   * as the stamp dual and an unposable one bare; the chip row offers a
   * posable literal in use as a chip on its axis. An unknown word is not
   * posable.
   *
   * @param array $clause
   *   A clause record (clause()).
   */
  public static function posable(array $clause): bool {
    if ($clause['kind'] === self::UNKNOWN) {
      return FALSE;
    }
    if ($clause['form'] === self::PSEUDO) {
      return $clause['kind'] === self::REFERENCE || !in_array(substr($clause['text'], 1), self::UNPOSABLE_STATES, TRUE);
    }
    return $clause['band'] !== NULL || $clause['preference'] !== NULL;
  }

  /**
   * The word the force-state stamp carries for a pseudo-class clause.
   *
   * A reference stamps its published name (`hover`, the attribute value
   * the pose writes today); a literal stamps its bare pseudo-class
   * (`checked`), the one word both the stamp and the printed dual spell.
   *
   * @param array $clause
   *   A clause record (clause()).
   *
   * @return string|null
   *   The stamp word; NULL for a clause of any other form.
   */
  public static function stamp(array $clause): ?string {
    if ($clause['form'] !== self::PSEUDO || $clause['kind'] === self::UNKNOWN) {
      return NULL;
    }
    return $clause['kind'] === self::REFERENCE ? $clause['text'] : substr($clause['text'], 1);
  }

  /**
   * A reference's record: the published definition's wrapper and axis.
   */
  private static function reference(string $name, array $conditions): array {
    $definition = $conditions[$name];
    $wrapper = is_string($definition['wrapper'] ?? NULL) ? $definition['wrapper'] : NULL;
    $axis = is_string($definition['axis'] ?? NULL) && $definition['axis'] !== '' ? $definition['axis'] : NULL;
    return self::record(self::REFERENCE, $name, $wrapper === NULL ? NULL : self::form($wrapper), $axis, $wrapper);
  }

  /**
   * Builds a clause record, deriving the structured twins from its CSS.
   */
  private static function record(string $kind, string $text, ?string $form, ?string $axis, ?string $css): array {
    return [
      'kind' => $kind,
      'text' => $text,
      'form' => $form,
      'axis' => $axis,
      'css' => $css,
      'band' => $css === NULL ? NULL : self::band($css),
      'preference' => $css === NULL ? NULL : self::preference($css),
    ];
  }

  /**
   * Reads a wrapper's CSS into its form, canonical text and features.
   *
   * A pseudo-class is ":name" (lowercased); an at-rule is its keyword, a
   * space and its body. A @media or @container body is words and
   * parenthesized feature tests "(name)" or "(name: value)", the names
   * and values lowercased and single-spaced, keywords lowercased and
   * identifiers (a container name) kept; a @supports body is kept as
   * written with its whitespace collapsed (its tests are declarations and
   * selectors the browser reads, no feature of ours). NULL for text of no
   * known form, an unbalanced or nested test, or a query without a
   * feature test.
   *
   * @return array{form: string, text: string, features: array<int, array{0: string, 1: ?string}>}|null
   *   The reading.
   */
  private static function read(string $css): ?array {
    $css = trim($css);
    if (preg_match('/^:([a-z][a-z0-9-]*)$/i', $css, $matches)) {
      return ['form' => self::PSEUDO, 'text' => ':' . strtolower($matches[1]), 'features' => []];
    }
    if (!preg_match('/^@(media|container|supports)(?:\s+(.*))?$/is', $css, $matches)) {
      return NULL;
    }
    $form = strtolower($matches[1]);
    $body = trim($matches[2] ?? '');
    if ($body === '') {
      return NULL;
    }
    if ($form === self::SUPPORTS) {
      $body = preg_replace(['/\s+/', '/\(\s+/', '/\s+\)/'], [' ', '(', ')'], $body);
      return ['form' => self::SUPPORTS, 'text' => '@supports ' . $body, 'features' => []];
    }
    $tokens = self::tokens($body);
    if ($tokens === NULL) {
      return NULL;
    }
    $features = [];
    $printed = [];
    foreach ($tokens as $token) {
      if ($token[0] === '(') {
        if (!preg_match('/^\(\s*([a-z][a-z0-9-]*)\s*(?::\s*([^()]*?)\s*)?\)$/i', $token, $feature)) {
          return NULL;
        }
        $name = strtolower($feature[1]);
        $value = isset($feature[2]) && $feature[2] !== '' ? strtolower(preg_replace('/\s+/', ' ', $feature[2])) : NULL;
        $features[] = [$name, $value];
        $printed[] = $value === NULL ? "($name)" : "($name: $value)";
      }
      else {
        $lower = strtolower($token);
        $printed[] = in_array($lower, self::QUERY_KEYWORDS, TRUE) ? $lower : $token;
      }
    }
    if ($features === []) {
      return NULL;
    }
    return ['form' => $form, 'text' => '@' . $form . ' ' . implode(' ', $printed), 'features' => $features];
  }

  /**
   * Splits a query body into words and parenthesized groups.
   *
   * @return string[]|null
   *   The tokens; NULL when the parentheses do not balance.
   */
  private static function tokens(string $body): ?array {
    $tokens = [];
    $current = '';
    $depth = 0;
    $length = strlen($body);
    for ($i = 0; $i < $length; $i++) {
      $char = $body[$i];
      if ($depth === 0 && ctype_space($char)) {
        if ($current !== '') {
          $tokens[] = $current;
          $current = '';
        }
        continue;
      }
      if ($char === '(') {
        if ($depth === 0 && $current !== '') {
          $tokens[] = $current;
          $current = '';
        }
        $depth++;
      }
      elseif ($char === ')') {
        if ($depth === 0) {
          return NULL;
        }
        $depth--;
        $current .= $char;
        if ($depth === 0) {
          $tokens[] = $current;
          $current = '';
        }
        continue;
      }
      $current .= $char;
    }
    if ($depth !== 0) {
      return NULL;
    }
    if ($current !== '') {
      $tokens[] = $current;
    }
    return $tokens;
  }

  /**
   * A literal's axis: its feature's, one per literal.
   *
   * @return string|null
   *   The axis; NULL when the literal's features span more than one axis
   *   (two clauses are the spelling: "(min-width: 900px)+(hover: hover)").
   */
  private static function literalAxis(array $read, array $conditions): ?string {
    if ($read['form'] === self::PSEUDO) {
      return self::STATE_AXIS;
    }
    if ($read['form'] === self::SUPPORTS) {
      return self::SUPPORTS_AXIS;
    }
    $axes = [];
    foreach ($read['features'] as [$name]) {
      $axes[self::featureAxis($name, $conditions)] = TRUE;
    }
    return count($axes) === 1 ? (string) array_key_first($axes) : NULL;
  }

  /**
   * A feature's axis: width, a published sibling's, or its own name.
   */
  private static function featureAxis(string $feature, array $conditions): string {
    if (in_array($feature, self::WIDTH_FEATURES, TRUE)) {
      return self::WIDTH_AXIS;
    }
    foreach ($conditions as $definition) {
      if (!is_array($definition) || !is_string($definition['wrapper'] ?? NULL) || !is_string($definition['axis'] ?? NULL) || $definition['axis'] === '') {
        continue;
      }
      $published = self::read($definition['wrapper']);
      if ($published === NULL) {
        continue;
      }
      foreach ($published['features'] as [$name]) {
        if ($name === $feature) {
          return $definition['axis'];
        }
      }
    }
    return $feature;
  }

  /**
   * A clause's sort rank: [group, key].
   *
   * Group 0 is a published axis (key its order index), group 1 a literal
   * axis the table does not know (key the axis name), group 2 an unknown
   * word (no key: spelled order decides).
   *
   * @return array{0: int, 1: int|string}
   *   The rank.
   */
  private static function rank(array $clause, array $order): array {
    if ($clause['axis'] === NULL) {
      return [2, 0];
    }
    if (isset($order[$clause['axis']])) {
      return [0, $order[$clause['axis']]];
    }
    return [1, $clause['axis']];
  }

  /**
   * Compares two ranks.
   */
  private static function compareRanks(array $a, array $b): int {
    if ($a[0] !== $b[0]) {
      return $a[0] <=> $b[0];
    }
    return match ($a[0]) {
      0 => $a[1] <=> $b[1],
      1 => strcmp((string) $a[1], (string) $b[1]),
      default => 0,
    };
  }

}
