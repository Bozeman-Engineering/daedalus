/**
 * The shared selector digest battery: one record both twins must answer.
 *
 * Each digest case is a prelude `text`, the roster `table` it is read
 * against (TABLES; `default` when omitted) and the one `answer` both
 * sides must give (`{selector, condition, refusal, folded}`,
 * SelectorDigest::digest() and digest()'s return); each reach case a canonical `selector`, its
 * table and the `answer` (the canonical reach, or null). The node suite
 * tests/js/selector-digest.test.mjs runs the client twin over every case;
 * SelectorDigestTest (daedalus_blueprint's PHP unit test) runs this file
 * to read the cases (stdout: {tables, digests, reaches}) and runs the PHP
 * class over every one. Both compare to the same recorded answer, so the
 * two can never drift and a case neither side agrees with fails on both.
 * Importing this file reads nothing: the print runs only as a script.
 */
import { fileURLToPath } from 'node:url';

const STATES = {
  hover: { wrapper: ':hover', axis: 'state' },
  focus: { wrapper: ':focus', axis: 'state' },
  'focus-within': { wrapper: ':focus-within', axis: 'state' },
  'focus-visible': { wrapper: ':focus-visible', axis: 'state' },
  active: { wrapper: ':active', axis: 'state' },
};
const WRAPPERS = {
  narrow: { wrapper: '@container (max-width: 767px)', axis: 'width' },
  medium: { wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width' },
  dark: { wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  light: { wrapper: '@media (prefers-color-scheme: light)', axis: 'scheme' },
  reduce: { wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion' },
};

/**
 * The rosters the cases read against: the shipped vocabulary (every
 * condition posable, as today), a richer one where `checked` is a posable
 * input state and `visited` is published but not posable, a frozen one
 * that poses nothing, and an empty one.
 */
export const TABLES = {
  default: { conditions: { ...STATES, ...WRAPPERS } },
  rich: {
    conditions: {
      ...STATES,
      ...WRAPPERS,
      checked: { wrapper: ':checked', axis: 'input' },
      visited: { wrapper: ':visited', axis: 'link' },
    },
    posable: [...Object.keys(STATES), ...Object.keys(WRAPPERS), 'checked'],
  },
  frozen: { conditions: { ...STATES, ...WRAPPERS }, posable: [] },
  none: { conditions: {} },
};

const WRAPPER_HINT = (published) =>
  `A condition is a published wrapper (${published}) or a literal the browser knows: "@media (hover: hover)", "@container (min-width: 500px)", "@supports (display: grid)"; a second axis nests as its own block.`;
const PUBLISHED = WRAPPER_HINT(
  '@container (max-width: 767px)  (narrow); @container (min-width: 768px) and (max-width: 1023px)  (medium); @media (prefers-color-scheme: dark)  (dark); @media (prefers-color-scheme: light)  (light); @media (prefers-reduced-motion: reduce)  (reduce)',
);
const unreadable = (text, hint = PUBLISHED) =>
  refused('UNKNOWN_WRAPPER', `"${text}" is not a condition: a @media or @container prelude tests features in parentheses ("(hover: hover)"), all on one axis, and @supports tests a declaration`, hint);
const HINT_OWN =
  'Style the element itself (":hover" its state, "&.open" its class), its parts (".part"), its tags ("p"), its children ("> *") or its descendants ("> d-text .link"); the page\'s root, html and body are no element\'s subtree.';
const HINT_GRAMMAR =
  'Compounds join by " ", " > ", " + " or " ~ " ("> d-text .link", "> p + p"); a compound is "*", a tag, a type ("d-text") or a part (".part") with its qualifiers glued to it (".active", "[aria-expanded=\\"true\\"]", ":hover", ":first-child", ":nth-child(2n+1)", ":has(a)", ":not(.x)", ":is(h1, h2)"); the element itself is "&" ("&.open > ul"), its own state a leading pseudo-class (":hover"); a pseudo-element ends the selector ("::before", "::after", "::marker"). No bare "*" by descent, no leading "+" or "~", no ":has()" inside ":has()", one compound inside ":not()", no ":is()" inside ":not()" or ":is()".';
const HINT_SIBLING =
  'Between compounds "+" and "~" are grammar ("> p + p", "> d-text:has(+ d-image)"); leading, they would reach outside the element.';
const HINT_FUNCTIONAL_PSEUDO =
  'Style a pseudo-element by its bare name, last and glued to the compound whose box it is; ::part(), ::slotted(), ::highlight() and ::cue() reach shadow trees and highlights this element does not expose.';
const HINT_LIST =
  'A selector list is one rule: members of one shape differing at one compound fold into ":is()" there ("h1, h2" is "*:is(h1, h2)", "> p, > div" is "> *:is(div, p)", "li:hover > ul, li:focus-within > ul" is "li:is(:focus-within, :hover) > ul"); rules of different shapes are separate rules.';
const HINT_LIST_CONDITION =
  'Each rule holds one condition ("a:hover { }" then "a:focus { }"); a list folds into one rule under the condition its members share ("a:hover, button:hover { }" is "*:is(a, button)" under hover).';
const HINT_LIST_STATE =
  'A rule holds one state on the element it addresses ("a:hover { }" then "a:focus-visible { }"); alternatives in ":is()" stand on the compounds above it ("li:is(:focus-within, :hover) > ul").';
const HINT_LIST_REDUNDANT =
  'Drop the member the others cover, or write the rule as that member alone ("a, a.x" styles every "a": write "a").';
const HINT_NO_ADDRESS =
  'Name what is in the state ("a:hover", "> *:hover" the children), or the element\'s own state (":hover").';

const ok = (selector, condition = null, folded = false) => ({ selector, condition, refusal: null, folded });
const folded = (selector, condition = null) => ok(selector, condition, true);
const refused = (code, message, hint) => ({ selector: null, condition: null, refusal: { code, message, hint }, folded: false });
const bad = (message, hint) => refused('BAD_SELECTOR', message, hint);
const outside = (text, word) => bad(`"${text}": "${word}" is not addressable; a rule styles inside this element's own subtree`, HINT_OWN);
const sibling = (text) =>
  bad(
    `"${text}": a rule styles inside this element's own subtree, and its siblings are outside it; address a sibling under its own rules, or from the parent as a relation among its children ("> d-text + d-text")`,
    HINT_SIBLING,
  );
const grammar = (text) => bad(`"${text}" is not a relative selector the grammar accepts`, HINT_GRAMMAR);
const notLast = (text) =>
  bad(
    `"${text}": a pseudo-element is terminal and one per selector; the qualifiers and states go before it ("&:hover::after", "> d-text:first-child::before") and nothing follows it`,
    'Put the pseudo-element last, glued to the last compound ("&::after", "li::marker").',
  );
const inQualifier = (text) =>
  bad(
    `"${text}": a pseudo-element never stands inside a qualifier (a generated box is no tree fact to narrow by); it is terminal, glued to the last compound`,
    'Style the box of what the qualifier narrows ("> d-text:has(a)::before"), or the box as its own rule ("a::before").',
  );
const spaced = (text) =>
  bad(
    `"${text}": a pseudo-element is glued to the compound whose box it is ("&::after" the element's own box, "> d-text::before" each text block's); "::" behind a space would address every descendant's box`,
    'Glue the pseudo-element to the compound whose box it is ("&::after", "li::marker").',
  );
const functionalPseudo = (text, name) =>
  bad(`"${text}": ::${name}() takes an argument and addresses nothing here; a pseudo-element is a bare name glued to its compound ("::before", "li::marker", "p::first-letter")`, HINT_FUNCTIONAL_PSEUDO);
const anotherId = (text, id) =>
  bad(
    `"${text}": "#${id}" addresses another element; a rule styles inside this element's own subtree`,
    'Style the other element under its own rules, or reach it from here by relation ("> d-text", "> * + *").',
  );
const anchorFirst = (text) =>
  bad(
    `"${text}": "&" is the element itself and stands first ("&:hover > ul", "&.open .menu")`,
    'A rule styles inside this element\'s own subtree, so the element starts every selector; "p &" would style it inside a paragraph, which is its ancestors\' business.',
  );
const noAddress = (text, left) => bad(`"${text}": the state peels into the rule's condition and leaves "${left}", which is not an address`, HINT_NO_ADDRESS);
const differ = (text, a, b, ca, cb) =>
  refused('SELECTOR_LIST', `"${text}": a selector list shares one condition; "${a}" and "${b}" peel different ones (${ca}, ${cb})`, HINT_LIST_CONDITION);
const empty = (text) => refused('SELECTOR_LIST', `"${text}": an empty selector in the list`, 'Remove the stray comma.');
const noShape = (text) => refused('SELECTOR_LIST', `"${text}": these rules share no shape; write them as separate rules`, HINT_LIST);
const redundant = (text, member) =>
  refused('SELECTOR_LIST', `"${text}": "${member}" already covers the others; a list member adds an alternative, and one the rest already cover is a slip`, HINT_LIST_REDUNDANT);
const statesOnLast = (text, state) =>
  refused('SELECTOR_LIST', `"${text}": two states on the addressed element (":is()" holds ":${state}" among its alternatives); write two rules, one per state`, HINT_LIST_STATE);
const nests = (text, member) =>
  refused('SELECTOR_LIST', `"${text}": "${member}" already holds alternatives where the fold would put the list's, and ":is()" never nests; write them as separate rules`, HINT_LIST);

export const DIGESTS = [
  // Relative preludes and the nesting sugar.
  { name: 'a child relation', text: '> ul', answer: ok('> ul') },
  { name: 'a descent', text: 'li a', answer: ok('li a') },
  { name: 'free whitespace lands canonical', text: '  > d-text  .link  ', answer: ok('> d-text .link') },
  { name: '& alone is the owner itself', text: '&', answer: ok('') },
  { name: 'an empty prelude is the owner itself', text: '', answer: ok('') },
  { name: 'whitespace alone is the owner itself', text: '   ', answer: ok('') },
  { name: '& then a child relation is the relation', text: '& > ul', answer: ok('> ul') },
  { name: '& glued to a child relation', text: '&>ul', answer: ok('> ul') },
  { name: '& then a descent is the descent', text: '& p', answer: ok('p') },
  { name: 'the qualified anchor keeps its class', text: '&.open > ul', answer: ok('&.open > ul') },
  { name: 'the qualified anchor keeps its position', text: '&:not(:last-child) > *', answer: ok('&:not(:last-child) > *') },
  { name: 'a leading colon is the owner\'s own box', text: '::before', answer: ok('&::before') },
  // B. A state on the last compound peels into the condition.
  { name: 'a leading colon is the owner\'s own state', text: ':hover', answer: ok('', 'hover') },
  { name: 'the anchor\'s state peels to the owner', text: '&:hover', answer: ok('', 'hover') },
  { name: 'the anchor\'s state before its box', text: '&:hover::before', answer: ok('&::before', 'hover') },
  { name: 'a leading colon state before the box', text: ':hover::after', answer: ok('&::after', 'hover') },
  { name: 'a tag\'s state', text: 'a:hover', answer: ok('a', 'hover') },
  { name: 'a type\'s state', text: '> d-text:hover', answer: ok('> d-text', 'hover') },
  { name: 'the children\'s state', text: '> *:hover', answer: ok('> *', 'hover') },
  { name: 'a part\'s state', text: '.cls:hover', answer: ok('.cls', 'hover') },
  { name: 'a two-class part\'s state', text: '.a.b:hover', answer: ok('.a.b', 'hover') },
  { name: 'the peel is by name: a state before a qualifier', text: '> d-text:hover:has(a)', answer: ok('> d-text:has(a)', 'hover') },
  { name: 'the peel is by name: a state after a qualifier', text: '> d-text:has(a):hover', answer: ok('> d-text:has(a)', 'hover') },
  { name: 'a position stays, the state peels', text: 'li:first-child:hover', answer: ok('li:first-child', 'hover') },
  { name: 'an An+B position stays, the state peels', text: 'li:nth-child(2n+1):hover', answer: ok('li:nth-child(2n+1)', 'hover') },
  { name: 'the anchor\'s class stays, its state peels', text: '&.open:hover', answer: ok('&.open', 'hover') },
  { name: 'the anchor\'s position stays, its state peels', text: '&:hover:first-child', answer: ok('&:first-child', 'hover') },
  { name: 'an attribute stays, the state peels', text: 'a[href^=http]:hover', answer: ok('a[href^="http"]', 'hover') },
  { name: 'every published state peels', text: 'a:focus-within', answer: ok('a', 'focus-within') },
  { name: 'case folds before the peel', text: 'A:HOVER', answer: ok('a', 'hover') },
  // A. A state on a non-terminal compound is that compound's qualifier.
  { name: 'a state on an ancestor compound stays', text: 'li:hover > ul', answer: ok('li:hover > ul') },
  { name: 'the anchor\'s state with a chain below stays', text: '&:hover > ul', answer: ok('&:hover > ul') },
  { name: 'a leading colon with a chain is the anchor\'s state', text: ':hover > ul', answer: ok('&:hover > ul') },
  { name: 'the anchor\'s state over a descent', text: '&:hover p', answer: ok('&:hover p') },
  { name: 'a qualified universal\'s state with a chain', text: '*:hover > ul', answer: ok('*:hover > ul') },
  { name: 'a position on an ancestor compound', text: 'li:first-child > a', answer: ok('li:first-child > a') },
  { name: 'case folds on every compound', text: 'Li:HOVER > UL', answer: ok('li:hover > ul') },
  // E, F. A negated state and a state inside a qualifier are qualifiers.
  { name: 'a negated state stays', text: 'li:not(:hover)', answer: ok('li:not(:hover)') },
  { name: 'a state inside :has() stays', text: '> d-text:has(a:hover)', answer: ok('> d-text:has(a:hover)') },
  // M. An unpublished posable pseudo-class peels as a LITERAL clause; a
  // state no stamp stands in for, or a published one the editor cannot
  // pose, is a qualifier.
  { name: 'a state no stamp stands in for stays', text: 'a:visited', answer: ok('a:visited') },
  { name: 'the URL fragment state stays', text: 'a:target', answer: ok('a:target') },
  { name: 'an unpublished posable input state peels as a literal', text: 'input:checked', answer: ok('input', ':checked') },
  { name: 'an unpublished posable state on the anchor peels as a literal', text: '&:checked', answer: ok('', ':checked') },
  { name: 'a literal state before the owner\'s box', text: ':disabled::before', answer: ok('&::before', ':disabled') },
  { name: 'a literal state folds its case', text: 'input:CHECKED', answer: ok('input', ':checked') },
  { name: 'a literal state on an ancestor compound stays', text: 'input:checked + label', answer: ok('input:checked + label') },
  { name: 'a literal and a published state share the state axis', text: 'input:checked:hover', answer: bad('"input:checked:hover": one condition per axis; ":checked" and ":hover" are both on the "state" axis', 'A rule holds one state; the second is a second rule.') },
  { name: 'a published posable input state peels as its reference', text: 'input:checked', table: 'rich', answer: ok('input', 'checked') },
  { name: 'two axes peel into one compound in axis order', text: 'input:checked:hover', table: 'rich', answer: ok('input', 'hover+checked') },
  { name: 'a published state the editor cannot pose stays', text: 'a:visited', table: 'rich', answer: ok('a:visited') },
  { name: 'the posable one peels beside the one that stays', text: 'a:visited:hover', table: 'rich', answer: ok('a:visited', 'hover') },
  { name: 'a frozen roster keeps its published states as qualifiers', text: 'a:hover', table: 'frozen', answer: ok('a:hover') },
  { name: 'with no roster a posable state peels as a literal', text: 'a:hover', table: 'none', answer: ok('a', ':hover') },
  { name: 'a list sharing a literal state folds under it', text: 'input:checked, select:checked', answer: folded('*:is(input, select)', ':checked') },
  // G, H. Classes and attributes on any compound.
  { name: 'a class on a tag', text: 'li.active > ul', answer: ok('li.active > ul') },
  { name: 'a leading attribute is the universal\'s', text: '[aria-expanded="true"] > ul', answer: ok('*[aria-expanded="true"] > ul') },
  // I. A selector list folds into one `:is()` rule: members of one shape
  // differing at one compound, the shared part on the host, each
  // remainder a member, the grammar's spelling (members sorted, the star
  // of a chain compound printed).
  { name: 'a list of tags folds to the star', text: 'h1, h2, h3', answer: folded('*:is(h1, h2, h3)') },
  { name: 'Lily\'s line: the states fold on the parent compound', text: '.menu-item:hover > ul, .menu-item:focus-within > ul', answer: folded('.menu-item:is(:focus-within, :hover) > ul') },
  { name: 'a list sharing a state peels first, then folds', text: 'a:hover, button:hover', answer: folded('*:is(a, button)', 'hover') },
  { name: 'child relations fold on the universal', text: '> p, > div', answer: folded('> *:is(div, p)') },
  { name: 'the pseudo-element rides the fold', text: 'h1::before, h2::before', answer: folded('*:is(h1, h2)::before') },
  { name: 'a comma inside a string is no split', text: 'a[data-x="a,b"], p', answer: folded('*:is(a[data-x="a,b"], p)') },
  { name: 'members land sorted whatever their order and case', text: 'H2, h1', answer: folded('*:is(h1, h2)') },
  { name: 'the shared subject stays, the classes fold', text: 'li.a > ul, li.b > ul', answer: folded('li:is(.a, .b) > ul') },
  { name: 'the shared class stays the subject', text: '.a.x, .b.x', answer: folded('.x:is(.a, .b)') },
  { name: 'the shared :has() stays on the host', text: 'li:has(a) > ul, li:has(img) > ul', answer: folded('li:is(:has(a), :has(img)) > ul') },
  { name: 'the anchor folds its alternatives', text: '&.open > ul, &.closed > ul', answer: folded('&:is(.closed, .open) > ul') },
  { name: 'a deeper compound folds', text: '> d-text a, > d-image a', answer: folded('> *:is(d-image, d-text) a') },
  { name: 'a member already holding :is() flattens into the fold', text: '*:is(h1, h2), h3', answer: folded('*:is(h1, h2, h3)') },
  { name: 'a member\'s :is() flattens beside a plain member', text: '.menu-item:is(:hover, :focus-within) > ul, .menu-item:active > ul', answer: folded('.menu-item:is(:active, :focus-within, :hover) > ul') },
  { name: 'a repeated member lands once and is no list', text: 'a, a', answer: ok('a') },
  { name: 'a frozen roster folds the states it cannot pose', text: 'a:hover, a:focus', table: 'frozen', answer: folded('a:is(:focus, :hover)') },
  // J. `:is()` typed directly is one rule; the refusals.
  { name: ':is() typed directly', text: '*:is(h1, h2)', answer: ok('*:is(h1, h2)') },
  { name: 'a leading :is() is the owner\'s own alternatives', text: ':is(h1, h2)', answer: ok('&:is(h1, h2)') },
  { name: ':where() reads as :is(), one member merged', text: 'a:where(.x)', answer: ok('a.x') },
  { name: 'a state on a non-terminal compound inside :is() stays', text: 'li:is(:hover, :focus-within) > ul', answer: ok('li:is(:focus-within, :hover) > ul') },
  { name: 'body inside :is() is outside every element', text: '> *:is(body, div)', answer: outside('> *:is(body, div)', 'body') },
  { name: 'members peeling different states refuse', text: 'a:hover, a:focus', answer: differ('a:hover, a:focus', 'a:hover', 'a:focus', 'hover', 'focus') },
  { name: 'a member with no state beside one with', text: 'h1, h2:hover', answer: differ('h1, h2:hover', 'h1', 'h2:hover', 'none', 'hover') },
  { name: 'an empty member', text: 'h1,, h2', answer: empty('h1,, h2') },
  { name: 'a trailing comma', text: 'h1,', answer: empty('h1,') },
  { name: 'the owner and a tag share no shape', text: '&:hover, a:hover', answer: noShape('&:hover, a:hover') },
  { name: 'different compound counts share no shape', text: 'h1, > p', answer: noShape('h1, > p') },
  { name: 'different combinators share no shape', text: 'li > ul, li a', answer: noShape('li > ul, li a') },
  { name: 'different pseudo-elements share no shape', text: 'h1::before, h2::after', answer: noShape('h1::before, h2::after') },
  { name: 'the owner\'s two boxes share no shape', text: '&::before, &::after', answer: noShape('&::before, &::after') },
  { name: 'two differing compounds share no shape', text: 'li.a > ul.x, li.b > ul.y', answer: noShape('li.a > ul.x, li.b > ul.y') },
  { name: 'the anchor and a tag at one place share no shape', text: '&:is(h1, h2), a', answer: noShape('&:is(h1, h2), a') },
  { name: 'a redundant member', text: 'a, a.x', answer: redundant('a, a.x', 'a') },
  { name: 'a redundant universal', text: '> p, > *', answer: redundant('> p, > *', '> *') },
  { name: 'a member another\'s :is() already holds', text: '*:is(h1, h2), h1', answer: redundant('*:is(h1, h2), h1', 'h1') },
  { name: 'a typed :is() with states on the addressed element', text: 'a:is(:hover, :focus-visible)', answer: statesOnLast('a:is(:hover, :focus-visible)', 'focus-visible') },
  { name: 'the owner\'s own :is() with states', text: ':is(:hover, :focus)', answer: statesOnLast(':is(:hover, :focus)', 'focus') },
  { name: 'one state among the alternatives on the addressed element', text: 'a:is(.x, :hover)', answer: statesOnLast('a:is(.x, :hover)', 'hover') },
  { name: 'a frozen roster poses nothing, so the states are qualifiers', text: 'a:is(:focus, :hover)', table: 'frozen', answer: ok('a:is(:focus, :hover)') },
  { name: 'a member whose :is() would nest', text: 'a.z:is(.x, .y), b', answer: nests('a.z:is(.x, .y), b', 'a.z:is(.x, .y)') },
  // Two states on one axis.
  { name: 'two states on one axis refuse', text: 'a:hover:focus', answer: bad('"a:hover:focus": one condition per axis; ":focus" and ":hover" are both on the "state" axis', 'A rule holds one state; the second is a second rule.') },
  // A peel that leaves no address.
  { name: 'a bare universal\'s state leaves no address', text: '*:hover', answer: noAddress('*:hover', '*') },
  { name: 'a spaced state leaves every descendant', text: 'li :hover', answer: noAddress('li :hover', 'li *') },
  // P. The correct refusals, carried verbatim.
  { name: 'html is outside every element', text: 'html', answer: outside('html', 'html') },
  { name: 'body is outside every element', text: 'body p', answer: outside('body p', 'body') },
  { name: ':root is outside every element', text: ':root', answer: outside(':root', ':root') },
  { name: 'body inside a qualifier', text: '> div:not(body)', answer: outside('> div:not(body)', 'body') },
  { name: 'another element\'s id', text: '#other', answer: anotherId('#other', 'other') },
  { name: 'another element\'s id with a chain', text: '#x > ul', answer: anotherId('#x > ul', 'x') },
  { name: 'a leading next-sibling combinator', text: '+ p', answer: sibling('+ p') },
  { name: 'a leading subsequent-sibling combinator', text: '~ p', answer: sibling('~ p') },
  { name: 'the anchor\'s sibling', text: '& + p', answer: sibling('& + p') },
  { name: 'a pseudo-element behind a space', text: '> d-text ::before', answer: spaced('> d-text ::before') },
  { name: 'the anchor\'s pseudo-element behind a space', text: '& ::before', answer: spaced('& ::before') },
  // K. The pseudo-element slot takes any name the browser spells (the
  // owner's through the leading `::`, a tag's or a chain's glued; the
  // legacy single colon for the four it still names; case folded); a
  // functional pseudo-element is refused, and whether this site knows a
  // name is the validator's verdict, never the digester's.
  { name: 'the owner\'s marker', text: '::marker', answer: ok('&::marker') },
  { name: 'a tag\'s marker', text: 'li::marker', answer: ok('li::marker') },
  { name: 'a chain\'s first letter', text: '> d-text::first-letter', answer: ok('> d-text::first-letter') },
  { name: 'a placeholder on an attribute-qualified tag', text: 'input[type="text"]::placeholder', answer: ok('input[type="text"]::placeholder') },
  { name: 'the owner\'s selection, case folded', text: '&::Selection', answer: ok('&::selection') },
  { name: 'a legacy single-colon first line', text: 'p:first-line', answer: ok('p::first-line') },
  { name: 'a state peels before another pseudo-element', text: 'li:hover::marker', answer: ok('li::marker', 'hover') },
  { name: 'a pseudo-element the browser does not know still digests', text: '&::markr', answer: ok('&::markr') },
  { name: 'a functional pseudo-element', text: '&::part(label)', answer: functionalPseudo('&::part(label)', 'part') },
  { name: 'a functional pseudo-element on a chain', text: '> d-text::slotted(span)', answer: functionalPseudo('> d-text::slotted(span)', 'slotted') },
  { name: 'a compound after another pseudo-element', text: 'li::marker .x', answer: notLast('li::marker .x') },
  { name: 'two pseudo-elements, one of them another', text: 'li::marker::before', answer: notLast('li::marker::before') },
  { name: 'another pseudo-element behind a space', text: 'li ::marker', answer: spaced('li ::marker') },
  { name: 'another pseudo-element inside :has()', text: 'ul:has(li::marker)', answer: inQualifier('ul:has(li::marker)') },
  { name: 'a pseudo-element inside :has()', text: '> d-text:has(a::before)', answer: inQualifier('> d-text:has(a::before)') },
  { name: 'a legacy pseudo-element inside :not()', text: '> *:not(:before)', answer: inQualifier('> *:not(:before)') },
  { name: 'a compound after the pseudo-element', text: '> d-text::before .x', answer: notLast('> d-text::before .x') },
  { name: 'two pseudo-elements', text: 'p::before::after', answer: notLast('p::before::after') },
  { name: 'a relation after the owner\'s box', text: '&::before > *', answer: notLast('&::before > *') },
  { name: 'the condition sigil is not a selector', text: '&hover', answer: bad('"&hover": a state is written as a pseudo-class (":hover", "&:hover")', '"&" is the element itself and takes CSS qualifiers (":hover", ".open", "[aria-expanded=\\"true\\"]"); "&hover" is the chrome\'s condition shorthand, not a selector.') },
  { name: '& after a compound', text: 'p &', answer: anchorFirst('p &') },
  { name: '& after a relation', text: '> d-text &::before', answer: anchorFirst('> d-text &::before') },
  { name: ':has() inside :has() is outside the grammar', text: 'p:has(:has(a))', answer: grammar('p:has(:has(a))') },
  { name: 'a functional state is outside the grammar', text: 'p:hover()', answer: grammar('p:hover()') },
  { name: 'two combinators are outside the grammar', text: '> >', answer: grammar('> >') },
  // Q. At-rule preludes: a published wrapper reads as its reference, any
  // other query the condition grammar reads as a literal, canonical.
  { name: 'a published container wrapper', text: '@container (max-width: 767px)', answer: ok(null, 'narrow') },
  { name: 'a published wrapper, case and spacing folded', text: '@CONTAINER  ( max-width:767px )', answer: ok(null, 'narrow') },
  { name: 'a published media wrapper', text: '@media (prefers-color-scheme: dark)', answer: ok(null, 'dark') },
  { name: 'an unpublished width media query is a literal', text: '@media (max-width: 600px)', answer: ok(null, '@media (max-width: 600px)') },
  { name: 'an unpublished container band is a literal', text: '@container (min-width: 500px)', answer: ok(null, '@container (min-width: 500px)') },
  { name: 'a pointer query is a literal', text: '@media (hover: hover)', answer: ok(null, '@media (hover: hover)') },
  { name: 'a literal lands canonical', text: '@MEDIA  ( Hover : hover )', answer: ok(null, '@media (hover: hover)') },
  { name: 'a supports test is a literal', text: '@supports (display: grid)', answer: ok(null, '@supports (display: grid)') },
  { name: 'a preference the roster does not publish is a literal', text: '@media (prefers-contrast: more)', answer: ok(null, '@media (prefers-contrast: more)') },
  { name: 'a literal with no roster', text: '@media (hover: hover)', table: 'none', answer: ok(null, '@media (hover: hover)') },
  { name: 'a query with no feature test is refused', text: '@media screen', answer: unreadable('@media screen') },
  { name: 'features on two axes are refused', text: '@media (min-width: 900px) and (hover: hover)', answer: unreadable('@media (min-width: 900px) and (hover: hover)') },
  { name: 'an unbalanced query is refused', text: '@container (min-width: 500px', answer: unreadable('@container (min-width: 500px') },
  { name: 'an at-rule of another kind is refused', text: '@keyframes spin', answer: unreadable('@keyframes spin') },
  { name: 'the refusal names the published wrappers, none here', text: '@media screen', table: 'none', answer: unreadable('@media screen', WRAPPER_HINT('none published')) },
];

export const REACHES = [
  { name: 'the owner reaches itself', selector: '', answer: '' },
  { name: 'an ancestor state is stripped', selector: 'li:hover > ul', answer: 'li > ul' },
  { name: 'the anchor\'s state is stripped and the anchor with it', selector: '&:hover > ul', answer: '> ul' },
  { name: 'the anchor keeps its class', selector: '&.open:hover .menu', answer: '&.open .menu' },
  { name: 'a state inside :has() is stripped', selector: '> d-text:has(a:hover)', answer: '> d-text:has(a)' },
  { name: 'a negated state stays', selector: 'li:not(:hover)', answer: 'li:not(:hover)' },
  { name: 'a :has() left with every descendant is dropped', selector: 'li:has(*:hover)', answer: 'li' },
  { name: 'a :has() keeps its relation', selector: 'li:has(> ul:hover)', answer: 'li:has(> ul)' },
  { name: 'a position stays', selector: 'li:first-child:hover > a', answer: 'li:first-child > a' },
  { name: 'an attribute and the box stay', selector: 'a[href^="http"]:hover::after', answer: 'a[href^="http"]::after' },
  { name: 'the owner\'s box stays', selector: '&:hover::before', answer: '&::before' },
  { name: 'the anchor\'s state alone reaches the owner', selector: '&:hover', answer: '' },
  { name: 'a part\'s state', selector: '.card:hover img', answer: '.card img' },
  { name: 'the children\'s state', selector: '> *:hover', answer: '> *' },
  { name: 'a universal left bare by descent reaches nothing', selector: '*:hover > ul', answer: null },
  { name: 'an unpublished pseudo-class stays', selector: 'a:visited', answer: 'a:visited' },
  { name: 'an unpublished posable state on an ancestor stays in the reach (no stamp poses it)', selector: 'input:checked + label', answer: 'input:checked + label' },
  { name: 'two posable states strip', selector: 'input:checked:hover > span', table: 'rich', answer: 'input > span' },
  { name: 'the non-posable one stays', selector: 'a:visited:hover', table: 'rich', answer: 'a:visited' },
  { name: 'a frozen roster strips nothing', selector: 'li:hover > ul', table: 'frozen', answer: 'li:hover > ul' },
  { name: 'outside the grammar reaches nothing', selector: 'p:has(:has(a))', answer: null },
  // Inside `:is()` the states strip per member: a member left bare drops,
  // an `:is()` left empty drops, one member left merges into its host.
  { name: 'Lily\'s rule: an :is() of states drops whole', selector: '.menu-item:is(:focus-within, :hover) > ul', answer: '.menu-item > ul' },
  { name: 'a member left bare drops, the other merges', selector: '.menu-item:is(.open, :hover) > ul', answer: '.menu-item.open > ul' },
  { name: 'the states strip inside each member', selector: '*:is(a:hover, button:hover)', answer: '*:is(a, button)' },
  { name: 'a member\'s :has() strips too', selector: '> *:is(d-image, d-text:has(a:hover))', answer: '> *:is(d-image, d-text:has(a))' },
  { name: 'an :is() dropped off a universal by descent reaches nothing', selector: '*:is(:focus, :hover) > ul', answer: null },
  { name: 'a negated state inside :is() stays', selector: 'li:is(:hover, :not(:hover)) > a', answer: 'li:not(:hover) > a' },
  { name: 'a frozen roster strips nothing inside :is()', selector: '.menu-item:is(:focus-within, :hover) > ul', table: 'frozen', answer: '.menu-item:is(:focus-within, :hover) > ul' },
];

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.stdout.write(JSON.stringify({ tables: TABLES, digests: DIGESTS, reaches: REACHES }));
}
