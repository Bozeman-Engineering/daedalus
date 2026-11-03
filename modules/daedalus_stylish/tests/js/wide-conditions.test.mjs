/**
 * Drift guard and shape battery for the committed wide-conditions file.
 *
 * Run directly (`node --test tests/js/wide-conditions.test.mjs`) or via
 * the module's JsSuitesTest glob. data/wide-conditions.json is the
 * universe a LITERAL condition clause is judged against (PHP:
 * Vocabulary\WideConditions, the one server reader, behind
 * StyleMapValidator::validateCondition): the media and container
 * features the specs define, each with its value space. These tests hold
 * the file in lockstep with the generator and the pinned webref extract
 * (a bump of @webref/css without `npm run generate:wide-selectors` fails
 * here), pin the shape the reader consumes, and spot-check the features
 * the admission rule, the clause grammar's derived twins and the
 * correctives lean on.
 */
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { buildWideConditions } from '../../js/src/tools/generate-wide-selectors.mjs';

const require = createRequire(import.meta.url);
const committed = JSON.parse(readFileSync(new URL('../../data/wide-conditions.json', import.meta.url), 'utf8'));

test('the committed file matches a fresh generator run against the pinned webref extract', () => {
  assert.equal(committed.webrefCss, require('@webref/css/package.json').version);
  assert.deepEqual(committed, buildWideConditions());
});

test('the shape: two at-rules, features by sorted lowercase name, each typed with its value space', () => {
  assert.deepEqual(Object.keys(committed), ['webrefCss', 'media', 'container']);
  for (const atrule of ['media', 'container']) {
    const names = Object.keys(committed[atrule]);
    assert.ok(names.length > 0, `${atrule}: empty`);
    assert.deepEqual(names, [...names].sort(), `${atrule}: not sorted`);
    for (const [name, feature] of Object.entries(committed[atrule])) {
      assert.match(name, /^[a-z][a-z0-9-]*$/, `${atrule} ${name}: not a bare lowercase name (vendor prefixes are left out)`);
      assert.deepEqual(Object.keys(feature), ['type', 'syntax', 'values'], `${atrule} ${name}: record keys`);
      assert.ok(['range', 'discrete'].includes(feature.type), `${atrule} ${name}: type ${feature.type}`);
      assert.equal(typeof feature.syntax, 'string');
      if (feature.values !== null) {
        assert.equal(feature.type, 'discrete', `${atrule} ${name}: only a discrete feature enumerates values`);
        assert.ok(feature.values.length >= 2, `${atrule} ${name}: a value space of one`);
        for (const value of feature.values) {
          assert.match(value, /^[a-z0-9][a-z0-9-]*$/, `${atrule} ${name}: value ${value}`);
        }
      }
    }
  }
});

test('spot checks: the features the published wrappers, the derived twins and the typed preludes use', () => {
  // The published wrappers' own features: the width bands (range, so
  // min-/max- exist), the scheme and motion preferences (discrete, the
  // published values among them).
  assert.deepEqual(committed.container.width, { type: 'range', syntax: '<length>', values: null });
  assert.deepEqual(committed.media.width, { type: 'range', syntax: '<length>', values: null });
  assert.deepEqual(committed.media['prefers-color-scheme'].values, ['light', 'dark']);
  assert.deepEqual(committed.media['prefers-reduced-motion'].values, ['no-preference', 'reduce']);
  // The problem space's typed preludes: pointer, contrast, orientation.
  assert.deepEqual(committed.media.hover.values, ['none', 'hover']);
  assert.deepEqual(committed.media['prefers-contrast'].values, ['no-preference', 'less', 'more', 'custom']);
  assert.deepEqual(committed.media.orientation.values, ['portrait', 'landscape']);
  assert.deepEqual(committed.container.orientation.values, ['portrait', 'landscape']);
  // A boolean feature is the two words; a range feature with a keyword
  // alternative keeps the spec syntax and enumerates nothing.
  assert.deepEqual(committed.media.grid.values, ['0', '1']);
  assert.deepEqual(committed.media.resolution, { type: 'range', syntax: '<resolution> | infinite', values: null });
  // The container size features beside width.
  for (const name of ['inline-size', 'block-size', 'height', 'aspect-ratio']) {
    assert.equal(committed.container[name].type, 'range', `@container (${name})`);
  }
  // A media feature is not a container feature and the other way round:
  // the two universes are judged apart.
  assert.equal(committed.container['prefers-color-scheme'], undefined);
  assert.equal(committed.media['inline-size'], undefined);
  // Typos are outside the tier: that is the refusal's whole basis.
  assert.equal(committed.media.hovre, undefined);
  assert.ok(!committed.media.hover.values.includes('sometimes'));
  // Nothing vendor-prefixed rides along.
  assert.ok(Object.keys(committed.media).every((name) => !name.startsWith('-')));
});
