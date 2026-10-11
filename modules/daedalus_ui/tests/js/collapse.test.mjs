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
 * The page elements tree's adapter (page-elements/tree-outline.js) is
 * covered at the end: its default postures over a rendered tree (the
 * root never folds, a unit row and a leafless row start collapsed, rich
 * text starts collapsed: a block over instance rows and an instance row
 * with rows of its own), the direct row count a collapsed rich-text row
 * shows, a manual posture surviving a re-projection, the all-gestures
 * and the selection reveal reaching the rich-text rows like any row, and
 * the row the selection rule reveals (revealId: the seated part's row
 * when the outline draws it, else the selected key, null unselected) with
 * the path change a seat under an already selected block makes. The
 * adapter uses webpack-style extensionless imports, so the suite
 * registers the extension-adding resolve hook before importing it.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';

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

// --- The page elements tree's adapter: its default postures -----------------

const {
  foldedRowCount,
  modelRow,
  outlineOf,
  pathTo,
  pseudoRow,
  revealId,
  richTextRow,
} = await import(
  '../../js/edit_mode/components/page-elements/tree-outline.js'
);

/**
 * A rendered tree the way the panel composes it (projection.js nodes with
 * markup-view.js pseudo rows appended): the root over a section holding
 * a merged text block (a paragraph and a list of two items, each item
 * over its paragraph), an open block whose body field carries the
 * carriers, a unit block, an empty block, and a generated box under the
 * section (a Markup-view pseudo row that is not rich text).
 */
const TEXT = 'block:text';
const OPEN = 'block:open';
const BODY = 'field:open:body';
const UNIT = 'block:unit';
const EMPTY = 'block:empty';
const pid = (owner, id) => `pseudo:${owner}:${id}`;

const instance = (owner, id, children = []) => ({
  pseudo: true,
  id,
  parentKey: owner,
  label: id,
  source: 'instance',
  children,
});
const shell = (owner, id, children = []) => ({
  pseudo: true,
  id,
  parentKey: owner,
  label: id,
  source: 'markup',
  children,
});
const model = (key, kind, children = [], presentation = 'open') => ({
  key,
  kind,
  label: key,
  presentation,
  children,
});

const richList = (owner) =>
  instance(owner, '.ul', [
    instance(owner, '.li1', [instance(owner, '.li1-p')]),
    instance(owner, '.li2', [instance(owner, '.li2-p')]),
  ]);

const renderedTree = (textChildren = [instance(TEXT, '.p'), richList(TEXT)]) =>
  model('entity:page', 'entity', [
    model(S1, 'container', [
      model(TEXT, 'block', textChildren),
      model(OPEN, 'block', [model(BODY, 'field', [instance(BODY, '.p')])]),
      model(UNIT, 'block', [model('field:unit:body', 'field')], 'unit'),
      model(EMPTY, 'block'),
      shell(S1, '::before'),
    ]),
  ]);

test('the tree\'s defaults: the root never folds, a unit row and a leafless row start collapsed, the rest open', () => {
  const tree = renderedTree();
  const outline = outlineOf(tree);
  const root = find(outline, 'entity:page');
  assert.deepEqual({ foldable: root.foldable, folded: root.folded }, { foldable: false, folded: false });
  assert.equal(isCollapsed(null, find(outline, S1)), false, 'a section opens');
  assert.equal(isCollapsed(null, find(outline, UNIT)), true, 'a unit row starts collapsed');
  assert.equal(find(outline, UNIT).foldable, true);
  assert.equal(isCollapsed(null, find(outline, EMPTY)), true, 'a leafless row reads collapsed');
  assert.equal(find(outline, EMPTY).foldable, false, 'but is not foldable');
  assert.equal(isCollapsed(null, find(outline, OPEN)), false, 'an open block over a field row opens');
  assert.equal(isCollapsed(null, find(outline, pid(S1, '::before'))), false, 'a generated box opens');
  // The ids: a model row by its key, a pseudo row by its owner and part.
  assert.deepEqual(modelRow(model(TEXT, 'block'), false).id, TEXT);
  assert.deepEqual(modelRow({ key: null, label: 'Region', children: [] }, false).id, 'Region');
  assert.deepEqual(pseudoRow(instance(TEXT, '.p')).id, pid(TEXT, '.p'));
});

test('rich text starts collapsed: a block over instance rows and an instance row with rows of its own', () => {
  const tree = renderedTree();
  const outline = outlineOf(tree);
  // The merged text block, over a paragraph and a list.
  assert.equal(richTextRow(tree.children[0].children[0]), true);
  assert.equal(isCollapsed(null, find(outline, TEXT)), true, 'the text block starts collapsed');
  assert.equal(find(outline, TEXT).foldable, true, 'and is foldable');
  // The open block's field row carries the carriers: it folds, the block does not.
  assert.equal(isCollapsed(null, find(outline, BODY)), true, 'the body field over carriers starts collapsed');
  assert.equal(isCollapsed(null, find(outline, OPEN)), false);
  // The list and each item (over its paragraph) start collapsed; a
  // paragraph has no rows and is not foldable.
  assert.equal(isCollapsed(null, find(outline, pid(TEXT, '.ul'))), true, 'a list starts collapsed');
  assert.equal(isCollapsed(null, find(outline, pid(TEXT, '.li1'))), true, 'a list item over its paragraph starts collapsed');
  assert.equal(isCollapsed(null, find(outline, pid(TEXT, '.p'))), false, 'a bare paragraph is open');
  assert.equal(find(outline, pid(TEXT, '.p')).foldable, false);
  assert.equal(find(outline, pid(TEXT, '.li1-p')).foldable, false);
  // A shell chain is structure, not rich text: it starts open even with
  // rows under it, as does a block over shells alone.
  const chain = shell(TEXT, '.wrap', [shell(TEXT, '.inner')]);
  assert.equal(richTextRow(chain), false);
  assert.equal(isCollapsed(null, pseudoRow(chain)), false);
  assert.equal(isCollapsed(null, modelRow(model(TEXT, 'block', [chain]), false)), false);
  // The Markup view lists a text block's shells beside its carriers: the
  // block still starts collapsed.
  const markupView = model(TEXT, 'block', [chain, instance(TEXT, '.p')]);
  assert.equal(isCollapsed(null, modelRow(markupView, false)), true);
});

test('a collapsed rich-text row counts its direct rows; an open row and every other row count nothing', () => {
  const tree = renderedTree();
  const section = tree.children[0];
  const text = section.children[0];
  const list = text.children[1];
  assert.equal(foldedRowCount(text, true), 2, 'the paragraph and the list');
  assert.equal(foldedRowCount(text, false), null, 'open: the rows tell the story');
  assert.equal(foldedRowCount(list, true), 2, 'a list counts its items');
  assert.equal(foldedRowCount(list.children[0], true), 1, 'an item counts its paragraph');
  assert.equal(foldedRowCount(list.children[0].children[0], true), null, 'a paragraph has no rows');
  // The body field over carriers counts them; the open block above it
  // and the unit, empty and section rows say nothing even when collapsed.
  assert.equal(foldedRowCount(section.children[1].children[0], true), 1);
  assert.equal(foldedRowCount(section.children[1], true), null);
  assert.equal(foldedRowCount(section.children[2], true), null, 'a unit row');
  assert.equal(foldedRowCount(section.children[3], true), null, 'a leafless row');
  assert.equal(foldedRowCount(section, true), null, 'a section collapsed by hand');
  assert.equal(foldedRowCount(section.children[4], true), null, 'a generated box');
  // In the Markup view the shells count among the block's direct rows.
  const markupView = model(TEXT, 'block', [shell(TEXT, '.wrap'), instance(TEXT, '.p')]);
  assert.equal(foldedRowCount(markupView, true), 2);
});

test('a manual posture on a rich-text row survives a re-projection of the same rows', () => {
  let outline = outlineOf(renderedTree());
  // The user opens the text block and its list: words keyed by row id.
  let folds = toggle(null, find(outline, TEXT));
  folds = toggle(folds, find(outline, pid(TEXT, '.ul')));
  assert.deepEqual(folds, { [TEXT]: false, [pid(TEXT, '.ul')]: false });
  // A mutation re-projects the tree: new node objects, one more
  // paragraph under the block. The words stand, the default does not
  // reassert itself, and the open row shows no count.
  const reprojected = renderedTree([instance(TEXT, '.p'), instance(TEXT, '.p2'), richList(TEXT)]);
  outline = outlineOf(reprojected);
  assert.equal(isCollapsed(folds, find(outline, TEXT)), false, 'the block stays open');
  assert.equal(isCollapsed(folds, find(outline, pid(TEXT, '.ul'))), false, 'the list stays open');
  assert.equal(isCollapsed(folds, find(outline, pid(TEXT, '.li1'))), true, 'an untouched item keeps its default');
  assert.equal(foldedRowCount(reprojected.children[0].children[0], isCollapsed(folds, find(outline, TEXT))), null);
  // Closed again by hand, the block shows its new count.
  folds = toggle(folds, find(outline, TEXT));
  assert.equal(isCollapsed(folds, find(outline, TEXT)), true);
  assert.equal(foldedRowCount(reprojected.children[0].children[0], true), 3);
});

test('expand all opens the rich-text rows, collapse all needs no word for them, and the reveal opens the chain to a paragraph', () => {
  const outline = outlineOf(renderedTree());
  // Expand all writes a word for every row collapsed by default: the
  // text block, the body field, the unit row, the list and its items.
  assert.deepEqual(expandAll(null, outline), {
    [TEXT]: false,
    [pid(TEXT, '.ul')]: false,
    [pid(TEXT, '.li1')]: false,
    [pid(TEXT, '.li2')]: false,
    [BODY]: false,
    [UNIT]: false,
  });
  assert.equal(allExpanded(expandAll(null, outline), outline), true);
  // Collapse all touches only the foldable rows open by default; the
  // rich-text rows are already collapsed and get no word, and the
  // generated box, a leaf, is not foldable.
  assert.deepEqual(collapseAll(null, outline), {
    [S1]: true,
    [OPEN]: true,
  });
  assert.equal(allCollapsed(collapseAll(null, outline), outline), true);
  // Neither predicate holds at the defaults: the root row's pair is live.
  assert.equal(allExpanded(null, outline), false);
  assert.equal(allCollapsed(null, outline), false);
  // The selection rule: a paragraph under a list item under the list
  // under the block reveals through every collapsed default above it.
  assert.deepEqual(reveal(null, outline, pid(TEXT, '.li2-p')), {
    [TEXT]: false,
    [pid(TEXT, '.ul')]: false,
    [pid(TEXT, '.li2')]: false,
  });
  // The block's own posture is the selection's: selecting the block
  // itself opens nothing (its section is open), as a unit row stays a unit.
  const none = {};
  assert.equal(reveal(none, outline, TEXT), none);
});

test('revealId: the seated part\'s row when the outline draws it, else the selected key, null unselected', () => {
  const outline = outlineOf(renderedTree());
  // A key alone: the selected element's row, as before the target joined.
  assert.equal(revealId(outline, { selectedKey: TEXT, target: null }), TEXT);
  assert.equal(revealId(outline, { selectedKey: S1, target: undefined }), S1);
  // A seated part on a folded block: the part row's id, the one pseudoRow
  // mints, so the reveal opens the block's closed default down to the
  // leaf, and the leaf's own posture is left alone (a seated list item
  // with rows of its own stays folded).
  const seated = { key: TEXT, selector: '.li2-p' };
  assert.equal(revealId(outline, { selectedKey: TEXT, target: seated }), pid(TEXT, '.li2-p'));
  assert.deepEqual(reveal(null, outline, revealId(outline, { selectedKey: TEXT, target: seated })), {
    [TEXT]: false,
    [pid(TEXT, '.ul')]: false,
    [pid(TEXT, '.li2')]: false,
  });
  const item = { key: TEXT, selector: '.li2' };
  const opened = reveal(null, outline, revealId(outline, { selectedKey: TEXT, target: item }));
  assert.deepEqual(opened, { [TEXT]: false, [pid(TEXT, '.ul')]: false });
  assert.equal(isCollapsed(opened, find(outline, pid(TEXT, '.li2'))), true, 'the seated item keeps its own fold');
  // A seat under the already selected block CHANGES the path (the rule
  // is keyed to the path's change), where the key alone would not.
  assert.notEqual(
    pathTo(outline, revealId(outline, { selectedKey: TEXT, target: seated })),
    pathTo(outline, revealId(outline, { selectedKey: TEXT, target: null })),
  );
  assert.equal(
    pathTo(outline, revealId(outline, { selectedKey: TEXT, target: seated })),
    [ROOT, S1, TEXT, pid(TEXT, '.ul'), pid(TEXT, '.li2'), pid(TEXT, '.li2-p')].join('\n'),
  );
  // A target whose key is not in the outline falls back to the key: the
  // selected element is revealed rather than nothing.
  assert.equal(
    revealId(outline, { selectedKey: TEXT, target: { key: 'block:gone', selector: '.p' } }),
    TEXT,
  );
  // A pair keyed above the selection (the slice never carries one as a
  // target now that a rule on an ancestor is the write address, but the
  // rule is pure over what it is handed) names no drawn row either: the
  // key again.
  assert.equal(
    revealId(outline, { selectedKey: TEXT, target: { key: S1, selector: 'p' } }),
    TEXT,
  );
  // A part the tree is not drawing under a drawn key: the key.
  assert.equal(
    revealId(outline, { selectedKey: TEXT, target: { key: TEXT, selector: '.undrawn' } }),
    TEXT,
  );
  // Nothing selected: null, and the path is empty, so the effect's
  // dependency holds through a clear.
  assert.equal(revealId(outline, { selectedKey: null, target: null }), null);
  assert.equal(pathTo(outline, revealId(outline, { selectedKey: null, target: null })), '');
});
