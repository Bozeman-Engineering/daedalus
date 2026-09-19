<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\Materials\ValueReference;

/**
 * Parses and validates the dialect's CSS surface.
 *
 * Two inputs share this grammar: style="" attribute values (declaration
 * lists) and <style> blocks (rules under the published selector grammar
 * and condition wrappers, spec v0.2 section 7). Rules map back onto node
 * style entries: the selector picks the node and the local key ('' own,
 * '::.part' part (declared parts and rich-text instance classes resolve
 * by the one rule: exact part, then the site instance namespaces),
 * '::tag' a declared instance tag's element-scoped tier, '::>' children,
 * '::.part::>' a part's children so ONE list's items are addressable),
 * the wrapper nesting and selector states pick the condition, and the
 * declarations are the entry's property map.
 * The property GRANT is not judged here: the parser judges grammar, and
 * a property outside an element's vocabulary roster is refused by the
 * semantic validator when the parsed styles land as update_style ops
 * (STYLE_PROPERTY_NOT_DECLARED, the roster's own corrective), the one
 * door that reads the roster the prompt teaches. The document path still
 * answers a grant miss and a grammar miss in ONE round: the document is
 * judged whole (DomOperationHandler::applyDocument()) and its best-effort
 * tree carries every grammatical declaration to the validator, so a
 * grammar error never hides a grant miss on the same bytes.
 * The value grammar is enforced here too, through the catalog's value
 * grammar over the materials roster: every var() must name a published
 * material (a skin token or a swatch; the dialect's API word for a
 * rostered var() name stays "token"), url() is only a media reference or
 * a generation request, and math functions, !important and custom
 * property definitions are refused. The corrective names the rule and,
 * for a miss, the nearest published materials the writer could have
 * reached for (the roster's one did-you-mean), because these are the
 * measured model error classes.
 *
 * Images in CSS: url('media:<uuid>') is the spine's media token (daedalus_stylish
 * validates the property's media capability and the uuid); url('generate:
 * <prompt>') is the page language's spelling of the {_generate} handled
 * value, translated here so every CSS entry point (style="", the style op,
 * <style> blocks) lands it as the one handled value the spine's value
 * handlers resolve. A generation request rides CSS's own comma-layer
 * model: a value carrying one splits on top-level commas into the layer
 * list StyleValueResolver takes on media-capable properties (literal CSS
 * fragments plus {_generate} objects, joined back with commas once the
 * media is minted), so `linear-gradient(...), url('generate:<prompt>')`
 * darkens a generated image in ONE write.
 *
 * Quoting canon (dialect 0.4): the canonical url() spelling is
 * SINGLE-quoted, the one form valid inside style="" attributes (a raw
 * double quote closes the attribute early and cascades into junk
 * attributes), in <style> blocks, and in JSON strings. Reading stays
 * quote-agnostic (the grammar reads either quote or none, so values
 * stored under either quoting keep parsing), and every value a parse
 * returns is respelled through the grammar's one canonical form
 * (ValueGrammar::canonical()), the same rewrite the printer applies, so
 * what the spine stores and what the model reads is one spelling.
 */
final class DialectCssParser {

  /**
   * A url() call: the whole call and its argument captured.
   *
   * The CSS-level shape only; what a call IS (a media reference, a
   * generation request, a file url the dialect refuses) is the grammar's
   * answer for the whole call.
   */
  private const URL_RE = '/url\(\s*(["\']?)(.*?)\1\s*\)/is';

  /**
   * The selector grammar.
   *
   * #id[:state...] followed by one of: nothing (the element itself),
   * .part[:state...][ > *] (a declared part or a rich-text instance
   * class, optionally composed with its direct children so ONE list's
   * items are addressable), a bare declared instance tag[:state...]
   * (#id p, the element-scoped tag tier), or > * (direct children).
   */
  private const SELECTOR_RE = '/^#(?<id>[^\s:>.]+)(?<idstates>(?::[A-Za-z-]+)*)(?:\s+\.(?<part>[A-Za-z][A-Za-z0-9_-]*)(?<partstates>(?::[A-Za-z-]+)*)(?<partchildren>\s*>\s*\*)?|\s+(?<tag>[a-z][a-z0-9-]*)(?<tagstates>(?::[A-Za-z-]+)*)|(?<children>\s*>\s*\*))?$/';

  public function __construct(
    private readonly DialectCatalog $catalog,
  ) {}

  /**
   * Parses a declaration list (a style="" value or a rule body).
   *
   * @param string $text
   *   The declaration list.
   * @param int|null $line
   *   The source line for errors.
   * @param string $context
   *   Where the declarations sit, for error messages.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector; validation errors are appended.
   *
   * @return array
   *   The parsed property => value map, in source order: every
   *   declaration whose name and value passed the grammar (the property
   *   grant is the semantic validator's question, not the parser's).
   */
  public function parseDeclarations(string $text, ?int $line, string $context, array &$errors): array {
    if (str_contains($text, '!important')) {
      $errors[] = new DialectError('IMPORTANT', 'the specificity system owns importance; !important is not accepted', $line);
    }
    if (preg_match('/url\s+\(/i', $text)) {
      $errors[] = new DialectError('URL_FUNCTION', 'url() takes no space before its parenthesis', $line);
    }
    if (substr_count($text, '(') !== substr_count($text, ')')) {
      $errors[] = new DialectError('BAD_DECLARATION', sprintf('unbalanced parentheses in %s', $context), $line);
    }

    $properties = [];
    foreach (explode(';', $text) as $declaration) {
      $declaration = trim($declaration);
      if ($declaration === '') {
        continue;
      }
      if (!str_contains($declaration, ':')) {
        $errors[] = new DialectError('BAD_DECLARATION', sprintf('not a "property: value" declaration: "%s"', $declaration), $line);
        continue;
      }
      [$property, $value] = explode(':', $declaration, 2);
      $property = trim($property);
      $value = trim($value);
      if (str_starts_with($property, '--')) {
        $errors[] = new DialectError('CUSTOM_PROP_DEF', sprintf('defining custom properties (%s) is not accepted; the token roster is the palette', $property), $line);
        continue;
      }
      if (!preg_match('/^[a-z][a-z0-9-]*$/', $property)) {
        $errors[] = new DialectError('BAD_DECLARATION', sprintf('illegal property name "%s"', $property), $line);
        continue;
      }
      $before = count($errors);
      $this->validateValue($property, $value, $line, $errors);
      $parsed = $this->imageValue($property, $value, $line, $errors);
      // A declaration the grammar refused stays out of the map: the tree
      // is best-effort under errors, and the semantic pass over it must
      // not judge (and refuse again) a value already refused here.
      if (count($errors) === $before) {
        $properties[$property] = $parsed;
      }
    }
    return $properties;
  }

  /**
   * Enforces the url() grammar and translates generation requests.
   *
   * Every url() call resolves through the grammar as a whole value: a
   * media reference or a generation request passes, anything else (a
   * file url, an external image) is refused. A generation request rides
   * CSS's own comma-layer model: a value that carries one splits on
   * top-level commas (paren-aware), each layer that IS one generation
   * request becomes the {_generate} handled value, and the layer list
   * (literal CSS fragments and handled objects in source order) is what
   * StyleValueResolver takes on media-capable properties, joining the
   * resolved layers back with commas on commit. The single-layer request
   * is the same path and lands as the bare {_generate} value, the shape
   * the resolver's handled-object door takes.
   *
   * @return string|array
   *   The value respelled canonically; the {_generate} handled value when
   *   the whole value is one generation request; or the layer list when
   *   a generation request composes with other layers.
   */
  private function imageValue(string $property, string $value, ?int $line, array &$errors): string|array {
    if (!preg_match_all(self::URL_RE, $value, $urls, PREG_SET_ORDER)) {
      return $value;
    }
    $generates = FALSE;
    foreach ($urls as $url) {
      $kind = $this->catalog->resolve($url[0], MaterialRecord::SLOT_STYLE)->kind;
      if ($kind === ValueKind::Generate) {
        $generates = TRUE;
      }
      elseif ($kind !== ValueKind::Media) {
        $errors[] = new DialectError('URL_FUNCTION', sprintf('url(%s) in %s: url() takes only url(\'media:<uuid>\') (existing media, by uuid) or url(\'generate:<prompt>\') (mint an image); file URLs and external images are not accepted', $url[2], $property), $line);
        return $value;
      }
    }
    if (!$generates) {
      return $this->catalog->canonical($value, MaterialRecord::SLOT_STYLE);
    }
    $layers = [];
    foreach (self::splitLayers($value) as $layer) {
      if ($layer === '') {
        $errors[] = new DialectError('BAD_DECLARATION', sprintf('empty comma layer in %s', $property), $line);
        return $value;
      }
      $reference = $this->catalog->resolve($layer, MaterialRecord::SLOT_STYLE);
      if ($reference->kind === ValueKind::Generate) {
        if ($reference->id === '') {
          $errors[] = new DialectError('BAD_DECLARATION', sprintf('url(\'generate:\') in %s needs a prompt: url(\'generate:detailed description of the image\')', $property), $line);
          return $value;
        }
        $layers[] = ['_generate' => $reference->id];
        continue;
      }
      foreach ($this->catalog->references($layer, MaterialRecord::SLOT_STYLE) as $inner) {
        if ($inner->kind === ValueKind::Generate) {
          $errors[] = new DialectError('BAD_DECLARATION', sprintf('url(\'generate:...\') in %s must be its own comma layer: compose as linear-gradient(...), url(\'generate:<prompt>\')', $property), $line);
          return $value;
        }
      }
      $layers[] = $this->catalog->canonical($layer, MaterialRecord::SLOT_STYLE);
    }
    return count($layers) === 1 ? $layers[0] : $layers;
  }

  /**
   * Splits a CSS value on top-level commas, paren-aware, layers trimmed.
   *
   * @return string[]
   *   The comma layers in source order.
   */
  private static function splitLayers(string $value): array {
    $layers = [];
    $depth = 0;
    $current = '';
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($char === '(') {
        $depth++;
      }
      elseif ($char === ')') {
        $depth = max(0, $depth - 1);
      }
      elseif ($char === ',' && $depth === 0) {
        $layers[] = trim($current);
        $current = '';
        continue;
      }
      $current .= $char;
    }
    $layers[] = trim($current);
    return $layers;
  }

  /**
   * Parses a <style> block into per-node style assignments.
   *
   * @param string $css
   *   The style block's text.
   * @param int $start_line
   *   The source line the block starts on.
   * @param array|null $known_ids
   *   Map of id => ['container' => bool, 'block_type' => string|null] for every
   *   addressable element in the document. NULL when the document is not
   *   at hand (envelope-level validation): grammar is still enforced, but
   *   id existence and part/children ownership are the caller's to
   *   check.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector; grammar errors are appended.
   *
   * @return array
   *   Assignments: [id, local key, condition name, property map] tuples in
   *   source order.
   */
  public function parseStyleBlock(string $css, int $start_line, ?array $known_ids, array &$errors): array {
    // Blank out comments, preserving line structure for error positions.
    $css = preg_replace_callback('/\/\*.*?\*\//s', static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]), $css);
    $assignments = [];
    $this->parseRules($css, 0, strlen($css), [], $start_line, $known_ids, $assignments, $errors);
    return $assignments;
  }

  /**
   * Recursively parses rules and at-rules within one brace level.
   *
   * @param string $css
   *   The whole stylesheet text.
   * @param int $pos
   *   The offset the brace level starts at.
   * @param int $end
   *   The offset the brace level ends at (exclusive).
   * @param string[] $wrappers
   *   The condition names of the enclosing at-rule wrappers, outermost
   *   first.
   * @param int $base_line
   *   The source line $pos sits on, for error line numbers.
   * @param array|null $known_ids
   *   Map of id => ['container' => bool, 'block_type' => string|null] for every
   *   addressable element in the document, or NULL when the document is not
   *   known.
   * @param array $assignments
   *   Collector for the parsed assignments (by reference).
   * @param array $errors
   *   Collector for parse errors (by reference).
   */
  private function parseRules(string $css, int $pos, int $end, array $wrappers, int $base_line, ?array $known_ids, array &$assignments, array &$errors): void {
    while ($pos < $end) {
      while ($pos < $end && strspn($css[$pos], " \t\r\n") === 1) {
        $pos++;
      }
      if ($pos >= $end) {
        break;
      }
      $line = $base_line + substr_count($css, "\n", 0, $pos);
      $brace = strpos(substr($css, 0, $end), '{', $pos);
      if ($brace === FALSE) {
        if (trim(substr($css, $pos, $end - $pos)) !== '') {
          $errors[] = new DialectError('CSS_SYNTAX', sprintf('stray text in style block: "%s"', substr(trim(substr($css, $pos, $end - $pos)), 0, 60)), $line);
        }
        break;
      }
      $prelude = trim(substr($css, $pos, $brace - $pos));
      $close = $this->matchingBrace($css, $brace, $end);
      if ($close === -1) {
        $errors[] = new DialectError('CSS_SYNTAX', 'unclosed { in style block', $line);
        break;
      }
      $body_start = $brace + 1;
      if (str_starts_with($prelude, '@')) {
        $condition = $this->catalog->conditionForWrapper($prelude);
        if ($condition === NULL) {
          $legal = [];
          foreach ($this->catalog->atRuleWrappers() as $name => $wrapper) {
            $legal[] = sprintf('%s  (%s)', $wrapper, $name);
          }
          $hint = '';
          if (str_starts_with(strtolower($prelude), '@media') && preg_match('/(max|min)-width/', $prelude)) {
            $hint = '; width conditions are container queries here';
          }
          $errors[] = new DialectError('UNKNOWN_WRAPPER', sprintf('"%s" is not a published condition wrapper%s', $prelude, $hint), $line, [
            'prelude' => $prelude,
            'published_wrappers' => $this->catalog->atRuleWrappers(),
          ], sprintf('Published wrappers: %s.', implode('; ', $legal)));
        }
        else {
          $this->parseRules($css, $body_start, $close, array_merge($wrappers, [$condition]), $base_line, $known_ids, $assignments, $errors);
        }
      }
      else {
        $this->parseRule($prelude, substr($css, $body_start, $close - $body_start), $wrappers, $line, $base_line + substr_count($css, "\n", 0, $body_start), $known_ids, $assignments, $errors);
      }
      $pos = $close + 1;
    }
  }

  /**
   * Parses one selector + body rule into an assignment.
   */
  private function parseRule(string $selector, string $body, array $wrappers, int $line, int $body_line, ?array $known_ids, array &$assignments, array &$errors): void {
    $target = $this->parseSelector($selector, $line, $known_ids, $errors);
    $properties = $this->parseDeclarations($body, $body_line, sprintf('rule "%s"', $selector), $errors);
    if ($target === NULL) {
      return;
    }
    $condition = $this->catalog->canonicalCompound(array_merge($wrappers, $target['states']));
    $assignments[] = [$target['id'], $target['local'], $condition, $properties];
  }

  /**
   * Parses and validates one selector against the published grammar.
   *
   * @return array|null
   *   ['id' => string, 'local' => string, 'states' => string[]] when the
   *   selector parses, NULL when it was rejected.
   */
  public function parseSelector(string $selector, ?int $line, ?array $known_ids, array &$errors): ?array {
    $selector = trim($selector);
    if (str_contains($selector, ',')) {
      $errors[] = new DialectError('SELECTOR_LIST', 'one selector per rule; split the comma-separated list into separate rules', $line);
      return NULL;
    }
    if ($selector === ':root' || str_starts_with($selector, ':root')) {
      $errors[] = new DialectError('BAD_SELECTOR', ':root is not addressable; tokens are the palette and elements are addressed by #id', $line);
      return NULL;
    }
    if (str_starts_with($selector, '.')) {
      $errors[] = new DialectError('BAD_SELECTOR', sprintf('bare class selector "%s"; address the element as #id (variants ride class="" on the element)', $selector), $line);
      return NULL;
    }
    if (!str_starts_with($selector, '#')) {
      $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s": element/universal selectors are not accepted; the grammar is #id, #id .declared-part, #id:state, #id > *', $selector), $line);
      return NULL;
    }
    if (!preg_match(self::SELECTOR_RE, $selector, $m)) {
      // The two measured fluency-spill classes (compound #id.class and
      // descent into rich text) get correctives that name the dialect
      // gesture, not just the grammar.
      if (preg_match('/^#[^\s>.]+\.[A-Za-z]/', $selector)) {
        $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s": compound #id.class selectors are not accepted; style variants ride class="" on the element itself (the set op)', $selector), $line);
        return NULL;
      }
      if (preg_match('/^#[^\s]+\s+[A-Za-z*\[]/', $selector)) {
        $tags = $this->catalog->textTags();
        if ($tags !== []) {
          $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s": only an element\'s own content is addressable: #id (the element), #id .part-class (a declared part), #id .s-x (ONE identified rich-text element), #id %s (ALL of its text elements of a declared tag: %s), #id .s-x > * (ONE list\'s items), #id > * (direct children). Arbitrary descendant selectors are not accepted', $selector, $this->catalog->exampleTextTag(), implode(', ', $tags)), $line);
        }
        else {
          $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s": descendant elements are not addressable; an element is styled as a whole (#id), its declared parts as #id .part-class, its direct children as #id > *', $selector), $line);
        }
        return NULL;
      }
      $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s" does not match the selector grammar (#id, #id .declared-part, #id:state, #id > *)', $selector), $line);
      return NULL;
    }

    $id = $m['id'];
    if (!preg_match(DialectParser::ID_RE, $id)) {
      $errors[] = new DialectError('BAD_ID', sprintf('illegal id "%s" in selector', $id), $line);
    }
    $states = [];
    foreach (['idstates', 'partstates', 'tagstates'] as $group) {
      foreach (array_filter(explode(':', $m[$group] ?? '')) as $state) {
        if (!$this->catalog->isStateCondition($state)) {
          $errors[] = new DialectError('UNKNOWN_STATE', sprintf(':%s is not a published state condition', $state), $line, [
            'state' => $state,
            'published_states' => $this->catalog->stateConditions(),
          ], sprintf('Legal states: %s.', implode(', ', array_map(static fn (string $s): string => ':' . $s, $this->catalog->stateConditions()))));
          return NULL;
        }
        $states[] = $state;
      }
    }

    $known = $known_ids[$id] ?? NULL;
    if ($known === NULL && $known_ids !== NULL) {
      $errors[] = new DialectError('UNKNOWN_ID_SELECTOR', sprintf('#%s matches no element in the document', $id), $line);
      return NULL;
    }

    $part = ($m['part'] ?? '') !== '' ? $m['part'] : NULL;
    $tag = ($m['tag'] ?? '') !== '' ? $m['tag'] : NULL;
    if ($part !== NULL) {
      // The one resolution rule, the dialect as its third consumer: the
      // guide-declared parts first, then the site instance namespaces.
      $declared = $this->catalog->parts();
      if (isset($declared[$part])) {
        if ($known !== NULL && !$known['container'] && !in_array(DialectCatalog::tagForBlockType((string) $known['block_type']), $declared[$part], TRUE)) {
          $errors[] = new DialectError('UNKNOWN_PART', sprintf('.%s is not a part of <%s> (owned by: %s; the container-scoped form needs a container id)', $part, DialectCatalog::tagForBlockType((string) $known['block_type']), implode(', ', $declared[$part])), $line);
          return NULL;
        }
      }
      elseif ($this->catalog->instanceNamespaceFor($part) !== NULL) {
        // An instance class addresses ONE identified element of the
        // element's rich text, so it must exist in the ACTUAL content:
        // the staged post-op content when the body was written in the
        // same batch, so create-and-style validates in one round.
        if ($known !== NULL && !$this->instanceExists($id, $part, $known, $line, $errors)) {
          return NULL;
        }
      }
      else {
        $namespaces = implode(', ', array_keys($this->catalog->instances()));
        $errors[] = new DialectError('UNKNOWN_PART', sprintf('.%s is not a declared part', $part), $line, [
          'part' => $part,
          'declared_parts' => array_keys($declared),
        ], sprintf('Declared parts: %s%s.', implode(', ', array_map(static fn (string $p): string => '.' . $p, array_keys($declared))) ?: '(none)', $namespaces === '' ? '' : sprintf('. Rich-text elements are addressed by their identity classes (%s, the classes visible in the body)', $namespaces)));
        return NULL;
      }
      $local = '::.' . $part;
      if (($m['partchildren'] ?? '') !== '') {
        // Part + children compose, so ONE list's items are addressable
        // (#id .s-x > * -> {key}::.s-x::>).
        $local .= '::>';
      }
    }
    elseif ($tag !== NULL) {
      // The element-scoped tag tier: #id p styles ALL of the element's
      // text elements of a declared text tag, present and future. The
      // vocabulary is the styling layer's one declaration of a prose
      // tag's styleability (textTags), never the identity namespaces.
      $tags = $this->catalog->textTags();
      if ($tags === []) {
        // No text-tag vocabulary on this site: the corrective teaches the
        // whole-element gesture, the form measured to correct the spill.
        $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s": descendant elements are not addressable; an element is styled as a whole (#id), its declared parts as #id .part-class, its direct children as #id > *', $selector), $line);
        return NULL;
      }
      if (!in_array($tag, $tags, TRUE)) {
        $errors[] = new DialectError('STYLE_TAG_NOT_DECLARED', sprintf('%s is not an addressable text tag; addressable tags: %s. One identified element is #id .s-x, ONE list\'s items are #id .s-x > *; any other tag is governed by the text format and is not a styling target', $tag, implode(', ', $tags)), $line);
        return NULL;
      }
      $local = '::' . $tag;
    }
    elseif (($m['children'] ?? '') !== '') {
      if ($known !== NULL && !$known['container']) {
        $errors[] = new DialectError('BAD_SELECTOR', sprintf('#%s > * : children styling is declared for containers only', $id), $line);
        return NULL;
      }
      $local = '::>';
    }
    else {
      $local = '';
    }

    return ['id' => $id, 'local' => $local, 'states' => $states];
  }

  /**
   * Checks an instance class against the element's actual rich text.
   *
   * @param string $id
   *   The selector's element id, for the corrective.
   * @param string $class
   *   The instance class (without the dot).
   * @param array $known
   *   The element's known-ids entry; 'body' carries its rich-text markup
   *   (staged post-op content) when the element has a rich body.
   * @param int|null $line
   *   The source line.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector.
   *
   * @return bool
   *   TRUE when the class exists in the element's content.
   */
  private function instanceExists(string $id, string $class, array $known, ?int $line, array &$errors): bool {
    $body = $known['body'] ?? NULL;
    if (!is_string($body)) {
      $errors[] = new DialectError('UNKNOWN_INSTANCE', sprintf('.%s: #%s has no rich text body; identity classes address elements inside a rich-text body', $class, $id), $line);
      return FALSE;
    }
    $instances = DialectTextInstances::instancesIn($body, $this->catalog);
    if (isset($instances[$class])) {
      return TRUE;
    }
    if ($instances === []) {
      $errors[] = new DialectError('UNKNOWN_INSTANCE', sprintf('.%s matches nothing in #%s: its rich text carries no identified elements yet. Author the body with your own %s classes on the elements you mean to style (the identity minter honors them), then style those', $class, $id, implode('/', array_map(static fn (string $p): string => substr($p, 1), array_keys($this->catalog->instances()))) ?: 's-*'), $line);
      return FALSE;
    }
    $roster = [];
    foreach ($instances as $name => $instance) {
      $roster[] = sprintf('.%s <%s> "%s"', $name, $instance['tag'], $instance['excerpt']);
    }
    $errors[] = new DialectError('UNKNOWN_INSTANCE', sprintf('.%s matches nothing in #%s; its identified elements: %s', $class, $id, implode('; ', $roster)), $line);
    return FALSE;
  }

  /**
   * Enforces the value grammar: every reference published, no math functions.
   *
   * The grammar lists what must resolve (each var(), each media or
   * generate url()); a reference the roster does not carry is an
   * UNKNOWN_TOKEN whose corrective ranks the nearest materials that CAN
   * be written in a style value, tokens and swatches alike, so a swatch
   * typo is corrected toward the swatch and never toward the token list.
   */
  private function validateValue(string $property, string $value, ?int $line, array &$errors): void {
    foreach ($this->catalog->references($value, MaterialRecord::SLOT_STYLE) as $reference) {
      if ($reference->kind !== ValueKind::Unknown) {
        continue;
      }
      $errors[] = self::unknownReference('UNKNOWN_TOKEN', sprintf('%s in %s is not a published token: no skin token or swatch is spelled var(%s)', $reference->spelling, $property, $reference->id), $reference, MaterialRecord::SLOT_STYLE, $this->catalog, 'Reference a published token or swatch by its var() spelling (the MATERIALS section in your instructions), or write a single CSS value.', $line);
    }
    if (preg_match('/(?:^|[\s(,])(clamp|calc|min|max)\s*\(/', $value, $m)) {
      // The corrective is the catalog's: the width-band lane that exists
      // instead of the math (a refused clamp() headline has no reachable
      // answer unless the hint names it).
      $errors[] = new DialectError(
        'UNSUPPORTED_VALUE',
        sprintf('%s() in %s: use a token or a single length; clamp()/calc() are not supported', $m[1], $property),
        $line,
        ['property' => $property, 'function' => $m[1], 'width_bands' => $this->catalog->widthBands()],
        $this->catalog->unsupportedValueHint($property),
      );
    }
  }

  /**
   * The structured error for a reference the roster does not carry.
   *
   * One shape for every material miss on the dialect door (a var() in a
   * style value, a class, an axis attribute value, a stencil name): the
   * message names the miss, the context carries the reference as written
   * and the candidates the roster ranked (kind, id, spelling, the same
   * facts a client store can offer), and the hint is the roster's one
   * did-you-mean sentence, or the caller's fallback teaching when nothing
   * is close enough to offer.
   *
   * @param string $code
   *   The error code.
   * @param string $message
   *   What went wrong, naming the reference.
   * @param \Drupal\daedalus_blueprint\Materials\ValueReference $reference
   *   The unresolved reference.
   * @param string $slot
   *   The slot it failed in (the candidates are what spells there).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The catalog.
   * @param string $fallback_hint
   *   The corrective when no candidate is close.
   * @param int|null $line
   *   The source line.
   * @param array $context
   *   Extra context (the element's declared roster, the allowed values).
   */
  public static function unknownReference(string $code, string $message, ValueReference $reference, string $slot, DialectCatalog $catalog, string $fallback_hint, ?int $line, array $context = []): DialectError {
    $candidates = $catalog->roster()->candidatesInSlot($slot, (string) $reference->id);
    $hint = $catalog->roster()->didYouMeanInSlot($slot, (string) $reference->id);
    return new DialectError($code, $message, $line, $context + [
      'reference' => $reference->spelling,
      'candidates' => MaterialsRoster::candidateFacts($candidates, $slot),
    ], $hint === '' ? $fallback_hint : $hint);
  }

  /**
   * Finds the matching closing brace, or -1.
   */
  private function matchingBrace(string $css, int $open, int $end): int {
    $depth = 0;
    for ($i = $open; $i < $end; $i++) {
      if ($css[$i] === '{') {
        $depth++;
      }
      elseif ($css[$i] === '}') {
        if (--$depth === 0) {
          return $i;
        }
      }
    }
    return -1;
  }

}
