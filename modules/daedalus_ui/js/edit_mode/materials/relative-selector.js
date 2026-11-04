/**
 * @file
 * The relative selector grammar: client twin of daedalus_blueprint's
 * RelativeSelector (PHP authoritative, locked by RelativeSelectorTest's
 * shared battery through tests/js/relative-selector-cases.mjs).
 *
 * The suffix after a style key's "::" (a style map key, a variant's or
 * skin's relative key, an address) is CSS's own relative selector,
 * relative to the element the root names, in CSS's own recursive shape:
 *
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
 *   pseudo     := '::' [a-z][a-z0-9-]*       the last compound's pseudo-element (vocabulary: the wide tier)
 *               | ':before' | ':after' | ':first-letter' | ':first-line'   CSS 2's single-colon spellings
 *   tag        := [a-z][a-z0-9]*            a declared base tag (vocabulary)
 *   type       := 'd-' [a-z][a-z0-9-]*     d-container, d-block, d-{bundle}
 *   class      := [A-Za-z0-9_-]+            a declared part, an instance part, or a markup class
 *   pseudo-class := -?[a-z][a-z0-9-]*       any name: a state, a position, an input state
 *
 * The grammar is CSS's; the vocabulary (which tags, types, parts,
 * classes, attributes and pseudo-class names exist on a site, and which
 * pseudo-classes are states the editor can pose) is the roster's and the
 * condition roster's. Structural rules: a bare `*` only after `>`, `+`
 * or `~` (a qualified one, `*:hover`, `*[aria-expanded]`, stands
 * anywhere, and is what an implied subject means in a chain:
 * `[aria-expanded="true"] > ul` is `*[aria-expanded="true"] > ul`;
 * inside `:not()` the implied subject prints nothing and `:not(*)` is
 * degenerate); a class qualifies any compound (`li.active > ul`,
 * `&.open .menu`), and a compound whose subject is `*` or implied
 * carries its classes as the subject, the first by name (`*.cls` is
 * `.cls`, `.b.a` is `.a.b`); an attribute qualifies any compound with
 * CSS's six operators, a quoted or ident value and the `i` / `s` flag,
 * printed with double quotes and a lowercase name; a pseudo-class
 * qualifies any compound, the last included (`li:hover > ul`,
 * `a:visited`, `li:not(:hover)`, `> d-text:has(a:hover)`), the
 * functional ones being the grammar's own (`:has()`, `:not()`, `:is()`,
 * the `An+B` positions; `:lang()`, `:dir()` refused, `:has`, `:not` and
 * `:is` without parens no pseudo-classes, `:before` / `:after` never
 * pseudo-classes but the terminal pseudo-element when they end the
 * selector); a leading `+` or `~` refused at the top level and admitted
 * inside `:has()`; no `:has()` anywhere inside a `:has()` argument;
 * `:not()` takes ONE compound whose subject may be implied
 * (`:not(:has(a))`, `:not(:last-child)`, `:not([hidden])`) and never
 * `*` alone or `:not()`; `:is()` is any of several compounds at the
 * compound it qualifies (`*:is(h1, h2)`, `.menu-item:is(:focus-within,
 * :hover) > ul`, `> *:is(div, p)`, `*:is(:not(.b), a)`: CSS's own
 * disjunction, the one home a selector list has in a key), each member
 * a compound as a chain admits one (subject optional, `:has()` and
 * `:not()` inside, never `:is()` or the anchor, no `:has()` when the
 * member stands inside a `:has()` argument), members sorted by printed
 * form and distinct, a single member merged into its host (`*:is(h1)`
 * is `h1`, `a:is(.x)` is `a.x`; a member subject against a host that
 * has its own, `h1:is(h2)`, is refused with `*` alone, an empty member
 * and `:is()`), `:where()` read as `:is()` and never written back, and
 * `:is()` refused inside `:not()` (`:not(:is(a, b))` is
 * `:not(a):not(b)`) and inside `:is()`; a position's `An+B` canonical as CSS serializes
 * it (`2n+1`, `n`, `-n+3`, `3`; `odd` and `even` kept as words); the
 * anchor `&` is the owner itself, first, qualified, at the top level
 * (`&:first-child`, `&:hover > ul`, `&.open .menu`), the chain behind it
 * by descent or `>` only, carried as the leading compound of kind
 * ANCHOR_KIND named `&` reached by `descendant`, while an unqualified
 * anchor is no address (`& > p` is `> p`); a pseudo-element is terminal
 * (`::name` glued to the last compound, any name the browser spells,
 * nothing after it, no argument, never inside a qualifier), the owner's own is
 * `&::before` / `&::after` with NO compounds (the bare anchor is no
 * compound); no top-level commas (a list is the digester's to fold into
 * `:is()`, where the comma lives). The
 * terminal pseudo is the only `::` a selector carries outside a quoted
 * attribute value, and something always precedes it, so the FIRST "::"
 * of any key still splits root from selector. One canonical spelling
 * (combinators spaced, a lead glued to its space, qualifiers glued to
 * their subject and sorted by their printed form, `.active` before
 * `:hover` before `[href]`, the pseudo glued to its host, no trailing
 * space); parseSelector reads leniently on whitespace, on the case of
 * every name but classes and attribute values (`Li:HOVER > UL` is
 * `li:hover > ul`), on attribute quoting and on the legacy `:before`
 * (a space before `::` is refused: CSS reads `p ::before` as every
 * descendant's box), printSelector prints canonical, and the key gates
 * admit the canonical spelling only. The record is `{compounds, pseudo}`
 * (the server class's two fields): the pseudo rides the selector, never
 * a compound. The server class owns the rationale of every rule.
 */

export const SUFFIX = '::';

export const UNIVERSAL = 'universal';
export const TAG = 'tag';
export const TYPE = 'type';
export const CLASS_PART = 'class';

/**
 * The kind of the qualified anchor: the owner itself as the leading
 * compound (`&:first-child`), named ANCHOR.
 */
export const ANCHOR_KIND = 'anchor';

/**
 * The combinators: what reaches a compound from the one before it. The
 * first compound's combinator is its lead (`descendant` when none is
 * written, and for the qualified anchor).
 */
export const DESCENDANT = 'descendant';
export const CHILD = 'child';
export const NEXT = 'next';
export const SUBSEQUENT = 'subsequent';

/**
 * The qualifier names. A qualifier record is `{name, argument}`: HAS
 * carries a chain, NOT one compound, IS a list of two or more compounds
 * (members, each shaped as NOT's argument, sorted by printed form,
 * distinct), CLASS_QUALIFIER a class name without its dot
 * (`{name: 'class', argument: 'active'}` prints `.active`), ATTRIBUTE an
 * `{attribute, operator, value, flag}` record (`[name]` when operator
 * and value are null; the flag `i`, `s` or null), an NTH_POSITIONS name
 * its canonical `An+B` string (canonicalNth()); every other name is a
 * pseudo-class with a null argument (a POSITIONS name, a state, an
 * input state). CLASS_QUALIFIER and ATTRIBUTE are record names, so `:class` and
 * `:attribute` are no pseudo-classes.
 */
export const HAS = 'has';
export const NOT = 'not';
export const IS = 'is';
export const CLASS_QUALIFIER = 'class';

/**
 * CSS's `:where()`, read as IS and never written: no record carries it.
 */
const WHERE = 'where';
export const ATTRIBUTE = 'attribute';
export const POSITIONS = [
  'first-child',
  'last-child',
  'only-child',
  'empty',
  'first-of-type',
  'last-of-type',
  'only-of-type',
];
export const NTH_POSITIONS = [
  'nth-child',
  'nth-last-child',
  'nth-of-type',
  'nth-last-of-type',
];

/**
 * The attribute operators, as CSS spells them.
 */
export const ATTRIBUTE_OPERATORS = ['=', '~=', '|=', '^=', '$=', '*='];

const COMBINATOR_SYMBOLS = { [CHILD]: '>', [NEXT]: '+', [SUBSEQUENT]: '~' };
const COMBINATORS_BY_SYMBOL = { '>': CHILD, '+': NEXT, '~': SUBSEQUENT };

/**
 * The type compound prefix (the page language's element tag prefix): the
 * one client spelling, the twin of RelativeSelector::TYPE_PREFIX (PHP).
 * The fixed tag names and the type pattern derive from it.
 */
export const TYPE_PREFIX = 'd-';
export const TYPE_CONTAINER = `${TYPE_PREFIX}container`;
export const TYPE_BLOCK = `${TYPE_PREFIX}block`;

/**
 * The compound charsets (the canonical, lowercase spellings).
 */
export const TAG_PATTERN = /^[a-z][a-z0-9]*$/;
export const TYPE_PATTERN = new RegExp(`^${TYPE_PREFIX}[a-z][a-z0-9-]*$`);
export const CLASS_PATTERN = /^\.[A-Za-z0-9_-]+$/;

/**
 * The two generated boxes, by name (no `::`).
 *
 * CSS's `::before` and `::after`: the pseudo-elements that exist only
 * while the cascade sets their `content` (the generated_content group,
 * the existence switch). NOT the grammar's admission list: the terminal
 * slot takes any pseudo-element name the browser spells (PSEUDO_PATTERN:
 * `::marker`, `::placeholder`, `::selection`, `::first-letter`,
 * `::first-line`, `::backdrop`, …), and whether this site knows and
 * admits one is the style guide's verdict over the wide selector tier,
 * as for a tag. These two name the sites that switch a box on: the birth
 * seed, the content grant, the address chip's two offers, the
 * page-elements tree's always-listed rows.
 */
export const PSEUDO_BEFORE = 'before';
export const PSEUDO_AFTER = 'after';
export const GENERATED_BOXES = [PSEUDO_BEFORE, PSEUDO_AFTER];

/**
 * The pseudo-elements CSS 2's single-colon spelling still names
 * (`p:before`, `p:first-line`); every other single-colon name is a
 * pseudo-class.
 */
export const LEGACY_PSEUDOS = ['before', 'after', 'first-letter', 'first-line'];

/**
 * A pseudo-element's name: a lowercase CSS ident, no vendor prefix.
 */
export const PSEUDO_PATTERN = /^[a-z][a-z0-9-]*$/;

/**
 * The owner's own anchor: the one subject of its own pseudo-element.
 */
export const ANCHOR = '&';

/**
 * The terminal pseudo-element and the trailing whitespace behind it, in
 * any case: `::` and any name (group 2), or the legacy single-colon
 * spelling of the four names it still means (group 1).
 */
const PSEUDO_AT_END =
  /(?:::?(before|after|first-letter|first-line)|::([A-Za-z][A-Za-z0-9-]*))\s*$/i;

/**
 * The DOM attribute carrying an element's address (daedalus_ui's
 * ElementAddress::ATTRIBUTE twin): what a type compound selects by.
 */
export const ELEMENT_ADDRESS_ATTRIBUTE = 'data-element';

const CLASS_AT = /\.[A-Za-z0-9_-]+/y;
const WORD_AT = /[A-Za-z][A-Za-z0-9-]*/y;

/**
 * A pseudo-class's name at the read position (any case), and its `(`.
 */
const QUALIFIER_AT = /:(-?[A-Za-z][A-Za-z0-9-]*)(\(?)/y;

/**
 * An attribute's name, operator, ident value and flag at the read
 * position.
 */
const ATTRIBUTE_NAME_AT = /[A-Za-z_-][A-Za-z0-9_-]*/y;
const ATTRIBUTE_OPERATOR_AT = /[~|^$*]?=/y;
const IDENT_AT = /(?:--|-?[A-Za-z_])[A-Za-z0-9_-]*/y;
const FLAG_AT = /([iIsS])\s*\]/y;
const HEX_ESCAPE_AT = /[0-9A-Fa-f]{1,6}\s?/y;

/**
 * The names a pseudo-class may not carry: the functional names without
 * their parens, the record names, the legacy pseudo-elements'.
 */
const NOT_PSEUDO_CLASSES = [
  HAS,
  NOT,
  IS,
  WHERE,
  CLASS_QUALIFIER,
  ATTRIBUTE,
  ...NTH_POSITIONS,
  ...LEGACY_PSEUDOS,
];

/**
 * An `An+B` argument, whitespace free at the ends and around the sign,
 * any case. Groups: 1 the keyword, 2 A's sign, 3 A's digits, 4 B's
 * sign, 5 B's digits, 6 a bare integer.
 */
const ANB = /^(?:(odd|even)|([+-]?)(\d*)n(?:\s*([+-])\s*(\d+))?|([+-]?\d+))$/i;

/**
 * The canonical spelling of an `An+B` argument, or null when it is none
 * (RelativeSelector::canonicalNth): CSS's own serialization, `odd` and
 * `even` kept as words, A as `n`, `-n` or `{A}n`, B signed when not
 * zero, B alone when A is zero (`2n+1`, `n`, `-n+3`, `3`, `2n`, `0`).
 * Lenient on case and on whitespace at the ends and around B's sign
 * only; the `of S` form is refused.
 *
 * @param {*} argument
 *   The argument text.
 *
 * @return {?string}
 *   The canonical spelling, or null.
 */
export const canonicalNth = (argument) => {
  const match = typeof argument === 'string' ? ANB.exec(argument.trim()) : null;
  if (match === null) {
    return null;
  }
  if (match[1] !== undefined) {
    return match[1].toLowerCase();
  }
  if (match[6] !== undefined) {
    return String(parseInt(match[6], 10));
  }
  const a =
    (match[3] === '' ? 1 : parseInt(match[3], 10)) *
    (match[2] === '-' ? -1 : 1);
  const b =
    match[5] === undefined
      ? 0
      : parseInt(match[5], 10) * (match[4] === '-' ? -1 : 1);
  if (a === 0) {
    return String(b);
  }
  let head = `${a}n`;
  if (a === 1) {
    head = 'n';
  } else if (a === -1) {
    head = '-n';
  }
  let tail = '';
  if (b > 0) {
    tail = `+${b}`;
  } else if (b < 0) {
    tail = `-${-b}`;
  }
  return `${head}${tail}`;
};

/**
 * The canonical spelling of one character of an attribute value: a quote
 * and a backslash escaped as themselves, a control character (C0 and
 * DEL) as its hex escape with its terminating space, any other as is.
 *
 * @param {string} char
 *   One UTF-16 code unit.
 *
 * @return {string}
 *   The spelling.
 */
const printChar = (char) => {
  if (char === '"') {
    return '\\"';
  }
  if (char === '\\') {
    return '\\\\';
  }
  const code = char.charCodeAt(0);
  return code < 0x20 || code === 0x7f ? `\\${code.toString(16)} ` : char;
};

/**
 * The canonical spelling of an attribute value: double-quoted, each
 * character as printChar spells it.
 *
 * @param {string} value
 *   The decoded value.
 *
 * @return {string}
 *   The spelling.
 */
const printString = (value) =>
  `"${Array.prototype.map.call(value, printChar).join('')}"`;

/**
 * The canonical spelling of an attribute qualifier's record.
 *
 * @param {object} record
 *   The record (parseAttribute).
 * @param {string} record.attribute
 *   The attribute's name, lowercased.
 * @param {?string} record.operator
 *   The operator (`=`, `^=`, ...), or null for a bare `[name]`.
 * @param {?string} record.value
 *   The decoded value, or null with no operator.
 * @param {?string} record.flag
 *   The `i` or `s` flag, or null.
 *
 * @return {string}
 *   The spelling.
 */
const printAttribute = ({ attribute, operator, value, flag }) =>
  `[${attribute}${operator === null ? '' : `${operator}${printString(value)}${flag === null ? '' : ` ${flag}`}`}]`;

/**
 * The canonical spelling of one qualifier.
 *
 * Exported for the CSS printer, which wraps this spelling in :where() at
 * the qualifier's compound rather than spell a qualifier a second time.
 *
 * @param {{name: string, argument: *}} qualifier
 *   A parsed qualifier (see parseSelector).
 *
 * @return {string}
 *   The spelling.
 */
export const printQualifier = (qualifier) => {
  if (qualifier.name === HAS) {
    return `:has(${printCompounds(qualifier.argument)})`;
  }
  if (qualifier.name === NOT) {
    return `:not(${printCompound(qualifier.argument)})`;
  }
  if (qualifier.name === IS) {
    return `:is(${qualifier.argument.map(printCompound).join(', ')})`;
  }
  if (qualifier.name === CLASS_QUALIFIER) {
    return `.${qualifier.argument}`;
  }
  if (qualifier.name === ATTRIBUTE) {
    return printAttribute(qualifier.argument);
  }
  return `:${qualifier.name}${qualifier.argument === null ? '' : `(${qualifier.argument})`}`;
};

/**
 * The canonical spelling of one compound without its combinator.
 *
 * @param {Object} compound
 *   A parsed compound (see parseSelector).
 *
 * @return {string}
 *   The spelling.
 */
export const printCompound = (compound) =>
  `${compound.name ?? ''}${compound.qualifiers.map(printQualifier).join('')}`;

/**
 * The canonical spelling of a chain of compounds: each behind its
 * combinator (a lead glued to its space, a descendant combinator the one
 * space between compounds), the subject glued to its qualifiers
 * (RelativeSelector::printCompounds).
 *
 * @param {Object[]} compounds
 *   Parsed compounds (see parseSelector).
 *
 * @return {string}
 *   The spelling.
 */
export const printCompounds = (compounds) =>
  compounds
    .map(
      (compound, i) =>
        `${i > 0 ? ' ' : ''}${
          compound.combinator === DESCENDANT
            ? ''
            : `${COMBINATOR_SYMBOLS[compound.combinator]} `
        }${printCompound(compound)}`,
    )
    .join('');

/**
 * The canonical spelling of a parsed selector: the chain (or the anchor
 * when there is none) with the pseudo glued to it.
 *
 * @param {object} record
 *   A parsed selector (parseSelector).
 * @param {Object[]} record.compounds
 *   The compounds.
 * @param {?string} record.pseudo
 *   The terminal pseudo-element's name, or null.
 *
 * @return {string}
 *   The spelling.
 */
export const printSelector = ({ compounds, pseudo }) =>
  `${compounds.length ? printCompounds(compounds) : ANCHOR}${pseudo ? `${SUFFIX}${pseudo}` : ''}`;

/**
 * Advances the cursor past whitespace.
 *
 * The cursor is a read position over the text, `{at}`, advanced in place
 * by the parse functions.
 *
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position.
 */
const skipSpace = (text, cursor) => {
  while (cursor.at < text.length && /\s/.test(text[cursor.at])) {
    cursor.at += 1;
  }
};

/**
 * Matches a sticky pattern at the cursor, or null.
 *
 * @param {RegExp} pattern
 *   A sticky (`y`) pattern.
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position; not advanced.
 *
 * @return {?Array<string>}
 *   The match, or null.
 */
const matchAt = (pattern, text, cursor) => {
  pattern.lastIndex = cursor.at;
  return pattern.exec(text);
};

/**
 * Reads a quoted string at the cursor (on its opening quote), decoded
 * (RelativeSelector::readString): either quote, a backslash escaping the
 * next character, a hex escape of up to six digits and one optional
 * whitespace after it (an invalid code point reads as U+FFFD, as does a
 * raw NUL), a backslash before a newline a continuation. An unescaped
 * newline or the end of the text before the closing quote is no string
 * (null).
 *
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position, on the opening quote; left after the closing one.
 *
 * @return {?string}
 *   The decoded string, or null.
 */
function readString(text, cursor) {
  const quote = text[cursor.at];
  cursor.at += 1;
  let value = '';
  while (cursor.at < text.length) {
    const char = text[cursor.at];
    if (char === quote) {
      cursor.at += 1;
      return value;
    }
    if (char === '\n' || char === '\r' || char === '\f') {
      return null;
    }
    cursor.at += 1;
    if (char === '\0') {
      value += '\uFFFD';
      continue;
    }
    if (char !== '\\') {
      value += char;
      continue;
    }
    if (cursor.at >= text.length) {
      return null;
    }
    if (text[cursor.at] === '\n') {
      cursor.at += 1;
      continue;
    }
    const hex = matchAt(HEX_ESCAPE_AT, text, cursor);
    if (hex !== null) {
      cursor.at += hex[0].length;
      const code = parseInt(hex[0].trim(), 16);
      value +=
        code === 0 || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff)
          ? '\uFFFD'
          : String.fromCodePoint(code);
      continue;
    }
    value += text[cursor.at];
    cursor.at += 1;
  }
  return null;
}

/**
 * Parses an attribute qualifier (`[` at the cursor) into its record
 * (RelativeSelector::parseAttribute): `[name]`, `[name op value]`,
 * `[name op value flag]`, the name lowercased, the value a string in
 * either quote or an ident kept as written, the flag `i` or `s` in any
 * case, after whitespace when the value is an ident. Null when the
 * brackets hold anything else.
 *
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position, on the `[`; left after the `]`.
 *
 * @return {?{attribute: string, operator: ?string, value: ?string, flag: ?string}}
 *   The record, or null.
 */
function parseAttribute(text, cursor) {
  cursor.at += 1;
  skipSpace(text, cursor);
  const name = matchAt(ATTRIBUTE_NAME_AT, text, cursor);
  if (name === null) {
    return null;
  }
  const attribute = name[0].toLowerCase();
  cursor.at += name[0].length;
  skipSpace(text, cursor);
  let operator = null;
  let value = null;
  let flag = null;
  const op = matchAt(ATTRIBUTE_OPERATOR_AT, text, cursor);
  if (op !== null) {
    operator = op[0];
    cursor.at += op[0].length;
    skipSpace(text, cursor);
    const quote = text[cursor.at] ?? '';
    const quoted = quote === '"' || quote === "'";
    if (quoted) {
      value = readString(text, cursor);
    } else {
      const ident = matchAt(IDENT_AT, text, cursor);
      if (ident !== null) {
        value = ident[0];
        cursor.at += ident[0].length;
      }
    }
    if (value === null) {
      return null;
    }
    const before = cursor.at;
    skipSpace(text, cursor);
    const flagged =
      quoted || cursor.at > before ? matchAt(FLAG_AT, text, cursor) : null;
    if (flagged !== null) {
      flag = flagged[1].toLowerCase();
      cursor.at += 1;
      skipSpace(text, cursor);
    }
  }
  if (text[cursor.at] !== ']') {
    return null;
  }
  cursor.at += 1;
  return { attribute, operator, value, flag };
}

/**
 * Parses one compound (a subject and its qualifiers) at the cursor.
 *
 * The subject is `*`, a tag or type word (any case), or implied when a
 * qualifier opens the compound. A compound whose subject is `*` or
 * implied carries its classes as the subject (the first by name; `*.cls`
 * and `.cls` are one spelling); without classes the implied subject is
 * the universal `*` in a chain (printed) and nothing in a predicate (a
 * `:not()` argument or an `:is()` member), and a bare `*` is refused by
 * descent (every descendant is no address) and in a predicate
 * (degenerate). A single-member `:is()` merges into the compound before
 * its subject settles. `combinator` is what reaches it (null for a
 * predicate), `inHas` whether it stands anywhere inside a `:has()`
 * argument, `inNot` whether it stands anywhere inside a `:not()`
 * argument (`:not()` and `:is()` refused), `inIs` whether it stands
 * anywhere inside an `:is()` member (`:is()` refused). Null outside the
 * grammar.
 *
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position, on the compound's first character; left after it.
 * @param {?string} combinator
 *   The combinator reaching the compound, null for a predicate.
 * @param {boolean} inHas
 *   Whether the compound stands inside a `:has()` argument.
 * @param {boolean} inNot
 *   Whether the compound stands inside a `:not()` argument.
 * @param {boolean} inIs
 *   Whether the compound stands inside an `:is()` member.
 *
 * @return {?{combinator: ?string, kind: ?string, name: ?string, qualifiers: Array}}
 *   The compound, or null.
 */
function parseCompound(text, cursor, combinator, inHas, inNot, inIs) {
  let kind = null;
  let name = null;
  const char = text[cursor.at] ?? '';
  if (char === '*') {
    kind = UNIVERSAL;
    name = '*';
    cursor.at += 1;
  } else {
    const word = matchAt(WORD_AT, text, cursor);
    if (word !== null) {
      name = word[0].toLowerCase();
      if (TYPE_PATTERN.test(name)) {
        kind = TYPE;
      } else if (TAG_PATTERN.test(name)) {
        kind = TAG;
      } else {
        return null;
      }
      cursor.at += word[0].length;
    } else if (char !== '.' && char !== '[' && char !== ':') {
      return null;
    }
  }
  const parsed = parseQualifiers(text, cursor, inHas, inNot, inIs);
  if (parsed === null) {
    return null;
  }
  const merged = mergeSingletons(kind, name, parsed);
  if (merged === null) {
    return null;
  }
  const { qualifiers } = merged;
  ({ kind, name } = merged);
  if (kind === null || kind === UNIVERSAL) {
    // The classes sort first: the first one is the subject.
    if (qualifiers[0]?.name === CLASS_QUALIFIER) {
      kind = CLASS_PART;
      name = `.${qualifiers.shift().argument}`;
    } else if (combinator === null) {
      if (!qualifiers.length) {
        return null;
      }
      kind = null;
      name = null;
    } else if (!qualifiers.length && combinator === DESCENDANT) {
      return null;
    } else {
      kind = UNIVERSAL;
      name = '*';
    }
  }
  return { combinator, kind, name, qualifiers };
}

/**
 * Compares two printed forms by code unit, the PHP twin's byte order
 * (never the locale's), so the canonical sort agrees across the seam.
 *
 * @param {string} a
 *   A printed form.
 * @param {string} b
 *   Another.
 *
 * @return {number}
 *   Negative when a sorts first, positive when b does, zero when equal.
 */
const compareText = (a, b) => {
  if (a < b) {
    return -1;
  }
  return a > b ? 1 : 0;
};

/**
 * Sorts qualifiers by their printed form: the one canonical order.
 *
 * @param {Array<{name: string, argument: *}>} qualifiers
 *   Parsed qualifiers; sorted in place.
 *
 * @return {Array<{name: string, argument: *}>}
 *   The same array.
 */
const sortQualifiers = (qualifiers) =>
  qualifiers.sort((a, b) => compareText(printQualifier(a), printQualifier(b)));

/**
 * Merges every single-member `:is()` among the qualifiers into its host
 * (RelativeSelector::mergeSingletons): `:is(x)` is `x`, so the record
 * never carries one. The member's subject becomes the host's when the
 * host has none (`*:is(h1)` is `h1`), a class subject goes back to the
 * class pool (`a:is(.x)` is `a.x`; the host's subject settles after the
 * merge), and the member's qualifiers sort in with the host's. A member
 * subject against a host that has its own (`h1:is(h2)`, `&:is(h1)`;
 * `h1:is(h1)` is `h1`) is refused: nothing is both.
 *
 * @param {?string} kind
 *   The host's subject kind as parsed, null when implied.
 * @param {?string} name
 *   The host's subject name as parsed, null when implied.
 * @param {Array<{name: string, argument: *}>} qualifiers
 *   The host's qualifiers, sorted.
 *
 * @return {?{kind: ?string, name: ?string, qualifiers: Array}}
 *   The host after the merge, or null when a member contradicts it.
 */
function mergeSingletons(kind, name, qualifiers) {
  const merged = [];
  let changed = false;
  for (const qualifier of qualifiers) {
    if (qualifier.name !== IS || qualifier.argument.length !== 1) {
      merged.push(qualifier);
      continue;
    }
    changed = true;
    const [member] = qualifier.argument;
    if (member.kind === CLASS_PART) {
      merged.push({ name: CLASS_QUALIFIER, argument: member.name.slice(1) });
    } else if (member.kind !== null) {
      if (kind === null || kind === UNIVERSAL) {
        ({ kind, name } = member);
      } else if (kind !== member.kind || name !== member.name) {
        return null;
      }
    }
    merged.push(...member.qualifiers);
  }
  return { kind, name, qualifiers: changed ? sortQualifiers(merged) : merged };
}

/**
 * Parses the qualifiers glued at the cursor, sorted canonical: [] when
 * none is written, null when a `.`, `[` or `:` there opens anything but
 * a qualifier. `inHas` refuses `:has()`, `inNot` refuses `:not()` and
 * `:is()`, `inIs` refuses `:is()`.
 *
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position, after the subject; left after the qualifiers.
 * @param {boolean} inHas
 *   Whether the compound stands inside a `:has()` argument.
 * @param {boolean} inNot
 *   Whether the compound stands inside a `:not()` argument.
 * @param {boolean} inIs
 *   Whether the compound stands inside an `:is()` member.
 *
 * @return {?Array<{name: string, argument: *}>}
 *   The qualifiers sorted canonical, or null.
 */
function parseQualifiers(text, cursor, inHas, inNot, inIs) {
  const qualifiers = [];
  for (;;) {
    const char = text[cursor.at] ?? '';
    if (char === '.') {
      const cls = matchAt(CLASS_AT, text, cursor);
      if (cls === null) {
        return null;
      }
      cursor.at += cls[0].length;
      qualifiers.push({ name: CLASS_QUALIFIER, argument: cls[0].slice(1) });
      continue;
    }
    if (char === '[') {
      const argument = parseAttribute(text, cursor);
      if (argument === null) {
        return null;
      }
      qualifiers.push({ name: ATTRIBUTE, argument });
      continue;
    }
    if (char !== ':') {
      break;
    }
    const match = matchAt(QUALIFIER_AT, text, cursor);
    if (match === null) {
      return null;
    }
    const qualifierName = match[1].toLowerCase();
    const open = match[2] === '(';
    cursor.at += match[0].length;
    let qualifier;
    if (qualifierName === HAS && open) {
      if (inHas) {
        return null;
      }
      const argument = parseChain(text, cursor, true, inNot, inIs);
      if (argument === null) {
        return null;
      }
      qualifier = { name: HAS, argument };
    } else if (qualifierName === NOT && open) {
      if (inNot) {
        return null;
      }
      skipSpace(text, cursor);
      const argument = parseCompound(text, cursor, null, inHas, true, inIs);
      if (argument === null) {
        return null;
      }
      qualifier = { name: NOT, argument };
    } else if ((qualifierName === IS || qualifierName === WHERE) && open) {
      // `:where()` reads as `:is()`. Members split at top-level commas,
      // each a predicate compound; sorted by printed form and distinct,
      // so one disjunction has one spelling. An empty member (`:is()`,
      // `:is(a,)`) is no compound.
      if (inNot || inIs) {
        return null;
      }
      const members = new Map();
      for (;;) {
        skipSpace(text, cursor);
        const member = parseCompound(text, cursor, null, inHas, false, true);
        if (member === null) {
          return null;
        }
        members.set(printCompound(member), member);
        skipSpace(text, cursor);
        if (text[cursor.at] !== ',') {
          break;
        }
        cursor.at += 1;
      }
      const argument = [...members.keys()]
        .sort(compareText)
        .map((printed) => members.get(printed));
      qualifier = { name: IS, argument };
    } else if (NTH_POSITIONS.includes(qualifierName) && open) {
      const close = text.indexOf(')', cursor.at);
      const argument =
        close === -1 ? null : canonicalNth(text.slice(cursor.at, close));
      if (argument === null) {
        return null;
      }
      cursor.at = close;
      qualifier = { name: qualifierName, argument };
    } else if (open || NOT_PSEUDO_CLASSES.includes(qualifierName)) {
      // A functional pseudo-class the grammar does not own, a functional
      // name without its parens, a record name, or a pseudo-element's
      // name spelled with one colon.
      return null;
    } else {
      qualifiers.push({ name: qualifierName, argument: null });
      continue;
    }
    skipSpace(text, cursor);
    if (text[cursor.at] !== ')') {
      return null;
    }
    cursor.at += 1;
    qualifiers.push(qualifier);
  }
  return sortQualifiers(qualifiers);
}

/**
 * Parses a chain (the qualified anchor or a lead, then compounds behind
 * combinators) at the cursor, leaving trailing whitespace unread.
 *
 * `inHas` admits sibling leads and refuses `:has()` anywhere inside;
 * `inNot` refuses `:not()` and `:is()` on every compound, `inIs`
 * refuses `:is()`. Null outside the grammar.
 *
 * @param {string} text
 *   The text being parsed.
 * @param {{at: number}} cursor
 *   The read position; left after the last compound.
 * @param {boolean} inHas
 *   Whether the chain is a `:has()` argument.
 * @param {boolean} inNot
 *   Whether the chain stands inside a `:not()` argument.
 * @param {boolean} inIs
 *   Whether the chain stands inside an `:is()` member.
 *
 * @return {?Object[]}
 *   The compounds in order (parseCompound's shape), or null.
 */
function parseChain(text, cursor, inHas, inNot, inIs) {
  skipSpace(text, cursor);
  const compounds = [];
  if (!inHas && !inNot && !inIs && text[cursor.at] === ANCHOR) {
    // The qualified anchor: the owner itself, top level, first. It
    // carries at least one qualifier (`&` alone and `& > p` are no
    // addresses: the root, and `> p`).
    cursor.at += 1;
    const qualifiers = parseQualifiers(text, cursor, false, false, false);
    if (qualifiers === null || !qualifiers.length) {
      return null;
    }
    // The owner is what it is: a single `:is()` member with a subject
    // has nothing to merge into (`&:is(h1)`), one without merges.
    const merged = mergeSingletons(ANCHOR_KIND, ANCHOR, qualifiers);
    if (merged === null) {
      return null;
    }
    compounds.push({
      combinator: DESCENDANT,
      kind: ANCHOR_KIND,
      name: ANCHOR,
      qualifiers: merged.qualifiers,
    });
  } else {
    let combinator = DESCENDANT;
    const symbol = text[cursor.at] ?? '';
    if (symbol in COMBINATORS_BY_SYMBOL) {
      combinator = COMBINATORS_BY_SYMBOL[symbol];
      if (!inHas && combinator !== CHILD) {
        return null;
      }
      cursor.at += 1;
      skipSpace(text, cursor);
    }
    const compound = parseCompound(
      text,
      cursor,
      combinator,
      inHas,
      inNot,
      inIs,
    );
    if (compound === null) {
      return null;
    }
    compounds.push(compound);
  }
  for (;;) {
    const before = cursor.at;
    skipSpace(text, cursor);
    const symbol = text[cursor.at] ?? '';
    if (symbol === '' || symbol === ')') {
      return compounds;
    }
    let combinator;
    if (symbol in COMBINATORS_BY_SYMBOL) {
      combinator = COMBINATORS_BY_SYMBOL[symbol];
      cursor.at += 1;
      skipSpace(text, cursor);
    } else if (cursor.at === before) {
      // Anything else glued to the compound (`p,`, `p::marker`, `p&`) is
      // outside the grammar; behind whitespace it is the next compound
      // by descent.
      return null;
    } else {
      combinator = DESCENDANT;
    }
    const previous = compounds[compounds.length - 1];
    // The owner's siblings are outside its subtree: behind the anchor
    // the chain descends.
    if (
      previous.kind === ANCHOR_KIND &&
      combinator !== DESCENDANT &&
      combinator !== CHILD
    ) {
      return null;
    }
    const compound = parseCompound(
      text,
      cursor,
      combinator,
      inHas,
      inNot,
      inIs,
    );
    if (compound === null) {
      return null;
    }
    compounds.push(compound);
  }
}

/**
 * Parses a selector, or null when the text is outside the grammar.
 *
 * The terminal pseudo is read first, so `before` never reads as a tag
 * and the chain parser never sees a `::`.
 *
 * @param {*} selector
 *   The selector text, in any spelling the grammar reads.
 *
 * @return {?{compounds: Array<{combinator: string, kind: ?string,
 *   name: ?string, qualifiers: Array<{name: string, argument: *}>}>,
 *   pseudo: ?string}}
 *   The record: the compounds in order, each with the combinator that
 *   reaches it (the first compound's is the lead), its kind and its name
 *   as it prints, and its qualifiers sorted canonical: `{name: 'has',
 *   argument: compounds}` (a chain of the same compounds), `{name:
 *   'not', argument: compound}` (one compound with `combinator` null,
 *   its kind and name null when the subject is implied), `{name: 'is',
 *   argument: compounds}` (two or more compounds shaped as `not`'s,
 *   sorted by printed form, distinct), `{name:
 *   'class', argument: 'active'}`, `{name: 'attribute', argument:
 *   {attribute, operator, value, flag}}`, a position `{name:
 *   'nth-child', argument: '2n+1'}` or any other pseudo-class `{name:
 *   'hover', argument: null}`; and the terminal pseudo by name
 *   ('before', 'marker', any name PSEUDO_PATTERN spells) or null. The
 *   owner's own pseudo (`&::before`) has no compounds.
 */
export const parseSelector = (selector) => {
  if (typeof selector !== 'string') {
    return null;
  }
  let pseudo = null;
  let head = selector;
  const match = PSEUDO_AT_END.exec(selector);
  if (match !== null) {
    pseudo = (match[1] ?? match[2]).toLowerCase();
    head = selector.slice(0, match.index);
    // Glued to its host: a bare pseudo has no host, and a space before
    // `::` is CSS's "every descendant's box", not an address here.
    if (head === '' || /\s/.test(head[head.length - 1])) {
      return null;
    }
    if (head.trimStart() === ANCHOR) {
      return { compounds: [], pseudo };
    }
  }
  const cursor = { at: 0 };
  const compounds = parseChain(head, cursor, false, false, false);
  if (compounds === null) {
    return null;
  }
  skipSpace(head, cursor);
  return cursor.at === head.length ? { compounds, pseudo } : null;
};

/**
 * The canonical spelling of selector text, or null outside the grammar.
 *
 * @param {*} selector
 *   The selector text, in any spelling the grammar reads.
 *
 * @return {?string}
 *   The canonical spelling, or null.
 */
export const canonicalSelector = (selector) => {
  const parsed = parseSelector(selector);
  return parsed === null ? null : printSelector(parsed);
};

/**
 * Whether a string is a selector in its one canonical spelling.
 *
 * @param {*} selector
 *   The candidate.
 *
 * @return {boolean}
 *   Whether it is canonical.
 */
export const isCanonicalSelector = (selector) =>
  canonicalSelector(selector) === selector;

/**
 * Splits a key at its first "::" into the root and its selector.
 *
 * The one split every key grammar shares: the root is everything before
 * the first "::", the selector everything after it, admitted canonical
 * only. A key without "::" is a root with no selector.
 *
 * @param {*} key
 *   The key.
 *
 * @return {?{root: string, selector: ?string}}
 *   The root and the canonical selector (null when the key carries
 *   none), or null when a suffix is present but is not a canonical
 *   selector.
 */
export const splitKey = (key) => {
  if (typeof key !== 'string') {
    return null;
  }
  const pos = key.indexOf(SUFFIX);
  if (pos === -1) {
    return { root: key, selector: null };
  }
  const suffix = key.slice(pos + SUFFIX.length);
  return isCanonicalSelector(suffix)
    ? { root: key.slice(0, pos), selector: suffix }
    : null;
};

/**
 * Whether a selector begins with a part class: the owner's own anatomy
 * (the first subject past the anchor, qualified or not, a pseudo behind
 * it or not; `&:first-child .cls` is the owner's own part when the
 * owner stands first). A class qualifying a tag or type subject
 * (`li.active`) is that subject's compound, not a part first. The
 * owner's own pseudo ('&::before') and the qualified anchor alone have
 * no first subject.
 *
 * @param {*} selector
 *   The selector text.
 *
 * @return {boolean}
 *   Whether a part class comes first.
 */
export const isPartFirst = (selector) => {
  const compounds = parseSelector(selector)?.compounds ?? [];
  const first =
    compounds[0]?.kind === ANCHOR_KIND ? compounds[1] : compounds[0];
  return first?.kind === CLASS_PART;
};

/**
 * Whether a selector is exactly one part class ('.cls', '> .cls'); a
 * qualified one ('.cls:has(a)', '.a.b') or a pseudo behind it
 * ('.cls::after') is a rule of its own.
 *
 * @param {*} selector
 *   The selector text.
 *
 * @return {boolean}
 *   Whether the selector is one part class.
 */
export const isPart = (selector) => {
  const parsed = parseSelector(selector);
  return (
    parsed?.pseudo === null &&
    parsed.compounds.length === 1 &&
    parsed.compounds[0].kind === CLASS_PART &&
    !parsed.compounds[0].qualifiers.length
  );
};

/**
 * Whether a selector is one unqualified compound of a combinator and
 * kind, with no pseudo behind it.
 *
 * @param {*} selector
 *   The selector text.
 * @param {string} combinator
 *   The combinator the one compound must carry.
 * @param {string} kind
 *   The subject kind it must have.
 *
 * @return {boolean}
 *   Whether the selector is that one compound.
 */
const isOne = (selector, combinator, kind) => {
  const parsed = parseSelector(selector);
  return (
    parsed?.pseudo === null &&
    parsed.compounds.length === 1 &&
    parsed.compounds[0].combinator === combinator &&
    parsed.compounds[0].kind === kind &&
    !parsed.compounds[0].qualifiers.length
  );
};

/**
 * Whether a selector is exactly the element's direct children ('> *');
 * a qualified one ('> *:not(d-image)') or a pseudo behind it
 * ('> *::before') is a rule of its own.
 *
 * @param {*} selector
 *   The selector text.
 *
 * @return {boolean}
 *   Whether the selector is '> *'.
 */
export const isChildren = (selector) => isOne(selector, CHILD, UNIVERSAL);

/**
 * Whether a selector is exactly one tag ('p'): the tag tier; a
 * qualified one ('p:has(a)', 'a.button') or a pseudo behind it
 * ('p::before') is a rule of its own.
 *
 * @param {*} selector
 *   The selector text.
 *
 * @return {boolean}
 *   Whether the selector is one tag.
 */
export const isTag = (selector) => isOne(selector, DESCENDANT, TAG);

/**
 * Whether a compound's subject is a type, an `:is()` member's included:
 * a member is the compound's own subject in disjunction
 * (`*:is(d-image, d-text)`, the fold of `d-text, d-image`, reaches as
 * `d-text` does), where `:has()` and `:not()` narrow and reach nothing
 * of their own.
 *
 * @param {Object} compound
 *   A parsed compound.
 *
 * @return {boolean}
 *   Whether its subject is a type.
 */
const isType = (compound) =>
  compound.kind === TYPE ||
  compound.qualifiers.some(
    (qualifier) => qualifier.name === IS && qualifier.argument.some(isType),
  );

/**
 * Whether a selector crosses into children: a relation (`>`, `+`, `~`)
 * or a type at the top level. A narrowing qualifier's argument reaches
 * nothing of its own, nor does the qualified anchor (the owner;
 * `&:first-child > *` reaches by its `> *`) nor a qualified universal
 * by descent (`*:hover`, like a tag by descent), and a pseudo hangs on
 * what the chain reaches.
 *
 * @param {*} selector
 *   The selector text.
 *
 * @return {boolean}
 *   Whether the selector reaches into children.
 */
export const reachesChildren = (selector) =>
  (parseSelector(selector)?.compounds ?? []).some(
    (compound) => compound.combinator !== DESCENDANT || isType(compound),
  );

/**
 * The classes a qualifier names, in print order: a class qualifier's own
 * (`li.active`'s `.active`), a `:has()`, `:is()` or `:not()` argument's
 * recursively; a position, state or attribute qualifier names none.
 *
 * @param {{name: string, argument: *}} qualifier
 *   A parsed qualifier.
 *
 * @return {string[]}
 *   The classes, '.cls' spelled.
 */
const qualifierClasses = (qualifier) => {
  if (qualifier.name === CLASS_QUALIFIER) {
    return [`.${qualifier.argument}`];
  }
  if (qualifier.name === HAS || qualifier.name === IS) {
    return classesOf(qualifier.argument);
  }
  if (qualifier.name === NOT) {
    return classesOf([qualifier.argument]);
  }
  return [];
};

/**
 * The classes of compounds and their qualifiers, in print order: the
 * subjects, then each compound's qualifiers' (qualifierClasses).
 *
 * @param {Object[]} compounds
 *   Parsed compounds.
 *
 * @return {string[]}
 *   The classes, '.cls' spelled.
 */
const classesOf = (compounds) =>
  compounds.flatMap((compound) => [
    ...(compound.kind === CLASS_PART ? [compound.name] : []),
    ...compound.qualifiers.flatMap(qualifierClasses),
  ]);

/**
 * The class names a selector carries, in print order ('.cls' spelled),
 * the qualifiers' arguments included.
 *
 * @param {*} selector
 *   The selector text.
 *
 * @return {string[]}
 *   The classes; empty outside the grammar.
 */
export const selectorClasses = (selector) =>
  classesOf(parseSelector(selector)?.compounds ?? []);

/**
 * Continues a rule's selector by a suffix, by descent
 * (RelativeSelector::under).
 *
 * The one rebase "this rule wears that" shares: a variant's suffix
 * continued under the rule's targets reads `{rule} {suffix}` (`> d-text`
 * under `.link` is `> d-text .link`, `a` under `.cls` is `a .cls`), and
 * the grammar decides admissibility exactly as for an authored key, so
 * a suffix the rule cannot carry answers null. A pseudo binds to the
 * rule's landing: `&::before` under `> d-text` is `> d-text::before`,
 * `.link::before` under it `> d-text .link::before`; a rule already
 * ending in a pseudo admits no continuation. The qualified anchor binds
 * the same way: its qualifiers land on the rule's last compound, sorted
 * in with that compound's own (`&:first-child` under `> d-text` is
 * `> d-text:first-child`, `&:not(:last-child) > *` under `> d-text:has(a)`
 * is `> d-text:has(a):not(:last-child) > *`, `&.open > ul` under `li`
 * is `li.open > ul`).
 *
 * @param {string} rule
 *   The rule's selector.
 * @param {string} suffix
 *   The suffix to continue by.
 *
 * @return {?string}
 *   The continued canonical selector, or null when the grammar refuses it.
 */
export const under = (rule, suffix) => {
  const parsedRule = parseSelector(rule);
  const parsedSuffix = parseSelector(suffix);
  if (
    parsedRule === null ||
    parsedSuffix === null ||
    parsedRule.pseudo !== null
  ) {
    return null;
  }
  let { compounds } = parsedSuffix;
  let text = printSelector(parsedRule);
  if (compounds.length && compounds[0].kind === ANCHOR_KIND) {
    text += compounds[0].qualifiers.map(printQualifier).join('');
    compounds = compounds.slice(1);
  }
  if (compounds.length) {
    text += ` ${printCompounds(compounds)}`;
  }
  return canonicalSelector(
    `${text}${parsedSuffix.pseudo === null ? '' : `${SUFFIX}${parsedSuffix.pseudo}`}`,
  );
};

/**
 * The selector truncated after one of its compounds
 * (RelativeSelector::prefix).
 *
 * The chain up to and including compound `index` (0-based), without the
 * terminal pseudo-element: the targets a qualifier at that compound
 * qualifies. The pose reads it to stamp a state qualifier where it
 * stands (`li:hover > ul` at 0 is `li:hover`, whose structural reach is
 * the `li`s the stamp lands on; `&:hover > ul` at 0 is `&:hover`, the
 * owner).
 *
 * @param {string} selector
 *   A relative selector.
 * @param {number} index
 *   The compound's 0-based position in the chain.
 *
 * @return {?string}
 *   The canonical prefix, or null when the selector is outside the
 *   grammar or the chain has no such compound (the owner's own
 *   pseudo-element has none).
 */
export const prefix = (selector, index) => {
  const parsed = parseSelector(selector);
  if (
    parsed === null ||
    !Number.isInteger(index) ||
    index < 0 ||
    index >= parsed.compounds.length
  ) {
    return null;
  }
  return canonicalSelector(
    printCompounds(parsed.compounds.slice(0, index + 1)),
  );
};

/**
 * The root address a type compound names (RelativeSelector::typeAddress).
 *
 * `d-container` is `kind:container`, `d-block` `kind:block`,
 * `d-{bundle}` `block_type:{bundle}` ('-' spelled '_').
 *
 * @param {string} type
 *   A compound's name.
 *
 * @return {?string}
 *   The printed address, or null when the name is not a type compound.
 */
export const typeAddress = (type) => {
  if (!TYPE_PATTERN.test(type)) {
    return null;
  }
  if (type === TYPE_CONTAINER) {
    return 'kind:container';
  }
  if (type === TYPE_BLOCK) {
    return 'kind:block';
  }
  return `block_type:${type.slice(TYPE_PREFIX.length).replace(/-/g, '_')}`;
};

/**
 * The type compound spelling a root address (RelativeSelector::typeName).
 *
 * @param {*} address
 *   A printed root address.
 *
 * @return {?string}
 *   `d-{bundle}` for a block type, `d-container` for the container
 *   kind, `d-block` for the block kind; null for every other address.
 */
export const typeName = (address) => {
  if (typeof address !== 'string' || address.includes(SUFFIX)) {
    return null;
  }
  if (address.startsWith('block_type:')) {
    return `${TYPE_PREFIX}${address.slice('block_type:'.length).replace(/_/g, '-')}`;
  }
  if (address === 'kind:container') {
    return TYPE_CONTAINER;
  }
  if (address === 'kind:block') {
    return TYPE_BLOCK;
  }
  return null;
};

/**
 * The attribute selectors a root address selects, by the element
 * address stamp (CssPrinter::addressMembers twin).
 *
 * @param {*} address
 *   A printed root address.
 *
 * @return {string[]}
 *   The members, [] when the address selects no stamped root.
 */
export const addressMembers = (address) => {
  if (
    typeof address !== 'string' ||
    address.includes(SUFFIX) ||
    address.startsWith('base:')
  ) {
    return [];
  }
  const attribute = ELEMENT_ADDRESS_ATTRIBUTE;
  if (!address.startsWith('kind:')) {
    return [`[${attribute}="${address}"]`];
  }
  if (address === 'kind:block') {
    return [`[${attribute}^="block_type:"]`, `[${attribute}^="block_plugin:"]`];
  }
  if (address === 'kind:container') {
    return [`[${attribute}^="layout:"]`];
  }
  if (address === 'kind:entity' || address === 'kind:field') {
    return [`[${attribute}="${address}"]`];
  }
  return [];
};

/**
 * The selector a type compound matches (CssPrinter::typeSelector twin).
 *
 * @param {string} type
 *   A type compound's name (`d-text`).
 *
 * @return {string}
 *   The attribute selector, or an `:is()` of several.
 */
export const typeSelector = (type) => {
  const members = addressMembers(typeAddress(type));
  return members.length === 1 ? members[0] : `:is(${members.join(', ')})`;
};
