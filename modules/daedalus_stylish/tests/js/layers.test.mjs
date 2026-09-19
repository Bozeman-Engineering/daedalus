/**
 * Self-asserting tests for the layer model (layer identity, stable hues,
 * the paint-canvas coverage projection).
 *
 * Run directly (`node tests/js/layers.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * layer contract has PHPUnit backpressure. Exits non-zero on the first
 * failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  DECLARED_LAYER, ELEMENT_LAYER, SKIN_COLOR, SKIN_LAYER, layerColor, lineLayerId, paintCoverage,
  variantLayerId, winnerLayerId,
} from '../../js/src/code/layers.js';

let current = null;
const test = (name, run) => {
  current = name;
  run();
  process.stdout.write(`ok - ${name}\n`);
};

process.on('uncaughtException', (error) => {
  process.stderr.write(`FAIL - ${current}\n${error.stack ?? error.message}\n`);
  process.exit(1);
});

// --- Layer identity -----------------------------------------------------------

test('layer ids: variants are namespaced; the element and floor layers are reserved', () => {
  assert.equal(variantLayerId('aqua'), 'variant:aqua');
  assert.notEqual(variantLayerId('element'), ELEMENT_LAYER);
  assert.notEqual(variantLayerId('skin'), SKIN_LAYER);
  assert.notEqual(variantLayerId('declared'), DECLARED_LAYER);
  assert.equal(new Set([ELEMENT_LAYER, SKIN_LAYER, DECLARED_LAYER]).size, 3);
});

test('a line and a winner carry their layer id; nothing derives from the reference', () => {
  assert.equal(lineLayerId({ layer: ELEMENT_LAYER, variant: null }), ELEMENT_LAYER);
  assert.equal(lineLayerId({ layer: variantLayerId('aqua'), variant: 'aqua' }), 'variant:aqua');
  assert.equal(lineLayerId({ layer: SKIN_LAYER, variant: null }), SKIN_LAYER);
  assert.equal(lineLayerId({ layer: DECLARED_LAYER, variant: null }), DECLARED_LAYER);
  assert.equal(winnerLayerId({ layer: ELEMENT_LAYER, variant: null }), ELEMENT_LAYER);
  assert.equal(winnerLayerId({ layer: variantLayerId('aqua'), variant: 'aqua' }), 'variant:aqua');
  assert.equal(winnerLayerId({ layer: SKIN_LAYER, variant: null }), SKIN_LAYER);
});

// --- Stable hues ---------------------------------------------------------------

test('layerColor is stable per layer and fixed for the element layer', () => {
  assert.equal(layerColor(ELEMENT_LAYER), 'hsl(213 90% 66%)');
  const aqua = layerColor(variantLayerId('aqua'));
  assert.equal(layerColor(variantLayerId('aqua')), aqua, 'same layer, same hue, every call');
  assert.match(aqua, /^hsl\(\d+ 70% 60%\)$/);
});

test('variant hues avoid the element blue band', () => {
  // Every derived hue keeps at least the guard distance from the element
  // layer's 213 hue, so the top layer stays visually reserved.
  ['aqua', 'cta_card', 'split_50_50', 'wide_1024', 'calm', 'pop'].forEach((name) => {
    const hue = Number(/^hsl\((\d+) /.exec(layerColor(variantLayerId(name)))[1]);
    assert.ok(Math.abs(hue - 213) >= 24, `${name} hue ${hue} clears the element band`);
  });
});

test('the floor layers share the fixed SKIN_COLOR, clear of the element blue', () => {
  assert.equal(SKIN_COLOR, 'hsl(172 66% 50%)');
  assert.equal(layerColor(SKIN_LAYER), SKIN_COLOR);
  assert.equal(layerColor(DECLARED_LAYER), SKIN_COLOR);
  assert.notEqual(layerColor(SKIN_LAYER), layerColor(ELEMENT_LAYER));
});

// --- The paint-canvas coverage projection --------------------------------------

const CTA = { id: variantLayerId('cta_card'), variant: 'cta_card' };
const AQUA = { id: variantLayerId('aqua'), variant: 'aqua' };
const ELEMENT = { id: ELEMENT_LAYER, variant: null };

const MAP = {
  'container:c1': {
    variants: [{ variant: 'cta_card', fragment: '' }, { variant: 'aqua', fragment: '' }],
    default: { display: 'grid' },
  },
  'block:b1': { variants: [{ variant: 'cta_card', fragment: 'block(text,1)' }] },
  'block:b2': { default: { color: 'red' } },
  'block:b2::.quote': { default: { 'font-style': 'italic' } },
  'block:b3': { variants: [], default: {} },
};

test('paintCoverage: a variant layer covers every wearer, root or fragment ref', () => {
  assert.deepEqual(paintCoverage(MAP, [CTA]), [
    { key: 'container:c1', sub: null, layers: [CTA.id] },
    { key: 'block:b1', sub: null, layers: [CTA.id] },
  ]);
});

test('paintCoverage: the element layer covers own declarations, not pure-ref entries', () => {
  assert.deepEqual(paintCoverage(MAP, [ELEMENT]), [
    { key: 'container:c1', sub: null, layers: [ELEMENT_LAYER] },
    { key: 'block:b2', sub: null, layers: [ELEMENT_LAYER] },
    { key: 'block:b2', sub: '.quote', layers: [ELEMENT_LAYER] },
  ], 'b1 wears a ref but adds nothing of its own; b3 has only empty blocks');
});

test('paintCoverage: overlap lists covering layers in the given stacking order', () => {
  const targets = paintCoverage(MAP, [CTA, AQUA, ELEMENT]);
  const container = targets.find((target) => target.key === 'container:c1');
  assert.deepEqual(container.layers, [CTA.id, AQUA.id, ELEMENT_LAYER]);
});

test('paintCoverage: a part map key splits into the resolveTargets pair', () => {
  const [part] = paintCoverage({ 'block:b2::.quote': { default: { color: 'red' } } }, [ELEMENT]);
  assert.deepEqual(part, { key: 'block:b2', sub: '.quote', layers: [ELEMENT_LAYER] });
});

test('paintCoverage: nothing to paint means an empty projection', () => {
  assert.deepEqual(paintCoverage(MAP, []), []);
  assert.deepEqual(paintCoverage(null, [CTA]), []);
  assert.deepEqual(paintCoverage(MAP, [{ id: variantLayerId('gone'), variant: 'gone' }]), []);
});

test('paintCoverage: a layer matches its variant reference verbatim', () => {
  // A reference IS the variant's record id; a wearing spelled any other
  // way (the retired uuid form) is unresolved and never covered.
  const uuid = '99999999-1111-2222-3333-444444444444';
  const map = {
    'block:by-name': { variants: [{ variant: 'cta_card', fragment: '' }] },
    'block:by-uuid': { variants: [{ variant: uuid, fragment: '' }] },
  };
  assert.deepEqual(paintCoverage(map, [CTA]), [
    { key: 'block:by-name', sub: null, layers: [CTA.id] },
  ]);
});

process.stdout.write('# all layers tests passed\n');
