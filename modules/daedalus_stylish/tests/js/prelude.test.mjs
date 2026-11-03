/**
 * Self-asserting tests for the row prelude spelling (prelude.js).
 *
 * Run directly (`node tests/js/prelude.test.mjs`) or via JsSuitesTest in
 * the PHP tier. Locks the nested-rule prelude every chrome row spells
 * (the element document, the variant document and the satellite read
 * one pure function over the canonical selector and the guide's
 * condition wrappers): every condition type's wrapper, a compound
 * (wrappers first in compound order, then `&` with its states), a
 * position-qualified row, a generated box, a chain, a rule row, and the parts a renderer
 * chips. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  COMBINATOR,
  TEXT,
  WRAPPER,
  prelude,
  preludeParts,
} from '../../js/src/code/prelude.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// The condition table as ClientStyleGuide serves it (declaration order is
// the canonical axis order: state, structural, width, scheme, motion),
// the wrappers spelled as the condition type plugins declare them.
const guide = {
  conditions: {
    hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
    focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
    narrow: { label: 'Narrow', wrapper: '@container (max-width: 640px)', axis: 'width', band: { max: 640 } },
    medium: { label: 'Medium', wrapper: '@container (min-width: 641px) and (max-width: 1024px)', axis: 'width', band: { min: 641, max: 1024 } },
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme', preference: { feature: 'prefers-color-scheme', value: 'dark' } },
    'reduced-motion': { label: 'Reduced motion', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion', preference: { feature: 'prefers-reduced-motion', value: 'reduce' } },
  },
};

test('the element itself: `&` at the default condition, `&` with the state glued under a state', () => {
  assert.equal(prelude(null, 'default', guide), '&');
  assert.equal(prelude('', 'default', guide), '&');
  assert.equal(prelude(null), '&');
  assert.equal(prelude(null, 'hover', guide), '&:hover');
  assert.equal(prelude(null, 'focus', guide), '&:focus');
});

test('every wrapper kind spells as the condition type declares it: a state glues, an at-rule leads', () => {
  assert.equal(prelude(null, 'narrow', guide), '@container (max-width: 640px) &');
  assert.equal(prelude(null, 'medium', guide), '@container (min-width: 641px) and (max-width: 1024px) &');
  assert.equal(prelude(null, 'dark', guide), '@media (prefers-color-scheme: dark) &');
  assert.equal(prelude(null, 'reduced-motion', guide), '@media (prefers-reduced-motion: reduce) &');
});

test('a compound prints its wrappers first in compound order, then `&` with every state glued in order', () => {
  assert.equal(prelude(null, 'hover+narrow', guide), '@container (max-width: 640px) &:hover');
  assert.equal(prelude(null, 'narrow+dark', guide), '@container (max-width: 640px) @media (prefers-color-scheme: dark) &');
  assert.equal(prelude(null, 'hover+narrow+dark', guide), '@container (max-width: 640px) @media (prefers-color-scheme: dark) &:hover');
});

test('a literal clause spells its own canonical text: an at-rule leads as a wrapper, a pseudo-class glues; a reference spells its wrapper beside it', () => {
  assert.equal(prelude(null, '@container (min-width: 500px)', guide), '@container (min-width: 500px) &');
  assert.equal(prelude(null, '@media (hover: hover)', guide), '@media (hover: hover) &');
  assert.equal(prelude(null, '@supports (display: grid)', guide), '@supports (display: grid) &');
  assert.equal(prelude(null, ':checked', guide), '&:checked');
  assert.equal(prelude(null, 'hover+@media (hover: hover)', guide), '@media (hover: hover) &:hover');
  assert.equal(prelude(null, 'narrow+@media (hover: hover)', guide), '@container (max-width: 640px) @media (hover: hover) &');
  assert.equal(prelude('> ul', '@container (min-width: 500px)', guide), '@container (min-width: 500px) & > ul');
  assert.equal(prelude('> ul', ':checked', guide), '& > ul:checked');
  assert.deepEqual(
    preludeParts(null, '@media (hover: hover)', guide).map((part) => part.kind),
    [WRAPPER, TEXT],
    'a literal at-rule is a wrapper part, chipped like a reference',
  );
  // A clause the grammar cannot read still spells as `:name`, honestly.
  assert.equal(prelude(null, 'bogus', guide), '&:bogus');
});

test('a position is selector text: a qualified row spells its selector, the state after the qualifier', () => {
  assert.equal(prelude('> *:not(:last-child)', 'default', guide), '> *:not(:last-child)');
  assert.equal(prelude('> *:not(:last-child)', 'hover', guide), '& > *:not(:last-child):hover');
  assert.equal(prelude('> d-text:nth-child(odd)', 'narrow', guide), '@container (max-width: 640px) & > d-text:nth-child(odd)');
  assert.equal(prelude('&:first-child', 'default', guide), '&:first-child');
});

test('a generated box: the anchor with its pseudo-element, the states before it', () => {
  assert.equal(prelude('&::before', 'default', guide), '&::before');
  assert.equal(prelude('&::after', 'hover', guide), '&:hover::after');
  assert.equal(prelude('&::before', 'hover+narrow', guide), '@container (max-width: 640px) &:hover::before');
  assert.equal(prelude('> d-text::after', 'hover', guide), '& > d-text:hover::after');
  assert.equal(prelude('.quote::after', 'default', guide), '.quote::after');
});

test('a selector row at the default condition is the bare relative chain; under a condition the chain follows `&` with the states on its last compound', () => {
  ['> *', '> d-text', 'p', '.quote', '> d-text:has(a)', '> d-text .text-block-link', '> d-container > p', '> p + p', '> *:not(d-image)'].forEach((selector) => {
    assert.equal(prelude(selector, 'default', guide), selector, selector);
  });
  assert.equal(prelude('> d-text:has(a)', 'hover', guide), '& > d-text:has(a):hover');
  assert.equal(prelude('p', 'hover', guide), '& p:hover');
  assert.equal(prelude('.quote', 'focus', guide), '& .quote:focus');
  assert.equal(prelude('> d-text .text-block-link', 'hover', guide), '& > d-text .text-block-link:hover');
  assert.equal(prelude('> d-container > p', 'narrow', guide), '@container (max-width: 640px) & > d-container > p');
  assert.equal(prelude('> p + p', 'hover+dark', guide), '@media (prefers-color-scheme: dark) & > p + p:hover');
});

test('a rule row spells its selector exactly as a part row does (the owner is its `&`)', () => {
  // An ancestor rule's row carries the rule's selector; the header's
  // words say whose it is, the prelude reads the same as the owner's row.
  assert.equal(prelude('> *', 'default', guide), '> *');
  assert.equal(prelude('> d-text', 'hover', guide), '& > d-text:hover');
  assert.equal(prelude('> d-text > d-text', 'default', guide), '> d-text > d-text');
});

test('a condition part the guide does not declare spells by name, so a row over inert data still reads', () => {
  assert.equal(prelude(null, 'bogus', guide), '&:bogus');
  assert.equal(prelude(null, 'hover+bogus', guide), '&:hover:bogus');
  assert.equal(prelude(null, 'hover', null), '&:hover');
  assert.equal(prelude(null, 'hover', {}), '&:hover');
});

test('a selector outside the grammar prints verbatim, the condition still spelled around it', () => {
  assert.equal(prelude('::weird', 'default', guide), '::weird');
  assert.equal(prelude('& ::before', 'default', guide), '& ::before');
  assert.equal(prelude('::weird', 'hover', guide), '& ::weird:hover');
});

test('the parts chip every symbol combinator, wrap every at-rule, merge the rest, and concatenate to the prelude', () => {
  const parts = preludeParts('> d-container > p + p', 'hover+narrow+dark', guide);
  assert.deepEqual(parts, [
    { kind: WRAPPER, text: '@container (max-width: 640px)' },
    { kind: TEXT, text: ' ' },
    { kind: WRAPPER, text: '@media (prefers-color-scheme: dark)' },
    { kind: TEXT, text: ' & ' },
    { kind: COMBINATOR, text: '>', combinator: 'child' },
    { kind: TEXT, text: ' d-container ' },
    { kind: COMBINATOR, text: '>', combinator: 'child' },
    { kind: TEXT, text: ' p ' },
    { kind: COMBINATOR, text: '+', combinator: 'next' },
    { kind: TEXT, text: ' p:hover' },
  ]);
  assert.equal(parts.map((part) => part.text).join(''), prelude('> d-container > p + p', 'hover+narrow+dark', guide));
  assert.deepEqual(preludeParts(null, 'hover', guide), [{ kind: TEXT, text: '&:hover' }]);
  assert.deepEqual(preludeParts('> p ~ p', 'default', guide), [
    { kind: COMBINATOR, text: '>', combinator: 'child' },
    { kind: TEXT, text: ' p ' },
    { kind: COMBINATOR, text: '~', combinator: 'subsequent' },
    { kind: TEXT, text: ' p' },
  ]);
  // A qualifier's own combinator is text inside the parens, never a part.
  assert.deepEqual(preludeParts('> d-text:has(> a)', 'default', guide), [
    { kind: COMBINATOR, text: '>', combinator: 'child' },
    { kind: TEXT, text: ' d-text:has(> a)' },
  ]);
  // A descendant combinator is the space between compounds, no part.
  assert.deepEqual(preludeParts('p span', 'default', guide), [{ kind: TEXT, text: 'p span' }]);
});

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    process.stdout.write(`ok - ${name}\n`);
  } catch (error) {
    failed += 1;
    process.stderr.write(`not ok - ${name}\n${error.stack ?? error.message}\n`);
  }
}
if (failed) {
  process.exit(1);
}
process.stdout.write('# all prelude tests passed\n');
