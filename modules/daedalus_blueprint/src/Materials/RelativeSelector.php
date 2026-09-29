<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * A relative selector: the suffix every styling address and key carries.
 *
 * The one grammar behind "what, inside this element, does this rule
 * style". A style map key is `{selectable key}::{selector}`, a variant's
 * or skin's relative key `{slot path}::{selector}`, an address
 * `{family}:{id}::{selector}`; the selector after the `::` is a curated
 * subset of CSS nesting's relative selectors, relative to the element
 * the root names, and it is CSS's own recursive shape:
 *
 *   selector   := chain [ pseudo ] | '&' pseudo
 *   chain      := [ lead ] compound ( combinator compound )*
 *   lead       := '> '                          top level: the owner anchors
 *               | '> ' | '+ ' | '~ '            inside :has(): the qualified compound anchors
 *   combinator := ' ' | ' > ' | ' + ' | ' ~ '
 *   compound   := subject qualifier*
 *   subject    := '*' | tag | type | '.' class
 *   qualifier  := ':has(' chain ')' | ':not(' compound ')'
 *   pseudo     := '::before' | '::after'    the generated box of the last compound
 *   tag        := [a-z][a-z0-9]*            a declared base tag (vocabulary)
 *   type       := 'pb-' [a-z][a-z0-9-]*     pb-container, pb-block, pb-{bundle}
 *   class      := [A-Za-z0-9_-]+            a declared part, or an instance part
 *
 * Structural rules the grammar enforces, each with its one reason (the
 * vocabulary, which tags, types and parts exist, is the roster's, never
 * this class's):
 * - `*` stands only after `>`, `+` or `~`: "every child" or "every later
 *   sibling" is a relation, "every descendant" is not an address.
 * - a part class stands first (the owner's own anatomy), after a type
 *   compound by descent or `>` (that type's anatomy), or after a class by
 *   a sibling combinator (the sibling of a part is a part of the same
 *   owner): a class never qualifies a tag or the anonymous children.
 * - a leading `+` or `~` is refused at the top level (the owner anchors,
 *   and its siblings are outside its own subtree) and admitted inside
 *   `:has()` (the qualified compound anchors, and its siblings are inside
 *   the owner's subtree: `> pb-text:has(+ pb-image)`).
 * - `:has()` never stands anywhere inside a `:has()` argument (CSS's own
 *   rule; one sub-walk depth).
 * - `:not()` takes ONE compound (a predicate the vocabulary can judge,
 *   never a chain); inside it the subject may be implied
 *   (`:not(:has(a))`, CSS's implied universal, the one spelling of "text
 *   blocks without a link"), and `*` and `:not()` are never written there
 *   (degenerate: one predicate keeps one spelling).
 * - a pseudo-element is terminal: `::before` or `::after` glued to the
 *   last compound names that compound's generated box (`.quote::after`,
 *   `> pb-text::before`), nothing follows it, and it never stands inside
 *   `:has()` or `:not()` (a generated box is no tree fact to qualify by).
 *   The owner's own generated box is `&::before` / `&::after`, CSS
 *   nesting's anchor for the element itself, and `&` stands nowhere else
 *   (`& > p` is `> p`, `&` alone is the root with no selector, ``). Other
 *   pseudo-elements (`::marker`, `::first-letter`) are refused: each has
 *   its own property law and lands separately. The record carries the
 *   pseudo on the selector, never on a compound: it is where the box
 *   hangs, not a step of the chain.
 * - no attributes, commas or pseudo-classes other than the two
 *   qualifiers: states and position facts are conditions (the WHEN
 *   axis), never selector text. The terminal pseudo is the only `::` a
 *   selector carries and something always precedes it (a compound or
 *   the anchor), so the FIRST `::` in any key still splits root from
 *   selector (the invariant carriage, the sweeps, capture and every
 *   prefix scan rest on).
 *
 * One canonical spelling: combinators spaced, a lead glued to its space
 * (`> `, `+ `, `~ `), qualifiers glued to their subject and sorted
 * (`:has` before `:not`, then by printed argument), arguments canonical,
 * the pseudo glued to the last compound or the anchor, no trailing space
 * (`.cls`, `p`, `> *`, `> pb-text .link`, `> p + p`,
 * `> pb-text:has(a):not(.featured)`, `&::before`, `.quote::after`).
 * parse() reads the spelling leniently on whitespace only (spaces around
 * combinators and inside parens are free: `>p+p`, `:has( a )`); a space
 * between a subject and `:has` is a descendant combinator followed by a
 * qualifier-only compound, refused outside `:not()`, and a space before
 * `::` is refused likewise (CSS reads `p ::before` as every descendant's
 * box, an address this grammar has no spelling for). The print is always canonical, and
 * the key gates (splitKey(), Address, the slot-key grammar, the
 * validator) admit the canonical spelling only, so a stored key has
 * exactly one shape and two spellings of one selector can never live
 * side by side in a map. Who canonicalizes is the door that takes free
 * text (the page language's CSS parser, the code view's gesture);
 * everything after stores what it was given.
 *
 * The type compounds spell an element the way the page language spells
 * it: `pb-container` names every container (the `kind:container`
 * family), `pb-block` every block that is not a declared inline type (a
 * plugin block or an undeclared bundle: the `kind:block` family, since the
 * dialect prints all of those as `<pb-block>`), and `pb-{bundle}` the
 * inline block type `block_type:{bundle}` (`_` spelled `-`, the dialect's
 * tag rule). typeAddress() is that mapping, typeName() its inverse.
 *
 * The client twin is daedalus_ui's relative-selector.js (beside the
 * roster twin), locked to this class by RelativeSelectorTest's shared
 * battery. Immutable and stringable.
 */
final class RelativeSelector implements \Stringable {

  /**
   * The separator between a root and its selector in every key grammar.
   */
  public const SUFFIX = '::';

  /**
   * The compound kinds.
   */
  public const UNIVERSAL = 'universal';
  public const TAG = 'tag';
  public const TYPE = 'type';
  public const CLASS_PART = 'class';

  /**
   * The combinators: what reaches a compound from the one before it.
   *
   * The first compound's combinator is its lead: `child` for `> `,
   * `descendant` when none is written; inside `:has()` also `next` for
   * `+ ` and `subsequent` for `~ `.
   */
  public const DESCENDANT = 'descendant';
  public const CHILD = 'child';
  public const NEXT = 'next';
  public const SUBSEQUENT = 'subsequent';

  /**
   * The qualifier names.
   */
  public const HAS = 'has';
  public const NOT = 'not';

  /**
   * The combinator symbols by name (the print), and by symbol (the read).
   */
  private const COMBINATOR_SYMBOLS = [
    self::CHILD => '>',
    self::NEXT => '+',
    self::SUBSEQUENT => '~',
  ];
  private const COMBINATORS_BY_SYMBOL = [
    '>' => self::CHILD,
    '+' => self::NEXT,
    '~' => self::SUBSEQUENT,
  ];

  /**
   * The type compound prefix (the page language's element tag prefix).
   */
  public const TYPE_PREFIX = 'pb-';

  /**
   * The type names of the two element-kind families.
   */
  public const TYPE_CONTAINER = 'pb-container';
  public const TYPE_BLOCK = 'pb-block';

  /**
   * The compound charsets.
   */
  public const TAG_PATTERN = '/^[a-z][a-z0-9]*$/';
  public const TYPE_PATTERN = '/^pb-[a-z][a-z0-9-]*$/';
  public const CLASS_PATTERN = '/^\.[A-Za-z0-9_-]+$/';

  /**
   * The pseudo-elements a selector may end in, by name (no `::`).
   */
  public const PSEUDO_BEFORE = 'before';
  public const PSEUDO_AFTER = 'after';
  public const PSEUDOS = [self::PSEUDO_BEFORE, self::PSEUDO_AFTER];

  /**
   * The owner's own anchor: the one subject of its own pseudo-element.
   */
  public const ANCHOR = '&';

  /**
   * The terminal pseudo-element and the trailing whitespace behind it.
   */
  private const PSEUDO_AT_END = '/::(before|after)\s*$/D';

  /**
   * The grammar in one sentence, for every corrective that spells a key.
   *
   * Every hint that names what may follow a `::` composes this sentence
   * rather than restating the grammar, so the teaching can never drift
   * from the parser.
   */
  public const GRAMMAR = 'After "::" comes a relative selector over the element\'s own subtree: ".class-name" (a declared part), "p" (every paragraph inside it, a declared base tag), "> *" (its direct children), "> pb-text" (the text blocks among its children; a type is spelled as the page language spells the element: pb-{block type}, pb-container, pb-block), compounds joined by " " (descendant), " > " (direct child), " + " (the next sibling) or " ~ " (any later sibling), e.g. "> pb-text .link", "> p + p"; a compound narrows by ":has(…)" (a relative selector from that compound: "> pb-text:has(a)", "> pb-text:has(+ pb-image)") and ":not(…)" (one compound it is not: "> *:not(pb-image)", "> pb-text:not(:has(a))"). A selector may end in "::before" or "::after", the generated box of its last compound ("> pb-text::before", ".quote::after"); the element\'s own generated box is "&::before" or "&::after" ("&" stands nowhere else). The pseudo-element is terminal: nothing follows it, it never stands inside ":has(…)" or ":not(…)", and no other pseudo-element ("::marker", "::first-letter") is admitted. No leading "+" or "~", no attributes, commas or other pseudo-classes inside a selector, and no "::" but the terminal pseudo-element: position (first child, last child) is a condition, not selector text.';

  /**
   * Constructs a selector from parsed compounds; use parse().
   *
   * @param array<int, array{combinator: string, kind: string, name: string, qualifiers: array}> $compounds
   *   The compounds in order. Each carries the combinator that reaches it
   *   (one of DESCENDANT, CHILD, NEXT, SUBSEQUENT; the first compound's is
   *   the selector's lead), its kind (one of UNIVERSAL, TAG, TYPE,
   *   CLASS_PART) and its name as it prints (`*`, the tag, the `pb-…`
   *   type, the `.class`), and its qualifiers: a list of
   *   `{name: HAS, argument: compounds}` (a relative selector from this
   *   compound, the same record) or `{name: NOT, argument: compound}`
   *   (one compound with `combinator` NULL, whose `kind` and `name` are
   *   NULL when its subject is implied), sorted canonical. Empty for the
   *   owner's own pseudo-element (`&::before`): the anchor is no compound.
   * @param string|null $pseudo
   *   The terminal pseudo-element by name (PSEUDO_BEFORE, PSEUDO_AFTER),
   *   or NULL when the selector ends in its last compound.
   */
  private function __construct(
    public readonly array $compounds,
    public readonly ?string $pseudo = NULL,
  ) {}

  /**
   * Parses a selector, or NULL when the text is outside the grammar.
   *
   * Lenient on whitespace only: any run of whitespace is one descendant
   * combinator, and `>`, `+`, `~` and the parens need no spaces around
   * them. Everything else is the grammar's letter. The terminal pseudo is
   * read first, so `before` never reads as a tag and the chain parser
   * never sees a `::`.
   */
  public static function parse(string $selector): ?self {
    $pseudo = NULL;
    $head = $selector;
    if (preg_match(self::PSEUDO_AT_END, $selector, $m, PREG_OFFSET_CAPTURE)) {
      $pseudo = $m[1][0];
      $head = substr($selector, 0, $m[0][1]);
      // Glued to its host: a bare pseudo has no host, and a space before
      // `::` is CSS's "every descendant's box", not an address here.
      if ($head === '' || ctype_space($head[-1])) {
        return NULL;
      }
      if (ltrim($head) === self::ANCHOR) {
        return new self([], $pseudo);
      }
    }
    $at = 0;
    $compounds = self::parseChain($head, $at, FALSE, FALSE);
    if ($compounds === NULL) {
      return NULL;
    }
    self::skipSpace($head, $at);
    return $at === strlen($head) ? new self($compounds, $pseudo) : NULL;
  }

  /**
   * Parses a chain (a lead, then compounds behind combinators) from $at.
   *
   * @param string $text
   *   The text.
   * @param int $at
   *   The read position, advanced past the chain (trailing whitespace
   *   left unread).
   * @param bool $in_has
   *   Whether the chain is a `:has()` argument: sibling leads admitted,
   *   `:has()` refused anywhere inside.
   * @param bool $in_not
   *   Whether the chain is inside a `:not()` argument (its compounds may
   *   never carry `:not()`).
   *
   * @return array[]|null
   *   The compounds, or NULL outside the grammar.
   */
  private static function parseChain(string $text, int &$at, bool $in_has, bool $in_not): ?array {
    self::skipSpace($text, $at);
    $combinator = self::DESCENDANT;
    $symbol = $text[$at] ?? '';
    if (isset(self::COMBINATORS_BY_SYMBOL[$symbol])) {
      $combinator = self::COMBINATORS_BY_SYMBOL[$symbol];
      if (!$in_has && $combinator !== self::CHILD) {
        return NULL;
      }
      $at++;
      self::skipSpace($text, $at);
    }
    $compounds = [];
    while (TRUE) {
      $previous = $compounds === [] ? NULL : $compounds[array_key_last($compounds)];
      $compound = self::parseCompound($text, $at, $combinator, $previous, $in_has, $in_not);
      if ($compound === NULL) {
        return NULL;
      }
      $compounds[] = $compound;
      $before = $at;
      self::skipSpace($text, $at);
      $symbol = $text[$at] ?? '';
      if ($symbol === '' || $symbol === ')') {
        return $compounds;
      }
      if (isset(self::COMBINATORS_BY_SYMBOL[$symbol])) {
        $combinator = self::COMBINATORS_BY_SYMBOL[$symbol];
        $at++;
        self::skipSpace($text, $at);
        continue;
      }
      // Anything else glued to the compound (`p.cls`, `p[x]`, `p:hover`)
      // is outside the grammar; behind whitespace it is the next
      // compound by descent.
      if ($at === $before) {
        return NULL;
      }
      $combinator = self::DESCENDANT;
    }
  }

  /**
   * Parses one compound (a subject and its qualifiers) from $at.
   *
   * @param string $text
   *   The text.
   * @param int $at
   *   The read position, advanced past the compound.
   * @param string|null $combinator
   *   The combinator reaching the compound (NULL for a `:not()` argument).
   * @param array|null $previous
   *   The compound before it in the chain, or NULL when first.
   * @param bool $in_has
   *   Whether the compound stands anywhere inside a `:has()` argument.
   * @param bool $in_not
   *   Whether the compound is a `:not()` argument (the subject may be
   *   implied, `*` and `:not()` are refused).
   */
  private static function parseCompound(string $text, int &$at, ?string $combinator, ?array $previous, bool $in_has, bool $in_not): ?array {
    $kind = NULL;
    $name = NULL;
    $char = $text[$at] ?? '';
    if ($char === '*') {
      // `*` is a relation's subject only: every child, every later
      // sibling; never every descendant, never a `:not()` predicate.
      if ($in_not || $combinator === self::DESCENDANT) {
        return NULL;
      }
      $kind = self::UNIVERSAL;
      $name = '*';
      $at++;
    }
    elseif ($char === '.') {
      if (!preg_match('/\G\.[A-Za-z0-9_-]+/', $text, $m, 0, $at)) {
        return NULL;
      }
      // A class stands first, after a type by descent or `>`, or after a
      // class by a sibling combinator; a `:not()` argument is a predicate
      // on its compound, not a chain step.
      $admitted = $in_not || $previous === NULL
        || ($previous['kind'] === self::TYPE && in_array($combinator, [self::DESCENDANT, self::CHILD], TRUE))
        || ($previous['kind'] === self::CLASS_PART && in_array($combinator, [self::NEXT, self::SUBSEQUENT], TRUE));
      if (!$admitted) {
        return NULL;
      }
      $kind = self::CLASS_PART;
      $name = $m[0];
      $at += strlen($m[0]);
    }
    elseif (preg_match('/\G[a-z][a-z0-9-]*/', $text, $m, 0, $at)) {
      $kind = match (TRUE) {
        preg_match(self::TYPE_PATTERN, $m[0]) === 1 => self::TYPE,
        preg_match(self::TAG_PATTERN, $m[0]) === 1 => self::TAG,
        default => NULL,
      };
      if ($kind === NULL) {
        return NULL;
      }
      $name = $m[0];
      $at += strlen($m[0]);
    }
    elseif (!($in_not && $char === ':')) {
      return NULL;
    }
    $qualifiers = [];
    while (($text[$at] ?? '') === ':') {
      if (substr($text, $at, 5) === ':has(') {
        if ($in_has) {
          return NULL;
        }
        $at += 5;
        $argument = self::parseChain($text, $at, TRUE, $in_not);
        if ($argument === NULL) {
          return NULL;
        }
        $qualifiers[] = ['name' => self::HAS, 'argument' => $argument];
      }
      elseif (substr($text, $at, 5) === ':not(') {
        if ($in_not) {
          return NULL;
        }
        $at += 5;
        self::skipSpace($text, $at);
        $argument = self::parseCompound($text, $at, NULL, NULL, $in_has, TRUE);
        if ($argument === NULL) {
          return NULL;
        }
        $qualifiers[] = ['name' => self::NOT, 'argument' => $argument];
      }
      else {
        return NULL;
      }
      self::skipSpace($text, $at);
      if (($text[$at] ?? '') !== ')') {
        return NULL;
      }
      $at++;
    }
    if ($kind === NULL && $qualifiers === []) {
      return NULL;
    }
    usort($qualifiers, static fn (array $a, array $b): int => strcmp(self::printQualifier($a), self::printQualifier($b)));
    return ['combinator' => $combinator, 'kind' => $kind, 'name' => $name, 'qualifiers' => $qualifiers];
  }

  /**
   * Advances $at past any whitespace.
   */
  private static function skipSpace(string $text, int &$at): void {
    while (($text[$at] ?? '') !== '' && ctype_space($text[$at])) {
      $at++;
    }
  }

  /**
   * Parses a selector, refusing anything outside the grammar.
   *
   * @throws \InvalidArgumentException
   *   When the text is not a selector.
   */
  public static function fromString(string $selector): self {
    return self::parse($selector) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a relative selector. %s', $selector, self::GRAMMAR));
  }

  /**
   * Whether a string is a selector in its one canonical spelling.
   */
  public static function isCanonical(string $selector): bool {
    return self::parse($selector)?->__toString() === $selector;
  }

  /**
   * Splits a key at its first "::" into the root and its selector.
   *
   * The one split every key grammar shares (element keys, slot keys,
   * addresses): the root is everything before the first "::", the
   * selector everything after it, admitted in its canonical spelling
   * only. A key without "::" is a root with no selector.
   *
   * @return array{0: string, 1: ?self}|null
   *   The root and the selector (NULL when the key carries none), or
   *   NULL when a suffix is present but is not a canonical selector.
   */
  public static function splitKey(string $key): ?array {
    $pos = strpos($key, self::SUFFIX);
    if ($pos === FALSE) {
      return [$key, NULL];
    }
    $suffix = substr($key, $pos + strlen(self::SUFFIX));
    $selector = self::parse($suffix);
    if ($selector === NULL || (string) $selector !== $suffix) {
      return NULL;
    }
    return [substr($key, 0, $pos), $selector];
  }

  /**
   * Continues a rule's selector by a suffix, by descent.
   *
   * The one rebase every consumer of "this rule wears that" shares: a
   * variant's element-relative suffix (`.cls`, `> *`, `p`) continued
   * under the rule's targets reads `{rule} {suffix}` (`> pb-text` under
   * `.link` is `> pb-text .link`; under `> *`, `> pb-text > *`), and the
   * grammar decides admissibility exactly as it does for an authored key:
   * a class after a tag (`a` under `.cls`) is refused, so a suffix the
   * rule cannot carry answers NULL and its styles land nowhere. A pseudo
   * binds to the rule's landing: `&::before` under `> pb-text` is
   * `> pb-text::before` (the anchor is the rule's own targets),
   * `.link::before` under it `> pb-text .link::before`; a rule already
   * ending in a pseudo admits no continuation (nothing follows a
   * generated box). Delivery (CssPrinter::printFragmentStyles() under a
   * rule), detach materialization and the part carrier of apply all
   * continue through this, never by writing a second `::`. The client
   * twin is relative-selector.js under().
   *
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector $rule
   *   The rule's selector.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector $suffix
   *   The suffix to continue by.
   *
   * @return self|null
   *   The continued selector, or NULL when the grammar refuses it.
   */
  public static function under(RelativeSelector $rule, RelativeSelector $suffix): ?self {
    if ($rule->pseudo !== NULL) {
      return NULL;
    }
    if ($suffix->compounds === []) {
      return new self($rule->compounds, $suffix->pseudo);
    }
    return self::parse((string) $rule . ' ' . (string) $suffix);
  }

  /**
   * Whether a selector continues a rule's by descent: under()'s inverse.
   *
   * The read every "this rule and its continuations" consumer shares (the
   * CSS print of a rule key, the clipboard copy at a rule): a candidate
   * continues the rule when some suffix continued under the rule spells
   * it, so `> pb-text .link`, `> pb-text > *` and the rule's own box
   * `> pb-text::before` continue `> pb-text`, while `> pb-text` itself,
   * `> pb-textual` and a sibling `> pb-container` do not. A rule ending
   * in a pseudo admits none (under()'s rule). The client twin is
   * variant-scope.js relativeUnder().
   *
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector $rule
   *   The rule's selector.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector $candidate
   *   The selector that may continue it.
   */
  public static function continues(RelativeSelector $rule, RelativeSelector $candidate): bool {
    if ($rule->pseudo !== NULL || $candidate->equals($rule)) {
      return FALSE;
    }
    $prefix = (string) $rule;
    $text = (string) $candidate;
    if (str_starts_with($text, $prefix . ' ')) {
      $suffix = self::parse(substr($text, strlen($prefix) + 1));
    }
    elseif (str_starts_with($text, $prefix . self::SUFFIX)) {
      $suffix = self::parse(self::ANCHOR . substr($text, strlen($prefix)));
    }
    else {
      return FALSE;
    }
    if ($suffix === NULL) {
      return FALSE;
    }
    $under = self::under($rule, $suffix);
    return $under !== NULL && $under->equals($candidate);
  }

  /**
   * The first compound.
   *
   * @return array{combinator: string, kind: string, name: string, qualifiers: array}
   *
   * @throws \LogicException
   *   When the selector is the owner's own pseudo-element (`&::before`):
   *   it has no compounds, the box hangs on the owner itself, and a
   *   consumer asking for a compound cannot serve that shape.
   */
  public function first(): array {
    return $this->compounds[0] ?? throw $this->noCompounds();
  }

  /**
   * The last compound: the one the rule's targets are, the one the
   * roster walk lands on, and the host of a terminal pseudo-element.
   *
   * @return array{combinator: string, kind: string, name: string, qualifiers: array}
   *
   * @throws \LogicException
   *   When the selector is the owner's own pseudo-element (see first()).
   */
  public function last(): array {
    return $this->compounds === [] ? throw $this->noCompounds() : $this->compounds[array_key_last($this->compounds)];
  }

  /**
   * The refusal of a compound read on the owner's own pseudo-element.
   */
  private function noCompounds(): \LogicException {
    return new \LogicException(sprintf('"%s" has no compounds: the owner\'s own pseudo-element hangs on the owner itself.', $this));
  }

  /**
   * How many compounds the selector chains.
   */
  public function count(): int {
    return count($this->compounds);
  }

  /**
   * Whether the selector begins with a part class: the owner's own anatomy.
   *
   * Reads the first subject, qualified or not, a pseudo behind it or not
   * (a part's generated box is inside the part's anatomy). The one shape
   * the delivery donut still bounds (a part's class name recurs in nested
   * identical anatomy); every other selector reaches its whole subtree.
   * The owner's own pseudo (`&::before`) has no first subject.
   */
  public function isPartFirst(): bool {
    return ($this->compounds[0]['kind'] ?? NULL) === self::CLASS_PART;
  }

  /**
   * Whether the selector is exactly the element's direct children (`> *`).
   *
   * A qualified compound (`> *:not(pb-image)`) or a pseudo behind it
   * (`> *::before`) is a rule of its own.
   */
  public function isChildren(): bool {
    return $this->isOne(self::CHILD, self::UNIVERSAL);
  }

  /**
   * Whether the selector is exactly one part class (`.cls`, `> .cls`).
   *
   * A qualified compound (`.cls:has(a)`) or a pseudo behind it
   * (`.cls::after`) is a rule of its own.
   */
  public function isPart(): bool {
    return $this->pseudo === NULL && count($this->compounds) === 1 && $this->compounds[0]['kind'] === self::CLASS_PART && $this->compounds[0]['qualifiers'] === [];
  }

  /**
   * Whether the selector is exactly one tag (`p`): the tag tier.
   *
   * A qualified compound (`p:has(a)`) or a pseudo behind it (`p::before`)
   * is a rule of its own.
   */
  public function isTag(): bool {
    return $this->isOne(self::DESCENDANT, self::TAG);
  }

  /**
   * Whether the selector is one unqualified compound of a combinator and
   * kind, with no pseudo behind it.
   */
  private function isOne(string $combinator, string $kind): bool {
    return $this->pseudo === NULL
      && count($this->compounds) === 1
      && $this->compounds[0]['combinator'] === $combinator
      && $this->compounds[0]['kind'] === $kind
      && $this->compounds[0]['qualifiers'] === [];
  }

  /**
   * Whether the selector crosses into children: a relation or a type at
   * the top level.
   *
   * A rule shaped like this addresses other elements' roots by relation
   * (`>`, `+`, `~` or a type anywhere in the chain), so its delivery
   * reaches past the owner's own anatomy on purpose. A qualifier's
   * argument never decides this: it narrows a compound, it reaches
   * nothing of its own.
   */
  public function reachesChildren(): bool {
    foreach ($this->compounds as $compound) {
      if ($compound['combinator'] !== self::DESCENDANT || $compound['kind'] === self::TYPE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The class names the selector carries, in print order (`.cls` spelled).
   *
   * Every class subject counts, the qualifiers' arguments included: a
   * class the selector names anywhere is a part the selector depends on
   * (an instance part inside `:not()` travels no better than one in the
   * chain).
   *
   * @return string[]
   */
  public function classes(): array {
    return self::classesOf($this->compounds);
  }

  /**
   * The class subjects of compounds and their qualifiers, in print order.
   *
   * @return string[]
   */
  private static function classesOf(array $compounds): array {
    $classes = [];
    foreach ($compounds as $compound) {
      if ($compound['kind'] === self::CLASS_PART) {
        $classes[] = $compound['name'];
      }
      foreach ($compound['qualifiers'] as $qualifier) {
        $argument = $qualifier['name'] === self::HAS ? $qualifier['argument'] : [$qualifier['argument']];
        $classes = array_merge($classes, self::classesOf($argument));
      }
    }
    return $classes;
  }

  /**
   * The root address a type compound names.
   *
   * `pb-container` is the container kind (every layout), `pb-block` the
   * block kind (the plugin blocks and undeclared bundles the page
   * language prints as `<pb-block>`), `pb-{bundle}` the inline block type.
   *
   * @param string $type
   *   A type compound name (`pb-text`).
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address
   *   The root address.
   *
   * @throws \InvalidArgumentException
   *   When the name is not a type compound.
   */
  public static function typeAddress(string $type): Address {
    if (!preg_match(self::TYPE_PATTERN, $type)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a type compound (pb-{block type}, pb-container, pb-block).', $type));
    }
    return match ($type) {
      self::TYPE_CONTAINER => Address::kind(Address::KIND_CONTAINER),
      self::TYPE_BLOCK => Address::kind(Address::KIND_BLOCK),
      default => Address::blockType(str_replace('-', '_', substr($type, strlen(self::TYPE_PREFIX)))),
    };
  }

  /**
   * The type compound spelling a root address, or NULL when it has none.
   *
   * The inverse of typeAddress(): `block_type:{bundle}` spells
   * `pb-{bundle}`, `kind:container` `pb-container`, `kind:block`
   * `pb-block`; a layout, a block plugin, a field, an entity and a base
   * tag have no type spelling (the page language names none of them by
   * tag).
   */
  public static function typeName(Address $address): ?string {
    if (!$address->isRoot()) {
      return NULL;
    }
    return match (TRUE) {
      $address->family === Address::FAMILY_BLOCK_TYPE => self::TYPE_PREFIX . str_replace('_', '-', $address->id),
      $address->isKind() && $address->id === Address::KIND_CONTAINER => self::TYPE_CONTAINER,
      $address->isKind() && $address->id === Address::KIND_BLOCK => self::TYPE_BLOCK,
      default => NULL,
    };
  }

  /**
   * Whether two selectors print the same.
   */
  public function equals(RelativeSelector $other): bool {
    return (string) $this === (string) $other;
  }

  /**
   * The canonical spelling (print() and the string cast are one).
   */
  public function print(): string {
    return (string) $this;
  }

  /**
   * The canonical spelling of a chain of compounds.
   *
   * Each compound behind its combinator (a lead glued to its space, a
   * descendant combinator the one space between compounds), the subject
   * glued to its qualifiers.
   */
  public static function printCompounds(array $compounds): string {
    $printed = '';
    foreach ($compounds as $i => $compound) {
      if ($i > 0) {
        $printed .= ' ';
      }
      if ($compound['combinator'] !== self::DESCENDANT) {
        $printed .= self::COMBINATOR_SYMBOLS[$compound['combinator']] . ' ';
      }
      $printed .= self::printCompound($compound);
    }
    return $printed;
  }

  /**
   * The canonical spelling of one compound without its combinator.
   */
  public static function printCompound(array $compound): string {
    return ($compound['name'] ?? '') . implode('', array_map(self::printQualifier(...), $compound['qualifiers']));
  }

  /**
   * The canonical spelling of one qualifier.
   */
  private static function printQualifier(array $qualifier): string {
    return $qualifier['name'] === self::HAS
      ? ':has(' . self::printCompounds($qualifier['argument']) . ')'
      : ':not(' . self::printCompound($qualifier['argument']) . ')';
  }

  /**
   * The canonical spelling: the chain (or the anchor) with the pseudo
   * glued to it.
   */
  public function __toString(): string {
    return ($this->compounds === [] ? self::ANCHOR : self::printCompounds($this->compounds))
      . ($this->pseudo === NULL ? '' : self::SUFFIX . $this->pseudo);
  }

}
