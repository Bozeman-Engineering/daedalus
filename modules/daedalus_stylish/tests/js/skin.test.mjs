/**
 * Self-asserting tests for the Skin tab (skin.js): the module resolves
 * under node with its components, the section headings (a scale's label
 * as declared; Animations and Fonts with their reference hints), the
 * "+ New" picker's kind words, and the skin card's refusal reasons. The
 * tab's data (the sections, the rows, the gesture roster) is
 * skin-model.test.mjs's; the row and the card's sentences are
 * material-card.test.mjs's; the skin card's read is
 * skin-card-model.test.mjs's.
 *
 * Run directly (`node tests/js/skin.test.mjs`) or with the rest of the
 * suite (`node --test tests/js/*.test.mjs`). Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { registerJsx } from './jsx-loader.mjs';
import { animationRecord, fontRecord, roster, tokenRecord } from './materials-fixture.mjs';

registerJsx();

globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
  formatPlural: (count, singular, plural) =>
    (count === 1 ? singular : plural).split('@count').join(String(count)),
  url: (path) => `/${path}`,
};

const skin = await import('../../js/src/skin.js');
const { skinSections } = await import('../../js/src/skin-model.js');

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('the module resolves with the tab and its helpers', () => {
  assert.equal(typeof skin.SkinView, 'function');
  assert.equal(typeof skin.sectionHeading, 'function');
  assert.equal(typeof skin.kindLabel, 'function');
  assert.equal(typeof skin.reasonText, 'function');
});

test('sectionHeading: a scale as declared, the two block kinds with their reference hints', () => {
  const guide = { tokenScales: { color: { label: 'Colors', shape: 'color', prefix: 'color' } } };
  const materials = roster(
    tokenRecord('color', 'accent', '#1a1a2e'),
    animationRecord('fade_in', 'fade_in', { '0%': { opacity: '0' } }),
    fontRecord('inter', 'inter', 'Inter', [{ weight: '400', style: 'normal', source: { file: 'f' } }]),
  );
  const [colors, animations, fonts] = skinSections(guide, materials);
  assert.deepEqual(skin.sectionHeading(colors), { label: 'Colors', hint: null });
  assert.deepEqual(skin.sectionHeading(animations), { label: 'Animations', hint: 'what motion-* names' });
  assert.deepEqual(skin.sectionHeading(fonts), { label: 'Fonts', hint: 'what a family stack names' });
  // A scale the guide does not declare keeps its id as the label.
  assert.deepEqual(skin.sectionHeading({ kind: 'token', label: 'radius' }), { label: 'radius', hint: null });
});

test('kindLabel: the "+ New" picker offers the three kinds by name', () => {
  assert.deepEqual(['token', 'animation', 'font'].map(skin.kindLabel), ['Token', 'Animation', 'Font']);
  assert.equal(skin.kindLabel('variant'), undefined, 'a variant is not minted on the skin');
});

test('reasonText: every refusal the skins read speaks, in the card\'s words', () => {
  assert.equal(skin.reasonText({ reason: 'unpublished' }), 'unpublished');
  assert.equal(skin.reasonText({ reason: 'nonconforming', lacks: { space: ['gutter', 'hero'], color: ['accent'] } }), 'lacks space: gutter, hero; color: accent');
  assert.equal(skin.reasonText({ reason: 'nonconforming' }), 'lacks ');
  assert.equal(skin.reasonText({ reason: 'not-member' }), 'not a member');
  assert.equal(skin.reasonText({ reason: 'refused' }), 'cannot be worn');
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} skin tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} skin tests passed.`);
