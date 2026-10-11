/**
 * Self-asserting tests for the page elements panel's drop surface
 * (tree-gaps.js): the gap-line model.
 *
 * The drop unit is the line between two visible rows. A line can mean
 * several drops, one per depth it sits at (after the deepest row above
 * it, then after each container ancestor that row closes, up to the root
 * flow), and the cursor's x picks the depth: the deepest candidate whose
 * anchor row's content edge is at or left of the cursor, else the
 * shallowest. A container-like row's lower band is the START of its flow
 * (before its first flow child, `inside` when empty), its middle the
 * append; a block row splits at its midpoint; inert rows resolve to
 * nothing. The same pixel line resolves to the same drop from both
 * directions because direction does not exist in the model.
 *
 * Fixtures are flat El rows carrying what the tree stamps (role,
 * aria-level, aria-expanded, data-tree-key, data-tree-region with
 * data-tree-owner, data-tree-claims with data-tree-owner), 24px tall
 * and indented one 8px step per level (the stubbed computed padding),
 * over the mirror the projection index composes from, booted the way
 * gaps.test.mjs boots it. A tiny canvas answers elementForKey and the
 * root entity key.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { El } from './stub-element.mjs';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (
        (specifier.startsWith('.') || specifier.startsWith('/')) &&
        existsSync(fileURLToPath(new URL(`${specifier}.js`, context.parentURL)))
      ) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});

// The modules read browser globals at load time (store setup); install
// stubs BEFORE import.
globalThis.Element = El;
globalThis.document = { querySelector: () => null, querySelectorAll: () => [] };
globalThis.CSS = { escape: (value) => value };
globalThis.window = globalThis;
globalThis.Drupal = { t: (value) => value, behaviors: {} };
globalThis.drupalSettings = {};
// The panel grammar pads a row one step per level; the fixture's rows
// stamp their level, so the computed padding reads off it.
const STEP_PX = 8;
globalThis.getComputedStyle = (element) => ({
  paddingLeft: `${(Number(element.getAttribute('aria-level')) - 1) * STEP_PX}px`,
});

const { readTreeRows, lineCandidates, pickByX, resolveRowTarget } = await import('../../js/edit_mode/drag/tree-gaps.js');
const { projectionIndex } = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

// --- Fixtures -------------------------------------------------------------

const markupNode = (tag, attributes = {}) => ({
  markup: { tag, attributes, classes: [], hoisted: [], parts: {}, slots: {} },
});
const block = (uuid) => ({ kind: 'block', uuid });
const container = (uuid, children) => ({
  kind: 'container',
  uuid,
  layout_id: 'test_container',
  _element: markupNode('section'),
  children,
});

/**
 * The page: Section A holds block a; Section B holds block b then nested
 * container C holding block c (block c closes two ancestors); D is a
 * two-region layout, its first region empty and its second holding block
 * d; E is an empty container.
 */
const bootMirror = () => {
  store.dispatch(blueprintBooted({
    uuid: 'page-1',
    blueprint: {
      _element: markupNode('article'),
      layout: [
        container('AAA', [block('blk-a')]),
        container('BBB', [block('blk-b'), container('CCC', [block('blk-c')])]),
        {
          kind: 'container',
          uuid: 'DDD',
          layout_id: 'layout_twocol',
          _element: markupNode('section'),
          children: [
            { kind: 'region', region: 'first', children: [] },
            { kind: 'region', region: 'second', children: [block('blk-d')] },
          ],
        },
        container('EEE', []),
      ],
    },
  }));
};

/**
 * The canvas the rows stand for: keys only (elementForKey and the root
 * entity key read it; no geometry is read from it).
 */
const buildCanvas = () => {
  const rect = { left: 0, top: 0, width: 1000, height: 100 };
  const main = new El('entity:page-1', rect);
  main.mainEntity = true;
  const el = (parent, key) => parent.add(new El(key, rect));
  const a = el(main, 'container:AAA');
  el(a, 'block:blk-a');
  const b = el(main, 'container:BBB');
  el(b, 'block:blk-b');
  const c = el(b, 'container:CCC');
  el(c, 'block:blk-c');
  const d = el(main, 'container:DDD');
  el(d, 'block:blk-d');
  el(main, 'container:EEE');
  const byKey = (key) => [main, ...main.descendants()].find((node) => node.selectable === key) ?? null;
  return { main, byKey };
};

/**
 * The tree's visible rows, in order, from flat specs: {name, level,
 * expanded?, key? | region+owner? | claims+owner?}. Row i is 24px tall at
 * top i*24; the content edge is (level-1)*8 (getComputedStyle above).
 */
const buildTree = (specs) => {
  const tree = new El(null, { left: 0, top: 0, width: 200, height: specs.length * 24 });
  const rows = {};
  specs.forEach((spec, i) => {
    const row = new El(null, { left: 0, top: i * 24, width: 200, height: 24 });
    row.attrs.role = 'treeitem';
    row.attrs['aria-level'] = String(spec.level);
    if (spec.expanded !== undefined) {
      row.attrs['aria-expanded'] = String(spec.expanded);
    }
    if (spec.key) {
      row.attrs['data-tree-key'] = spec.key;
    }
    if (spec.region) {
      row.attrs['data-tree-region'] = spec.region;
      row.attrs['data-tree-owner'] = spec.owner;
    }
    if (spec.claims) {
      row.attrs['data-tree-claims'] = spec.claims;
      row.attrs['data-tree-owner'] = spec.owner;
    }
    tree.add(row);
    rows[spec.name] = row;
  });
  return { tree, rows };
};

/**
 * The built-in view, everything expanded: the root, A > a > a's body
 * field, B > b, C > c, D > first (empty), second > d, E (empty). The
 * page row carries no aria-expanded (it never folds); leaves and empty
 * containers carry none either.
 */
const BUILT_IN = [
  { name: 'root', key: 'entity:page-1', level: 1 },
  { name: 'A', key: 'container:AAA', level: 2, expanded: true },
  { name: 'blkA', key: 'block:blk-a', level: 3, expanded: true },
  { name: 'fieldA', key: 'block:blk-a:body', level: 4 },
  { name: 'B', key: 'container:BBB', level: 2, expanded: true },
  { name: 'blkB', key: 'block:blk-b', level: 3 },
  { name: 'C', key: 'container:CCC', level: 3, expanded: true },
  { name: 'blkC', key: 'block:blk-c', level: 4 },
  { name: 'D', key: 'container:DDD', level: 2, expanded: true },
  { name: 'first', region: 'first', owner: 'container:DDD', level: 3 },
  { name: 'second', region: 'second', owner: 'container:DDD', level: 3, expanded: true },
  { name: 'blkD', key: 'block:blk-d', level: 4 },
  { name: 'E', key: 'container:EEE', level: 2 },
];

/**
 * The markup view: shell rows between the root and its sections. The
 * content shell claims fields, the layout shell claims the root's
 * sections (the root row's twin) and holds the section rows; a foreign
 * shell claims sections of another entity.
 */
const MARKUP = [
  { name: 'root', key: 'entity:page-1', level: 1 },
  { name: 'content', claims: 'field', owner: 'entity:page-1', level: 2 },
  { name: 'layout', claims: 'section', owner: 'entity:page-1', level: 2, expanded: true },
  { name: 'A', key: 'container:AAA', level: 3, expanded: true },
  { name: 'blkA', key: 'block:blk-a', level: 4 },
  { name: 'B', key: 'container:BBB', level: 3, expanded: false },
  { name: 'foreign', claims: 'section', owner: 'entity:para-1', level: 3 },
];

/**
 * Runs fn with document routed to a tree and the canvas: [data-main-entity]
 * and exact-key lookups into the canvas, the tree's rows for the
 * treeitem scan.
 */
const within = (tree, canvas, fn) => {
  const original = globalThis.document;
  globalThis.document = {
    querySelector: (selector) => {
      if (selector === '[data-main-entity]') {
        return canvas.main;
      }
      if (selector === '.daedalus-ui-tree') {
        return tree;
      }
      const exact = selector.match(/^\[data-selectable="([^"]+)"\]$/);
      return exact ? canvas.byKey(exact[1]) : null;
    },
    querySelectorAll: (selector) => tree.querySelectorAll(selector),
  };
  try {
    return fn(canvas);
  } finally {
    globalThis.document = original;
  }
};

/**
 * A fixture over one row list: resolve(row, x, y, targets), the model
 * reads (rows, candidatesBelow(name)) and the canvas, all routed.
 */
const fixture = (specs = BUILT_IN) => {
  const { tree, rows } = buildTree(specs);
  const canvas = buildCanvas();
  const names = specs.map((spec) => spec.name);
  const run = (fn) => within(tree, canvas, fn);
  return {
    rows,
    resolve: (row, x, y, targets = 'block') =>
      run(() => resolveRowTarget(row, x, y, targets)),
    read: () => run(() => readTreeRows()),
    candidatesBelow: (name) =>
      run(() => lineCandidates(readTreeRows(), names.indexOf(name), projectionIndex())),
    canvas: run,
  };
};

/**
 * Row i's y coordinates: its top band, middle and bottom band on a 24px
 * row (bands are 8px), and the two halves of a block row.
 */
const yOf = (i) => ({ top: i * 24 + 2, middle: i * 24 + 12, bottom: i * 24 + 22, upper: i * 24 + 5, lower: i * 24 + 19 });

// --- The spec ------------------------------------------------------------

test('before the mirror boots nothing is known: no row resolves', () => {
  const { rows, resolve } = fixture();
  assert.equal(resolve(rows.A, 100, yOf(1).bottom), null);
  bootMirror();
  // The premise the region cases rest on: regions are transparent in the
  // index, so D's flow children are its blocks across regions.
  assert.deepEqual(projectionIndex().get('container:DDD').children, ['block:blk-d']);
});

test('readTreeRows: DOM order, aria-level the depth, aria-expanded false the one fold, identity by key, region or claim', () => {
  const { rows, read } = fixture(MARKUP);
  const read_ = read();
  assert.deepEqual(read_.map((row) => row.element), Object.values(rows), 'the visible order');
  assert.deepEqual(read_.map((row) => row.depth), [1, 2, 2, 3, 4, 3, 3]);
  const byName = Object.fromEntries(read_.map((row, i) => [MARKUP[i].name, row]));
  assert.equal(byName.root.expanded, true, 'the page row never folds and stamps no aria-expanded');
  assert.equal(byName.blkA.expanded, true, 'a leaf hides nothing');
  assert.equal(byName.B.expanded, false, 'the one fold');
  assert.deepEqual([byName.A.key, byName.A.region, byName.A.owner, byName.A.claims], ['container:AAA', null, null, null]);
  assert.deepEqual([byName.layout.key, byName.layout.claims, byName.layout.owner], [null, ['section'], 'entity:page-1']);
  const region = fixture().read()[9];
  assert.deepEqual([region.key, region.region, region.owner], [null, 'first', 'container:DDD']);
});

test('a container showing children: its lower band is its START, one candidate at every x', () => {
  const { rows, resolve, candidatesBelow, canvas } = fixture();
  const candidates = candidatesBelow('A');
  assert.equal(candidates.length, 1);
  assert.deepEqual(candidates[0].location, { anchor: 'blk-a', placement: 'before' });
  for (const x of [0, 10, 100, 199]) {
    const target = resolve(rows.A, x, yOf(1).bottom);
    assert.deepEqual(target.location, { anchor: 'blk-a', placement: 'before' }, `x=${x}`);
    assert.equal(target.newSection, undefined, 'inside Section A: no wrap');
    assert.equal(target.rootFlow, false);
  }
  const target = resolve(rows.A, 100, yOf(1).bottom);
  assert.equal(target.kind, 'gap');
  assert.equal(target.empty, false);
  assert.equal(target.container, rows.A, 'the row of the container inserted into');
  assert.equal(target.gapId, 'tree|block:blk-a|before');
  canvas(({ byKey }) => assert.equal(target.canvasElement, byKey('block:blk-a'), 'the anchor\'s canvas element'));
  // The section drag reads the same line.
  assert.deepEqual(resolve(rows.A, 100, yOf(1).bottom, 'section').location, { anchor: 'blk-a', placement: 'before' });
});

test('the repro: the line between a section row and its first child means the same drop from both rows', () => {
  const { rows, resolve } = fixture();
  // Coming down: Section B's lower band. Coming up: block b's upper half.
  // One pixel line, one drop: before b inside B, never "after B" (the
  // root-flow gap that the row model drew on the same line).
  const down = resolve(rows.B, 100, yOf(4).bottom);
  const up = resolve(rows.blkB, 100, yOf(5).upper);
  assert.deepEqual(down.location, { anchor: 'blk-b', placement: 'before' });
  assert.deepEqual(up.location, down.location);
  assert.equal(up.gapId, down.gapId);
  assert.equal(down.newSection, undefined);
  assert.equal(down.rootFlow, false);
});

test('an empty container: its START is inside, its middle appends inside', () => {
  const { rows, resolve } = fixture();
  const start = resolve(rows.E, 100, yOf(12).bottom);
  assert.deepEqual(start.location, { anchor: 'EEE', placement: 'inside' });
  assert.equal(start.empty, true, 'the append affordance over the row');
  assert.equal(start.container, rows.E);
  assert.equal(start.gapId, 'tree|container:EEE|inside');
  assert.equal(start.rootFlow, false);
  const middle = resolve(rows.E, 100, yOf(12).middle, 'section');
  assert.deepEqual(middle.location, { anchor: 'EEE', placement: 'inside' });
  assert.equal(middle.newSection, undefined);
});

test('a block row closing one ancestor: two candidates, x picks the depth', () => {
  const { rows, resolve, candidatesBelow, canvas } = fixture();
  // Block a is Section A's last child; under its field row the line
  // closes a and A. Deepest first.
  const candidates = candidatesBelow('fieldA');
  assert.deepEqual(candidates.map((candidate) => candidate.location), [
    { anchor: 'blk-a', placement: 'after' },
    { anchor: 'AAA', placement: 'after' },
  ]);
  assert.deepEqual(candidates.map((candidate) => candidate.containerRow.element), [rows.A, rows.root]);
  // The same line is Section B's upper band. Block a's row indents to 16,
  // A's to 8: x at or right of 16 lands after a, between 8 and 16 after
  // A, left of every edge the shallowest (after A).
  const deep = resolve(rows.B, 100, yOf(4).top);
  assert.deepEqual(deep.location, { anchor: 'blk-a', placement: 'after' });
  assert.equal(deep.container, rows.A);
  assert.equal(deep.newSection, undefined);
  const edge = resolve(rows.B, 16, yOf(4).top);
  assert.deepEqual(edge.location, { anchor: 'blk-a', placement: 'after' }, 'at the edge counts as right of it');
  const shallow = resolve(rows.B, 10, yOf(4).top);
  assert.deepEqual(shallow.location, { anchor: 'AAA', placement: 'after' });
  assert.equal(shallow.container, rows.root);
  assert.equal(shallow.rootFlow, true);
  assert.equal(shallow.newSection, true, 'a block in the root flow wraps in a new section');
  assert.equal(shallow.gapId, 'tree|container:AAA|after');
  canvas(({ byKey }) => assert.equal(shallow.canvasElement, byKey('container:AAA')));
  const outside = resolve(rows.B, 0, yOf(4).top);
  assert.deepEqual(outside.location, { anchor: 'AAA', placement: 'after' }, 'left of every edge: the shallowest');
});

test('a block row closing two ancestors: three candidates, x picks each', () => {
  const { rows, resolve, candidatesBelow } = fixture();
  // Block c ends container C, which ends Section B: after c in C, after C
  // in B, after B in the root flow.
  const candidates = candidatesBelow('blkC');
  assert.deepEqual(candidates.map((candidate) => candidate.location), [
    { anchor: 'blk-c', placement: 'after' },
    { anchor: 'CCC', placement: 'after' },
    { anchor: 'BBB', placement: 'after' },
  ]);
  assert.deepEqual(candidates.map((candidate) => candidate.containerRow.element), [rows.C, rows.B, rows.root]);
  // Edges: c 24, C 16, B 8.
  const y = yOf(7).lower;
  assert.deepEqual(resolve(rows.blkC, 30, y).location, { anchor: 'blk-c', placement: 'after' });
  assert.deepEqual(resolve(rows.blkC, 20, y).location, { anchor: 'CCC', placement: 'after' });
  assert.deepEqual(resolve(rows.blkC, 10, y).location, { anchor: 'BBB', placement: 'after' });
  assert.deepEqual(resolve(rows.blkC, 0, y).location, { anchor: 'BBB', placement: 'after' });
  // The same line is D's upper band.
  assert.deepEqual(resolve(rows.D, 20, yOf(8).top).location, { anchor: 'CCC', placement: 'after' });
});

test('the root flow candidate wraps a block drop and leaves a section drop plain', () => {
  const { rows, resolve } = fixture();
  const y = yOf(7).lower;
  const blockDrop = resolve(rows.blkC, 10, y, 'block');
  assert.equal(blockDrop.rootFlow, true);
  assert.equal(blockDrop.newSection, true);
  const sectionDrop = resolve(rows.blkC, 10, y, 'section');
  assert.deepEqual(sectionDrop.location, { anchor: 'BBB', placement: 'after' });
  assert.equal(sectionDrop.rootFlow, true);
  assert.equal(sectionDrop.newSection, undefined);
  // The nested candidates are not root flow for either kind.
  const nested = resolve(rows.blkC, 20, y, 'block');
  assert.equal(nested.rootFlow, false);
  assert.equal(nested.newSection, undefined);
});

test('a region row: its middle appends inside with the region, its lower band is its START', () => {
  const { rows, resolve, candidatesBelow, canvas } = fixture();
  const append = resolve(rows.second, 100, yOf(10).middle);
  assert.deepEqual(append.location, { anchor: 'DDD', placement: 'inside', region: 'second' });
  assert.equal(append.empty, true);
  assert.equal(append.container, rows.second, 'the region row is the container inserted into');
  assert.equal(append.gapId, 'tree|container:DDD|inside|second');
  assert.equal(append.rootFlow, false);
  assert.equal(append.newSection, undefined);
  canvas(({ byKey }) => assert.equal(append.canvasElement, byKey('container:DDD'), 'the owner\'s canvas element'));
  // The populated region's start: before its first visible child.
  const start = resolve(rows.second, 100, yOf(10).bottom);
  assert.deepEqual(start.location, { anchor: 'blk-d', placement: 'before' });
  assert.equal(start.container, rows.second);
  assert.equal(start.empty, false);
  // The empty region's start is its inside, region named.
  const emptyStart = resolve(rows.first, 100, yOf(9).bottom);
  assert.deepEqual(emptyStart.location, { anchor: 'DDD', placement: 'inside', region: 'first' });
  assert.deepEqual(resolve(rows.first, 100, yOf(9).middle).location, emptyStart.location);
  // The line between the two region rows: the empty first region offers
  // its inside and nothing else (no "after a region" in the grammar; D is
  // not closed).
  assert.deepEqual(candidatesBelow('first').map((candidate) => candidate.location), [
    { anchor: 'DDD', placement: 'inside', region: 'first' },
  ]);
  assert.deepEqual(resolve(rows.second, 0, yOf(10).top).location, { anchor: 'DDD', placement: 'inside', region: 'first' });
  // Under block d the line closes the second region (no gap of its own)
  // and D: after d, then after D in the root flow.
  assert.deepEqual(candidatesBelow('blkD').map((candidate) => candidate.location), [
    { anchor: 'blk-d', placement: 'after' },
    { anchor: 'DDD', placement: 'after' },
  ]);
  assert.deepEqual(resolve(rows.E, 10, yOf(12).top).location, { anchor: 'DDD', placement: 'after' });
});

test('a multi-region container\'s START is before its first block, through the transparent regions', () => {
  const { rows, resolve } = fixture();
  const start = resolve(rows.D, 100, yOf(8).bottom);
  assert.deepEqual(start.location, { anchor: 'blk-d', placement: 'before' });
  assert.equal(start.container, rows.D);
  // The same line read from the first region row's upper band.
  assert.deepEqual(resolve(rows.first, 100, yOf(9).top).location, start.location);
  // D's middle appends to D itself, no region.
  assert.deepEqual(resolve(rows.D, 100, yOf(8).middle).location, { anchor: 'DDD', placement: 'inside' });
});

test('the page row: its middle appends to the root flow, its lower band is the root flow\'s START, nothing is above it', () => {
  const { rows, resolve } = fixture();
  const append = resolve(rows.root, 100, yOf(0).middle, 'block');
  assert.deepEqual(append.location, { anchor: 'page-1', placement: 'inside' });
  assert.equal(append.rootFlow, true);
  assert.equal(append.newSection, true, 'the root row wrap-appends a block');
  assert.equal(append.empty, true);
  assert.equal(append.container, rows.root);
  assert.equal(resolve(rows.root, 100, yOf(0).middle, 'section').newSection, undefined);
  const start = resolve(rows.root, 100, yOf(0).bottom, 'block');
  assert.deepEqual(start.location, { anchor: 'AAA', placement: 'before' });
  assert.equal(start.rootFlow, true);
  assert.equal(start.newSection, true);
  assert.equal(start.container, rows.root);
  // Section A's upper band is the same line.
  assert.deepEqual(resolve(rows.A, 100, yOf(1).top, 'section').location, { anchor: 'AAA', placement: 'before' });
  assert.equal(resolve(rows.root, 100, yOf(0).top), null, 'no line above the first row');
});

test('the sections shell is the root row\'s twin; other shells and a foreign claim are inert', () => {
  const { rows, resolve, canvas } = fixture(MARKUP);
  for (const y of [yOf(2).middle]) {
    const section = resolve(rows.layout, 100, y, 'section');
    assert.deepEqual(section.location, { anchor: 'page-1', placement: 'inside' });
    assert.equal(section.newSection, undefined);
    assert.equal(section.empty, true);
    assert.equal(section.kind, 'gap');
    assert.equal(section.container, rows.layout);
    assert.equal(section.rootFlow, true);
    canvas(({ main }) => assert.equal(section.canvasElement, main));
  }
  const blockDrop = resolve(rows.layout, 100, yOf(2).middle, 'block');
  assert.equal(blockDrop.newSection, true, 'the shell wrap-appends a block');
  // Its lower band is the root flow's START: before Section A.
  const start = resolve(rows.layout, 100, yOf(2).bottom, 'block');
  assert.deepEqual(start.location, { anchor: 'AAA', placement: 'before' });
  assert.equal(start.newSection, true);
  assert.equal(start.container, rows.layout);
  // The root row's own middle highlights the shell when it is rendered:
  // one location, one face.
  const rootAppend = resolve(rows.root, 100, yOf(0).middle, 'section');
  assert.deepEqual(rootAppend.location, { anchor: 'page-1', placement: 'inside' });
  assert.equal(rootAppend.container, rows.layout);
  assert.equal(rootAppend.gapId, resolve(rows.layout, 100, yOf(2).middle, 'section').gapId);
  // The shell's upper band is the line under the content shell, which
  // closes nothing of the flow: no drop.
  assert.equal(resolve(rows.layout, 100, yOf(2).top), null);
  for (const [name, i] of [['content', 1], ['foreign', 6]]) {
    for (const y of [yOf(i).top, yOf(i).middle, yOf(i).bottom]) {
      assert.equal(resolve(rows[name], 100, y, 'section'), null, `${name} shell`);
      assert.equal(resolve(rows[name], 100, y, 'block'), null);
    }
  }
});

test('a collapsed container offers no start: its lower band means after it', () => {
  const { rows, resolve } = fixture(MARKUP);
  // Section B is collapsed (aria-expanded="false"); below it sits the
  // foreign shell at B's depth, so the line closes nothing further.
  const target = resolve(rows.B, 100, yOf(5).bottom, 'block');
  assert.deepEqual(target.location, { anchor: 'BBB', placement: 'after' });
  assert.equal(target.rootFlow, true);
  assert.equal(target.newSection, true);
  assert.equal(target.container, rows.layout, 'the root flow\'s row is the shell when rendered');
  // The ancestors closed by block a (A's last child, under the shell):
  // after a, after A; the shell ends the walk.
  assert.deepEqual(resolve(rows.B, 100, yOf(5).top).location, { anchor: 'blk-a', placement: 'after' });
  assert.deepEqual(resolve(rows.B, 10, yOf(5).top).location, { anchor: 'AAA', placement: 'after' });
  assert.equal(resolve(rows.B, 0, yOf(5).top).container, rows.layout);
});

test('inert rows resolve to nothing for both kinds at every y', () => {
  const { rows, resolve } = fixture();
  for (const y of [yOf(3).top, yOf(3).middle, yOf(3).bottom]) {
    assert.equal(resolve(rows.fieldA, 100, y, 'block'), null, 'a block\'s field row');
    assert.equal(resolve(rows.fieldA, 100, y, 'section'), null);
  }
  // A row the tree does not list resolves nothing either.
  const stranger = new El(null, { left: 0, top: 0, width: 200, height: 24 });
  stranger.attrs['data-tree-key'] = 'container:AAA';
  assert.equal(resolve(stranger, 100, 12), null);
});

test('the bands by y: 8px bands on container-like rows, the midpoint on block rows', () => {
  const { rows, resolve } = fixture();
  const top = 4 * 24;
  const above = { anchor: 'blk-a', placement: 'after' };
  const inside = { anchor: 'BBB', placement: 'inside' };
  const below = { anchor: 'blk-b', placement: 'before' };
  assert.deepEqual(resolve(rows.B, 100, top).location, above);
  assert.deepEqual(resolve(rows.B, 100, top + 7).location, above, 'the last pixel of the upper band');
  assert.deepEqual(resolve(rows.B, 100, top + 8).location, inside, 'the first pixel of the middle');
  assert.deepEqual(resolve(rows.B, 100, top + 16).location, inside, 'the last pixel of the middle');
  assert.deepEqual(resolve(rows.B, 100, top + 17).location, below, 'the first pixel of the lower band');
  assert.deepEqual(resolve(rows.B, 100, top + 24).location, below);
  // A block row: above its midpoint the line above, from it on the line
  // below.
  const blockTop = 5 * 24;
  assert.deepEqual(resolve(rows.blkB, 100, blockTop + 11).location, below, 'before b: the line above block b');
  assert.deepEqual(resolve(rows.blkB, 100, blockTop + 12).location, { anchor: 'blk-b', placement: 'after' });
  // Short rows cap the band at a third so the middle survives.
  rows.B.rect = { left: 0, top, width: 200, height: 12 };
  assert.deepEqual(resolve(rows.B, 100, top + 3).location, above);
  assert.deepEqual(resolve(rows.B, 100, top + 6).location, inside);
  assert.deepEqual(resolve(rows.B, 100, top + 9).location, below);
});

test('pickByX: deepest whose edge is at or left of x, else the shallowest; one candidate at every x', () => {
  const edges = new Map([['deep', 24], ['mid', 16], ['shallow', 8]]);
  const candidate = (name) => ({ location: name, anchorRow: name });
  const contentEdge = (row) => edges.get(row);
  const candidates = [candidate('deep'), candidate('mid'), candidate('shallow')];
  assert.equal(pickByX(candidates, 100, contentEdge), candidates[0]);
  assert.equal(pickByX(candidates, 24, contentEdge), candidates[0], 'at the edge counts');
  assert.equal(pickByX(candidates, 23, contentEdge), candidates[1]);
  assert.equal(pickByX(candidates, 8, contentEdge), candidates[2]);
  assert.equal(pickByX(candidates, 0, contentEdge), candidates[2], 'left of every edge: the shallowest');
  assert.equal(pickByX([candidates[0]], 0, contentEdge), candidates[0], 'one candidate ignores x');
  assert.equal(pickByX([], 0, contentEdge), null);
});

// --- A locked page's doors ------------------------------------------------

/**
 * The locked page: the template's section T holds door 1 (slot hero) and
 * door 2 (slot aside); the page fills hero with container F holding block
 * f, and nothing fills aside. The lean tree prints F at the root tagged
 * with its slot; the projection pairs it under door 1 (slot-fills.js), so
 * the index reads door 1's children as F.
 */
const bootDoorMirror = () => {
  store.dispatch(blueprintBooted({
    uuid: 'page-1',
    blueprint: {
      _element: markupNode('article'),
      layout: [
        container('TTT', [
          { kind: 'block', uuid: 'dr-1', plugin_id: 'daedalus_slot:hero', slot: 'hero' },
          { kind: 'block', uuid: 'dr-2', plugin_id: 'daedalus_slot:aside', slot: 'aside' },
        ]),
        { ...container('FFF', [block('blk-f')]), slot: 'hero' },
      ],
    },
  }));
};

const buildDoorCanvas = () => {
  const rect = { left: 0, top: 0, width: 1000, height: 100 };
  const main = new El('entity:page-1', rect);
  main.mainEntity = true;
  const el = (parent, key) => parent.add(new El(key, rect));
  const t = el(main, 'container:TTT');
  const door1 = el(t, 'block:dr-1');
  const f = el(door1, 'container:FFF');
  el(f, 'block:blk-f');
  el(t, 'block:dr-2');
  const byKey = (key) => [main, ...main.descendants()].find((node) => node.selectable === key) ?? null;
  return { main, byKey };
};

/**
 * The built-in view of the locked page, everything expanded: the root,
 * T > door 1 > F > f, door 2 (an empty door, a leaf).
 */
const DOORS = [
  { name: 'root', key: 'entity:page-1', level: 1 },
  { name: 'T', key: 'container:TTT', level: 2, expanded: true },
  { name: 'door1', key: 'block:dr-1', level: 3, expanded: true, door: 'hero' },
  { name: 'F', key: 'container:FFF', level: 4, expanded: true },
  { name: 'blkF', key: 'block:blk-f', level: 5 },
  { name: 'door2', key: 'block:dr-2', level: 3, door: 'aside' },
];

const doorFixture = () => {
  const { tree, rows } = buildTree(DOORS);
  for (const spec of DOORS) {
    if (spec.door) {
      rows[spec.name].attrs['data-tree-door'] = spec.door;
    }
  }
  const canvas = buildDoorCanvas();
  const names = DOORS.map((spec) => spec.name);
  const run = (fn) => within(tree, canvas, fn);
  return {
    rows,
    resolve: (row, x, y, targets = 'block') =>
      run(() => resolveRowTarget(row, x, y, targets)),
    read: () => run(() => readTreeRows()),
    candidatesBelow: (name) =>
      run(() => lineCandidates(readTreeRows(), names.indexOf(name), projectionIndex())),
  };
};

test('the pairing reaches the index: a door\'s children are its fill', () => {
  bootDoorMirror();
  assert.deepEqual(projectionIndex().get('block:dr-1').children, ['container:FFF']);
  assert.deepEqual(projectionIndex().get('block:dr-2').children, []);
  assert.equal(projectionIndex().get('container:FFF').parent, 'block:dr-1');
  const { read } = doorFixture();
  assert.deepEqual(read().map((row) => row.door), [null, null, 'hero', null, null, 'aside'], 'the door stamp reads with the rows');
});

test('a filled door row is container-like: its lower band is the fill\'s START, its middle is inside the door', () => {
  const { rows, resolve, candidatesBelow } = doorFixture();
  const candidates = candidatesBelow('door1');
  assert.equal(candidates.length, 1);
  assert.deepEqual(candidates[0].location, { anchor: 'FFF', placement: 'before' });
  const start = resolve(rows.door1, 100, yOf(2).bottom);
  assert.deepEqual(start.location, { anchor: 'FFF', placement: 'before' });
  assert.equal(start.container, rows.door1, 'the door is the container inserted into');
  assert.equal(start.rootFlow, false);
  // The middle: inside the door, the canvas's empty-door grammar; the op
  // door lands it in the slot section. No band split at the midpoint as a
  // plain block row has.
  const middle = resolve(rows.door1, 100, yOf(2).middle);
  assert.deepEqual(middle.location, { anchor: 'dr-1', placement: 'inside' });
  assert.equal(middle.empty, true);
  assert.equal(middle.gapId, 'tree|block:dr-1|inside');
  assert.equal(middle.container, rows.door1);
  assert.equal(middle.newSection, undefined, 'not the root flow: no wrap');
  // The top band is the line above: before the door in the template's
  // section (the gate refuses it on a locked page; the model only names it).
  assert.deepEqual(resolve(rows.door1, 100, yOf(2).top).location, { anchor: 'dr-1', placement: 'before' });
  // The section drag reads the same lines.
  assert.deepEqual(resolve(rows.door1, 100, yOf(2).middle, 'section').location, { anchor: 'dr-1', placement: 'inside' });
});

test('an empty door row: its middle and its lower band both mean inside the door', () => {
  const { rows, resolve, candidatesBelow } = doorFixture();
  const middle = resolve(rows.door2, 100, yOf(5).middle);
  assert.deepEqual(middle.location, { anchor: 'dr-2', placement: 'inside' });
  assert.equal(middle.empty, true);
  assert.equal(middle.container, rows.door2);
  const bottom = resolve(rows.door2, 100, yOf(5).bottom);
  assert.deepEqual(bottom.location, { anchor: 'dr-2', placement: 'inside' }, 'an empty door shows no first child: its START is inside');
  // Its line as read from the model: the door is the last row, so the
  // line below it is the START of the empty door alone (startOf wins).
  assert.deepEqual(candidatesBelow('door2').map((candidate) => candidate.location), [{ anchor: 'dr-2', placement: 'inside' }]);
});

test('the fill\'s rows are ordinary: the line under its last block closes the fill, then the door', () => {
  const { rows, resolve, candidatesBelow } = doorFixture();
  const candidates = candidatesBelow('blkF');
  assert.deepEqual(candidates.map((candidate) => candidate.location), [
    { anchor: 'blk-f', placement: 'after' },
    { anchor: 'FFF', placement: 'after' },
    { anchor: 'dr-1', placement: 'after' },
  ]);
  assert.deepEqual(candidates.map((candidate) => candidate.containerRow.element), [rows.F, rows.door1, rows.T]);
  // The fill container's own lower band is its START, before its block.
  assert.deepEqual(resolve(rows.F, 100, yOf(3).bottom).location, { anchor: 'blk-f', placement: 'before' });
  // The deepest candidate on the line under block f, at every depth x
  // picks: after f (x right of f's edge), after F, after the door.
  assert.deepEqual(resolve(rows.door2, 100, yOf(5).top).location, { anchor: 'blk-f', placement: 'after' });
  assert.deepEqual(resolve(rows.door2, 20, yOf(5).top).location, { anchor: 'dr-1', placement: 'after' });
});
