/**
 * The relative selector grammar's client twin (relative-selector.js):
 * parse, canonical print, the key split, compound reads, the type
 * mapping. PHP is authoritative (RelativeSelectorTest); the shared
 * battery in relative-selector-cases.mjs locks the two together.
 */
import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import {
  addressMembers,
  canonicalNth,
  canonicalSelector,
  isCanonicalSelector,
  isChildren,
  isPart,
  isPartFirst,
  isTag,
  parseSelector,
  printCompounds,
  prefix,
  printSelector,
  reachesChildren,
  selectorClasses,
  splitKey,
  typeAddress,
  typeName,
  typeSelector,
  under,
} from '../../js/edit_mode/materials/relative-selector.js';

const c = (combinator, kind, name, qualifiers = []) => ({ combinator, kind, name, qualifiers });
const has = (argument) => ({ name: 'has', argument });
const not = (kind, name, qualifiers = []) => ({
  name: 'not',
  argument: { combinator: null, kind, name, qualifiers },
});
const at = (name, argument = null) => ({ name, argument });
const cls = (name) => ({ name: 'class', argument: name });
const attr = (attribute, operator = null, value = null, flag = null) => ({
  name: 'attribute',
  argument: { attribute, operator, value, flag },
});
const anchor = (qualifiers) => ({ combinator: 'descendant', kind: 'anchor', name: '&', qualifiers });
const is = (...members) => ({ name: 'is', argument: members });
const member = (kind, name, qualifiers = []) => ({ combinator: null, kind, name, qualifiers });

test('every selector shape parses and prints canonical', () => {
  const cases = {
    // `:is()`: any of several compounds at the compound it qualifies,
    // the members sorted by printed form, each a predicate compound (the
    // implied subject prints nothing), on any subject kind, the anchor
    // included, inside `:has()`, among other qualifiers.
    '*:is(h1, h2)': [c('descendant', 'universal', '*', [is(member('tag', 'h1'), member('tag', 'h2'))])],
    '.menu-item:is(:focus-within, :hover) > ul': [
      c('descendant', 'class', '.menu-item', [is(member(null, null, [at('focus-within')]), member(null, null, [at('hover')]))]),
      c('child', 'tag', 'ul'),
    ],
    '*:is(.a, .b)': [c('descendant', 'universal', '*', [is(member('class', '.a'), member('class', '.b'))])],
    '> *:is(div, p)': [c('child', 'universal', '*', [is(member('tag', 'div'), member('tag', 'p'))])],
    '*:is(:not(.b), a)': [c('descendant', 'universal', '*', [is(member(null, null, [not('class', '.b')]), member('tag', 'a'))])],
    '> d-text:has(*:is(a, button))': [
      c('child', 'type', 'd-text', [has([c('descendant', 'universal', '*', [is(member('tag', 'a'), member('tag', 'button'))])])]),
    ],
    '*:is(d-image, d-text)': [c('descendant', 'universal', '*', [is(member('type', 'd-image'), member('type', 'd-text'))])],
    '&:is(.a, .b) > ul': [anchor([is(member('class', '.a'), member('class', '.b'))]), c('child', 'tag', 'ul')],
    'li:hover:is(.a, .b):not(.c)': [c('descendant', 'tag', 'li', [at('hover'), is(member('class', '.a'), member('class', '.b')), not('class', '.c')])],
    '> d-text:is(:first-child, :last-child) a': [
      c('child', 'type', 'd-text', [is(member(null, null, [at('first-child')]), member(null, null, [at('last-child')]))]),
      c('descendant', 'tag', 'a'),
    ],
    '*:is(a:has(img), button)': [
      c('descendant', 'universal', '*', [is(member('tag', 'a', [has([c('descendant', 'tag', 'img')])]), member('tag', 'button'))]),
    ],
    'a:is(.x, [href])': [c('descendant', 'tag', 'a', [is(member('class', '.x'), member(null, null, [attr('href')]))])],
    '.cls': [c('descendant', 'class', '.cls')],
    p: [c('descendant', 'tag', 'p')],
    '> *': [c('child', 'universal', '*')],
    '.cls > *': [c('descendant', 'class', '.cls'), c('child', 'universal', '*')],
    '> d-text .link': [c('child', 'type', 'd-text'), c('descendant', 'class', '.link')],
    'd-container > * > *': [
      c('descendant', 'type', 'd-container'),
      c('child', 'universal', '*'),
      c('child', 'universal', '*'),
    ],
    'p > *': [c('descendant', 'tag', 'p'), c('child', 'universal', '*')],
    '> p + p': [c('child', 'tag', 'p'), c('next', 'tag', 'p')],
    'p ~ p': [c('descendant', 'tag', 'p'), c('subsequent', 'tag', 'p')],
    '> p + *': [c('child', 'tag', 'p'), c('next', 'universal', '*')],
    '> d-text .link ~ .link': [
      c('child', 'type', 'd-text'),
      c('descendant', 'class', '.link'),
      c('subsequent', 'class', '.link'),
    ],
    '> d-text:has(a)': [c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a')])])],
    '> d-text:has(+ d-image)': [c('child', 'type', 'd-text', [has([c('next', 'type', 'd-image')])])],
    '> d-container:has(> d-image) > *': [
      c('child', 'type', 'd-container', [has([c('child', 'type', 'd-image')])]),
      c('child', 'universal', '*'),
    ],
    '> d-text:has(a):has(img)': [
      c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a')]), has([c('descendant', 'tag', 'img')])]),
    ],
    '> *:not(d-image)': [c('child', 'universal', '*', [not('type', 'd-image')])],
    '> *:not(d-image):not(d-video)': [
      c('child', 'universal', '*', [not('type', 'd-image'), not('type', 'd-video')]),
    ],
    '> d-text:not(:has(a))': [
      c('child', 'type', 'd-text', [not(null, null, [has([c('descendant', 'tag', 'a')])])]),
    ],
    '> d-text:has(a):not(.featured)': [
      c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a')]), not('class', '.featured')]),
    ],
    '.cls:not(.x)': [c('descendant', 'class', '.cls', [not('class', '.x')])],
    // Position pseudo-classes qualify any compound, inside `:not()` and
    // `:has()` too, the `An+B` canonical as CSS serializes it.
    '> *:not(:last-child)': [c('child', 'universal', '*', [not(null, null, [at('last-child')])])],
    '> *:first-child:last-child': [c('child', 'universal', '*', [at('first-child'), at('last-child')])],
    'li:nth-child(2n+1)': [c('descendant', 'tag', 'li', [at('nth-child', '2n+1')])],
    'li:nth-child(odd)': [c('descendant', 'tag', 'li', [at('nth-child', 'odd')])],
    '> d-text:nth-of-type(2)': [c('child', 'type', 'd-text', [at('nth-of-type', '2')])],
    '> d-text:nth-last-child(-n+3)': [c('child', 'type', 'd-text', [at('nth-last-child', '-n+3')])],
    '> d-text:nth-last-of-type(even)': [c('child', 'type', 'd-text', [at('nth-last-of-type', 'even')])],
    '.cls:only-child': [c('descendant', 'class', '.cls', [at('only-child')])],
    '> d-text:empty': [c('child', 'type', 'd-text', [at('empty')])],
    '> d-text:first-of-type:last-of-type:only-of-type': [
      c('child', 'type', 'd-text', [at('first-of-type'), at('last-of-type'), at('only-of-type')]),
    ],
    '> d-text:first-child:has(a):not(.featured):nth-child(n)': [
      c('child', 'type', 'd-text', [at('first-child'), has([c('descendant', 'tag', 'a')]), not('class', '.featured'), at('nth-child', 'n')]),
    ],
    '> d-text:has(a:first-child)': [c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a', [at('first-child')])])])],
    '> d-text:not(p:first-child)': [c('child', 'type', 'd-text', [not('tag', 'p', [at('first-child')])])],
    // The qualified anchor: the owner itself as the leading compound.
    '&:first-child': [anchor([at('first-child')])],
    '&:not(:last-child) > *': [anchor([not(null, null, [at('last-child')])]), c('child', 'universal', '*')],
    '&:nth-child(2n) .cls': [anchor([at('nth-child', '2n')]), c('descendant', 'class', '.cls')],
    '&:first-child > .cls': [anchor([at('first-child')]), c('child', 'class', '.cls')],
    '&:last-child p': [anchor([at('last-child')]), c('descendant', 'tag', 'p')],
    '&:only-child > d-text .link': [anchor([at('only-child')]), c('child', 'type', 'd-text'), c('descendant', 'class', '.link')],
    '&:first-child:last-child': [anchor([at('first-child'), at('last-child')])],
    '&:has(a) p': [anchor([has([c('descendant', 'tag', 'a')])]), c('descendant', 'tag', 'p')],
    '&:not(.x) > *': [anchor([not('class', '.x')]), c('child', 'universal', '*')],
    // A. A state (any pseudo-class) on a non-terminal compound, the
    // anchor included; a bare pseudo-class is the universal's.
    'li:hover > ul': [c('descendant', 'tag', 'li', [at('hover')]), c('child', 'tag', 'ul')],
    'li:first-child > a': [c('descendant', 'tag', 'li', [at('first-child')]), c('child', 'tag', 'a')],
    'a:hover span': [c('descendant', 'tag', 'a', [at('hover')]), c('descendant', 'tag', 'span')],
    '.card:hover img': [c('descendant', 'class', '.card', [at('hover')]), c('descendant', 'tag', 'img')],
    '> ul li:hover > ul': [c('child', 'tag', 'ul'), c('descendant', 'tag', 'li', [at('hover')]), c('child', 'tag', 'ul')],
    '&:hover > ul': [anchor([at('hover')]), c('child', 'tag', 'ul')],
    '*:hover > ul': [c('descendant', 'universal', '*', [at('hover')]), c('child', 'tag', 'ul')],
    '> d-text:hover a': [c('child', 'type', 'd-text', [at('hover')]), c('descendant', 'tag', 'a')],
    // B. A state on the last compound (whether it peels into a condition
    // is the digester's; the grammar carries it as a qualifier).
    'a:hover': [c('descendant', 'tag', 'a', [at('hover')])],
    '> d-text:hover': [c('child', 'type', 'd-text', [at('hover')])],
    '&:hover': [anchor([at('hover')])],
    '*:hover': [c('descendant', 'universal', '*', [at('hover')])],
    'a:visited': [c('descendant', 'tag', 'a', [at('visited')])],
    'input:checked': [c('descendant', 'tag', 'input', [at('checked')])],
    'li:first-child:hover': [c('descendant', 'tag', 'li', [at('first-child'), at('hover')])],
    'p:active:hover': [c('descendant', 'tag', 'p', [at('active'), at('hover')])],
    // E. A negated state. F. A state inside a qualifier.
    'li:not(:hover)': [c('descendant', 'tag', 'li', [not(null, null, [at('hover')])])],
    'a:not(:focus-visible)': [c('descendant', 'tag', 'a', [not(null, null, [at('focus-visible')])])],
    '> d-text:has(a:hover)': [c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a', [at('hover')])])])],
    'li:has(> ul:hover)': [c('descendant', 'tag', 'li', [has([c('child', 'tag', 'ul', [at('hover')])])])],
    '*:has(*:focus-within)': [c('descendant', 'universal', '*', [has([c('descendant', 'universal', '*', [at('focus-within')])])])],
    // G. A class on any compound: a tag, a type, the anchor; a second
    // class on a part is the part's qualifier.
    'li.active > ul': [c('descendant', 'tag', 'li', [cls('active')]), c('child', 'tag', 'ul')],
    'li.open': [c('descendant', 'tag', 'li', [cls('open')])],
    '&.open > ul': [anchor([cls('open')]), c('child', 'tag', 'ul')],
    '&.open .menu': [anchor([cls('open')]), c('descendant', 'class', '.menu')],
    '&.open': [anchor([cls('open')])],
    'a.button': [c('descendant', 'tag', 'a', [cls('button')])],
    '.is-open > ul': [c('descendant', 'class', '.is-open'), c('child', 'tag', 'ul')],
    '> d-text.featured': [c('child', 'type', 'd-text', [cls('featured')])],
    '.a.b': [c('descendant', 'class', '.a', [cls('b')])],
    'li.a.b': [c('descendant', 'tag', 'li', [cls('a'), cls('b')])],
    '&.a.b': [anchor([cls('a'), cls('b')])],
    'li.active:hover > ul': [c('descendant', 'tag', 'li', [cls('active'), at('hover')]), c('child', 'tag', 'ul')],
    'p:not(.a.b)': [c('descendant', 'tag', 'p', [not('class', '.a', [cls('b')])])],
    '.a .b': [c('descendant', 'class', '.a'), c('descendant', 'class', '.b')],
    'p .a': [c('descendant', 'tag', 'p'), c('descendant', 'class', '.a')],
    '> * .a': [c('child', 'universal', '*'), c('descendant', 'class', '.a')],
    'p + .cls': [c('descendant', 'tag', 'p'), c('next', 'class', '.cls')],
    // H. Attribute predicates: the six operators, the flag, several on
    // one compound, inside `:not()`, on the anchor, leading.
    'a[href^="http"]': [c('descendant', 'tag', 'a', [attr('href', '^=', 'http')])],
    'input[type="checkbox"]': [c('descendant', 'tag', 'input', [attr('type', '=', 'checkbox')])],
    '*[aria-expanded="true"] > ul': [c('descendant', 'universal', '*', [attr('aria-expanded', '=', 'true')]), c('child', 'tag', 'ul')],
    'li[aria-current="page"]': [c('descendant', 'tag', 'li', [attr('aria-current', '=', 'page')])],
    'a[target="_blank"]': [c('descendant', 'tag', 'a', [attr('target', '=', '_blank')])],
    '*[x]': [c('descendant', 'universal', '*', [attr('x')])],
    '> *[hidden]': [c('child', 'universal', '*', [attr('hidden')])],
    '&[aria-expanded="true"] > ul': [anchor([attr('aria-expanded', '=', 'true')]), c('child', 'tag', 'ul')],
    '*[lang|="en"]': [c('descendant', 'universal', '*', [attr('lang', '|=', 'en')])],
    '*[class~="x"]': [c('descendant', 'universal', '*', [attr('class', '~=', 'x')])],
    '*[data-x$="y" i]': [c('descendant', 'universal', '*', [attr('data-x', '$=', 'y', 'i')])],
    '*[data-x*="y" s]': [c('descendant', 'universal', '*', [attr('data-x', '*=', 'y', 's')])],
    'a[href^="http"][target="_blank"]': [c('descendant', 'tag', 'a', [attr('href', '^=', 'http'), attr('target', '=', '_blank')])],
    '*:not([hidden])': [c('descendant', 'universal', '*', [not(null, null, [attr('hidden')])])],
    '*[x=""]': [c('descendant', 'universal', '*', [attr('x', '=', '')])],
    '*[x="say \\"hi\\""]': [c('descendant', 'universal', '*', [attr('x', '=', 'say "hi"')])],
    '*[x="a\\\\b"]': [c('descendant', 'universal', '*', [attr('x', '=', 'a\\b')])],
    '*[x="tab\\9 "]': [c('descendant', 'universal', '*', [attr('x', '=', 'tab\t')])],
    '*[data-x="a::b"]': [c('descendant', 'universal', '*', [attr('data-x', '=', 'a::b')])],
    // The marks the emit gate admits in no text print as hex escapes and
    // read back as themselves.
    '*[x="\\3c /style\\3e "]': [c('descendant', 'universal', '*', [attr('x', '=', '</style>')])],
    '*[x="a\\3b b\\7b c\\7d d"]': [c('descendant', 'universal', '*', [attr('x', '=', 'a;b{c}d')])],
    '> d-text:has(a[data-x=")"])': [c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a', [attr('data-x', '=', ')')])])])],
    'li.active:hover[aria-current="page"]': [c('descendant', 'tag', 'li', [cls('active'), at('hover'), attr('aria-current', '=', 'page')])],
    // The implied subject is the universal in a chain.
    '*:first-child': [c('descendant', 'universal', '*', [at('first-child')])],
    'd-text *:has(a)': [c('descendant', 'type', 'd-text'), c('descendant', 'universal', '*', [has([c('descendant', 'tag', 'a')])])],
  };
  Object.entries(cases).forEach(([selector, compounds]) => {
    assert.deepEqual(parseSelector(selector), { compounds, pseudo: null }, selector);
    assert.equal(printSelector({ compounds, pseudo: null }), selector);
    assert.equal(printCompounds(compounds), selector);
    assert.equal(isCanonicalSelector(selector), true);
  });
});

test('the An+B serializer is CSS\'s own, read leniently on case and whitespace only', () => {
  const canonical = {
    odd: 'odd', ' even ': 'even', '2n+1': '2n+1', ' 2n + 1 ': '2n+1', '+2n+1': '2n+1', n: 'n', '+n': 'n', '1n+0': 'n',
    '-n': '-n', '-1n+3': '-n+3', '2n': '2n', '2n-1': '2n-1', '-2n - 1': '-2n-1', 3: '3', '+3': '3', '0n+3': '3', '007': '7',
    '-1': '-1', 0: '0', '0n': '0', '-0n+0': '0', '2N': '2n', ODD: 'odd', 'Even': 'even', '-N+3': '-n+3',
  };
  Object.entries(canonical).forEach(([argument, expected]) => assert.equal(canonicalNth(argument), expected, argument));
  ['', ' ', 'foo', '2 n', '- n', 'n+', '+', '2n+1 of .x', '2n+-1', '2n++1', '1.5n', 'n n', 'odd+1', null, 3]
    .forEach((argument) => assert.equal(canonicalNth(argument), null, JSON.stringify(argument)));
});

test('the terminal pseudo-element rides the selector, and the anchor is its own subject', () => {
  const cases = {
    '&::before': { compounds: [], pseudo: 'before' },
    '&::after': { compounds: [], pseudo: 'after' },
    '.quote::after': { compounds: [c('descendant', 'class', '.quote')], pseudo: 'after' },
    'p::before': { compounds: [c('descendant', 'tag', 'p')], pseudo: 'before' },
    '> d-text::before': { compounds: [c('child', 'type', 'd-text')], pseudo: 'before' },
    '> *::before': { compounds: [c('child', 'universal', '*')], pseudo: 'before' },
    '> p + p::after': { compounds: [c('child', 'tag', 'p'), c('next', 'tag', 'p')], pseudo: 'after' },
    '> d-text:has(a)::before': {
      compounds: [c('child', 'type', 'd-text', [has([c('descendant', 'tag', 'a')])])],
      pseudo: 'before',
    },
    '> d-text .link::before': {
      compounds: [c('child', 'type', 'd-text'), c('descendant', 'class', '.link')],
      pseudo: 'before',
    },
    // A position before the box; the qualified anchor's own box carries
    // the anchor compound, the bare anchor's none.
    '> *:not(:last-child)::after': {
      compounds: [c('child', 'universal', '*', [not(null, null, [at('last-child')])])],
      pseudo: 'after',
    },
    '&:first-child::before': { compounds: [anchor([at('first-child')])], pseudo: 'before' },
    '&:first-child > *:not(:last-child)::after': {
      compounds: [anchor([at('first-child')]), c('child', 'universal', '*', [not(null, null, [at('last-child')])])],
      pseudo: 'after',
    },
    // A state, a class or an attribute before the box.
    '&:hover::before': { compounds: [anchor([at('hover')])], pseudo: 'before' },
    'a[href^="http"]::after': { compounds: [c('descendant', 'tag', 'a', [attr('href', '^=', 'http')])], pseudo: 'after' },
    'li.active::before': { compounds: [c('descendant', 'tag', 'li', [cls('active')])], pseudo: 'before' },
    '*:is(h1, h2)::before': { compounds: [c('descendant', 'universal', '*', [is(member('tag', 'h1'), member('tag', 'h2'))])], pseudo: 'before' },
  };
  Object.entries(cases).forEach(([selector, record]) => {
    assert.deepEqual(parseSelector(selector), record, selector);
    assert.equal(printSelector(record), selector);
    assert.equal(isCanonicalSelector(selector), true);
  });
  assert.equal(canonicalSelector('  &::before '), '&::before');
  assert.equal(canonicalSelector('>d-text::after\n'), '> d-text::after');
  assert.equal(canonicalSelector('>d-text:has( a )::before'), '> d-text:has(a)::before');
  // Any case, and the legacy single colon at the end.
  assert.equal(canonicalSelector('&::Before'), '&::before');
  assert.equal(canonicalSelector('p::AFTER'), 'p::after');
  assert.equal(canonicalSelector('p:before'), 'p::before');
  assert.equal(canonicalSelector('&:after'), '&::after');
  assert.equal(canonicalSelector('&:hover:before'), '&:hover::before');
  assert.equal(isCanonicalSelector(' &::before'), false);
  assert.equal(isCanonicalSelector('p:before'), false);
  // Any name the browser spells, the legacy single colon for the four it
  // still names; whether a site knows a name is the roster's verdict.
  assert.equal(canonicalSelector('li::marker'), 'li::marker');
  assert.equal(canonicalSelector('input::placeholder'), 'input::placeholder');
  assert.equal(canonicalSelector('&::Selection'), '&::selection');
  assert.equal(canonicalSelector('> d-text::first-letter'), '> d-text::first-letter');
  assert.equal(canonicalSelector('p:first-line'), 'p::first-line');
  assert.equal(canonicalSelector('&::backdrop'), '&::backdrop');
  assert.equal(canonicalSelector('&::markr'), '&::markr');
  assert.deepEqual(parseSelector('li:hover::marker'), { compounds: [c('descendant', 'tag', 'li', [at('hover')])], pseudo: 'marker' });
  assert.deepEqual(parseSelector('p:marker'), { compounds: [c('descendant', 'tag', 'p', [at('marker')])], pseudo: null }, 'a single colon names a pseudo-class but for the legacy four');
  // Terminal, glued to a host, a bare name, never inside a qualifier; the
  // anchor stands nowhere but as its own pseudo's subject; the legacy
  // single-colon spelling is never a pseudo-class.
  [
    '.quote::part(x)', '&::slotted(span)', '&::1x', '&::-webkit-x', 'li::marker .x', 'li::marker::before', 'p:first-letter > a',
    '::before', '&', '& > p', '> d-text &::before', '> d-text::before .x',
    '> d-text::before > *', '> d-text:has(a::before)', '> *:not(d-image::after)', '> d-text:has(&::before)',
    '.quote::before::after', '> d-text ::before', '& ::before', 'p:before .x', 'p:after > *', '> *:not(:before)',
    '> d-text:has(a:after)', '&:before:hover', 'p::before::after', ':before',
  ].forEach((selector) => assert.equal(parseSelector(selector), null, JSON.stringify(selector)));
});

test('lenient reads canonicalize; the malformed spellings never parse', () => {
  assert.equal(canonicalSelector('>*'), '> *');
  assert.equal(canonicalSelector('  .cls>*  '), '.cls > *');
  assert.equal(canonicalSelector('>d-text   .link'), '> d-text .link');
  assert.equal(canonicalSelector('>p+p'), '> p + p');
  assert.equal(canonicalSelector('> d-text:has( a )'), '> d-text:has(a)');
  assert.equal(canonicalSelector('>d-text:has(+d-image)'), '> d-text:has(+ d-image)');
  assert.equal(canonicalSelector('> d-text:not(.featured):has(a)'), '> d-text:has(a):not(.featured)');
  assert.equal(canonicalSelector('> *:not(d-video):not( d-image )'), '> *:not(d-image):not(d-video)');
  assert.equal(isCanonicalSelector('>*'), false);
  assert.equal(isCanonicalSelector('> d-text:not(.featured):has(a)'), false);
  // Positions sort among the qualifiers by printed form; the `An+B`
  // canonicalizes; the anchor's whitespace is free like any other.
  assert.equal(canonicalSelector('> *:last-child:first-child'), '> *:first-child:last-child');
  assert.equal(canonicalSelector('> *:not( :last-child ):has(a)'), '> *:has(a):not(:last-child)');
  assert.equal(canonicalSelector('li:nth-child( 2n + 1 )'), 'li:nth-child(2n+1)');
  assert.equal(canonicalSelector('> *:nth-child(+1n+0)'), '> *:nth-child(n)');
  assert.equal(canonicalSelector('> *:nth-child(0n+3)'), '> *:nth-child(3)');
  assert.equal(canonicalSelector('> *:nth-child(-n + 3)'), '> *:nth-child(-n+3)');
  assert.equal(canonicalSelector('> *:nth-child(2n -1)'), '> *:nth-child(2n-1)');
  assert.equal(canonicalSelector(' &:last-child:first-child '), '&:first-child:last-child');
  assert.equal(canonicalSelector('&:not(:last-child)>*'), '&:not(:last-child) > *');
  assert.equal(isCanonicalSelector('> *:last-child:first-child'), false);
  assert.equal(isCanonicalSelector('li:nth-child(2n + 1)'), false);
  assert.equal(isCanonicalSelector('&:first-child'), true);
  // D. Case: tags, types, pseudo-class, pseudo-element and attribute
  // names in any case print lowercase; classes and attribute values
  // keep theirs. Spacing around combinators and inside brackets is free.
  assert.equal(canonicalSelector('Li:hover > UL'), 'li:hover > ul');
  assert.equal(canonicalSelector('LI>UL'), 'li > ul');
  assert.equal(canonicalSelector('a:HOVER'), 'a:hover');
  assert.equal(canonicalSelector('li>ul'), 'li > ul');
  assert.equal(canonicalSelector('D-Text .Link'), 'd-text .Link');
  assert.equal(canonicalSelector('&:HOVER > UL'), '&:hover > ul');
  assert.equal(canonicalSelector('p:HOVER:Active'), 'p:active:hover');
  assert.equal(canonicalSelector('li:NTH-CHILD(2N+1)'), 'li:nth-child(2n+1)');
  assert.equal(canonicalSelector('> *:nth-child(2N)'), '> *:nth-child(2n)');
  assert.equal(canonicalSelector('> *:nth-child(ODD)'), '> *:nth-child(odd)');
  assert.equal(canonicalSelector('P'), 'p');
  assert.equal(canonicalSelector('.Card'), '.Card');
  assert.equal(canonicalSelector('[TYPE=Checkbox]'), '*[type="Checkbox"]');
  assert.equal(canonicalSelector('[Data-X]'), '*[data-x]');
  assert.equal(canonicalSelector('[ type = "checkbox" I ]'), '*[type="checkbox" i]');
  assert.equal(isCanonicalSelector('Li:hover > UL'), false);
  // G. Classes: `*.cls` is `.cls`; the classes of a part or universal
  // compound pool into the subject, the first by name; on a tag they
  // sort among the qualifiers.
  assert.equal(canonicalSelector('> *.cls'), '> .cls');
  assert.equal(canonicalSelector('*.a.b'), '.a.b');
  assert.equal(canonicalSelector('.b.a'), '.a.b');
  assert.equal(canonicalSelector('li.b.a'), 'li.a.b');
  assert.equal(canonicalSelector('&:first-child.x'), '&.x:first-child');
  assert.equal(canonicalSelector(':not(*.x)'), '*:not(.x)');
  assert.equal(canonicalSelector('li:hover[aria-current="page"].active'), 'li.active:hover[aria-current="page"]');
  // H. Attributes: ident values quote, single quotes become double, the
  // escapes decode and re-encode, the flag follows an ident after
  // whitespace only.
  assert.equal(canonicalSelector('input[type=checkbox]'), 'input[type="checkbox"]');
  assert.equal(canonicalSelector("input[type='checkbox']"), 'input[type="checkbox"]');
  assert.equal(canonicalSelector('[aria-expanded="true"] > ul'), '*[aria-expanded="true"] > ul');
  assert.equal(canonicalSelector('li[aria-current=page]'), 'li[aria-current="page"]');
  assert.equal(canonicalSelector('a[target=_blank]'), 'a[target="_blank"]');
  assert.equal(canonicalSelector('[x]'), '*[x]');
  assert.equal(canonicalSelector('[lang|=en]'), '*[lang|="en"]');
  assert.equal(canonicalSelector("a[title='say \"hi\"']"), 'a[title="say \\"hi\\""]');
  assert.equal(canonicalSelector("[x='a\\'b']"), '*[x="a\'b"]');
  assert.equal(canonicalSelector('[x="\\41 bc"]'), '*[x="Abc"]');
  assert.equal(canonicalSelector('[x="a\\\nb"]'), '*[x="ab"]');
  assert.equal(canonicalSelector('[x=a i]'), '*[x="a" i]');
  assert.equal(canonicalSelector('[x=ai]'), '*[x="ai"]');
  assert.equal(canonicalSelector('[x="a"i]'), '*[x="a" i]');
  assert.equal(canonicalSelector('[x="a" S]'), '*[x="a" s]');
  assert.equal(canonicalSelector('[ x ]'), '*[x]');
  // The implied subject: the universal in a chain, nothing in `:not()`.
  assert.equal(canonicalSelector(':hover > ul'), '*:hover > ul');
  assert.equal(canonicalSelector(':hover'), '*:hover');
  assert.equal(canonicalSelector(':first-child'), '*:first-child');
  assert.equal(canonicalSelector('> :not(:last-child)'), '> *:not(:last-child)');
  assert.equal(canonicalSelector(':has(:focus-within)'), '*:has(*:focus-within)');
  assert.equal(canonicalSelector(':not(*:first-child)'), '*:not(:first-child)');
  assert.equal(canonicalSelector('d-text :has(a)'), 'd-text *:has(a)');
  assert.equal(canonicalSelector('li :hover'), 'li *:hover');
  assert.equal(canonicalSelector(':not([hidden])'), '*:not([hidden])');
  assert.equal(canonicalSelector('li:hover>ul'), 'li:hover > ul');
  // `:is()`: members sorted by printed form and distinct, `:where()` read
  // as `:is()`, the implied subject the universal in a chain and nothing
  // in a member, a single member merged into its host (its subject taken
  // when the host's is `*` or implied, a class member pooled, its
  // qualifiers sorted in), the qualifier sorted by its printed form
  // among the host's.
  assert.equal(canonicalSelector(':is(h1, h2)'), '*:is(h1, h2)');
  assert.equal(canonicalSelector(':is(.b, .a)'), '*:is(.a, .b)');
  assert.equal(canonicalSelector(':is(h2,h1,h2)'), '*:is(h1, h2)');
  assert.equal(canonicalSelector(':IS( H1 , h2 )'), '*:is(h1, h2)');
  assert.equal(canonicalSelector(':where(h1, h2)'), '*:is(h1, h2)');
  assert.equal(canonicalSelector('.menu-item:is(:hover, :focus-within) > ul'), '.menu-item:is(:focus-within, :hover) > ul');
  assert.equal(canonicalSelector('> *:is(p, div)'), '> *:is(div, p)');
  assert.equal(canonicalSelector(':is(a, :not(.b))'), '*:is(:not(.b), a)');
  assert.equal(canonicalSelector('> d-text:has(:is(a, button))'), '> d-text:has(*:is(a, button))');
  assert.equal(canonicalSelector('a:where(.x)'), 'a.x');
  assert.equal(canonicalSelector('*:is(h1)'), 'h1');
  assert.equal(canonicalSelector(':is(h1)'), 'h1');
  assert.equal(canonicalSelector('> *:is(p)'), '> p');
  assert.equal(canonicalSelector(':where(.x)'), '.x');
  assert.equal(canonicalSelector('*:is(h1):is(.x)'), 'h1.x');
  assert.equal(canonicalSelector('*:is(.b):is(.a)'), '.a.b');
  assert.equal(canonicalSelector('h1:is(h1)'), 'h1');
  assert.equal(canonicalSelector('h1:is(h1.x)'), 'h1.x');
  assert.equal(canonicalSelector('a:is(:hover)'), 'a:hover');
  assert.equal(canonicalSelector('d-text:is(d-text)'), 'd-text');
  assert.equal(canonicalSelector('&:is(.x)'), '&.x');
  assert.equal(canonicalSelector('&:where(.open) > ul'), '&.open > ul');
  assert.equal(canonicalSelector('li:is(.a, .b):hover'), 'li:hover:is(.a, .b)');
  assert.equal(canonicalSelector(':is(h1, h1, h2)'), '*:is(h1, h2)');
  assert.equal(isCanonicalSelector('*:is(h1, h2)'), true);
  assert.equal(isCanonicalSelector('.menu-item:is(:focus-within, :hover) > ul'), true);
  assert.equal(isCanonicalSelector(':is(h1, h2)'), false);
  assert.equal(isCanonicalSelector('*:is(h2, h1)'), false);
  assert.equal(isCanonicalSelector('*:where(h1, h2)'), false);
  assert.equal(isCanonicalSelector('*:is(h1)'), false);
  assert.equal(isCanonicalSelector('*:is(h1,h2)'), false);
  [
    '', '>', '*', '::>', '> >', 'p >', '> p +', 'd-text *', '&:first-child *', '2x', 'd-',
    '+ p', '~ p', '> d-text:has(:has(a))', '> p:has(a:not(:has(b)))', '> p:not(:not(p))', '> *:not(*)', ':not(*)', '> p:not(> p)',
    '> p:not(p + p)', '> d-text:has()', '> p:not()', '.a::>', 'p,', 'p&',
    // I. A selector list is the digester's to fold into `:is()`: the
    // grammar refuses the comma at the top level, with every functional
    // pseudo-class it does not own.
    'div, p', 'h1, h2, h3', 'a:hover, a:focus', ':lang(en)', ':dir(rtl)', 'p:hover()',
    // `:is()`: never inside `:not()` (`:not(a):not(b)` is the spelling)
    // or inside `:is()`, through `:has()` and `:not()` too; never the
    // anchor; no empty member, no `*` alone, no chain, no box; a member
    // subject against the host's; the names need their parens.
    ':not(:is(a, b))', 'li:not(:is(a, b))', ':is(:is(a))', ':is(a:not(:is(b)))', ':is(a:has(:is(b, c)))', ':not(a:has(:is(b, c)))',
    ':is(:where(a))', ':where(:is(a))', ':is(&)', ':is(&:hover, a)', ':is()', ':where()', ':is(a,)', ':is(,a)', ':is(a,,b)', ':is(*)',
    ':is(*, a)', 'h1:is(h2)', 'h1:is(d-text)', '&:is(h1)', ':is(a b)', ':is(> a)', ':is(a::before)', '> d-text:has(:is(a:has(b)))',
    ':is', ':where', 'p:is',
    // Positions: the `An+B` whitespace free but around B's sign; the
    // `of` form refused; a functional name needs its parens.
    '> *:nth-child()', '> *:nth-child(2n+1 of .x)', '> *:nth-child(foo)',
    '> *:first-child()', '> *:nth-child', '> *:nth-child(2 n)', '> *:nth-child(- n)',
    '> *::before:first-child', ':has', ':not', 'p:has', ':class', ':attribute', '&:',
    // Attributes: a value is an ident or a string, after an operator,
    // one flag after the value, the brackets closed.
    '[x=1]', '[x=]', '[=x]', '[x="unterminated', '[x y]', '[x="a" x]', '[x="a"i i]', '[x="a" i s]', '[x i]', '[]', '[x',
    '[x="a\nb"]', '[x=="a"]', '[x="a"]]', '[.x]', '[x=a.b]',
    // The anchor: first, qualified, top level, descent behind it.
    '&:first-child + p', '&:first-child ~ *', '&.open + p', '> d-text:has(&:first-child)',
    '> *:not(&:first-child)', '&:first-child & > p', '> &:first-child', 'p &:first-child', '&:first-child:has(:has(a))',
    '&:first-child ::before', '& + *', '& > ul',
  ].forEach((selector) => assert.equal(parseSelector(selector), null, JSON.stringify(selector)));
});

test('the relations: three canonical prints and three refusals', () => {
  assert.equal(canonicalSelector('> p + p'), '> p + p');
  assert.equal(canonicalSelector('> *:not(d-image)'), '> *:not(d-image)');
  assert.equal(canonicalSelector('> d-text:not(.featured):has(a)'), '> d-text:has(a):not(.featured)');
  assert.equal(parseSelector('+ p'), null);
  assert.equal(parseSelector('> *:not(*)'), null);
  assert.equal(parseSelector('> d-text:has(:has(a))'), null);
});

test('splitKey splits at the first :: and admits the canonical spelling only', () => {
  assert.deepEqual(splitKey('container:x'), { root: 'container:x', selector: null });
  assert.deepEqual(splitKey('container:x::> *'), { root: 'container:x', selector: '> *' });
  assert.deepEqual(splitKey('block(text,1)::.quote > *'), { root: 'block(text,1)', selector: '.quote > *' });
  assert.deepEqual(splitKey('container:x::> d-text:has(a):not(.featured)'), {
    root: 'container:x',
    selector: '> d-text:has(a):not(.featured)',
  });
  assert.equal(splitKey('container:x::>'), null);
  assert.equal(splitKey('container:x::.a::>'), null);
  assert.equal(splitKey('container:x::>*'), null);
  assert.equal(splitKey('container:x::> d-text:not(.featured):has(a)'), null);
  assert.equal(splitKey(null), null);
  // A pseudo-element key still splits at its FIRST "::".
  assert.deepEqual(splitKey('container:x::&::before'), { root: 'container:x', selector: '&::before' });
  assert.deepEqual(splitKey('block(text,1)::.quote::after'), { root: 'block(text,1)', selector: '.quote::after' });
  assert.deepEqual(splitKey('::&::after'), { root: '', selector: '&::after' });
  assert.equal(splitKey('container:x::::before'), null);
  assert.equal(splitKey('container:x::&'), null);
  assert.equal(splitKey('container:x:: &::before'), null);
  assert.equal(splitKey('container:x::.quote::before::after'), null);
  // The qualified anchor and a position carry no `::` of their own.
  assert.deepEqual(splitKey('container:x::&:first-child'), { root: 'container:x', selector: '&:first-child' });
  assert.deepEqual(splitKey('container:x::&:not(:last-child) > *'), { root: 'container:x', selector: '&:not(:last-child) > *' });
  assert.deepEqual(splitKey('container:x::&:first-child::before'), { root: 'container:x', selector: '&:first-child::before' });
  assert.deepEqual(splitKey('block(text,1)::li:nth-child(2n+1)'), { root: 'block(text,1)', selector: 'li:nth-child(2n+1)' });
  assert.equal(splitKey('container:x::> *:last-child:first-child'), null);
  assert.equal(splitKey('container:x::li:nth-child(2n + 1)'), null);
  // States, classes and attributes are selector text; a `::` inside a
  // quoted value is no split; the lenient spellings are not keys.
  assert.deepEqual(splitKey('container:x::li:hover > ul'), { root: 'container:x', selector: 'li:hover > ul' });
  assert.deepEqual(splitKey('container:x::&.open > ul'), { root: 'container:x', selector: '&.open > ul' });
  assert.deepEqual(splitKey('container:x::*[aria-expanded="true"] > ul'), { root: 'container:x', selector: '*[aria-expanded="true"] > ul' });
  assert.deepEqual(splitKey('container:x::a[href^="http"]::after'), { root: 'container:x', selector: 'a[href^="http"]::after' });
  assert.deepEqual(splitKey('container:x::*[data-x="a::b"]'), { root: 'container:x', selector: '*[data-x="a::b"]' });
  assert.equal(splitKey('container:x::Li:hover > UL'), null);
  assert.equal(splitKey('container:x::[aria-expanded="true"] > ul'), null);
  assert.equal(splitKey('container:x::input[type=checkbox]'), null);
  assert.equal(splitKey('container:x::li:hover.active'), null);
  // `:is()` in a key: the canonical spelling only (sorted members, no
  // `:where()`, no single member); a top-level comma is no key.
  assert.deepEqual(splitKey('container:x::*:is(h1, h2)'), { root: 'container:x', selector: '*:is(h1, h2)' });
  assert.deepEqual(splitKey('container:x::.menu-item:is(:focus-within, :hover) > ul'), { root: 'container:x', selector: '.menu-item:is(:focus-within, :hover) > ul' });
  assert.equal(splitKey('container:x::*:is(h2, h1)'), null);
  assert.equal(splitKey('container:x::*:where(h1, h2)'), null);
  assert.equal(splitKey('container:x::*:is(h1)'), null);
  assert.equal(splitKey('container:x::h1, h2'), null);
});

test('the compound reads', () => {
  assert.equal(isPartFirst('.cls > *'), true);
  assert.equal(isPartFirst('> .cls'), true);
  assert.equal(isPartFirst('.cls:not(.x)'), true);
  assert.equal(isPartFirst('> d-text .cls'), false);
  assert.equal(isPart('.cls'), true);
  assert.equal(isPart('.cls > *'), false);
  assert.equal(isPart('.cls:has(a)'), false);
  assert.equal(isChildren('> *'), true);
  assert.equal(isChildren('> * > *'), false);
  assert.equal(isChildren('> *:not(d-image)'), false);
  assert.equal(isTag('p'), true);
  assert.equal(isTag('> p'), false);
  assert.equal(isTag('p:has(a)'), false);
  assert.equal(reachesChildren('.cls'), false);
  assert.equal(reachesChildren('.cls > *'), true);
  assert.equal(reachesChildren('d-text'), true);
  assert.equal(reachesChildren('p ~ p'), true);
  assert.equal(reachesChildren('p:has(> a)'), false);
  assert.deepEqual(selectorClasses('> d-text .s-a3'), ['.s-a3']);
  assert.deepEqual(selectorClasses('> d-text:has(.s-a3):not(.featured) .link'), ['.s-a3', '.featured', '.link']);
  assert.deepEqual(selectorClasses(null), []);
  // A pseudo behind the one compound makes it a rule of its own: not
  // the part, not the tag tier, not the children.
  assert.equal(isPart('.cls::before'), false);
  assert.equal(isPartFirst('.cls::before'), true);
  assert.equal(isTag('p::after'), false);
  assert.equal(isChildren('> *::before'), false);
  assert.equal(isPart('&::before'), false);
  assert.equal(isPartFirst('&::before'), false);
  assert.equal(isTag('&::before'), false);
  assert.equal(isChildren('&::before'), false);
  assert.equal(reachesChildren('&::before'), false);
  assert.equal(reachesChildren('> d-text::before'), true);
  assert.deepEqual(selectorClasses('.quote::after'), ['.quote']);
  assert.equal(parseSelector('> d-text::before').pseudo, 'before');
  assert.equal(parseSelector('> d-text').pseudo, null);
  // A position makes a rule of its own; the qualified anchor is the
  // owner: its part is part-first, its children are not the tier.
  assert.equal(isChildren('> *:not(:last-child)'), false);
  assert.equal(isPart('.cls:first-child'), false);
  assert.equal(isTag('p:nth-child(2)'), false);
  assert.equal(isPartFirst('.cls:first-child'), true);
  assert.equal(isPartFirst('&:first-child .cls'), true);
  assert.equal(isPartFirst('&:first-child > .cls::before'), true);
  assert.equal(isPartFirst('&:first-child'), false);
  assert.equal(isPartFirst('&:first-child p'), false);
  assert.equal(isChildren('&:not(:last-child) > *'), false);
  assert.equal(isPart('&:first-child .cls'), false);
  assert.equal(isTag('&:first-child p'), false);
  assert.equal(reachesChildren('&:first-child'), false);
  assert.equal(reachesChildren('&:first-child > *'), true);
  assert.equal(reachesChildren('&:has(> a)'), false);
  assert.deepEqual(selectorClasses('> *:not(:last-child)'), []);
  assert.deepEqual(selectorClasses('&:not(.x) > *:first-child'), ['.x']);
  assert.deepEqual(selectorClasses('&:first-child > d-text:not(.featured):nth-child(2n) .link'), ['.featured', '.link']);
  assert.deepEqual(parseSelector('&:first-child').compounds, [anchor([at('first-child')])]);
  // A state, class or attribute makes a rule of its own; a class
  // qualifying a tag is the tag's compound, not a part first; the class
  // qualifiers count among the selector's classes in print order; a
  // qualified universal by descent reaches like a tag.
  assert.equal(isTag('a:hover'), false);
  assert.equal(isTag('a.button'), false);
  assert.equal(isTag('a[href]'), false);
  assert.equal(isPart('.a.b'), false);
  assert.equal(isPart('.cls:hover'), false);
  assert.equal(isChildren('> *:hover'), false);
  assert.equal(isChildren('> *[hidden]'), false);
  assert.equal(isPartFirst('.a.b'), true);
  assert.equal(isPartFirst('.card:hover img'), true);
  assert.equal(isPartFirst('li.active > ul'), false);
  assert.equal(isPartFirst('&.open .menu'), true);
  assert.equal(isPartFirst('&.open'), false);
  assert.equal(isPartFirst('*[x] .cls'), false);
  assert.equal(reachesChildren('*:hover'), false);
  assert.equal(reachesChildren('*[aria-expanded="true"] > ul'), true);
  assert.equal(reachesChildren('li:hover > ul'), true);
  assert.equal(reachesChildren('&:hover'), false);
  assert.equal(reachesChildren('&.open .menu'), false);
  assert.deepEqual(selectorClasses('li.active > ul'), ['.active']);
  assert.deepEqual(selectorClasses('.a.b'), ['.a', '.b']);
  assert.deepEqual(selectorClasses('&.open .menu'), ['.open', '.menu']);
  assert.deepEqual(selectorClasses('> d-text.featured:not(.a.b):has(li.c) .link'), ['.featured', '.c', '.a', '.b', '.link']);
  assert.deepEqual(selectorClasses('li:hover[aria-current="page"] > ul'), []);
  assert.deepEqual(parseSelector('&.open > ul').compounds[0], anchor([cls('open')]));
  // `:is()` makes a rule of its own; the host's subject decides the
  // part-first read; a type among the members reaches as the type does
  // (the fold of `d-text, d-image` keeps `d-text`'s reach); the members'
  // classes count among the selector's in print order.
  assert.equal(isTag('*:is(h1, h2)'), false);
  assert.equal(isChildren('> *:is(div, p)'), false);
  assert.equal(isPart('*:is(.a, .b)'), false);
  assert.equal(isPartFirst('*:is(.a, .b)'), false);
  assert.equal(isPartFirst('.menu-item:is(:focus-within, :hover) > ul'), true);
  assert.equal(reachesChildren('*:is(h1, h2)'), false);
  assert.equal(reachesChildren('> *:is(div, p)'), true);
  assert.equal(reachesChildren('*:is(d-image, d-text)'), true);
  assert.equal(reachesChildren('*:is(a, d-text) .link'), true);
  assert.equal(reachesChildren('*:not(d-image)'), false);
  assert.equal(reachesChildren('*:is(a:has(d-text), b)'), false);
  assert.deepEqual(selectorClasses('*:is(.a, .b)'), ['.a', '.b']);
  assert.deepEqual(selectorClasses('*:is(.a, li.b):not(.c)'), ['.a', '.b', '.c']);
  assert.deepEqual(selectorClasses('.menu-item:is(:focus-within, :hover) > ul'), ['.menu-item']);
  assert.deepEqual(parseSelector('*:is(h1, h2) a').compounds[0], c('descendant', 'universal', '*', [is(member('tag', 'h1'), member('tag', 'h2'))]));
});

test('type compounds map to root addresses and back, and select by the stamp', () => {
  assert.equal(typeAddress('d-text'), 'block_type:text');
  assert.equal(typeAddress('d-formatted-text'), 'block_type:formatted_text');
  assert.equal(typeAddress('d-container'), 'kind:container');
  assert.equal(typeAddress('d-block'), 'kind:block');
  assert.equal(typeAddress('text'), null);
  assert.equal(typeName('block_type:formatted_text'), 'd-formatted-text');
  assert.equal(typeName('kind:container'), 'd-container');
  assert.equal(typeName('layout:x'), null);
  assert.equal(typeName('block_type:text::p'), null);
  assert.equal(typeSelector('d-text'), '[data-element="block_type:text"]');
  assert.equal(typeSelector('d-container'), '[data-element^="layout:"]');
  assert.equal(typeSelector('d-block'), ':is([data-element^="block_type:"], [data-element^="block_plugin:"])');
  assert.deepEqual(addressMembers('kind:field'), ['[data-element="kind:field"]']);
  assert.deepEqual(addressMembers('base:a'), []);
  assert.deepEqual(addressMembers('kind:region'), []);
});

test('prefix truncates the chain after a compound, without the pseudo', () => {
  // A state qualifier's compound: the targets the stamp lands on.
  assert.equal(prefix('li:hover > ul', 0), 'li:hover');
  assert.equal(prefix('li:hover > ul', 1), 'li:hover > ul');
  assert.equal(prefix('> d-text:hover a', 0), '> d-text:hover');
  assert.equal(prefix('> d-text:hover a', 1), '> d-text:hover a');
  // The qualified anchor alone is the owner.
  assert.equal(prefix('&:hover > ul', 0), '&:hover');
  assert.equal(prefix('&:hover > ul', 1), '&:hover > ul');
  // The pseudo never rides a prefix, the full chain included; a sibling
  // step keeps its lead; the spelling is canonical.
  assert.equal(prefix('> d-text:has(a:hover) .link::before', 0), '> d-text:has(a:hover)');
  assert.equal(prefix('> d-text:has(a:hover) .link::before', 1), '> d-text:has(a:hover) .link');
  assert.equal(prefix('> p + p:focus-within', 0), '> p');
  assert.equal(prefix('Li:HOVER > UL', 0), 'li:hover');
  // Out of range, the owner's own pseudo-element (no compounds), and
  // text outside the grammar.
  assert.equal(prefix('li:hover > ul', 2), null);
  assert.equal(prefix('li:hover > ul', -1), null);
  assert.equal(prefix('&::before', 0), null);
  assert.equal(prefix('div > > p', 0), null);
  // A state inside `:is()` on a non-terminal compound: the prefix is
  // that compound, `:is()` and all (the pose stamps the `.menu-item`s).
  assert.equal(prefix('.menu-item:is(:focus-within, :hover) > ul', 0), '.menu-item:is(:focus-within, :hover)');
  assert.equal(prefix('.menu-item:is(:focus-within, :hover) > ul', 1), '.menu-item:is(:focus-within, :hover) > ul');
});

test('under continues a rule by descent and lets the grammar refuse', () => {
  assert.equal(under('> d-text', '.link'), '> d-text .link');
  assert.equal(under('> d-text', '> *'), '> d-text > *');
  assert.equal(under('> d-text', 'p'), '> d-text p');
  assert.equal(under('a', 'p'), 'a p');
  assert.equal(under('> d-text:has(a)', '.link ~ .link'), '> d-text:has(a) .link ~ .link');
  // A class after a tag or the children continues by descent like any
  // compound (a markup class is selector text).
  assert.equal(under('a', '.cls'), 'a .cls');
  assert.equal(under('> *', '.cls'), '> * .cls');
  assert.equal(under(null, '.cls'), null);
  // A pseudo binds to the rule's landing: the anchor is the rule's own
  // targets, a compound's pseudo continues by descent, and a rule
  // already ending in a pseudo admits nothing after it.
  assert.equal(under('> d-text', '&::before'), '> d-text::before');
  assert.equal(under('.cls', '&::after'), '.cls::after');
  assert.equal(under('> *', '&::before'), '> *::before');
  assert.equal(under('> d-text:has(a)', '&::after'), '> d-text:has(a)::after');
  assert.equal(under('> d-text', '.link::before'), '> d-text .link::before');
  assert.equal(under('> d-text', 'p::after'), '> d-text p::after');
  assert.equal(under('a', '.cls::before'), 'a .cls::before');
  assert.equal(under('> d-text::before', '&::after'), null);
  assert.equal(under('> d-text::before', '.link'), null);
  assert.equal(under('&::before', 'p'), null);
  // The qualified anchor binds to the rule's landing: its qualifiers
  // sort in with the last compound's own, the chain behind it continues
  // by descent, and a position continues like any suffix.
  assert.equal(under('> d-text', '&:first-child'), '> d-text:first-child');
  assert.equal(under('> *', '&:nth-child(2n)'), '> *:nth-child(2n)');
  assert.equal(under('> d-text:has(a)', '&:not(:last-child) > *'), '> d-text:has(a):not(:last-child) > *');
  assert.equal(under('> d-text:not(.x)', '&:has(a)'), '> d-text:has(a):not(.x)');
  assert.equal(under('> d-text', '&:first-child .link::before'), '> d-text:first-child .link::before');
  assert.equal(under('> d-text', '&:first-child::after'), '> d-text:first-child::after');
  assert.equal(under('> d-text', '> *:not(:last-child)'), '> d-text > *:not(:last-child)');
  assert.equal(under('&:first-child', '.cls'), '&:first-child .cls');
  assert.equal(under('&:first-child', '> *'), '&:first-child > *');
  assert.equal(under('&:first-child', '&:last-child'), '&:first-child:last-child');
  assert.equal(under('&:first-child', '&::before'), '&:first-child::before');
  assert.equal(under('&:first-child > d-text', '.link'), '&:first-child > d-text .link');
  assert.equal(under('> d-text::before', '&:first-child'), null);
  assert.equal(under('a', '&:first-child .cls'), 'a:first-child .cls');
  assert.equal(under('&:first-child::before', '.cls'), null);
  // A state, a class and an attribute on the anchor bind the same way,
  // onto the rule's last compound, in with its own qualifiers; a class
  // onto a part pools into the part.
  assert.equal(under('li', '&:hover > ul'), 'li:hover > ul');
  assert.equal(under('li', '&.open > ul'), 'li.open > ul');
  assert.equal(under('> d-text', '&[aria-expanded="true"] a'), '> d-text[aria-expanded="true"] a');
  assert.equal(under('> d-text:has(a)', '&.featured:hover'), '> d-text.featured:has(a):hover');
  assert.equal(under('.cls', '&.x'), '.cls.x');
  assert.equal(under('.x', '&.a'), '.a.x');
  assert.equal(under('> *', '&.cls'), '> .cls');
  assert.equal(under('li:hover', '> ul'), 'li:hover > ul');
  assert.equal(under('&.open', '.menu'), '&.open .menu');
  // An `:is()` on the anchor binds like any qualifier; a single member
  // merges before the rebase (`&:is(.a)` is `&.a`, and `&:is(h1)` is no
  // suffix at all: a member subject against the anchor).
  assert.equal(under('> *', '&:is(.a, .b)'), '> *:is(.a, .b)');
  assert.equal(under('> d-text', '&:is(:focus-within, :hover) a'), '> d-text:is(:focus-within, :hover) a');
  assert.equal(under('> *', '&:is(.a)'), '> .a');
  assert.equal(under('h1', '&:is(h1)'), null);
  assert.equal(under('*:is(h1, h2)', 'a'), '*:is(h1, h2) a');
});
