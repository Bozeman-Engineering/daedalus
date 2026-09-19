/**
 * Self-asserting tests for the swatch kind registry's pure model.
 *
 * Run directly (`node tests/js/swatch-kinds-model.test.mjs`) or via the
 * node suite glob. Locks the strict-lifted color mint seed: authoring is
 * never strict-gated, so the seed is the neutral hex on every posture —
 * strict sites no longer redirect it to a palette reference. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { colorSeed } from '../../js/src/swatch-kinds-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const GUIDE = {
  enforcement: 'strict',
  tokens: [
    { name: '--color-accent', label: 'Accent', value: '#b91c1c', type: 'color' },
  ],
  palette: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c', variable: '--swatch-brand_red' },
  ],
};

test('colorSeed is the neutral hex with no guide', () => {
  assert.equal(colorSeed(), '#808080');
  assert.equal(colorSeed(null), '#808080');
  assert.equal(colorSeed({}), '#808080');
});

test('colorSeed ignores strict: no palette-reference redirect', () => {
  assert.equal(colorSeed(GUIDE), '#808080');
});

test('colorSeed ignores strict even with an empty vocabulary', () => {
  assert.equal(colorSeed({ enforcement: 'strict', tokens: [], palette: [] }), '#808080');
});

test('colorSeed matches the free posture', () => {
  assert.equal(colorSeed({ ...GUIDE, enforcement: 'guarded' }), '#808080');
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
    console.error(error);
  }
}
if (failures > 0) {
  console.error(`${failures} failure(s)`);
  process.exit(1);
}
console.log(`${tests.length} tests passed`);
