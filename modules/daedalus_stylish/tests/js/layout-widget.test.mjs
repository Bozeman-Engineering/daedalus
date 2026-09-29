/**
 * Self-asserting tests for the layout widget's pure logic.
 *
 * Run directly (`node tests/js/layout-widget.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Locks mode + share's client half over the one-box container
 * (every read and write on the container ROOT — no part key exists): the
 * layout-tree reads, the arrangement state (stack / row / columns / the
 * honest Custom: any own display, flex-direction or grid-template-columns
 * is a hand claim, since the widget writes none of them), the proportion
 * strip math (snap scale, pair-preserving drag), the count-aware preset
 * recipes, the mode-scoped alignment axes (the folded-in Arrange
 * control over the flex vocabulary: a container is a flex column by
 * declaration and a flex row under either worn variant, so a pick
 * writes its property alone, and its rows light the container stack's
 * winner at the pose while the pick lands at the compound), the root
 * rows' facts (the Gap row's value as the container stack's winner at
 * the pose, stackAt over buildStack; rootField's definition + resolved
 * constraints per property, and under a write address the grant judged
 * at the address through write-address.js withheldAt while the
 * vocabulary stays the container's), and the exact
 * spine-op batches every gesture fires — the same shapes LayoutWidgetBatchTest pins server-side. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  COLUMNS_VARIANT,
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
  gapValueOps,
  rootField,
  layoutOwnsGap,
  addColumnPlan,
  removalPlan,
} from '../../js/src/layout-widget.js';
import { buildStack, stackAt } from '../../js/src/style-stack.js';
import { withheldAt } from '../../js/src/write-address.js';

// Drupal.t at call time, placeholders substituted, so the withheld
// reason under test reads as the row's title renders it.
globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce(
      (out, [key, value]) => out.split(key).join(String(value)),
      text,
    ),
};

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
 *  the children pseudo-part carries the equal fill. The declared tier
 *  ships the display (every container is a flex column); the variant
 *  flips the direction, and its narrow block IS the stack. */
const columnsVariant = {
  id: COLUMNS_VARIANT,
  label: 'Columns',
  scope: 'kind:container',
  group: 'container_layout',
  value: {
    '': {
      default: { 'flex-direction': 'row', gap: '16px' },
      narrow: { 'flex-direction': 'column' },
    },
    '::> *': {
      default: { 'flex-grow': '1', 'flex-shrink': '1', 'flex-basis': '0', 'min-width': '0' },
      narrow: { 'flex-grow': '0', 'flex-shrink': '0', 'flex-basis': 'auto' },
    },
  },
};

/** The shipped Row: a wrapping flex row of natural-width children on the
 *  root alone, no narrow block (wrapping IS its narrow behavior) and no
 *  children rule. */
const rowVariant = {
  id: ROW_VARIANT,
  label: 'Row',
  scope: 'kind:container',
  group: 'container_layout',
  value: {
    '': {
      default: { 'flex-direction': 'row', 'flex-wrap': 'wrap', gap: '16px' },
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

const library = [columnsVariant, rowVariant, widthVariant];

/** The guide's condition table (cascade order, pose labels). */
const CONDITIONS = {
  narrow: { label: 'Narrow' },
  dark: { label: 'Dark' },
  hover: { label: 'Hover' },
};

/** No own claim on the root at the pose. */
const UNCLAIMED = { display: null, direction: null, tracks: null };

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
    mode: 'stack', wearing: null, via: null, customKeys: [], carriers: UNCLAIMED,
  });
});

test('a worn arrangement-axis reference reads as columns', () => {
  const map = {
    [KEY]: {
      variants: [
        { variant: 'container_lg', fragment: '' },
        { variant: COLUMNS_VARIANT, fragment: '' },
      ],
    },
  };
  assert.deepEqual(arrangementState(map, KEY, library), {
    mode: 'columns', wearing: COLUMNS_VARIANT, via: null, customKeys: [], carriers: UNCLAIMED,
  });
});

test('the shipped names resolve while the library is still loading', () => {
  const map = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.equal(arrangementState(map, KEY, []).mode, 'columns');
  const row = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.equal(arrangementState(row, KEY, []).mode, 'row');
});

test('a worn row reads as row: the mode id is the worn shipped name', () => {
  const map = {
    [KEY]: {
      variants: [
        { variant: 'container_lg', fragment: '' },
        { variant: ROW_VARIANT, fragment: '' },
      ],
    },
  };
  assert.deepEqual(arrangementState(map, KEY, library), {
    mode: 'row', wearing: ROW_VARIANT, via: null, customKeys: [], carriers: UNCLAIMED,
  });
  // Row ships no narrow block (it wraps, never stacks), so it stays a
  // row at every pose and is via nothing.
  assert.deepEqual(arrangementState(map, KEY, library, 'narrow', CONDITIONS), {
    mode: 'row', wearing: ROW_VARIANT, via: null, customKeys: [], carriers: UNCLAIMED,
  });
  // An own display under a Row is Custom too, still reporting the wear.
  const claimed = { [KEY]: { ...map[KEY], default: { display: 'grid' } } };
  assert.equal(arrangementState(claimed, KEY, library).mode, 'custom');
  assert.equal(arrangementState(claimed, KEY, library).wearing, ROW_VARIANT);
});

test('a foreign arrangement wear reads as stack while reporting the wear', () => {
  // A design system's own container_layout variant is neither shipped
  // mode: the segment lights nothing, the takeover replaces it within
  // the axis.
  const cluster = { id: 'cluster', label: 'Cluster', scope: 'kind:container', group: 'container_layout', value: { '': { default: { 'flex-direction': 'row' } } } };
  const map = { [KEY]: { variants: [{ variant: 'cluster', fragment: '' }] } };
  const state = arrangementState(map, KEY, [...library, cluster]);
  assert.equal(state.mode, 'stack');
  assert.equal(state.wearing, 'cluster');
  assert.deepEqual(modeOps(KEY, state, 'row'), [
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  assert.deepEqual(modeOps(KEY, state, 'stack'), [
    { op: 'remove_variant', key: KEY, variant: 'cluster' },
  ]);
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
    carriers: { display: 'default', direction: null, tracks: 'default' },
  });
  // ANY own display is a hand claim: the widget writes none (the
  // declared tier makes every container a flex box), so a restated flex
  // and a grid read alike.
  const flex = { [KEY]: { default: { display: 'flex' } } };
  assert.deepEqual(arrangementState(flex, KEY, library).customKeys, [KEY]);
  const grid = { [KEY]: { default: { display: 'grid' } } };
  assert.equal(arrangementState(grid, KEY, library).mode, 'custom');
  // Custom still reports what the entry wears, for the takeover batch —
  // an own display UNDER a worn arrangement reference is Custom too.
  const wornToo = {
    [KEY]: {
      variants: [{ variant: COLUMNS_VARIANT, fragment: '' }],
      default: { display: 'grid' },
    },
  };
  const state = arrangementState(wornToo, KEY, library);
  assert.equal(state.mode, 'custom');
  assert.equal(state.wearing, COLUMNS_VARIANT);
});

test('an own direction claims the mode: Custom, on a bare root and under a wear', () => {
  // A hand-written flex-direction is the arrangement axis's business
  // (the axis owns it), so the root reads Custom even when the value
  // restates the declared column.
  const column = { [KEY]: { default: { 'flex-direction': 'column' } } };
  assert.deepEqual(arrangementState(column, KEY, library), {
    mode: 'custom',
    wearing: null,
    via: null,
    customKeys: [KEY],
    carriers: { display: null, direction: 'default', tracks: null },
  });
  // Under a worn row, an own row direction is a second authority over
  // the mode: Custom, still reporting the wear.
  const wornRow = {
    [KEY]: {
      variants: [{ variant: COLUMNS_VARIANT, fragment: '' }],
      default: { 'flex-direction': 'row' },
    },
  };
  const state = arrangementState(wornRow, KEY, library);
  assert.equal(state.mode, 'custom');
  assert.equal(state.wearing, COLUMNS_VARIANT);
  assert.deepEqual(state.carriers, { display: null, direction: 'default', tracks: null });
});

test('the widget\'s own alignment words are stack state, never Custom', () => {
  const aligned = { [KEY]: { default: { 'align-items': 'center', 'justify-content': 'flex-end' } } };
  assert.deepEqual(arrangementState(aligned, KEY, library), {
    mode: 'stack', wearing: null, via: null, customKeys: [], carriers: UNCLAIMED,
  });
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

test('stack to columns applies the shipped columns variant', () => {
  const state = arrangementState({}, KEY, library);
  assert.deepEqual(modeOps(KEY, state, 'columns'), [
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
});

test('columns to stack removes the worn reference, grows stay untouched', () => {
  const map = {
    [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] },
    [KEY_A]: { default: { 'flex-grow': '2' } },
  };
  const state = arrangementState(map, KEY, library);
  assert.deepEqual(modeOps(KEY, state, 'stack'), [
    { op: 'remove_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
});

test('a takeover from Custom clears the hand-written root first', () => {
  const map = {
    [KEY]: { default: { display: 'grid', 'grid-template-columns': 'repeat(3, minmax(0,1fr))' } },
  };
  const state = arrangementState(map, KEY, library);
  assert.deepEqual(modeOps(KEY, state, 'columns'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  // Custom over an already-worn row: the clear alone restores Columns.
  const wornToo = {
    [KEY]: { ...map[KEY], variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] },
  };
  assert.deepEqual(modeOps(KEY, arrangementState(wornToo, KEY, library), 'columns'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
  ]);
});

test('a takeover clears an own direction where it lives', () => {
  // The segment click is the takeover: the hand-written direction
  // retires in its block and the axis changes hands (Columns applies the
  // variant, Stack removes nothing since nothing is worn).
  const column = { [KEY]: { default: { 'flex-direction': 'column' } } };
  assert.deepEqual(modeOps(KEY, arrangementState(column, KEY, library), 'columns'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  assert.deepEqual(modeOps(KEY, arrangementState(column, KEY, library), 'stack'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
  ]);
  // Under a worn row, Stack clears the direction AND removes the wear.
  const wornRow = {
    [KEY]: {
      variants: [{ variant: COLUMNS_VARIANT, fragment: '' }],
      narrow: { 'flex-direction': 'row' },
    },
  };
  const posed = arrangementState(wornRow, KEY, library, 'narrow', CONDITIONS);
  assert.equal(posed.mode, 'custom');
  assert.deepEqual(modeOps(KEY, posed, 'stack'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
    { op: 'remove_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  // Unposed, the narrow claim is off: a plain worn row.
  assert.equal(arrangementState(wornRow, KEY, library).mode, 'columns');
});

test('a no-change click fires nothing', () => {
  assert.deepEqual(modeOps(KEY, arrangementState({}, KEY, library), 'stack'), []);
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.deepEqual(modeOps(KEY, arrangementState(worn, KEY, library), 'columns'), []);
  const wornRow = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(modeOps(KEY, arrangementState(wornRow, KEY, library), 'row'), []);
});

test('stack to row applies the shipped row variant; row to stack removes it', () => {
  assert.deepEqual(modeOps(KEY, arrangementState({}, KEY, library), 'row'), [
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  const worn = {
    [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] },
    [KEY_A]: { default: { 'flex-grow': '2' } },
  };
  // Grows are Columns state; a Row leaves them in the map, inert.
  assert.deepEqual(modeOps(KEY, arrangementState(worn, KEY, library), 'stack'), [
    { op: 'remove_variant', key: KEY, variant: ROW_VARIANT },
  ]);
});

test('row to columns and back is one apply_variant: within-axis replace', () => {
  const wornRow = { [KEY]: { variants: [{ variant: ROW_VARIANT, fragment: '' }] } };
  assert.deepEqual(modeOps(KEY, arrangementState(wornRow, KEY, library), 'columns'), [
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  const wornColumns = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.deepEqual(modeOps(KEY, arrangementState(wornColumns, KEY, library), 'row'), [
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // Posed where Columns stacks, Row still applies: the wear is what the
  // segment changes, whatever the pose reads.
  const posed = arrangementState(wornColumns, KEY, library, 'narrow', CONDITIONS);
  assert.equal(posed.mode, 'stack');
  assert.deepEqual(modeOps(KEY, posed, 'row'), [
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
  // A takeover from Custom onto Row clears the hand claims first.
  const custom = { [KEY]: { default: { 'flex-direction': 'row', 'flex-wrap': 'wrap' } } };
  assert.deepEqual(modeOps(KEY, arrangementState(custom, KEY, library), 'row'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: ROW_VARIANT },
  ]);
});

test('columns over an aligned stack applies the variant alone, alignment stays inert', () => {
  const map = {
    [KEY]: { default: { 'align-items': 'center', 'justify-content': 'center' } },
  };
  assert.deepEqual(modeOps(KEY, arrangementState(map, KEY, library), 'columns'), [
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  // Clicking Stack on an aligned stack changes nothing: alignment IS
  // stack state.
  assert.deepEqual(modeOps(KEY, arrangementState(map, KEY, library), 'stack'), []);
});

// --- alignment (the folded-in Arrange control) ---------------------------

test('alignment axes are mode-scoped', () => {
  assert.deepEqual(alignmentAxes('stack').map((axis) => [axis.label, axis.property]), [
    ['Horizontal', 'align-items'],
    ['Vertical', 'justify-content'],
  ]);
  assert.deepEqual(alignmentAxes('row').map((axis) => [axis.label, axis.property]), [
    ['Horizontal', 'justify-content'],
    ['Vertical', 'align-items'],
  ]);
  assert.deepEqual(alignmentAxes('columns').map((axis) => [axis.label, axis.property]), [
    ['Vertical', 'align-items'],
  ]);
  assert.deepEqual(alignmentAxes('custom'), []);
  // A Row's main axis carries four stops: the three flex stops plus
  // Spread (space-between), the one distribution natural-width items
  // can take; its cross axis is the same three as Columns'.
  const [rowHorizontal, rowVertical] = alignmentAxes('row');
  assert.deepEqual(rowHorizontal.stops.map((stop) => [stop.id, stop.label, stop.value]), [
    ['start', 'Left', 'flex-start'],
    ['center', 'Center', 'center'],
    ['end', 'Right', 'flex-end'],
    ['spread', 'Spread', 'space-between'],
  ]);
  assert.deepEqual(rowVertical.stops.map((stop) => stop.label), ['Top', 'Middle', 'Bottom']);
  // Spread exists only there: a stack's vertical main axis and a
  // Columns' cross axis keep three.
  assert.equal(alignmentAxes('stack').some((axis) => axis.stops.some((stop) => stop.id === 'spread')), false);
  assert.equal(alignmentAxes('columns').some((axis) => axis.stops.some((stop) => stop.id === 'spread')), false);
  // The stops face the screen axis: the same flex spellings read Left /
  // Center / Right across the column and Top / Middle / Bottom along it
  // (and across the row).
  const [horizontal, vertical] = alignmentAxes('stack');
  assert.deepEqual(horizontal.stops.map((stop) => [stop.label, stop.value]), [
    ['Left', 'flex-start'], ['Center', 'center'], ['Right', 'flex-end'],
  ]);
  assert.deepEqual(vertical.stops.map((stop) => [stop.label, stop.value]), [
    ['Top', 'flex-start'], ['Middle', 'center'], ['Bottom', 'flex-end'],
  ]);
  assert.deepEqual(alignmentAxes('columns')[0].stops.map((stop) => stop.label), ['Top', 'Middle', 'Bottom']);
});

/** An alignment row's read: the container root's stack at the pose (the
 *  layout section's rootFacts + stackAt), the axis property's winner
 *  and own. The Gap row's read (gapAt below), one property wider. */
const alignAt = (map, property, condition = 'default', variants = library, guide = {}) =>
  stackAt(buildStack({ guide, map, key: KEY, variants }), property, condition, CONDITIONS);

test('alignment state lights the container root\'s winner', () => {
  const [horizontal, vertical] = alignmentAxes('stack');
  assert.deepEqual(alignmentState(alignAt({}, 'align-items'), horizontal), {
    selected: null, custom: false, value: null,
  });
  // A row with no stack reads the same honest nothing.
  assert.deepEqual(alignmentState(null, horizontal), {
    selected: null, custom: false, value: null,
  });
  const aligned = { [KEY]: { default: { 'align-items': 'center' } } };
  assert.equal(alignmentState(alignAt(aligned, 'align-items'), horizontal).selected, 'center');
  // The modern aliases light the stop; unrepresentable values are
  // explicitly custom.
  const alias = { [KEY]: { default: { 'justify-content': 'end' } } };
  assert.equal(alignmentState(alignAt(alias, 'justify-content'), vertical).selected, 'end');
  assert.equal(alignmentState(alignAt({ [KEY]: { default: { 'align-items': 'start' } } }, 'align-items'), horizontal).selected, 'start');
  const custom = { [KEY]: { default: { 'justify-content': 'space-between' } } };
  assert.deepEqual(alignmentState(alignAt(custom, 'justify-content'), vertical), {
    selected: null, custom: true, value: 'space-between',
  });
  // The winner lights whatever layer delivers it (spine B, the Gap
  // row's read): a worn Columns variant's alignment on its cross axis
  // with nothing own (the row's mark wears the variant's hue, Unset is
  // not offered), and the active skin's beneath everything worn.
  const centeringRow = {
    ...columnsVariant,
    value: { '': { default: { 'flex-direction': 'row', 'align-items': 'center' } } },
  };
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  const [rowVertical] = alignmentAxes('columns');
  const viaVariant = alignAt(worn, 'align-items', 'default', [centeringRow]);
  assert.equal(viaVariant.winner.tier, 'variant');
  assert.equal(viaVariant.own, null, 'nothing own: not unsettable');
  assert.equal(alignmentState(viaVariant, rowVertical).selected, 'center');
  const skinned = { skinDefaults: { 'kind:container': { '': { default: { 'align-items': 'flex-end' } } } } };
  const viaSkin = alignAt({}, 'align-items', 'default', library, skinned);
  assert.equal(viaSkin.winner.id, 'skin:kind:container');
  assert.equal(alignmentState(viaSkin, horizontal).selected, 'end');
});

test('a stack pick writes its flex property alone, no display ever rides along', () => {
  const [horizontal, vertical] = alignmentAxes('stack');
  assert.deepEqual(alignOps({}, KEY, horizontal, 'flex-start'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'align-items': 'flex-start' },
    },
  ]);
  // The second axis on an aligned root: its own property, nothing else.
  const aligned = { [KEY]: { default: { 'align-items': 'flex-start' } } };
  assert.deepEqual(alignOps(aligned, KEY, vertical, 'center'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'justify-content': 'center' },
    },
  ]);
});

test('unset clears only its own property and never adds companions', () => {
  const [horizontal] = alignmentAxes('stack');
  const aligned = { [KEY]: { default: { 'align-items': 'center', 'justify-content': 'center' } } };
  assert.deepEqual(alignOps(aligned, KEY, horizontal, null), [
    { op: 'update_style', key: KEY, properties: { 'align-items': null } },
  ]);
  assert.deepEqual(alignOps({}, KEY, horizontal, null), []);
});

test('the columns cross-axis pick writes align-items alone', () => {
  const [cross] = alignmentAxes('columns');
  assert.deepEqual(alignOps({}, KEY, cross, 'center'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'align-items': 'center' },
    },
  ]);
});

test('a Spread pick on a Row writes justify-content: space-between alone', () => {
  const [horizontal, vertical] = alignmentAxes('row');
  const spread = horizontal.stops.find((stop) => stop.id === 'spread');
  assert.deepEqual(alignOps({}, KEY, horizontal, spread.value), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'justify-content': 'space-between' },
    },
  ]);
  // The row's cross axis is the same one-property write as Columns'.
  assert.deepEqual(alignOps({}, KEY, vertical, 'flex-end'), [
    {
      op: 'update_style',
      key: KEY,
      properties: { 'align-items': 'flex-end' },
    },
  ]);
  // The written value lights Spread on the Row's main axis, and reads
  // as the stack's honest custom on its vertical one (no Spread there).
  const worn = {
    [KEY]: {
      variants: [{ variant: ROW_VARIANT, fragment: '' }],
      default: { 'justify-content': 'space-between' },
    },
  };
  assert.deepEqual(alignmentState(alignAt(worn, 'justify-content'), horizontal), {
    selected: 'spread', custom: false, value: null,
  });
  const [, stackVertical] = alignmentAxes('stack');
  assert.equal(alignmentState(alignAt(worn, 'justify-content'), stackVertical).custom, true);
  // The pick lands at the compound like every other alignment write.
  assert.deepEqual(alignOps({}, KEY, horizontal, spread.value, 'narrow'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'justify-content': 'space-between' } },
  ]);
});

// --- gap ----------------------------------------------------------------

/** The Gap row's read: the container root's stack at the pose (the
 *  layout section's rootFacts + stackAt), `gap`'s winner and own. */
const gapAt = (map, variants = library, condition = 'default', guide = {}) =>
  stackAt(buildStack({ guide, map, key: KEY, variants }), 'gap', condition, CONDITIONS);

test('the gap row reads the worn variant\'s gap and the own override off the stack', () => {
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  const read = gapAt(worn);
  assert.equal(read.winner.value, '16px');
  assert.equal(read.winner.tier, 'variant');
  assert.equal(read.own, null, 'nothing own: not unsettable');
  const overridden = {
    [KEY]: { ...worn[KEY], default: { gap: '24px' } },
  };
  const own = gapAt(overridden);
  assert.equal(own.winner.value, '24px');
  assert.equal(own.winner.tier, 'element');
  assert.equal(own.own.condition, 'default', 'the own block at the pose: unsettable');
  assert.equal(own.beneath[0].value, '16px', 'what an unset returns to');
  // Any spelling reports verbatim: the field wears it, never a parse.
  assert.equal(gapAt({ [KEY]: { ...worn[KEY], default: { gap: '2rem' } } }).winner.value, '2rem');
  assert.equal(gapAt({ [KEY]: { ...worn[KEY], default: { gap: 'var(--space-4)' } } }).winner.value, 'var(--space-4)');
});

test('a push-restyled variant default follows the library', () => {
  const restyled = {
    ...columnsVariant,
    value: { '': { default: { 'flex-direction': 'row', gap: '24px' } } },
  };
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.equal(gapAt(worn, [restyled]).winner.value, '24px');
});

test('a gap write overrides on the root; reset unsets back to the default', () => {
  assert.deepEqual(gapValueOps(KEY, '24px'), [
    { op: 'update_style', key: KEY, properties: { gap: '24px' } },
  ]);
  assert.deepEqual(gapValueOps(KEY, null), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('stack-mode gap: the declared floor, the write is the gap alone', () => {
  // No worn arrangement and nothing declared: honestly unset (the
  // widget invents no literal).
  assert.equal(gapAt({}).winner, null);
  // The container declaration's `gap: 0px` (daedalus_stylish_layouts)
  // arrives as the declared layer: the flex column's initial zero gap,
  // lit and named as the declaration's.
  const declared = { declaredDefaults: { 'kind:container': { '': { default: { display: 'flex', 'flex-direction': 'column', gap: '0px' } } } } };
  const floor = gapAt({}, library, 'default', declared);
  assert.equal(floor.winner.value, '0px');
  assert.equal(floor.winner.tier, 'declared');
  assert.equal(floor.winner.id, 'declared:kind:container');
  // A skin's gap and a worn row's gap beat it in turn.
  const skinned = { ...declared, skinDefaults: { 'kind:container': { '': { default: { gap: '1rem' } } } } };
  assert.equal(gapAt({}, library, 'default', skinned).winner.id, 'skin:kind:container');
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.equal(gapAt(worn, library, 'default', skinned).winner.value, '16px');
  // A stack-mode gap is the gap alone on the root: the declared flex
  // column lays it out the moment it lands, no display rides along.
  assert.deepEqual(gapValueOps(KEY, '8px'), [
    { op: 'update_style', key: KEY, properties: { gap: '8px' } },
  ]);
  // The reset gesture is the same one-property op.
  assert.deepEqual(gapValueOps(KEY, null), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
});

test('every field gesture speaks the one value-level primitive', () => {
  // A scrubbed or typed value is the same batch a stop pick fires, value
  // for value, on the one box (there is no second carrier to migrate or
  // clear).
  assert.deepEqual(gapValueOps(KEY, '2rem'), [
    { op: 'update_style', key: KEY, properties: { gap: '2rem' } },
  ]);
  assert.deepEqual(gapValueOps(KEY, '20px'), [
    { op: 'update_style', key: KEY, properties: { gap: '20px' } },
  ]);
  // An emptied field is the unset gesture, companions never added.
  assert.deepEqual(gapValueOps(KEY, null), [
    { op: 'update_style', key: KEY, properties: { gap: null } },
  ]);
  // A stop pick commits its var() reference through the same primitive.
  assert.deepEqual(gapValueOps(KEY, 'var(--space-4)'), [
    { op: 'update_style', key: KEY, properties: { gap: 'var(--space-4)' } },
  ]);
});

test('a root row reads its property\'s definition and the resolved constraints', () => {
  const guide = {
    groups: { spacing: { properties: { gap: { type: 'dimension' } } } },
    kinds: {
      container: { grants: { spacing: ['gap', 'align-items'] } },
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
        grants: { spacing: ['gap', 'align-items'] },
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
  assert.deepEqual(rootField(guide, KEY, 'align-items'), { definition: {}, constraints: {} });

  // An alignment axis the guide constrains: the resolved constraints
  // flow through so the row's fallback table gives way to the guide's.
  const aligned = {
    ...guide,
    enforcement: 'guarded',
    kinds: {
      container: {
        grants: { spacing: ['gap', 'align-items'] },
        valueSpaces: { 'align-items': { allowed: ['start', 'end'] } },
      },
    },
  };
  assert.deepEqual(rootField(aligned, KEY, 'align-items'), {
    definition: {},
    constraints: { allowed: ['start', 'end'] },
  });
});

test('under a write address the rows keep the container\'s vocabulary and judge the grant at the address', () => {
  // The container declaration grants the gap and both alignment axes
  // with a closed gap scale; its `children` node (where the band's
  // `> *` rule lands, style-guide.js land()) grants the flex-item
  // family and none of the three.
  const guide = {
    groups: { spacing: { properties: { gap: { type: 'dimension' } } } },
    kinds: {
      container: {
        grants: { spacing: ['gap', 'align-items', 'justify-content'] },
        valueSpaces: { gap: { allowed: ['0', '1rem'] } },
        children: { grants: { flex: ['flex-grow', 'flex-basis'] } },
      },
    },
  };
  const tree = [{
    kind: 'container',
    uuid: BAND,
    layout_id: 'daedalus_stylish_container',
    label: 'Welcome',
    _element: { name: 'Welcome', type: 'Section', markup: { tag: 'section', parts: {} } },
    children: [{
      kind: 'container',
      uuid: CHILD_A,
      layout_id: 'daedalus_stylish_container',
      _element: { name: 'Container', type: 'Container', markup: { tag: 'div', parts: {} } },
      children: [],
    }],
  }];
  const properties = ['gap', 'align-items', 'justify-content'];
  // The child container selected, the band's children rule seated as
  // the write address: the rows' VOCABULARY is still the selection's
  // (rootField over the selected key: a grant is a yes or no, not a
  // different scale) ...
  const address = { key: KEY, selector: '> *' };
  assert.deepEqual(rootField(guide, KEY_A, 'gap'), {
    definition: { type: 'dimension' },
    constraints: { allowed: ['0', '1rem'] },
  });
  // ... while the GRANT is the address's landing's: the roster's read
  // (withheldAt, the judge the pipeline's `withheld` and the Advanced
  // roster use) withholds all three with the reason the disabled row
  // wears as its title, the owner named from the tree.
  const withheld = withheldAt({ guide, address, properties, tree });
  assert.deepEqual(Object.keys(withheld), properties);
  assert.equal(
    withheld.gap,
    'gap is not allowed at "> *" on Welcome: the style guide does not grant it there. Choose this element alone to set it here.',
  );
  assert.equal(
    withheld['align-items'],
    'align-items is not allowed at "> *" on Welcome: the style guide does not grant it there. Choose this element alone to set it here.',
  );
  // Without a tree the owner has no node to be named from: the generic word.
  assert.equal(
    withheldAt({ guide, address, properties: ['gap'] }).gap,
    'gap is not allowed at "> *" on the parent element: the style guide does not grant it there. Choose this element alone to set it here.',
  );
  // An address whose landing grants the rows' properties withholds
  // nothing: a `> pb-container` type rule lands on the container kind's
  // own declaration, the same grant the selection reads.
  assert.deepEqual(
    withheldAt({ guide, address: { key: KEY, selector: '> pb-container' }, properties, tree }),
    {},
  );
});

test('the Layout section owns gap exactly where it renders', () => {
  // An arrangeable container: the spacing control's gap row yields.
  assert.equal(layoutOwnsGap(layout, KEY), true);
  assert.equal(layoutOwnsGap(layout, KEY_B), true);
  // Everything else keeps the spacing row: blocks laying out multiple
  // fields, part drills, and containers the layout tree cannot arrange.
  assert.equal(layoutOwnsGap(layout, KEY_BLOCK), false);
  assert.equal(layoutOwnsGap(layout, `${KEY}::.some-part`), false);
  // A container's children rule (a selector key) never owns the
  // container's gap story: the Layout section keeps the container's gap
  // while the children's rows style the children themselves.
  assert.equal(layoutOwnsGap(layout, `${KEY}::> *`), false);
  assert.equal(layoutOwnsGap(layout, `${KEY}::> pb-text`), false);
  assert.equal(layoutOwnsGap([], KEY), false);
});

// --- the posed compound (the pose doctrine, both halves) ----------------

test('a row wear posed Narrow reads stack via the variant; the wear stays', () => {
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.deepEqual(arrangementState(worn, KEY, library, 'narrow', CONDITIONS), {
    mode: 'stack',
    wearing: COLUMNS_VARIANT,
    via: { variant: COLUMNS_VARIANT, condition: 'narrow' },
    customKeys: [],
    carriers: UNCLAIMED,
  });
  // A compound covering narrow stacks too, and names the whole compound
  // (the doorway drives the canvas back to exactly this pose).
  assert.deepEqual(arrangementState(worn, KEY, library, 'narrow+dark', CONDITIONS).via, {
    variant: COLUMNS_VARIANT, condition: 'narrow+dark',
  });
  // A pose the variant says nothing at keeps the row.
  const dark = arrangementState(worn, KEY, library, 'dark', CONDITIONS);
  assert.equal(dark.mode, 'columns');
  assert.equal(dark.via, null);
  // Unposed: unchanged, nothing is via anything.
  assert.equal(arrangementState(worn, KEY, library).via, null);
  // A wear the store cannot resolve has no posed blocks to stack with.
  assert.equal(arrangementState(worn, KEY, [], 'narrow', CONDITIONS).mode, 'columns');
});

test('posed alignment under a stacking wear is stack state; a posed display is Custom', () => {
  // The widget's own words at narrow under a wear stacking there: the
  // variant's flex column is the stack, the alignment rides it.
  const aligned = {
    [KEY]: {
      variants: [{ variant: COLUMNS_VARIANT, fragment: '' }],
      narrow: { 'align-items': 'center' },
    },
  };
  const narrow = arrangementState(aligned, KEY, library, 'narrow', CONDITIONS);
  assert.equal(narrow.mode, 'stack');
  assert.deepEqual(narrow.via, { variant: COLUMNS_VARIANT, condition: 'narrow' });
  assert.deepEqual(narrow.carriers, UNCLAIMED);
  // Columns on a wear that already stacks here changes no wear; Stack
  // removes the wear as always, alignment left in place.
  assert.deepEqual(modeOps(KEY, narrow, 'columns'), []);
  assert.deepEqual(modeOps(KEY, narrow, 'stack'), [
    { op: 'remove_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  // A hand-written display at the pose is a claim wherever it sits,
  // under a stacking wear or one still arranging a row there.
  const posedDisplay = { [KEY]: { ...aligned[KEY], narrow: { display: 'grid' } } };
  const claimed = arrangementState(posedDisplay, KEY, library, 'narrow', CONDITIONS);
  assert.equal(claimed.mode, 'custom');
  assert.deepEqual(claimed.carriers, { display: 'narrow', direction: null, tracks: null });
  // At default the narrow block is off: plain Columns, no claim.
  assert.deepEqual(arrangementState(posedDisplay, KEY, library), {
    mode: 'columns', wearing: COLUMNS_VARIANT, via: null, customKeys: [], carriers: UNCLAIMED,
  });
  const rowAtDark = { [KEY]: { ...aligned[KEY], dark: { display: 'grid' } } };
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
  assert.deepEqual(posed.carriers, { display: 'default', direction: null, tracks: 'default' });
  assert.deepEqual(modeOps(KEY, posed, 'columns'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  // A claim written at narrow clears at narrow, and the properties no
  // block carries ride the same op.
  const flexAtNarrow = { [KEY]: { narrow: { display: 'flex' } } };
  const narrow = arrangementState(flexAtNarrow, KEY, library, 'narrow', CONDITIONS);
  assert.deepEqual(narrow.carriers, { display: 'narrow', direction: null, tracks: null });
  assert.deepEqual(modeOps(KEY, narrow, 'columns'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { display: null, 'flex-direction': null, 'grid-template-columns': null } },
    { op: 'apply_variant', key: KEY, variant: COLUMNS_VARIANT },
  ]);
  // Unposed, the narrow claim is off: a plain stack.
  assert.equal(arrangementState(flexAtNarrow, KEY, library).mode, 'stack');
  // Claims split across blocks clear in each; the property no block
  // carries rides the first claiming block's op.
  const split = {
    [KEY]: { default: { display: 'grid' }, narrow: { 'grid-template-columns': '1fr 2fr' } },
  };
  assert.deepEqual(modeOps(KEY, arrangementState(split, KEY, library, 'narrow', CONDITIONS), 'stack'), [
    { op: 'update_style', key: KEY, properties: { display: null, 'flex-direction': null } },
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'grid-template-columns': null } },
  ]);
  // The compound's own block is the carrier over an inherited one.
  const both = { [KEY]: { default: { display: 'block' }, narrow: { display: 'grid' } } };
  assert.equal(arrangementState(both, KEY, library, 'narrow', CONDITIONS).carriers.display, 'narrow');
  assert.equal(arrangementState(both, KEY, library).carriers.display, 'default');
});

test('alignment lights the winner at the pose and writes at the compound', () => {
  const [horizontal] = alignmentAxes('stack');
  const centered = { [KEY]: { default: { 'align-items': 'center' } } };
  // The segment lights the stack's winner at the pose (the Gap row's
  // read): the default block's centre reaches narrow through the
  // condition cascade and lights there as the element's own layer
  // delivering from Base, while the own block AT narrow says nothing
  // (so the row offers no Unset: a null write there would remove
  // nothing, alignOps below).
  const posed = alignAt(centered, 'align-items', 'narrow');
  assert.equal(posed.winner.tier, 'element');
  assert.equal(posed.winner.condition, 'default');
  assert.deepEqual(alignmentState(posed, horizontal), { selected: 'center', custom: false, value: null });
  assert.equal(posed.own.condition, 'default', 'own delivers from default, not at the pose');
  assert.equal(alignmentState(alignAt(centered, 'align-items'), horizontal).selected, 'center');
  // An own narrow block beats default at narrow and is what lights
  // there; unposed, default's centre is back.
  const both = { [KEY]: { default: { 'align-items': 'center' }, narrow: { 'align-items': 'flex-end' } } };
  assert.equal(alignmentState(alignAt(both, 'align-items', 'narrow'), horizontal).selected, 'end');
  assert.equal(alignmentState(alignAt(both, 'align-items'), horizontal).selected, 'center');
  // A pick lands at the compound, its property alone.
  assert.deepEqual(alignOps(centered, KEY, horizontal, 'flex-end', 'narrow'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'align-items': 'flex-end' } },
  ]);
  // A stack pick on a wear stacking at narrow lands at narrow, no
  // companion: the variant's narrow block IS the stack.
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  assert.deepEqual(alignOps(worn, KEY, horizontal, 'center', 'narrow'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'align-items': 'center' } },
  ]);
  // Unset retires only what the compound's own block carries.
  assert.deepEqual(alignOps(centered, KEY, horizontal, null, 'narrow'), []);
  const atNarrow = { [KEY]: { narrow: { 'align-items': 'center' } } };
  assert.deepEqual(alignOps(atNarrow, KEY, horizontal, null, 'narrow'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { 'align-items': null } },
  ]);
  // Unposed: the op spelling is unchanged (no condition on the wire).
  assert.deepEqual(alignOps({}, KEY, horizontal, 'center'), [
    { op: 'update_style', key: KEY, properties: { 'align-items': 'center' } },
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

test('the posed gap is the layer stack at the pose; the write lands there', () => {
  const narrowGap = {
    ...columnsVariant,
    value: {
      ...columnsVariant.value,
      '': { default: { 'flex-direction': 'row', gap: '16px' }, narrow: { 'flex-direction': 'column', gap: '8px' } },
    },
  };
  const worn = { [KEY]: { variants: [{ variant: COLUMNS_VARIANT, fragment: '' }] } };
  // The variant's own narrow gap shows at Narrow, its default elsewhere.
  assert.deepEqual([gapAt(worn, [narrowGap], 'narrow').winner.value, gapAt(worn, [narrowGap], 'narrow').winner.condition], ['8px', 'narrow']);
  assert.equal(gapAt(worn, [narrowGap], 'dark').winner.value, '16px');
  assert.equal(gapAt(worn, library, 'narrow').winner.value, '16px');
  // An own default gap reaches narrow first, lit as the own layer's from
  // Base (the narrow block commits nothing, so not unsettable there);
  // an own narrow gap is the block at the pose.
  const own = { [KEY]: { ...worn[KEY], default: { gap: '24px' } } };
  const posed = gapAt(own, [narrowGap], 'narrow');
  assert.deepEqual([posed.winner.value, posed.winner.tier, posed.own.condition], ['24px', 'element', 'default']);
  const atNarrow = { [KEY]: { ...own[KEY], narrow: { gap: '12px' } } };
  const narrow = gapAt(atNarrow, [narrowGap], 'narrow');
  assert.deepEqual([narrow.winner.value, narrow.own.condition, narrow.beneath[0].value], ['12px', 'narrow', '8px']);
  assert.equal(gapAt(atNarrow, [narrowGap], 'default').winner.value, '24px');
  // A plain stack posed anywhere with nothing declared is unset.
  assert.equal(gapAt({}, library, 'narrow').winner, null);
  // The write lands at the compound, the gap alone in either mode (on a
  // wear stacking there the variant's narrow block IS the stack).
  assert.deepEqual(gapValueOps(KEY, '20px', 'narrow'), [
    { op: 'update_style', key: KEY, condition: 'narrow', properties: { gap: '20px' } },
  ]);
  assert.deepEqual(gapValueOps(KEY, '20px', 'dark'), [
    { op: 'update_style', key: KEY, condition: 'dark', properties: { gap: '20px' } },
  ]);
  assert.deepEqual(gapValueOps(KEY, null, 'narrow'), [
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
