/**
 * Self-asserting tests for the shared condition-cascade vocabulary
 * (condition-cascade.js): structural order (the printer's emission order,
 * CssPrinter::orderedConditions() mirror), applicability at a posed
 * compound, and the inherited-value read the inspector's provenance
 * renders from.
 *
 * Run directly (`node tests/js/condition-cascade.test.mjs`). Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  appliesWithin,
  cascadeOrder,
  compoundLabel,
  entryAtPose,
  inheritedValue,
  posedBlock,
  propertyValues,
  valueAtPose,
} from '../../js/src/condition-cascade.js';

const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
  medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width' },
  dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
};

test('cascadeOrder: default, then singles, then compounds', () => {
  assert.deepEqual(
    cascadeOrder(['narrow+dark', 'medium', 'default', 'narrow'], CONDITIONS),
    ['default', 'narrow', 'medium', 'narrow+dark'],
  );
});

test('cascadeOrder: ties break on table position, then given order', () => {
  // hover precedes narrow in the table; unknown parts sort last and keep
  // their given order among themselves.
  assert.deepEqual(
    cascadeOrder(['narrow', 'hover', 'bogus', 'variants'], CONDITIONS),
    ['hover', 'narrow', 'bogus', 'variants'],
  );
});

test('appliesWithin: default everywhere, subsets at a compound', () => {
  assert.equal(appliesWithin('default', 'narrow'), true);
  assert.equal(appliesWithin('narrow', 'narrow+dark'), true);
  assert.equal(appliesWithin('dark', 'narrow+dark'), true);
  assert.equal(appliesWithin('narrow+dark', 'narrow+dark'), true);
  assert.equal(appliesWithin('medium', 'narrow+dark'), false);
  assert.equal(appliesWithin('narrow+dark', 'narrow'), false);
  // At the default pose nothing but default applies.
  assert.equal(appliesWithin('narrow', 'default'), false);
  assert.equal(appliesWithin('default', 'default'), true);
});

test('compoundLabel: site labels joined with " + ", the name where the table has none', () => {
  assert.equal(compoundLabel('hover', CONDITIONS), 'Hover');
  assert.equal(compoundLabel('narrow+dark', CONDITIONS), 'Narrow + Dark');
  assert.equal(compoundLabel('print', CONDITIONS), 'print');
  assert.equal(compoundLabel('narrow', { narrow: { label: '' } }), 'narrow');
  assert.equal(compoundLabel('default', CONDITIONS), 'default');
});

test('inheritedValue: base shows through at a width pose', () => {
  const values = [
    { condition: 'default', value: '2rem' },
    { condition: 'medium', value: '4rem' },
  ];
  assert.deepEqual(inheritedValue(values, 'narrow', CONDITIONS), { condition: 'default', value: '2rem' });
  // At the block's own compound it is the committed value, never the ghost.
  assert.deepEqual(inheritedValue(values, 'medium', CONDITIONS), { condition: 'default', value: '2rem' });
});

test('inheritedValue: the cascade-winning applicable block wins', () => {
  const values = [
    { condition: 'default', value: '2rem' },
    { condition: 'narrow', value: '1rem' },
  ];
  // Posed at narrow+dark, the narrow block beats base.
  assert.deepEqual(inheritedValue(values, 'narrow+dark', CONDITIONS), { condition: 'narrow', value: '1rem' });
});

test('inheritedValue: nothing applicable is honestly null', () => {
  // Posed at Base, a narrow-only value casts no ghost.
  assert.equal(inheritedValue([{ condition: 'narrow', value: '1rem' }], 'default', CONDITIONS), null);
  assert.equal(inheritedValue([], 'narrow', CONDITIONS), null);
});

test('valueAtPose: the compound\'s own block wins over the inheriting one', () => {
  const values = [
    { condition: 'default', value: '2rem' },
    { condition: 'narrow', value: '1rem' },
  ];
  assert.deepEqual(valueAtPose(values, 'narrow', CONDITIONS), { condition: 'narrow', value: '1rem' });
  assert.deepEqual(valueAtPose(values, 'default', CONDITIONS), { condition: 'default', value: '2rem' });
});

test('valueAtPose: without an own block the inheriting block delivers', () => {
  const values = [
    { condition: 'default', value: '2rem' },
    { condition: 'narrow', value: '1rem' },
  ];
  assert.deepEqual(valueAtPose(values, 'narrow+dark', CONDITIONS), { condition: 'narrow', value: '1rem' });
  assert.deepEqual(valueAtPose(values, 'hover', CONDITIONS), { condition: 'default', value: '2rem' });
});

test('valueAtPose: no layer reaching the pose is honestly null', () => {
  assert.equal(valueAtPose([{ condition: 'narrow', value: '1rem' }], 'default', CONDITIONS), null);
  assert.equal(valueAtPose([], 'hover', CONDITIONS), null);
});

test('propertyValues: one property across a condition-keyed entry, Base first, nulls and the variants lane dropped', () => {
  const entry = {
    narrow: { 'padding-top': '12px', gap: '1rem' },
    variants: [{ variant: 'stuff', fragment: '' }],
    default: { 'padding-top': 22, gap: null },
    dark: { 'padding-top': null },
    medium: [],
  };
  assert.deepEqual(propertyValues(entry, 'padding-top', CONDITIONS), [
    { condition: 'default', value: '22' },
    { condition: 'narrow', value: '12px' },
  ]);
  assert.deepEqual(propertyValues(entry, 'gap', CONDITIONS), [{ condition: 'narrow', value: '1rem' }]);
  assert.deepEqual(propertyValues(null, 'gap'), []);
  // An unlisted condition sorts after the guide's, never lost.
  assert.deepEqual(
    propertyValues({ 'narrow+dark': { gap: '2rem' }, default: { gap: '1rem' } }, 'gap', CONDITIONS).map((row) => row.condition),
    ['default', 'narrow+dark'],
  );
});

test('entryAtPose: a condition-keyed entry delivers per property through the cascade', () => {
  const entry = { default: { display: 'block', width: 'fit-content' }, narrow: { width: '100%' }, medium: { display: 'flex' } };
  // Base delivers everywhere, from Base.
  assert.deepEqual(entryAtPose(entry, 'width', 'medium', CONDITIONS), { condition: 'default', value: 'fit-content' });
  // The posed compound's own block wins.
  assert.deepEqual(entryAtPose(entry, 'display', 'medium', CONDITIONS), { condition: 'medium', value: 'flex' });
  // A subset compound overrides Base at a posed compound.
  assert.deepEqual(entryAtPose(entry, 'width', 'narrow+dark', CONDITIONS), { condition: 'narrow', value: '100%' });
  // Nothing reaching the pose, and nothing at all, is null.
  assert.equal(entryAtPose({ medium: { gap: '1rem' } }, 'gap', 'narrow', CONDITIONS), null);
  assert.equal(entryAtPose(null, 'gap', 'default', CONDITIONS), null);
});

test('posedBlock: the applicable blocks merge in cascade order, a re-set property moving to its later position', () => {
  const entry = { default: { padding: '1rem 2rem', gap: '1rem' }, medium: { 'padding-top': '3rem' }, narrow: { padding: '0' }, variants: [] };
  assert.deepEqual(posedBlock(entry, 'default', CONDITIONS), { padding: '1rem 2rem', gap: '1rem' });
  // Posed at Medium: Base's shorthand, then Medium's longhand after it.
  assert.deepEqual(Object.entries(posedBlock(entry, 'medium', CONDITIONS)), [['padding', '1rem 2rem'], ['gap', '1rem'], ['padding-top', '3rem']]);
  // Posed at Narrow: Narrow's padding lands AFTER gap, as the later
  // block's declaration does in the printed document.
  assert.deepEqual(Object.entries(posedBlock(entry, 'narrow', CONDITIONS)), [['gap', '1rem'], ['padding', '0']]);
  // Nulls are unsets; nothing applicable is null.
  assert.deepEqual(posedBlock({ default: { gap: null, padding: '1px' } }, 'dark', CONDITIONS), { padding: '1px' });
  assert.equal(posedBlock({ medium: { gap: '1rem' } }, 'default', CONDITIONS), null);
  assert.equal(posedBlock(null, 'default', CONDITIONS), null);
});
