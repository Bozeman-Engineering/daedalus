/**
 * Self-asserting tests for pointer gap resolution (gaps.js).
 *
 * The one-flow model: each container holds one mixed ordered flow of
 * direct children (block placements and nested containers alike, document
 * order), the root layout field's rows are the root flow, and anchor kind
 * is irrelevant to addressing. Legality runs after resolution: the root
 * flow holds only sections, so block drops there wrap in a new section
 * (newSection); container flows hold anything, so interior gaps are plain
 * placements for both drag kinds. The edge-zone rule stays: a cursor
 * within an edge band of an enclosing container (min(28px, 25% of its
 * flow-axis extent), measured along the PARENT flow's axis) resolves to
 * the sibling gap in the parent flow, enclosing containers tested
 * outermost first. Regions carry no selectable keys: a container's
 * children are one ordered flow, and the container root IS the drop
 * surface.
 *
 * gaps.js is browser code: extensionless webpack-style imports, Drupal
 * globals, and an `instanceof Element` guard. The file registers a resolve
 * hook appending `.js`, installs stub globals, and imports the module
 * dynamically — geometry comes from stub elements' getBoundingClientRect.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

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

/**
 * A stub canvas element: a selectable key, a rect, and just enough DOM
 * surface for gaps.js (getAttribute, matches, closest, parentElement,
 * querySelector(All) over descendants, getBoundingClientRect).
 */
class El {
  constructor(selectable, rect) {
    this.selectable = selectable;
    this.rect = rect;
    this.children = [];
    this.parentElement = null;
    this.mainEntity = false;
    // Generic attributes (data-tree-key and friends).
    this.attrs = {};
  }

  add(child) {
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  getAttribute(name) {
    if (name === 'data-selectable') {
      return this.selectable ?? null;
    }
    return this.attrs[name] ?? null;
  }

  hasAttribute(name) {
    if (name === 'data-selectable') {
      return this.selectable != null;
    }
    if (name === 'data-main-entity') {
      return this.mainEntity;
    }
    return name in this.attrs;
  }

  get childElementCount() {
    return this.children.length;
  }

  /**
   * Attribute selectors only, compound and comma-listed: [name],
   * [name="v"], [name^="v"], [name~="v"] (the shapes gaps.js queries).
   */
  matches(selector) {
    return selector.split(',').some((single) => {
      const clauses = [...single.trim().matchAll(/\[([a-z-]+)(?:([\^~]?)="([^"]*)")?\]/g)];
      if (clauses.length === 0) {
        return false;
      }
      return clauses.every(([, name, operator, value]) => {
        if (value === undefined) {
          return this.hasAttribute(name);
        }
        const actual = this.getAttribute(name);
        if (actual == null) {
          return false;
        }
        if (operator === '^') {
          return actual.startsWith(value);
        }
        if (operator === '~') {
          return actual.split(' ').includes(value);
        }
        return actual === value;
      });
    });
  }

  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) {
        return node;
      }
    }
    return null;
  }

  descendants() {
    return this.children.flatMap((child) => [child, ...child.descendants()]);
  }

  querySelectorAll(selector) {
    return this.descendants().filter((node) => node.matches(selector));
  }

  querySelector(selector) {
    return this.descendants().find((node) => node.matches(selector)) ?? null;
  }

  getBoundingClientRect() {
    const { left, top, width, height } = this.rect;
    return { left, top, width, height, right: left + width, bottom: top + height };
  }

  get isConnected() {
    return true;
  }
}

// The module reads browser globals at load time (store setup) and checks
// `target instanceof Element` per resolution — install stubs BEFORE import.
globalThis.Element = El;
globalThis.document = { querySelector: () => null };
globalThis.CSS = { escape: (value) => value };
globalThis.window = globalThis;
globalThis.Drupal = { t: (value) => value, behaviors: {} };
globalThis.drupalSettings = {};

const { resolveDropTarget, enumerateGapTargets, containerKeyForLocation, isRootFlowLocation, treeRowForLocation } = await import('../../js/edit_mode/drag/gaps.js');
const { createRoster } = await import('../../js/edit_mode/materials/roster.js');
const builders = await import('../../js/edit_mode/drag/ops-builders.js');

/**
 * A fresh page fixture, rebuilt per test so mutations can't leak:
 * main (0–800 tall) holds containers A (0–400) and B (400–800), each with a
 * full-bleed unkeyed inner wrapper (the layout part div at root depth) —
 * every pixel sits inside some container, the geometry that made root-flow
 * gaps pointer-unreachable pre-edge-zones. Container B nests container C
 * (500–700) with its own inner wrapper.
 */
const buildPage = () => {
  const main = new El('entity:page-1', { left: 0, top: 0, width: 1000, height: 800 });
  main.mainEntity = true;
  const containerA = main.add(new El('container:AAA', { left: 0, top: 0, width: 1000, height: 400 }));
  const innerA = containerA.add(new El(null, { left: 0, top: 0, width: 1000, height: 400 }));
  const blockA = innerA.add(new El('block:blk-a', { left: 100, top: 100, width: 800, height: 200 }));
  const containerB = main.add(new El('container:BBB', { left: 0, top: 400, width: 1000, height: 400 }));
  const innerB = containerB.add(new El(null, { left: 0, top: 400, width: 1000, height: 400 }));
  const containerC = innerB.add(new El('container:CCC', { left: 100, top: 500, width: 800, height: 200 }));
  const innerC = containerC.add(new El(null, { left: 100, top: 500, width: 800, height: 200 }));
  return { main, containerA, innerA, blockA, containerB, innerB, containerC, innerC };
};

test('section drag, container interior: a positioned gap in the mixed flow', () => {
  const { blockA } = buildPage();
  // y=150 misses container A's bands and sits above block A's midpoint:
  // the section lands BEFORE the block, a section anchored on a block,
  // the sentence the unified grammar added.
  const target = resolveDropTarget(blockA, 500, 150, 'section');
  assert.deepEqual(target.location, { anchor: 'blk-a', placement: 'before' });
  assert.equal(target.newSection, undefined);
  assert.equal(target.empty, false);
});

test('section drag, nested container interior: nests into the nested container', () => {
  const { innerC } = buildPage();
  // y=600 misses container C's bands (500–528, 672–700) and container B's
  // (400–428, 772–800); childless container C takes the nest.
  const target = resolveDropTarget(innerC, 500, 600, 'section');
  assert.deepEqual(target.location, { anchor: 'CCC', placement: 'inside' });
});

test('section drag, top edge band: sibling gap before the container', () => {
  const { blockA } = buildPage();
  // y=10 is within 28px of container A's top although the cursor sits over
  // a block deep inside it — the root flow claims it.
  const target = resolveDropTarget(blockA, 500, 10, 'section');
  assert.deepEqual(target.location, { anchor: 'AAA', placement: 'before' });
  assert.equal(target.gapId, 'AAA|before');
});

test('section drag, bottom edge band: sibling gap after the container', () => {
  const { blockA } = buildPage();
  const target = resolveDropTarget(blockA, 500, 390, 'section');
  assert.deepEqual(target.location, { anchor: 'AAA', placement: 'after' });
});

test('section drag, bottom of the page: append gap after the last container', () => {
  const { innerB } = buildPage();
  // y=790 sits inside full-bleed container B but within 28px of its bottom.
  // B ends at the page's bottom edge, so there is no space below it for the
  // pointer to land in; the edge band is what makes the append gap
  // reachable.
  const target = resolveDropTarget(innerB, 500, 790, 'section');
  assert.deepEqual(target.location, { anchor: 'BBB', placement: 'after' });
});

test('section drag, nested edge band alone: the nested flow claims it', () => {
  const { innerC } = buildPage();
  // y=505 is in container C's top band (500–528) but misses container B's
  // bands, so the gap lives in container B's nested-container flow.
  const target = resolveDropTarget(innerC, 500, 505, 'section');
  assert.deepEqual(target.location, { anchor: 'CCC', placement: 'before' });
});

test('section drag, enclosing band above the nested container: root flow wins', () => {
  const { innerB } = buildPage();
  // y=410 is inside container B (above nested C) and in its top band:
  // the sibling gap in the ROOT flow, not a nest into container B.
  const target = resolveDropTarget(innerB, 500, 410, 'section');
  assert.deepEqual(target.location, { anchor: 'AAA', placement: 'after' });
});

test('section drag, coincident nested bands: the outermost container wins', () => {
  const { main, containerC, innerC } = buildPage();
  // Slide container C flush against container B's top so their top bands
  // overlap: y=405 sits in BOTH bands, and shallowest-wins hands the gap
  // to container B's flow (the root flow), not C's.
  containerC.rect = { left: 100, top: 400, width: 800, height: 200 };
  innerC.rect = { left: 100, top: 400, width: 800, height: 200 };
  const target = resolveDropTarget(innerC, 500, 405, 'section');
  assert.deepEqual(target.location, { anchor: 'AAA', placement: 'after' });
  assert.equal(target.insert.parent, main, 'the gap lives in the root flow container');
});

test('section drag, horizontal nested flow: bands run along left/right edges', () => {
  const { innerB, containerC, innerC } = buildPage();
  // A second nested container beside C makes container B's flow horizontal.
  const containerD = innerB.add(new El('container:DDD', { left: 500, top: 500, width: 400, height: 200 }));
  containerD.add(new El(null, { left: 500, top: 500, width: 400, height: 200 }));
  containerC.rect = { left: 100, top: 500, width: 400, height: 200 };
  innerC.rect = { left: 100, top: 500, width: 400, height: 200 };
  // x=110 is within 28px of container C's LEFT edge (the flow axis), y=600
  // clears container B's vertical bands: gap before C in the horizontal
  // flow.
  const edge = resolveDropTarget(innerC, 110, 600, 'section');
  assert.deepEqual(edge.location, { anchor: 'CCC', placement: 'before' });
  assert.equal(edge.orientation, 'horizontal');
  // x=300 is interior on the flow axis: childless container C takes the
  // nest.
  const interior = resolveDropTarget(innerC, 300, 600, 'section');
  assert.deepEqual(interior.location, { anchor: 'CCC', placement: 'inside' });
});

test('short containers: the band caps at 25% of the flow-axis extent', () => {
  const { innerC } = buildPage();
  // A 40px-tall container nested in container C: its band is 10px, not
  // 28px.
  const containerD = innerC.add(new El('container:DDD', { left: 100, top: 550, width: 800, height: 40 }));
  const innerD = containerD.add(new El(null, { left: 100, top: 550, width: 800, height: 40 }));
  // 15px from the top: outside the capped band, so the interior nests.
  const interior = resolveDropTarget(innerD, 500, 565, 'section');
  assert.deepEqual(interior.location, { anchor: 'DDD', placement: 'inside' });
  // 8px from the top: inside the band, the flow gap.
  const edge = resolveDropTarget(innerD, 500, 558, 'section');
  assert.deepEqual(edge.location, { anchor: 'DDD', placement: 'before' });
});

test('wrapped root containers: the gap container spans the whole flow', () => {
  // daedalus_layout renders each root section inside its own single-child wrapper
  // div, so a container's parentElement spans ONE band. The gap container
  // must be the wrappers' shared parent, or the end-of-flow caret line
  // clamps to the first band's bottom instead of the flow's.
  const main = new El('entity:page-1', { left: 0, top: 0, width: 1000, height: 800 });
  main.mainEntity = true;
  const flow = main.add(new El(null, { left: 0, top: 0, width: 1000, height: 800 }));
  const wrapA = flow.add(new El(null, { left: 0, top: 0, width: 1000, height: 400 }));
  const containerA = wrapA.add(new El('container:AAA', { left: 0, top: 0, width: 1000, height: 400 }));
  const wrapB = flow.add(new El(null, { left: 0, top: 400, width: 1000, height: 400 }));
  const containerB = wrapB.add(new El('container:BBB', { left: 0, top: 400, width: 1000, height: 400 }));
  // y=790 is container B's bottom band: the append-at-end gap.
  const target = resolveDropTarget(containerB, 500, 790, 'section');
  assert.deepEqual(target.location, { anchor: 'BBB', placement: 'after' });
  assert.equal(target.index, 2);
  assert.equal(target.container, flow, 'the container is the wrappers\' shared parent, not one wrapper');
  assert.equal(target.items[0], containerA, 'gap items stay the containers themselves');
});

test('block drag, edge band: the drop wraps the block in a new container', () => {
  const { blockA } = buildPage();
  const target = resolveDropTarget(blockA, 500, 390, 'block');
  assert.deepEqual(target.location, { anchor: 'AAA', placement: 'after' });
  assert.equal(target.newSection, true);
  assert.equal(target.gapId, 'new-section|AAA|after');
});

test('block drag, container interior: container block gaps unchanged', () => {
  const { blockA } = buildPage();
  // y=150 is above block A's midpoint (200) and outside container A's
  // bands.
  const target = resolveDropTarget(blockA, 500, 150, 'block');
  assert.deepEqual(target.location, { anchor: 'blk-a', placement: 'before' });
  assert.equal(target.newSection, undefined);
  assert.equal(target.gapId, 'blk-a|before');
});

test('block drag, nested edge band: a plain sibling beside the container', () => {
  const { innerC } = buildPage();
  // y=505 is container C's top band; the gap lives in container B's flow,
  // a NESTED flow, so the block lands as an ordinary sibling of container
  // C with no new-section wrap (the wrap is root-only).
  const target = resolveDropTarget(innerC, 500, 505, 'block');
  assert.deepEqual(target.location, { anchor: 'CCC', placement: 'before' });
  assert.equal(target.newSection, undefined);
  assert.equal(target.gapId, 'CCC|before');
});

test('block drag, container-holding interior: gaps among the nested containers', () => {
  const { innerB } = buildPage();
  // y=460 misses every band; container B's mixed flow is [container C]
  // and the cursor sits above C's midpoint (600): a plain sibling
  // placement, where the old blocks-only flow resolved 'inside' (append).
  const target = resolveDropTarget(innerB, 500, 460, 'block');
  assert.deepEqual(target.location, { anchor: 'CCC', placement: 'before' });
  assert.equal(target.newSection, undefined);
});

test('mixed flow: the gap between a block and a container spells "after the block"', () => {
  const { innerA } = buildPage();
  // A nested container after block A makes container A's flow mixed.
  const containerD = innerA.add(new El('container:DDD', { left: 100, top: 320, width: 800, height: 60 }));
  containerD.add(new El(null, { left: 100, top: 320, width: 800, height: 60 }));
  // y=310 sits between block A (bottom 300) and container D (top 320);
  // both drag kinds resolve the same canonical spelling, anchored on the
  // PRECEDING item.
  const block = resolveDropTarget(innerA, 500, 310, 'block');
  const section = resolveDropTarget(innerA, 500, 310, 'section');
  assert.deepEqual(block.location, { anchor: 'blk-a', placement: 'after' });
  assert.deepEqual(section.location, { anchor: 'blk-a', placement: 'after' });
  assert.equal(block.newSection, undefined);
  assert.deepEqual(block.items, [innerA.children[0], containerD], 'the flow holds both kinds in document order');
});

test('keyboard enumeration: both kinds walk the same positions; the wrap is root-only', () => {
  const { main } = buildPage();
  const originalQuery = globalThis.document.querySelector;
  globalThis.document.querySelector = (selector) => (selector === '[data-main-entity]' ? main : null);
  try {
    const spell = (gap) => `${gap.location.anchor}|${gap.location.placement}${gap.newSection ? '|new-section' : ''}`;
    const blocks = enumerateGapTargets('block').map(spell).sort();
    const sections = enumerateGapTargets('section').map(spell).sort();
    // Root-flow gaps wrap block drops in a new section; every nested gap,
    // including the ones beside container C, is a plain placement.
    assert.deepEqual(blocks, [
      'AAA|after|new-section',
      'AAA|before|new-section',
      'BBB|after|new-section',
      'CCC|after',
      'CCC|before',
      'CCC|inside',
      'blk-a|after',
      'blk-a|before',
    ]);
    // Section drops walk the SAME positions, root gaps as plain sibling
    // placements: keyboard parity with the pointer's one resolver.
    assert.deepEqual(sections, [
      'AAA|after',
      'AAA|before',
      'BBB|after',
      'CCC|after',
      'CCC|before',
      'CCC|inside',
      'blk-a|after',
      'blk-a|before',
    ]);
  } finally {
    globalThis.document.querySelector = originalQuery;
  }
});

// --- Tree rows: uniform zones, kind-blind -------------------------------

/**
 * A tree fixture over the standard page: model rows carry data-tree-key
 * and a rect (24px tall, top 0), and the markup view's shell rows (no key)
 * carry the owner/claims pair the tree stamps on a claiming part: the
 * layout root claiming the root's sections, a content shell claiming only
 * fields, and a sections-claiming shell owned by a non-root entity. Model
 * rows and the layout shell sit in a `.daedalus-ui-tree` stub for the caret-face
 * lookups. document.querySelector routes elementForKey's exact-key lookups
 * into the page, the [data-main-entity] probe to main, and `.daedalus-ui-tree` to
 * the tree stub. Returns a run(row, y, targets) helper that installs the
 * router for one resolution, and within(fn) to run any lookup under it.
 */
const buildTreeRows = () => {
  const page = buildPage();
  const row = (attrs) => {
    const el = new El(null, { left: 0, top: 0, width: 200, height: 24 });
    Object.assign(el.attrs, attrs);
    return el;
  };
  const rows = {
    root: row({ 'data-tree-key': 'entity:page-1' }),
    layout: row({ 'data-tree-owner': 'entity:page-1', 'data-tree-claims': 'section' }),
    content: row({ 'data-tree-owner': 'entity:page-1', 'data-tree-claims': 'field' }),
    foreignLayout: row({ 'data-tree-owner': 'entity:para-1', 'data-tree-claims': 'section' }),
    containerA: row({ 'data-tree-key': 'container:AAA' }),
    containerC: row({ 'data-tree-key': 'container:CCC' }),
    blockA: row({ 'data-tree-key': 'block:blk-a' }),
  };
  const tree = new El(null, { left: 0, top: 0, width: 200, height: 200 });
  tree.add(rows.root);
  tree.add(rows.containerA);
  tree.add(rows.containerC);
  tree.add(rows.blockA);
  const query = (selector) => {
    if (selector === '[data-main-entity]') {
      return page.main;
    }
    if (selector === '.daedalus-ui-tree') {
      return tree;
    }
    const exact = selector.match(/^\[data-selectable="([^"]+)"\]$/);
    if (exact) {
      return [page.main, ...page.main.descendants()]
        .find((node) => node.selectable === exact[1]) ?? null;
    }
    return null;
  };
  const within = (fn) => {
    const original = globalThis.document.querySelector;
    globalThis.document.querySelector = query;
    try {
      return fn();
    } finally {
      globalThis.document.querySelector = original;
    }
  };
  const run = (target, y, targets) =>
    within(() => resolveDropTarget(target, 100, y, targets));
  return { ...page, rows, tree, run, within };
};

test('tree container row, middle zone: inside for BOTH drag kinds', () => {
  const { rows, containerC, run } = buildTreeRows();
  // y=12 is the middle zone (bands are 8px on a 24px row): the nest that
  // section drags on tree rows never offered (Tim's symptom 2).
  const section = run(rows.containerC, 12, 'section');
  assert.deepEqual(section.location, { anchor: 'CCC', placement: 'inside' });
  assert.equal(section.empty, true);
  assert.equal(section.gapId, 'tree|container:CCC|inside');
  assert.equal(section.canvasElement, containerC);
  const block = run(rows.containerC, 12, 'block');
  assert.deepEqual(block.location, { anchor: 'CCC', placement: 'inside' });
  assert.equal(block.newSection, undefined);
});

test('tree nested-container row, edge bands: plain siblings for BOTH kinds', () => {
  const { rows, run } = buildTreeRows();
  // y=4 is the top band; container C is nested (its parent flow is
  // container B's region), so a block lands as an ordinary sibling with
  // no wrap (Tim's symptom 1) and a section as a sibling as before.
  const block = run(rows.containerC, 4, 'block');
  assert.deepEqual(block.location, { anchor: 'CCC', placement: 'before' });
  assert.equal(block.newSection, undefined);
  assert.equal(block.gapId, 'tree|container:CCC|before');
  const section = run(rows.containerC, 20, 'section');
  assert.deepEqual(section.location, { anchor: 'CCC', placement: 'after' });
  assert.equal(section.newSection, undefined);
});

test('tree root-container row, edge band: block wraps, section stays plain', () => {
  const { rows, run } = buildTreeRows();
  // Container A is root-level: its sibling bands address ROOT-flow gaps,
  // so a block drop wraps in a new section there (the same legality the
  // canvas edge bands apply).
  const block = run(rows.containerA, 20, 'block');
  assert.deepEqual(block.location, { anchor: 'AAA', placement: 'after' });
  assert.equal(block.newSection, true);
  const section = run(rows.containerA, 4, 'section');
  assert.deepEqual(section.location, { anchor: 'AAA', placement: 'before' });
  assert.equal(section.newSection, undefined);
});

test('tree block row, midpoint split: before/after for BOTH kinds', () => {
  const { rows, blockA, run } = buildTreeRows();
  // Section drags on block rows are legal now (a section anchored on a
  // block, the sentence the unified grammar added).
  const section = run(rows.blockA, 4, 'section');
  assert.deepEqual(section.location, { anchor: 'blk-a', placement: 'before' });
  assert.equal(section.newSection, undefined);
  const block = run(rows.blockA, 20, 'block');
  assert.deepEqual(block.location, { anchor: 'blk-a', placement: 'after' });
  assert.equal(block.newSection, undefined);
  assert.equal(block.canvasElement, blockA);
});

test('tree root row: inside for both kinds; the block drop wraps', () => {
  const { rows, run } = buildTreeRows();
  const section = run(rows.root, 12, 'section');
  assert.deepEqual(section.location, { anchor: 'page-1', placement: 'inside' });
  assert.equal(section.newSection, undefined);
  const block = run(rows.root, 12, 'block');
  assert.deepEqual(block.location, { anchor: 'page-1', placement: 'inside' });
  assert.equal(block.newSection, true, 'the root row wrap-appends a block');
});

test('tree sections shell row: the root inside target for both kinds, its own gapId', () => {
  const { rows, main, run } = buildTreeRows();
  // The layout root claims the root's sections, so the row holding the
  // section rows appends to the root flow exactly as the root row does,
  // at every y (one inside zone, no bands).
  for (const y of [1, 12, 23]) {
    const section = run(rows.layout, y, 'section');
    assert.deepEqual(section.location, { anchor: 'page-1', placement: 'inside' });
    assert.equal(section.newSection, undefined);
    assert.equal(section.empty, true);
    assert.equal(section.kind, 'gap');
    assert.equal(section.container, rows.layout);
    assert.equal(section.canvasElement, main);
  }
  const block = run(rows.layout, 12, 'block');
  assert.deepEqual(block.location, { anchor: 'page-1', placement: 'inside' });
  assert.equal(block.newSection, true, 'the sections shell wrap-appends a block');
  // Two rows, one location: hysteresis still tells them apart.
  const root = run(rows.root, 12, 'section');
  assert.notEqual(run(rows.layout, 12, 'section').gapId, root.gapId);
  assert.equal(run(rows.layout, 12, 'section').gapId, 'tree|entity:page-1|sections|inside');
});

test('tree shell rows claiming no sections, or sections of a non-root owner, are no target', () => {
  const { rows, run } = buildTreeRows();
  assert.equal(run(rows.content, 12, 'section'), null, 'a fields-only shell');
  assert.equal(run(rows.content, 12, 'block'), null);
  assert.equal(run(rows.foreignLayout, 12, 'section'), null, 'not the root entity');
  assert.equal(run(rows.foreignLayout, 12, 'block'), null);
});

test('treeRowForLocation: the root inside face is the sections shell when rendered, else the root row', () => {
  const { rows, tree, within } = buildTreeRows();
  const rootInside = { anchor: 'page-1', placement: 'inside' };
  assert.equal(within(() => treeRowForLocation(rootInside)).row, rows.root, 'shells off');
  tree.add(rows.layout);
  assert.deepEqual(within(() => treeRowForLocation(rootInside)), { row: rows.layout, placement: 'inside' }, 'markup on');
  // Every other location keeps its keyed row: the shell answers for the
  // root inside location alone.
  assert.equal(within(() => treeRowForLocation({ anchor: 'CCC', placement: 'inside' })).row, rows.containerC);
  assert.equal(within(() => treeRowForLocation({ anchor: 'AAA', placement: 'after' })).row, rows.containerA);
});

// --- Builders: sibling section placement --------------------------------

test('moveSection emits the move at the resolved location', () => {
  const target = { location: { anchor: 'AAA', placement: 'after' } };
  assert.deepEqual(builders.moveSection(target, { uuid: 'x' }), [
    { op: 'move_element', element: 'x', location: { anchor: 'AAA', placement: 'after' } },
  ]);
});

// --- Annotation-fed emptiness -------------------------------------------
//
// Every test above ran WITHOUT a blueprint mirror on purpose: markupForKey
// answers null there, and emptiness degrades to the DOM item scan. These
// tests boot the mirror (inside the first test, so the earlier tests keep
// their unbooted store — callbacks run in file order after top-level
// code) and lock the annotation lane: the resolver's one {empty: true}
// answer, carried as the matched empty form's declared
// data-container-empty attribute on the container's markup annotation, is
// the emptiness fact the drop grammar reads; the canvas DOM is a
// projection, not a source.

const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');

const markupNode = (tag, attributes = {}) => ({
  markup: { tag, attributes, classes: [], hoisted: [], parts: {}, slots: {} },
});

const bootMirror = () => {
  store.dispatch(blueprintBooted({
    uuid: 'page-1',
    blueprint: {
      _element: markupNode('article'),
      // The delivered mirror carries BOTH section shapes: the classic
      // layout_builder__layout the projection index composes rows from
      // (the root-flow read below resolves through it) and the lean
      // `_tree` the built-in projection reads. One page, described twice.
      layout_builder__layout: [
        { uuid: 'AAA', layout_id: 'test_container', children: [{ kind: 'block', uuid: 'blk-a', block_type: 'text', label: 'Text', block_fields: {} }] },
        { uuid: 'BBB', layout_id: 'test_container', children: [{ kind: 'container', uuid: 'CCC', layout_id: 'test_container', children: [] }] },
      ],
      _tree: [
        {
          kind: 'container',
          uuid: 'AAA',
          layout_id: 'test_container',
          _element: markupNode('section'),
          children: [{ kind: 'block', uuid: 'blk-a' }],
        },
        {
          kind: 'container',
          uuid: 'BBB',
          layout_id: 'test_container',
          _element: markupNode('section'),
          children: [{
            kind: 'container',
            uuid: 'CCC',
            layout_id: 'test_container',
            // The one-box container's empty form matched: the declared
            // drop-affordance marker rides the resolved root.
            _element: markupNode('div', { 'data-container-empty': '' }),
            children: [],
          }],
        },
      ],
    },
  }));
};

test('annotation emptiness: an annotated-empty container is one inside target on its root', () => {
  bootMirror();
  const { innerC, containerC } = buildPage();
  const target = resolveDropTarget(innerC, 500, 600, 'section');
  assert.deepEqual(target.location, { anchor: 'CCC', placement: 'inside' });
  assert.equal(target.empty, true);
  // The drop surface reads off the annotation too: no part claims the
  // regions slot (the one-box container), so the surface is the container
  // ROOT, not the unkeyed inner wrapper the cursor sat in.
  assert.equal(target.insert.parent, containerC);
});

test('annotation emptiness beats a stale DOM item (mid-mutation moment)', () => {
  const { innerC } = buildPage();
  // A stale block still sits in container C's canvas markup, but the
  // annotation says the container resolved EMPTY: the grammar reads the
  // model's answer, not a second client-side derivation.
  innerC.add(new El('block:blk-stale', { left: 150, top: 550, width: 700, height: 100 }));
  const target = resolveDropTarget(innerC, 500, 600, 'section');
  assert.deepEqual(target.location, { anchor: 'CCC', placement: 'inside' });
  assert.equal(target.empty, true);
});

test('a populated container annotated non-empty keeps its positioned gaps', () => {
  const { blockA } = buildPage();
  const target = resolveDropTarget(blockA, 500, 150, 'section');
  assert.deepEqual(target.location, { anchor: 'blk-a', placement: 'before' });
  assert.equal(target.empty, false);
});

test('an item-less flow is an inside target regardless of its annotation', () => {
  // Container B is annotated NON-empty (it holds C in the model), but
  // this canvas moment resolves no flow items: gaps are computed FROM
  // items, so the honest degrade is the whole-container target, never a
  // broken caret.
  const main = new El('entity:page-1', { left: 0, top: 0, width: 1000, height: 800 });
  main.mainEntity = true;
  const containerB = main.add(new El('container:BBB', { left: 0, top: 0, width: 1000, height: 800 }));
  const inner = containerB.add(new El(null, { left: 0, top: 0, width: 1000, height: 800 }));
  const target = resolveDropTarget(inner, 500, 400, 'section');
  assert.deepEqual(target.location, { anchor: 'BBB', placement: 'inside' });
});

// ——— The root-flow read, and a source's acceptsTarget over it. ———
//
// A drag source answers "may this land here" from the target's LOCATION
// alone: isRootFlowLocation resolves the container a location inserts
// into through the projection index (the mirror booted above) and asks
// whether it is the projection root. The read must agree with the
// DOM-derived rootFlow flag the resolvers stamp on gap targets, so a
// door that builds a bare {location} (the Add panel's click-to-place)
// answers exactly as the pointer drag and the keyboard lift do.

test('isRootFlowLocation: the page entity is the root flow; every container is not', () => {
  assert.equal(containerKeyForLocation({ anchor: 'page-1', placement: 'inside' }), 'entity:page-1', 'a root inside gap inserts into the page entity');
  assert.equal(containerKeyForLocation({ anchor: 'AAA', placement: 'after' }), 'entity:page-1', 'a top-level sibling gap inserts into the page entity');
  assert.ok(isRootFlowLocation({ anchor: 'page-1', placement: 'inside' }), 'append to a page: root flow');
  assert.ok(isRootFlowLocation({ anchor: 'AAA', placement: 'after' }) && isRootFlowLocation({ anchor: 'BBB', placement: 'before' }), 'beside a top-level container: root flow');
  assert.ok(!isRootFlowLocation({ anchor: 'AAA', placement: 'inside' }), 'inside a top-level container: nested');
  assert.ok(!isRootFlowLocation({ anchor: 'CCC', placement: 'after' }) && !isRootFlowLocation({ anchor: 'CCC', placement: 'inside' }), 'a nested container and its siblings: nested');
  assert.ok(!isRootFlowLocation({ anchor: 'blk-a', placement: 'after' }), 'beside a block: nested');
  assert.ok(!isRootFlowLocation({ anchor: 'nope', placement: 'after' }) && !isRootFlowLocation(null), 'an unresolvable location is not the root flow');
});

test('a band stencil source accepts exactly the gaps the resolvers flag rootFlow', () => {
  const { main } = buildPage();
  globalThis.document = { querySelector: (selector) => (selector === '[data-main-entity]' ? main : null) };
  const roster = createRoster([
    { kind: 'variant', id: 'container_site', label: 'Site', description: '', value: null, group: 'width', spellings: { 'attr:kind:container:width': 'site' }, scope: 'kind:container', applies: { depth: 'root' }, owned_by: null, published: true, weight: 0, icon: null, root_kind: null, origin: 'shipped', updatable: false },
    { kind: 'stencil', id: 'band', label: 'Band', description: '', value: null, group: null, spellings: { stencil: 'band' }, scope: null, applies: { wears: ['container_site'] }, owned_by: null, published: true, weight: 0, icon: null, root_kind: 'section', origin: 'user', updatable: true },
    { kind: 'stencil', id: 'card', label: 'Card', description: '', value: null, group: null, spellings: { stencil: 'card' }, scope: null, applies: [], owned_by: null, published: true, weight: 0, icon: null, root_kind: 'section', origin: 'user', updatable: true },
  ]);
  // The source spec the Add panel declares for a section-rooted stencil:
  // the roster's predicate over the location's depth.
  const source = {
    targets: 'section',
    acceptsTarget: (target, data) => roster.applicable(roster.get('stencil', data.stencilId), 'kind:container', isRootFlowLocation(target.location)),
  };
  const gaps = enumerateGapTargets(source.targets);
  assert.ok(gaps.length > 3, 'the fixture enumerates root and nested section gaps');
  const flagged = gaps.filter((gap) => gap.rootFlow === true).map((gap) => gap.gapId);
  const offered = gaps.filter((gap) => source.acceptsTarget(gap, { stencilId: 'band' })).map((gap) => gap.gapId);
  assert.deepEqual(offered, flagged, 'the location read and the DOM-derived rootFlow flag name the same gaps');
  assert.deepEqual(offered, ['AAA|before', 'AAA|after', 'BBB|after'], 'a band is offered the root flow only');
  assert.deepEqual(
    gaps.filter((gap) => source.acceptsTarget(gap, { stencilId: 'card' })).map((gap) => gap.gapId),
    gaps.map((gap) => gap.gapId),
    'a stencil that is no band is offered every gap',
  );
  // The click door builds a bare {location} from its anchor: the same
  // answers, with no rootFlow flag to read.
  assert.ok(source.acceptsTarget({ location: { anchor: 'BBB', placement: 'after' } }, { stencilId: 'band' }), 'after a top-level container: offered');
  assert.ok(!source.acceptsTarget({ location: { anchor: 'CCC', placement: 'after' } }, { stencilId: 'band' }), 'after a nested container: refused');
  assert.ok(source.acceptsTarget({ location: { anchor: 'CCC', placement: 'after' } }, { stencilId: 'card' }));
});
