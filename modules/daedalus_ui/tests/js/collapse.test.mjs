/**
 * Self-asserting tests for the collapse model (js/outline/collapse.js),
 * the one posture rule every outline the UI folds adapts to: the page
 * elements tree, code mode's page view, the Advanced inspector sections.
 *
 * Covered: the rule `explicit ?? default` both ways; toggle, expand and
 * collapse on one row; collapseAll and expandAll touching the foldable
 * rows only and returning the same object when nothing changes; the two
 * all-predicates (the controls' disabled states, vacuously true for an
 * outline without a foldable row); reveal opening ancestors only, the
 * same object when the row is already visible and no change for an id
 * the outline lacks; foldableIds and ancestorsOf. The cases code mode's
 * retired per-view helpers carried (its own toggle, path opening,
 * all-gestures and all-predicate over a Set of keys) and the Advanced
 * sections' retired per-anchor open model carried (a mark overriding the
 * auto state both ways, the open, add, delete sequence, the latest word
 * winning) are ported here against the model's names. That open model's
 * anchor reset is a component key in the adapter, not a model function,
 * so its stale-anchor cases have no port.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const {
  allCollapsed,
  allExpanded,
  ancestorsOf,
  collapse,
  collapseAll,
  expand,
  expandAll,
  foldableIds,
  isCollapsed,
  reveal,
  toggle,
} = await import('../../js/outline/collapse.js');

const ROOT = 'entity:page';
const S1 = 'container:s1';
const S2 = 'container:s2';
const NESTED = 'container:nested';
const B1 = 'block:b1';
const B2 = 'block:b2';
const B3 = 'block:b3';

const node = (id, folded, foldable, children = []) => ({
  id,
  folded,
  foldable,
  children,
});

/**
 * Code mode's shape: every row defaults open, a row is foldable when it
 * has a body (styled, has children, or is the selection). S1 holds two
 * bare leaves (no body), B3 is a styled leaf.
 */
const codeOutline = (selected = null) => [
  node(ROOT, false, true, [
    node(S1, false, true, [
      node(B1, false, selected === B1),
      node(B2, false, selected === B2),
    ]),
    node(S2, false, true, [node(NESTED, false, true, [node(B3, false, true)])]),
  ]),
];

/**
 * The page elements tree's shape: the root never folds and has no
 * chevron, a unit row and a leafless row default collapsed, a row with
 * children is foldable.
 */
const treeOutline = () => [
  node(ROOT, false, false, [
    node(S1, false, true, [node(B1, true, false), node(B2, true, false)]),
    node(S2, true, true, [node(NESTED, false, true, [node(B3, true, false)])]),
  ]),
];

/**
 * The Advanced sections' shape: a flat list, each section defaulting to
 * its auto state (open when it has values), every one foldable.
 */
const sectionsOutline = (auto) =>
  Object.entries(auto).map(([id, open]) => node(id, !open, true));

const find = (outline, id) => {
  for (const candidate of outline) {
    if (candidate.id === id) {
      return candidate;
    }
    const found = find(candidate.children ?? [], id);
    if (found) {
      return found;
    }
  }
  return null;
};

// --- isCollapsed: explicit over default, both ways ---------------------------

test('isCollapsed reads the default posture with no explicit word', () => {
  const tree = treeOutline();
  assert.equal(isCollapsed({}, find(tree, S1)), false);
  assert.equal(isCollapsed({}, find(tree, S2)), true);
  assert.equal(isCollapsed(null, find(tree, S2)), true);
  assert.equal(isCollapsed(undefined, find(tree, S1)), false);
  // A word on another row is not this row's.
  assert.equal(isCollapsed({ [S1]: true }, find(tree, S2)), true);
  assert.equal(isCollapsed({ [S2]: false }, find(tree, S1)), false);
});

test('isCollapsed: an explicit posture overrides the default in both directions', () => {
  const tree = treeOutline();
  // Hand-opened stays open although the default says collapsed.
  assert.equal(isCollapsed({ [S2]: false }, find(tree, S2)), false);
  // Hand-closed stays closed although the default says open.
  assert.equal(isCollapsed({ [S1]: true }, find(tree, S1)), true);
});

test('the sections\' open, add, delete sequence: the mark survives the default flipping', () => {
  // Empty section, the user opens it by hand.
  let folds = toggle({}, find(sectionsOutline({ spacing: false }), 'spacing'));
  assert.deepEqual(folds, { spacing: false });
  // A value lands: the default says open, still open.
  assert.equal(isCollapsed(folds, find(sectionsOutline({ spacing: true }), 'spacing')), false);
  // The value is deleted: the default says closed, the user's word stands.
  assert.equal(isCollapsed(folds, find(sectionsOutline({ spacing: false }), 'spacing')), false);
  // The mirror: a section closed by hand stays closed when a value lands.
  folds = toggle({}, find(sectionsOutline({ spacing: true }), 'spacing'));
  assert.deepEqual(folds, { spacing: true });
  assert.equal(isCollapsed(folds, find(sectionsOutline({ spacing: true }), 'spacing')), true);
});

test('a truthy non-boolean word reads as a posture but the writers only ever write booleans', () => {
  const tree = treeOutline();
  assert.equal(isCollapsed({ [S1]: 1 }, find(tree, S1)), true);
  assert.equal(isCollapsed({ [S2]: 0 }, find(tree, S2)), false);
  assert.deepEqual(toggle({ [S1]: 1 }, find(tree, S1)), { [S1]: false });
});

// --- toggle, expand, collapse: one row --------------------------------------

test('toggle flips one row and leaves the rest; the default is the starting point', () => {
  const tree = codeOutline();
  const none = {};
  assert.equal(isCollapsed(none, find(tree, S1)), false);
  const one = toggle(none, find(tree, S1));
  assert.equal(isCollapsed(one, find(tree, S1)), true);
  // The state is immutable: the previous object is untouched.
  assert.deepEqual(none, {});
  const two = toggle(one, find(tree, B1));
  assert.deepEqual(two, { [S1]: true, [B1]: true });
  const back = toggle(two, find(tree, S1));
  assert.deepEqual(back, { [S1]: false, [B1]: true });
  // A null state toggles from the default too.
  assert.deepEqual(toggle(null, find(treeOutline(), S2)), { [S2]: false });
});

test('the latest word on a row wins; words accumulate per row', () => {
  const sections = sectionsOutline({ spacing: false, typography: true });
  let folds = toggle({}, find(sections, 'spacing'));
  folds = toggle(folds, find(sections, 'typography'));
  folds = toggle(folds, find(sections, 'spacing'));
  assert.deepEqual(folds, { spacing: true, typography: true });
});

test('expand and collapse write one row and return the same object when it already holds', () => {
  const tree = treeOutline();
  const none = {};
  // Expanding a row already open by default changes nothing.
  assert.equal(expand(none, find(tree, S1)), none);
  assert.equal(expand(null, find(tree, S1)), null);
  // Expanding a row collapsed by default writes the word.
  const opened = expand(none, find(tree, S2));
  assert.deepEqual(opened, { [S2]: false });
  assert.equal(expand(opened, find(tree, S2)), opened);
  // Collapse is the mirror.
  assert.equal(collapse(none, find(tree, S2)), none);
  const closed = collapse(opened, find(tree, S2));
  assert.deepEqual(closed, { [S2]: true });
  assert.equal(collapse(closed, find(tree, S2)), closed);
  assert.deepEqual(opened, { [S2]: false }, 'the previous object is untouched');
  // A word that equals the default is a word: collapse on a row whose
  // explicit posture is open writes it closed.
  assert.deepEqual(collapse({ [S1]: false }, find(tree, S1)), { [S1]: true });
});

// --- foldableIds and ancestorsOf: what the outline says ----------------------

test('foldableIds lists the foldable rows in render order, bare leaves left out', () => {
  assert.deepEqual(foldableIds(codeOutline()), [ROOT, S1, S2, NESTED, B3]);
  // The selected bare leaf joins the foldable rows (code mode's rule,
  // applied by its adapter through the flag).
  assert.deepEqual(foldableIds(codeOutline(B2)), [ROOT, S1, B2, S2, NESTED, B3]);
  // The tree's root and its leafless rows are not foldable.
  assert.deepEqual(foldableIds(treeOutline()), [S1, S2, NESTED]);
  assert.deepEqual(foldableIds([]), []);
  assert.deepEqual(foldableIds(undefined), []);
  // A leaf without a children list is fine.
  assert.deepEqual(foldableIds([{ id: 'a', foldable: true, folded: false }]), ['a']);
});

test('ancestorsOf walks from the root down to the parent, [] for a root or an unknown id', () => {
  const tree = codeOutline();
  assert.deepEqual(ancestorsOf(tree, B3), [ROOT, S2, NESTED]);
  assert.deepEqual(ancestorsOf(tree, S1), [ROOT]);
  assert.deepEqual(ancestorsOf(tree, ROOT), []);
  assert.deepEqual(ancestorsOf(tree, 'field:abc:body'), []);
  assert.deepEqual(ancestorsOf(tree, null), []);
  assert.deepEqual(ancestorsOf(tree, undefined), []);
  assert.deepEqual(ancestorsOf([], B3), []);
  // A scoped outline answers from its own root: nothing above prints.
  const scoped = [find(tree, S2)];
  assert.deepEqual(ancestorsOf(scoped, B3), [S2, NESTED]);
  assert.deepEqual(ancestorsOf(scoped, S2), []);
});

// --- reveal: the selection rule ----------------------------------------------

test('reveal opens every row above the id and leaves the row itself and the rest, same object when already visible', () => {
  const tree = codeOutline();
  const folds = { [ROOT]: true, [S2]: true, [NESTED]: true, [B3]: true, [S1]: true };
  const opened = reveal(folds, tree, B3);
  // The chain down to B3 is open; B3's own collapsed posture and the
  // unrelated one on S1 stay (a unit row stays a unit when selected; code mode opens
  // the selection itself with expand).
  assert.deepEqual(opened, { [ROOT]: false, [S2]: false, [NESTED]: false, [B3]: true, [S1]: true });
  assert.deepEqual(folds, { [ROOT]: true, [S2]: true, [NESTED]: true, [B3]: true, [S1]: true }, 'the previous object is untouched');
  // An already-visible row returns the SAME object: the view re-renders
  // only on a real change.
  assert.equal(reveal(opened, tree, B3), opened);
  assert.equal(reveal(opened, tree, B1) === opened, false);
  assert.deepEqual(reveal(opened, tree, B1), { ...opened, [S1]: false });
  // An id the outline lacks (a field key) opens nothing.
  assert.equal(reveal(folds, tree, 'field:abc:body'), folds);
  assert.equal(reveal(folds, tree, null), folds);
  // An empty state stays itself when the chain is open by default.
  const none = {};
  assert.equal(reveal(none, tree, B3), none);
  assert.equal(reveal(null, tree, B3), null);
});

test('reveal opens a chain collapsed by default, not only by word', () => {
  const tree = treeOutline();
  // S2 defaults collapsed and holds NESTED > B3.
  assert.deepEqual(reveal({}, tree, B3), { [S2]: false });
  assert.deepEqual(reveal(null, tree, B3), { [S2]: false });
  // The root is above everything and never collapsed: no word for it.
  assert.deepEqual(reveal({}, tree, S1), {});
  const none = {};
  assert.equal(reveal(none, tree, S1), none);
  // The selected row itself keeps its default (a unit row stays a unit).
  assert.equal(isCollapsed(reveal({}, tree, B3), find(tree, B3)), true);
});

test('the reticle gesture: collapse all, then reveal opens only the selection\'s path', () => {
  const tree = codeOutline();
  const all = collapseAll({}, tree);
  const isolated = reveal(all, tree, B3);
  assert.equal(isCollapsed(isolated, find(tree, ROOT)), false);
  assert.equal(isCollapsed(isolated, find(tree, S2)), false);
  assert.equal(isCollapsed(isolated, find(tree, NESTED)), false);
  assert.equal(isCollapsed(isolated, find(tree, B3)), true, 'the row itself keeps the collapse');
  assert.equal(isCollapsed(isolated, find(tree, S1)), true, 'the sibling subtree stays collapsed');
  // Code mode's selection rule: reveal, then expand the selection.
  const selected = expand(isolated, find(tree, B3));
  assert.equal(isCollapsed(selected, find(tree, B3)), false);
  assert.equal(isCollapsed(selected, find(tree, S1)), true);
});

// --- collapseAll, expandAll, allCollapsed, allExpanded -----------------------

test('collapseAll and expandAll act on the outline\'s foldable rows only; the predicates are the controls\' disabled states', () => {
  const tree = codeOutline();
  const none = {};
  assert.equal(allCollapsed(none, tree), false);
  assert.equal(allExpanded(none, tree), true);
  // Collapse-all collapses every foldable row of THIS outline; a word on
  // a row this outline does not hold (another scope's row) survives it.
  const other = { 'container:elsewhere': true };
  const all = collapseAll(other, tree);
  assert.deepEqual(all, {
    'container:elsewhere': true,
    [ROOT]: true, [S1]: true, [S2]: true, [NESTED]: true, [B3]: true,
  });
  assert.deepEqual(other, { 'container:elsewhere': true }, 'the previous object is untouched');
  assert.equal(allCollapsed(all, tree), true);
  assert.equal(allExpanded(all, tree), false);
  assert.equal(collapseAll(all, tree), all, 'nothing left to collapse returns the same object');
  // The bare leaves never enter the state, so their absence never holds
  // the predicates.
  assert.equal(Object.hasOwn(all, B1), false);
  assert.equal(Object.hasOwn(all, B2), false);
  // Expand-all opens this outline's rows and keeps the other scope's word.
  const open = expandAll(all, tree);
  assert.deepEqual(open, {
    'container:elsewhere': true,
    [ROOT]: false, [S1]: false, [S2]: false, [NESTED]: false, [B3]: false,
  });
  assert.equal(allCollapsed(open, tree), false);
  assert.equal(allExpanded(open, tree), true);
  assert.equal(expandAll(open, tree), open, 'nothing left to expand returns the same object');
  assert.equal(expandAll(none, tree), none);
  assert.equal(expandAll(null, tree), null);
  assert.equal(collapseAll(none, []), none);
  assert.equal(expandAll(none, []), none);
  assert.equal(collapseAll(null, []), null);
});

test('the mixed state: neither predicate holds, both gestures change something', () => {
  const tree = codeOutline();
  const mixed = toggle({}, find(tree, S1));
  assert.equal(allCollapsed(mixed, tree), false);
  assert.equal(allExpanded(mixed, tree), false);
  assert.notEqual(collapseAll(mixed, tree), mixed);
  assert.notEqual(expandAll(mixed, tree), mixed);
  // Collapse-all from the mixed state writes only the rows still open.
  assert.deepEqual(collapseAll(mixed, tree), {
    [S1]: true, [ROOT]: true, [S2]: true, [NESTED]: true, [B3]: true,
  });
});

test('a selected bare leaf becomes foldable and joins the all-gestures', () => {
  const tree = codeOutline(B1);
  const all = collapseAll({}, tree);
  assert.equal(isCollapsed(all, find(tree, B1)), true);
  assert.equal(allCollapsed(all, tree), true);
  // The same state read against the outline where B1 is not foldable
  // still counts as all collapsed: B1's word is not consulted.
  assert.equal(allCollapsed(all, codeOutline()), true);
  // And the reverse: the code outline's collapse-all misses B1, so the
  // outline where B1 is selected is not all collapsed.
  assert.equal(allCollapsed(collapseAll({}, codeOutline()), tree), false);
});

test('the all-gestures respect the default posture: the tree\'s unit rows count as collapsed untouched', () => {
  const tree = treeOutline();
  // S2 defaults collapsed, S1 and NESTED default open.
  assert.equal(allCollapsed({}, tree), false);
  assert.equal(allExpanded({}, tree), false);
  // Collapse-all writes only the rows still open; S2 needs no word.
  assert.deepEqual(collapseAll({}, tree), { [S1]: true, [NESTED]: true });
  // Expand-all writes only the collapsed one.
  assert.deepEqual(expandAll({}, tree), { [S2]: false });
  // The root, not foldable, never receives a word from either gesture.
  assert.equal(Object.hasOwn(collapseAll({}, tree), ROOT), false);
  assert.equal(Object.hasOwn(expandAll({}, tree), ROOT), false);
  // The Advanced sections: every section auto-open, collapse-all closes them.
  const sections = sectionsOutline({ spacing: true, typography: true, layout: false });
  assert.deepEqual(collapseAll({}, sections), { spacing: true, typography: true });
  assert.deepEqual(expandAll({}, sections), { layout: false });
  assert.equal(allExpanded(expandAll({}, sections), sections), true);
});

test('an outline without a foldable row is both all collapsed and all expanded: both controls disabled', () => {
  // Code mode's flip label read an empty tree as "not all folded" so the
  // toggling button had a direction; two controls disabled when they
  // would do nothing want the vacuous truth instead.
  assert.equal(allCollapsed({}, []), true);
  assert.equal(allExpanded({}, []), true);
  assert.equal(allCollapsed(null, []), true);
  const leaves = [node(B1, false, false), node(B2, true, false)];
  const none = {};
  assert.equal(allCollapsed(none, leaves), true);
  assert.equal(allExpanded(none, leaves), true);
  assert.equal(collapseAll(none, leaves), none, 'nothing to touch returns the same object');
  assert.equal(expandAll(none, leaves), none);
});
