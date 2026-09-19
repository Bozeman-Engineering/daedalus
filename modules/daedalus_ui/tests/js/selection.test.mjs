/**
 * Self-asserting tests for the selection spine's part facet.
 *
 * The slice half locks the reducer invariants: a part is only ever valid
 * with its key (any key change without an explicit part clears the facet),
 * multi-select is element-only (every set gesture clears selectedPart),
 * setHoverPart writes only the facet, and clearSelection resets both
 * facets. The facade half locks the change-firing granularity decided with
 * the facet: onChange stays KEY-only (element-scoped clear-on-change
 * consumers must not be disturbed by part drills), while onPartChange
 * fires on any (key, part) tuple change and onHoverPartChange on any
 * part-hover value change.
 *
 * The ancestry half locks the breadcrumb's trail (ancestry.js) over the
 * two-tier selection index: a part selection climbs its markup parents to
 * the owner and on through the row parents to the root, every crumb
 * carries the {key, part} pair the tree's rows commit, and a part the
 * index does not place (a hoisted one) contributes no crumb.
 *
 * The traversal half locks the arrow grammar (traversal.js) over the same
 * index: Up/Down walk the row parent's `rows` across both tiers, Right
 * drills into the first row (Raster from a linked Image, nothing from an
 * unlinked one), Left climbs the row parent (list item to list, list to
 * its owner block), and every step carries the pair to commit plus the
 * element-tier drill context.
 *
 * The facade imports the app store (webpack-style extensionless imports;
 * window and Drupal globals), so the suite registers an extension-adding
 * resolve hook and stubs the globals before dynamically importing it.
 *
 * Run with `node --test tests/js/selection.test.mjs` from the module root
 * (the bare directory form would try to execute non-test helpers as
 * tests).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import reducer, {
  setHover,
  setHoverPart,
  setSelected,
  toggleSelected,
  removeSelected,
  setSelectedSet,
  clearSelection,
} from '../../js/edit_mode/store/slices/selection-slice.js';

// ---------------------------------------------------------------------------
// Slice invariants
// ---------------------------------------------------------------------------

const initial = reducer(undefined, { type: '@@INIT' });

/**
 * Runs a sequence of actions from the initial state.
 */
const run = (...actions) => actions.reduce(reducer, initial);

test('object setSelected commits the (key, part) pair as a single selection', () => {
  const state = run(setSelected({ key: 'A', part: 'icon' }));
  assert.equal(state.selectedKey, 'A');
  assert.equal(state.selectedPart, 'icon');
  assert.deepEqual(state.selectedKeys, ['A']);
});

test('bare-key setSelected clears the part facet (key change without an explicit part)', () => {
  const state = run(setSelected({ key: 'A', part: 'icon' }), setSelected('B'));
  assert.equal(state.selectedKey, 'B');
  assert.equal(state.selectedPart, null);
});

test('re-selecting the same key without a part clears the facet (the reclick shape)', () => {
  const state = run(setSelected({ key: 'A', part: 'icon' }), setSelected('A'));
  assert.equal(state.selectedKey, 'A');
  assert.equal(state.selectedPart, null);
});

test('a part without a key is invalid and drops to null', () => {
  const state = run(setSelected({ key: null, part: 'icon' }));
  assert.equal(state.selectedKey, null);
  assert.equal(state.selectedPart, null);
  assert.deepEqual(state.selectedKeys, []);
});

test('toggleSelected is element-only: any toggle clears the part facet', () => {
  const state = run(setSelected({ key: 'A', part: 'icon' }), toggleSelected('B'));
  assert.deepEqual(state.selectedKeys, ['A', 'B']);
  assert.equal(state.selectedKey, 'B');
  assert.equal(state.selectedPart, null);
});

test('removeSelected of the primary clears the part facet; a non-member remove is a no-op', () => {
  const selected = run(setSelected({ key: 'A', part: 'icon' }));
  const removed = reducer(selected, removeSelected('A'));
  assert.equal(removed.selectedKey, null);
  assert.equal(removed.selectedPart, null);
  const noop = reducer(selected, removeSelected('B'));
  assert.equal(noop.selectedKey, 'A');
  assert.equal(noop.selectedPart, 'icon');
});

test('setSelectedSet is element-only: replacing the set clears the part facet', () => {
  const state = run(
    setSelected({ key: 'A', part: 'icon' }),
    setSelectedSet({ keys: ['A', 'B'], primary: 'A' }),
  );
  assert.equal(state.selectedKey, 'A');
  assert.equal(state.selectedPart, null);
});

test('setHover with a part sets both hover halves; a bare key clears the facet', () => {
  const hovered = run(setHover({ key: 'A', part: 'icon' }));
  assert.equal(hovered.hoverKey, 'A');
  assert.deepEqual(hovered.hoverPart, { key: 'A', part: 'icon' });
  const moved = reducer(hovered, setHover('B'));
  assert.equal(moved.hoverKey, 'B');
  assert.equal(moved.hoverPart, null);
  const left = reducer(moved, setHover(null));
  assert.equal(left.hoverKey, null);
  assert.equal(left.hoverPart, null);
});

test('setHoverPart writes only the facet, leaving the element hover alone', () => {
  const hovered = run(setHover('A'), setHoverPart({ key: 'B', part: 'icon' }));
  assert.equal(hovered.hoverKey, 'A');
  assert.deepEqual(hovered.hoverPart, { key: 'B', part: 'icon' });
  const cleared = reducer(hovered, setHoverPart(null));
  assert.equal(cleared.hoverKey, 'A');
  assert.equal(cleared.hoverPart, null);
  // A part without its owner key is invalid.
  const invalid = reducer(hovered, setHoverPart({ part: 'icon' }));
  assert.equal(invalid.hoverPart, null);
});

test('clearSelection resets both part facets with the rest of the spine', () => {
  const state = run(
    setSelected({ key: 'A', part: 'icon' }),
    setHoverPart({ key: 'A', part: 'label' }),
    clearSelection(),
  );
  assert.equal(state.selectedKey, null);
  assert.equal(state.selectedPart, null);
  assert.equal(state.hoverKey, null);
  assert.equal(state.hoverPart, null);
  assert.deepEqual(state.selectedKeys, []);
});

// ---------------------------------------------------------------------------
// Facade change-firing granularity
// ---------------------------------------------------------------------------

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (specifier.startsWith('.') || specifier.startsWith('/')) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});
globalThis.window = globalThis;
globalThis.document = {
  addEventListener: () => {},
  querySelector: () => null,
  documentElement: { addEventListener: () => {} },
};
globalThis.Drupal = { t: (string) => string };

const { labelFor, fallbackLabel } = await import('../../js/edit_mode/selection/label-for-key.js');
const { typeFor } = await import('../../js/edit_mode/selection/type-for-key.js');
const { ancestryOf } = await import('../../js/edit_mode/selection/ancestry.js');
const { seatOf, siblingOf, parentOf, firstRowOf } = await import('../../js/edit_mode/selection/traversal.js');
const { partAddress, splitAddress, placedAddress } = await import('../../js/edit_mode/components/page-elements/projection-index.js');

// ---------------------------------------------------------------------------
// The label resolver (label-for-key.js): one wording for every surface
// ---------------------------------------------------------------------------

test('labelFor prefers the projection index and falls back to the vocabulary', () => {
  const index = new Map([['block:u1:hero', { label: 'Masthead' }]]);
  assert.equal(labelFor(index, 'block:u1:hero'), 'Masthead');
  assert.equal(labelFor(index, 'block:u2:hero_banner'), 'Hero banner');
  assert.equal(labelFor(null, 'block:u1:hero'), 'Hero');
});

test('the vocabulary fallback names every key kind and never returns empty', () => {
  assert.equal(fallbackLabel('entity:node:1'), 'Entity');
  assert.equal(fallbackLabel('container:abc'), 'Container');
  assert.equal(fallbackLabel('field:node:field_body'), 'Body');
  assert.equal(fallbackLabel('field:node'), 'Field');
  assert.equal(fallbackLabel('block:u1'), 'Block');
  assert.equal(fallbackLabel('block:u1:my-cta_button'), 'My cta button');
  assert.equal(fallbackLabel('mystery'), 'Element');
  assert.equal(fallbackLabel('field:x:_'), 'Element');
});

test('labelFor labels a part address from the index like any other row', () => {
  const index = new Map([
    ['block:u1', { label: 'Image', tier: 'element' }],
    [partAddress('block:u1', '.image-block-image'), { label: 'Raster', tier: 'part' }],
  ]);
  assert.equal(labelFor(index, partAddress('block:u1', '.image-block-image')), 'Raster');
});

test('splitAddress is partAddress\'s inverse; an element key splits to a null part', () => {
  assert.deepEqual(splitAddress(partAddress('block:u1:field_image', '.field__item')), {
    key: 'block:u1:field_image',
    part: '.field__item',
  });
  assert.deepEqual(splitAddress('block:u1:field_image'), { key: 'block:u1:field_image', part: null });
});

test('placedAddress seats a placed part on its address and an unplaced one on its owner', () => {
  const index = new Map([
    ['block:u1', { label: 'Image', tier: 'element' }],
    [partAddress('block:u1', '.image-block-image'), { label: 'Raster', tier: 'part' }],
  ]);
  assert.equal(placedAddress(index, 'block:u1', '.image-block-image'), partAddress('block:u1', '.image-block-image'));
  assert.equal(placedAddress(index, 'block:u1', '.s-zz'), 'block:u1');
  assert.equal(placedAddress(index, 'block:u1'), 'block:u1');
  assert.equal(placedAddress(null, 'block:u1', '.image-block-image'), 'block:u1');
});

// ---------------------------------------------------------------------------
// The ancestry resolver (ancestry.js): the breadcrumb's trail over both tiers
// ---------------------------------------------------------------------------

/**
 * The index shape projection.test.mjs locks, hand-built: a linked
 * image whose Raster shell claims the image field (its link field a plain
 * row beside the shell), an UNLINKED image whose hoisted picture has no
 * row, and a merged text block carrying a nested list as instance
 * carriers. `rowParent` is the panel's nesting (the field sits in the
 * shell); `parent` is the element tier; `rows` is the panel's order over
 * both tiers.
 */
const IMAGE = 'block:img';
const RASTER = partAddress(IMAGE, '.image-block-image');
const FIELD = `${IMAGE}:field_image`;
const LINK = `${IMAGE}:field_link`;
const UNLINKED = 'block:img2';
const TEXT = 'block:txt';
const UL = partAddress(TEXT, '.s-l1');
const LI = partAddress(TEXT, '.s-i1');
const ancestryIndex = () => new Map([
  ['entity:r', { label: 'Page', tier: 'element', parent: null, rowParent: null, rows: ['container:s'] }],
  ['container:s', { label: 'Container', tier: 'element', parent: 'entity:r', rowParent: 'entity:r', rows: [IMAGE, UNLINKED, TEXT] }],
  [IMAGE, { label: 'Image', tier: 'element', parent: 'container:s', rowParent: 'container:s', rows: [RASTER, LINK] }],
  [RASTER, { label: 'Raster', tier: 'part', owner: IMAGE, parent: IMAGE, rowParent: IMAGE, rows: [FIELD] }],
  [FIELD, { label: 'Field image', tier: 'element', parent: IMAGE, rowParent: RASTER, rows: [] }],
  [LINK, { label: 'Link', tier: 'element', parent: IMAGE, rowParent: IMAGE, rows: [] }],
  [UNLINKED, { label: 'Image', tier: 'element', parent: 'container:s', rowParent: 'container:s', rows: [] }],
  [TEXT, { label: 'Formatted text', tier: 'element', parent: 'container:s', rowParent: 'container:s', rows: [UL] }],
  [UL, { label: 'Bulleted list', tier: 'part', owner: TEXT, parent: TEXT, rowParent: TEXT, rows: [LI] }],
  [LI, { label: 'List item', tier: 'part', owner: TEXT, parent: UL, rowParent: UL, rows: [] }],
]);

const labels = (trail) => trail.map((crumb) => crumb.label);

test('a selected shell part trails through its owner: Container › Image › Raster', () => {
  const trail = ancestryOf(ancestryIndex(), IMAGE, '.image-block-image');
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Image', 'Raster']);
  // Every crumb carries what its click commits: the pair for the part, the
  // bare key for the elements above it.
  assert.deepEqual(trail.at(-1), { address: RASTER, key: IMAGE, part: '.image-block-image', label: 'Raster' });
  assert.deepEqual(trail.at(-2), { address: IMAGE, key: IMAGE, part: null, label: 'Image' });
  assert.deepEqual(trail[0], { address: 'entity:r', key: 'entity:r', part: null, label: 'Page' });
});

test('a nested carrier trails part to part: Formatted text › Bulleted list › List item', () => {
  const trail = ancestryOf(ancestryIndex(), TEXT, '.s-i1');
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Formatted text', 'Bulleted list', 'List item']);
  assert.deepEqual(trail.at(-1), { address: LI, key: TEXT, part: '.s-i1', label: 'List item' });
  assert.deepEqual(trail.at(-2), { address: UL, key: TEXT, part: '.s-l1', label: 'Bulleted list' });
  assert.deepEqual(trail.at(-3), { address: TEXT, key: TEXT, part: null, label: 'Formatted text' });
});

test('an element claimed into a shell names the shell above it (the panel\'s nesting)', () => {
  const trail = ancestryOf(ancestryIndex(), FIELD);
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Image', 'Raster', 'Field image']);
  assert.equal(trail.at(-2).part, '.image-block-image');
});

test('a part the index does not place contributes no crumb: the trail is the owner\'s', () => {
  // The hoisted case (the unlinked image's picture IS the block root): the
  // facet can still be committed through the facade, but the index has no
  // such address, so the breadcrumb ends at the element it rides on.
  const trail = ancestryOf(ancestryIndex(), 'container:s', '.image-block-image');
  assert.deepEqual(labels(trail), ['Page', 'Container']);
  assert.deepEqual(trail.at(-1), { address: 'container:s', key: 'container:s', part: null, label: 'Container' });
});

test('an element the index cannot place is its own one-crumb trail', () => {
  assert.deepEqual(ancestryOf(ancestryIndex(), 'block:u9:hero_banner'), [
    { address: 'block:u9:hero_banner', key: 'block:u9:hero_banner', part: null, label: 'Hero banner' },
  ]);
  assert.deepEqual(labels(ancestryOf(null, 'container:x')), ['Container']);
});

// ---------------------------------------------------------------------------
// The traversal grammar (traversal.js): arrows over both tiers
// ---------------------------------------------------------------------------

test('Right from a linked Image drills into its Raster shell, drill seated at the owner\'s parent', () => {
  assert.deepEqual(firstRowOf(ancestryIndex(), IMAGE), {
    address: RASTER, key: IMAGE, part: '.image-block-image', drill: 'container:s',
  });
  // And on into the field the shell claims: an element step, drill = its
  // element parent (the owner), exactly the pointer's drill-in shape.
  assert.deepEqual(firstRowOf(ancestryIndex(), IMAGE, '.image-block-image'), {
    address: FIELD, key: FIELD, part: null, drill: IMAGE,
  });
});

test('Right from an unlinked Image finds no row: the hoisted picture is not a stop', () => {
  assert.equal(firstRowOf(ancestryIndex(), UNLINKED), null);
});

test('Left from a list item climbs to its list (part to part)', () => {
  assert.deepEqual(parentOf(ancestryIndex(), TEXT, '.s-i1'), {
    address: UL, key: TEXT, part: '.s-l1', drill: 'container:s',
  });
});

test('Left from the list climbs to the owner block (part to element)', () => {
  assert.deepEqual(parentOf(ancestryIndex(), TEXT, '.s-l1'), {
    address: TEXT, key: TEXT, part: null, drill: 'container:s',
  });
  // Left from a field claimed into a shell lands on the shell, the same
  // stop the breadcrumb draws above it.
  assert.deepEqual(parentOf(ancestryIndex(), FIELD), {
    address: RASTER, key: IMAGE, part: '.image-block-image', drill: 'container:s',
  });
  // The root has nowhere to climb.
  assert.equal(parentOf(ancestryIndex(), 'entity:r'), null);
});

test('Up/Down walk the row parent\'s rows across both tiers and stop at the edges', () => {
  // A shell part and a plain field are siblings in the owner's rows.
  assert.deepEqual(siblingOf(ancestryIndex(), IMAGE, '.image-block-image', 1), {
    address: LINK, key: LINK, part: null, drill: IMAGE,
  });
  assert.deepEqual(siblingOf(ancestryIndex(), LINK, null, -1), {
    address: RASTER, key: IMAGE, part: '.image-block-image', drill: 'container:s',
  });
  assert.equal(siblingOf(ancestryIndex(), IMAGE, '.image-block-image', -1), null);
  // Element siblings still walk the same list they always did.
  assert.equal(siblingOf(ancestryIndex(), IMAGE, null, 1).address, UNLINKED);
  assert.equal(siblingOf(ancestryIndex(), TEXT, null, 1), null);
  // The root is its own only sibling.
  assert.equal(siblingOf(ancestryIndex(), 'entity:r', null, 1), null);
  assert.equal(siblingOf(ancestryIndex(), 'entity:r', null, -1), null);
});

test('an unplaced part seats on its owner; an unplaced element has no seat', () => {
  // The hoisted facet the facade can still commit: arrows move as if the
  // owner block were selected.
  assert.equal(seatOf(ancestryIndex(), UNLINKED, '.image-block-image').address, UNLINKED);
  assert.equal(siblingOf(ancestryIndex(), UNLINKED, '.image-block-image', 1).address, TEXT);
  // Outside the universe: nothing to move, the caller declines the key.
  assert.equal(seatOf(ancestryIndex(), 'block:u9'), null);
  assert.equal(seatOf(null, 'block:u9'), null);
  assert.equal(firstRowOf(ancestryIndex(), 'block:u9'), null);
});

// ---------------------------------------------------------------------------
// The type resolver (type-for-key.js): what the element IS, never what it
// says — the label resolver's sibling read of the same projection index.
// ---------------------------------------------------------------------------

test('a block speaks its bundle as its type, whatever content its label carries', () => {
  const index = new Map([
    ['block:u1', { label: 'Hello there', kind: 'block', bundle: 'text', parent: 'entity:r' }],
    ['block:u2', { label: 'Gallery', kind: 'block', bundle: 'image_gallery', parent: 'entity:r' }],
    ['block:u3', { label: 'Plugin block', kind: 'block', bundle: null, parent: 'entity:r' }],
  ]);
  // The seam: the same projection node answers two named reads — the label
  // is the content, the type is the bundle.
  assert.equal(typeFor(index, 'block:u1'), 'Text');
  assert.notEqual(typeFor(index, 'block:u1'), index.get('block:u1').label);
  assert.equal(typeFor(index, 'block:u2'), 'Image gallery');
  // No bundle (a plugin block): the vocabulary fallback, never null.
  assert.equal(typeFor(index, 'block:u3'), 'Block');
});

test('a top-level container is a Section; one nested in another is a Container', () => {
  const index = new Map([
    ['entity:r', { label: 'Page', kind: 'entity', bundle: 'page', parent: null }],
    ['container:top', { label: 'Renamed hero', kind: 'container', bundle: null, parent: 'entity:r' }],
    ['container:inner', { label: 'Inner', kind: 'container', bundle: null, parent: 'container:top' }],
  ]);
  assert.equal(typeFor(index, 'container:top'), 'Section');
  assert.equal(typeFor(index, 'container:inner'), 'Container');
});

test('entities speak their bundle and fields their humanized field name', () => {
  const index = new Map([
    ['entity:r', { label: 'Hello there', kind: 'entity', bundle: 'landing_page', parent: null }],
    ['field:r:field_body', { label: 'Body', kind: 'field', bundle: null, parent: 'entity:r' }],
    ['block:u1:field_text_tag', { label: 'Text tag', kind: 'field', bundle: null, parent: 'block:u1' }],
  ]);
  assert.equal(typeFor(index, 'entity:r'), 'Landing page');
  assert.equal(typeFor(index, 'field:r:field_body'), 'Body');
  assert.equal(typeFor(index, 'block:u1:field_text_tag'), 'Text tag');
});

test('keys without a projection node fall back to the key vocabulary, never null', () => {
  assert.equal(typeFor(null, 'block:u9:hero_banner'), 'Hero banner');
  assert.equal(typeFor(new Map(), 'container:abc'), 'Container');
  assert.equal(typeFor(new Map(), 'mystery'), 'Element');
});

const { initSelectionFacade } = await import('../../js/edit_mode/selection/selection-facade.js');
initSelectionFacade();
const Selection = globalThis.Drupal.Daedalus.Selection;

/**
 * Records every (current, previous) delivery of a facade subscription for
 * the duration of act(), then unsubscribes.
 */
const record = (subscribe, act) => {
  const calls = [];
  const unsubscribe = subscribe((current, previous) => calls.push({ current, previous }));
  try {
    act();
  } finally {
    unsubscribe();
  }
  return calls;
};

test('facade select carries the facet and the getters read it', () => {
  Selection.clear();
  Selection.select('A', 'icon');
  assert.equal(Selection.getSelected(), 'A');
  assert.equal(Selection.getSelectedPart(), 'icon');
  Selection.select('B');
  assert.equal(Selection.getSelected(), 'B');
  assert.equal(Selection.getSelectedPart(), null);
});

test('facade hover and hoverPart drive the two hover halves independently', () => {
  Selection.clear();
  Selection.hover('A');
  assert.equal(Selection.getHovered(), 'A');
  assert.equal(Selection.getHoveredPart(), null);
  Selection.hoverPart('B', 'icon');
  assert.equal(Selection.getHovered(), 'A');
  assert.deepEqual(Selection.getHoveredPart(), { key: 'B', part: 'icon' });
  Selection.hoverPart(null);
  assert.equal(Selection.getHovered(), 'A');
  assert.equal(Selection.getHoveredPart(), null);
  Selection.hover('C', 'label');
  assert.equal(Selection.getHovered(), 'C');
  assert.deepEqual(Selection.getHoveredPart(), { key: 'C', part: 'label' });
});

test('onChange fires on key changes only, never on part-facet changes under the same key', () => {
  Selection.clear();
  const calls = record(Selection.onChange, () => {
    Selection.select('A', 'icon');
    // Facet-only changes under the same key are silent here.
    Selection.select('A', 'label');
    Selection.select('A');
    // A key change fires, whatever the facets do.
    Selection.select('B', 'icon');
  });
  assert.deepEqual(calls, [
    { current: 'A', previous: null },
    { current: 'B', previous: 'A' },
  ]);
});

test('onPartChange fires on any (key, part) tuple change and is value-compared', () => {
  Selection.clear();
  const calls = record(Selection.onPartChange, () => {
    Selection.select('A', 'icon');
    Selection.select('A', 'label');
    // The same tuple re-asserted does not fire.
    Selection.select('A', 'label');
    Selection.select('B');
  });
  assert.deepEqual(calls, [
    { current: { key: 'A', part: 'icon' }, previous: { key: null, part: null } },
    { current: { key: 'A', part: 'label' }, previous: { key: 'A', part: 'icon' } },
    { current: { key: 'B', part: null }, previous: { key: 'A', part: 'label' } },
  ]);
});

test('onHoverPartChange fires on part-hover value changes, from either writer', () => {
  Selection.clear();
  const calls = record(Selection.onHoverPartChange, () => {
    Selection.hoverPart('A', 'icon');
    // The same value re-asserted does not fire.
    Selection.hoverPart('A', 'icon');
    // A bare element hover clears the facet (an explicit-part-absent write).
    Selection.hover('B');
    Selection.hover('B', 'label');
  });
  assert.deepEqual(calls, [
    { current: { key: 'A', part: 'icon' }, previous: null },
    { current: null, previous: { key: 'A', part: 'icon' } },
    { current: { key: 'B', part: 'label' }, previous: null },
  ]);
});

test('element-only set gestures clear the committed part facet through the facade', () => {
  Selection.clear();
  Selection.select('A', 'icon');
  Selection.toggle('B');
  assert.equal(Selection.getSelectedPart(), null);
  Selection.select('A', 'icon');
  Selection.selectSet(['A', 'B'], 'A');
  assert.equal(Selection.getSelectedPart(), null);
  Selection.clear();
});
