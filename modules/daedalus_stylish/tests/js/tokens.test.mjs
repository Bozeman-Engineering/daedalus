/**
 * Self-asserting tests for token consumption: the roster projections
 * (materials.js over the guide's declarations), the namespace twins
 * (style-guide.js), the scale-constraint and minted-reference checks
 * (validate-value.js), and the island engine's warn/strict split.
 *
 * Run directly (`node tests/js/tokens.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the suite so the
 * client half of the token contract has PHPUnit backpressure. Exits
 * non-zero on the first failure with a readable diff.
 */
import { strict as assert } from 'node:assert';
import {
  animationReference, schemeCondition, schemeDisplayValue, tokenDisplayValue, tokenReference,
} from '../../js/src/style-guide.js';
import {
  animationOf, animationOptions, animationSpelling, customProperty, gradientTokenOf, materialOf, paintValue, scaleTokens, styleReference, tokenOptions,
} from '../../js/src/materials.js';
import { validateValue } from '../../js/src/code/validate-value.js';
import { analyzeIsland } from '../../js/src/code/island.js';
import { tokenEditorKind, tokenPaintsFace, tokenSeed } from '../../js/src/skin-model.js';
import { EMPTY_MATERIALS, animationRecord, materialsFrom, roster, tokenRecord } from './materials-fixture.mjs';

// The guide's token DECLARATIONS: scales as ClientStyleGuide serves them
// (prefix RESOLVED); the tokens themselves are roster records (below).
const tokenScales = {
  space: { label: 'Space', shape: 'dimension', prefix: 'space' },
  color: { label: 'Color', shape: 'color', prefix: 'color' },
  transition: { label: 'Transition', shape: 'text', prefix: 'trans' },
  gradient: { label: 'Gradient', shape: 'gradient', prefix: 'gradient' },
};
const PLUM_VEIL = 'radial-gradient(circle at 30% 20%, #6b2d5c, #1a1a2e)';
const tokens = {
  space: { 4: '1rem', gutter: '2.5rem' },
  color: { accent: { default: '#1a1a2e', dark: '#e8e8f0' } },
  transition: { fast: '150ms ease' },
  gradient: { 'plum-veil': PLUM_VEIL },
};
// The condition vocabulary names the scheme axis ('dark' is only a scheme
// key because the guide says so).
const conditions = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container page (max-width: 500px)', axis: 'width' },
  dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  'reduced-motion': { label: 'Reduced motion', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion' },
};
const guide = { tokenScales, conditions };
// The active skin's tokens as the materials store carries them: declared
// scale order, then stored order.
const tokenMaterials = materialsFrom({ tokenScales, tokens });

// --- tokenDisplayValue: string as-is, condition map's default. ---
assert.equal(tokenDisplayValue('1rem'), '1rem');
assert.equal(tokenDisplayValue({ default: '#1a1a2e', dark: '#e8e8f0' }), '#1a1a2e');
assert.equal(tokenDisplayValue({ dark: '#e8e8f0' }), '');
assert.equal(tokenDisplayValue(undefined), '');

// --- schemeDisplayValue: the scheme-paired entry for split faces. ---
// The guide names the scheme axis's conditions; the shipped vocabulary is
// 'dark' (@media prefers-color-scheme), beside width and motion axes.
const conditionedGuide = { conditions };
assert.equal(schemeDisplayValue(conditionedGuide, { default: '#1a1a2e', dark: '#e8e8f0' }), '#e8e8f0');
// A plain string or a map without a scheme entry has no pair.
assert.equal(schemeDisplayValue(conditionedGuide, '#1a1a2e'), null);
assert.equal(schemeDisplayValue(conditionedGuide, { default: '#1a1a2e' }), null);
assert.equal(schemeDisplayValue(conditionedGuide, { default: '#1a1a2e', narrow: '#fff' }), null);
// Compound keys do NOT count for face duty: only single scheme-axis
// condition names are consulted (a 'narrow+dark' entry applies under a
// width part too, so it is not "the other scheme").
assert.equal(schemeDisplayValue(conditionedGuide, { default: '#1a1a2e', 'narrow+dark': '#e8e8f0' }), null);
// A 'dark' key is only a scheme entry when the guide says so: without the
// conditions table (or with 'dark' on another axis) there is no pair.
assert.equal(schemeDisplayValue({}, { default: '#1a1a2e', dark: '#e8e8f0' }), null);
assert.equal(schemeDisplayValue(undefined, { default: '#1a1a2e', dark: '#e8e8f0' }), null);
// Empty entries are not paintable faces.
assert.equal(schemeDisplayValue(conditionedGuide, { default: '#1a1a2e', dark: '' }), null);
assert.equal(schemeDisplayValue(conditionedGuide, undefined), null);

// --- schemeCondition: where per-scheme authoring writes. ---
// The first scheme-axis condition in declaration order; the guide alone
// decides which names ride the scheme axis.
assert.equal(schemeCondition(conditionedGuide), 'dark');
assert.equal(schemeCondition({ conditions: {
  light: { label: 'Light', wrapper: '@media (prefers-color-scheme: light)', axis: 'scheme' },
  dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
} }), 'light');
// No scheme axis (or no conditions table at all): no authoring target.
assert.equal(schemeCondition({ conditions: { hover: { label: 'Hover', wrapper: ':hover', axis: 'state' } } }), null);
assert.equal(schemeCondition({}), null);
assert.equal(schemeCondition(undefined), null);

// --- scaleTokens: one scale's records as picker entries. ---
const strip = ({ record, ...entry }) => entry;
assert.deepEqual(scaleTokens(guide, tokenMaterials, 'space').map(strip), [
  { scale: 'space', scaleLabel: 'Space', shape: 'dimension', name: '4', ref: 'var(--space-4)', value: '1rem', valueDark: null },
  { scale: 'space', scaleLabel: 'Space', shape: 'dimension', name: 'gutter', ref: 'var(--space-gutter)', value: '2.5rem', valueDark: null },
]);
assert.deepEqual(scaleTokens(guide, tokenMaterials, 'transition').map(strip), [
  { scale: 'transition', scaleLabel: 'Transition', shape: 'text', name: 'fast', ref: 'var(--trans-fast)', value: '150ms ease', valueDark: null },
]);
// A scheme-paired token carries the raw pair (valueDark) beside its
// display value, so split faces never re-read the record.
assert.deepEqual(scaleTokens(guide, tokenMaterials, 'color').map(strip), [
  { scale: 'color', scaleLabel: 'Color', shape: 'color', name: 'accent', ref: 'var(--color-accent)', value: '#1a1a2e', valueDark: '#e8e8f0' },
]);
// A gradient-shaped scale rides the same projection: the shape is the
// scale's, the value the gradient whole.
assert.deepEqual(scaleTokens(guide, tokenMaterials, 'gradient').map(strip), [
  { scale: 'gradient', scaleLabel: 'Gradient', shape: 'gradient', name: 'plum-veil', ref: 'var(--gradient-plum-veil)', value: PLUM_VEIL, valueDark: null },
]);
// The entry carries its record (the store's own object).
assert.equal(scaleTokens(guide, tokenMaterials, 'space')[0].record, tokenMaterials.get('token', 'space/4'));
// Undeclared scale or nothing rostered: empty, never a throw.
assert.deepEqual(scaleTokens(guide, tokenMaterials, 'radius'), []);
assert.deepEqual(scaleTokens(guide, EMPTY_MATERIALS, 'space'), []);
// A rostered token whose scale the guide does not declare keeps its group
// as the label and carries no shape (unfiltered reads list it, a shape
// filter excludes it).
const stray = roster(tokenRecord('radius', 'pill', '999px'));
assert.deepEqual(tokenOptions(guide, stray).map(strip), [
  { scale: 'radius', scaleLabel: 'radius', shape: null, name: 'pill', ref: 'var(--radius-pill)', value: '999px', valueDark: null },
]);
assert.deepEqual(tokenOptions(guide, stray, ['dimension']), []);

// --- tokenOptions: roster order, shape filtering. ---
assert.deepEqual(tokenOptions(guide, tokenMaterials).map((token) => token.ref), [
  'var(--space-4)', 'var(--space-gutter)', 'var(--color-accent)', 'var(--trans-fast)', 'var(--gradient-plum-veil)',
]);
assert.deepEqual(tokenOptions(guide, tokenMaterials, ['color']).map((token) => token.ref), ['var(--color-accent)']);
assert.deepEqual(tokenOptions(guide, tokenMaterials, ['gradient']).map((token) => token.ref), ['var(--gradient-plum-veil)']);
assert.equal(tokenOptions(guide, tokenMaterials, ['color'])[0].scaleLabel, 'Color');
// The raw scheme pair rides through the shape-filtered aggregate too.
assert.equal(tokenOptions(guide, tokenMaterials, ['color'])[0].valueDark, '#e8e8f0');
assert.deepEqual(tokenOptions(guide, tokenMaterials, ['keyword']), []);

// --- tokenReference: the namespace twin (prefix match), for the lint. ---
assert.deepEqual(tokenReference('--space-4', tokenScales), { scale: 'space', name: '4' });
assert.deepEqual(tokenReference('--trans-fast', tokenScales), { scale: 'transition', name: 'fast' });
assert.deepEqual(tokenReference('--color-accent-hover', tokenScales), { scale: 'color', name: 'accent-hover' });
assert.deepEqual(tokenReference('--gradient-plum-veil', tokenScales), { scale: 'gradient', name: 'plum-veil' });

// --- tokenEditorKind / tokenSeed / tokenPaintsFace: the editor by shape. ---
// The palette mounts one value editor per scale shape: the color field,
// the gradient builder, the stack editor for the scale font-family binds
// to, the text editor for the rest.
const boundGuide = { ...guide, constraintDefaults: { 'font-family': { scale: 'transition' } } };
assert.equal(tokenEditorKind(guide, 'color', 'color'), 'color');
assert.equal(tokenEditorKind(guide, 'gradient', 'gradient'), 'gradient');
assert.equal(tokenEditorKind(guide, 'space', 'dimension'), 'text');
assert.equal(tokenEditorKind(guide, 'transition', 'text'), 'text');
assert.equal(tokenEditorKind(boundGuide, 'transition', 'text'), 'stack', 'the bound scale edits as a stack');
assert.equal(tokenEditorKind(boundGuide, 'gradient', 'gradient'), 'gradient', 'the shape decides before the binding');
assert.equal(tokenEditorKind(guide, null, null), 'text');
assert.equal(tokenEditorKind(undefined, 'space', 'dimension'), 'text');
// The seed: the color kind's neutral, the builder's default gradient, nothing.
assert.equal(tokenSeed('color', guide, tokenMaterials), '#808080');
assert.match(tokenSeed('gradient', guide, tokenMaterials), /^linear-gradient\(/);
assert.match(tokenSeed('gradient', {}, EMPTY_MATERIALS), /^linear-gradient\(/, 'a bare guide still seeds a gradient');
assert.equal(tokenSeed('dimension', guide, tokenMaterials), '');
assert.equal(tokenSeed(null, guide, tokenMaterials), '');
// A face paints for the backgrounds a chip can wear.
assert.equal(tokenPaintsFace('color'), true);
assert.equal(tokenPaintsFace('gradient'), true);
assert.equal(tokenPaintsFace('dimension'), false);
assert.equal(tokenPaintsFace(null), false);
// Outside every declared prefix: not a token reference.
assert.equal(tokenReference('--daedalus-ui-accent', tokenScales), null);
// The bare prefix is not a token (no name tail).
assert.equal(tokenReference('--space-', tokenScales), null);
assert.equal(tokenReference('--space', tokenScales), null);

// --- styleReference / customProperty: the record's spelling, read back. ---
assert.equal(styleReference(tokenMaterials.get('token', 'space/4')), 'var(--space-4)');
assert.equal(customProperty(tokenMaterials.get('token', 'space/4')), '--space-4');
assert.equal(styleReference({ kind: 'variant', id: 'x', spellings: { class: 'x' } }), null);
assert.equal(customProperty(null), null);

// --- validateValue: the scale constraint closes the value space. ---
const definition = { label: 'Padding', type: 'dimension' };
const permissive = { guide, materials: tokenMaterials, closed: false };
const strict = { guide, materials: tokenMaterials, closed: true };

assert.deepEqual(validateValue('padding', 'var(--space-4)', definition, { scale: 'space' }, permissive), []);
let violations = validateValue('padding', '1rem', definition, { scale: 'space' }, permissive);
assert.equal(violations.length, 1);
assert.equal(violations[0].code, 'STYLE_VALUE_NOT_ON_SCALE');
assert.match(violations[0].hint, /var\(--space-4\)/);
// An unminted token of the right namespace is off the closed list too.
assert.equal(validateValue('padding', 'var(--space-9)', definition, { scale: 'space' }, permissive)[0].code, 'STYLE_VALUE_NOT_ON_SCALE');
// Allowed entries beside the scale join the closed space.
assert.deepEqual(validateValue('padding', 'auto', definition, { scale: 'space', allowed: ['auto'] }, permissive), []);
// Without the materials context the check defers to the server.
assert.deepEqual(validateValue('padding', '1rem', definition, { scale: 'space' }, null), []);

// --- validateValue: minted-reference check on var() pass-through. ---
assert.deepEqual(validateValue('padding', 'var(--space-4)', definition, null, permissive), []);
// Unminted in a declared namespace: warning permissive, error strict.
violations = validateValue('padding', 'var(--space-9)', definition, null, permissive);
assert.equal(violations.length, 1);
assert.equal(violations[0].code, 'STYLE_TOKEN_NOT_MINTED');
assert.equal(violations[0].severity, 'warning');
violations = validateValue('padding', 'var(--space-9)', definition, null, strict);
assert.equal(violations[0].severity, 'error');
// Out-of-namespace variables pass everywhere (the spine owns only its
// declared prefixes).
assert.deepEqual(validateValue('padding', 'var(--daedalus-ui-x)', definition, null, strict), []);
// References inside calc() and fallbacks are found too.
assert.equal(validateValue('padding', 'calc(var(--space-9) * 2)', definition, null, permissive)[0].code, 'STYLE_TOKEN_NOT_MINTED');
assert.deepEqual(validateValue('padding', 'calc(var(--space-4) * 2)', definition, null, strict), []);
// A var() entry in a plain allowed list still gets the minted check.
violations = validateValue('padding', 'var(--space-9)', definition, { allowed: ['var(--space-9)'] }, strict);
assert.equal(violations[0]?.code, 'STYLE_TOKEN_NOT_MINTED');

// The palette half: site-minted color and gradient tokens beside the
// shipped ones, as the roster carries them (declared scale order, then
// stored order).
const palette = [
  tokenRecord('color', 'brand-red', '#b91c1c', 'color', { origin: 'user' }),
  tokenRecord('gradient', 'hero-fade', { default: 'linear-gradient(#000, #fff)', dark: 'linear-gradient(#fff, #000)' }, 'gradient', { origin: 'user' }),
];
const paletteGuide = guide;
const materials = roster(tokenMaterials.all(), palette);

// --- tokenOptions over the palette: shape filtering, display values. ---
// Roster order is the store's: the shipped tokens as delivered, then the
// site mints in the order they landed.
assert.deepEqual(tokenOptions(paletteGuide, materials, ['color', 'gradient']).map((token) => token.ref), [
  'var(--color-accent)', 'var(--gradient-plum-veil)', 'var(--color-brand-red)', 'var(--gradient-hero-fade)',
]);
assert.deepEqual(tokenOptions(paletteGuide, materials, ['gradient']).map(strip).at(-1), {
  scale: 'gradient',
  scaleLabel: 'Gradient',
  shape: 'gradient',
  name: 'hero-fade',
  ref: 'var(--gradient-hero-fade)',
  value: 'linear-gradient(#000, #fff)',
  valueDark: 'linear-gradient(#fff, #000)',
});
// A single-valued token has no pair.
assert.equal(tokenOptions(paletteGuide, materials, ['color']).at(-1).valueDark, null);
assert.deepEqual(tokenOptions(paletteGuide, materials, ['shadow']), []);

// --- gradientTokenOf: the chip gate, a token on a gradient-shaped scale. ---
assert.equal(gradientTokenOf(paletteGuide, materials, 'var(--gradient-hero-fade)')?.name, 'hero-fade');
assert.equal(gradientTokenOf(paletteGuide, materials, '  VAR(--gradient-plum-veil)  ')?.name, 'plum-veil');
// A color token, a foreign var(), a literal, a layered value: never a gate.
assert.equal(gradientTokenOf(paletteGuide, materials, 'var(--color-brand-red)'), null);
assert.equal(gradientTokenOf(paletteGuide, materials, 'var(--unknown)'), null);
assert.equal(gradientTokenOf(paletteGuide, materials, 'linear-gradient(#000, #fff)'), null);
assert.equal(gradientTokenOf(paletteGuide, materials, "var(--gradient-hero-fade), url('media:abc')"), null);
assert.equal(gradientTokenOf(paletteGuide, materials, null), null);
// The shape is the guide's declaration: without it no token gates.
assert.equal(gradientTokenOf({}, materials, 'var(--gradient-hero-fade)'), null);

// --- materialOf: the grammar's whole-value read as a picker entry. ---
assert.equal(materialOf(paletteGuide, materials, 'var(--color-brand-red)').record.kind, 'token');
assert.equal(materialOf(paletteGuide, materials, 'var(--color-brand-red)').value, '#b91c1c');
// Condition-keyed values answer their default entry (tokenDisplayValue).
assert.equal(materialOf(paletteGuide, materials, 'var(--gradient-hero-fade)').value, 'linear-gradient(#000, #fff)');
assert.equal(materialOf(paletteGuide, materials, 'var(--color-accent)').record.kind, 'token');
assert.equal(materialOf(paletteGuide, materials, 'var(--color-accent)').name, 'accent');
// Whitespace and the var keyword's case are tolerated; the NAME is exact.
assert.equal(materialOf(paletteGuide, materials, '  VAR( --color-accent ) ').name, 'accent');
assert.equal(materialOf(paletteGuide, materials, 'var(--Color-Accent)'), null);
// Unknown references, fallbacks, compounds and literals answer null: the
// face paints the raw spelling.
assert.equal(materialOf(paletteGuide, materials, 'var(--daedalus-ui-x)'), null);
assert.equal(materialOf(paletteGuide, materials, 'var(--color-accent, red)'), null);
assert.equal(materialOf(paletteGuide, materials, 'calc(var(--space-4) * 2)'), null);
assert.equal(materialOf(paletteGuide, materials, '#1a1a2e'), null);

// --- paintValue: the one face-paint rule, default and scheme halves. ---
assert.equal(paintValue(paletteGuide, materials, 'var(--color-brand-red)'), '#b91c1c');
assert.equal(paintValue(paletteGuide, materials, 'var(--color-accent)'), '#1a1a2e');
// The scheme pass paints a paired token's dark entry, and falls back to
// the default for single-valued entries.
assert.equal(paintValue(paletteGuide, materials, 'var(--color-accent)', true), '#e8e8f0');
assert.equal(paintValue(paletteGuide, materials, 'var(--color-brand-red)', true), '#b91c1c');
assert.equal(paintValue(paletteGuide, materials, 'var(--gradient-hero-fade)', true), 'linear-gradient(#fff, #000)');
// References inside a compound paint in place, fallbacks included;
// unknown references stay verbatim.
assert.equal(paintValue(paletteGuide, materials, 'linear-gradient(var(--color-accent), var(--color-brand-red, red))'), 'linear-gradient(#1a1a2e, #b91c1c)');
assert.equal(paintValue(paletteGuide, materials, 'var(--daedalus-ui-x)'), 'var(--daedalus-ui-x)');
// Without a conditions table 'dark' is not a scheme key, so nothing pairs.
assert.equal(paintValue({ ...paletteGuide, conditions: undefined }, materials, 'var(--gradient-hero-fade)', true), 'linear-gradient(#000, #fff)');

// --- validateValue: the minted check covers the palette scales too. ---
const permissivePalette = { ...permissive, materials };
const strictPalette = { ...strict, materials };
assert.deepEqual(validateValue('color', 'var(--color-brand-red)', { label: 'Color', type: 'color' }, null, strictPalette), []);
assert.deepEqual(validateValue('background-image', 'var(--gradient-hero-fade)', { label: 'Image', type: 'text', media: true }, null, strictPalette), []);
// Unminted on the gradient scale: warning permissive, error strict, the
// one posture (there is no second namespace).
violations = validateValue('background-image', 'var(--gradient-missing)', { label: 'Image', type: 'text', media: true }, null, permissivePalette);
assert.equal(violations.length, 1);
assert.equal(violations[0].code, 'STYLE_TOKEN_NOT_MINTED');
assert.equal(violations[0].severity, 'warning');
violations = validateValue('background-image', 'var(--gradient-missing)', { label: 'Image', type: 'text', media: true }, null, strictPalette);
assert.equal(violations[0].severity, 'error');
// A variable in no declared namespace passes even on a strict guide that
// declares nothing: the spine owns only its declared prefixes.
assert.deepEqual(validateValue('color', 'var(--theme-missing)', { label: 'Color', type: 'color' }, null, { guide: {}, materials, closed: true }), []);

// --- analyzeIsland: the warn/strict split at the island boundary. ---
const islandGuide = (closed) => ({
  enforcement: closed ? 'strict' : 'guarded',
  groups: {
    spacing: {
      label: 'Spacing',
      properties: { padding: { label: 'Padding', type: 'dimension', repeat: 4 } },
    },
  },
  conditions: {},
  tokenScales,
});
const declaration = { grants: { spacing: ['padding'] } };

// Permissive: the declaration stays valid (committable) and the unminted
// reference surfaces as a warning diagnostic.
let analysis = analyzeIsland('padding: var(--space-9);', { guide: islandGuide(false), materials, declaration });
assert.equal(analysis.declarations[0].valid, true);
assert.equal(analysis.diagnostics.length, 1);
assert.equal(analysis.diagnostics[0].severity, 'warning');
assert.equal(analysis.diagnostics[0].code, 'STYLE_TOKEN_NOT_MINTED');

// Strict: the same line is an error and blocks itself.
analysis = analyzeIsland('padding: var(--space-9);', { guide: islandGuide(true), materials, declaration });
assert.equal(analysis.declarations[0].valid, false);
assert.equal(analysis.diagnostics[0].severity, 'error');

// A minted reference is clean on both.
analysis = analyzeIsland('padding: var(--space-4);', { guide: islandGuide(true), materials, declaration });
assert.equal(analysis.declarations[0].valid, true);
assert.deepEqual(analysis.diagnostics, []);

// The palette scales at the island boundary: an unminted gradient
// reference warns permissive, errors strict; a minted one is clean on both.
analysis = analyzeIsland('padding: var(--gradient-missing);', { guide: islandGuide(false), materials, declaration });
assert.equal(analysis.declarations[0].valid, true);
assert.equal(analysis.diagnostics[0].severity, 'warning');
assert.equal(analysis.diagnostics[0].code, 'STYLE_TOKEN_NOT_MINTED');
analysis = analyzeIsland('padding: var(--gradient-missing);', { guide: islandGuide(true), materials, declaration });
assert.equal(analysis.declarations[0].valid, false);
assert.equal(analysis.diagnostics[0].severity, 'error');
analysis = analyzeIsland('padding: var(--color-brand-red);', { guide: islandGuide(true), materials, declaration });
assert.equal(analysis.declarations[0].valid, true);
assert.deepEqual(analysis.diagnostics, []);

// The animation half: published animations as the roster carries them
// (label order), spelled motion-{name} in the animation slot; the guide
// declares nothing they join against.
const FADE_STEPS = { '0%': { opacity: '0' }, '100%': { opacity: '1' } };
const RISE_STEPS = { '0%': { opacity: '0', transform: 'translateY(1rem)' }, '100%': { opacity: '1', transform: 'none' } };
const animations = [
  animationRecord('fade_in', 'Fade in', FADE_STEPS, { description: 'Fade the element in.', origin: 'shipped' }),
  animationRecord('rise', 'Rise', RISE_STEPS),
];
const motionMaterials = roster(tokenMaterials.all(), palette, animations);

// --- animationSpelling: the record's keyframes ident, read back. ---
assert.equal(animationSpelling(motionMaterials.get('animation', 'fade_in')), 'motion-fade_in');
assert.equal(animationSpelling(tokenMaterials.get('token', 'space/4')), null, 'a token spells in the style slot only');
assert.equal(animationSpelling(null), null);

// --- animationOptions: roster order, label, spelling, steps. ---
assert.deepEqual(animationOptions(motionMaterials).map(strip), [
  { name: 'fade_in', label: 'Fade in', ref: 'motion-fade_in', steps: FADE_STEPS, description: 'Fade the element in.' },
  { name: 'rise', label: 'Rise', ref: 'motion-rise', steps: RISE_STEPS, description: '' },
]);
// The entry carries its record (the store's own object).
assert.equal(animationOptions(motionMaterials)[0].record, motionMaterials.get('animation', 'fade_in'));
// No animations rostered: empty, never a throw.
assert.deepEqual(animationOptions(materials), []);
assert.deepEqual(animationOptions(EMPTY_MATERIALS), []);

// --- animationOf: the grammar's whole-name read in the animation slot. ---
assert.equal(animationOf(motionMaterials, 'motion-fade_in').label, 'Fade in');
assert.equal(animationOf(motionMaterials, '  motion-rise ').name, 'rise', 'whitespace tolerated, the name exact');
assert.equal(animationOf(motionMaterials, 'motion-missing'), null, 'in the namespace, not rostered');
assert.equal(animationOf(motionMaterials, 'spin'), null, 'a theme keyframes name is no material');
assert.equal(animationOf(motionMaterials, 'none'), null);
assert.equal(animationOf(motionMaterials, 'motion-fade_in, motion-rise'), null, 'one name, never the list');
// The style slot never resolves a keyframes name (the property-scoped
// slot rule): materialOf answers null for the same spelling.
assert.equal(materialOf(paletteGuide, motionMaterials, 'motion-fade_in'), null);

// --- animationReference: the reserved-namespace twin, for the lint. ---
assert.equal(animationReference('motion-fade_in'), 'fade_in');
assert.equal(animationReference('motion-x'), 'x');
// The bare prefix is not an animation (no name tail); a plain ident and
// the keyword are outside the namespace.
assert.equal(animationReference('motion-'), null);
assert.equal(animationReference('motion'), null);
assert.equal(animationReference('spin'), null);
assert.equal(animationReference('none'), null);
assert.equal(animationReference(null), null);

// --- validateValue: the published-animation check on motion values. ---
const animationName = { label: 'Animation name', type: 'animation_name', list: true, keywords: ['none'] };
const animationShorthand = { label: 'Animation', type: 'text', keywords: ['none'] };
const permissiveMotion = { guide: paletteGuide, materials: motionMaterials, closed: false };
const strictMotion = { guide: paletteGuide, materials: motionMaterials, closed: true };
// A published animation, `none`, and a list of published names are clean
// on both postures.
assert.deepEqual(validateValue('animation-name', 'motion-fade_in', animationName, null, strictMotion), []);
assert.deepEqual(validateValue('animation-name', 'none', animationName, null, strictMotion), []);
assert.deepEqual(validateValue('animation-name', 'motion-fade_in, motion-rise', animationName, null, strictMotion), []);
// Unpublished in the motion namespace: warning permissive, error strict,
// the exact posture of the token twin.
violations = validateValue('animation-name', 'motion-missing', animationName, null, permissiveMotion);
assert.equal(violations.length, 1);
assert.equal(violations[0].code, 'STYLE_ANIMATION_NOT_PUBLISHED');
assert.equal(violations[0].severity, 'warning');
assert.match(violations[0].message, /no published "missing" animation/);
violations = validateValue('animation-name', 'motion-missing', animationName, null, strictMotion);
assert.equal(violations[0].severity, 'error');
// The strict corrective lists the published spellings with their labels
// (the server's hint), so a patch needs nothing else.
assert.match(violations[0].hint, /motion-fade_in \(Fade in\), motion-rise \(Rise\)/);
// A plain ident is a theme's own keyframes name: outside the namespace it
// passes permissive (the spine owns only its prefix) and fails strict
// (the closed world curates site animations only).
assert.deepEqual(validateValue('animation-name', 'spin', animationName, null, permissiveMotion), []);
violations = validateValue('animation-name', 'spin', animationName, null, strictMotion);
assert.equal(violations.length, 1);
assert.equal(violations[0].code, 'STYLE_ANIMATION_NOT_PUBLISHED');
assert.equal(violations[0].severity, 'error');
assert.match(violations[0].message, /"spin" is not a site animation/);
// Each name in a list is checked on its own; only the unresolved one
// reports.
violations = validateValue('animation-name', 'motion-fade_in, motion-missing', animationName, null, permissiveMotion);
assert.equal(violations.length, 1);
assert.match(violations[0].message, /"motion-missing"/);
// The shorthand's name layers are found among the timings and keywords
// (keyframes-names.js, the KeyframesNames twin).
assert.deepEqual(validateValue('animation', 'motion-rise 600ms ease-out both', animationShorthand, null, strictMotion), []);
violations = validateValue('animation', 'motion-missing 600ms ease-out both, motion-fade_in 1s', animationShorthand, null, permissiveMotion);
assert.equal(violations.length, 1);
assert.equal(violations[0].code, 'STYLE_ANIMATION_NOT_PUBLISHED');
assert.equal(violations[0].severity, 'warning');
// No animations published on a strict site: the corrective says so.
violations = validateValue('animation-name', 'motion-fade_in', animationName, null, { ...strictMotion, materials });
assert.equal(violations[0].severity, 'error');
assert.match(violations[0].hint, /No animations are published/);
// Without the materials context the check defers to the server; a var()
// value wears no keyframes name to check.
assert.deepEqual(validateValue('animation-name', 'motion-missing', animationName, null, null), []);
assert.deepEqual(validateValue('animation-name', 'var(--theme-entrance)', animationName, null, strictMotion), []);
// Other properties never ask the animation slot, whatever they say.
assert.deepEqual(validateValue('view-transition-name', 'motion-missing', { type: 'ident' }, null, strictMotion), []);

console.log('tokens.test.mjs: all assertions passed');
