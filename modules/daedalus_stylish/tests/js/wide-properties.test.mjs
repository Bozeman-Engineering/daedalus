/**
 * Self-asserting tests for the committed wide-properties data file.
 *
 * Run directly (`node tests/js/wide-properties.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. The committed data/wide-properties.json is the server's wide
 * property tier (task: one PHP reader, weighted below the curated groups);
 * these tests hold it in lockstep with the generator and the pinned
 * mdn-data: a drifted file (mdn-data bumped or classifier changed without
 * `npm run generate:wide-properties`) fails here, and the definition
 * shapes are pinned to what the typed validators actually consume. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { lexer } from 'css-tree';
import { validateValue } from '../../js/src/code/validate-value.js';
import { isWideProperty, widePropertyNames, wideValueKeywords } from '../../js/src/code/wide-tier.js';
import { buildWideProperties } from '../../js/src/tools/generate-wide-properties.mjs';

const require = createRequire(import.meta.url);
const committed = JSON.parse(readFileSync(new URL('../../data/wide-properties.json', import.meta.url), 'utf8'));

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('the committed file matches a fresh generator run against the pinned mdn-data', () => {
  assert.equal(committed.mdnData, require('mdn-data/package.json').version);
  assert.deepEqual(committed, buildWideProperties());
});

test('every standard mdn-data property is present, nothing else', () => {
  const mdn = require('mdn-data/css');
  const expected = Object.keys(mdn.properties)
    .filter((name) => mdn.properties[name].status === 'standard' && !name.startsWith('-'))
    .sort();
  assert.deepEqual(Object.keys(committed.properties), expected);
  assert.ok(expected.length >= 450, `only ${expected.length} standard properties`);
});

test('definitions carry only the shapes the typed validators consume', () => {
  const types = ['keyword', 'dimension', 'number', 'color', 'ident', 'any'];
  const flags = ['keywords', 'negative', 'number', 'list'];
  for (const [name, def] of Object.entries(committed.properties)) {
    assert.ok(types.includes(def.type), `${name}: unknown type ${def.type}`);
    assert.ok(typeof def.label === 'string' && def.label !== '', `${name}: missing label`);
    assert.ok(typeof def.area === 'string' && def.area !== '', `${name}: missing area`);
    if (def.type === 'any') {
      assert.ok(typeof def.syntax === 'string' && def.syntax !== '', `${name}: any without syntax`);
      for (const flag of flags) {
        assert.equal(def[flag], undefined, `${name}: any carries typed flag ${flag}`);
      }
    }
    else {
      assert.equal(def.syntax, undefined, `${name}: typed def carries syntax`);
    }
    if (def.type === 'keyword') {
      assert.ok(Array.isArray(def.keywords) && def.keywords.length > 0, `${name}: keyword without keywords`);
    }
    if (def.keywords !== undefined) {
      for (const keyword of def.keywords) {
        assert.equal(keyword, keyword.toLowerCase(), `${name}: keyword ${keyword} not lowercase`);
      }
    }
  }
});

test('the typed share holds the probe posture: most properties, honest tail', () => {
  const total = Object.keys(committed.properties).length;
  const any = Object.values(committed.properties).filter((def) => def.type === 'any').length;
  assert.ok((total - any) / total >= 0.65, `typed share fell to ${total - any}/${total}`);
});

test('spot checks: known classifications hold', () => {
  const props = committed.properties;
  assert.equal(props.display.type, 'keyword');
  assert.ok(props.display.keywords.includes('flex'));
  assert.equal(props.color.type, 'color');
  assert.equal(props['transition-duration'].type, 'dimension');
  assert.equal(props['transition-duration'].list, true);
  assert.equal(props.opacity.type, 'dimension');
  assert.equal(props.opacity.number, true);
  assert.equal(props['z-index'].type, 'number');
  assert.equal(props['z-index'].negative, true);
  assert.equal(props['view-transition-name'].type, 'ident');
  // Combination grammars validate per component via the list flag.
  assert.equal(props['text-decoration-line'].list, true);
  // Shorthands and function/url/string grammars stay honest in the tail.
  assert.equal(props.font.type, 'any');
  assert.equal(props['background-image'].type, 'any');
  // Slash-separated grammars must not be typed lists (splitList would hand
  // "/" to a scalar validator and falsely reject).
  assert.equal(props['aspect-ratio'].type, 'any');
  assert.equal(props['border-radius'].type, 'any');
  // Everything standard includes the curated groups' properties; the
  // curated definitions win by weight server-side.
  assert.equal(props.padding.type, 'dimension');
});

test('typed definitions drive the validator mirror as-is', () => {
  const props = committed.properties;
  assert.deepEqual(validateValue('transition-duration', '0.2s, 1s', props['transition-duration'], null), []);
  assert.equal(validateValue('transition-duration', 'fast', props['transition-duration'], null).length, 1);
  assert.deepEqual(validateValue('text-decoration-line', 'underline overline', props['text-decoration-line'], null), []);
  assert.equal(validateValue('text-decoration-line', 'wavy', props['text-decoration-line'], null).length, 1);
  assert.deepEqual(validateValue('view-transition-name', 'hero-card', props['view-transition-name'], null), []);
  assert.deepEqual(validateValue('opacity', '0.5', props.opacity, null), []);
  assert.deepEqual(validateValue('accent-color', '#1a1a2e', props['accent-color'], null), []);
});

// --- Parity: the committed file vs the installed csstree ---------------------
// The client never receives the committed file; it reads csstree's embedded
// tables (wide-tier.js), which derive from the same exactly-pinned mdn-data.
// These checks lock the skew: a css-tree or mdn-data bump that moves one
// without the other fails loudly here.

test('parity: every committed property is in the client vocabulary (csstree tables)', () => {
  // The load-bearing direction: a server-recognized property missing from
  // the client tables would make the code view flag valid styles as
  // unrecognized. (The converse — csstree carrying extra non-standard
  // names — is the accepted permissive superset.)
  const names = new Set(widePropertyNames());
  for (const name of Object.keys(committed.properties)) {
    assert.ok(isWideProperty(name), `${name}: unknown to the installed csstree tables`);
    assert.ok(names.has(name), `${name}: missing from widePropertyNames()`);
  }
});

test('parity: committed keyword vocabularies are grammar-valid per the installed csstree', () => {
  // Spot-check semantics, not just names: a scalar keyword def's keywords
  // must match the property's grammar in csstree's tables. csstree patches
  // a handful of grammars away from raw mdn-data (dominant-baseline,
  // stroke-linejoin...), so a tiny divergence tail is tolerated; a version
  // skew between the two pins would blow far past it.
  let checked = 0;
  const rejected = [];
  for (const [name, def] of Object.entries(committed.properties)) {
    if (def.type !== 'keyword' || def.list) {
      continue;
    }
    for (const keyword of def.keywords) {
      checked += 1;
      if (lexer.matchProperty(name, keyword).error) {
        rejected.push(`${name}: ${keyword}`);
      }
    }
  }
  assert.ok(checked >= 400, `only ${checked} keywords checked`);
  assert.ok(rejected.length <= 10, `csstree rejects ${rejected.length} committed keywords: ${rejected.join(', ')}`);
});

test('parity: the client keyword walk covers the committed keyword vocabularies', () => {
  // wideValueKeywords drives value completion for wide-tier properties;
  // it must offer (at least) what the server's keyword defs accept, up to
  // the same patched-grammar tail.
  const missing = [];
  for (const [name, def] of Object.entries(committed.properties)) {
    if (def.type !== 'keyword') {
      continue;
    }
    const walked = new Set(wideValueKeywords(name));
    for (const keyword of def.keywords) {
      if (!walked.has(keyword)) {
        missing.push(`${name}: ${keyword}`);
      }
    }
  }
  assert.ok(missing.length <= 10, `the keyword walk misses ${missing.length} committed keywords: ${missing.join(', ')}`);
});

test('parity: the keyword walk keeps atom types terminal', () => {
  // A color-bearing grammar must not flood completion with named colors.
  const backdrop = wideValueKeywords('backdrop-filter');
  assert.ok(backdrop.includes('none'));
  assert.ok(!backdrop.includes('aliceblue'));
  assert.ok(wideValueKeywords('text-wrap').includes('balance'));
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} wide-properties tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} wide-properties tests passed.`);
