/**
 * Self-asserting tests for the selection spine's styling target.
 *
 * The slice half locks the reducer invariants: a target is only ever
 * valid with a single selection (every selection write clears it, and
 * setTarget under a multi-select or no selection drops to null),
 * setHoverSelector writes only the hover facet, and clearSelection
 * resets the target and the hover facet. The facade half locks the
 * change-firing granularity decided with the target: onChange stays
 * KEY-only (element-scoped clear-on-change consumers must not be
 * disturbed by target moves), while onTargetChange fires on a selection
 * or target change and onHoverSelectorChange on any selector-hover value
 * change; and the facade's gates: every public write canonicalizes the
 * selector through the relative-selector grammar and refuses text outside
 * it, and target() refuses a key that is neither the selection nor an
 * ancestor of it.
 *
 * The ancestry half locks the breadcrumb's trail (ancestry.js) over the
 * two-tier selection index read through reach (reach.js): a drawn part
 * target climbs its markup parents to the owner and on through the row
 * parents to the root, every crumb carries the (key, selector) the
 * tree's rows commit, a target the index does not place (a hoisted part,
 * a tag tier, a chain, an ancestor rule) or places on a row the Markup
 * view is not drawing ends the trail as one target crumb after the
 * element's, and the trail never names an undrawn shell.
 *
 * The traversal half locks the arrow grammar (traversal.js) over the same
 * index and reach: Up/Down walk the drawn row parent's drawn `rows`
 * across both tiers, Right drills into the first drawn row (Raster from
 * a linked Image with the Markup view on, the claimed field with it off,
 * nothing from an unlinked one), Left climbs to the nearest drawn row
 * parent (list item to list, list to its owner block, the claimed field
 * past an undrawn shell to its owner), and every step carries the pair to
 * commit plus the element-tier drill context.
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
  setHoverSelector,
  setSelected,
  setTarget,
  toggleSelected,
  removeSelected,
  setSelectedSet,
  setPickerArmed,
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

test('setSelected commits a single selection; setTarget seats the target under it', () => {
  const state = run(setSelected('A'), setTarget({ key: 'A', selector: '.icon' }));
  assert.equal(state.selectedKey, 'A');
  assert.deepEqual(state.target, { key: 'A', selector: '.icon' });
  assert.deepEqual(state.selectedKeys, ['A']);
});

test('a target may sit on an ancestor of the selection (the slice holds no tree; the facade judges ancestry)', () => {
  const state = run(setSelected('A'), setTarget({ key: 'P', selector: '> pb-text' }));
  assert.equal(state.selectedKey, 'A');
  assert.deepEqual(state.target, { key: 'P', selector: '> pb-text' });
});

test('setSelected of another key clears the target (a selection write is element-only)', () => {
  const state = run(setSelected('A'), setTarget({ key: 'A', selector: '.icon' }), setSelected('B'));
  assert.equal(state.selectedKey, 'B');
  assert.equal(state.target, null);
});

test('re-selecting the same key clears the target (the reclick shape)', () => {
  const state = run(setSelected('A'), setTarget({ key: 'A', selector: '.icon' }), setSelected('A'));
  assert.equal(state.selectedKey, 'A');
  assert.equal(state.target, null);
});

test('setTarget(null) drops the target and leaves the selection', () => {
  const state = run(setSelected('A'), setTarget({ key: 'A', selector: '.icon' }), setTarget(null));
  assert.equal(state.selectedKey, 'A');
  assert.equal(state.target, null);
});

test('a target without a selection, or without a key or selector, drops to null', () => {
  assert.equal(run(setTarget({ key: 'A', selector: '.icon' })).target, null);
  assert.equal(run(setSelected('A'), setTarget({ key: null, selector: '.icon' })).target, null);
  assert.equal(run(setSelected('A'), setTarget({ key: 'A', selector: null })).target, null);
});

test('toggleSelected is element-only: any toggle clears the target, and a set carries none', () => {
  const state = run(setSelected('A'), setTarget({ key: 'A', selector: '.icon' }), toggleSelected('B'));
  assert.deepEqual(state.selectedKeys, ['A', 'B']);
  assert.equal(state.selectedKey, 'B');
  assert.equal(state.target, null);
  assert.equal(reducer(state, setTarget({ key: 'B', selector: '.icon' })).target, null);
});

test('removeSelected of the primary clears the target; a non-member remove is a no-op', () => {
  const selected = run(setSelected('A'), setTarget({ key: 'A', selector: '.icon' }));
  const removed = reducer(selected, removeSelected('A'));
  assert.equal(removed.selectedKey, null);
  assert.equal(removed.target, null);
  const noop = reducer(selected, removeSelected('B'));
  assert.equal(noop.selectedKey, 'A');
  assert.deepEqual(noop.target, { key: 'A', selector: '.icon' });
});

test('setSelectedSet is element-only: replacing the set clears the target', () => {
  const state = run(
    setSelected('A'),
    setTarget({ key: 'A', selector: '.icon' }),
    setSelectedSet({ keys: ['A', 'B'], primary: 'A' }),
  );
  assert.equal(state.selectedKey, 'A');
  assert.equal(state.target, null);
});

test('setHover with a selector sets both hover halves; a bare key clears the facet', () => {
  const hovered = run(setHover({ key: 'A', selector: '.icon' }));
  assert.equal(hovered.hoverKey, 'A');
  assert.deepEqual(hovered.hoverSelector, { key: 'A', selector: '.icon' });
  const moved = reducer(hovered, setHover('B'));
  assert.equal(moved.hoverKey, 'B');
  assert.equal(moved.hoverSelector, null);
  const left = reducer(moved, setHover(null));
  assert.equal(left.hoverKey, null);
  assert.equal(left.hoverSelector, null);
});

test('setHoverSelector writes only the facet, leaving the element hover alone', () => {
  const hovered = run(setHover('A'), setHoverSelector({ key: 'B', selector: '.icon' }));
  assert.equal(hovered.hoverKey, 'A');
  assert.deepEqual(hovered.hoverSelector, { key: 'B', selector: '.icon' });
  const cleared = reducer(hovered, setHoverSelector(null));
  assert.equal(cleared.hoverKey, 'A');
  assert.equal(cleared.hoverSelector, null);
  // A selector without its owner key is invalid.
  const invalid = reducer(hovered, setHoverSelector({ selector: '.icon' }));
  assert.equal(invalid.hoverSelector, null);
});

test('clearSelection resets the target and the hover facet with the rest of the spine', () => {
  const state = run(
    setSelected('A'),
    setTarget({ key: 'A', selector: '.icon' }),
    setHoverSelector({ key: 'A', selector: '.label' }),
    clearSelection(),
  );
  assert.equal(state.selectedKey, null);
  assert.equal(state.target, null);
  assert.equal(state.hoverKey, null);
  assert.equal(state.hoverSelector, null);
  assert.deepEqual(state.selectedKeys, []);
});

test('the picker starts disarmed and setPickerArmed flips it, coerced to a boolean', () => {
  assert.equal(initial.pickerArmed, false);
  assert.equal(run(setPickerArmed(true)).pickerArmed, true);
  assert.equal(run(setPickerArmed(true), setPickerArmed(false)).pickerArmed, false);
  assert.equal(run(setPickerArmed(1)).pickerArmed, true);
  assert.equal(run(setPickerArmed(true), setPickerArmed()).pickerArmed, false);
});

test('clearSelection and the selection writes leave the armed picker alone (disarming is the gesture\'s)', () => {
  const state = run(
    setPickerArmed(true),
    setSelected('A'),
    setTarget({ key: 'A', selector: '.icon' }),
    toggleSelected('B'),
    clearSelection(),
  );
  assert.equal(state.pickerArmed, true);
  assert.equal(state.selectedKey, null);
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
const { selectorAddress, splitAddress } = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { reachOver, seatAddress } = await import('../../js/edit_mode/components/page-elements/reach.js');
const { MARKUP_VIEW_ID } = await import('../../js/edit_mode/components/page-elements/markup-view.js');

// ---------------------------------------------------------------------------
// The label resolver (label-for-key.js): one wording for every surface
// ---------------------------------------------------------------------------

test('labelFor reads the projection index (the delivered name) and falls back to the vocabulary', () => {
  // The index label IS the server-minted `_element.name`: a container
  // whose label says "Welcome" is read as such, whatever its kind or
  // layout; only keys the index lacks fall back to the key's vocabulary.
  const index = new Map([
    ['block:u1:hero', { label: 'Masthead' }],
    ['container:c', { label: 'Welcome', elementType: 'Section', kind: 'container' }],
    ['container:fresh', { label: 'Container', elementType: 'Container', kind: 'container' }],
  ]);
  assert.equal(labelFor(index, 'block:u1:hero'), 'Masthead');
  assert.equal(labelFor(index, 'container:c'), 'Welcome');
  assert.equal(labelFor(index, 'container:fresh'), 'Container');
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

test('labelFor labels a placed part address from the index, and any other address by its owner', () => {
  const index = new Map([
    ['block:u1', { label: 'Image', tier: 'element' }],
    [selectorAddress('block:u1', '.image-block-image'), { label: 'Raster', tier: 'part' }],
    ['container:c', { label: 'Welcome', tier: 'element' }],
  ]);
  assert.equal(labelFor(index, selectorAddress('block:u1', '.image-block-image')), 'Raster');
  // An unplaced address (a tag tier, a chain, an ancestor rule) is its
  // owner's other name, never the key's kind.
  assert.equal(labelFor(index, selectorAddress('container:c', 'a')), 'Welcome');
  assert.equal(labelFor(index, selectorAddress('container:c', '> pb-text .quote')), 'Welcome');
  assert.equal(labelFor(null, selectorAddress('block:u2:hero_banner', 'p')), 'Hero banner');
});

test('splitAddress is selectorAddress\'s inverse; an element key splits to a null selector', () => {
  assert.deepEqual(splitAddress(selectorAddress('block:u1:field_image', '.field__item')), {
    key: 'block:u1:field_image',
    selector: '.field__item',
  });
  assert.deepEqual(splitAddress('block:u1:field_image'), { key: 'block:u1:field_image', selector: null });
});

test('seatAddress seats a placed, drawn part on its address and an unplaced one on its owner', () => {
  const index = new Map([
    ['block:u1', { label: 'Image', tier: 'element', source: 'element' }],
    [selectorAddress('block:u1', '.image-block-image'), { label: 'Raster', tier: 'part', source: MARKUP_VIEW_ID }],
  ]);
  const on = reachOver(index, { [MARKUP_VIEW_ID]: true });
  assert.equal(seatAddress(index, on, 'block:u1', '.image-block-image'), selectorAddress('block:u1', '.image-block-image'));
  assert.equal(seatAddress(index, on, 'block:u1', '.s-zz'), 'block:u1');
  assert.equal(seatAddress(index, on, 'block:u1'), 'block:u1');
  assert.equal(seatAddress(null, reachOver(null, { [MARKUP_VIEW_ID]: true }), 'block:u1', '.image-block-image'), 'block:u1');
});

test('seatAddress seats a placed shell part on its owner while the Markup view is off, on its row with it on', () => {
  // The seat never validates the target (the facade's target() can stand
  // it whatever the view); it says which drawn row stands for it now.
  const index = new Map([
    ['block:u1', { label: 'Image', tier: 'element', source: 'element' }],
    [selectorAddress('block:u1', '.image-block-image'), { label: 'Raster', tier: 'part', source: MARKUP_VIEW_ID }],
    [selectorAddress('block:u1', '.s-l1'), { label: 'List', tier: 'part', source: 'instance' }],
  ]);
  const off = reachOver(index, {});
  const on = reachOver(index, { [MARKUP_VIEW_ID]: true });
  assert.equal(seatAddress(index, off, 'block:u1', '.image-block-image'), 'block:u1');
  assert.equal(seatAddress(index, on, 'block:u1', '.image-block-image'), selectorAddress('block:u1', '.image-block-image'));
  // An instance carrier is content: drawn, and seated, in every view.
  assert.equal(seatAddress(index, off, 'block:u1', '.s-l1'), selectorAddress('block:u1', '.s-l1'));
  assert.equal(seatAddress(index, on, 'block:u1', '.s-l1'), selectorAddress('block:u1', '.s-l1'));
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
 * both tiers; `source` is what reach reads (the shell is the Markup
 * view's row, the carriers are content).
 *
 * Two reach predicates over it: ON draws every row (the Markup view on),
 * OFF draws the model and instance rows only (the shell undrawn). The
 * existing cases walk under ON, where the walks read exactly as before
 * reach; the reach cases walk under OFF.
 */
const IMAGE = 'block:img';
const RASTER = selectorAddress(IMAGE, '.image-block-image');
const FIELD = `${IMAGE}:field_image`;
const LINK = `${IMAGE}:field_link`;
const UNLINKED = 'block:img2';
const TEXT = 'block:txt';
const UL = selectorAddress(TEXT, '.s-l1');
const LI = selectorAddress(TEXT, '.s-i1');
const ancestryIndex = () => new Map([
  ['entity:r', { label: 'Page', tier: 'element', source: 'element', parent: null, rowParent: null, rows: ['container:s'] }],
  ['container:s', { label: 'Container', tier: 'element', source: 'element', parent: 'entity:r', rowParent: 'entity:r', rows: [IMAGE, UNLINKED, TEXT] }],
  [IMAGE, { label: 'Image', tier: 'element', source: 'element', parent: 'container:s', rowParent: 'container:s', rows: [RASTER, LINK] }],
  [RASTER, { label: 'Raster', tier: 'part', source: MARKUP_VIEW_ID, owner: IMAGE, parent: IMAGE, rowParent: IMAGE, rows: [FIELD] }],
  [FIELD, { label: 'Field image', tier: 'element', source: 'element', parent: IMAGE, rowParent: RASTER, rows: [] }],
  [LINK, { label: 'Link', tier: 'element', source: 'element', parent: IMAGE, rowParent: IMAGE, rows: [] }],
  [UNLINKED, { label: 'Image', tier: 'element', source: 'element', parent: 'container:s', rowParent: 'container:s', rows: [] }],
  [TEXT, { label: 'Formatted text', tier: 'element', source: 'element', parent: 'container:s', rowParent: 'container:s', rows: [UL] }],
  [UL, { label: 'Bulleted list', tier: 'part', source: 'instance', owner: TEXT, parent: TEXT, rowParent: TEXT, rows: [LI] }],
  [LI, { label: 'List item', tier: 'part', source: 'instance', owner: TEXT, parent: UL, rowParent: UL, rows: [] }],
]);
const ON = reachOver(ancestryIndex(), { [MARKUP_VIEW_ID]: true });
const OFF = reachOver(ancestryIndex(), {});

const labels = (trail) => trail.map((crumb) => crumb.label);

const target = (key, selector) => ({ key, selector });

test('a targeted shell part trails through its owner: Container › Image › Raster', () => {
  const trail = ancestryOf(ancestryIndex(), ON, IMAGE, target(IMAGE, '.image-block-image'));
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Image', 'Raster']);
  // Every crumb carries what its click commits: the pair for the part, the
  // bare key for the elements above it.
  assert.deepEqual(trail.at(-1), { address: RASTER, key: IMAGE, selector: '.image-block-image', label: 'Raster' });
  assert.deepEqual(trail.at(-2), { address: IMAGE, key: IMAGE, selector: null, label: 'Image' });
  assert.deepEqual(trail[0], { address: 'entity:r', key: 'entity:r', selector: null, label: 'Page' });
});

test('a nested carrier trails part to selector: Formatted text › Bulleted list › List item', () => {
  const trail = ancestryOf(ancestryIndex(), ON, TEXT, target(TEXT, '.s-i1'));
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Formatted text', 'Bulleted list', 'List item']);
  assert.deepEqual(trail.at(-1), { address: LI, key: TEXT, selector: '.s-i1', label: 'List item' });
  assert.deepEqual(trail.at(-2), { address: UL, key: TEXT, selector: '.s-l1', label: 'Bulleted list' });
  assert.deepEqual(trail.at(-3), { address: TEXT, key: TEXT, selector: null, label: 'Formatted text' });
});

test('an element claimed into a shell names the shell above it (the panel\'s nesting)', () => {
  const trail = ancestryOf(ancestryIndex(), ON, FIELD);
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Image', 'Raster', 'Field image']);
  assert.equal(trail.at(-2).selector, '.image-block-image');
});

test('a target the index does not place ends the trail as a target crumb after its element', () => {
  // The hoisted case (the unlinked image's picture IS the block root): the
  // target can still be seated through the facade, but the index has no
  // such address, so the breadcrumb ends at the element it rides on plus
  // one crumb naming the target, worded by the label seam (the raw
  // selector when none is registered, as here) and committing the target
  // itself.
  const trail = ancestryOf(ancestryIndex(), ON, 'container:s', target('container:s', '.image-block-image'));
  assert.deepEqual(labels(trail), ['Page', 'Container', '.image-block-image']);
  assert.deepEqual(trail.at(-2), { address: 'container:s', key: 'container:s', selector: null, label: 'Container' });
  assert.deepEqual(trail.at(-1), {
    address: selectorAddress('container:s', '.image-block-image'),
    key: 'container:s',
    selector: '.image-block-image',
    label: '.image-block-image',
  });
  // A tag tier and a chain of the selected element: the same one crumb,
  // through an injected label seam.
  const words = (key, selector) => `every ${selector} of ${key}`;
  assert.deepEqual(labels(ancestryOf(ancestryIndex(), ON, TEXT, target(TEXT, 'a'), words)), ['Page', 'Container', 'Formatted text', `every a of ${TEXT}`]);
  assert.deepEqual(labels(ancestryOf(ancestryIndex(), ON, TEXT, target(TEXT, '> pb-text .quote'))), ['Page', 'Container', 'Formatted text', '> pb-text .quote']);
});

test('an ancestor-rule target sits on the selected element and ends the trail with the rule\'s crumb', () => {
  // The selection is the image; the target is a rule on its container.
  // The trail is the image's, and the target crumb carries the CONTAINER
  // key with the rule's selector: what the crumb addresses is the rule.
  const trail = ancestryOf(ancestryIndex(), ON, IMAGE, target('container:s', '> pb-image'));
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Image', '> pb-image']);
  assert.deepEqual(trail.at(-1), {
    address: selectorAddress('container:s', '> pb-image'),
    key: 'container:s',
    selector: '> pb-image',
    label: '> pb-image',
  });
  // A placed part of an ANCESTOR is not the selection's row either: it
  // sits on the selected element like any ancestor target.
  assert.deepEqual(labels(ancestryOf(ancestryIndex(), ON, FIELD, target(IMAGE, '.image-block-image'))), ['Page', 'Container', 'Image', 'Raster', 'Field image', '.image-block-image']);
});

test('an element the index cannot place is its own one-crumb trail', () => {
  assert.deepEqual(ancestryOf(ancestryIndex(), ON, 'block:u9:hero_banner'), [
    { address: 'block:u9:hero_banner', key: 'block:u9:hero_banner', selector: null, label: 'Hero banner' },
  ]);
  assert.deepEqual(labels(ancestryOf(null, reachOver(null, {}), 'container:x')), ['Container']);
});

// ---------------------------------------------------------------------------
// The traversal grammar (traversal.js): arrows over both tiers
// ---------------------------------------------------------------------------

test('Right from a linked Image drills into its Raster shell, drill seated at the owner\'s parent', () => {
  assert.deepEqual(firstRowOf(ancestryIndex(), ON, IMAGE), {
    address: RASTER, key: IMAGE, selector: '.image-block-image', drill: 'container:s',
  });
  // And on into the field the shell claims: an element step, drill = its
  // element parent (the owner), exactly the pointer's drill-in shape.
  assert.deepEqual(firstRowOf(ancestryIndex(), ON, IMAGE, '.image-block-image'), {
    address: FIELD, key: FIELD, selector: null, drill: IMAGE,
  });
});

test('Right from an unlinked Image finds no row: the hoisted picture is not a stop', () => {
  assert.equal(firstRowOf(ancestryIndex(), ON, UNLINKED), null);
});

test('Left from a list item climbs to its list (part to part)', () => {
  assert.deepEqual(parentOf(ancestryIndex(), ON, TEXT, '.s-i1'), {
    address: UL, key: TEXT, selector: '.s-l1', drill: 'container:s',
  });
});

test('Left from the list climbs to the owner block (part to element)', () => {
  assert.deepEqual(parentOf(ancestryIndex(), ON, TEXT, '.s-l1'), {
    address: TEXT, key: TEXT, selector: null, drill: 'container:s',
  });
  // Left from a field claimed into a shell lands on the shell, the same
  // stop the breadcrumb draws above it.
  assert.deepEqual(parentOf(ancestryIndex(), ON, FIELD), {
    address: RASTER, key: IMAGE, selector: '.image-block-image', drill: 'container:s',
  });
  // The root has nowhere to climb.
  assert.equal(parentOf(ancestryIndex(), ON, 'entity:r'), null);
});

test('Up/Down walk the row parent\'s rows across both tiers and stop at the edges', () => {
  // A shell part and a plain field are siblings in the owner's rows.
  assert.deepEqual(siblingOf(ancestryIndex(), ON, IMAGE, '.image-block-image', 1), {
    address: LINK, key: LINK, selector: null, drill: IMAGE,
  });
  assert.deepEqual(siblingOf(ancestryIndex(), ON, LINK, null, -1), {
    address: RASTER, key: IMAGE, selector: '.image-block-image', drill: 'container:s',
  });
  assert.equal(siblingOf(ancestryIndex(), ON, IMAGE, '.image-block-image', -1), null);
  // Element siblings still walk the same list they always did.
  assert.equal(siblingOf(ancestryIndex(), ON, IMAGE, null, 1).address, UNLINKED);
  assert.equal(siblingOf(ancestryIndex(), ON, TEXT, null, 1), null);
  // The root is its own only sibling.
  assert.equal(siblingOf(ancestryIndex(), ON, 'entity:r', null, 1), null);
  assert.equal(siblingOf(ancestryIndex(), ON, 'entity:r', null, -1), null);
});

test('an unplaced part seats on its owner; an unplaced element has no seat', () => {
  // The hoisted facet the facade can still commit: arrows move as if the
  // owner block were selected.
  assert.equal(seatOf(ancestryIndex(), ON, UNLINKED, '.image-block-image').address, UNLINKED);
  assert.equal(siblingOf(ancestryIndex(), ON, UNLINKED, '.image-block-image', 1).address, TEXT);
  // Outside the universe: nothing to move, the caller declines the key.
  assert.equal(seatOf(ancestryIndex(), ON, 'block:u9'), null);
  assert.equal(seatOf(null, reachOver(null, {}), 'block:u9'), null);
  assert.equal(firstRowOf(ancestryIndex(), ON, 'block:u9'), null);
});

// ---------------------------------------------------------------------------
// Reach: the walks and the trail stop only on rows the panel draws
// ---------------------------------------------------------------------------

test('Right from a linked Image lands on the claimed field with the Markup view off, on Raster with it on', () => {
  // The shell is the Markup view's row: undrawn, its rows hoist into the
  // owner's place, so the drill-in skips straight to the field, an
  // element step whose drill is its element parent.
  assert.deepEqual(firstRowOf(ancestryIndex(), OFF, IMAGE), {
    address: FIELD, key: FIELD, selector: null, drill: IMAGE,
  });
  assert.deepEqual(firstRowOf(ancestryIndex(), ON, IMAGE), {
    address: RASTER, key: IMAGE, selector: '.image-block-image', drill: 'container:s',
  });
  // Instance rows are content: Right from the text block reaches its
  // list in every view.
  assert.equal(firstRowOf(ancestryIndex(), OFF, TEXT).address, UL);
  assert.equal(firstRowOf(ancestryIndex(), OFF, TEXT, '.s-l1').address, LI);
});

test('Left from the claimed field climbs past the undrawn shell to the block', () => {
  assert.deepEqual(parentOf(ancestryIndex(), OFF, FIELD), {
    address: IMAGE, key: IMAGE, selector: null, drill: 'container:s',
  });
  // With the shell drawn the same key stops on it (the existing stop).
  assert.equal(parentOf(ancestryIndex(), ON, FIELD).address, RASTER);
  // Part to part inside instance rows climbs in every view.
  assert.equal(parentOf(ancestryIndex(), OFF, TEXT, '.s-i1').address, UL);
});

test('Up/Down with the Markup view off walk the hoisted rows: the claimed field sits beside the link field', () => {
  assert.deepEqual(siblingOf(ancestryIndex(), OFF, FIELD, null, 1), {
    address: LINK, key: LINK, selector: null, drill: IMAGE,
  });
  assert.deepEqual(siblingOf(ancestryIndex(), OFF, LINK, null, -1), {
    address: FIELD, key: FIELD, selector: null, drill: IMAGE,
  });
  assert.equal(siblingOf(ancestryIndex(), OFF, FIELD, null, -1), null);
  // With the shell drawn the field is the shell's only row, and Up from
  // the link lands on the shell (the existing order).
  assert.equal(siblingOf(ancestryIndex(), ON, FIELD, null, 1), null);
  assert.equal(siblingOf(ancestryIndex(), ON, LINK, null, -1).address, RASTER);
});

test('a shell target seats on the owner with the Markup view off and on its row with it on', () => {
  assert.equal(seatOf(ancestryIndex(), OFF, IMAGE, '.image-block-image').address, IMAGE);
  assert.equal(seatOf(ancestryIndex(), ON, IMAGE, '.image-block-image').address, RASTER);
  // Seated on the owner, the arrows move as if the owner were selected:
  // Down from the undrawn Raster target is Down from the Image.
  assert.equal(siblingOf(ancestryIndex(), OFF, IMAGE, '.image-block-image', 1).address, UNLINKED);
  assert.equal(firstRowOf(ancestryIndex(), OFF, IMAGE, '.image-block-image').address, FIELD);
});

test('the breadcrumb omits undrawn shells and ends an unreachable placed target with a target crumb', () => {
  // The claimed field's trail names its owner directly with the shell
  // undrawn, the shell between them with it drawn.
  assert.deepEqual(labels(ancestryOf(ancestryIndex(), OFF, FIELD)), ['Page', 'Container', 'Image', 'Field image']);
  assert.deepEqual(labels(ancestryOf(ancestryIndex(), ON, FIELD)), ['Page', 'Container', 'Image', 'Raster', 'Field image']);
  // A standing shell target the view flip made unreachable degrades to a
  // doorway seat: the owner's trail plus one target crumb committing the
  // target itself, exactly as a tag tier does.
  const trail = ancestryOf(ancestryIndex(), OFF, IMAGE, target(IMAGE, '.image-block-image'));
  assert.deepEqual(labels(trail), ['Page', 'Container', 'Image', '.image-block-image']);
  assert.deepEqual(trail.at(-2), { address: IMAGE, key: IMAGE, selector: null, label: 'Image' });
  assert.deepEqual(trail.at(-1), {
    address: RASTER,
    key: IMAGE,
    selector: '.image-block-image',
    label: '.image-block-image',
  });
  // An instance carrier trails through its rows in every view.
  assert.deepEqual(labels(ancestryOf(ancestryIndex(), OFF, TEXT, target(TEXT, '.s-i1'))), ['Page', 'Container', 'Formatted text', 'Bulleted list', 'List item']);
});

// ---------------------------------------------------------------------------
// The type resolver (type-for-key.js): what the element IS, never what it
// says — the label resolver's sibling read of the same projection index.
// Both words are minted server-side (`_element.name` / `_element.type`)
// and ride the index entry as `label` / `elementType`; the resolvers copy,
// they never derive.
// ---------------------------------------------------------------------------

test('a block speaks its delivered type word, whatever content its label carries', () => {
  const index = new Map([
    ['block:u1', { label: 'Hello there', elementType: 'Text', kind: 'block', bundle: 'text', parent: 'entity:r' }],
    ['block:u2', { label: 'Gallery', elementType: 'Image gallery', kind: 'block', bundle: 'image_gallery', parent: 'entity:r' }],
    ['block:u3', { label: 'Footer credits', elementType: 'Powered by Drupal', kind: 'block', bundle: null, parent: 'entity:r' }],
  ]);
  // The seam: the same projection node answers two named reads — the label
  // is the content, the type is the block type's word.
  assert.equal(typeFor(index, 'block:u1'), 'Text');
  assert.notEqual(typeFor(index, 'block:u1'), index.get('block:u1').label);
  assert.equal(typeFor(index, 'block:u2'), 'Image gallery');
  // A plugin block: its type is the plugin's admin label, delivered like
  // any other; the bundle is not consulted (nothing is derived here).
  assert.equal(typeFor(index, 'block:u3'), 'Powered by Drupal');
});

test('containers speak the depth word the annotator minted, never the nesting the index shows', () => {
  const index = new Map([
    ['entity:r', { label: 'Page', elementType: 'Page', kind: 'entity', bundle: 'page', parent: null }],
    ['container:top', { label: 'Renamed hero', elementType: 'Section', kind: 'container', bundle: null, parent: 'entity:r' }],
    ['container:inner', { label: 'Container', elementType: 'Container', kind: 'container', bundle: null, parent: 'container:top' }],
  ]);
  assert.equal(typeFor(index, 'container:top'), 'Section');
  assert.equal(typeFor(index, 'container:inner'), 'Container');
  // The word is read, not computed from `parent`: a fixture that says
  // otherwise is believed, so no surface can drift from the server's rule.
  assert.equal(typeFor(new Map([['container:x', { elementType: 'Band', kind: 'container', parent: 'container:y' }]]), 'container:x'), 'Band');
});

test('entities and fields speak their delivered word too', () => {
  const index = new Map([
    ['entity:r', { label: 'Hello there', elementType: 'Landing page', kind: 'entity', bundle: 'landing_page', parent: null }],
    ['field:r:field_body', { label: 'Body', elementType: 'Body', kind: 'field', bundle: null, parent: 'entity:r' }],
    ['block:u1:field_text_tag', { label: 'Text tag', elementType: 'Text tag', kind: 'field', bundle: null, parent: 'block:u1' }],
  ]);
  assert.equal(typeFor(index, 'entity:r'), 'Landing page');
  assert.equal(typeFor(index, 'field:r:field_body'), 'Body');
  assert.equal(typeFor(index, 'block:u1:field_text_tag'), 'Text tag');
});

test('keys without a projection node, or entries without a type word, fall back to the key vocabulary, never null', () => {
  assert.equal(typeFor(null, 'block:u9:hero_banner'), 'Hero banner');
  assert.equal(typeFor(new Map(), 'container:abc'), 'Container');
  assert.equal(typeFor(new Map(), 'mystery'), 'Element');
  // A part entry carries a label only (its word is the matched-form or
  // declared label): asked for a type, the address's own vocabulary answers.
  const parts = new Map([[selectorAddress('block:u1', '.image-block-image'), { label: 'Raster', kind: 'part', tier: 'part' }]]);
  assert.equal(typeFor(parts, selectorAddress('block:u1', '.image-block-image')), 'Element');
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

test('facade select is bare, target seats the styling target and the getters read both', () => {
  Selection.clear();
  Selection.select('A');
  Selection.target('A', '.icon');
  assert.equal(Selection.getSelected(), 'A');
  assert.deepEqual(Selection.getTarget(), { key: 'A', selector: '.icon' });
  // A selection write clears the target; re-selecting the selected key
  // is the drop-the-target gesture.
  Selection.select('B');
  assert.equal(Selection.getSelected(), 'B');
  assert.equal(Selection.getTarget(), null);
  Selection.target('B', 'p');
  Selection.select('B');
  assert.equal(Selection.getSelected(), 'B');
  assert.equal(Selection.getTarget(), null);
});

test('facade target refuses a key that is neither the selection nor an ancestor, and needs a single selection', () => {
  Selection.clear();
  // No projection index boots in this suite, so no key has ancestry:
  // only the selected key itself may carry a target.
  assert.throws(() => Selection.target('A', '.icon'), TypeError);
  Selection.select('A');
  assert.throws(() => Selection.target('P', '> pb-text'), TypeError);
  assert.equal(Selection.getTarget(), null);
  Selection.selectSet(['A', 'B'], 'A');
  assert.throws(() => Selection.target('A', '.icon'), TypeError);
  assert.equal(Selection.getTarget(), null);
  Selection.clear();
});

test('facade hover and hoverSelector drive the two hover halves independently', () => {
  Selection.clear();
  Selection.hover('A');
  assert.equal(Selection.getHovered(), 'A');
  assert.equal(Selection.getHoveredSelector(), null);
  Selection.hoverSelector('B', '.icon');
  assert.equal(Selection.getHovered(), 'A');
  assert.deepEqual(Selection.getHoveredSelector(), { key: 'B', selector: '.icon' });
  Selection.hoverSelector(null);
  assert.equal(Selection.getHovered(), 'A');
  assert.equal(Selection.getHoveredSelector(), null);
  Selection.hover('C', '.label');
  assert.equal(Selection.getHovered(), 'C');
  assert.deepEqual(Selection.getHoveredSelector(), { key: 'C', selector: '.label' });
});

test('onChange fires on key changes only, never on target changes under the same key', () => {
  Selection.clear();
  const calls = record(Selection.onChange, () => {
    Selection.select('A');
    Selection.target('A', '.icon');
    // Target-only changes under the same key are silent here.
    Selection.target('A', '.label');
    Selection.select('A');
    // A key change fires, whatever the target does.
    Selection.select('B');
    Selection.target('B', '.icon');
  });
  assert.deepEqual(calls, [
    { current: 'A', previous: null },
    { current: 'B', previous: 'A' },
  ]);
});

test('onTargetChange fires on a selection or target change and is value-compared on the target', () => {
  Selection.clear();
  const calls = record(Selection.onTargetChange, () => {
    Selection.select('A');
    Selection.target('A', '.icon');
    Selection.target('A', '.label');
    // The same target re-asserted does not fire.
    Selection.target('A', '.label');
    Selection.select('B');
  });
  assert.deepEqual(calls, [
    { current: { selected: 'A', target: null }, previous: { selected: null, target: null } },
    { current: { selected: 'A', target: { key: 'A', selector: '.icon' } }, previous: { selected: 'A', target: null } },
    { current: { selected: 'A', target: { key: 'A', selector: '.label' } }, previous: { selected: 'A', target: { key: 'A', selector: '.icon' } } },
    { current: { selected: 'B', target: null }, previous: { selected: 'A', target: { key: 'A', selector: '.label' } } },
  ]);
});

test('onHoverSelectorChange fires on selector-hover value changes, from either writer', () => {
  Selection.clear();
  const calls = record(Selection.onHoverSelectorChange, () => {
    Selection.hoverSelector('A', '.icon');
    // The same value re-asserted does not fire.
    Selection.hoverSelector('A', '.icon');
    // A bare element hover clears the facet (an explicit-selector-absent write).
    Selection.hover('B');
    Selection.hover('B', '.label');
  });
  assert.deepEqual(calls, [
    { current: { key: 'A', selector: '.icon' }, previous: null },
    { current: null, previous: { key: 'A', selector: '.icon' } },
    { current: { key: 'B', selector: '.label' }, previous: null },
  ]);
});

test('element-only set gestures clear the target through the facade', () => {
  Selection.clear();
  Selection.select('A');
  Selection.target('A', '.icon');
  Selection.toggle('B');
  assert.equal(Selection.getTarget(), null);
  Selection.select('A');
  Selection.target('A', '.icon');
  Selection.selectSet(['A', 'B'], 'A');
  assert.equal(Selection.getTarget(), null);
  Selection.clear();
});

test('the facade seats any selector the grammar reads, canonicalized', () => {
  Selection.clear();
  // A part, the children, a type: every relative selector is a target,
  // and a lenient spelling lands as the one canonical string style-map
  // keys carry.
  Selection.select('container:c');
  Selection.target('container:c', '>*');
  assert.deepEqual(Selection.getTarget(), { key: 'container:c', selector: '> *' });
  Selection.target('container:c', ' > pb-text ');
  assert.deepEqual(Selection.getTarget(), { key: 'container:c', selector: '> pb-text' });
  Selection.hover('container:c', 'p');
  assert.deepEqual(Selection.getHoveredSelector(), { key: 'container:c', selector: 'p' });
  Selection.hoverSelector('container:c', '.cls>*');
  assert.deepEqual(Selection.getHoveredSelector(), { key: 'container:c', selector: '.cls > *' });
  Selection.clear();
});

test('the facade refuses a selector outside the grammar instead of seating it', () => {
  Selection.clear();
  Selection.select('A');
  Selection.target('A', '.icon');
  for (const bad of ['div > .loose', '.two.classes', 'a:hover', '*', '::p', '', null]) {
    assert.throws(() => Selection.target('A', bad), TypeError);
    if (bad !== null) {
      assert.throws(() => Selection.hover('A', bad), TypeError);
      assert.throws(() => Selection.hoverSelector('A', bad), TypeError);
    }
  }
  // A refused write leaves the committed target untouched.
  assert.deepEqual(Selection.getTarget(), { key: 'A', selector: '.icon' });
  Selection.clear();
});
