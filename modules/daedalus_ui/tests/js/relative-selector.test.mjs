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
  canonicalSelector,
  isCanonicalSelector,
  isChildren,
  isPart,
  isPartFirst,
  isTag,
  parseSelector,
  printCompounds,
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

test('every selector shape parses and prints canonical', () => {
  const cases = {
    '.cls': [c('descendant', 'class', '.cls')],
    p: [c('descendant', 'tag', 'p')],
    '> *': [c('child', 'universal', '*')],
    '.cls > *': [c('descendant', 'class', '.cls'), c('child', 'universal', '*')],
    '> pb-text .link': [c('child', 'type', 'pb-text'), c('descendant', 'class', '.link')],
    'pb-container > * > *': [
      c('descendant', 'type', 'pb-container'),
      c('child', 'universal', '*'),
      c('child', 'universal', '*'),
    ],
    'p > *': [c('descendant', 'tag', 'p'), c('child', 'universal', '*')],
    '> p + p': [c('child', 'tag', 'p'), c('next', 'tag', 'p')],
    'p ~ p': [c('descendant', 'tag', 'p'), c('subsequent', 'tag', 'p')],
    '> p + *': [c('child', 'tag', 'p'), c('next', 'universal', '*')],
    '> pb-text .link ~ .link': [
      c('child', 'type', 'pb-text'),
      c('descendant', 'class', '.link'),
      c('subsequent', 'class', '.link'),
    ],
    '> pb-text:has(a)': [c('child', 'type', 'pb-text', [has([c('descendant', 'tag', 'a')])])],
    '> pb-text:has(+ pb-image)': [c('child', 'type', 'pb-text', [has([c('next', 'type', 'pb-image')])])],
    '> pb-container:has(> pb-image) > *': [
      c('child', 'type', 'pb-container', [has([c('child', 'type', 'pb-image')])]),
      c('child', 'universal', '*'),
    ],
    '> pb-text:has(a):has(img)': [
      c('child', 'type', 'pb-text', [has([c('descendant', 'tag', 'a')]), has([c('descendant', 'tag', 'img')])]),
    ],
    '> *:not(pb-image)': [c('child', 'universal', '*', [not('type', 'pb-image')])],
    '> *:not(pb-image):not(pb-video)': [
      c('child', 'universal', '*', [not('type', 'pb-image'), not('type', 'pb-video')]),
    ],
    '> pb-text:not(:has(a))': [
      c('child', 'type', 'pb-text', [not(null, null, [has([c('descendant', 'tag', 'a')])])]),
    ],
    '> pb-text:has(a):not(.featured)': [
      c('child', 'type', 'pb-text', [has([c('descendant', 'tag', 'a')]), not('class', '.featured')]),
    ],
    '.cls:not(.x)': [c('descendant', 'class', '.cls', [not('class', '.x')])],
  };
  Object.entries(cases).forEach(([selector, compounds]) => {
    assert.deepEqual(parseSelector(selector), { compounds, pseudo: null }, selector);
    assert.equal(printSelector({ compounds, pseudo: null }), selector);
    assert.equal(printCompounds(compounds), selector);
    assert.equal(isCanonicalSelector(selector), true);
  });
});

test('the terminal pseudo-element rides the selector, and the anchor is its own subject', () => {
  const cases = {
    '&::before': { compounds: [], pseudo: 'before' },
    '&::after': { compounds: [], pseudo: 'after' },
    '.quote::after': { compounds: [c('descendant', 'class', '.quote')], pseudo: 'after' },
    'p::before': { compounds: [c('descendant', 'tag', 'p')], pseudo: 'before' },
    '> pb-text::before': { compounds: [c('child', 'type', 'pb-text')], pseudo: 'before' },
    '> *::before': { compounds: [c('child', 'universal', '*')], pseudo: 'before' },
    '> p + p::after': { compounds: [c('child', 'tag', 'p'), c('next', 'tag', 'p')], pseudo: 'after' },
    '> pb-text:has(a)::before': {
      compounds: [c('child', 'type', 'pb-text', [has([c('descendant', 'tag', 'a')])])],
      pseudo: 'before',
    },
    '> pb-text .link::before': {
      compounds: [c('child', 'type', 'pb-text'), c('descendant', 'class', '.link')],
      pseudo: 'before',
    },
  };
  Object.entries(cases).forEach(([selector, record]) => {
    assert.deepEqual(parseSelector(selector), record, selector);
    assert.equal(printSelector(record), selector);
    assert.equal(isCanonicalSelector(selector), true);
  });
  assert.equal(canonicalSelector('  &::before '), '&::before');
  assert.equal(canonicalSelector('>pb-text::after\n'), '> pb-text::after');
  assert.equal(canonicalSelector('>pb-text:has( a )::before'), '> pb-text:has(a)::before');
  assert.equal(isCanonicalSelector(' &::before'), false);
  // Two names, terminal, glued to a host, never inside a qualifier; the
  // anchor stands nowhere but as its own pseudo's subject.
  [
    '::marker', '.quote::first-letter', '::before', '&', '& > p', '> pb-text &::before', '> pb-text::before .x',
    '> pb-text::before > *', '> pb-text:has(a::before)', '> *:not(pb-image::after)', '> pb-text:has(&::before)',
    '.quote::before::after', '> pb-text ::before', '& ::before', '&::Before', 'p:before',
  ].forEach((selector) => assert.equal(parseSelector(selector), null, JSON.stringify(selector)));
});

test('lenient whitespace canonicalizes; the retired and malformed spellings never parse', () => {
  assert.equal(canonicalSelector('>*'), '> *');
  assert.equal(canonicalSelector('  .cls>*  '), '.cls > *');
  assert.equal(canonicalSelector('>pb-text   .link'), '> pb-text .link');
  assert.equal(canonicalSelector('>p+p'), '> p + p');
  assert.equal(canonicalSelector('> pb-text:has( a )'), '> pb-text:has(a)');
  assert.equal(canonicalSelector('>pb-text:has(+pb-image)'), '> pb-text:has(+ pb-image)');
  assert.equal(canonicalSelector('> pb-text:not(.featured):has(a)'), '> pb-text:has(a):not(.featured)');
  assert.equal(canonicalSelector('> *:not(pb-video):not( pb-image )'), '> *:not(pb-image):not(pb-video)');
  assert.equal(isCanonicalSelector('>*'), false);
  assert.equal(isCanonicalSelector('> pb-text:not(.featured):has(a)'), false);
  [
    '', '>', '*', '::>', '.a .b', 'p .a', '> * .a', '> >', 'p >', '> p +', '.a.b', 'p:hover', 'div, p', '[x]', 'P', '2x', 'pb-',
    '+ p', '~ p', '> pb-text:has(:has(a))', '> p:has(a:not(:has(b)))', '> p:not(:not(p))', '> *:not(*)', '> p:not(> p)',
    '> p:not(p + p)', 'p + .cls', 'pb-text :has(a)', '> pb-text:has()', '> p:not()', '.a::>',
  ].forEach((selector) => assert.equal(parseSelector(selector), null, JSON.stringify(selector)));
});

test('the relations: three canonical prints and three refusals', () => {
  assert.equal(canonicalSelector('> p + p'), '> p + p');
  assert.equal(canonicalSelector('> *:not(pb-image)'), '> *:not(pb-image)');
  assert.equal(canonicalSelector('> pb-text:not(.featured):has(a)'), '> pb-text:has(a):not(.featured)');
  assert.equal(parseSelector('+ p'), null);
  assert.equal(parseSelector('> *:not(*)'), null);
  assert.equal(parseSelector('> pb-text:has(:has(a))'), null);
});

test('splitKey splits at the first :: and admits the canonical spelling only', () => {
  assert.deepEqual(splitKey('container:x'), { root: 'container:x', selector: null });
  assert.deepEqual(splitKey('container:x::> *'), { root: 'container:x', selector: '> *' });
  assert.deepEqual(splitKey('block(text,1)::.quote > *'), { root: 'block(text,1)', selector: '.quote > *' });
  assert.deepEqual(splitKey('container:x::> pb-text:has(a):not(.featured)'), {
    root: 'container:x',
    selector: '> pb-text:has(a):not(.featured)',
  });
  assert.equal(splitKey('container:x::>'), null);
  assert.equal(splitKey('container:x::.a::>'), null);
  assert.equal(splitKey('container:x::>*'), null);
  assert.equal(splitKey('container:x::> pb-text:not(.featured):has(a)'), null);
  assert.equal(splitKey(null), null);
  // A pseudo-element key still splits at its FIRST "::".
  assert.deepEqual(splitKey('container:x::&::before'), { root: 'container:x', selector: '&::before' });
  assert.deepEqual(splitKey('block(text,1)::.quote::after'), { root: 'block(text,1)', selector: '.quote::after' });
  assert.deepEqual(splitKey('::&::after'), { root: '', selector: '&::after' });
  assert.equal(splitKey('container:x::::before'), null);
  assert.equal(splitKey('container:x::&'), null);
  assert.equal(splitKey('container:x:: &::before'), null);
  assert.equal(splitKey('container:x::.quote::before::after'), null);
});

test('the compound reads', () => {
  assert.equal(isPartFirst('.cls > *'), true);
  assert.equal(isPartFirst('> .cls'), true);
  assert.equal(isPartFirst('.cls:not(.x)'), true);
  assert.equal(isPartFirst('> pb-text .cls'), false);
  assert.equal(isPart('.cls'), true);
  assert.equal(isPart('.cls > *'), false);
  assert.equal(isPart('.cls:has(a)'), false);
  assert.equal(isChildren('> *'), true);
  assert.equal(isChildren('> * > *'), false);
  assert.equal(isChildren('> *:not(pb-image)'), false);
  assert.equal(isTag('p'), true);
  assert.equal(isTag('> p'), false);
  assert.equal(isTag('p:has(a)'), false);
  assert.equal(reachesChildren('.cls'), false);
  assert.equal(reachesChildren('.cls > *'), true);
  assert.equal(reachesChildren('pb-text'), true);
  assert.equal(reachesChildren('p ~ p'), true);
  assert.equal(reachesChildren('p:has(> a)'), false);
  assert.deepEqual(selectorClasses('> pb-text .s-a3'), ['.s-a3']);
  assert.deepEqual(selectorClasses('> pb-text:has(.s-a3):not(.featured) .link'), ['.s-a3', '.featured', '.link']);
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
  assert.equal(reachesChildren('> pb-text::before'), true);
  assert.deepEqual(selectorClasses('.quote::after'), ['.quote']);
  assert.equal(parseSelector('> pb-text::before').pseudo, 'before');
  assert.equal(parseSelector('> pb-text').pseudo, null);
});

test('type compounds map to root addresses and back, and select by the stamp', () => {
  assert.equal(typeAddress('pb-text'), 'block_type:text');
  assert.equal(typeAddress('pb-formatted-text'), 'block_type:formatted_text');
  assert.equal(typeAddress('pb-container'), 'kind:container');
  assert.equal(typeAddress('pb-block'), 'kind:block');
  assert.equal(typeAddress('text'), null);
  assert.equal(typeName('block_type:formatted_text'), 'pb-formatted-text');
  assert.equal(typeName('kind:container'), 'pb-container');
  assert.equal(typeName('layout:x'), null);
  assert.equal(typeName('block_type:text::p'), null);
  assert.equal(typeSelector('pb-text'), '[data-element="block_type:text"]');
  assert.equal(typeSelector('pb-container'), '[data-element^="layout:"]');
  assert.equal(typeSelector('pb-block'), ':is([data-element^="block_type:"], [data-element^="block_plugin:"])');
  assert.deepEqual(addressMembers('kind:field'), ['[data-element="kind:field"]']);
  assert.deepEqual(addressMembers('base:a'), []);
  assert.deepEqual(addressMembers('kind:region'), []);
});

test('under continues a rule by descent and lets the grammar refuse', () => {
  assert.equal(under('> pb-text', '.link'), '> pb-text .link');
  assert.equal(under('> pb-text', '> *'), '> pb-text > *');
  assert.equal(under('> pb-text', 'p'), '> pb-text p');
  assert.equal(under('a', 'p'), 'a p');
  assert.equal(under('> pb-text:has(a)', '.link ~ .link'), '> pb-text:has(a) .link ~ .link');
  // A class after a tag or the children, and a second lead, are outside
  // the grammar: the suffix lands nowhere under that rule.
  assert.equal(under('a', '.cls'), null);
  assert.equal(under('> *', '.cls'), null);
  assert.equal(under(null, '.cls'), null);
  // A pseudo binds to the rule's landing: the anchor is the rule's own
  // targets, a compound's pseudo continues by descent, and a rule
  // already ending in a pseudo admits nothing after it.
  assert.equal(under('> pb-text', '&::before'), '> pb-text::before');
  assert.equal(under('.cls', '&::after'), '.cls::after');
  assert.equal(under('> *', '&::before'), '> *::before');
  assert.equal(under('> pb-text:has(a)', '&::after'), '> pb-text:has(a)::after');
  assert.equal(under('> pb-text', '.link::before'), '> pb-text .link::before');
  assert.equal(under('> pb-text', 'p::after'), '> pb-text p::after');
  assert.equal(under('a', '.cls::before'), null);
  assert.equal(under('> pb-text::before', '&::after'), null);
  assert.equal(under('> pb-text::before', '.link'), null);
  assert.equal(under('&::before', 'p'), null);
});
