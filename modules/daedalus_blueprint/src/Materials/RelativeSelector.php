<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * A relative selector: the suffix every styling address and key carries.
 *
 * The one grammar behind "what, inside this element, does this rule
 * style". A style map key is `{selectable key}::{selector}`, a variant's
 * or skin's relative key `{slot path}::{selector}`, an address
 * `{family}:{id}::{selector}`; the selector after the `::` is CSS's own
 * relative selector, relative to the element the root names, in CSS's
 * own recursive shape:
 *
 * @code
 *   selector   := chain [ pseudo ] | '&' pseudo
 *   chain      := anchor ( combinator compound )*            the owner itself, qualified, then its subtree
 *               | [ lead ] compound ( combinator compound )*
 *   anchor     := '&' qualifier+                            top level only, first; '&' alone is no address
 *   lead       := '> '                          top level: the owner anchors
 *               | '> ' | '+ ' | '~ '            inside :has(): the qualified compound anchors
 *   combinator := ' ' | ' > ' | ' + ' | ' ~ '   behind the anchor ' ' and ' > ' only
 *   compound   := subject qualifier* | qualifier+           the implied subject is '*' (printed in a chain, not in :not())
 *   subject    := '*' | tag | type | '.' class
 *   qualifier  := '.' class | attribute | ':' pseudo-class | ':has(' chain ')' | ':not(' compound ')' | ':is(' members ')' | position
 *   members    := compound ( ', ' compound )*              two or more; one member merges into its host compound
 *   attribute  := '[' name [ operator '"' value '"' [ ' ' flag ] ] ']'
 *   operator   := '=' | '~=' | '|=' | '^=' | '$=' | '*='
 *   flag       := 'i' | 's'
 *   position   := ':nth-child(' anb ')' | ':nth-last-child(' anb ')'
 *               | ':nth-of-type(' anb ')' | ':nth-last-of-type(' anb ')'
 *   anb        := 'odd' | 'even' | [sign] [digits] 'n' [ sign digits ] | [sign] digits
 *   pseudo     := '::' [a-z][a-z0-9-]*       the last compound's pseudo-element
 *               | ':' legacy                 CSS 2's single-colon spellings
 *   legacy     := 'before' | 'after' | 'first-letter' | 'first-line'
 *   tag        := [a-z][a-z0-9]*            a declared base tag (vocabulary)
 *   type       := 'd-' [a-z][a-z0-9-]*     d-container, d-block, d-{bundle}
 *   class      := [A-Za-z0-9_-]+            a declared part, an instance part, or a markup class
 *   pseudo-class := -?[a-z][a-z0-9-]*       any name: a state, a position, an input state
 * @endcode
 *
 * The grammar is CSS's selector grammar; the vocabulary (which tags,
 * types, parts, classes, attributes and pseudo-class names exist on a
 * site, and which pseudo-classes are states the editor can pose) is the
 * roster's and the condition roster's, never this class's. The
 * structural rules the grammar does enforce, each with its one reason:
 * - a bare `*` stands only after `>`, `+` or `~`: "every child" or
 *   "every later sibling" is a relation, "every descendant" is not an
 *   address. A qualified universal is one (`*:hover`, `*[aria-expanded]`:
 *   every hovered descendant, every expanded one), and it is what an
 *   implied subject means in a chain (`[aria-expanded="true"] > ul` is
 *   `*[aria-expanded="true"] > ul`, `:hover > ul` is `*:hover > ul`), so
 *   a chain compound always prints its subject; inside `:not()` the
 *   implied subject prints nothing (`:not(:last-child)`, CSS's one
 *   spelling there), and `:not(*)` is degenerate.
 * - a class qualifies any compound (`li.active > ul`, `> d-text.featured`,
 *   `&.open .menu`, `a.button`), and a compound whose subject is `*` or
 *   implied carries its classes as the subject: `*.cls` is `.cls`, and
 *   with several classes the first by name is the subject and the rest
 *   its qualifiers (`.b.a` is `.a.b`), so one selector has one spelling.
 * - an attribute qualifies any compound with CSS's six operators, a
 *   quoted or ident value and the `i` / `s` flag; it prints with double
 *   quotes and the name lowercased (HTML attribute names are
 *   case-insensitive), the value kept as written.
 * - a pseudo-class qualifies any compound, the last included
 *   (`li:hover > ul`, `a:visited`, `input:checked`, `li:not(:hover)`,
 *   `> d-text:has(a:hover)`): whether its name is a state this site
 *   publishes, and whether a state on the last compound peels into the
 *   condition dimension, is the digester's and the roster's knowledge.
 *   The functional pseudo-classes are the grammar's own: `:has()`,
 *   `:not()`, `:is()` and the `An+B` positions; any other name with an
 *   argument (`:lang()`, `:dir()`) is refused, and `:has`, `:not`, `:is`
 *   and the nth names without their parens are no pseudo-classes. The
 *   names of the pseudo-elements (`:before`, `:after`) are never
 *   pseudo-classes: at the end of a selector the single-colon spelling
 *   reads as the terminal pseudo-element, anywhere else it is refused.
 * - `:is()` is any of several compounds at the compound it qualifies
 *   (`*:is(h1, h2)`, `.menu-item:is(:focus-within, :hover) > ul`,
 *   `> *:is(div, p)`, `*:is(:not(.b), a)`): CSS's own disjunction, the
 *   one home a selector list has in a key (the digester folds a typed
 *   list into it). A member is a compound as a chain admits one, the
 *   subject optional (`:is(:hover, :focus-within)`), classes, attributes,
 *   positions, pseudo-classes, `:has()` and `:not()` inside, never
 *   `:is()` (nested `:is()` flattens to one) and never the anchor; under
 *   the one-sub-walk rule a member inside a `:has()` argument carries no
 *   `:has()`. Members sort by their printed form and duplicates collapse,
 *   so one disjunction has one spelling; a single member merges into its
 *   host compound (`*:is(h1)` is `h1`, `a:is(.x)` is `a.x`, `:is(.b):is(.a)`
 *   is `.a.b`: the member's subject is taken when the host's is `*` or
 *   implied, its qualifiers sort in with the host's), and a member whose
 *   subject contradicts the host's (`h1:is(h2)`, `&:is(h1)`) is refused
 *   with `*` alone, an empty member and `:is()` with no members
 *   (degenerate). `:where()` reads as `:is()` and is never written back:
 *   specificity is flat here by the printer's `:where()` discipline, so
 *   the one difference CSS draws between them does not exist. `:is()`
 *   never stands inside `:not()` (`:not(:is(a, b))` has the spelling
 *   `:not(a):not(b)`) or inside `:is()`.
 * - a leading `+` or `~` is refused at the top level (the owner anchors,
 *   and its siblings are outside its own subtree) and admitted inside
 *   `:has()` (the qualified compound anchors, and its siblings are inside
 *   the owner's subtree: `> d-text:has(+ d-image)`).
 * - `:has()` never stands anywhere inside a `:has()` argument (CSS's own
 *   rule; one sub-walk depth).
 * - `:not()` takes ONE compound (a predicate the vocabulary can judge,
 *   never a chain); inside it the subject may be implied
 *   (`:not(:has(a))`, `:not(:last-child)`, `:not([hidden])`), and `*`
 *   alone and `:not()` are never written there (degenerate: one
 *   predicate keeps one spelling).
 * - a position pseudo-class qualifies any compound by what the markup
 *   decides (`> *:not(:last-child)`, `li:nth-child(2n+1)`,
 *   `> d-text:first-of-type`): position is structure, selector text, the
 *   WHERE. The eleven are CSS's tree-structural pseudo-classes
 *   (POSITIONS and NTH_POSITIONS); an `An+B` argument is canonical as
 *   CSS serializes it (`2n+1`, `n`, `-n+3`, `3`; `odd` and `even` kept
 *   as words), so a stored key has one spelling of each position.
 * - the anchor `&` is the owner itself. It stands first, qualified, at
 *   the top level (`&:first-child`, `&:hover > ul`, `&.open .menu`,
 *   `&:not(:last-child) > *`: the owner when it is the first child, its
 *   submenu while it is hovered, its menu while it wears the class, every
 *   child of the owner when the owner is not the last), and the chain
 *   behind it reaches its subtree by descent or `>` only (its siblings
 *   are outside). An unqualified anchor is no address (`& > p` is `> p`,
 *   `&` alone is the root with no selector, ``), and the anchor stands
 *   nowhere inside `:has()` or `:not()`. The record carries it as the
 *   leading compound of kind ANCHOR_KIND, named `&`, reached by
 *   DESCENDANT (no lead is written before it), so every reader of "the
 *   last compound" lands on the owner itself for `&:first-child` and on
 *   the chain's end otherwise.
 * - a pseudo-element is terminal: `::name` glued to the last compound
 *   names that compound's pseudo-element (`.quote::after`,
 *   `> d-text::before`, `li::marker`, `input::placeholder`; any name the
 *   browser spells, PSEUDO_PATTERN, whether this site knows it being the
 *   style guide's verdict), nothing follows it, it takes no argument, and
 *   it never stands inside a qualifier (a box is no tree fact to qualify
 *   by). The
 *   owner's own generated box is `&::before` / `&::after`, CSS nesting's
 *   anchor for the element itself, carried with NO compounds (the bare
 *   anchor is no compound; `&:first-child::before` carries the qualified
 *   one). Other pseudo-elements (`::marker`, `::first-letter`) are
 *   refused: each has its own property law and lands separately. The
 *   record carries the pseudo on the selector, never on a compound: it
 *   is where the box hangs, not a step of the chain.
 * - no top-level commas (a key holds one selector; a list is the
 *   digester's to fold into `:is()`, where the comma lives). The
 *   terminal pseudo is the only `::` a selector carries outside
 *   a quoted attribute value, and something always precedes it (a
 *   compound or the anchor), so the FIRST `::` in any key still splits
 *   root from selector (the invariant carriage, the sweeps, capture and
 *   every prefix scan rest on).
 *
 * One canonical spelling: combinators spaced, a lead glued to its space
 * (`> `, `+ `, `~ `), qualifiers glued to their subject and sorted by
 * their printed form (`.active` before `:first-child` before `:has(…)`
 * before `:hover` before `:is(…)` before `:not(…)` before
 * `:nth-child(…)` before `[aria-current="page"]`, then by printed
 * argument), arguments canonical (`:is()` members sorted the same way
 * and joined by `, `), the pseudo glued to the last compound or the
 * anchor, no trailing space (`.cls`, `p`, `> *`, `> d-text .link`,
 * `> p + p`, `> d-text:has(a):not(.featured)`, `> *:not(:last-child)`,
 * `li.active:hover > ul`, `*:is(h1, h2)`, `a[href^="http"]::after`,
 * `&:first-child`, `&::before`, `.quote::after`).
 * parse() reads the spelling leniently: tags, types, pseudo-class,
 * pseudo-element and attribute names in any case (`Li:HOVER > UL` is
 * `li:hover > ul`; classes and attribute values keep theirs), whitespace
 * free around combinators, inside parens and brackets and around an
 * `An+B` sign (`>p+p`, `:has( a )`, `:nth-child( 2n + 1 )`,
 * `[ type = checkbox ]`), an attribute value in either quote or none
 * (`[type=checkbox]`, `[type='checkbox']`), the legacy `:before` /
 * `:after` at the end. A space between a subject and a qualifier is a
 * descendant combinator followed by an implied-subject compound
 * (`d-text :has(a)` is `d-text *:has(a)`), and a space before `::` is
 * refused (CSS reads `p ::before` as every descendant's box, an address
 * this grammar has no spelling for). The print is always canonical, and
 * the key gates (splitKey(), Address, the slot-key grammar, the
 * validator) admit the canonical spelling only, so a stored key has
 * exactly one shape and two spellings of one selector can never live
 * side by side in a map. Who canonicalizes is the door that takes free
 * text (the page language's CSS parser, the code view's gesture);
 * everything after stores what it was given.
 *
 * The type compounds spell an element the way the page language spells
 * it: `d-container` names every container (the `kind:container`
 * family), `d-block` every block that is not a declared inline type (a
 * plugin block or an undeclared bundle: the `kind:block` family, since the
 * dialect prints all of those as `<d-block>`), and `d-{bundle}` the
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
   * The kind of the qualified anchor.
   *
   * The owner itself as the leading compound (`&:first-child`), named
   * ANCHOR.
   */
  public const ANCHOR_KIND = 'anchor';

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
   *
   * A qualifier record is `{name, argument}`. The grammar's own names:
   * HAS carries a chain, NOT one compound, IS a list of two or more
   * compounds (members, each shaped as NOT's argument, sorted by printed
   * form, distinct), CLASS a class name without
   * its dot (`{name: 'class', argument: 'active'}` prints `.active`),
   * ATTRIBUTE an `{attribute, operator, value, flag}` record (`[name]`
   * when operator and value are NULL; the flag `i`, `s` or NULL), an
   * NTH_POSITIONS name its canonical `An+B` string (canonicalNth()).
   * Every other name is a pseudo-class with a NULL argument: a
   * POSITIONS name (`first-child`), a state (`hover`), an input state
   * (`checked`), whatever the vocabulary admits. CLASS and ATTRIBUTE are
   * record names, so `:class` and `:attribute` are no pseudo-classes.
   */
  public const HAS = 'has';
  public const NOT = 'not';
  public const IS = 'is';
  public const CLASS_QUALIFIER = 'class';

  /**
   * CSS's `:where()`, read as IS and never written: no record carries it.
   */
  private const WHERE = 'where';
  public const ATTRIBUTE = 'attribute';
  public const POSITIONS = [
    'first-child',
    'last-child',
    'only-child',
    'empty',
    'first-of-type',
    'last-of-type',
    'only-of-type',
  ];
  public const NTH_POSITIONS = ['nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type'];

  /**
   * The attribute operators, as CSS spells them.
   */
  public const ATTRIBUTE_OPERATORS = ['=', '~=', '|=', '^=', '$=', '*='];

  /**
   * A pseudo-class's name at the read position (any case), and its `(`.
   */
  private const QUALIFIER_AT = '/\G:(-?[A-Za-z][A-Za-z0-9-]*)(\(?)/';

  /**
   * A class glued at the read position (its dot included).
   */
  private const CLASS_AT = '/\G\.[A-Za-z0-9_-]+/';

  /**
   * A tag or type word at the read position, in any case.
   */
  private const WORD_AT = '/\G[A-Za-z][A-Za-z0-9-]*/';

  /**
   * An attribute's name, operator and ident value at the read position.
   */
  private const ATTRIBUTE_NAME_AT = '/\G[A-Za-z_-][A-Za-z0-9_-]*/';
  private const ATTRIBUTE_OPERATOR_AT = '/\G[~|^$*]?=/';
  private const IDENT_AT = '/\G(?:--|-?[A-Za-z_])[A-Za-z0-9_-]*/';

  /**
   * An `An+B` argument, whitespace free at the ends and around the sign.
   *
   * Groups: 1 the keyword, 2 A's sign, 3 A's digits, 4 B's sign, 5 B's
   * digits, 6 a bare integer. Any case (`2N+1`, `ODD`).
   */
  private const ANB = '/^(?:(odd|even)|([+-]?)(\d*)n(?:\s*([+-])\s*(\d+))?|([+-]?\d+))$/Di';

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
   *
   * The one PHP spelling of the prefix; every fixed tag name and the type
   * pattern derive from it, and the dialect reads it from here.
   */
  public const TYPE_PREFIX = 'd-';

  /**
   * The type names of the two element-kind families.
   */
  public const TYPE_CONTAINER = self::TYPE_PREFIX . 'container';
  public const TYPE_BLOCK = self::TYPE_PREFIX . 'block';

  /**
   * The compound charsets (the canonical, lowercase spellings).
   */
  public const TAG_PATTERN = '/^[a-z][a-z0-9]*$/';
  public const TYPE_PATTERN = '/^' . self::TYPE_PREFIX . '[a-z][a-z0-9-]*$/';
  public const CLASS_PATTERN = '/^\.[A-Za-z0-9_-]+$/';

  /**
   * The two generated boxes, by name (no `::`).
   *
   * CSS's `::before` and `::after`: the pseudo-elements that exist only
   * while the cascade sets their `content` (daedalus_stylish's
   * GeneratedContent group, the existence switch). NOT the grammar's
   * admission list: the terminal slot takes any pseudo-element name the
   * browser spells (PSEUDO_PATTERN: `::marker`, `::placeholder`,
   * `::selection`, `::first-letter`, `::first-line`, `::backdrop`, …), and
   * whether this site knows and admits one is the style guide's verdict
   * over the wide selector tier, as for a tag. These two name the sites
   * that switch a box on: the birth seed, the content grant, the address
   * chip's two offers, the page-elements tree's always-listed rows.
   */
  public const PSEUDO_BEFORE = 'before';
  public const PSEUDO_AFTER = 'after';
  public const GENERATED_BOXES = [self::PSEUDO_BEFORE, self::PSEUDO_AFTER];

  /**
   * The pseudo-elements CSS 2's single-colon spelling still names.
   *
   * `p:before`, `p:first-line` read as the pseudo-elements they are to
   * the browser; every other single-colon name is a pseudo-class.
   */
  public const LEGACY_PSEUDOS = ['before', 'after', 'first-letter', 'first-line'];

  /**
   * A pseudo-element's name: a lowercase CSS ident, no vendor prefix.
   */
  public const PSEUDO_PATTERN = '/^[a-z][a-z0-9-]*$/';

  /**
   * The names a pseudo-class may not carry.
   *
   * The functional names without their parens, the record names, the
   * legacy pseudo-elements'.
   */
  private const NOT_PSEUDO_CLASSES = [
    self::HAS,
    self::NOT,
    self::IS,
    self::WHERE,
    self::CLASS_QUALIFIER,
    self::ATTRIBUTE,
    ...self::NTH_POSITIONS,
    ...self::LEGACY_PSEUDOS,
  ];

  /**
   * The owner's own anchor: the one subject of its own pseudo-element.
   */
  public const ANCHOR = '&';

  /**
   * The terminal pseudo-element and the trailing whitespace behind it.
   *
   * Any case: `::` and any name, or the legacy single-colon spelling of
   * the four names it still means (LEGACY_PSEUDOS). The branch reset
   * puts the name in group 1 either way.
   */
  private const PSEUDO_AT_END = '/(?|::?(before|after|first-letter|first-line)|::([A-Za-z][A-Za-z0-9-]*))\s*$/Di';

  /**
   * The grammar in one sentence, for every corrective that spells a key.
   *
   * Every hint that names what may follow a `::` composes this sentence
   * rather than restating the grammar, so the teaching can never drift
   * from the parser.
   */
  public const GRAMMAR = 'After "::" comes a relative selector over the element\'s own subtree, in CSS\'s own grammar: compounds joined by " " (descendant), " > " (direct child), " + " (the next sibling) or " ~ " (any later sibling), e.g. ".class-name" (a declared part), "p" (every paragraph inside it), "> *" (its direct children), "> d-text" (the text blocks among its children; a type is spelled as the page language spells the element: d-{block type}, d-container, d-block), "> d-text .link", "> p + p". A compound is a subject ("*", a tag, a type, or implied) with qualifiers glued to it: classes (".active" on any compound: "li.active > ul", "&.open"), attributes ("[aria-expanded=\"true\"] > ul", "a[href^=\"http\"]", "input[type=\"checkbox\"]"; the operators =, ~=, |=, ^=, $=, *=, a quoted or ident value, the i or s flag), pseudo-classes ("li:hover > ul", "a:visited", "> *:first-child", "li:nth-child(2n+1)" with "odd", "even" or an An+B; whether a name is a state this site publishes is the style guide\'s verdict, not the grammar\'s), ":has(…)" (a relative selector from that compound: "> d-text:has(a)", "> d-text:has(+ d-image)") and ":not(…)" (one compound it is not: "> *:not(d-image)", "> d-text:not(:has(a))", "> *:not(:last-child)", "li:not(:hover)"), and ":is(…)" (any of several compounds at that compound: "*:is(h1, h2)", ".menu-item:is(:focus-within, :hover) > ul", "> *:is(div, p)", "*:is(:not(.b), a)"; a member is a compound as a chain admits one, never ":is()" or "&", members print sorted and distinct, one member merges into its host ("*:is(h1)" is "h1", "a:is(.x)" is "a.x"); ":where(…)" reads as ":is(…)"; ":is()" never stands inside ":not()", where ":not(:is(a, b))" is ":not(a):not(b)", nor inside ":is()"). The element itself is "&", which stands first and qualified ("&:hover > ul", "&.open .menu", "&:not(:last-child) > *"; "&" alone and "& > p" are not addresses, the latter is "> p"), and only descendants (" ", " > ") follow it. A selector may end in a pseudo-element, glued to its last compound: "::before" or "::after", the generated boxes ("> d-text::before", ".quote::after"; the element\'s own are "&::before" and "&::after"), or any other the browser knows ("li::marker", "input::placeholder", "&::selection", "p::first-letter", "p::first-line", "&::backdrop"; whether this site knows and admits one is the style guide\'s verdict, and each is stylable with the properties its specification allows). The pseudo-element is terminal: nothing follows it, it never stands inside a qualifier, it takes no argument ("::part()", "::slotted()" are not addressable), and a single-colon spelling names one only for "before", "after", "first-letter" and "first-line". Tags, types, pseudo-class and attribute names read in any case and print lowercase ("Li:hover > UL" is "li:hover > ul"); classes and attribute values keep their case; whitespace and quotes are free. No leading "+" or "~", no bare "*" by descent (every descendant is not an address; a qualified one, "*:hover", is), no selector list (one selector per key; a list folds into ":is()"), no functional pseudo-class but ":has()", ":not()", ":is()" and the nth positions, and no "::" but the terminal pseudo-element.';

  /**
   * Constructs a selector from parsed compounds; use parse().
   *
   * @param array<int, array{combinator: string, kind: string, name: string, qualifiers: array}> $compounds
   *   The compounds in order. Each carries the combinator that reaches it
   *   (one of DESCENDANT, CHILD, NEXT, SUBSEQUENT; the first compound's is
   *   the selector's lead, DESCENDANT for the qualified anchor), its kind
   *   (one of UNIVERSAL, TAG, TYPE, CLASS_PART, or ANCHOR_KIND for the
   *   qualified anchor, first only) and its name as it prints (`*`, the
   *   tag, the `d-…` type, the `.class`, `&`), and its qualifiers: a
   *   list of `{name: HAS, argument: compounds}` (a relative selector
   *   from this compound, the same record), `{name: NOT, argument:
   *   compound}` (one compound with `combinator` NULL, whose `kind` and
   *   `name` are NULL when its subject is implied), `{name: IS,
   *   argument: compounds}` (two or more compounds shaped as NOT's,
   *   sorted by printed form, distinct), `{name: CLASS,
   *   argument: 'active'}`, `{name: ATTRIBUTE, argument: {attribute,
   *   operator, value, flag}}`, a position `{name: 'nth-child',
   *   argument: '2n+1'}` or any other pseudo-class `{name: 'hover',
   *   argument: NULL}`, sorted canonical. Empty for the owner's own
   *   pseudo-element (`&::before`): the bare anchor is no compound.
   * @param string|null $pseudo
   *   The terminal pseudo-element by name (`before`, `marker`, any name
   *   PSEUDO_PATTERN spells), or NULL when the selector ends in its last
   *   compound.
   */
  private function __construct(
    public readonly array $compounds,
    public readonly ?string $pseudo = NULL,
  ) {}

  /**
   * Parses a selector, or NULL when the text is outside the grammar.
   *
   * Lenient on whitespace, on the case of every name but classes and
   * attribute values, and on attribute quoting; everything else is the
   * grammar's letter. The terminal pseudo is read first, so `before`
   * never reads as a tag and the chain parser never sees a `::`.
   */
  public static function parse(string $selector): ?self {
    $pseudo = NULL;
    $head = $selector;
    if (preg_match(self::PSEUDO_AT_END, $selector, $m, PREG_OFFSET_CAPTURE)) {
      $pseudo = strtolower($m[1][0]);
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
    $compounds = self::parseChain($head, $at, FALSE, FALSE, FALSE);
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
   *   never carry `:not()` or `:is()`).
   * @param bool $in_is
   *   Whether the chain is inside an `:is()` member (its compounds may
   *   never carry `:is()`).
   *
   * @return array[]|null
   *   The compounds, or NULL outside the grammar.
   */
  private static function parseChain(string $text, int &$at, bool $in_has, bool $in_not, bool $in_is): ?array {
    self::skipSpace($text, $at);
    $compounds = [];
    if (!$in_has && !$in_not && !$in_is && ($text[$at] ?? '') === self::ANCHOR) {
      // The qualified anchor: the owner itself, top level, first. It
      // carries at least one qualifier (`&` alone and `& > p` are no
      // addresses: the root, and `> p`).
      $at++;
      $qualifiers = self::parseQualifiers($text, $at, FALSE, FALSE, FALSE);
      if ($qualifiers === NULL || $qualifiers === []) {
        return NULL;
      }
      // The owner is what it is: a single `:is()` member with a subject
      // has nothing to merge into (`&:is(h1)`), one without merges.
      $merged = self::mergeSingletons(self::ANCHOR_KIND, self::ANCHOR, $qualifiers);
      if ($merged === NULL) {
        return NULL;
      }
      $compounds[] = [
        'combinator' => self::DESCENDANT,
        'kind' => self::ANCHOR_KIND,
        'name' => self::ANCHOR,
        'qualifiers' => $merged[2],
      ];
    }
    else {
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
      $compound = self::parseCompound($text, $at, $combinator, $in_has, $in_not, $in_is);
      if ($compound === NULL) {
        return NULL;
      }
      $compounds[] = $compound;
    }
    while (TRUE) {
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
      }
      elseif ($at === $before) {
        // Anything else glued to the compound (`p,`, `p::marker`, `p&`)
        // is outside the grammar; behind whitespace it is the next
        // compound by descent.
        return NULL;
      }
      else {
        $combinator = self::DESCENDANT;
      }
      $previous = $compounds[array_key_last($compounds)];
      // The owner's siblings are outside its subtree: behind the anchor
      // the chain descends.
      if ($previous['kind'] === self::ANCHOR_KIND && $combinator !== self::DESCENDANT && $combinator !== self::CHILD) {
        return NULL;
      }
      $compound = self::parseCompound($text, $at, $combinator, $in_has, $in_not, $in_is);
      if ($compound === NULL) {
        return NULL;
      }
      $compounds[] = $compound;
    }
  }

  /**
   * Parses one compound (a subject and its qualifiers) from $at.
   *
   * The subject is `*`, a tag or type word (any case), or implied when
   * a qualifier opens the compound. A compound whose subject is `*` or
   * implied carries its classes as the subject (the first by name;
   * `*.cls` and `.cls` are one spelling); without classes the implied
   * subject is the universal `*` in a chain (printed) and nothing in a
   * predicate (a `:not()` argument or an `:is()` member), and a bare `*`
   * is refused by descent (every descendant is no address) and in a
   * predicate (degenerate). A single-member `:is()` merges into the
   * compound before its subject settles.
   *
   * @param string $text
   *   The text.
   * @param int $at
   *   The read position, advanced past the compound.
   * @param string|null $combinator
   *   The combinator reaching the compound (NULL for a predicate: a
   *   `:not()` argument or an `:is()` member).
   * @param bool $in_has
   *   Whether the compound stands anywhere inside a `:has()` argument.
   * @param bool $in_not
   *   Whether the compound stands anywhere inside a `:not()` argument
   *   (`:not()` and `:is()` refused).
   * @param bool $in_is
   *   Whether the compound stands anywhere inside an `:is()` member
   *   (`:is()` refused).
   */
  private static function parseCompound(string $text, int &$at, ?string $combinator, bool $in_has, bool $in_not, bool $in_is): ?array {
    $kind = NULL;
    $name = NULL;
    $char = $text[$at] ?? '';
    if ($char === '*') {
      $kind = self::UNIVERSAL;
      $name = '*';
      $at++;
    }
    elseif (preg_match(self::WORD_AT, $text, $m, 0, $at)) {
      $name = strtolower($m[0]);
      $kind = match (TRUE) {
        preg_match(self::TYPE_PATTERN, $name) === 1 => self::TYPE,
        preg_match(self::TAG_PATTERN, $name) === 1 => self::TAG,
        default => NULL,
      };
      if ($kind === NULL) {
        return NULL;
      }
      $at += strlen($m[0]);
    }
    elseif ($char !== '.' && $char !== '[' && $char !== ':') {
      return NULL;
    }
    $qualifiers = self::parseQualifiers($text, $at, $in_has, $in_not, $in_is);
    if ($qualifiers === NULL) {
      return NULL;
    }
    $merged = self::mergeSingletons($kind, $name, $qualifiers);
    if ($merged === NULL) {
      return NULL;
    }
    [$kind, $name, $qualifiers] = $merged;
    if ($kind === NULL || $kind === self::UNIVERSAL) {
      // The classes sort first: the first one is the subject.
      if (($qualifiers[0]['name'] ?? NULL) === self::CLASS_QUALIFIER) {
        $kind = self::CLASS_PART;
        $name = '.' . array_shift($qualifiers)['argument'];
      }
      elseif ($combinator === NULL) {
        if ($qualifiers === []) {
          return NULL;
        }
        $kind = NULL;
        $name = NULL;
      }
      elseif ($qualifiers === [] && $combinator === self::DESCENDANT) {
        return NULL;
      }
      else {
        $kind = self::UNIVERSAL;
        $name = '*';
      }
    }
    return ['combinator' => $combinator, 'kind' => $kind, 'name' => $name, 'qualifiers' => $qualifiers];
  }

  /**
   * Merges every single-member `:is()` among the qualifiers into its host.
   *
   * `:is(x)` is `x`, so the record never carries one: the member's
   * subject becomes the host's when the host has none (`*:is(h1)` is
   * `h1`, `> *:is(p)` is `> p`), a class subject goes back to the class
   * pool (`a:is(.x)` is `a.x`, `:is(.b):is(.a)` is `.a.b`: the host's
   * subject settles after the merge), and the member's qualifiers sort
   * in with the host's. A member subject against a host that has its
   * own (`h1:is(h2)`, `&:is(h1)`; `h1:is(h1)` is `h1`) is refused:
   * nothing is both, and a selector that matches nothing keeps no
   * spelling.
   *
   * @param string|null $kind
   *   The host's kind as read (NULL when implied).
   * @param string|null $name
   *   The host's name as read.
   * @param array $qualifiers
   *   The host's qualifiers, sorted.
   *
   * @return array{0: ?string, 1: ?string, 2: array}|null
   *   The kind, the name and the qualifiers after the merge, or NULL
   *   when a member contradicts the host.
   */
  private static function mergeSingletons(?string $kind, ?string $name, array $qualifiers): ?array {
    $merged = [];
    $changed = FALSE;
    foreach ($qualifiers as $qualifier) {
      if ($qualifier['name'] !== self::IS || count($qualifier['argument']) !== 1) {
        $merged[] = $qualifier;
        continue;
      }
      $changed = TRUE;
      $member = $qualifier['argument'][0];
      if ($member['kind'] === self::CLASS_PART) {
        $merged[] = ['name' => self::CLASS_QUALIFIER, 'argument' => substr($member['name'], 1)];
      }
      elseif ($member['kind'] !== NULL) {
        if ($kind === NULL || $kind === self::UNIVERSAL) {
          $kind = $member['kind'];
          $name = $member['name'];
        }
        elseif ($kind !== $member['kind'] || $name !== $member['name']) {
          return NULL;
        }
      }
      array_push($merged, ...$member['qualifiers']);
    }
    if ($changed) {
      self::sortQualifiers($merged);
    }
    return [$kind, $name, $merged];
  }

  /**
   * Sorts qualifiers by their printed form: the one canonical order.
   */
  private static function sortQualifiers(array &$qualifiers): void {
    usort($qualifiers, static fn (array $a, array $b): int => strcmp(self::printQualifier($a), self::printQualifier($b)));
  }

  /**
   * Parses the qualifiers glued at $at, sorted canonical.
   *
   * @param string $text
   *   The text.
   * @param int $at
   *   The read position, advanced past the qualifiers.
   * @param bool $in_has
   *   Whether the qualifiers stand anywhere inside a `:has()` argument
   *   (`:has()` refused).
   * @param bool $in_not
   *   Whether they stand anywhere inside a `:not()` argument (`:not()`
   *   and `:is()` refused).
   * @param bool $in_is
   *   Whether they stand anywhere inside an `:is()` member (`:is()`
   *   refused).
   *
   * @return array[]|null
   *   The qualifiers ([] when none is written), or NULL when a `.`, `[`
   *   or `:` at the position opens anything but a qualifier.
   */
  private static function parseQualifiers(string $text, int &$at, bool $in_has, bool $in_not, bool $in_is): ?array {
    $qualifiers = [];
    while (TRUE) {
      $char = $text[$at] ?? '';
      if ($char === '.') {
        if (!preg_match(self::CLASS_AT, $text, $m, 0, $at)) {
          return NULL;
        }
        $at += strlen($m[0]);
        $qualifiers[] = ['name' => self::CLASS_QUALIFIER, 'argument' => substr($m[0], 1)];
        continue;
      }
      if ($char === '[') {
        $argument = self::parseAttribute($text, $at);
        if ($argument === NULL) {
          return NULL;
        }
        $qualifiers[] = ['name' => self::ATTRIBUTE, 'argument' => $argument];
        continue;
      }
      if ($char !== ':') {
        break;
      }
      if (!preg_match(self::QUALIFIER_AT, $text, $m, 0, $at)) {
        return NULL;
      }
      $qualifier_name = strtolower($m[1]);
      $open = $m[2] === '(';
      $at += strlen($m[0]);
      if ($qualifier_name === self::HAS && $open) {
        if ($in_has) {
          return NULL;
        }
        $argument = self::parseChain($text, $at, TRUE, $in_not, $in_is);
        if ($argument === NULL) {
          return NULL;
        }
        $qualifier = ['name' => self::HAS, 'argument' => $argument];
      }
      elseif ($qualifier_name === self::NOT && $open) {
        if ($in_not) {
          return NULL;
        }
        self::skipSpace($text, $at);
        $argument = self::parseCompound($text, $at, NULL, $in_has, TRUE, $in_is);
        if ($argument === NULL) {
          return NULL;
        }
        $qualifier = ['name' => self::NOT, 'argument' => $argument];
      }
      elseif (($qualifier_name === self::IS || $qualifier_name === self::WHERE) && $open) {
        // `:where()` reads as `:is()`. Members split at top-level commas,
        // each a predicate compound; sorted by printed form and distinct,
        // so one disjunction has one spelling. An empty member (`:is()`,
        // `:is(a,)`) is no compound.
        if ($in_not || $in_is) {
          return NULL;
        }
        $members = [];
        while (TRUE) {
          self::skipSpace($text, $at);
          $member = self::parseCompound($text, $at, NULL, $in_has, FALSE, TRUE);
          if ($member === NULL) {
            return NULL;
          }
          $members[self::printCompound($member)] = $member;
          self::skipSpace($text, $at);
          if (($text[$at] ?? '') !== ',') {
            break;
          }
          $at++;
        }
        ksort($members, SORT_STRING);
        $qualifier = ['name' => self::IS, 'argument' => array_values($members)];
      }
      elseif (in_array($qualifier_name, self::NTH_POSITIONS, TRUE) && $open) {
        $close = strpos($text, ')', $at);
        $argument = $close === FALSE ? NULL : self::canonicalNth(substr($text, $at, $close - $at));
        if ($argument === NULL) {
          return NULL;
        }
        $at = $close;
        $qualifier = ['name' => $qualifier_name, 'argument' => $argument];
      }
      elseif ($open || in_array($qualifier_name, self::NOT_PSEUDO_CLASSES, TRUE)) {
        // A functional pseudo-class the grammar does not own, a
        // functional name without its parens, a record name, or a legacy
        // pseudo-element's name spelled with one colon off the end.
        return NULL;
      }
      else {
        $qualifiers[] = ['name' => $qualifier_name, 'argument' => NULL];
        continue;
      }
      self::skipSpace($text, $at);
      if (($text[$at] ?? '') !== ')') {
        return NULL;
      }
      $at++;
      $qualifiers[] = $qualifier;
    }
    self::sortQualifiers($qualifiers);
    return $qualifiers;
  }

  /**
   * Parses an attribute qualifier (`[` at $at) into its record.
   *
   * `[name]`, `[name op value]`, `[name op value flag]`: the name
   * lowercased, the value a string in either quote or an ident, kept as
   * written (decoded); the flag `i` or `s` in any case, after whitespace
   * when the value is an ident (`[a=b i]`; `[a=bi]` is the value `bi`).
   *
   * @return array{attribute: string, operator: ?string, value: ?string, flag: ?string}|null
   *   The record, or NULL when the brackets hold anything else.
   */
  private static function parseAttribute(string $text, int &$at): ?array {
    $at++;
    self::skipSpace($text, $at);
    if (!preg_match(self::ATTRIBUTE_NAME_AT, $text, $m, 0, $at)) {
      return NULL;
    }
    $attribute = strtolower($m[0]);
    $at += strlen($m[0]);
    self::skipSpace($text, $at);
    $operator = NULL;
    $value = NULL;
    $flag = NULL;
    if (preg_match(self::ATTRIBUTE_OPERATOR_AT, $text, $m, 0, $at)) {
      $operator = $m[0];
      $at += strlen($m[0]);
      self::skipSpace($text, $at);
      $quote = $text[$at] ?? '';
      $quoted = $quote === '"' || $quote === "'";
      if ($quoted) {
        $value = self::readString($text, $at);
      }
      elseif (preg_match(self::IDENT_AT, $text, $m, 0, $at)) {
        $value = $m[0];
        $at += strlen($m[0]);
      }
      if ($value === NULL) {
        return NULL;
      }
      $before = $at;
      self::skipSpace($text, $at);
      if (($quoted || $at > $before) && preg_match('/\G([iIsS])\s*\]/', $text, $m, 0, $at)) {
        $flag = strtolower($m[1]);
        $at++;
        self::skipSpace($text, $at);
      }
    }
    if (($text[$at] ?? '') !== ']') {
      return NULL;
    }
    $at++;
    return ['attribute' => $attribute, 'operator' => $operator, 'value' => $value, 'flag' => $flag];
  }

  /**
   * Reads a quoted string at $at (on its opening quote), decoded.
   *
   * CSS's string: either quote, a backslash escaping the next character,
   * a hex escape of up to six digits and one optional whitespace after
   * it (an invalid code point reads as U+FFFD, as does a raw NUL), a
   * backslash before a newline a continuation. An unescaped newline or
   * the end of the text before the closing quote is no string (NULL).
   */
  private static function readString(string $text, int &$at): ?string {
    $quote = $text[$at++];
    $value = '';
    $length = strlen($text);
    while ($at < $length) {
      $char = $text[$at];
      if ($char === $quote) {
        $at++;
        return $value;
      }
      if ($char === "\n" || $char === "\r" || $char === "\f") {
        return NULL;
      }
      $at++;
      if ($char === "\0") {
        $value .= "\u{FFFD}";
        continue;
      }
      if ($char !== '\\') {
        $value .= $char;
        continue;
      }
      if ($at >= $length) {
        return NULL;
      }
      if ($text[$at] === "\n") {
        $at++;
        continue;
      }
      if (preg_match('/\G[0-9A-Fa-f]{1,6}\s?/', $text, $m, 0, $at)) {
        $at += strlen($m[0]);
        $code = (int) hexdec(trim($m[0]));
        $value .= ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) ? "\u{FFFD}" : mb_chr($code, 'UTF-8');
        continue;
      }
      $value .= $text[$at++];
    }
    return NULL;
  }

  /**
   * The canonical spelling of an attribute value: double-quoted.
   *
   * A quote and a backslash escape as themselves; a control character
   * and each mark the emit gate admits in no text (`;`, `{`, `}`, `<`,
   * `>`: EmitGate) as its hex escape with its terminating space, so the
   * spelling means the same value to a browser and can stand in a style
   * element whatever the value holds.
   */
  private static function printString(string $value): string {
    return '"' . preg_replace_callback(
      '/["\\\\]|[;{}<>\x00-\x1F\x7F]/',
      static fn (array $m): string => match ($m[0]) {
        '"' => '\\"',
        '\\' => '\\\\',
        default => '\\' . dechex(ord($m[0])) . ' ',
      },
      $value,
    ) . '"';
  }

  /**
   * The canonical spelling of an `An+B` argument, or NULL when it is none.
   *
   * CSS's own serialization (css-syntax, "serialize an <an+b>"): `odd`
   * and `even` stay words; otherwise A prints as `n`, `-n` or `{A}n`
   * and B as `+{B}` or `-{B}` when not zero, and an A of zero prints B
   * alone (`2n+1`, `n`, `-n+3`, `3`, `2n`, `0`). Read leniently on case
   * (`2N+1`, `ODD`) and on whitespace at the ends and around B's sign
   * (`2n + 1`), never between a sign and its digits or before the `n`;
   * the `of S` form is refused.
   */
  public static function canonicalNth(string $argument): ?string {
    if (!preg_match(self::ANB, trim($argument), $m)) {
      return NULL;
    }
    if ($m[1] !== '') {
      return strtolower($m[1]);
    }
    if (isset($m[6])) {
      return (string) (int) $m[6];
    }
    $a = ($m[3] === '' ? 1 : (int) $m[3]) * ($m[2] === '-' ? -1 : 1);
    $b = isset($m[5]) ? (int) $m[5] * ($m[4] === '-' ? -1 : 1) : 0;
    if ($a === 0) {
      return (string) $b;
    }
    $printed = match ($a) {
      1 => 'n',
      -1 => '-n',
      default => $a . 'n',
    };
    return $printed . ($b > 0 ? '+' . $b : ($b < 0 ? '-' . -$b : ''));
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
   * under the rule's targets reads `{rule} {suffix}` (`> d-text` under
   * `.link` is `> d-text .link`; under `> *`, `> d-text > *`; `a` under
   * `.cls` is `a .cls`), and the grammar decides admissibility exactly
   * as it does for an authored key, so a suffix the rule cannot carry
   * answers NULL and its styles land nowhere. A pseudo binds to the
   * rule's landing: `&::before` under `> d-text` is `> d-text::before`
   * (the anchor is the rule's own targets), `.link::before` under it
   * `> d-text .link::before`; a rule already ending in a pseudo admits
   * no continuation (nothing follows a generated box). The qualified
   * anchor binds the same way: its qualifiers land on the rule's last
   * compound, sorted in with that compound's own (`&:first-child` under
   * `> d-text` is `> d-text:first-child`, `&:not(:last-child) > *` under
   * `> d-text:has(a)` is `> d-text:has(a):not(:last-child) > *`,
   * `&.open > ul` under `li` is `li.open > ul`), the rule's targets when
   * they stand in that position. Delivery (CssPrinter::printFragmentStyles()
   * under a rule), detach materialization and the part carrier of apply
   * all continue through this, never by writing a second `::`. The
   * client twin is relative-selector.js under().
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
    $compounds = $suffix->compounds;
    $text = (string) $rule;
    if ($compounds !== [] && $compounds[0]['kind'] === self::ANCHOR_KIND) {
      $text .= implode('', array_map(self::printQualifier(...), $compounds[0]['qualifiers']));
      $compounds = array_slice($compounds, 1);
    }
    if ($compounds !== []) {
      $text .= ' ' . self::printCompounds($compounds);
    }
    return self::parse($text . ($suffix->pseudo === NULL ? '' : self::SUFFIX . $suffix->pseudo));
  }

  /**
   * Whether a selector continues a rule's by descent.
   *
   * The read every "this rule and its continuations" consumer shares (the
   * CSS print of a rule key, the clipboard copy at a rule, the rule
   * move's family): a candidate continues the rule when it spells the
   * rule followed by a chain by descent or by the rule's own box, so
   * `> d-text .link`, `> d-text > *` and `> d-text::before` continue
   * `> d-text`, while `> d-text` itself, `> d-textual` and a sibling
   * `> d-container` do not. A rule ending in a pseudo admits none
   * (under()'s rule). A qualifier added to the rule's last compound
   * (`> d-text:has(a)`, `> d-text:first-child`, `> d-text.featured`: the
   * suffix `&:has(a)`, `&:first-child`, `&.featured` under the rule) is a
   * REFINEMENT of the rule, not a continuation: it rebases under the
   * rule (under()) but it is not of the rule's family, which is its
   * descent. The client twins read the same two forms: rule-move.js
   * continuationSuffix() and variant-scope.js relativeUnder().
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
   * The selector truncated after one of its compounds.
   *
   * The chain up to and including compound $index (0-based), without the
   * terminal pseudo-element: the targets a qualifier at that compound
   * qualifies. The pose reads it to stamp a state qualifier where it
   * stands (`li:hover > ul` at 0 is `li:hover`, whose structural reach
   * is the `li`s the stamp lands on; `&:hover > ul` at 0 is `&:hover`,
   * the owner). The client twin is relative-selector.js prefix().
   *
   * @param int $index
   *   The compound's 0-based position in the chain.
   *
   * @return self|null
   *   The prefix, or NULL when the chain has no such compound (the
   *   owner's own pseudo-element has none).
   */
  public function prefix(int $index): ?self {
    if ($index < 0 || $index >= count($this->compounds)) {
      return NULL;
    }
    return self::parse(self::printCompounds(array_slice($this->compounds, 0, $index + 1)));
  }

  /**
   * The first compound.
   *
   * The qualified anchor when the selector begins with one.
   *
   * @return array{combinator: string, kind: string, name: string, qualifiers: array}
   *   The compound: its combinator, kind, name and qualifiers.
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
   * The last compound.
   *
   * The one the rule's targets are, the one the roster walk lands on, and
   * the host of a terminal pseudo-element.
   *
   * For the qualified anchor alone (`&:first-child`, `&:first-child::before`)
   * it is the anchor compound: the targets are the owner itself.
   *
   * @return array{combinator: string, kind: string, name: string, qualifiers: array}
   *   The compound: its combinator, kind, name and qualifiers.
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
   * Reads the first subject past the anchor, qualified or not, a pseudo
   * behind it or not (a part's generated box is inside the part's
   * anatomy, and `&:first-child .cls` is the owner's own part when the
   * owner stands first). The one shape the delivery donut still bounds
   * (a part's class name recurs in nested identical anatomy); every
   * other selector reaches its whole subtree. A class qualifying a tag
   * or type subject (`li.active`) is that subject's compound, not a
   * part first. The owner's own pseudo (`&::before`) and the qualified
   * anchor alone have no first subject.
   */
  public function isPartFirst(): bool {
    $first = $this->compounds[0] ?? NULL;
    if (($first['kind'] ?? NULL) === self::ANCHOR_KIND) {
      $first = $this->compounds[1] ?? NULL;
    }
    return ($first['kind'] ?? NULL) === self::CLASS_PART;
  }

  /**
   * Whether the selector is exactly the element's direct children (`> *`).
   *
   * A qualified compound (`> *:not(d-image)`) or a pseudo behind it
   * (`> *::before`) is a rule of its own.
   */
  public function isChildren(): bool {
    return $this->isOne(self::CHILD, self::UNIVERSAL);
  }

  /**
   * Whether the selector is exactly one part class (`.cls`, `> .cls`).
   *
   * A qualified compound (`.cls:has(a)`, `.a.b`) or a pseudo behind it
   * (`.cls::after`) is a rule of its own.
   */
  public function isPart(): bool {
    return $this->pseudo === NULL && count($this->compounds) === 1 && $this->compounds[0]['kind'] === self::CLASS_PART && $this->compounds[0]['qualifiers'] === [];
  }

  /**
   * Whether the selector is exactly one tag (`p`): the tag tier.
   *
   * A qualified compound (`p:has(a)`, `a.button`) or a pseudo behind it
   * (`p::before`) is a rule of its own.
   */
  public function isTag(): bool {
    return $this->isOne(self::DESCENDANT, self::TAG);
  }

  /**
   * Whether the selector is one unqualified compound of a combinator and kind.
   *
   * The compound carries no pseudo behind it.
   */
  private function isOne(string $combinator, string $kind): bool {
    return $this->pseudo === NULL
      && count($this->compounds) === 1
      && $this->compounds[0]['combinator'] === $combinator
      && $this->compounds[0]['kind'] === $kind
      && $this->compounds[0]['qualifiers'] === [];
  }

  /**
   * Whether the selector crosses into children.
   *
   * A relation or a type at the top level.
   *
   * A rule shaped like this addresses other elements' roots by relation
   * (`>`, `+`, `~` or a type anywhere in the chain), so its delivery
   * reaches past the owner's own anatomy on purpose. A narrowing
   * qualifier's argument never decides this (`:has()`, `:not()` narrow
   * a compound and reach nothing of their own); an `:is()` member is
   * the compound's own subject in disjunction, so a type among the
   * members is a type at that position (`*:is(d-image, d-text)`, the
   * fold of `d-text, d-image`, reaches as `d-text` does). Nor does the
   * qualified anchor, which is the owner (`&:first-child > *` reaches
   * by its `> *`), nor a qualified universal by descent (`*:hover`, like
   * a tag by descent).
   */
  public function reachesChildren(): bool {
    foreach ($this->compounds as $compound) {
      if ($compound['combinator'] !== self::DESCENDANT || self::isType($compound)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a compound's subject is a type, an `:is()` member's included.
   */
  private static function isType(array $compound): bool {
    if ($compound['kind'] === self::TYPE) {
      return TRUE;
    }
    foreach ($compound['qualifiers'] as $qualifier) {
      if ($qualifier['name'] === self::IS && array_filter($qualifier['argument'], self::isType(...)) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The class names the selector carries, in print order (`.cls` spelled).
   *
   * Every class counts, the subjects and the qualifiers (`li.active`'s
   * `.active`), the qualifiers' arguments included: a class the selector
   * names anywhere is one the selector depends on (an instance part
   * inside `:not()` travels no better than one in the chain).
   *
   * @return string[]
   *   The class names, in print order.
   */
  public function classes(): array {
    return self::classesOf($this->compounds);
  }

  /**
   * The classes of compounds and their qualifiers, in print order.
   *
   * @return string[]
   *   The class names, in print order.
   */
  private static function classesOf(array $compounds): array {
    $classes = [];
    foreach ($compounds as $compound) {
      if ($compound['kind'] === self::CLASS_PART) {
        $classes[] = $compound['name'];
      }
      foreach ($compound['qualifiers'] as $qualifier) {
        // A position, state or attribute qualifier names no class.
        if ($qualifier['name'] === self::CLASS_QUALIFIER) {
          $classes[] = '.' . $qualifier['argument'];
        }
        elseif ($qualifier['name'] === self::HAS) {
          $classes = array_merge($classes, self::classesOf($qualifier['argument']));
        }
        elseif ($qualifier['name'] === self::NOT) {
          $classes = array_merge($classes, self::classesOf([$qualifier['argument']]));
        }
        elseif ($qualifier['name'] === self::IS) {
          $classes = array_merge($classes, self::classesOf($qualifier['argument']));
        }
      }
    }
    return $classes;
  }

  /**
   * The root address a type compound names.
   *
   * `d-container` is the container kind (every layout), `d-block` the
   * block kind (the plugin blocks and undeclared bundles the page
   * language prints as `<d-block>`), `d-{bundle}` the inline block type.
   *
   * @param string $type
   *   A type compound name (`d-text`).
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address
   *   The root address.
   *
   * @throws \InvalidArgumentException
   *   When the name is not a type compound.
   */
  public static function typeAddress(string $type): Address {
    if (!preg_match(self::TYPE_PATTERN, $type)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a type compound (d-{block type}, d-container, d-block).', $type));
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
   * `d-{bundle}`, `kind:container` `d-container`, `kind:block`
   * `d-block`; a layout, a block plugin, a field, an entity and a base
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
   *
   * Public for the CSS printers, which wrap this spelling in :where() at
   * the qualifier's compound rather than spell a qualifier a second time.
   */
  public static function printQualifier(array $qualifier): string {
    return match ($qualifier['name']) {
      self::HAS => ':has(' . self::printCompounds($qualifier['argument']) . ')',
      self::NOT => ':not(' . self::printCompound($qualifier['argument']) . ')',
      self::IS => ':is(' . implode(', ', array_map(self::printCompound(...), $qualifier['argument'])) . ')',
      self::CLASS_QUALIFIER => '.' . $qualifier['argument'],
      self::ATTRIBUTE => self::printAttribute($qualifier['argument']),
      default => ':' . $qualifier['name'] . ($qualifier['argument'] === NULL ? '' : '(' . $qualifier['argument'] . ')'),
    };
  }

  /**
   * The canonical spelling of an attribute qualifier's record.
   */
  private static function printAttribute(array $attribute): string {
    $printed = '[' . $attribute['attribute'];
    if ($attribute['operator'] !== NULL) {
      $printed .= $attribute['operator'] . self::printString($attribute['value']) . ($attribute['flag'] === NULL ? '' : ' ' . $attribute['flag']);
    }
    return $printed . ']';
  }

  /**
   * The canonical spelling.
   *
   * The chain (or the anchor) with the pseudo glued to it.
   */
  public function __toString(): string {
    return ($this->compounds === [] ? self::ANCHOR : self::printCompounds($this->compounds))
      . ($this->pseudo === NULL ? '' : self::SUFFIX . $this->pseudo);
  }

}
