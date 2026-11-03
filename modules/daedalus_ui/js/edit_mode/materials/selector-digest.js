/**
 * @file
 * The selector digester: client twin of daedalus_blueprint's SelectorDigest
 * (PHP authoritative, locked by SelectorDigestTest's shared battery
 * tests/js/selector-digest-cases.mjs).
 *
 * The ONE reader of typed or pasted CSS selector text, owner-relative. The
 * relative selector grammar (relative-selector.js) parses what the browser
 * parses; the digester sits on top of it with the three moves every door
 * (the code view's typed line, the paste door, the editable prelude, the
 * dialect's parser behind its `#id` root) needs before a selector becomes
 * a key:
 *
 * - NESTING SUGAR. `&` is the owner: `&` alone is the owner itself (the
 *   empty selector OWNER), `& > ul` is `> ul`, `& p` is `p`, `&.open > ul`
 *   and `&:not(:last-child) > *` keep the qualified anchor; a bare relative
 *   prelude (`> ul`, `li a`) is already relative; a leading `:` is the
 *   owner's own pseudo-class (`:hover` is `&:hover`, `::before` is
 *   `&::before`), as the dialect reads a nested prelude.
 * - THE PEEL. A pseudo-class on the LAST compound (or on the bare anchor)
 *   that the editor can pose comes off into the condition compound as a
 *   CLAUSE of the condition grammar (condition-clause.js): a REFERENCE
 *   when its wrapper names a published posable condition (`a:hover` is
 *   `a` under `hover`, `&:hover` the owner under `hover`, `&:hover::before`
 *   is `&::before` under `hover`, `> d-text:hover:has(a)` is
 *   `> d-text:has(a)` under `hover`; by name, not by position in the
 *   text), a LITERAL when the roster does not publish it but a stamp can
 *   pose it (`input:checked` is `input` under `:checked` on a site without
 *   `checked`; admission is the validator's by mode). Every other
 *   pseudo-class stays in the selector as a qualifier: on a non-terminal
 *   compound (`li:hover > ul`, `&:hover > ul`), inside `:not()` or
 *   `:has()`, a position (`:first-child`), a published name the editor
 *   cannot pose, or a state no stamp stands in for (`a:visited`, `:target`:
 *   UNPOSABLE_STATES). The peeled clauses spell the compound as the
 *   condition grammar spells it (canonicalCompound), one per axis.
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
 *   it). The answer is the canonical spelling the grammar prints and
 *   reports the fold, so the code view's notice reads the digester
 *   rather than the text. Refused (SELECTOR_LIST): members peeling
 *   different conditions, an empty member, a list that shares no shape
 *   or differs at two compounds (separate rules), a member whose
 *   remainder is empty (`a, a.x`), and `:is()` holding a posable state
 *   on the LAST compound (`a:is(:hover, :focus-visible)`, typed or
 *   folded: a disjunctive condition). A single member after collapsing
 *   is no list (`a, a` is `a`, not folded).
 * - THE WHEN. An at-rule prelude (`@media …`, `@container …`, `@supports
 *   …`) is a condition with no selectors, read by the condition grammar: a
 *   literal equal to a published wrapper (case, spacing and colon spacing
 *   folded) is that REFERENCE (`@container (max-width: 767px)` is
 *   `narrow`), any other query the grammar reads is a LITERAL in its
 *   canonical spelling (`@media (hover: hover)`, `@container (min-width:
 *   500px)`, `@supports (display: grid)`), and text the grammar cannot
 *   read is refused.
 *
 * The refusals carry the dialect's codes and correctives, owner-relative:
 * BAD_SELECTOR for `html` / `body` / `:root`, another element's `#id`, a
 * top-level leading sibling combinator, `&` anywhere but first, a
 * pseudo-element behind a space, inside a qualifier, followed by anything
 * or of another name, a peel that leaves no address (`*:hover` leaves
 * `*`), two states on one axis, and anything else outside the grammar;
 * SELECTOR_LIST for a list that does not fold (above); UNKNOWN_WRAPPER
 * for an `@` prelude the condition grammar cannot read.
 *
 * reach() is the canvas paint's read: a canonical selector with its
 * posable pseudo-class qualifiers removed at every compound (`li:hover > ul`
 * is `li > ul`, `&:hover > ul` is `> ul`, `> d-text:has(a:hover)` is
 * `> d-text:has(a)`), because such a qualifier matches nothing in the DOM
 * until the state holds, while position, class and attribute qualifiers
 * stay (the browser evaluates them live). Inside `:not()` a posable state
 * stays too (`li:not(:hover)` holds for every unhovered item, which is the
 * reach). Inside `:is()` the states strip per member: a member left bare
 * drops and an `:is()` left empty drops (`.menu-item:is(:focus-within,
 * :hover) > ul` reaches `.menu-item > ul`; `.menu-item:is(.open, :hover)
 * > ul` reaches `.menu-item.open > ul`). A `:has()` whose chain would be
 * left with a bare universal by descent is dropped (`li:has(:hover)`
 * reaches `li`); null when nothing addressable is left.
 *
 * The answer is `{selector, condition, refusal, folded}`: the one
 * canonical selector (OWNER, the empty string, for the owner itself; null
 * for a wrapper alone or a refusal), the condition compound or null, the
 * refusal `{code, message, hint}` or null, and whether a list was folded
 * into `:is()`. The server class owns the rationale.
 */

import {
  ANCHOR,
  ANCHOR_KIND,
  CLASS_PART,
  CLASS_QUALIFIER,
  DESCENDANT,
  HAS,
  IS,
  NOT,
  POSITIONS,
  SUFFIX,
  TAG,
  UNIVERSAL,
  canonicalSelector,
  parseSelector,
  printCompound,
  printCompounds,
  printQualifier,
} from './relative-selector.js';
import {
  DELIMITER,
  LITERAL,
  UNKNOWN,
  canonicalCompound,
  clausePosable,
  parseClause,
  parseCompound,
} from './condition-clause.js';

/**
 * The refusal codes (the dialect's).
 */
export const BAD_SELECTOR = 'BAD_SELECTOR';
export const SELECTOR_LIST = 'SELECTOR_LIST';
export const UNKNOWN_WRAPPER = 'UNKNOWN_WRAPPER';

/**
 * The owner itself: the selector of the element's own key, no suffix.
 */
export const OWNER = '';

/**
 * The subjects outside every element's subtree.
 */
const OUTSIDE = ['html', 'body'];

const HINT_OWN =
  'Style the element itself (":hover" its state, "&.open" its class), its parts (".part"), its tags ("p"), its children ("> *") or its descendants ("> d-text .link"); the page\'s root, html and body are no element\'s subtree.';
const HINT_GRAMMAR =
  'Compounds join by " ", " > ", " + " or " ~ " ("> d-text .link", "> p + p"); a compound is "*", a tag, a type ("d-text") or a part (".part") with its qualifiers glued to it (".active", "[aria-expanded=\\"true\\"]", ":hover", ":first-child", ":nth-child(2n+1)", ":has(a)", ":not(.x)", ":is(h1, h2)"); the element itself is "&" ("&.open > ul"), its own state a leading pseudo-class (":hover"); a pseudo-element ends the selector ("::before", "::after", "::marker"). No bare "*" by descent, no leading "+" or "~", no ":has()" inside ":has()", one compound inside ":not()", no ":is()" inside ":not()" or ":is()".';
const HINT_LIST =
  'A selector list is one rule: members of one shape differing at one compound fold into ":is()" there ("h1, h2" is "*:is(h1, h2)", "> p, > div" is "> *:is(div, p)", "li:hover > ul, li:focus-within > ul" is "li:is(:focus-within, :hover) > ul"); rules of different shapes are separate rules.';
const HINT_LIST_CONDITION =
  'Each rule holds one condition ("a:hover { }" then "a:focus { }"); a list folds into one rule under the condition its members share ("a:hover, button:hover { }" is "*:is(a, button)" under hover).';
const HINT_LIST_STATE =
  'A rule holds one state on the element it addresses ("a:hover { }" then "a:focus-visible { }"); alternatives in ":is()" stand on the compounds above it ("li:is(:focus-within, :hover) > ul").';
const HINT_LIST_REDUNDANT =
  'Drop the member the others cover, or write the rule as that member alone ("a, a.x" styles every "a": write "a").';

/**
 * A refusal answer.
 */
const refuse = (code, message, hint) => ({
  selector: null,
  condition: null,
  refusal: { code, message, hint },
  folded: false,
});

/**
 * The text with every quoted string's interior blanked (same length, the
 * quotes kept), so the structural scans never read a `,`, `#`, `::` or
 * paren inside an attribute value. A backslash escapes the next character;
 * an unterminated string blanks to the end.
 */
const mask = (text) => {
  let masked = '';
  let quote = null;
  for (let i = 0; i < text.length; i += 1) {
    const char = text[i];
    if (quote === null) {
      masked += char;
      if (char === '"' || char === "'") {
        quote = char;
      }
    } else if (char === '\\') {
      masked += '__';
      i += 1;
    } else if (char === quote) {
      masked += char;
      quote = null;
    } else {
      masked += '_';
    }
  }
  return masked;
};

/**
 * The paren and bracket depth at an index of a masked text.
 */
const depthAt = (masked, index) => {
  let depth = 0;
  for (let i = 0; i < index; i += 1) {
    if (masked[i] === '(' || masked[i] === '[') {
      depth += 1;
    } else if (masked[i] === ')' || masked[i] === ']') {
      depth -= 1;
    }
  }
  return depth;
};

/**
 * Splits a text at its top-level commas (outside parens, brackets and
 * strings), each member trimmed.
 */
const splitList = (text) => {
  const masked = mask(text);
  const members = [];
  let start = 0;
  for (let i = 0; i < masked.length; i += 1) {
    if (masked[i] === ',' && depthAt(masked, i) === 0) {
      members.push(text.slice(start, i).trim());
      start = i + 1;
    }
  }
  members.push(text.slice(start).trim());
  return members;
};

/**
 * The roster the options describe: the condition table itself, the
 * posable pseudo-class wrappers by lowercase text, every published
 * pseudo-class wrapper, and the published at-rule wrappers for the
 * corrective.
 *
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   `conditions` is the published table, name => {wrapper, axis};
 *   `posable` the published names the editor can pose (every pseudo-class
 *   condition when omitted).
 */
const rosterOf = ({ conditions, posable } = {}) => {
  const table = conditions ?? {};
  const states = new Map();
  const publishedStates = new Set();
  const published = [];
  Object.entries(table).forEach(([name, definition]) => {
    const wrapper = typeof definition?.wrapper === 'string' ? definition.wrapper : '';
    if (wrapper.startsWith('@')) {
      published.push(`${wrapper}  (${name})`);
    } else if (wrapper.startsWith(':')) {
      publishedStates.add(wrapper.toLowerCase());
      if ((posable == null || posable.includes(name)) && !states.has(wrapper.toLowerCase())) {
        states.set(wrapper.toLowerCase(), name);
      }
    }
  });
  return { table, states, publishedStates, published };
};

/**
 * The published posable condition a pseudo-class qualifier spells, or null.
 */
const posableOf = (qualifier, roster) =>
  qualifier.argument === null ? (roster.states.get(`:${qualifier.name}`) ?? null) : null;

/**
 * The first posable state an `:is()` member of a compound carries, or null
 * (SelectorDigest::stateInsideIs): on the last compound such a state is a
 * disjunctive condition, which the condition model does not hold.
 */
const stateInsideIs = (compound, roster) => {
  for (const qualifier of compound.qualifiers) {
    if (qualifier.name !== IS) {
      continue;
    }
    for (const member of qualifier.argument) {
      const inner = member.qualifiers.find((candidate) => posableOf(candidate, roster) !== null);
      if (inner !== undefined) {
        return inner.name;
      }
    }
  }
  return null;
};

/**
 * The condition clause a last-compound pseudo-class peels into, or null
 * (SelectorDigest::clauseOf): a published posable condition's name, a
 * pseudo-class literal (`:checked`) when unpublished and a stamp can pose
 * it, null for a published name the editor cannot pose, a position, a
 * qualifier with an argument, or a state no stamp stands in for.
 */
const clauseOf = (qualifier, roster) => {
  if (qualifier.argument !== null) {
    return null;
  }
  const name = posableOf(qualifier, roster);
  if (name !== null) {
    return name;
  }
  const wrapper = `:${qualifier.name}`;
  if (roster.publishedStates.has(wrapper) || POSITIONS.includes(qualifier.name)) {
    return null;
  }
  const clause = parseClause(wrapper, roster.table);
  return clause.kind === LITERAL && clausePosable(clause) ? clause.text : null;
};

/**
 * The first subject or pseudo-class outside every element's subtree in a
 * chain (`html`, `body`, `:root`), or null.
 */
const outsideOf = (compounds) => {
  for (const compound of compounds) {
    if (compound.kind === TAG && OUTSIDE.includes(compound.name)) {
      return compound.name;
    }
    for (const qualifier of compound.qualifiers) {
      if (qualifier.name === HAS) {
        const inner = outsideOf(qualifier.argument);
        if (inner !== null) {
          return inner;
        }
      } else if (qualifier.name === NOT) {
        const inner = outsideOf([qualifier.argument]);
        if (inner !== null) {
          return inner;
        }
      } else if (qualifier.name === IS) {
        const inner = outsideOf(qualifier.argument);
        if (inner !== null) {
          return inner;
        }
      } else if (qualifier.name === 'root' && qualifier.argument === null) {
        return ':root';
      }
    }
  }
  return null;
};

/**
 * The canonical text of compounds and a pseudo, the owner's own forms
 * included: no compounds is OWNER, or `&::before`.
 */
const spell = (compounds, pseudo) =>
  `${compounds.length ? printCompounds(compounds) : pseudo === null ? OWNER : ANCHOR}${pseudo === null ? '' : `${SUFFIX}${pseudo}`}`;

/**
 * Digests one list member: `{selector, states}` or a refusal answer.
 */
const digestOne = (member, roster) => {
  const text = member.trim();
  const masked = mask(text);
  const id = /#([^\s>+~.:\[,]*)/.exec(masked);
  if (id !== null) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": "#${id[1]}" addresses another element; a rule styles inside this element's own subtree`,
      'Style the other element under its own rules, or reach it from here by relation ("> d-text", "> * + *").',
    );
  }
  if (/\s::/.test(masked)) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": a pseudo-element is glued to the compound whose box it is ("&::after" the element's own box, "> d-text::before" each text block's); "::" behind a space would address every descendant's box`,
      'Glue the pseudo-element to the compound whose box it is ("&::after", "li::marker").',
    );
  }
  if (masked.indexOf(ANCHOR, 1) !== -1) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": "&" is the element itself and stands first ("&:hover > ul", "&.open .menu")`,
      'A rule styles inside this element\'s own subtree, so the element starts every selector; "p &" would style it inside a paragraph, which is its ancestors\' business.',
    );
  }
  // The nesting sugar.
  let sugared = text;
  if (text === '' || text === ANCHOR) {
    return { selector: OWNER, clauses: [] };
  }
  if (text[0] === ANCHOR) {
    if (/\s|[>+~]/.test(text[1])) {
      sugared = text.slice(1).trim();
    }
  } else if (text[0] === ':') {
    sugared = `${ANCHOR}${text}`;
  }
  if (/^[+~]/.test(sugared)) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": a rule styles inside this element's own subtree, and its siblings are outside it; address a sibling under its own rules, or from the parent as a relation among its children ("> d-text + d-text")`,
      'Between compounds "+" and "~" are grammar ("> p + p", "> d-text:has(+ d-image)"); leading, they would reach outside the element.',
    );
  }
  const sugaredMasked = mask(sugared);
  // A functional pseudo-element (`::part()`, `::slotted()`,
  // `::highlight()`, `::cue()`) reaches shadow trees and highlights no
  // element here exposes; the terminal slot takes bare names, any the
  // browser spells (whether this site knows one is the validator's).
  const functionalPseudo = /::([A-Za-z][A-Za-z0-9-]*)\(/.exec(sugaredMasked);
  if (functionalPseudo !== null) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": ::${functionalPseudo[1].toLowerCase()}() takes an argument and addresses nothing here; a pseudo-element is a bare name glued to its compound ("::before", "li::marker", "p::first-letter")`,
      'Style a pseudo-element by its bare name, last and glued to the compound whose box it is; ::part(), ::slotted(), ::highlight() and ::cue() reach shadow trees and highlights this element does not expose.',
    );
  }
  const boxes = [...sugaredMasked.matchAll(/::?(?:before|after|first-letter|first-line)(?![A-Za-z0-9_-])|::[A-Za-z][A-Za-z0-9-]*/gi)];
  if (boxes.length) {
    if (depthAt(sugaredMasked, boxes[0].index) > 0) {
      return refuse(
        BAD_SELECTOR,
        `"${text}": a pseudo-element never stands inside a qualifier (a generated box is no tree fact to narrow by); it is terminal, glued to the last compound`,
        'Style the box of what the qualifier narrows ("> d-text:has(a)::before"), or the box as its own rule ("a::before").',
      );
    }
    if (boxes.length > 1 || boxes[0].index + boxes[0][0].length !== sugaredMasked.length) {
      return refuse(
        BAD_SELECTOR,
        `"${text}": a pseudo-element is terminal and one per selector; the qualifiers and states go before it ("&:hover::after", "> d-text:first-child::before") and nothing follows it`,
        'Put the pseudo-element last, glued to the last compound ("&::after", "li::marker").',
      );
    }
  }
  const sigil = /^&([A-Za-z][A-Za-z0-9-]*)$/.exec(sugared);
  if (sigil !== null) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": a state is written as a pseudo-class (":${sigil[1]}", "&:${sigil[1]}")`,
      `"&" is the element itself and takes CSS qualifiers (":hover", ".open", "[aria-expanded=\\"true\\"]"); "&${sigil[1]}" is the chrome's condition shorthand, not a selector.`,
    );
  }
  const parsed = parseSelector(sugared);
  if (parsed === null) {
    return refuse(BAD_SELECTOR, `"${text}" is not a relative selector the grammar accepts`, HINT_GRAMMAR);
  }
  const outside = outsideOf(parsed.compounds);
  if (outside !== null) {
    return refuse(BAD_SELECTOR, `"${text}": "${outside}" is not addressable; a rule styles inside this element's own subtree`, HINT_OWN);
  }
  // The peel, by name, off the last compound (the bare anchor included).
  const compounds = parsed.compounds.map((compound) => ({ ...compound, qualifiers: [...compound.qualifiers] }));
  const clauses = [];
  if (compounds.length) {
    const last = compounds[compounds.length - 1];
    const state = stateInsideIs(last, roster);
    if (state !== null) {
      return refuse(
        SELECTOR_LIST,
        `"${text}": two states on the addressed element (":is()" holds ":${state}" among its alternatives); write two rules, one per state`,
        HINT_LIST_STATE,
      );
    }
    last.qualifiers = last.qualifiers.filter((qualifier) => {
      const clause = clauseOf(qualifier, roster);
      if (clause !== null) {
        if (!clauses.includes(clause)) {
          clauses.push(clause);
        }
        return false;
      }
      return true;
    });
    if (!last.qualifiers.length && last.kind === ANCHOR_KIND) {
      compounds.pop();
    }
  }
  const selector = spell(compounds, parsed.pseudo);
  if (selector !== OWNER && canonicalSelector(selector) !== selector) {
    return refuse(
      BAD_SELECTOR,
      `"${text}": the state peels into the rule's condition and leaves "${selector}", which is not an address`,
      'Name what is in the state ("a:hover", "> *:hover" the children), or the element\'s own state (":hover").',
    );
  }
  const seen = new Map();
  const records = clauses.length ? parseCompound(clauses.join(DELIMITER), roster.table) : [];
  for (const record of records) {
    if (seen.has(record.axis)) {
      return refuse(
        BAD_SELECTOR,
        `"${text}": one condition per axis; "${seen.get(record.axis)}" and "${record.css}" are both on the "${record.axis}" axis`,
        'A rule holds one state; the second is a second rule.',
      );
    }
    seen.set(record.axis, record.css);
  }
  return { selector, clauses };
};

/**
 * A compound as the factoring reads it (SelectorDigest::bag): a subject
 * and a qualifier bag. A class subject goes to the bag as a class
 * qualifier and a `*` subject is none, so what two compounds share is
 * read over the whole bag; the grammar settles the subject again when the
 * fold is parsed.
 *
 * @return {{subject: ?{kind: string, name: string}, qualifiers: Array}}
 */
const bag = (compound) => {
  const qualifiers = [...compound.qualifiers];
  let subject = null;
  if (compound.kind === CLASS_PART) {
    qualifiers.push({ name: CLASS_QUALIFIER, argument: compound.name.slice(1) });
  } else if (compound.kind !== null && compound.kind !== UNIVERSAL) {
    subject = { kind: compound.kind, name: compound.name };
  }
  return { subject, qualifiers };
};

/**
 * The refusal for a list that shares no shape or differs at two places.
 */
const noShape = (whole) =>
  refuse(SELECTOR_LIST, `"${whole}": these rules share no shape; write them as separate rules`, HINT_LIST);

/**
 * The refusal for a member the others already cover.
 */
const redundant = (whole, selector) =>
  refuse(
    SELECTOR_LIST,
    `"${whole}": "${selector}" already covers the others; a list member adds an alternative, and one the rest already cover is a slip`,
    HINT_LIST_REDUNDANT,
  );

/**
 * Folds two or more distinct canonical selectors into one `:is()` rule
 * (SelectorDigest::fold). The factoring: the members share a shape (the
 * same number of compounds, the same combinators, the same pseudo-element
 * or none, the anchor at the same place) and differ at exactly one
 * compound; the host compound there keeps the subject when all agree
 * (else `*`) and the qualifiers all carry, and each member's remainder is
 * an `:is()` member; a remainder that is `:is()` alone flattens into the
 * fold.
 *
 * @return {string|Object}
 *   The canonical folded selector, or a refusal answer (SELECTOR_LIST: no
 *   shared shape, a redundant member, an `:is()` the fold would nest).
 */
const fold = (selectors, whole) => {
  const parsed = selectors.map((selector) => {
    const record = selector === OWNER ? null : parseSelector(selector);
    return { compounds: record?.compounds ?? [], pseudo: record?.pseudo ?? null };
  });
  const [first] = parsed;
  const differing = new Set();
  for (const member of parsed) {
    if (member.compounds.length !== first.compounds.length || member.pseudo !== first.pseudo) {
      return noShape(whole);
    }
    for (const [i, compound] of member.compounds.entries()) {
      const model = first.compounds[i];
      if (compound.combinator !== model.combinator || (compound.kind === ANCHOR_KIND) !== (model.kind === ANCHOR_KIND)) {
        return noShape(whole);
      }
      if (printCompound(compound) !== printCompound(model)) {
        differing.add(i);
      }
    }
  }
  if (differing.size !== 1) {
    return noShape(whole);
  }
  const [at] = differing;
  const bags = parsed.map((member) => bag(member.compounds[at]));
  let subject = bags[0].subject;
  let shared = bags[0].qualifiers;
  for (const candidate of bags) {
    if (candidate.subject?.kind !== subject?.kind || candidate.subject?.name !== subject?.name) {
      subject = null;
    }
    const prints = candidate.qualifiers.map(printQualifier);
    shared = shared.filter((qualifier) => prints.includes(printQualifier(qualifier)));
  }
  const sharedPrints = shared.map(printQualifier);
  const members = [];
  const seen = new Set();
  for (const [i, candidate] of bags.entries()) {
    const own = subject === null ? candidate.subject : null;
    const qualifiers = candidate.qualifiers.filter((qualifier) => !sharedPrints.includes(printQualifier(qualifier)));
    let remainders;
    if (own === null && qualifiers.length === 1 && qualifiers[0].name === IS) {
      remainders = qualifiers[0].argument;
    } else if (qualifiers.some((qualifier) => qualifier.name === IS)) {
      return refuse(
        SELECTOR_LIST,
        `"${whole}": "${selectors[i]}" already holds alternatives where the fold would put the list's, and ":is()" never nests; write them as separate rules`,
        HINT_LIST,
      );
    } else if (own === null && !qualifiers.length) {
      return redundant(whole, selectors[i]);
    } else {
      remainders = [{ combinator: null, kind: own?.kind ?? null, name: own?.name ?? null, qualifiers }];
    }
    for (const remainder of remainders) {
      const print = printCompound(remainder);
      if (seen.has(print)) {
        return redundant(whole, selectors[i]);
      }
      seen.add(print);
      members.push(remainder);
    }
  }
  const compounds = [...first.compounds];
  compounds[at] = {
    combinator: compounds[at].combinator,
    kind: subject?.kind ?? UNIVERSAL,
    name: subject?.name ?? '*',
    qualifiers: [...shared, { name: IS, argument: members }],
  };
  const spelled = spell(compounds, first.pseudo);
  const canonical = canonicalSelector(spelled);
  if (canonical === null) {
    throw new Error(`The fold of "${whole}" spelled "${spelled}", which the grammar refuses.`);
  }
  return canonical;
};

/**
 * Digests selector or at-rule prelude text relative to an owner.
 *
 * @param {*} text
 *   The typed or pasted prelude: a relative selector, nesting sugar, a
 *   selector list, or an `@` prelude.
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   The published condition table (name => {wrapper, axis}) and the names
 *   the editor can pose (every pseudo-class condition when omitted).
 *
 * @return {{selector: ?string, condition: ?string, refusal: ?{code: string, message: string, hint: string}, folded: boolean}}
 *   The one canonical selector (OWNER for the owner itself; null for a
 *   wrapper alone or a refusal), the condition compound (null when none
 *   peeled), the refusal (null when the text digested), and whether a
 *   list was folded into `:is()`.
 */
export const digest = (text, options = {}) => {
  const roster = rosterOf(options);
  const whole = typeof text === 'string' ? text.trim() : '';
  if (whole.startsWith('@')) {
    const clause = parseClause(whole, roster.table);
    if (clause.kind !== UNKNOWN) {
      return { selector: null, condition: clause.text, refusal: null, folded: false };
    }
    return refuse(
      UNKNOWN_WRAPPER,
      `"${whole}" is not a condition: a @media or @container prelude tests features in parentheses ("(hover: hover)"), all on one axis, and @supports tests a declaration`,
      `A condition is a published wrapper (${roster.published.length ? roster.published.join('; ') : 'none published'}) or a literal the browser knows: "@media (hover: hover)", "@container (min-width: 500px)", "@supports (display: grid)"; a second axis nests as its own block.`,
    );
  }
  const members = splitList(whole);
  if (members.length > 1 && members.includes('')) {
    return refuse(SELECTOR_LIST, `"${whole}": an empty selector in the list`, 'Remove the stray comma.');
  }
  const selectors = [];
  let condition;
  let firstMember = null;
  for (const member of members) {
    const answer = digestOne(member, roster);
    if (answer.refusal) {
      return answer;
    }
    const peeled = answer.clauses.length ? canonicalCompound(answer.clauses.join(DELIMITER), roster.table) : null;
    if (condition === undefined) {
      condition = peeled;
      firstMember = member;
    } else if (peeled !== condition) {
      return refuse(
        SELECTOR_LIST,
        `"${whole}": a selector list shares one condition; "${firstMember}" and "${member}" peel different ones (${condition ?? 'none'}, ${peeled ?? 'none'})`,
        HINT_LIST_CONDITION,
      );
    }
    if (!selectors.includes(answer.selector)) {
      selectors.push(answer.selector);
    }
  }
  if (selectors.length === 1) {
    return { selector: selectors[0], condition: condition ?? null, refusal: null, folded: false };
  }
  const folded = fold(selectors, whole);
  if (typeof folded !== 'string') {
    return folded;
  }
  return { selector: folded, condition: condition ?? null, refusal: null, folded: true };
};

/**
 * Strips the posable pseudo-class qualifiers from compounds, recursively:
 * at every compound, inside `:has()` (a `:has()` left with a bare
 * universal by descent is dropped) and inside `:is()` per member (a
 * member left bare is dropped, an `:is()` left empty is dropped; one
 * member left merges into its host on the re-parse), never inside
 * `:not()`.
 */
const stripCompounds = (compounds, roster) =>
  compounds.map((compound) => ({
    ...compound,
    qualifiers: compound.qualifiers.flatMap((qualifier) => {
      if (posableOf(qualifier, roster) !== null) {
        return [];
      }
      if (qualifier.name === IS) {
        const members = stripCompounds(qualifier.argument, roster).filter(
          (member) => member.kind !== null || member.qualifiers.length,
        );
        return members.length ? [{ ...qualifier, argument: members }] : [];
      }
      if (qualifier.name !== HAS) {
        return [qualifier];
      }
      const argument = stripCompounds(qualifier.argument, roster);
      const degenerate = argument.some(
        (inner) => inner.combinator === DESCENDANT && inner.kind === UNIVERSAL && !inner.qualifiers.length,
      );
      return degenerate ? [] : [{ ...qualifier, argument }];
    }),
  }));

/**
 * The structural reach of a canonical selector: the selector with its
 * posable pseudo-class qualifiers removed, for the canvas paint.
 *
 * @param {*} selector
 *   A canonical relative selector (OWNER for the owner itself).
 * @param {{conditions: ?Object, posable: ?string[]}} options
 *   As digest()'s.
 *
 * @return {?string}
 *   The canonical reach, or null when the selector is outside the grammar
 *   or nothing addressable is left.
 */
export const reach = (selector, options = {}) => {
  if (selector === OWNER) {
    return OWNER;
  }
  const parsed = parseSelector(selector);
  if (parsed === null) {
    return null;
  }
  const roster = rosterOf(options);
  const compounds = stripCompounds(parsed.compounds, roster);
  if (compounds.length && compounds[0].kind === ANCHOR_KIND && !compounds[0].qualifiers.length) {
    compounds.shift();
  }
  // A universal or part compound whose classes were its subject keeps
  // its subject; a universal left bare by descent is no address.
  const spelled = spell(compounds, parsed.pseudo);
  if (spelled === OWNER) {
    return OWNER;
  }
  return canonicalSelector(spelled);
};
