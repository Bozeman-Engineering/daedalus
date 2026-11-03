/**
 * Drift guard and parity battery for the committed wide-selectors file.
 *
 * Run directly (`node --test tests/js/wide-selectors.test.mjs`) or via the
 * module's JsSuitesTest glob. data/wide-selectors.json is the universe
 * admission is judged against on both sides (PHP: WideSelectors, the one
 * server reader; client: js/src/wide-selectors.js, which bundles the same
 * file). These tests hold the file in lockstep with the generator and the
 * two pinned packages (a bump of mdn-data or html-tag-names without
 * `npm run generate:wide-selectors` fails here), pin the shape the
 * readers consume, spot-check the names the admission rule and its
 * correctives lean on, and lock the client reader to the committed file.
 */
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import {
  knowsPseudoClass,
  knowsPseudoElement,
  knowsTag,
  widePseudoClasses,
  widePseudoElements,
  wideTags,
} from '../../js/src/wide-selectors.js';
import { buildWideSelectors } from '../../js/src/tools/generate-wide-selectors.mjs';

const require = createRequire(import.meta.url);
const committed = JSON.parse(readFileSync(new URL('../../data/wide-selectors.json', import.meta.url), 'utf8'));

test('the committed file matches a fresh generator run against the pinned packages', () => {
  assert.equal(committed.mdnData, require('mdn-data/package.json').version);
  assert.equal(committed.htmlTagNames, require('html-tag-names/package.json').version);
  assert.deepEqual(committed, buildWideSelectors());
});

test('the three lists are sorted, deduplicated, lowercase bare names', () => {
  for (const list of ['pseudoClasses', 'pseudoElements', 'tags']) {
    const names = committed[list];
    assert.ok(Array.isArray(names) && names.length > 0, `${list}: empty`);
    assert.deepEqual(names, [...new Set(names)].sort(), `${list}: not sorted and unique`);
    for (const name of names) {
      assert.match(name, /^[a-z][a-z0-9-]*$/, `${list}: ${name} is not a bare lowercase name`);
    }
  }
  assert.deepEqual(Object.keys(committed), ['mdnData', 'htmlTagNames', 'pseudoClasses', 'pseudoElements', 'tags']);
});

test('every standard mdn-data pseudo-class and bare pseudo-element is present, nothing vendor-prefixed or functional-only', () => {
  const selectors = require('mdn-data/css/selectors.json');
  const standard = Object.entries(selectors).filter(([key, definition]) => key.startsWith(':') && definition.status === 'standard');
  for (const [key] of standard) {
    const name = key.replace(/^:+/, '').replace(/\(\)$/, '');
    if (name.startsWith('-')) {
      continue;
    }
    if (key.startsWith('::') && key.endsWith('()')) {
      // The terminal slot takes a bare name: a functional pseudo-element
      // is in the tier only by a bare twin (`::cue`), never by itself.
      assert.equal(committed.pseudoElements.includes(name), Object.hasOwn(selectors, `::${name}`), key);
      continue;
    }
    assert.ok((key.startsWith('::') ? committed.pseudoElements : committed.pseudoClasses).includes(name), `${key} missing`);
  }
  assert.ok(committed.pseudoClasses.length >= 60, `only ${committed.pseudoClasses.length} pseudo-classes`);
  assert.ok(committed.pseudoElements.length >= 15, `only ${committed.pseudoElements.length} pseudo-elements`);
  for (const name of ['part', 'slotted', 'highlight', 'view-transition-group']) {
    assert.ok(!committed.pseudoElements.includes(name), `::${name}() is functional only`);
  }
  assert.ok(committed.pseudoElements.includes('cue'));
  assert.ok(committed.tags.length >= 140, `only ${committed.tags.length} tags`);
});

test('spot checks: the names admission and its correctives lean on', () => {
  // The published states and the user-action and input states the
  // roster may grow to are all pseudo-classes the browser knows.
  for (const name of ['hover', 'focus', 'focus-within', 'focus-visible', 'active', 'checked', 'disabled', 'visited', 'link', 'target', 'placeholder-shown', 'required', 'invalid', 'open', 'popover-open']) {
    assert.ok(committed.pseudoClasses.includes(name), `${name} missing`);
  }
  // The grammar's own functional forms are listed by bare name, the
  // folded :host / :host() pair once.
  for (const name of ['has', 'not', 'is', 'where', 'nth-child', 'nth-last-of-type', 'host']) {
    assert.ok(committed.pseudoClasses.includes(name), `${name} missing`);
  }
  assert.equal(committed.pseudoClasses.filter((name) => name === 'host').length, 1);
  // The boxes landing 4 opens, before and after among them.
  for (const name of ['before', 'after', 'marker', 'placeholder', 'selection', 'first-letter', 'first-line', 'backdrop', 'file-selector-button']) {
    assert.ok(committed.pseudoElements.includes(name), `::${name} missing`);
  }
  // Tags outside the closed BASE_TAGS list are what the tier exists for.
  for (const name of ['nav', 'span', 'div', 'button', 'input', 'label', 'section', 'article', 'header', 'footer', 'svg', 'video', 'p', 'a', 'li']) {
    assert.ok(committed.tags.includes(name), `<${name}> missing`);
  }
  // Typos are outside the tier: that is the refusal's whole basis.
  assert.ok(!committed.pseudoClasses.includes('hovre'));
  assert.ok(!committed.tags.includes('navv'));
  // Nothing vendor-prefixed rides along.
  assert.ok(committed.pseudoElements.every((name) => !name.startsWith('-')));
});

test('parity: the client reader answers the committed file', () => {
  assert.deepEqual(widePseudoClasses(), committed.pseudoClasses);
  assert.deepEqual(widePseudoElements(), committed.pseudoElements);
  assert.deepEqual(wideTags(), committed.tags);
  assert.equal(knowsPseudoClass('hover'), true);
  assert.equal(knowsPseudoClass(':hover'), false, 'bare names only');
  assert.equal(knowsPseudoClass('hovre'), false);
  assert.equal(knowsPseudoElement('marker'), true);
  assert.equal(knowsPseudoElement('markr'), false);
  assert.equal(knowsTag('nav'), true);
  assert.equal(knowsTag('navv'), false);
  assert.equal(knowsTag('NAV'), false, 'lowercase only; the grammar lowercases tags before the tier sees them');
});
