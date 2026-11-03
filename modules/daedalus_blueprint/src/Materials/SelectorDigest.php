<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The selector digester: the one reader of typed or pasted selector text.
 *
 * The relative selector grammar (RelativeSelector) parses what the browser
 * parses; the digester sits on top of it with the three moves every door
 * (the code view's typed line, the paste door, the editable prelude, the
 * dialect's parser behind its `#id` root) needs before a selector becomes
 * a key, so no door carries a grammar of its own:
 *
 * - NESTING SUGAR. `&` is the owner: `&` alone is the owner itself (the
 *   empty selector OWNER, the element's own key), `& > ul` is `> ul`,
 *   `& p` is `p`, while `&.open > ul` and `&:not(:last-child) > *` keep
 *   the qualified anchor; a bare relative prelude (`> ul`, `li a`) is
 *   already relative; a leading `:` is the owner's own pseudo-class
 *   (`:hover` is `&:hover`, `::before` is `&::before`), the way the
 *   dialect reads a nested prelude.
 * - THE PEEL. A pseudo-class on the LAST compound (or on the bare anchor)
 *   that the editor can pose comes off into the condition compound as a
 *   CLAUSE of the condition grammar (ConditionClause): a REFERENCE when
 *   its wrapper names a published posable condition (`a:hover` is `a`
 *   under `hover`, `&:hover` the owner under `hover`, `&:hover::before`
 *   is `&::before` under `hover`, `> d-text:hover:has(a)` is `> d-text:
 *   has(a)` under `hover`), a LITERAL when the roster does not publish it
 *   but a stamp can pose it (`input:checked` is `input` under `:checked`
 *   on a site without `checked`; whether the site admits the literal is
 *   the validator's verdict by enforcement mode). The peel is by NAME,
 *   never by the token's place in the text (the dialect's trailing-token
 *   peel left `> d-text:hover:has(a)` with `:hover` as a qualifier).
 *   Every other pseudo-class stays in the selector as a qualifier: on a
 *   non-terminal compound (`li:hover > ul`, `&:hover > ul`: the reveal
 *   patterns), inside `:not()` or `:has()`, a position (`:first-child`,
 *   structure, never a condition), a published name the editor cannot
 *   pose, or a state no stamp stands in for (`a:visited`, `:target`:
 *   ConditionClause::UNPOSABLE_STATES): whether such a qualifier is
 *   admitted is the roster's verdict by enforcement mode, never the
 *   digester's (grammar is CSS's, admission is policy). The peeled
 *   clauses spell the compound as the condition grammar spells it
 *   (ConditionClause::canonical()), one per axis.
 * - LISTS. A selector list is ONE rule with several targets, as CSS
 *   reads a comma, so it folds into `:is()`, CSS's own disjunction and
 *   the one home a list has in a key. Each member is read as above
 *   (sugar, peel, canonical), duplicates collapse, then the members are
 *   FACTORED: they fold when they have the same number of compounds, the
 *   same combinators and the same pseudo-element (or none) and differ at
 *   exactly one compound; the host compound there keeps what every
 *   member shares (the subject when all agree, else `*`; the qualifiers
 *   they all carry) and each member's remainder is an `:is()` member
 *   (`h1, h2, h3` is `*:is(h1, h2, h3)`; `> p, > div` is `> *:is(div,
 *   p)`; `.menu-item:hover > ul, .menu-item:focus-within > ul` is
 *   `.menu-item:is(:focus-within, :hover) > ul`; `a:hover, button:hover`
 *   is `*:is(a, button)` under `hover`, since the peel runs first; a
 *   member that already holds `:is()` where the fold lands flattens into
 *   it). The answer is the canonical spelling the grammar prints (`:is()`
 *   members sorted, the star of a chain compound printed) and reports
 *   the fold, so the code view's notice reads the digester rather than
 *   the text. Refused (SELECTOR_LIST): members peeling different
 *   conditions (a rule holds one), an empty member, a list that shares
 *   no shape or differs at two compounds (separate rules; `:is()` over
 *   chains is nobody's ask), a member whose remainder is empty (`a, a.x`:
 *   the author may have mistyped), and `:is()` holding a posable state
 *   on the LAST compound (`a:is(:hover, :focus-visible)`, typed or
 *   folded: a disjunctive condition, and the condition model holds one
 *   state per axis; Lily's states stand on the parent compound, which is
 *   structure). A single member after collapsing is no list (`a, a` is
 *   `a`, not folded).
 * - THE WHEN. An at-rule prelude (`@media …`, `@container …`, `@supports
 *   …`) is a condition with no selectors, read by the condition grammar:
 *   a literal equal to a published wrapper, case, spacing and colon
 *   spacing folded, is that REFERENCE (`@container (max-width: 767px)`
 *   is `narrow`, the same move as `:hover` to `hover`); any other query
 *   the grammar reads is a LITERAL in its canonical spelling (`@media
 *   (hover: hover)`, `@container (min-width: 500px)`, `@supports
 *   (display: grid)`), admitted or refused by the validator's mode;
 *   text the grammar cannot read (no feature test, unbalanced parens,
 *   features spanning two axes, an at-rule of another kind) is refused.
 *
 * The refusals carry the dialect's codes and correctives, owner-relative
 * (a caller with an `#id` may prefix them): BAD_SELECTOR for `html` /
 * `body` / `:root`, another element's `#id`, a top-level leading sibling
 * combinator, `&` anywhere but first, a pseudo-element behind a space,
 * inside a qualifier, followed by anything or of another name, a peel
 * that leaves no address (`*:hover` leaves `*`, every descendant), two
 * states on one axis, and anything else outside the grammar (the hint is
 * a short form of RelativeSelector::GRAMMAR, which the dialect may
 * append whole); SELECTOR_LIST for a list that does not fold (above);
 * UNKNOWN_WRAPPER for an `@` prelude the condition grammar cannot read.
 *
 * reach() is the canvas paint's read: a canonical selector with its
 * posable pseudo-class qualifiers removed at every compound (`li:hover >
 * ul` is `li > ul`, `&:hover > ul` is `> ul`, `> d-text:has(a:hover)` is
 * `> d-text:has(a)`), because such a qualifier matches nothing in the DOM
 * until the state holds, while position, class and attribute qualifiers
 * stay (the browser evaluates them live). Inside `:not()` a posable state
 * stays too (`li:not(:hover)` holds for every unhovered item, which is
 * the reach). Inside `:is()` the states strip per member: a member left
 * bare drops and an `:is()` left empty drops (`.menu-item:is(:focus-within,
 * :hover) > ul` reaches `.menu-item > ul`; `.menu-item:is(.open, :hover)
 * > ul` reaches `.menu-item.open > ul`). A `:has()` whose chain would be
 * left with a bare universal by descent is dropped (`li:has(:hover)`
 * reaches `li`); NULL when nothing addressable is left.
 *
 * Pure: the roster arrives as options (`conditions`: the published table,
 * name => {wrapper, axis}, the plugin manager's getConditions() and the
 * client guide payload's shape; `posable`: the published names the editor
 * can pose, every pseudo-class condition when omitted, since every
 * published condition is posable today). The client twin is daedalus_ui's
 * selector-digest.js, locked to this class by SelectorDigestTest over the
 * shared battery tests/js/selector-digest-cases.mjs (cases carry their
 * recorded answer, so a wrong expectation fails both sides).
 */
final class SelectorDigest {

  /**
   * The refusal codes (the dialect's).
   */
  public const BAD_SELECTOR = 'BAD_SELECTOR';
  public const SELECTOR_LIST = 'SELECTOR_LIST';
  public const UNKNOWN_WRAPPER = 'UNKNOWN_WRAPPER';

  /**
   * The owner itself: the selector of the element's own key, no suffix.
   */
  public const OWNER = '';

  /**
   * The subjects outside every element's subtree.
   */
  private const OUTSIDE = ['html', 'body'];

  private const HINT_OWN = 'Style the element itself (":hover" its state, "&.open" its class), its parts (".part"), its tags ("p"), its children ("> *") or its descendants ("> d-text .link"); the page\'s root, html and body are no element\'s subtree.';
  private const HINT_GRAMMAR = 'Compounds join by " ", " > ", " + " or " ~ " ("> d-text .link", "> p + p"); a compound is "*", a tag, a type ("d-text") or a part (".part") with its qualifiers glued to it (".active", "[aria-expanded=\"true\"]", ":hover", ":first-child", ":nth-child(2n+1)", ":has(a)", ":not(.x)", ":is(h1, h2)"); the element itself is "&" ("&.open > ul"), its own state a leading pseudo-class (":hover"); a pseudo-element ends the selector ("::before", "::after", "::marker"). No bare "*" by descent, no leading "+" or "~", no ":has()" inside ":has()", one compound inside ":not()", no ":is()" inside ":not()" or ":is()".';
  private const HINT_LIST = 'A selector list is one rule: members of one shape differing at one compound fold into ":is()" there ("h1, h2" is "*:is(h1, h2)", "> p, > div" is "> *:is(div, p)", "li:hover > ul, li:focus-within > ul" is "li:is(:focus-within, :hover) > ul"); rules of different shapes are separate rules.';
  private const HINT_LIST_CONDITION = 'Each rule holds one condition ("a:hover { }" then "a:focus { }"); a list folds into one rule under the condition its members share ("a:hover, button:hover { }" is "*:is(a, button)" under hover).';
  private const HINT_LIST_REDUNDANT = 'Drop the member the others cover, or write the rule as that member alone ("a, a.x" styles every "a": write "a").';
  private const HINT_LIST_STATE = 'A rule holds one state on the element it addresses ("a:hover { }" then "a:focus-visible { }"); alternatives in ":is()" stand on the compounds above it ("li:is(:focus-within, :hover) > ul").';

  /**
   * Digests selector or at-rule prelude text relative to an owner.
   *
   * @param string $text
   *   The typed or pasted prelude: a relative selector, nesting sugar, a
   *   selector list, or an `@` prelude.
   * @param array{conditions?: ?array<string, array{wrapper?: string, axis?: string}>, posable?: ?string[]} $options
   *   The published condition table and the names the editor can pose
   *   (every pseudo-class condition when omitted).
   *
   * @return array{selector: ?string, condition: ?string, refusal: ?array{code: string, message: string, hint: string}, folded: bool}
   *   The one canonical selector (OWNER for the owner itself; NULL for a
   *   wrapper alone or a refusal), the condition compound (NULL when none
   *   peeled), the refusal (NULL when the text digested), and whether a
   *   list was folded into `:is()`.
   */
  public static function digest(string $text, array $options = []): array {
    $roster = self::roster($options);
    $whole = trim($text);
    if (str_starts_with($whole, '@')) {
      $clause = ConditionClause::clause($whole, $roster['table']);
      if ($clause['kind'] !== ConditionClause::UNKNOWN) {
        return ['selector' => NULL, 'condition' => $clause['text'], 'refusal' => NULL, 'folded' => FALSE];
      }
      return self::refuse(
        self::UNKNOWN_WRAPPER,
        sprintf('"%s" is not a condition: a @media or @container prelude tests features in parentheses ("(hover: hover)"), all on one axis, and @supports tests a declaration', $whole),
        sprintf('A condition is a published wrapper (%s) or a literal the browser knows: "@media (hover: hover)", "@container (min-width: 500px)", "@supports (display: grid)"; a second axis nests as its own block.', $roster['published'] === [] ? 'none published' : implode('; ', $roster['published'])),
      );
    }
    $members = self::splitList($whole);
    if (count($members) > 1 && in_array('', $members, TRUE)) {
      return self::refuse(self::SELECTOR_LIST, sprintf('"%s": an empty selector in the list', $whole), 'Remove the stray comma.');
    }
    $selectors = [];
    $condition = FALSE;
    $first_member = NULL;
    foreach ($members as $member) {
      $answer = self::digestOne($member, $roster);
      if (isset($answer['refusal'])) {
        return $answer;
      }
      $peeled = $answer['clauses'] === [] ? NULL : ConditionClause::canonical(implode(ConditionClause::DELIMITER, $answer['clauses']), $roster['table']);
      if ($condition === FALSE) {
        $condition = $peeled;
        $first_member = $member;
      }
      elseif ($peeled !== $condition) {
        return self::refuse(
          self::SELECTOR_LIST,
          sprintf('"%s": a selector list shares one condition; "%s" and "%s" peel different ones (%s, %s)', $whole, $first_member, $member, $condition ?? 'none', $peeled ?? 'none'),
          self::HINT_LIST_CONDITION,
        );
      }
      if (!in_array($answer['selector'], $selectors, TRUE)) {
        $selectors[] = $answer['selector'];
      }
    }
    $condition = $condition === FALSE ? NULL : $condition;
    if (count($selectors) === 1) {
      return ['selector' => $selectors[0], 'condition' => $condition, 'refusal' => NULL, 'folded' => FALSE];
    }
    $folded = self::fold($selectors, $whole);
    if (is_array($folded)) {
      return $folded;
    }
    return ['selector' => $folded, 'condition' => $condition, 'refusal' => NULL, 'folded' => TRUE];
  }

  /**
   * Folds two or more distinct canonical selectors into one `:is()` rule.
   *
   * The factoring: the members share a shape (the same number of
   * compounds, the same combinators, the same pseudo-element or none, the
   * anchor at the same place) and differ at exactly one compound; the
   * host compound there keeps the subject when all agree (else `*`) and
   * the qualifiers all carry, and each member's remainder is an `:is()`
   * member. A class subject is a class among the qualifiers for the
   * factoring (`.a.x, .b.x` is `.x:is(.a, .b)`: the grammar settles the
   * subject again on the re-parse); a `*` subject is no remainder; a
   * remainder that is `:is()` alone flattens into the fold (`*:is(h1,
   * h2), h3` is `*:is(h1, h2, h3)`).
   *
   * @param string[] $selectors
   *   The distinct canonical selectors, two or more.
   * @param string $whole
   *   The typed list, for the refusals.
   *
   * @return string|array
   *   The canonical folded selector, or a refusal answer (SELECTOR_LIST:
   *   no shared shape, a redundant member, an `:is()` the fold would
   *   nest).
   */
  private static function fold(array $selectors, string $whole): string|array {
    $parsed = [];
    foreach ($selectors as $selector) {
      $record = $selector === self::OWNER ? NULL : RelativeSelector::parse($selector);
      $parsed[] = ['compounds' => $record?->compounds ?? [], 'pseudo' => $record?->pseudo];
    }
    $first = $parsed[0];
    $differing = [];
    foreach ($parsed as $member) {
      if (count($member['compounds']) !== count($first['compounds']) || $member['pseudo'] !== $first['pseudo']) {
        return self::noShape($whole);
      }
      foreach ($member['compounds'] as $i => $compound) {
        $model = $first['compounds'][$i];
        if ($compound['combinator'] !== $model['combinator'] || ($compound['kind'] === RelativeSelector::ANCHOR_KIND) !== ($model['kind'] === RelativeSelector::ANCHOR_KIND)) {
          return self::noShape($whole);
        }
        if (RelativeSelector::printCompound($compound) !== RelativeSelector::printCompound($model)) {
          $differing[$i] = TRUE;
        }
      }
    }
    if (count($differing) !== 1) {
      return self::noShape($whole);
    }
    $at = array_key_first($differing);
    $bags = array_map(static fn (array $member): array => self::bag($member['compounds'][$at]), $parsed);
    $subject = $bags[0]['subject'];
    $shared = $bags[0]['qualifiers'];
    foreach ($bags as $bag) {
      if ($bag['subject'] !== $subject) {
        $subject = NULL;
      }
      $prints = array_map(RelativeSelector::printQualifier(...), $bag['qualifiers']);
      $shared = array_values(array_filter($shared, static fn (array $qualifier): bool => in_array(RelativeSelector::printQualifier($qualifier), $prints, TRUE)));
    }
    $shared_prints = array_map(RelativeSelector::printQualifier(...), $shared);
    $members = [];
    $seen = [];
    foreach ($bags as $i => $bag) {
      $own = $subject === NULL ? $bag['subject'] : NULL;
      $qualifiers = array_values(array_filter($bag['qualifiers'], static fn (array $qualifier): bool => !in_array(RelativeSelector::printQualifier($qualifier), $shared_prints, TRUE)));
      $remainders = [];
      if ($own === NULL && count($qualifiers) === 1 && $qualifiers[0]['name'] === RelativeSelector::IS) {
        $remainders = $qualifiers[0]['argument'];
      }
      elseif (array_filter($qualifiers, static fn (array $qualifier): bool => $qualifier['name'] === RelativeSelector::IS) !== []) {
        return self::refuse(
          self::SELECTOR_LIST,
          sprintf('"%s": "%s" already holds alternatives where the fold would put the list\'s, and ":is()" never nests; write them as separate rules', $whole, $selectors[$i]),
          self::HINT_LIST,
        );
      }
      elseif ($own === NULL && $qualifiers === []) {
        return self::refuse(
          self::SELECTOR_LIST,
          sprintf('"%s": "%s" already covers the others; a list member adds an alternative, and one the rest already cover is a slip', $whole, $selectors[$i]),
          self::HINT_LIST_REDUNDANT,
        );
      }
      else {
        $remainders[] = [
          'combinator' => NULL,
          'kind' => $own['kind'] ?? NULL,
          'name' => $own['name'] ?? NULL,
          'qualifiers' => $qualifiers,
        ];
      }
      foreach ($remainders as $remainder) {
        $print = RelativeSelector::printCompound($remainder);
        if (isset($seen[$print])) {
          return self::refuse(
            self::SELECTOR_LIST,
            sprintf('"%s": "%s" already covers the others; a list member adds an alternative, and one the rest already cover is a slip', $whole, $selectors[$i]),
            self::HINT_LIST_REDUNDANT,
          );
        }
        $seen[$print] = TRUE;
        $members[] = $remainder;
      }
    }
    $compounds = $first['compounds'];
    $compounds[$at] = [
      'combinator' => $compounds[$at]['combinator'],
      'kind' => $subject['kind'] ?? RelativeSelector::UNIVERSAL,
      'name' => $subject['name'] ?? '*',
      'qualifiers' => [...$shared, ['name' => RelativeSelector::IS, 'argument' => $members]],
    ];
    $spelled = self::spell($compounds, $first['pseudo']);
    return RelativeSelector::parse($spelled)?->__toString()
      ?? throw new \LogicException(sprintf('The fold of "%s" spelled "%s", which the grammar refuses.', $whole, $spelled));
  }

  /**
   * The refusal for a list that shares no shape or differs at two places.
   */
  private static function noShape(string $whole): array {
    return self::refuse(
      self::SELECTOR_LIST,
      sprintf('"%s": these rules share no shape; write them as separate rules', $whole),
      self::HINT_LIST,
    );
  }

  /**
   * A compound as the factoring reads it: a subject and a qualifier bag.
   *
   * A class subject goes to the bag as a class qualifier and a `*`
   * subject is none, so what two compounds share is read over the whole
   * bag; the grammar settles the subject again when the fold is parsed.
   *
   * @return array{subject: ?array{kind: string, name: string}, qualifiers: array[]}
   *   The subject (NULL when `*`, implied or a class) and the bag.
   */
  private static function bag(array $compound): array {
    $qualifiers = $compound['qualifiers'];
    $subject = NULL;
    if ($compound['kind'] === RelativeSelector::CLASS_PART) {
      $qualifiers[] = ['name' => RelativeSelector::CLASS_QUALIFIER, 'argument' => substr($compound['name'], 1)];
    }
    elseif ($compound['kind'] !== NULL && $compound['kind'] !== RelativeSelector::UNIVERSAL) {
      $subject = ['kind' => $compound['kind'], 'name' => $compound['name']];
    }
    return ['subject' => $subject, 'qualifiers' => $qualifiers];
  }

  /**
   * The structural reach of a canonical selector, for the canvas paint.
   *
   * @param string $selector
   *   A canonical relative selector (OWNER for the owner itself).
   * @param array $options
   *   As digest()'s.
   *
   * @return string|null
   *   The canonical reach (the selector with its posable pseudo-class
   *   qualifiers removed), or NULL when the selector is outside the
   *   grammar or nothing addressable is left.
   */
  public static function reach(string $selector, array $options = []): ?string {
    if ($selector === self::OWNER) {
      return self::OWNER;
    }
    $parsed = RelativeSelector::parse($selector);
    if ($parsed === NULL) {
      return NULL;
    }
    $roster = self::roster($options);
    $compounds = self::stripCompounds($parsed->compounds, $roster);
    if ($compounds !== [] && $compounds[0]['kind'] === RelativeSelector::ANCHOR_KIND && $compounds[0]['qualifiers'] === []) {
      array_shift($compounds);
    }
    $spelled = self::spell($compounds, $parsed->pseudo);
    if ($spelled === self::OWNER) {
      return self::OWNER;
    }
    return RelativeSelector::parse($spelled)?->__toString();
  }

  /**
   * Digests one list member: `{selector, states}` or a refusal answer.
   */
  private static function digestOne(string $member, array $roster): array {
    $text = trim($member);
    $masked = self::mask($text);
    if (preg_match('/#([^\s>+~.:\[,]*)/', $masked, $m)) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": "#%s" addresses another element; a rule styles inside this element\'s own subtree', $text, $m[1]),
        'Style the other element under its own rules, or reach it from here by relation ("> d-text", "> * + *").',
      );
    }
    if (preg_match('/\s::/', $masked)) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": a pseudo-element is glued to the compound whose box it is ("&::after" the element\'s own box, "> d-text::before" each text block\'s); "::" behind a space would address every descendant\'s box', $text),
        'Glue the pseudo-element to the compound whose box it is ("&::after", "li::marker").',
      );
    }
    if ($masked !== '' && strpos($masked, RelativeSelector::ANCHOR, 1) !== FALSE) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": "&" is the element itself and stands first ("&:hover > ul", "&.open .menu")', $text),
        'A rule styles inside this element\'s own subtree, so the element starts every selector; "p &" would style it inside a paragraph, which is its ancestors\' business.',
      );
    }
    // The nesting sugar.
    $sugared = $text;
    if ($text === '' || $text === RelativeSelector::ANCHOR) {
      return ['selector' => self::OWNER, 'clauses' => []];
    }
    if ($text[0] === RelativeSelector::ANCHOR) {
      if (preg_match('/^[\s>+~]/', $text[1])) {
        $sugared = trim(substr($text, 1));
      }
    }
    elseif ($text[0] === ':') {
      $sugared = RelativeSelector::ANCHOR . $text;
    }
    if (preg_match('/^[+~]/', $sugared)) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": a rule styles inside this element\'s own subtree, and its siblings are outside it; address a sibling under its own rules, or from the parent as a relation among its children ("> d-text + d-text")', $text),
        'Between compounds "+" and "~" are grammar ("> p + p", "> d-text:has(+ d-image)"); leading, they would reach outside the element.',
      );
    }
    $sugared_masked = self::mask($sugared);
    // A functional pseudo-element (`::part()`, `::slotted()`,
    // `::highlight()`, `::cue()`) reaches shadow trees and highlights no
    // element here exposes; the terminal slot takes bare names, any the
    // browser spells (whether this site knows one is the validator's).
    if (preg_match('/::([A-Za-z][A-Za-z0-9-]*)\(/', $sugared_masked, $functional)) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": ::%s() takes an argument and addresses nothing here; a pseudo-element is a bare name glued to its compound ("::before", "li::marker", "p::first-letter")', $text, strtolower($functional[1])),
        'Style a pseudo-element by its bare name, last and glued to the compound whose box it is; ::part(), ::slotted(), ::highlight() and ::cue() reach shadow trees and highlights this element does not expose.',
      );
    }
    preg_match_all('/::?(?:before|after|first-letter|first-line)(?![A-Za-z0-9_-])|::[A-Za-z][A-Za-z0-9-]*/i', $sugared_masked, $boxes, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    if ($boxes !== []) {
      if (self::depthAt($sugared_masked, $boxes[0][0][1]) > 0) {
        return self::refuse(
          self::BAD_SELECTOR,
          sprintf('"%s": a pseudo-element never stands inside a qualifier (a generated box is no tree fact to narrow by); it is terminal, glued to the last compound', $text),
          'Style the box of what the qualifier narrows ("> d-text:has(a)::before"), or the box as its own rule ("a::before").',
        );
      }
      if (count($boxes) > 1 || $boxes[0][0][1] + strlen($boxes[0][0][0]) !== strlen($sugared_masked)) {
        return self::refuse(
          self::BAD_SELECTOR,
          sprintf('"%s": a pseudo-element is terminal and one per selector; the qualifiers and states go before it ("&:hover::after", "> d-text:first-child::before") and nothing follows it', $text),
          'Put the pseudo-element last, glued to the last compound ("&::after", "li::marker").',
        );
      }
    }
    if (preg_match('/^&([A-Za-z][A-Za-z0-9-]*)$/', $sugared, $m)) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": a state is written as a pseudo-class (":%s", "&:%s")', $text, $m[1], $m[1]),
        sprintf('"&" is the element itself and takes CSS qualifiers (":hover", ".open", "[aria-expanded=\"true\"]"); "&%s" is the chrome\'s condition shorthand, not a selector.', $m[1]),
      );
    }
    $parsed = RelativeSelector::parse($sugared);
    if ($parsed === NULL) {
      return self::refuse(self::BAD_SELECTOR, sprintf('"%s" is not a relative selector the grammar accepts', $text), self::HINT_GRAMMAR);
    }
    $outside = self::outsideOf($parsed->compounds);
    if ($outside !== NULL) {
      return self::refuse(self::BAD_SELECTOR, sprintf('"%s": "%s" is not addressable; a rule styles inside this element\'s own subtree', $text, $outside), self::HINT_OWN);
    }
    // The peel, by name, off the last compound (the bare anchor included).
    $compounds = $parsed->compounds;
    $clauses = [];
    if ($compounds !== []) {
      $last = array_key_last($compounds);
      $state = self::stateInsideIs($compounds[$last], $roster);
      if ($state !== NULL) {
        return self::refuse(
          self::SELECTOR_LIST,
          sprintf('"%s": two states on the addressed element (":is()" holds ":%s" among its alternatives); write two rules, one per state', $text, $state),
          self::HINT_LIST_STATE,
        );
      }
      $kept = [];
      foreach ($compounds[$last]['qualifiers'] as $qualifier) {
        $clause = self::clauseOf($qualifier, $roster);
        if ($clause === NULL) {
          $kept[] = $qualifier;
        }
        elseif (!in_array($clause, $clauses, TRUE)) {
          $clauses[] = $clause;
        }
      }
      $compounds[$last]['qualifiers'] = $kept;
      if ($kept === [] && $compounds[$last]['kind'] === RelativeSelector::ANCHOR_KIND) {
        array_pop($compounds);
      }
    }
    $selector = self::spell($compounds, $parsed->pseudo);
    if ($selector !== self::OWNER && !RelativeSelector::isCanonical($selector)) {
      return self::refuse(
        self::BAD_SELECTOR,
        sprintf('"%s": the state peels into the rule\'s condition and leaves "%s", which is not an address', $text, $selector),
        'Name what is in the state ("a:hover", "> *:hover" the children), or the element\'s own state (":hover").',
      );
    }
    $seen = [];
    $records = $clauses === [] ? [] : ConditionClause::parse(implode(ConditionClause::DELIMITER, $clauses), $roster['table']);
    foreach ($records as $record) {
      if (isset($seen[$record['axis']])) {
        return self::refuse(
          self::BAD_SELECTOR,
          sprintf('"%s": one condition per axis; "%s" and "%s" are both on the "%s" axis', $text, $seen[$record['axis']], $record['css'], $record['axis']),
          'A rule holds one state; the second is a second rule.',
        );
      }
      $seen[$record['axis']] = $record['css'];
    }
    return ['selector' => $selector, 'clauses' => $clauses];
  }

  /**
   * The roster the options describe.
   *
   * @return array{table: array, states: array<string, string>, published_states: array<string, true>, published: string[]}
   *   The condition table itself, the posable pseudo-class wrappers by
   *   lowercase text, every published pseudo-class wrapper, and the
   *   published at-rule wrappers spelled for the corrective.
   */
  private static function roster(array $options): array {
    $table = $options['conditions'] ?? [];
    $posable = $options['posable'] ?? NULL;
    $roster = ['table' => $table, 'states' => [], 'published_states' => [], 'published' => []];
    foreach ($table as $name => $definition) {
      $wrapper = is_string($definition['wrapper'] ?? NULL) ? $definition['wrapper'] : '';
      if (str_starts_with($wrapper, '@')) {
        $roster['published'][] = sprintf('%s  (%s)', $wrapper, $name);
      }
      elseif (str_starts_with($wrapper, ':')) {
        $roster['published_states'][strtolower($wrapper)] = TRUE;
        if ($posable === NULL || in_array($name, $posable, TRUE)) {
          $roster['states'][strtolower($wrapper)] ??= $name;
        }
      }
    }
    return $roster;
  }

  /**
   * The published posable condition a pseudo-class qualifier spells, or NULL.
   */
  private static function posableOf(array $qualifier, array $roster): ?string {
    return $qualifier['argument'] === NULL ? ($roster['states'][':' . $qualifier['name']] ?? NULL) : NULL;
  }

  /**
   * The first posable state an `:is()` member of a compound carries, or NULL.
   *
   * On the last compound such a state is a disjunctive condition, which
   * the condition model does not hold (one state per axis); the digester
   * refuses it whether typed or folded.
   */
  private static function stateInsideIs(array $compound, array $roster): ?string {
    foreach ($compound['qualifiers'] as $qualifier) {
      if ($qualifier['name'] !== RelativeSelector::IS) {
        continue;
      }
      foreach ($qualifier['argument'] as $member) {
        foreach ($member['qualifiers'] as $inner) {
          if (self::posableOf($inner, $roster) !== NULL) {
            return $inner['name'];
          }
        }
      }
    }
    return NULL;
  }

  /**
   * The condition clause a last-compound pseudo-class peels into, or NULL.
   *
   * A published posable condition peels as its reference (the name); a
   * published condition the editor cannot pose stays a qualifier; a
   * position is structure and stays; any other argument-less pseudo-class
   * peels as a pseudo-class LITERAL (`:checked`) when a stamp can pose it
   * (ConditionClause::posable()), and stays a qualifier when none can
   * (`:visited`, `:target`).
   *
   * @return string|null
   *   The clause text (a reference's name or the literal's canonical
   *   text), or NULL when the qualifier stays in the selector.
   */
  private static function clauseOf(array $qualifier, array $roster): ?string {
    if ($qualifier['argument'] !== NULL) {
      return NULL;
    }
    $name = self::posableOf($qualifier, $roster);
    if ($name !== NULL) {
      return $name;
    }
    $wrapper = ':' . $qualifier['name'];
    if (isset($roster['published_states'][$wrapper]) || in_array($qualifier['name'], RelativeSelector::POSITIONS, TRUE)) {
      return NULL;
    }
    $clause = ConditionClause::clause($wrapper, $roster['table']);
    return $clause['kind'] === ConditionClause::LITERAL && ConditionClause::posable($clause) ? $clause['text'] : NULL;
  }

  /**
   * The first subject or pseudo-class outside every element's subtree.
   *
   * Walks a chain and its qualifiers' arguments for `html`, `body` or
   * `:root`; NULL when none stands there.
   */
  private static function outsideOf(array $compounds): ?string {
    foreach ($compounds as $compound) {
      if ($compound['kind'] === RelativeSelector::TAG && in_array($compound['name'], self::OUTSIDE, TRUE)) {
        return $compound['name'];
      }
      foreach ($compound['qualifiers'] as $qualifier) {
        if ($qualifier['name'] === RelativeSelector::HAS) {
          $inner = self::outsideOf($qualifier['argument']);
        }
        elseif ($qualifier['name'] === RelativeSelector::NOT) {
          $inner = self::outsideOf([$qualifier['argument']]);
        }
        elseif ($qualifier['name'] === RelativeSelector::IS) {
          $inner = self::outsideOf($qualifier['argument']);
        }
        else {
          $inner = $qualifier['name'] === 'root' && $qualifier['argument'] === NULL ? ':root' : NULL;
        }
        if ($inner !== NULL) {
          return $inner;
        }
      }
    }
    return NULL;
  }

  /**
   * Strips the posable pseudo-class qualifiers from compounds, recursively.
   *
   * At every compound, inside `:has()` (a `:has()` left with a bare
   * universal by descent is dropped) and inside `:is()` per member (a
   * member left bare is dropped, an `:is()` left empty is dropped; one
   * member left merges into its host on the re-parse), never inside
   * `:not()`.
   */
  private static function stripCompounds(array $compounds, array $roster): array {
    foreach ($compounds as &$compound) {
      $qualifiers = [];
      foreach ($compound['qualifiers'] as $qualifier) {
        if (self::posableOf($qualifier, $roster) !== NULL) {
          continue;
        }
        if ($qualifier['name'] === RelativeSelector::HAS) {
          $argument = self::stripCompounds($qualifier['argument'], $roster);
          foreach ($argument as $inner) {
            if ($inner['combinator'] === RelativeSelector::DESCENDANT && $inner['kind'] === RelativeSelector::UNIVERSAL && $inner['qualifiers'] === []) {
              continue 2;
            }
          }
          $qualifier['argument'] = $argument;
        }
        elseif ($qualifier['name'] === RelativeSelector::IS) {
          $members = array_values(array_filter(
            self::stripCompounds($qualifier['argument'], $roster),
            static fn (array $member): bool => $member['kind'] !== NULL || $member['qualifiers'] !== [],
          ));
          if ($members === []) {
            continue;
          }
          $qualifier['argument'] = $members;
        }
        $qualifiers[] = $qualifier;
      }
      $compound['qualifiers'] = $qualifiers;
    }
    return $compounds;
  }

  /**
   * The canonical text of compounds and a pseudo, the owner's own included.
   *
   * No compounds is OWNER, or `&::before` with a pseudo.
   */
  private static function spell(array $compounds, ?string $pseudo): string {
    $head = $compounds === [] ? ($pseudo === NULL ? self::OWNER : RelativeSelector::ANCHOR) : RelativeSelector::printCompounds($compounds);
    return $head . ($pseudo === NULL ? '' : RelativeSelector::SUFFIX . $pseudo);
  }

  /**
   * A refusal answer.
   */
  private static function refuse(string $code, string $message, string $hint): array {
    return [
      'selector' => NULL,
      'condition' => NULL,
      'refusal' => ['code' => $code, 'message' => $message, 'hint' => $hint],
      'folded' => FALSE,
    ];
  }

  /**
   * The text with every quoted string's interior blanked.
   *
   * Same length, the quotes kept, so the structural scans never read a
   * `,`, `#`, `::` or paren inside an attribute value. A backslash
   * escapes the next character; an unterminated string blanks to the
   * end.
   */
  private static function mask(string $text): string {
    $masked = '';
    $quote = NULL;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      $char = $text[$i];
      if ($quote === NULL) {
        $masked .= $char;
        if ($char === '"' || $char === "'") {
          $quote = $char;
        }
      }
      elseif ($char === '\\') {
        $masked .= '__';
        $i++;
      }
      elseif ($char === $quote) {
        $masked .= $char;
        $quote = NULL;
      }
      else {
        $masked .= '_';
      }
    }
    return $masked;
  }

  /**
   * The paren and bracket depth at an offset of a masked text.
   */
  private static function depthAt(string $masked, int $offset): int {
    $depth = 0;
    for ($i = 0; $i < $offset; $i++) {
      if ($masked[$i] === '(' || $masked[$i] === '[') {
        $depth++;
      }
      elseif ($masked[$i] === ')' || $masked[$i] === ']') {
        $depth--;
      }
    }
    return $depth;
  }

  /**
   * Splits a text at its top-level commas, each member trimmed.
   *
   * Top level: outside parens, brackets and strings. Public for the
   * dialect's root handling (DialectCssParser), which reads the `#id` of
   * every list member before handing the rests here as one list.
   *
   * @return string[]
   *   The members.
   */
  public static function splitList(string $text): array {
    $masked = self::mask($text);
    $members = [];
    $start = 0;
    $length = strlen($masked);
    for ($i = 0; $i < $length; $i++) {
      if ($masked[$i] === ',' && self::depthAt($masked, $i) === 0) {
        $members[] = trim(substr($text, $start, $i - $start));
        $start = $i + 1;
      }
    }
    $members[] = trim(substr($text, $start));
    return $members;
  }

}
