/**
 * Self-asserting tests for the client observability seam (js/src/log.js).
 *
 * Run directly (`node tests/js/log.test.mjs`) or via PrinterEquivalenceTest,
 * which executes this file inside the PHPUnit suite. The log POINTS are
 * console noise by design, but the diffs they report are pure functions the
 * developer reads a distribution story out of, so they get the same
 * backpressure as the rest of the client's pure core. Also pins the
 * degradation the node suites themselves rely on: with no Drupal global (no
 * daedalus_ui logging library), log() is a silent no-op rather than a
 * crash at import. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  log,
  logEnabled,
  styleMapDelta,
  variantCarriers,
  carrierDelta,
} from '../../js/src/log.js';

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';
const SECTION = `section:${UUID_A}`;
const BLOCK = `block:${UUID_B}`;

// An entryRefs() item as the log carries it: the origin slot rides every
// item (null when the reference records none), the server's exact shape.
const ref = (variant, fragment = '') => ({ variant, fragment, via: null });

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('no logging library: log() is silent, never a throw', () => {
  assert.equal(logEnabled(), false);
  assert.equal(log('op', 'nothing prints', { key: SECTION }), undefined);
});

test('an unchanged map yields an empty delta', () => {
  const map = { [SECTION]: { default: { padding: '2rem' } } };
  assert.deepEqual(styleMapDelta(map, { ...map }), {});
  assert.deepEqual(styleMapDelta({}, {}), {});
  assert.deepEqual(styleMapDelta(undefined, undefined), {});
});

test('added, removed and changed entries report their condition blocks', () => {
  const before = {
    [SECTION]: { default: { padding: '2rem' }, hover: { color: 'red' } },
    [BLOCK]: { default: { color: 'blue' } },
  };
  const after = {
    [SECTION]: { default: { padding: '3rem' }, hover: { color: 'red' } },
    [`${BLOCK}::.quote`]: { narrow: { 'text-wrap': 'balance' } },
  };
  assert.deepEqual(styleMapDelta(before, after), {
    [SECTION]: { change: 'changed', conditions: ['default'] },
    [BLOCK]: { change: 'removed', conditions: ['default'] },
    [`${BLOCK}::.quote`]: { change: 'added', conditions: ['narrow'] },
  });
});

test('reference gains and losses read per key, fragments distinguished', () => {
  const before = {
    [SECTION]: { variants: [ref('cta_card')] },
    [BLOCK]: { variants: [ref('cta_card', 'region(first)/block(text,1)')] },
  };
  const after = {
    // Same axis, other variant: one lost, one gained on the same key.
    [SECTION]: { variants: [ref('hero_dark')] },
    // The fragment moved to another slot: not the same reference.
    [BLOCK]: { variants: [ref('cta_card', 'region(first)/block(text,2)')] },
  };
  assert.deepEqual(styleMapDelta(before, after), {
    [SECTION]: {
      change: 'changed',
      refsGained: [ref('hero_dark')],
      refsLost: [ref('cta_card')],
    },
    [BLOCK]: {
      change: 'changed',
      refsGained: [ref('cta_card', 'region(first)/block(text,2)')],
      refsLost: [ref('cta_card', 'region(first)/block(text,1)')],
    },
  });
});

test('a detach reads as a reference loss plus the materialized overrides', () => {
  const before = { [BLOCK]: { variants: [ref('cta_card')] } };
  const after = { [BLOCK]: { default: { color: 'blue' } } };
  assert.deepEqual(styleMapDelta(before, after), {
    [BLOCK]: {
      change: 'changed',
      refsLost: [ref('cta_card')],
      conditions: ['default'],
    },
  });
});

test('malformed references are ignored, exactly like the server read', () => {
  const before = { [BLOCK]: { variants: ['cta_card'] } };
  const after = { [BLOCK]: { variants: [ref('cta_card')] } };
  // The retired bare-string form never counted as a reference, so the
  // structured one is a clean gain.
  assert.deepEqual(styleMapDelta(before, after), {
    [BLOCK]: { change: 'changed', refsGained: [ref('cta_card')] },
  });
});

test('carriers are the keys wearing a variant, with their fragments', () => {
  const map = {
    [SECTION]: { variants: [ref('cta_card'), ref('hero_dark')] },
    [BLOCK]: { variants: [ref('cta_card', 'region(first)/block(text,1)')] },
    [`${BLOCK}::.quote`]: { default: { color: 'red' } },
  };
  assert.deepEqual(variantCarriers(map, 'cta_card'), {
    [SECTION]: [''],
    [BLOCK]: ['region(first)/block(text,1)'],
  });
  assert.deepEqual(variantCarriers(map, 'hero_dark'), { [SECTION]: [''] });
  assert.deepEqual(variantCarriers(map, 'nothing_wears_this'), {});
  assert.deepEqual(variantCarriers(null, 'cta_card'), {});
});

test('the carrier delta tells the distribution story of an apply', () => {
  const before = {};
  const after = {
    [SECTION]: [''],
    [BLOCK]: ['region(first)/block(text,1)'],
  };
  assert.deepEqual(carrierDelta(before, after), {
    gained: { [SECTION]: [''], [BLOCK]: ['region(first)/block(text,1)'] },
    lost: {},
    carriers: 2,
  });
  // The root detach's group gesture, read backwards.
  assert.deepEqual(carrierDelta(after, before), {
    gained: {},
    lost: { [SECTION]: [''], [BLOCK]: ['region(first)/block(text,1)'] },
    carriers: 0,
  });
  // A per-key detach leaves the rest carrying.
  assert.deepEqual(carrierDelta(after, { [SECTION]: [''] }), {
    gained: {},
    lost: { [BLOCK]: ['region(first)/block(text,1)'] },
    carriers: 1,
  });
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
  console.error(`${failures} of ${tests.length} log tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} log tests passed.`);
