/**
 * @file
 * The relative selector grammar: client twin of daedalus_blueprint's
 * RelativeSelector (PHP authoritative, locked by RelativeSelectorTest's
 * shared battery through tests/js/relative-selector-cases.mjs).
 *
 * The suffix after a style key's "::" (a style map key, a variant's or
 * skin's relative key, an address) is a curated subset of CSS nesting's
 * relative selectors, relative to the element the root names, in CSS's
 * own recursive shape:
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
 * Structural rules: `*` only after `>`, `+` or `~`; a part class first,
 * after a type by descent or `>`, or after a class by a sibling
 * combinator; a leading `+` or `~` refused at the top level and admitted
 * inside `:has()`; no `:has()` anywhere inside a `:has()` argument;
 * `:not()` takes ONE compound whose subject may be implied
 * (`:not(:has(a))`) and never `*` or `:not()`; a pseudo-element is
 * terminal (`::before` or `::after` glued to the last compound, nothing
 * after it, never inside `:has()` or `:not()`, no other pseudo-element),
 * the owner's own is `&::before` / `&::after` and `&` stands nowhere
 * else; no attributes, commas or other pseudo-classes (states and
 * position facts are conditions). The terminal pseudo is the only `::` a
 * selector carries and something always precedes it, so the FIRST "::"
 * of any key still splits root from selector. One canonical spelling
 * (combinators spaced, a lead glued to its space, qualifiers glued to
 * their subject and sorted, `:has` before `:not` then by printed
 * argument, the pseudo glued to its host, no trailing space);
 * parseSelector reads leniently on whitespace only (a space before `::`
 * is refused: CSS reads `p ::before` as every descendant's box),
 * printSelector prints canonical, and the key gates admit the canonical
 * spelling only. The record is `{compounds, pseudo}` (the server class's
 * two fields): the pseudo rides the selector, never a compound. The
 * server class owns the rationale of every rule.
 */

export const SUFFIX = '::';

export const UNIVERSAL = 'universal';
export const TAG = 'tag';
export const TYPE = 'type';
export const CLASS_PART = 'class';

/**
 * The combinators: what reaches a compound from the one before it. The
 * first compound's combinator is its lead (`descendant` when none is
 * written).
 */
export const DESCENDANT = 'descendant';
export const CHILD = 'child';
export const NEXT = 'next';
export const SUBSEQUENT = 'subsequent';

/**
 * The qualifier names.
 */
export const HAS = 'has';
export const NOT = 'not';

const COMBINATOR_SYMBOLS = { [CHILD]: '>', [NEXT]: '+', [SUBSEQUENT]: '~' };
const COMBINATORS_BY_SYMBOL = { '>': CHILD, '+': NEXT, '~': SUBSEQUENT };

export const TYPE_PREFIX = 'pb-';
export const TYPE_CONTAINER = 'pb-container';
export const TYPE_BLOCK = 'pb-block';

export const TAG_PATTERN = /^[a-z][a-z0-9]*$/;
export const TYPE_PATTERN = /^pb-[a-z][a-z0-9-]*$/;
export const CLASS_PATTERN = /^\.[A-Za-z0-9_-]+$/;

/**
 * The pseudo-elements a selector may end in, by name (no `::`).
 */
export const PSEUDO_BEFORE = 'before';
export const PSEUDO_AFTER = 'after';
export const PSEUDOS = [PSEUDO_BEFORE, PSEUDO_AFTER];

/**
 * The owner's own anchor: the one subject of its own pseudo-element.
 */
export const ANCHOR = '&';

const PSEUDO_AT_END = /::(before|after)\s*$/;

/**
 * The DOM attribute carrying an element's address (daedalus_ui's
 * ElementAddress::ATTRIBUTE twin): what a type compound selects by.
 */
export const ELEMENT_ADDRESS_ATTRIBUTE = 'data-element';

const CLASS_AT = /\.[A-Za-z0-9_-]+/y;
const WORD_AT = /[a-z][a-z0-9-]*/y;

/**
 * The canonical spelling of one qualifier.
 */
const printQualifier = (qualifier) =>
  qualifier.name === HAS
    ? `:has(${printCompounds(qualifier.argument)})`
    : `:not(${printCompound(qualifier.argument)})`;

/**
 * The canonical spelling of one compound without its combinator.
 */
export const printCompound = (compound) =>
  `${compound.name ?? ''}${compound.qualifiers.map(printQualifier).join('')}`;

/**
 * The canonical spelling of a chain of compounds: each behind its
 * combinator (a lead glued to its space, a descendant combinator the one
 * space between compounds), the subject glued to its qualifiers
 * (RelativeSelector::printCompounds).
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
 */
export const printSelector = ({ compounds, pseudo }) =>
  `${compounds.length ? printCompounds(compounds) : ANCHOR}${pseudo ? `${SUFFIX}${pseudo}` : ''}`;

/**
 * A read position over the text, advanced by the parse functions.
 */
const skipSpace = (text, cursor) => {
  while (cursor.at < text.length && /\s/.test(text[cursor.at])) {
    cursor.at += 1;
  }
};

/**
 * Parses one compound (a subject and its qualifiers) at the cursor.
 *
 * `combinator` is what reaches it (null for a `:not()` argument),
 * `previous` the compound before it in the chain, `inHas` whether it
 * stands anywhere inside a `:has()` argument, `inNot` whether it is a
 * `:not()` argument (the subject may be implied, `*` and `:not()` are
 * refused). Null outside the grammar.
 */
function parseCompound(text, cursor, combinator, previous, inHas, inNot) {
  let kind = null;
  let name = null;
  const char = text[cursor.at] ?? '';
  if (char === '*') {
    // `*` is a relation's subject only: every child, every later
    // sibling; never every descendant, never a `:not()` predicate.
    if (inNot || combinator === DESCENDANT) {
      return null;
    }
    kind = UNIVERSAL;
    name = '*';
    cursor.at += 1;
  } else if (char === '.') {
    CLASS_AT.lastIndex = cursor.at;
    const match = CLASS_AT.exec(text);
    if (match === null) {
      return null;
    }
    // A class stands first, after a type by descent or `>`, or after a
    // class by a sibling combinator; a `:not()` argument is a predicate
    // on its compound, not a chain step.
    const admitted =
      inNot ||
      previous === null ||
      (previous.kind === TYPE && [DESCENDANT, CHILD].includes(combinator)) ||
      (previous.kind === CLASS_PART && [NEXT, SUBSEQUENT].includes(combinator));
    if (!admitted) {
      return null;
    }
    kind = CLASS_PART;
    name = match[0];
    cursor.at += match[0].length;
  } else {
    WORD_AT.lastIndex = cursor.at;
    const match = WORD_AT.exec(text);
    if (match !== null) {
      if (TYPE_PATTERN.test(match[0])) {
        kind = TYPE;
      } else if (TAG_PATTERN.test(match[0])) {
        kind = TAG;
      } else {
        return null;
      }
      name = match[0];
      cursor.at += match[0].length;
    } else if (!(inNot && char === ':')) {
      return null;
    }
  }
  const qualifiers = [];
  while (text[cursor.at] === ':') {
    if (text.startsWith(':has(', cursor.at)) {
      if (inHas) {
        return null;
      }
      cursor.at += 5;
      const argument = parseChain(text, cursor, true, inNot);
      if (argument === null) {
        return null;
      }
      qualifiers.push({ name: HAS, argument });
    } else if (text.startsWith(':not(', cursor.at)) {
      if (inNot) {
        return null;
      }
      cursor.at += 5;
      skipSpace(text, cursor);
      const argument = parseCompound(text, cursor, null, null, inHas, true);
      if (argument === null) {
        return null;
      }
      qualifiers.push({ name: NOT, argument });
    } else {
      return null;
    }
    skipSpace(text, cursor);
    if (text[cursor.at] !== ')') {
      return null;
    }
    cursor.at += 1;
  }
  if (kind === null && !qualifiers.length) {
    return null;
  }
  qualifiers.sort((a, b) => {
    const [pa, pb] = [printQualifier(a), printQualifier(b)];
    return pa < pb ? -1 : pa > pb ? 1 : 0;
  });
  return { combinator, kind, name, qualifiers };
}

/**
 * Parses a chain (a lead, then compounds behind combinators) at the
 * cursor, leaving trailing whitespace unread.
 *
 * `inHas` admits sibling leads and refuses `:has()` anywhere inside;
 * `inNot` refuses `:not()` on every compound. Null outside the grammar.
 */
function parseChain(text, cursor, inHas, inNot) {
  skipSpace(text, cursor);
  let combinator = DESCENDANT;
  let symbol = text[cursor.at] ?? '';
  if (symbol in COMBINATORS_BY_SYMBOL) {
    combinator = COMBINATORS_BY_SYMBOL[symbol];
    if (!inHas && combinator !== CHILD) {
      return null;
    }
    cursor.at += 1;
    skipSpace(text, cursor);
  }
  const compounds = [];
  for (;;) {
    const previous = compounds.length ? compounds[compounds.length - 1] : null;
    const compound = parseCompound(text, cursor, combinator, previous, inHas, inNot);
    if (compound === null) {
      return null;
    }
    compounds.push(compound);
    const before = cursor.at;
    skipSpace(text, cursor);
    symbol = text[cursor.at] ?? '';
    if (symbol === '' || symbol === ')') {
      return compounds;
    }
    if (symbol in COMBINATORS_BY_SYMBOL) {
      combinator = COMBINATORS_BY_SYMBOL[symbol];
      cursor.at += 1;
      skipSpace(text, cursor);
    } else if (cursor.at === before) {
      // Anything else glued to the compound (`p.cls`, `p[x]`, `p:hover`)
      // is outside the grammar; behind whitespace it is the next
      // compound by descent.
      return null;
    } else {
      combinator = DESCENDANT;
    }
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
 *   argument: compounds}` (a chain of the same compounds) or `{name:
 *   'not', argument: compound}` (one compound with `combinator` null,
 *   its kind and name null when the subject is implied); and the
 *   terminal pseudo by name ('before', 'after') or null. The owner's own
 *   pseudo (`&::before`) has no compounds.
 */
export const parseSelector = (selector) => {
  if (typeof selector !== 'string') {
    return null;
  }
  let pseudo = null;
  let head = selector;
  const match = PSEUDO_AT_END.exec(selector);
  if (match !== null) {
    pseudo = match[1];
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
  const compounds = parseChain(head, cursor, false, false);
  if (compounds === null) {
    return null;
  }
  skipSpace(head, cursor);
  return cursor.at === head.length ? { compounds, pseudo } : null;
};

/**
 * The canonical spelling of selector text, or null outside the grammar.
 */
export const canonicalSelector = (selector) => {
  const parsed = parseSelector(selector);
  return parsed === null ? null : printSelector(parsed);
};

/**
 * Whether a string is a selector in its one canonical spelling.
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
 * (the first subject, qualified or not, a pseudo behind it or not). The
 * owner's own pseudo ('&::before') has no first subject.
 */
export const isPartFirst = (selector) =>
  parseSelector(selector)?.compounds[0]?.kind === CLASS_PART;

/**
 * Whether a selector is exactly one part class ('.cls', '> .cls'); a
 * qualified one ('.cls:has(a)') or a pseudo behind it ('.cls::after') is
 * a rule of its own.
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
 * a qualified one ('> *:not(pb-image)') or a pseudo behind it
 * ('> *::before') is a rule of its own.
 */
export const isChildren = (selector) => isOne(selector, CHILD, UNIVERSAL);

/**
 * Whether a selector is exactly one tag ('p'): the tag tier; a
 * qualified one ('p:has(a)') or a pseudo behind it ('p::before') is a
 * rule of its own.
 */
export const isTag = (selector) => isOne(selector, DESCENDANT, TAG);

/**
 * Whether a selector crosses into children: a relation (`>`, `+`, `~`)
 * or a type at the top level. A qualifier's argument reaches nothing of
 * its own, and a pseudo hangs on what the chain reaches.
 */
export const reachesChildren = (selector) =>
  (parseSelector(selector)?.compounds ?? []).some(
    (compound) => compound.combinator !== DESCENDANT || compound.kind === TYPE,
  );

/**
 * The class subjects of compounds and their qualifiers, in print order.
 */
const classesOf = (compounds) =>
  compounds.flatMap((compound) => [
    ...(compound.kind === CLASS_PART ? [compound.name] : []),
    ...compound.qualifiers.flatMap((qualifier) =>
      classesOf(qualifier.name === HAS ? qualifier.argument : [qualifier.argument]),
    ),
  ]);

/**
 * The class names a selector carries, in print order ('.cls' spelled),
 * the qualifiers' arguments included.
 */
export const selectorClasses = (selector) =>
  classesOf(parseSelector(selector)?.compounds ?? []);

/**
 * Continues a rule's selector by a suffix, by descent
 * (RelativeSelector::under).
 *
 * The one rebase "this rule wears that" shares: a variant's suffix
 * continued under the rule's targets reads `{rule} {suffix}` (`> pb-text`
 * under `.link` is `> pb-text .link`), and the grammar decides
 * admissibility exactly as for an authored key, so a suffix the rule
 * cannot carry answers null. A pseudo binds to the rule's landing:
 * `&::before` under `> pb-text` is `> pb-text::before`, `.link::before`
 * under it `> pb-text .link::before`; a rule already ending in a pseudo
 * admits no continuation.
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
  if (parsedRule === null || parsedSuffix === null || parsedRule.pseudo !== null) {
    return null;
  }
  if (!parsedSuffix.compounds.length) {
    return printSelector({ compounds: parsedRule.compounds, pseudo: parsedSuffix.pseudo });
  }
  return canonicalSelector(`${rule} ${suffix}`);
};

/**
 * The root address a type compound names (RelativeSelector::typeAddress).
 *
 * `pb-container` is `kind:container`, `pb-block` `kind:block`,
 * `pb-{bundle}` `block_type:{bundle}` ('-' spelled '_').
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
 * @return {?string}
 *   `pb-{bundle}` for a block type, `pb-container` for the container
 *   kind, `pb-block` for the block kind; null for every other address.
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
 * @return {string[]}
 *   The members, [] when the address selects no stamped root.
 */
export const addressMembers = (address) => {
  if (typeof address !== 'string' || address.includes(SUFFIX) || address.startsWith('base:')) {
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
 */
export const typeSelector = (type) => {
  const members = addressMembers(typeAddress(type));
  return members.length === 1 ? members[0] : `:is(${members.join(', ')})`;
};
