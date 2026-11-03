/**
 * Shape guard for the committed pseudo-vocabularies table.
 *
 * Run directly (`node --test tests/js/pseudo-vocabularies.test.mjs`) or
 * via the module's JsSuitesTest glob. data/pseudo-vocabularies.json is
 * hand-maintained from each pseudo-element's specification (PHP:
 * Vocabulary\PseudoVocabularies, the one server reader; client:
 * js/src/pseudo-vocabularies.js, which bundles the same file). These
 * tests pin the shape both readers consume, hold every key to a
 * pseudo-element the wide selector tier knows and every property to one
 * the wide property tier knows (so a typo in the table is caught here,
 * never at a write), keep `content` the generated boxes' alone, and lock
 * the client reader to the committed file. VocabularyRosterTest locks the
 * table to the property universe the server's group manager owns.
 */
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { pseudoVocabulary, vocabularyPseudos } from '../../js/src/pseudo-vocabularies.js';
import { knowsPseudoElement } from '../../js/src/wide-selectors.js';
import { GENERATED_BOXES, PSEUDO_PATTERN } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

const committed = JSON.parse(readFileSync(new URL('../../data/pseudo-vocabularies.json', import.meta.url), 'utf8'));
const wide = JSON.parse(readFileSync(new URL('../../data/wide-properties.json', import.meta.url), 'utf8'));

test('the table carries the five pseudo-elements with a vocabulary of their own, each a spec url and a property allowlist', () => {
  assert.deepEqual(Object.keys(committed), ['marker', 'placeholder', 'selection', 'first-letter', 'first-line']);
  for (const [pseudo, entry] of Object.entries(committed)) {
    assert.match(pseudo, PSEUDO_PATTERN, pseudo);
    assert.ok(knowsPseudoElement(pseudo), `${pseudo}: not a pseudo-element the wide selector tier knows`);
    assert.ok(!GENERATED_BOXES.includes(pseudo), `${pseudo}: a generated box derives its host, it has no table entry`);
    assert.deepEqual(Object.keys(entry), ['spec', 'properties'], pseudo);
    assert.match(entry.spec, /^https:\/\/drafts\.csswg\.org\//, pseudo);
    assert.ok(Array.isArray(entry.properties) && entry.properties.length > 0, `${pseudo}: empty allowlist`);
    assert.deepEqual(entry.properties, [...new Set(entry.properties)], `${pseudo}: duplicate property`);
  }
});

test('every listed property is one the wide property tier knows, and content stays the generated boxes\' existence switch', () => {
  for (const [pseudo, entry] of Object.entries(committed)) {
    for (const property of entry.properties) {
      assert.match(property, /^[a-z][a-z0-9-]*$/, `${pseudo}: ${property}`);
      assert.ok(Object.hasOwn(wide.properties, property), `${pseudo}: ${property} is not a property the wide tier knows`);
    }
    assert.ok(!entry.properties.includes('content'), `${pseudo}: content is the existence switch of ::before and ::after alone`);
  }
});

test('spot checks: what the specs say a box takes and refuses', () => {
  assert.ok(committed.marker.properties.includes('color'));
  assert.ok(committed.marker.properties.includes('font-size'));
  assert.ok(!committed.marker.properties.includes('padding'), 'a marker has no box model');
  assert.ok(committed.placeholder.properties.includes('opacity'));
  assert.ok(committed.selection.properties.includes('background-color'));
  assert.ok(!committed.selection.properties.includes('font-size'), 'a highlight changes no metrics');
  assert.ok(committed['first-letter'].properties.includes('float'));
  assert.ok(committed['first-letter'].properties.includes('margin'));
  assert.ok(!committed['first-line'].properties.includes('margin'), 'a first line has no box model');
});

test('the client reader answers the committed file', () => {
  assert.deepEqual(vocabularyPseudos(), Object.keys(committed));
  for (const [pseudo, entry] of Object.entries(committed)) {
    assert.deepEqual(pseudoVocabulary(pseudo), entry.properties, pseudo);
  }
  assert.equal(pseudoVocabulary('before'), null);
  assert.equal(pseudoVocabulary('backdrop'), null);
  assert.equal(pseudoVocabulary('markr'), null);
  // A caller may not reach into the table through the answer.
  pseudoVocabulary('marker').push('padding');
  assert.deepEqual(pseudoVocabulary('marker'), committed.marker.properties);
});
