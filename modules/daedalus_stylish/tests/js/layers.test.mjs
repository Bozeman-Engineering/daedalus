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
  ELEMENT_LAYER, SKIN_COLOR, ancestorLayerId, ancestorRule, ancestorVariant, ancestorWearLayerId, declaredLayerId,
  isAncestorLayer, isFloorLayer, layerColor, layerScope, layerTier, lineLayerId, paintCoverage, skinLayerId,
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

test('layer ids: every tier is namespaced by prefix; the element layer is the bare reserved id', () => {
  assert.equal(variantLayerId('aqua'), 'variant:aqua');
  assert.equal(declaredLayerId('kind:block'), 'declared:kind:block');
  assert.equal(skinLayerId('block_type:text'), 'skin:block_type:text');
  assert.equal(skinLayerId('base:p'), 'skin:base:p');
  assert.notEqual(variantLayerId('element'), ELEMENT_LAYER);
  // The same address under each tier is four distinct layers.
  assert.equal(new Set([
    ELEMENT_LAYER, skinLayerId('kind:block'), declaredLayerId('kind:block'), variantLayerId('kind:block'),
  ]).size, 4);
});

test('layerTier and layerScope read the id grammar back: the prefix, then the declaring scope or reference', () => {
  assert.equal(layerTier(ELEMENT_LAYER), 'element');
  assert.equal(layerTier(variantLayerId('aqua')), 'variant');
  assert.equal(layerTier(skinLayerId('base:p')), 'skin');
  assert.equal(layerTier(declaredLayerId('block_type:text')), 'declared');
  assert.equal(layerScope(ELEMENT_LAYER), null);
  assert.equal(layerScope(variantLayerId('aqua')), 'aqua');
  // A scope carries colons of its own; the split is at the FIRST one.
  assert.equal(layerScope(skinLayerId('base:p')), 'base:p');
  assert.equal(layerScope(declaredLayerId('block_type:text')), 'block_type:text');
  assert.equal(isFloorLayer(skinLayerId('kind:block')), true);
  assert.equal(isFloorLayer(declaredLayerId('kind:block')), true);
  assert.equal(isFloorLayer(variantLayerId('aqua')), false);
  assert.equal(isFloorLayer(ELEMENT_LAYER), false);
});

test('ancestor ids: the own spelling names the position, the worn spelling appends the variant; ancestorRule reads the position from both, ancestorVariant the variant from the worn one', () => {
  const own = ancestorLayerId('container:x', '> pb-text .link');
  const worn = ancestorWearLayerId('container:x', '> pb-text .link', 'button');
  assert.equal(own, 'ancestor:container:x::> pb-text .link');
  assert.equal(worn, 'ancestor:container:x::> pb-text .link|button');
  assert.equal(isAncestorLayer(own), true);
  assert.equal(isAncestorLayer(worn), true);
  assert.deepEqual(ancestorRule(own), { key: 'container:x', selector: '> pb-text .link' });
  assert.deepEqual(ancestorRule(worn), { key: 'container:x', selector: '> pb-text .link' });
  assert.equal(ancestorVariant(own), null);
  assert.equal(ancestorVariant(worn), 'button');
  // A `+` or `~` combinator, a `:has()` qualifier: the position keeps
  // its own characters; only the last `|` splits.
  assert.deepEqual(ancestorRule(ancestorWearLayerId('container:x', '> p + p:has(a)', 'quiet_link')), { key: 'container:x', selector: '> p + p:has(a)' });
  assert.equal(ancestorVariant(ancestorWearLayerId('container:x', '> p + p:has(a)', 'quiet_link')), 'quiet_link');
  // Any other id answers nothing.
  assert.equal(ancestorRule(variantLayerId('button')), null);
  assert.equal(ancestorVariant(variantLayerId('button')), null);
  assert.equal(ancestorVariant(ELEMENT_LAYER), null);
});

test('a line and a winner carry their layer id; nothing derives from the reference', () => {
  assert.equal(lineLayerId({ layer: ELEMENT_LAYER, variant: null }), ELEMENT_LAYER);
  assert.equal(lineLayerId({ layer: variantLayerId('aqua'), variant: 'aqua' }), 'variant:aqua');
  assert.equal(lineLayerId({ layer: skinLayerId('kind:block'), variant: null }), 'skin:kind:block');
  assert.equal(lineLayerId({ layer: declaredLayerId('kind:block'), variant: null }), 'declared:kind:block');
  assert.equal(winnerLayerId({ layer: ELEMENT_LAYER, variant: null }), ELEMENT_LAYER);
  assert.equal(winnerLayerId({ layer: variantLayerId('aqua'), variant: 'aqua' }), 'variant:aqua');
  assert.equal(winnerLayerId({ layer: skinLayerId('kind:block'), variant: null }), 'skin:kind:block');
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

test('every floor layer shares the fixed SKIN_COLOR by tier prefix, clear of the element blue', () => {
  assert.equal(SKIN_COLOR, 'hsl(172 66% 50%)');
  assert.equal(layerColor(skinLayerId('kind:block')), SKIN_COLOR);
  assert.equal(layerColor(skinLayerId('block_type:text')), SKIN_COLOR);
  assert.equal(layerColor(skinLayerId('base:p')), SKIN_COLOR);
  assert.equal(layerColor(declaredLayerId('kind:block')), SKIN_COLOR);
  assert.equal(layerColor(declaredLayerId('block_type:text')), SKIN_COLOR);
  assert.notEqual(layerColor(skinLayerId('kind:block')), layerColor(ELEMENT_LAYER));
  // The prefix decides, never the address: a variant whose reference
  // spells a tier still hashes to its own hue.
  assert.notEqual(layerColor(variantLayerId('skin:kind:block')), SKIN_COLOR);
  assert.match(layerColor(variantLayerId('skin')), /^hsl\(\d+ 70% 60%\)$/);
});

test('an ancestor rule\'s own layer shares the floor hue; a variant the rule wears is that variant\'s hue, the same as an own wearing of it', () => {
  assert.equal(layerColor(ancestorLayerId('container:x', '> pb-text')), SKIN_COLOR);
  assert.equal(layerColor(ancestorLayerId('container:x', 'pb-text .text-block-link')), SKIN_COLOR);
  const worn = layerColor(ancestorWearLayerId('container:x', 'pb-text .text-block-link', 'aqua'));
  assert.equal(worn, layerColor(variantLayerId('aqua')), 'one variant, one hue, whoever wears it');
  assert.notEqual(worn, SKIN_COLOR);
  // The position never colors the worn layer: the same variant through
  // another rule on another owner is the same hue.
  assert.equal(layerColor(ancestorWearLayerId('container:y', '> *', 'aqua')), worn);
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
    { key: 'container:c1', selector: null, layers: [CTA.id] },
    { key: 'block:b1', selector: null, layers: [CTA.id] },
  ]);
});

test('paintCoverage: the element layer covers own declarations, not pure-ref entries', () => {
  assert.deepEqual(paintCoverage(MAP, [ELEMENT]), [
    { key: 'container:c1', selector: null, layers: [ELEMENT_LAYER] },
    { key: 'block:b2', selector: null, layers: [ELEMENT_LAYER] },
    { key: 'block:b2', selector: '.quote', layers: [ELEMENT_LAYER] },
  ], 'b1 wears a ref but adds nothing of its own; b3 has only empty blocks');
});

test('paintCoverage: overlap lists covering layers in the given stacking order', () => {
  const targets = paintCoverage(MAP, [CTA, AQUA, ELEMENT]);
  const container = targets.find((target) => target.key === 'container:c1');
  assert.deepEqual(container.layers, [CTA.id, AQUA.id, ELEMENT_LAYER]);
});

test('paintCoverage: a part map key splits into the resolveTargets pair', () => {
  const [part] = paintCoverage({ 'block:b2::.quote': { default: { color: 'red' } } }, [ELEMENT]);
  assert.deepEqual(part, { key: 'block:b2', selector: '.quote', layers: [ELEMENT_LAYER] });
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
    { key: 'block:by-name', selector: null, layers: [CTA.id] },
  ]);
});

process.stdout.write('# all layers tests passed\n');
