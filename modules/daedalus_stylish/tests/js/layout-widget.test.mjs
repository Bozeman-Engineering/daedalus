/**
 * Self-asserting tests for the layout widget's pure logic.
 *
 * Run directly (`node tests/js/layout-widget.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Locks mode + share's client half over the one-box container
 * (every read and write on the container ROOT — no part key exists): the
 * layout-tree reads, the arrangement state (stack / row / the honest
 * Custom, with the widget's own stack vocabulary recognized), the
 * proportion strip math (snap scale, pair-preserving drag), the
 * count-aware preset recipes, the mode-scoped alignment axes (the
 * folded-in Arrange control: the first stack pick riding in with the
 * canonical display: grid upgrade), the root rows' facts (gapState's
 * committed value + resting ghost, rootField's definition + resolved
 * constraints per property), and the exact
 * spine-op batches every gesture fires — the same shapes LayoutWidgetBatchTest pins server-side. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  ROW_VARIANT,
  containerChildren,
  parentContainerOf,
  arrangementState,
  alignmentAxes,
  alignmentState,
  alignOps,
  growOf,
  childGrows,
  percentLabels,
  snapFraction,
  dragPair,
  growOps,
  presetDefs,
  presetActive,
  presetPlan,
  modeOps,
  gapState,
  gapValueOps,
  rootField,
  layoutOwnsGap,
  addColumnPlan,
  removalPlan,
} from '../../js/src/layout-widget.js';

let failures = 0;
const test = (name, fn) => {
  try {
    fn();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
};

const BAND = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const CHILD_A = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const CHILD_B = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const BLOCK = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

const KEY = `container:${BAND}`;
const KEY_A = `container:${CHILD_A}`;
const KEY_B = `container:${CHILD_B}`;
const KEY_BLOCK = `block:${BLOCK}`;

/** A band holding a nested container (with a block), an empty container,
 *  and a block as a DIRECT child — all three column flavors. */
const layout = [{
  uuid: BAND,
  layout_id: 'daedalus_stylish_container',
  children: [
    {
      uuid: CHILD_A,
      layout_id: 'daedalus_stylish_container',
      children: [{ uuid: BLOCK, block_type: 'text' }],
    },
    { uuid: CHILD_B, layout_id: 'daedalus_stylish_container', children: [] },
  ],
}];

/** The shipped one-box shape: both axes style the container ROOT (''),
 *  the children pseudo-part carries the equal fill. */
const rowVariant = {
  id: ROW_VARIANT,
  label: 'Row',
  scope: 'kind:container',
  group: 'container_layout',
  value: {
    '': {
      default: { display: 'flex', gap: '16px' },
      narrow: { 'flex-direction': 'column' },
    },
    '::>': {
      default: { 'flex-grow': '1', 'flex-shrink': '1', 'flex-basis': '0', 'min-width': '0' },
      narrow: { 'flex-grow': '0', 'flex-shrink': '0', 'flex-basis': 'auto' },
    },
  },
};

const widthVariant = {
  id: 'container_lg',
  label: 'Large',
  scope: 'kind:container',
  group: 'width',
  value: {
    '': { default: { 'padding-inline': 'max(var(--space-gutter), calc((100% - 1024px) / 2))' } },
  },
};

const library = [rowVariant, widthVariant];

/** The guide's condition table (cascade order, pose labels). */
const CONDITIONS = {
  narrow: { label: 'Narrow' },
  dark: { label: 'Dark' },
  hover: { label: 'Hover' },
};

/** No own claim on the root at the pose. */
const UNCLAIMED = { display: null, tracks: null };

/** A guide payload minting a spacing scale (the widget-values fixture),
 *  for the scale-constrained gap field. */
const scaledGuide = {
  tokenScales: {
    space: { label: 'Space', shape: 'dimension', prefix: 'space' },
  },
  tokens: {
    space: { 2: '0.5rem', 4: '1rem', 8: '2rem', 16: '4rem' },
  },
};

// --- layout-tree reads --------------------------------------------------

test('containerChildren lists all three column flavors in order', () => {
  const blockChild = { uuid: BLOCK, block_type: 'text' };
  const tree = [{ uuid: BAND, layout_id: 'daedalus_stylish_container', children: [...layout[0].children, blockChild] }];
  assert.deepEqual(containerChildren(tree, BAND), [
    { key: KEY_A, uuid: CHILD_A, kind: 'container', empty: false },
    { key: KEY_B, uuid: CHILD_B, kind: 'container', empty: true },
    { key: KEY_BLOCK, uuid: BLOCK, kind: 'block', empty: false },
  ]);
});

test('containerChildren finds nested containers and refuses non-flows', () => {
  assert.deepEqual(containerChildren(layout, CHILD_A), [
    { key: KEY_BLOCK, uuid: BLOCK, kind: 'block', empty: false },
  ]);
  assert.equal(containerChildren(layout, 'missing'), null);
  const multiRegion = [{ uuid: BAND, layout_id: 'two_col', regions: { first: [], second: [] } }];
  assert.equal(containerChildren(multiRegion, BAND), null);
});

test('parentContainerOf answers direct children only, with position', () => {
  assert.deepEqual(parentContainerOf(layout, KEY_B), {
    key: KEY, uuid: BAND, index: 1, count: 2,
  });
  assert.deepEqual(parentContainerOf(layout, KEY_BLOCK), {
    key: KEY_A, uuid: CHILD_A, index: 0, count: 1,
  });
  // A page band's parent is the entity — no row parent.
  assert.equal(parentContainerOf(layout, KEY), null);
  assert.equal(parentContainerOf(layout, 'container:missing'), null);
});

// --- arrangement state --------------------------------------------------

test('a bare container reads as stack', () => {
  assert.deepEqual(arrangementState({}, KEY, library), {
    mode: 'stack', wearing: null, via: null, customKeys: [], displayKeys: [], carriers: UNCLAIMED,
  });
});

test('a worn arrangement-axis reference reads as row', () => {
  const map = {
    [KEY]: {
      variants: [
        { variant: 'container_lg', fragment: '' },
        { variant: ROW_VARIANT, fragment: '' },
      ],
    },
  };
  assert.deepEqual(arrangementState(map, KEY, library), {
    mode: 'row', wearing: ROW_VARIANT, via: null, customKeys: [], displayKeys: [], carriers: UNCLAIMED,
  });
});

test('the shipped row name resolves while the library is still loading', () => {
  const map = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.equal(arrangementState(map, KEY, []).mode, 'row');
});

test('a width reference alone is not an arrangement', () => {
  const map = { [KEY]: { variants: [{ variant: 'container_lg', fragment: '' }] } };
  assert.equal(arrangementState(map, KEY, library).mode, 'stack');
});

test('hand-written layout styles on the root read as Custom', () => {
  const tracks = {
    [KEY]: { default: { display: 'grid', 'grid-template-columns': 'repeat(3, minmax(0,1fr))' } },
  };
  assert.deepEqual(arrangementState(tracks, KEY, library), {
    mode: 'custom',
    wearing: null,
    via: null,
    customKeys: [KEY],
    displayKeys: [],
    carriers: { display: 'default', tracks: 'default' },
  });
  const flex = { [KEY]: { default: { display: 'flex' } } };
  assert.deepEqual(arrangementState(flex, KEY, library).customKeys, [KEY]);
  // Custom still reports what the entry wears, for the takeover batch —
  // and a stack display UNDER a worn arrangement reference is Custom too
  // (two authorities over one mode).
  const wornToo = {
    [KEY]: {
      variants: [{ variant: ROW_VARIANT, fragment: '' }],
      default: { display: 'grid' },
    },
  };
  const state = arrangementState(wornToo, KEY, library);
  assert.equal(state.mode, 'custom');
  assert.equal(state.wearing, ROW_VARIANT);
});

test('the widget\'s own stack vocabulary is recognized state, not Custom', () => {
  // The upgrade the widget itself writes (root display: grid).
  const upgraded = { [KEY]: { default: { display: 'grid', 'justify-items': 'center' } } };
  assert.deepEqual(arrangementState(upgraded, KEY, library), {
    mode: 'stack',
    wearing: null,
    via: null,
    customKeys: [],
    displayKeys: [KEY],
    carriers: { display: 'default', tracks: null },
  });
  // Explicit tracks stay Custom even with a stack display.
  const tracks = { [KEY]: { default: { display: 'grid', 'grid-template-columns': '1fr 2fr' } } };
  assert.equal(arrangementState(tracks, KEY, library).mode, 'custom');
});

test('a gap override alone is not Custom', () => {
  const map = { [KEY]: { default: { gap: '24px' } } };
  assert.equal(arrangementState(map, KEY, library).mode, 'stack');
});

// --- grows and the strip math -------------------------------------------

test('grows read own default overrides, unset means equal', () => {
  const map = { [KEY_A]: { default: { 'flex-grow': '2' } } };
  assert.equal(growOf(map, KEY_A), 2);
  assert.equal(growOf(map, KEY_B), null);
  assert.deepEqual(childGrows(map, [KEY_A, KEY_B, KEY_BLOCK]), [2, 1, 1]);
  assert.equal(growOf({ [KEY_A]: { default: { 'flex-grow': 'oops' } } }, KEY_A), null);
});

test('percent labels round over the total', () => {
  assert.deepEqual(percentLabels([2, 1, 1]), ['50%', '25%', '25%']);
  assert.deepEqual(percentLabels([1.5, 1.5]), ['50%', '50%']);
});

test('the snap scale owns every drag fraction', () => {
  assert.equal(snapFraction(0.48), 0.5);
  assert.equal(snapFraction(0.32), 1 / 3);
  assert.equal(snapFraction(0.05), 0.2);
  assert.equal(snapFraction(0.95), 0.8);
});

test('a drag preserves the pair combined weight (67/33 to center = 1.5/1.5)', () => {
  assert.deepEqual(dragPair([2, 1], 0, 0.5), [1.5, 1.5]);
  // Other columns never move.
  assert.deepEqual(dragPair([1, 1, 2], 0, 2 / 3), [1.33, 0.67, 2]);
});

test('growOps normalizes 1 to unset and skips unchanged children', () => {
  const map = { [KEY_A]: { default: { 'flex-grow': '2' } } };
  assert.deepEqual(growOps([KEY_A, KEY_B], map, [1.5, 1.5]), [
    { op: 'update_style', key: KEY_A, properties: { 'flex-grow': '1.5' } },
    { op: 'update_style', key: KEY_B, properties: { 'flex-grow': '1.5' } },
  ]);
  // Back to equal: the set child unsets, the unset child emits nothing.
  assert.deepEqual(growOps([KEY_A, KEY_B], map, [1, 1]), [
    { op: 'update_style', key: KEY_A, properties: { 'flex-grow': null } },
  ]);
  // Unchanged values emit nothing at all.
  assert.deepEqual(growOps([KEY_A, KEY_B], map, [2, null]), []);
});

// --- presets ------------------------------------------------------------

test('preset recipes are count-aware and include minting recipes', () => {
  assert.deepEqual(presetDefs(1).map((def) => def.weights), [
    [1, 1], [2, 1], [1, 2], [1, 1, 1], [1, 1, 1, 1],
  ]);
  assert.deepEqual(presetDefs(3).map((def) => def.weights), [
    [1, 1, 1], [1, 2, 1], [2, 1, 1], [1, 1, 2], [1, 1, 1, 1],
  ]);
  assert.deepEqual(presetDefs(4).map((def) => def.weights), [
    [1, 1, 1, 1], [2, 1, 1, 1], [1, 1, 1, 2],
  ]);
  assert.deepEqual(presetDefs(6).map((def) => def.weights), [[1, 1, 1, 1, 1, 1]]);
});

test('presetActive compares ratios, not raw weights', () => {
  assert.equal(presetActive([1.5, 1.5], [1, 1]), true);
  assert.equal(presetActive([2, 1], [2, 1]), true);
  assert.equal(presetActive([2, 1], [1, 2]), false);
  assert.equal(presetActive([1, 1], [1, 1, 1]), false);
});

test('a minting preset is pure add_sections plus only the deviating grows', () => {
  let n = 0;
  const uuids = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
  const plan = presetPlan({
    containerUuid: BAND,
    childKeys: [KEY_A],
    map: {},
    weights: [1, 2, 1],
    generateUuid: () => uuids[n++],
  });
  assert.deepEqual(plan.minted, uuids);
  assert.deepEqual(plan.operations, [
    { op: 'add_section', uuid: uuids[0], location: { anchor: BAND, placement: 'inside' } },
    { op: 'add_section', uuid: uuids[1], location: { anchor: BAND, placement: 'inside' } },
    { op: 'update_style', key: `container:${uuids[0]}`, properties: { 'flex-grow': '2' } },
  ]);
});

test('an equal preset over dressed children unsets their grows', () => {
  const map = {
    [KEY_A]: { default: { 'flex-grow': '2' } },
    [KEY_B]: { default: { 'flex-grow': '1.5' } },
  };
  const plan = presetPlan({
    containerUuid: BAND,
    childKeys: [KEY_A, KEY_B],
    map,
    weights: [1, 1],
    generateUuid: () => { throw new Error('nothing to mint'); },
  });
  assert.deepEqual(plan.operations, [
    { op: 'update_style', key: KEY_A, properties: { 'flex-grow': null } },
    { op: 'update_style', key: KEY_B, properties: { 'flex-grow': null } },
  ]);
});

// --- mode flips ---------------------------------------------------------

test('stack to columns applies the shipped row variant', () => {
  const state = arrangementState({}, KEY, library);
  assert.deepEqual(modeOps(KEY, state, 'row'), [
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
});

test('columns to stack removes the worn reference, grows stay untouched', () => {
  const map = {
    [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] },
    [KEY_A]: { default: { 'flex-grow': '2' } },
  };
  const state = arrangementState(map, KEY, library);
  assert.deepEqual(modeOps(KEY, state, 'stack'), [
    { op: 'remove_variant', key: KEY, variant: ROW_VARIANT },
  ]);
});

test('a takeover from Custom clears the hand-written root first', () => {
  const map = {
    [KEY]: { default: { display: 'grid', 'grid-template-columns': 'repeat(3, minmax(0,1fr))' } },
  };
  const state = arrangementState(map, KEY, library);
  assert.deepEqual(modeOps(KEY, state, 'row'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // Custom over an already-worn row: the clear alone restores Columns.
  const wornToo = {
    [KEY]: { ...map[KEY], variants: [{ variant: ROW_VARIANT, fragment: '' }] },
  };
  assert.deepEqual(modeOps(KEY, arrangementState(wornToo, KEY, library), 'row'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'grid-template-columns': null } },
  ]);
});

test('a no-change click fires nothing', () => {
  assert.deepEqual(modeOps(KEY, arrangementState({}, KEY, library), 'stack'), []);
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(modeOps(KEY, arrangementState(worn, KEY, library), 'row'), []);
});

test('columns over an aligned stack clears the upgrade, alignment stays inert', () => {
  const map = {
    [KEY]: { default: { display: 'grid', 'justify-items': 'center' } },
  };
  assert.deepEqual(modeOps(KEY, arrangementState(map, KEY, library), 'row'), [
    { op: 'update_style', key: KEY, properties: { display: null } },
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // Clicking Stack on an aligned stack changes nothing: the upgrade IS
  // stack state.
  assert.deepEqual(modeOps(KEY, arrangementState(map, KEY, library), 'stack'), []);
});

// --- alignment (the folded-in Arrange control) ---------------------------

test('alignment axes are mode-scoped', () => {
  assert.deepEqual(alignmentAxes('stack').map((axis) => [axis.label, axis.property]), [
    ['Horizontal', 'justify-items'],
    ['Vertical', 'align-content'],
  ]);
  assert.deepEqual(alignmentAxes('row').map((axis) => [axis.label, axis.property]), [
    ['Vertical', 'align-items'],
  ]);
  assert.deepEqual(alignmentAxes('custom'), []);
});

test('alignment state reads the container root', () => {
  const [horizontal, vertical] = alignmentAxes('stack');
  assert.deepEqual(alignmentState({}, KEY, horizontal), {
    selected: null, custom: false, value: null,
  });
  const aligned = { [KEY]: { default: { display: 'grid', 'justify-items': 'center' } } };
  assert.equal(alignmentState(aligned, KEY, horizontal).selected, 'center');
  // Aliases light the stop; unrepresentable values are explicitly custom.
  const alias = { [KEY]: { default: { 'align-content': 'flex-end' } } };
  assert.equal(alignmentState(alias, KEY, vertical).selected, 'end');
  const custom = { [KEY]: { default: { 'align-content': 'space-between' } } };
  assert.deepEqual(alignmentState(custom, KEY, vertical), {
    selected: null, custom: true, value: 'space-between',
  });
});

test('the first stack pick rides in with the grid upgrade, one compound op', () => {
  const [horizontal] = alignmentAxes('stack');
  assert.deepEqual(alignOps({}, KEY, horizontal, 'center', 'stack'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'justify-items': 'center', display: 'grid' },
    },
  ]);
  // A later pick sees the committed upgrade and writes its property alone.
  const upgraded = { [KEY]: { default: { display: 'grid', 'justify-items': 'center' } } };
  const [, vertical] = alignmentAxes('stack');
  assert.deepEqual(alignOps(upgraded, KEY, vertical, 'center', 'stack'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'align-content': 'center' },
    },
  ]);
});

test('unset clears only its own property and never adds companions', () => {
  const [horizontal] = alignmentAxes('stack');
  const aligned = { [KEY]: { default: { display: 'grid', 'justify-items': 'center' } } };
  assert.deepEqual(alignOps(aligned, KEY, horizontal, null, 'stack'), [
    { op: 'update_style', key: KEY, properties: { 'justify-items': null } },
  ]);
  assert.deepEqual(alignOps({}, KEY, horizontal, null, 'stack'), []);
});

test('the row cross-axis pick writes align-items alone, no upgrade', () => {
  const [cross] = alignmentAxes('row');
  assert.deepEqual(alignOps({}, KEY, cross, 'center', 'row'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'align-items': 'center' },
    },
  ]);
});

// --- gap ----------------------------------------------------------------

test('gap state reads the worn variant default and the own override', () => {
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(gapState(worn, KEY, library), { raw: null, ghost: '16px' });
  const overridden = {
    [KEY]: { ...worn[KEY], default: { gap: '24px' } },
  };
  assert.deepEqual(gapState(overridden, KEY, library), { raw: '24px', ghost: '16px' });
  // Any spelling reports verbatim: the field wears it, never a parse.
  const rem = { [KEY]: { ...worn[KEY], default: { gap: '2rem' } } };
  assert.deepEqual(gapState(rem, KEY, library), { raw: '2rem', ghost: '16px' });
  const token = { [KEY]: { ...worn[KEY], default: { gap: 'var(--space-4)' } } };
  assert.deepEqual(gapState(token, KEY, library), { raw: 'var(--space-4)', ghost: '16px' });
});

test('a push-restyled variant default follows the library', () => {
  const restyled = {
    ...rowVariant,
    value: { '': { default: { display: 'flex', gap: '24px' } } },
  };
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.equal(gapState(worn, KEY, [restyled]).ghost, '24px');
});

test('a gap write overrides on the root; reset unsets back to the default', () => {
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(gapValueOps(worn, KEY, '24px', 'row'), [
    { op: 'update_style', key: KEY, properties: { gap: '24px' } },
  ]);
  assert.deepEqual(gapValueOps(worn, KEY, null, 'row'), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('stack-mode gap: zero default, the write carries the grid upgrade', () => {
  // No worn arrangement: the resting default is grid's initial zero gap.
  assert.deepEqual(gapState({}, KEY, library), { raw: null, ghost: '0px' });
  // A stack-mode gap is a first stack write: the upgrade rides along.
  assert.deepEqual(gapValueOps({}, KEY, '8px', 'stack'), [
    { op: 'update_style', key: KEY, properties: { gap: '8px', display: 'grid' } },
  ]);
  // With the upgrade committed, a write is gap alone.
  const upgraded = { [KEY]: { default: { display: 'grid' } } };
  assert.deepEqual(gapValueOps(upgraded, KEY, '8px', 'stack'), [
    { op: 'update_style', key: KEY, properties: { gap: '8px' } },
  ]);
  // The reset gesture never adds companions.
  assert.deepEqual(gapValueOps({}, KEY, null, 'stack'), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('every field gesture speaks the one value-level primitive', () => {
  // A scrubbed or typed value is the same batch a stop pick fires, value
  // for value: the stack upgrade rides along.
  assert.deepEqual(gapValueOps({}, KEY, '2rem', 'stack'), [
    { op: 'update_style', key: KEY, properties: { gap: '2rem', display: 'grid' } },
  ]);
  // A committed display means the write is gap alone — same key, in
  // place (one box: there is no second carrier to migrate or clear).
  const arranged = { [KEY]: { default: { display: 'grid', gap: '8px' } } };
  assert.deepEqual(gapValueOps(arranged, KEY, '20px', 'stack'), [
    { op: 'update_style', key: KEY, properties: { gap: '20px' } },
  ]);
  // An emptied field is the unset gesture, companions never added.
  assert.deepEqual(gapValueOps({}, KEY, null, 'stack'), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
  // A stop pick commits its var() reference through the same primitive.
  assert.deepEqual(gapValueOps({}, KEY, 'var(--space-4)', 'row'), [
    { op: 'update_style', key: KEY, properties: { gap: 'var(--space-4)' } },
  ]);
});

test('a root row reads its property\'s definition and the resolved constraints', () => {
  const guide = {
    groups: { spacing: { properties: { gap: { type: 'dimension' } } } },
    kinds: {
      container: { grants: { spacing: ['gap', 'justify-items'] } },
    },
  };
  assert.deepEqual(rootField(guide, KEY, 'gap'), { definition: { type: 'dimension' }, constraints: {} });

  // No gate lives here: a closed vocabulary flows through as constraints
  // for the field to adapt to (stop select, detent scrub), never as a
  // reason to withhold the row.
  const constrained = {
    ...guide,
    enforcement: 'guarded',
    kinds: {
      container: {
        grants: { spacing: ['gap', 'justify-items'] },
        valueSpaces: { gap: { allowed: ['0', '1rem'] } },
      },
    },
  };
  assert.deepEqual(rootField(constrained, KEY, 'gap'), {
    definition: { type: 'dimension' },
    constraints: { allowed: ['0', '1rem'] },
  });

  // A guide that defines no such property yields an empty definition (the
  // gap field reads it as a free dimension; an alignment row falls back to
  // its axis table), never a throw.
  assert.deepEqual(rootField({}, KEY, 'gap'), { definition: {}, constraints: {} });
  assert.deepEqual(rootField(guide, KEY, 'justify-items'), { definition: {}, constraints: {} });

  // An alignment axis the guide constrains: the resolved constraints
  // flow through so the row's fallback table gives way to the guide's.
  const aligned = {
    ...guide,
    enforcement: 'guarded',
    kinds: {
      container: {
        grants: { spacing: ['gap', 'justify-items'] },
        valueSpaces: { 'justify-items': { allowed: ['start', 'end'] } },
      },
    },
  };
  assert.deepEqual(rootField(aligned, KEY, 'justify-items'), {
    definition: {},
    constraints: { allowed: ['start', 'end'] },
  });
});

test('the Layout section owns gap exactly where it renders', () => {
  // An arrangeable container: the spacing control's gap row yields.
  assert.equal(layoutOwnsGap(layout, KEY), true);
  assert.equal(layoutOwnsGap(layout, KEY_B), true);
  // Everything else keeps the spacing row: blocks laying out multiple
  // fields, part drills, and containers the layout tree cannot arrange.
  assert.equal(layoutOwnsGap(layout, KEY_BLOCK), false);
  assert.equal(layoutOwnsGap(layout, `${KEY}::.some-part`), false);
  assert.equal(layoutOwnsGap([], KEY), false);
});

// --- the posed compound (the pose doctrine, both halves) ----------------

test('a row wear posed Narrow reads stack via the variant; the wear stays', () => {
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(arrangementState(worn, KEY, library, 'narrow', CONDITIONS), {
    mode: 'stack',
    wearing: ROW_VARIANT,
    via: { variant: ROW_VARIANT, condition: 'narrow' },
    customKeys: [],
    displayKeys: [],
    carriers: UNCLAIMED,
  });
  // A compound covering narrow stacks too, and names the whole compound
  // (the doorway drives the canvas back to exactly this pose).
  assert.deepEqual(arrangementState(worn, KEY, library, 'narrow+dark', CONDITIONS).via, {
    variant: ROW_VARIANT, condition: 'narrow+dark',
  });
  // A pose the variant says nothing at keeps the row.
  const dark = arrangementState(worn, KEY, library, 'dark', CONDITIONS);
  assert.equal(dark.mode, 'row');
  assert.equal(dark.via, null);
  // Unposed: unchanged, nothing is via anything.
  assert.equal(arrangementState(worn, KEY, library).via, null);
  // A wear the store cannot resolve has no posed blocks to stack with.
  assert.equal(arrangementState(worn, KEY, [], 'narrow', CONDITIONS).mode, 'row');
});

test('the posed upgrade under a stacking wear is the widget\'s own stack, not Custom', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: ROW_VARIANT, fragment: '' }],
      narrow: { display: 'grid', 'justify-items': 'center' },
    },
  };
  const narrow = arrangementState(map, KEY, library, 'narrow', CONDITIONS);
  assert.equal(narrow.mode, 'stack');
  assert.deepEqual(narrow.displayKeys, [KEY]);
  assert.deepEqual(narrow.carriers, { display: 'narrow', tracks: null });
  // At default the narrow block is off: plain Columns, no claim.
  assert.deepEqual(arrangementState(map, KEY, library), {
    mode: 'row', wearing: ROW_VARIANT, via: null, customKeys: [], displayKeys: [], carriers: UNCLAIMED,
  });
  // Columns on a wear that already stacks here changes no wear and leaves
  // the posed upgrade alone; Stack removes the wear as always.
  assert.deepEqual(modeOps(KEY, narrow, 'row'), []);
  assert.deepEqual(modeOps(KEY, narrow, 'stack'), [
    { op: 'remove_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // The same grid under a wear that still arranges a row at the pose is
  // two authorities over one mode: Custom.
  assert.equal(arrangementState(map, KEY, library, 'narrow', { dark: {} }).mode, 'stack');
  const rowAtDark = { [KEY]: { ...map[KEY], dark: { display: 'grid' } } };
  assert.equal(arrangementState(rowAtDark, KEY, library, 'dark', CONDITIONS).mode, 'custom');
});

test('an own claim reaches the pose through the cascade and clears where it lives', () => {
  // A default-block claim claims at Narrow too; the takeover retires it in
  // the default block (an unset at narrow would change nothing).
  const tracks = {
    [KEY]: { default: { display: 'grid', 'grid-template-columns': 'repeat(3, minmax(0,1fr))' } },
  };
  const posed = arrangementState(tracks, KEY, library, 'narrow', CONDITIONS);
  assert.equal(posed.mode, 'custom');
  assert.deepEqual(posed.carriers, { display: 'default', tracks: 'default' });
  assert.deepEqual(modeOps(KEY, posed, 'row'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // A claim written at narrow clears at narrow, and the property no block
  // carries rides the same op.
  const flexAtNarrow = { [KEY]: { narrow: { display: 'flex' } } };
  const narrow = arrangementState(flexAtNarrow, KEY, library, 'narrow', CONDITIONS);
  assert.deepEqual(narrow.carriers, { display: 'narrow', tracks: null });
  assert.deepEqual(modeOps(KEY, narrow, 'row'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { display: null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // Unposed, the narrow claim is off: a plain stack.
  assert.equal(arrangementState(flexAtNarrow, KEY, library).mode, 'stack');
  // Claims split across blocks clear in each.
  const split = {
    [KEY]: { default: { display: 'grid' }, narrow: { 'grid-template-columns': '1fr 2fr' } },
  };
  assert.deepEqual(modeOps(KEY, arrangementState(split, KEY, library, 'narrow', CONDITIONS), 'stack'), [
    { op: 'update_style', key: KEY, properties: { display: null } },
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'grid-template-columns': null } },
  ]);
  // The compound's own block wins over an inherited one.
  const both = { [KEY]: { default: { display: 'block' }, narrow: { display: 'grid' } } };
  assert.equal(arrangementState(both, KEY, library, 'narrow', CONDITIONS).mode, 'stack');
  assert.equal(arrangementState(both, KEY, library).mode, 'custom');
});

test('alignment reads the compound\'s own block and writes there', () => {
  const [horizontal] = alignmentAxes('stack');
  const centered = { [KEY]: { default: { display: 'grid', 'justify-items': 'center' } } };
  // The segment lights what THIS compound commits (a ControlWidget's
  // read): the default pick is not narrow's.
  assert.deepEqual(alignmentState(centered, KEY, horizontal, 'narrow'), { selected: null, custom: false, value: null });
  assert.equal(alignmentState(centered, KEY, horizontal).selected, 'center');
  // A pick lands at the compound; the default display already reaches
  // narrow through the cascade, so no upgrade rides along.
  assert.deepEqual(alignOps(centered, KEY, horizontal, 'end', 'stack', 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'justify-items': 'end' } },
  ]);
  // A first stack pick on a wear stacking at narrow takes the upgrade at
  // narrow with it.
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(alignOps(worn, KEY, horizontal, 'center', 'stack', 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'justify-items': 'center', display: 'grid' } },
  ]);
  // Unset retires only what the compound's own block carries.
  assert.deepEqual(alignOps(centered, KEY, horizontal, null, 'stack', 'narrow', CONDITIONS), []);
  const atNarrow = { [KEY]: { narrow: { 'justify-items': 'center' } } };
  assert.deepEqual(alignOps(atNarrow, KEY, horizontal, null, 'stack', 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'justify-items': null } },
  ]);
  // Unposed: the op spelling is unchanged (no condition on the wire).
  assert.deepEqual(alignOps({}, KEY, horizontal, 'center', 'stack'), [
    { op: 'update_style', key: KEY, properties: { 'justify-items': 'center', display: 'grid' } },
  ]);
});

test('grows read through the cascade at the pose and write at it', () => {
  const map = {
    [KEY_A]: { default: { 'flex-grow': '2' } },
    [KEY_B]: { narrow: { 'flex-grow': '3' } },
  };
  assert.deepEqual(childGrows(map, [KEY_A, KEY_B], 'narrow', CONDITIONS), [2, 3]);
  assert.deepEqual(childGrows(map, [KEY_A, KEY_B]), [2, 1]);
  assert.equal(growOf(map, KEY_B, 'dark', CONDITIONS), null);
  // Back to equal at narrow: A's default 2 still reaches narrow, so equal
  // is written out; B's own narrow weight unsets.
  assert.deepEqual(growOps([KEY_A, KEY_B], map, [1, 1], 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY_A, condition: 'narrow', properties: { 'flex-grow': '1' } },
    { op: 'update_style', key: KEY_B, condition: 'narrow', properties: { 'flex-grow': null } },
  ]);
  // A deviation at narrow lands at narrow; unchanged weights emit nothing.
  assert.deepEqual(growOps([KEY_A, KEY_B], map, [2, 1.5], 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY_B, condition: 'narrow', properties: { 'flex-grow': '1.5' } },
  ]);
  // A preset carries the compound into its grow writes.
  const plan = presetPlan({
    containerUuid: BAND,
    childKeys: [KEY_A, KEY_B],
    map,
    weights: [1, 2],
    generateUuid: () => { throw new Error('nothing to mint'); },
    condition: 'narrow',
    conditions: CONDITIONS,
  });
  assert.deepEqual(plan.operations, [
    { op: 'update_style', key: KEY_A, condition: 'narrow', properties: { 'flex-grow': '1' } },
    { op: 'update_style', key: KEY_B, condition: 'narrow', properties: { 'flex-grow': '2' } },
  ]);
});

test('the posed gap ghost is the layer stack at the pose; the write lands there', () => {
  const narrowGap = {
    ...rowVariant,
    value: {
      ...rowVariant.value,
      '': { default: { display: 'flex', gap: '16px' }, narrow: { 'flex-direction': 'column', gap: '8px' } },
    },
  };
  const worn = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  // The variant's own narrow gap shows at Narrow, its default elsewhere.
  assert.deepEqual(gapState(worn, KEY, [narrowGap], 'narrow', CONDITIONS), { raw: null, ghost: '8px' });
  assert.deepEqual(gapState(worn, KEY, [narrowGap], 'dark', CONDITIONS), { raw: null, ghost: '16px' });
  assert.deepEqual(gapState(worn, KEY, library, 'narrow', CONDITIONS), { raw: null, ghost: '16px' });
  // An own default gap reaches narrow first, as the ghost (the narrow
  // block commits nothing); an own narrow gap is the committed value.
  const own = { [KEY]: { ...worn[KEY], default: { gap: '24px' } } };
  assert.deepEqual(gapState(own, KEY, [narrowGap], 'narrow', CONDITIONS), { raw: null, ghost: '24px' });
  const atNarrow = { [KEY]: { ...own[KEY], narrow: { gap: '12px' } } };
  assert.deepEqual(gapState(atNarrow, KEY, [narrowGap], 'narrow', CONDITIONS), { raw: '12px', ghost: '24px' });
  assert.deepEqual(gapState(atNarrow, KEY, [narrowGap], 'default', CONDITIONS), { raw: '24px', ghost: '16px' });
  // A plain stack posed anywhere rests at zero.
  assert.deepEqual(gapState({}, KEY, library, 'narrow', CONDITIONS), { raw: null, ghost: '0px' });
  // The write lands at the compound; on a wear stacking there it is a
  // stack write and takes the upgrade with it.
  assert.deepEqual(gapValueOps(worn, KEY, '20px', 'stack', 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { gap: '20px', display: 'grid' } },
  ]);
  assert.deepEqual(gapValueOps(worn, KEY, '20px', 'row', 'dark', CONDITIONS), [
    { op: 'update_style', key: KEY, condition: 'dark', properties: { gap: '20px' } },
  ]);
  assert.deepEqual(gapValueOps(worn, KEY, null, 'stack', 'narrow', CONDITIONS), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { gap: null } },
  ]);
});

// --- count steps --------------------------------------------------------

test('the + is a pure add_section', () => {
  const uuid = '33333333-3333-4333-8333-333333333333';
  assert.deepEqual(addColumnPlan(BAND, () => uuid), {
    operations: [{ op: 'add_section', uuid, location: { anchor: BAND, placement: 'inside' } }],
    minted: uuid,
  });
});

test('the minus removes the trailing child; empties are silent', () => {
  const children = containerChildren(layout, BAND);
  const plan = removalPlan(children);
  assert.equal(plan.uuid, CHILD_B);
  assert.equal(plan.empty, true);
  assert.deepEqual(plan.operations, [{ op: 'remove_element', element: CHILD_B }]);
  // The trailing child holding content needs the confirm.
  const reversed = removalPlan([...children].reverse());
  assert.equal(reversed.uuid, CHILD_A);
  assert.equal(reversed.empty, false);
  // The last child stays: emptying a container is canvas work.
  assert.equal(removalPlan([children[0]]), null);
});

if (failures) {
  console.error(`${failures} failure(s).`);
  process.exit(1);
}
console.log('layout-widget suite passed.');
