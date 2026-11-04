<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Materials\ConditionClause;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Materials\SelectorDigest;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\Materials\ValueReference;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;

/**
 * Parses and validates the dialect's CSS surface.
 *
 * Two inputs share this grammar: style="" attribute values (declaration lists)
 * and <style> blocks (rules under the published selector grammar and condition
 * wrappers, spec v0.2 section 7). Rules map back onto node style entries: the
 * selector is `#id` plus a RELATIVE SELECTOR under it (RelativeSelector, the
 * one suffix grammar every style key speaks: a declared part or rich-text
 * instance class `.x`, a declared base tag `p`, the children `> *`, a block
 * type among them `> d-text`, their compounds `> d-text .link`, the relations
 * `> p + p`, `> d-text:has(a)`, `> *:not(d-image)`, any of several compounds at
 * one position `> *:is(p, div)`), so the local key is '' (the element) or '::'
 * plus the selector in its canonical spelling; the at-rule nesting (a published
 * wrapper read as its reference, any other `@media` / `@container` /
 * `@supports` query the condition grammar reads as a literal clause:
 * ConditionClause through the digester's WHEN half) and the posable states on
 * the LAST compound (`#id:hover`, `#id > d-text:has(a):hover`, peeled by name
 * whatever their order among its qualifiers) pick the condition, every other
 * qualifier stays selector text (`#id li:hover > ul`, `#id.open > ul`, `#id
 * [aria-expanded="true"] > ul`, `#id a:visited`, `#id > *:not(:last-child)`), a
 * selector list is ONE rule folded into `:is()` where its members differ (`#id
 * h1, #id h2` lands at `::*:is(h1, h2)`) or refused whole (members sharing no
 * shape, a fold that would put two states on the last compound), and the
 * declarations are the entry's property map. The reader is SelectorDigest, the
 * one digester every door shares; grammar is CSS's and admission (an undeclared
 * tag, class, attribute or state) is the validator's verdict by enforcement
 * mode, never a refusal here. CSS nesting is accepted as sugar and flattened
 * (`#id { p { … } &:hover { … } @media … { … } }` reads as the flat rules it
 * means); the print is always flat. A rule also WEARS: `class: button` and
 * `{axis}: {value}` inside a `::` rule's plain block (peelWear()) are the
 * attribute's twin, the variant references the rule's entry carries and
 * delivers at every match, parsed by the same catalog lookups the element's
 * class="" and axis attributes use and refused on the element itself, under a
 * condition, and for a top-level-only axis. The property GRANT is not judged
 * here: the parser judges grammar, and a property outside an element's
 * vocabulary roster is refused by the semantic validator when the parsed styles
 * land as update_style ops (STYLE_PROPERTY_NOT_DECLARED, the roster's own
 * corrective), the one door that reads the roster the prompt teaches. The
 * document path still answers a grant miss and a grammar miss in ONE round: the
 * document is judged whole (DomOperationHandler::applyDocument()) and its
 * best-effort tree carries every grammatical declaration to the validator, so a
 * grammar error never hides a grant miss on the same bytes. The value grammar
 * is enforced here too, through the catalog's value grammar over the materials
 * roster: every var() must name a published material (a skin token: the
 * dialect's API word for a rostered var() name is "token"), url() is only a
 * media reference, a generation request or a file reference, and math
 * functions, !important and custom property definitions are refused. The
 * corrective names the rule and, for a miss, the nearest published materials
 * the writer could have reached for (the roster's one did-you-mean), because
 * these are the measured model error classes.
 *
 * Images in CSS: url('media:<uuid>') is the spine's media token
 * (daedalus_stylish validates the property's media capability and the uuid);
 * url('generate: <prompt>') is the page language's spelling of the {_generate}
 * handled value and url('file:<name>') of the {_file} handled value (a file the
 * conversation holds, by its name), both translated here so every CSS entry
 * point (style="", the style op, <style> blocks) lands them as the handled
 * values the spine's value handlers resolve. A handled request rides CSS's own
 * comma-layer model: a value carrying one splits on top-level commas into the
 * layer list StyleValueResolver takes on media-capable properties (literal CSS
 * fragments plus {_generate} / {_file} objects, joined back with commas once
 * the media is minted), so `linear-gradient(...), url('generate:<prompt>')`
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
   * generation request, a file reference, a plain url the dialect
   * refuses) is the grammar's answer for the whole call.
   */
  private const URL_RE = '/url\(\s*(["\']?)(.*?)\1\s*\)/is';

  /**
   * The selector grammar's root: #id, then everything after it.
   *
   * An id runs to the first character that cannot be part of one; the
   * rest (qualifiers glued to the id, a chain after a space or a
   * combinator, the generated box, or nothing) is owner-relative text
   * for the digester (SelectorDigest), the anchor spelled where the id
   * stood.
   */
  private const ROOT_RE = '/^#(?<id>[^\s:>.+~\[,]+)(?<rest>.*)$/s';

  /**
   * The corrective's one-line grammar for a refused selector.
   */
  private const SELECTOR_GRAMMAR = '#id (the element), #id .part (a declared part), #id p (its paragraphs, a tag), #id > * (its direct children), #id > d-text (the text blocks among them), compounds such as #id > d-text .link and #id > p + p (the next sibling; ~ any later sibling), in CSS\'s own selector grammar: a compound is narrowed by a class (#id li.active > ul, #id.open > ul the element wearing a class), an attribute (#id [aria-expanded="true"] > ul, #id a[href^="http"]; the operators =, ~=, |=, ^=, $=, *=), a position pseudo-class (#id > d-text:first-child, #id > *:not(:last-child), #id li:nth-child(2n+1), #id:first-child > *), :has(…) or :not(…) (#id > d-text:has(a), #id > *:not(d-image)), each qualifying the compound it stands on; a published state on the LAST compound is the rule\'s condition (#id:hover, #id > d-text:has(a):hover) and a state on any compound before it qualifies that compound (#id li:hover > ul, #id:hover > d-text); a pseudo-class the site does not publish stays selector text (#id a:visited); :is(…) is any of several compounds at one position (#id > *:is(p, div), #id li:is(:hover, :focus-within) > ul; :where() reads as :is()), and a selector list is ONE rule, folded into :is() where its members differ (#id h1, #id h2 is #id *:is(h1, h2)); a list whose members share no shape, or whose fold would put two states on the last compound, is written as separate rules; tags, types and pseudo-class names read in any case; a pseudo-element last of all, glued to the last compound after its states (#id::after the element\'s own generated box, #id > d-text::before each text block\'s, #id:hover::after; #id li::marker, #id input::placeholder, #id::selection, #id p::first-letter, #id p::first-line and any other the browser knows, each stylable with the properties its specification allows), a bare name with nothing after it (no ::part(), no ::slotted()); no html, body, :root or another element\'s id';

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
   * Enforces the url() grammar and translates handled requests.
   *
   * Every url() call resolves through the grammar as a whole value: a
   * media reference, a generation request or a file reference passes,
   * anything else (a path, an external image) is refused. A handled
   * request (generate or file) rides CSS's own comma-layer model: a value
   * that carries one splits on top-level commas (paren-aware), each layer
   * that IS one request becomes its handled value ({_generate: prompt} or
   * {_file: name}), and the layer list (literal CSS fragments and handled
   * objects in source order) is what StyleValueResolver takes on
   * media-capable properties, joining the resolved layers back with
   * commas on commit. The single-layer request is the same path and
   * lands as the bare handled value, the shape the resolver's
   * handled-object door takes.
   *
   * @return string|array
   *   The value respelled canonically; the handled value when the whole
   *   value is one request; or the layer list when a request composes
   *   with other layers.
   */
  private function imageValue(string $property, string $value, ?int $line, array &$errors): string|array {
    if (!preg_match_all(self::URL_RE, $value, $urls, PREG_SET_ORDER)) {
      return $value;
    }
    $handled = FALSE;
    foreach ($urls as $url) {
      $kind = $this->catalog->resolve($url[0], MaterialRecord::SLOT_STYLE)->kind;
      if ($kind === ValueKind::Generate || $kind === ValueKind::File) {
        $handled = TRUE;
      }
      elseif ($kind !== ValueKind::Media) {
        $errors[] = new DialectError('URL_FUNCTION', sprintf('url(%s) in %s: url() takes only url(\'media:<uuid>\') (existing media, by uuid), url(\'generate:<prompt>\') (mint an image) or url(\'file:<name>\') (a file this conversation holds, by its name); paths and external images are not accepted', $url[2], $property), $line);
        return $value;
      }
    }
    if (!$handled) {
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
      if ($reference->kind === ValueKind::File) {
        if ($reference->id === '') {
          $errors[] = new DialectError('BAD_DECLARATION', sprintf('url(\'file:\') in %s needs a name: url(\'file:<name>\'), the name a file this conversation holds is known under', $property), $line);
          return $value;
        }
        $layers[] = ['_file' => $reference->id];
        continue;
      }
      foreach ($this->catalog->references($layer, MaterialRecord::SLOT_STYLE) as $inner) {
        if ($inner->kind === ValueKind::Generate || $inner->kind === ValueKind::File) {
          $errors[] = new DialectError('BAD_DECLARATION', sprintf('url(\'%s:...\') in %s must be its own comma layer: compose as linear-gradient(...), url(\'%s:...\')', $inner->kind->value, $property, $inner->kind->value), $line);
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
    $css = self::blankComments($css);
    $assignments = [];
    $this->parseRules($css, 0, strlen($css), [], $start_line, $known_ids, $assignments, $errors);
    return $assignments;
  }

  /**
   * Blanks out comments, preserving line structure for error positions.
   *
   * Every comment character but a newline becomes a space, so offsets
   * and line numbers into the text stay those of the original.
   */
  public static function blankComments(string $css): string {
    return preg_replace_callback('/\/\*.*?\*\//s', static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]), $css);
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
      $close = self::matchingBrace($css, $brace, $end);
      if ($close === -1) {
        $errors[] = new DialectError('CSS_SYNTAX', 'unclosed { in style block', $line);
        break;
      }
      $body_start = $brace + 1;
      if (str_starts_with($prelude, '@')) {
        $condition = $this->wrapperCondition($prelude, $line, $errors);
        if ($condition !== NULL) {
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
   *
   * Nesting is sugar: a `{ … }` block inside the body is a nested rule,
   * flattened here into the rule it means. A nested `@wrapper` prelude
   * adds its condition; any other nested prelude composes with this rule's
   * selector by CSS nesting's relative rule (`&` is the parent selector,
   * a prelude without `&` is relative to it, and one starting with `:` is
   * `&:` sugar for the parent's own state; a list composes member by
   * member, as CSS nesting reads it), then parses as a rule of its own,
   * recursively. The declarations outside the nested blocks are this
   * rule's.
   *
   * A selector list (`#id h1, #id h2`) is one rule: the digester folds
   * it into one `:is()` selector (`#id *:is(h1, h2)`) or refuses it, so a
   * rule lands on one local. A nested prelude under a list parent
   * distributes textually over the parent's WRITTEN members and its own
   * (`#id h2, #id h3 { a {} }` re-enters as `#id h2 a, #id h3 a`), and
   * that list text folds or is refused here like any other: one fold
   * path, the digester's.
   *
   * A rule's WEAR declarations (peelWear()) come out first: `class: x` and
   * `{axis}: {value}` on a `::` local land as a second assignment whose
   * condition is 'variants' and whose payload is the reference list, the
   * shape the node's style entry holds them in; the property grammar
   * never sees them.
   */
  private function parseRule(string $selector, string $body, array $wrappers, int $line, int $body_line, ?array $known_ids, array &$assignments, array &$errors): void {
    ['declarations' => $declarations, 'nested' => $nested] = self::splitNested($body);
    $target = $this->parseSelector($selector, $line, $known_ids, $errors);
    if ($target === NULL) {
      $this->parseDeclarations($declarations, $body_line, sprintf('rule "%s"', $selector), $errors);
    }
    else {
      ['declarations' => $kept, 'references' => $references] = $this->peelWear($declarations, $target, $wrappers, $known_ids[$target['id']] ?? NULL, $body_line, $errors);
      $properties = $this->parseDeclarations($kept, $body_line, sprintf('rule "%s"', $selector), $errors);
      if ($references !== []) {
        $assignments[] = [$target['id'], $target['local'], 'variants', $references];
      }
      if ($properties !== [] || ($nested === [] && $references === [])) {
        $condition = $this->catalog->canonicalCompound(array_merge($wrappers, $target['states']));
        $assignments[] = [$target['id'], $target['local'], $condition, $properties];
      }
    }
    foreach ($nested as ['prelude' => $prelude, 'body' => $inner, 'offset' => $offset]) {
      $inner_line = $body_line + $offset;
      if (str_starts_with($prelude, '@')) {
        $condition = $this->wrapperCondition($prelude, $inner_line, $errors);
        if ($condition !== NULL) {
          $this->parseRule($selector, $inner, array_merge($wrappers, [$condition]), $inner_line, $inner_line, $known_ids, $assignments, $errors);
        }
        continue;
      }
      $members = [];
      foreach (SelectorDigest::splitList($selector) as $parent) {
        foreach (SelectorDigest::splitList($prelude) as $member) {
          if (str_starts_with($member, ':')) {
            $member = '&' . $member;
          }
          $members[] = str_contains($member, '&')
            ? str_replace('&', $parent, $member)
            : $parent . ' ' . $member;
        }
      }
      $this->parseRule(implode(', ', $members), $inner, $wrappers, $inner_line, $inner_line, $known_ids, $assignments, $errors);
    }
  }

  /**
   * Peels a rule's wear declarations off its declaration text.
   *
   * Wear inside a rule is the attribute's twin: `class: button` (style
   * classes, space-separated, each a published style variant) and
   * `{axis}: {value}` (an axis attribute the rule's landing carries,
   * its value one the axis offers) wear the variant on every element the
   * rule matches, exactly as class="" and the axis attributes wear it on
   * one element. The rule's landing (ruleLanding()) decides which axes
   * apply and which class roster the corrective names. A declaration
   * named like a landing axis whose value spells none of the axis's
   * variants is not a wear and stays for the property grammar (`width:
   * 100%` on a rule whose landing has a width axis is CSS), so the two
   * vocabularies coexist without a reserved word.
   *
   * Refused: wear on the element itself (`#id { class: x }`: the
   * element's wear rides its own attributes and the set op, one spelling
   * per surface), wear under a state or wrapper (a reference has no
   * condition; a rule wears in its default block), a value no variant
   * spells (the class and axis correctives), and a top-level-only axis
   * (a rule's matches are never top-level). A composition variant or a
   * scope the landing does not pair with is the spine's refusal when
   * the reference lands (STYLE_VARIANT_COMPOSITION_AT_RULE,
   * STYLE_VARIANT_SCOPE_MISMATCH): the parser judges spelling, the
   * validator the wear.
   *
   * @param string $declarations
   *   The rule's declaration text (outside nested blocks).
   * @param array{id: string, local: string, states: string[]} $target
   *   The parsed selector.
   * @param string[] $wrappers
   *   The enclosing at-rule wrappers' conditions.
   * @param array|null $known
   *   The id's known-ids entry, or NULL without a document.
   * @param int|null $line
   *   The source line for errors.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector.
   *
   * @return array{declarations: string, references: array<int, array{variant: string, fragment: string}>}
   *   The declaration text with the wear declarations removed, and the
   *   references they spell: axis wear in declaration order, class wear
   *   after it (the element attribute order), each `{variant, fragment:
   *   ''}`.
   */
  private function peelWear(string $declarations, array $target, array $wrappers, ?array $known, ?int $line, array &$errors): array {
    $landing = $this->ruleLanding($target['local'], $known);
    $kept = [];
    $axis_refs = [];
    $class_refs = [];
    foreach (explode(';', $declarations) as $declaration) {
      $trimmed = trim($declaration);
      if ($trimmed === '' || !str_contains($trimmed, ':')) {
        $kept[] = $declaration;
        continue;
      }
      [$name, $value] = explode(':', $trimmed, 2);
      $name = trim($name);
      $value = trim($value);
      $axis = $landing['axes'][$name] ?? NULL;
      $reference = NULL;
      if ($axis !== NULL) {
        $reference = $this->catalog->resolve($value, MaterialRecord::attrSlot($axis['scope'], $name));
        if ($reference->kind !== ValueKind::Variant) {
          // Not one of the axis's values: CSS, the property grammar's.
          $kept[] = $declaration;
          continue;
        }
      }
      elseif ($name !== 'class') {
        $kept[] = $declaration;
        continue;
      }
      if ($target['local'] === '') {
        $errors[] = new DialectError(
          'WEAR_ON_ELEMENT',
          sprintf('"%s: %s" in the rule for #%s: an element wears variants on its own tag (class="", its axis attributes; the set op\'s attrs), never in a rule; a declaration wears at a rule\'s matches (#%s > d-text { %s: %s })', $name, $value, $target['id'], $target['id'], $name, $value),
          $line,
          ['attribute' => $name, 'value' => $value],
          'Wear on the element rides its attributes: set {ref, attrs: {class: "..."}} or the axis attribute; a rule under #id wears on the elements it matches.',
        );
        continue;
      }
      if ($wrappers !== [] || $target['states'] !== []) {
        $errors[] = new DialectError(
          'WEAR_UNDER_CONDITION',
          sprintf('"%s: %s" under a condition: a rule wears a variant in its default block only, never under a state or a wrapper (a wear has no condition; the variant\'s own conditions ride it)', $name, $value),
          $line,
          ['attribute' => $name, 'value' => $value],
          sprintf('Write the wear in the rule\'s plain block: #%s %s { %s: %s }.', $target['id'], substr($target['local'], 2), $name, $value),
        );
        continue;
      }
      if ($reference !== NULL) {
        if ($reference->record->rootOnly()) {
          $errors[] = new DialectError(
            'AXIS_NOT_TOP_LEVEL',
            sprintf('%s applies to top-level containers only, and a rule\'s matches are never top-level', $name),
            $line,
            ['attribute' => $name, 'variant' => $reference->id],
            'Set it on the outermost container itself, or wear a variant on an axis the element offers at every depth.',
          );
          continue;
        }
        $axis_refs[] = ['variant' => $reference->id, 'fragment' => ''];
        continue;
      }
      foreach ($this->catalog->references($value, MaterialRecord::SLOT_CLASS) as $class) {
        if ($class->kind !== ValueKind::Variant) {
          $errors[] = DialectWalk::unknownClass($class, $landing['tag'] ?? substr($target['local'], 2), $landing['roster'], $this->catalog, $line);
          continue;
        }
        $class_refs[] = ['variant' => $class->id, 'fragment' => ''];
      }
    }
    return ['declarations' => implode(';', $kept), 'references' => [...$axis_refs, ...$class_refs]];
  }

  /**
   * The element view a rule's landing carries: its axes, roster and tag.
   *
   * The landing is the element the rule's last compound names (the roster
   * walk's, VocabularyRoster::landingScope() on the styling side): a type
   * compound is that type's element (`> d-text` the text block, `>
   * d-container` any container, `> d-block` any other block), so its
   * axis view and class roster are the element's own
   * (DialectCatalog::axesFor(), classRosterFor()); a tag compound is a
   * base tag with no axes (base variants spell as classes); the element
   * itself (local '') answers its own view; a part, `> *` or a
   * qualifier-only compound lands on no element and carries neither. The
   * one derivation the wear declarations (peelWear()) and the style op's
   * remove list (DomOperationHandler) read.
   *
   * @param string $local
   *   The style local: '' for the element, '::' plus the selector for a
   *   rule.
   * @param array|null $known
   *   The id's known-ids entry (knownId()), or NULL without a document.
   *
   * @return array{axes: array, roster: array, tag: ?string}
   *   The axis view (attr => axis entry), the class roster (class =>
   *   variant id) and the landing's dialect tag, NULL where the landing
   *   is no element.
   */
  public function ruleLanding(string $local, ?array $known): array {
    if ($local === '') {
      if ($known === NULL) {
        return ['axes' => [], 'roster' => [], 'tag' => NULL];
      }
      return [
        'axes' => $this->catalog->axesFor($known['container'], $known['block_type'], $known['plugin_id']),
        'roster' => $this->catalog->classRosterFor($known['container'], $known['block_type'], $known['plugin_id']),
        'tag' => self::tagOf($known),
      ];
    }
    $relative = RelativeSelector::parse(substr($local, strlen(RelativeSelector::SUFFIX)));
    // A generated box (`&::before`, `> d-text::after`) is no element:
    // it lands on no scope and wears nothing, the validator's verdict on
    // any reference at a box key (STYLE_VARIANT_SCOPE_MISMATCH).
    if ($relative === NULL || $relative->pseudo !== NULL) {
      return ['axes' => [], 'roster' => [], 'tag' => NULL];
    }
    $last = $relative->last();
    if ($last['kind'] !== RelativeSelector::TYPE && $last['kind'] !== RelativeSelector::TAG) {
      return ['axes' => [], 'roster' => [], 'tag' => NULL];
    }
    if ($last['kind'] === RelativeSelector::TAG) {
      return ['axes' => [], 'roster' => [], 'tag' => $last['name']];
    }
    $is_container = $last['name'] === RelativeSelector::TYPE_CONTAINER;
    $block_type = $is_container || $last['name'] === RelativeSelector::TYPE_BLOCK ? NULL : DialectCatalog::blockTypeForTag($last['name']);
    return [
      'axes' => $this->catalog->axesFor($is_container, $block_type, NULL),
      'roster' => $this->catalog->classRosterFor($is_container, $block_type, NULL),
      'tag' => $last['name'],
    ];
  }

  /**
   * Splits a rule body into its own declarations and its nested blocks.
   *
   * @return array{declarations: string, nested: array<int, array{prelude: string, body: string, offset: int}>}
   *   The declaration text outside every nested block, and each nested
   *   block's prelude, inner text and line offset within the body.
   */
  private static function splitNested(string $body): array {
    $declarations = '';
    $nested = [];
    $current = '';
    $length = strlen($body);
    for ($i = 0; $i < $length; $i++) {
      $char = $body[$i];
      if ($char !== '{') {
        $current .= $char;
        continue;
      }
      // The prelude is the text since the last terminated declaration.
      $semicolon = strrpos($current, ';');
      $prelude = trim($semicolon === FALSE ? $current : substr($current, $semicolon + 1));
      $declarations .= $semicolon === FALSE ? '' : substr($current, 0, $semicolon + 1);
      $depth = 0;
      $close = -1;
      for ($j = $i; $j < $length; $j++) {
        if ($body[$j] === '{') {
          $depth++;
        }
        elseif ($body[$j] === '}' && --$depth === 0) {
          $close = $j;
          break;
        }
      }
      if ($close === -1) {
        // Unclosed: hand the rest to the declaration parser, which reports
        // it as a malformed declaration.
        $declarations .= $current . substr($body, $i);
        return ['declarations' => $declarations, 'nested' => $nested];
      }
      $nested[] = [
        'prelude' => $prelude,
        'body' => substr($body, $i + 1, $close - $i - 1),
        'offset' => substr_count($body, "\n", 0, $i),
      ];
      $current = '';
      $i = $close;
    }
    return ['declarations' => $declarations . $current, 'nested' => $nested];
  }

  /**
   * The condition clause an at-rule prelude spells, or NULL (error appended).
   *
   * The digester's WHEN half (SelectorDigest::digest() over the catalog's
   * condition table): a prelude equal to a published wrapper is that
   * reference, any other `@media`, `@container` or `@supports` query the
   * condition grammar reads is a literal clause in its canonical text
   * (admitted or refused by the validator's enforcement mode, never
   * here), and text the grammar cannot read is UNKNOWN_WRAPPER with the
   * digester's corrective.
   */
  private function wrapperCondition(string $prelude, int $line, array &$errors): ?string {
    $digest = SelectorDigest::digest($prelude, ['conditions' => $this->catalog->conditions()]);
    if ($digest['refusal'] !== NULL) {
      ['code' => $code, 'message' => $message, 'hint' => $hint] = $digest['refusal'];
      $errors[] = new DialectError($code, $message, $line, ['prelude' => $prelude], $hint);
      return NULL;
    }
    return $digest['condition'];
  }

  /**
   * Parses one selector: the `#id` root, then the digester's reading.
   *
   * The dialect owns the root and the document: `#id` names the element
   * (every member of a selector list the same one), the known ids say
   * whether it exists, and the compounds are judged against the
   * document's vocabulary of parts, instances and types (judgeChain()).
   * Everything after the id is owner-relative selector text and the
   * digester's (SelectorDigest::digest(), the one reader of typed or
   * pasted selectors): the qualifiers glued to the id are the anchor's
   * (`#id.open > ul` is `&.open > ul`, `#id:first-child::after` is
   * `&:first-child::after`), a chain after a space or combinator is
   * relative, the generated box is terminal, a state on the LAST
   * compound (or on the id alone) peels into the rule's condition
   * (`#id > d-text:hover` is `::> d-text` under hover) while a state on
   * any compound before it stays a qualifier of that compound (`#id
   * li:hover > ul`, `#id:hover > d-text`), a list folds into one `:is()`
   * selector under the one condition its members share (`#id > p, #id >
   * h2` is `::> *:is(h2, p)`) or is refused where it does not fold, and
   * what cannot be addressed under an element (`:root`, another id, a
   * leading sibling combinator, a box behind a space or inside a
   * qualifier, a second box) is refused with the digester's correctives.
   * Whether a tag, a class, an attribute or a pseudo-class is admitted
   * on this site is the validator's verdict by enforcement mode, never
   * the parser's.
   *
   * @return array|null
   *   ['id' => string, 'local' => string, 'states' => string[]] when the
   *   selector parses ('' the element itself, '::' plus the canonical
   *   selector for a rule), NULL when it was rejected.
   */
  public function parseSelector(string $selector, ?int $line, ?array $known_ids, array &$errors): ?array {
    $selector = trim($selector);
    if ($selector === ':root' || str_starts_with($selector, ':root')) {
      $errors[] = new DialectError('BAD_SELECTOR', ':root is not addressable; tokens are the palette and elements are addressed by #id', $line);
      return NULL;
    }
    if (str_starts_with($selector, '.')) {
      $errors[] = new DialectError('BAD_SELECTOR', sprintf('bare class selector "%s"; address the element as #id (variants ride class="" on the element)', $selector), $line);
      return NULL;
    }
    $id = NULL;
    $rests = [];
    foreach (SelectorDigest::splitList($selector) as $member) {
      if (!preg_match(self::ROOT_RE, $member, $m)) {
        $errors[] = new DialectError('BAD_SELECTOR', sprintf('"%s": element/universal selectors are not accepted; the grammar is %s', $selector, self::SELECTOR_GRAMMAR), $line);
        return NULL;
      }
      if ($id !== NULL && $m['id'] !== $id) {
        $errors[] = new DialectError('SELECTOR_LIST', sprintf('"%s": one element per rule; a selector list folds into one rule under the same element (#%s h1, #%s h2 is #%s *:is(h1, h2)), and another element takes a rule of its own', $selector, $id, $id, $id), $line);
        return NULL;
      }
      $id = $m['id'];
      // The anchor stands where the id stood: what is glued to it
      // qualifies the element itself, a chain after a space or a
      // combinator is relative, and a `::` behind a space stays behind
      // one for the digester to refuse.
      $rests[] = RelativeSelector::ANCHOR . $m['rest'];
    }
    if (!preg_match(DialectParser::ID_RE, $id)) {
      $errors[] = new DialectError('BAD_ID', sprintf('illegal id "%s" in selector', $id), $line);
    }
    $digest = SelectorDigest::digest(implode(', ', $rests), ['conditions' => $this->catalog->conditions()]);
    if ($digest['refusal'] !== NULL) {
      ['code' => $code, 'message' => $message, 'hint' => $hint] = $digest['refusal'];
      $errors[] = new DialectError($code, sprintf('%s (in "%s")', $message, $selector), $line, [
        'selector' => $selector,
        'grammar' => RelativeSelector::GRAMMAR,
      ], $hint);
      return NULL;
    }
    $states = $digest['condition'] === NULL ? [] : ConditionClause::split($digest['condition']);

    $known = $known_ids[$id] ?? NULL;
    if ($known === NULL && $known_ids !== NULL) {
      $errors[] = new DialectError('UNKNOWN_ID_SELECTOR', sprintf('#%s matches no element in the document', $id), $line);
      return NULL;
    }
    $spelled = $digest['selector'];
    if ($spelled === SelectorDigest::OWNER) {
      return ['id' => $id, 'local' => '', 'states' => $states];
    }
    $relative = RelativeSelector::parse($spelled);
    // The anchor's box (`&::before`) has no compounds to judge: every
    // element has both boxes, and what may be styled there is the
    // validator's question (the roster's derived or declared grant).
    if ($relative->count() > 0 && !$this->judgeCompounds($relative, $id, $known, $line, $errors)) {
      return NULL;
    }
    return ['id' => $id, 'local' => '::' . $relative, 'states' => $states];
  }

  /**
   * Judges a relative selector's compounds against the vocabulary.
   *
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector $relative
   *   The selector.
   * @param string $id
   *   The selector's element id, for correctives.
   * @param array|null $known
   *   The element's known-ids entry (knownId()), or NULL when the document
   *   is not at hand (ownership and existence are then the caller's).
   * @param int|null $line
   *   The source line.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector.
   *
   * @return bool
   *   TRUE when every compound passed.
   */
  private function judgeCompounds(RelativeSelector $relative, string $id, ?array $known, ?int $line, array &$errors): bool {
    $owner_tag = $known === NULL ? NULL : self::tagOf($known);
    // The element's own children (a leading `>`, behind the qualified
    // anchor or not) and the types among its descendants are a
    // container's to address: a block has no child elements of the page
    // (its parts' children, `.s-x > *`, are its own rich text and stay
    // addressable).
    $lead = $relative->first()['kind'] === RelativeSelector::ANCHOR_KIND ? ($relative->compounds[1] ?? NULL) : $relative->first();
    $addresses_children = ($lead['combinator'] ?? NULL) === RelativeSelector::CHILD || array_filter($relative->compounds, static fn (array $compound): bool => $compound['kind'] === RelativeSelector::TYPE) !== [];
    if ($addresses_children && $known !== NULL && !$known['container']) {
      $errors[] = new DialectError('BAD_SELECTOR', sprintf('#%s %s: children are addressed on containers only (#%s is a <%s>); a block is styled as a whole (#%s), by its declared parts (#%s .part) or its text tags (#%s p)', $id, $relative, $id, $owner_tag, $id, $id, $id), $line);
      return FALSE;
    }
    return $this->judgeChain($relative->compounds, $id, $relative, $known, [$owner_tag, TRUE], [$owner_tag, TRUE], $line, $errors);
  }

  /**
   * Judges a chain of compounds in a context, the roster walk's way.
   *
   * The context is [owner tag, direct]: the owner a part resolves
   * against (the id's element, then the last type compound; a type
   * compound's owner entry is derived the way the element index derives
   * the id's, so ownership reads one way) and whether the chain still
   * addresses the id's own body (an instance class must exist there). A
   * compound reached by a sibling combinator resolves where its
   * predecessor did (the sibling of a type is another child of the same
   * owner, the sibling of a part a part of the same owner), so the
   * context before the predecessor is restored for it; a sibling LEAD
   * (`:has(+ d-image)`) restores the context the qualified compound was
   * judged in, since its siblings are that owner's children.
   *
   * @param array[] $compounds
   *   The compounds.
   * @param string $id
   *   The selector's element id, for correctives.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector $relative
   *   The whole selector, for correctives.
   * @param array|null $known
   *   The id's known-ids entry, or NULL without a document.
   * @param array{0: ?string, 1: bool} $context
   *   The context the chain starts in.
   * @param array{0: ?string, 1: bool} $previous
   *   The context a sibling lead restores (the one before $context).
   * @param int|null $line
   *   The source line.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector.
   *
   * @return bool
   *   TRUE when every compound landed.
   */
  private function judgeChain(array $compounds, string $id, RelativeSelector $relative, ?array $known, array $context, array $previous, ?int $line, array &$errors): bool {
    foreach ($compounds as $compound) {
      if (in_array($compound['combinator'], [RelativeSelector::NEXT, RelativeSelector::SUBSEQUENT], TRUE)) {
        $context = $previous;
      }
      $previous = $context;
      $context = $this->judgeCompound($compound, $id, $relative, $known, $context, $line, $errors);
      if ($context === NULL) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Judges one compound: its subject in the context, then its qualifiers.
   *
   * A `:has()` argument is a chain walked from the compound's landing (a
   * type's landing owns the argument's parts: `> d-text:has(.link)`); a
   * `:not()` compound with a subject is judged where the qualified
   * compound stands (the negated class is an alternative subject at the
   * same position, never the type's part: `> d-text:not(.section)` on a
   * container declaring `.section`), and one with an implied subject judges
   * only its qualifiers from the landing (`> d-text:not(:has(a))`); an
   * `:is()` qualifier's every member is judged the same way (every
   * alternative's words must be the site's: `> *:is(d-image, d-text)`,
   * `.menu-item:is(:focus-within, :hover) > ul`).
   *
   * @return array{0: ?string, 1: bool}|null
   *   The landing (the context the next compound is judged in), or NULL
   *   when the compound was refused (the error appended).
   */
  private function judgeCompound(array $compound, string $id, RelativeSelector $relative, ?array $known, array $context, ?int $line, array &$errors): ?array {
    [$owner_tag, $direct] = $context;
    $name = $compound['name'];
    switch ($compound['kind']) {
      case RelativeSelector::CLASS_PART:
        $part = substr($name, 1);
        $owners = $this->catalog->partOwners($name);
        if ($owners !== []) {
          // The selector roster of the owner (the id's element, or the
          // last type compound) says whose part the class is: a part
          // of another element is addressed under that element's id
          // or after its type.
          if ($owner_tag !== NULL && !in_array($owner_tag, $owners, TRUE)) {
            $errors[] = new DialectError('UNKNOWN_PART', sprintf('%s is not a part of <%s> (owned by: %s; reach it after its type, #%s %s %s, or under that element\'s own id)', $name, $owner_tag, implode(', ', $owners), $id, $owners[0], $name), $line, [
              'part' => $part,
              'owner' => $owner_tag,
              'declared_parts' => array_keys($this->catalog->partsOf($owner_tag)),
            ], $this->partsHint($owner_tag));
            return NULL;
          }
        }
        elseif ($this->catalog->instanceNamespaceFor($part) !== NULL) {
          // An instance class addresses ONE identified element of the
          // element's rich text, so it must exist in the ACTUAL content
          // when it addresses the id's own body: the staged post-op
          // content when the body was written in the same batch, so
          // create-and-style validates in one round. After a type
          // compound the class addresses many blocks' bodies; existence
          // is not one element's fact then.
          if ($direct && $known !== NULL && !$this->instanceExists($id, $part, $known, $line, $errors)) {
            return NULL;
          }
        }
        else {
          $errors[] = new DialectError('UNKNOWN_PART', sprintf('%s is not a declared part', $name), $line, [
            'part' => $part,
            'owner' => $owner_tag,
            'declared_parts' => array_keys($owner_tag === NULL ? $this->catalog->parts() : $this->catalog->partsOf($owner_tag)),
          ], $this->partsHint($owner_tag));
          return NULL;
        }
        break;

      case RelativeSelector::TYPE:
        // A type compound names one of the owner's children: the spine's
        // type list under a container, nothing under a block (a block
        // has no child elements of the page).
        if ($owner_tag !== NULL && $this->catalog->typesOf($owner_tag) === []) {
          $errors[] = new DialectError('BAD_SELECTOR', sprintf('#%s %s: children are addressed on containers only (%s is a block, so no type follows it); a block is styled as a whole, by its declared parts (.part) or its text tags (p)', $id, $relative, $owner_tag), $line);
          return NULL;
        }
        if ($name !== RelativeSelector::TYPE_CONTAINER && $name !== RelativeSelector::TYPE_BLOCK) {
          $block_type = DialectCatalog::blockTypeForTag($name);
          if ($block_type === NULL || $this->catalog->blockType($block_type) === NULL) {
            $known_types = $this->catalog->types();
            $errors[] = new DialectError('UNKNOWN_TYPE', sprintf('%s is not an element type of this site', $name), $line, [
              'type' => $name,
              'declared_types' => $known_types,
            ], sprintf('Types are spelled as the page language spells the element: %s (%s is any container, %s any other block).', implode(', ', $known_types), RelativeSelector::TYPE_CONTAINER, RelativeSelector::TYPE_BLOCK));
            return NULL;
          }
        }
        $owner_tag = $name;
        $direct = FALSE;
        break;
    }
    $landing = [$owner_tag, $direct];
    foreach ($compound['qualifiers'] as $qualifier) {
      if ($qualifier['name'] === RelativeSelector::HAS) {
        if (!$this->judgeChain($qualifier['argument'], $id, $relative, $known, $landing, $context, $line, $errors)) {
          return NULL;
        }
        continue;
      }
      // A `:not()` argument is one predicate compound, an `:is()` holds
      // several: each is judged where the qualified compound stands (a
      // subject in its context, an implied subject from its landing).
      $members = match ($qualifier['name']) {
        RelativeSelector::NOT => [$qualifier['argument']],
        RelativeSelector::IS => $qualifier['argument'],
        // A position qualifier is structure: nothing of the vocabulary
        // to judge (the roster builder skips it the same way).
        default => [],
      };
      foreach ($members as $member) {
        if ($this->judgeCompound($member, $id, $relative, $known, $member['kind'] === NULL ? $landing : $context, $line, $errors) === NULL) {
          return NULL;
        }
      }
    }
    return $landing;
  }

  /**
   * The corrective for an unknown part: what the owner declares instead.
   *
   * The owner's own parts when the owner is known (the id's element or
   * the last type compound), every declared part with its owners when it
   * is not, and the identity namespaces where rich text declares any.
   *
   * @param string|null $owner
   *   The owner's dialect tag, NULL when no element is at hand.
   */
  private function partsHint(?string $owner): string {
    if ($owner === NULL) {
      $entries = [];
      foreach ($this->catalog->parts() as $selector => $owners) {
        $entries[] = sprintf('%s (%s)', $selector, implode(', ', $owners));
      }
      $hint = sprintf('Declared parts: %s.', implode(', ', $entries) ?: '(none)');
    }
    else {
      $hint = sprintf('Parts of <%s>: %s.', $owner, implode(', ', array_keys($this->catalog->partsOf($owner))) ?: '(none)');
    }
    $namespaces = implode(', ', array_keys($this->catalog->instances()));
    if ($namespaces !== '') {
      $hint .= sprintf(' Rich-text elements are addressed by their identity classes (%s, the classes visible in the body).', $namespaces);
    }
    return $hint;
  }

  /**
   * The known-ids entry a lean node yields: the ONE derivation.
   *
   * Both doors (the document's <style> block and the style op) judge
   * selectors against entries of this shape, built from the same lean
   * node the page language prints, so ownership answers can never differ
   * between them: `container` for a container node, `block_type` the
   * declared inline bundle (NULL for a plugin block and for an undeclared
   * bundle, which the page language prints as <d-block>), `plugin_id`
   * the plugin block's BASE id (field_block for a field node), and `body`
   * the element's rich text (staged
   * content) when its type declares a rich body, else NULL.
   *
   * @param array $node
   *   A lean node (NodeTreeBuilder's shape).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The catalog (for the body contract).
   *
   * @return array{container: bool, block_type: ?string, plugin_id: ?string, body: ?string}
   *   The entry.
   */
  public static function knownId(array $node, DialectCatalog $catalog): array {
    $is_container = ($node['kind'] ?? '') === 'container';
    $block_type = !$is_container && isset($node['block_type']) ? (string) $node['block_type'] : NULL;
    if ($block_type !== NULL && $catalog->blockType($block_type) === NULL) {
      $block_type = NULL;
    }
    $body = NULL;
    $body_spec = $block_type === NULL ? NULL : ($catalog->blockType($block_type)['body'] ?? NULL);
    if ($body_spec !== NULL && ($body_spec['kind'] ?? '') === 'rich') {
      $value = $node['block_fields'][$body_spec['field']] ?? NULL;
      if (is_array($value) && !empty($value['_form_only'])) {
        $value = NULL;
      }
      $body = is_array($value) ? (string) ($value['value'] ?? '') : (string) ($value ?? '');
    }
    return [
      'container' => $is_container,
      'block_type' => $block_type,
      // The base plugin the element wears under (a field node's is
      // field_block): the axis and class views key by it.
      'plugin_id' => $is_container ? NULL : NodeTreeBuilder::pluginBase($node),
      'body' => $body,
    ];
  }

  /**
   * The known-ids map of a lean tree: every identified node, at any depth.
   *
   * Region nodes are transparent. Keyed by the node's identity (its uuid,
   * or its authored id in a document being parsed).
   *
   * @return array<string, array>
   *   Id => knownId() entry.
   */
  public static function knownIds(array $nodes, DialectCatalog $catalog): array {
    $ids = [];
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      $identity = $node['uuid'] ?? $node['id'] ?? NULL;
      if ($identity !== NULL && ($node['kind'] ?? '') !== 'region') {
        $ids[(string) $identity] = self::knownId($node, $catalog);
      }
      if (is_array($node['children'] ?? NULL)) {
        $ids += self::knownIds($node['children'], $catalog);
      }
    }
    return $ids;
  }

  /**
   * The page-language tag a known-ids entry's element prints as.
   */
  private static function tagOf(array $known): string {
    if ($known['container']) {
      return RelativeSelector::TYPE_CONTAINER;
    }
    return $known['block_type'] === NULL ? RelativeSelector::TYPE_BLOCK : DialectCatalog::tagForBlockType((string) $known['block_type']);
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
   * be written in a style value (the tokens of every scale), so a typo is
   * corrected toward the nearest token.
   */
  private function validateValue(string $property, string $value, ?int $line, array &$errors): void {
    foreach ($this->catalog->references($value, MaterialRecord::SLOT_STYLE) as $reference) {
      if ($reference->kind !== ValueKind::Unknown) {
        continue;
      }
      $errors[] = self::unknownReference('UNKNOWN_TOKEN', sprintf('%s in %s is not a published token: no skin token is spelled var(%s)', $reference->spelling, $property, $reference->id), $reference, MaterialRecord::SLOT_STYLE, $this->catalog, 'Reference a published token by its var() spelling (the MATERIALS section in your instructions), or write a single CSS value.', $line);
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
   * style value, a class, an axis attribute value, a blueprint name): the
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
   *
   * @param string $css
   *   The text.
   * @param int $open
   *   The offset of the opening brace.
   * @param int $end
   *   The offset the scan stops at (exclusive).
   */
  public static function matchingBrace(string $css, int $open, int $end): int {
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
