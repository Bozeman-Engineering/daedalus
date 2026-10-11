/**
 * Self-asserting tests for "a read-only element is not selectable".
 *
 * An element the actor can do nothing to (its published verdict allows
 * none of the five capabilities) cannot become the selection by any
 * road. The one definition is capable-model.js selectableIn, and every
 * road asks it: the pointer chain (resolver.js representedChainKeys, so
 * a click resolves to the outermost SELECTABLE key and a field block
 * presenting one of the page's own fields resolves to the row that
 * presents that field), the editing gate (keyForEditableItem, both
 * doors), reach (reach.js, so the arrows and the breadcrumb walk past
 * it), commitAddress, the tree row model (rowInert) and, as the
 * backstop under the facade, the tree, applySelect and the stylish
 * sources, the store's selection gate (store/selection-gate.js).
 *
 * The fixture is the shape a page of a non-overridable bundle delivers:
 * the page owns its fields (content and its own style key open at the
 * root), the template's section and text block are refused everything,
 * and the template's field block presenting the page's body carries the
 * body's content verdict and nothing else.
 *
 * Run with `node --test tests/js/not-selectable.test.mjs` from the
 * module root.
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
 * A stub element: attributes, parent links, and the DOM surface the
 * resolver walks.
 */
class El {
  constructor(attrs = {}) {
    this.attrs = attrs;
    this.parentElement = null;
    this.children = [];
  }

  add(child) {
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  getAttribute(name) {
    return this.attrs[name] ?? null;
  }

  hasAttribute(name) {
    return name in this.attrs;
  }

  matches(selector) {
    const presence = selector.match(/^\[([a-z-]+)\]$/);
    return presence ? this.hasAttribute(presence[1]) : false;
  }

  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) {
        return node;
      }
    }
    return null;
  }
}

globalThis.Element = El;
globalThis.document = {
  querySelector: () => null,
  documentElement: { addEventListener: () => {} },
};
globalThis.window = globalThis;
globalThis.Drupal = { t: (value) => value, behaviors: {} };
globalThis.drupalSettings = {};

const { selectableIn } = await import('../../js/edit_mode/selection/capable-model.js');
const { selectable } = await import('../../js/edit_mode/selection/capable.js');
const { keyForEditableItem, representedChainKeys, chainKeys, resolve, POLICY } = await import('../../js/edit_mode/selection/resolver.js');
const { commitAddress } = await import('../../js/edit_mode/selection/commit-address.js');
const { ancestryOf } = await import('../../js/edit_mode/selection/ancestry.js');
const { reachable, reachPredicate, reachableRows, reachableParent } = await import('../../js/edit_mode/components/page-elements/reach.js');
const { projectionIndex } = await import('../../js/edit_mode/components/page-elements/projection-index.js');
const { rowInert } = await import('../../js/edit_mode/components/page-elements/tree-row-model.js');
const { gateSelectionAction, selectionGate } = await import('../../js/edit_mode/store/selection-gate.js');
const { default: store } = await import('../../js/edit_mode/store/store.js');
const { blueprintBooted } = await import('../../js/edit_mode/store/slices/blueprint-slice.js');
const {
  setSelected,
  toggleSelected,
  setSelectedSet,
  setHover,
  setHoverSelector,
  clearSelection,
} = await import('../../js/edit_mode/store/slices/selection-slice.js');
const { initSelectionFacade } = await import('../../js/edit_mode/selection/selection-facade.js');

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';
const TEXT_BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const TEXT = 'cccccccc-1111-2222-3333-444444444444';
const FIELD_BLOCK = 'eeeeeeee-1111-2222-3333-444444444444';

const ROOT_KEY = `entity:${ROOT}`;
const SECTION_KEY = `container:${SECTION}`;
const TEXT_BLOCK_KEY = `block:${TEXT_BLOCK}`;
const FIELD_BLOCK_KEY = `block:${FIELD_BLOCK}`;
const BODY_KEY = `field:${ROOT}:body`;

const refused = { content: false, configure: false, style: false, arrange: false, insert: false };
const contentOnly = { ...refused, content: true };
const rootCan = { ...refused, content: true, style: true };

const named = (name, type = name) => ({ name, type });

/**
 * The template's components as a plain host delivers them: the text
 * block refused everything, the field block (presenting the page's body,
 * merged into it) with the body's content verdict alone.
 */
const components = () => [
  {
    kind: 'block',
    uuid: TEXT_BLOCK,
    block_type: 'text',
    label: 'Template text',
    block_fields: { field_text: { uuid: TEXT, type: 'text', fields: { field_text_text: 'Template body' } } },
    _element: { ...named('Template body', 'Text'), presentation: { mode: 'merged', edit_target: 'field_text' }, can: refused },
  },
  {
    kind: 'block',
    uuid: FIELD_BLOCK,
    plugin_id: 'field_block:node:page:body',
    label: 'Body',
    _element: {
      ...named('Body'),
      presentation: { mode: 'merged', label: 'Body', edit_target: 'body' },
      presented_field: BODY_KEY,
      can: contentOnly,
    },
  },
];

const boot = () =>
  store.dispatch(blueprintBooted({
    entityType: 'node',
    entityId: '3',
    uuid: ROOT,
    label: 'Plain page',
    langcode: 'en',
    viewMode: 'full',
    verdict: { can: rootCan, refused: [{ code: 'FOREIGN_DOCUMENT', capabilities: ['configure', 'arrange', 'insert'] }] },
    blueprint: {
      bundle: 'plain',
      // The body is non-preserved on the display: it renders through the
      // field block only, so its own row folds and the field block's row
      // presents it.
      fields: { title: 'Plain page', body: 'Page body text' },
      _element: { ...named('Plain page', 'Page'), presentation: { mode: 'merged', edit_target: 'title' }, fields: { body: { element: false } }, can: rootCan },
      layout: [{
        kind: 'container',
        uuid: SECTION,
        layout_id: 'layout_onecol',
        _element: { ...named('Section'), can: refused },
        children: [{ kind: 'region', region: 'content', children: components() }],
      }],
    },
  }));

/**
 * The canvas of the plain host: the section wraps the two placements,
 * the field block (a merged presentation root) wraps the page's body
 * field and its editable leaf, the text block wraps its own field.
 */
const canvas = () => {
  const main = new El({ 'data-selectable': ROOT_KEY, 'data-main-entity': '' });
  const section = main.add(new El({ 'data-selectable': SECTION_KEY }));
  const fieldBlock = section.add(new El({ 'data-selectable': FIELD_BLOCK_KEY, 'data-presentation': 'merged', 'data-presented-field': BODY_KEY }));
  const body = fieldBlock.add(new El({ 'data-selectable': BODY_KEY }));
  const bodyLeaf = body.add(new El({ 'data-editable': '' }));
  const textBlock = section.add(new El({ 'data-selectable': TEXT_BLOCK_KEY, 'data-presentation': 'merged' }));
  const text = textBlock.add(new El({ 'data-selectable': `field:${TEXT}:field_text_text` }));
  const textLeaf = text.add(new El({ 'data-editable': '' }));
  return { main, section, fieldBlock, body, bodyLeaf, textBlock, text, textLeaf };
};

test('selectableIn: a verdict allowing anything is selectable, one allowing nothing is not, a part answers its owner', () => {
  const index = new Map([
    [SECTION_KEY, { can: refused }],
    [FIELD_BLOCK_KEY, { can: contentOnly }],
    ['block:unstamped', {}],
  ]);
  assert.equal(selectableIn(index, SECTION_KEY), false);
  assert.equal(selectableIn(index, FIELD_BLOCK_KEY), true);
  assert.equal(selectableIn(index, `${SECTION_KEY}::> *`), false);
  assert.equal(selectableIn(index, `${FIELD_BLOCK_KEY}::> p`), true);
  assert.equal(selectableIn(index, 'block:unstamped'), false);
  assert.equal(selectableIn(index, 'block:unknown'), false);
  assert.equal(selectableIn(index, null), false);
  assert.equal(selectableIn(null, FIELD_BLOCK_KEY), false);
});

test('the gate rule, pure: an unselectable key is dropped from every selection action, the rest pass', () => {
  const ok = (key) => key === 'a' || key === 'b';
  assert.deepEqual(gateSelectionAction(setSelected('a'), ok), setSelected('a'));
  assert.equal(gateSelectionAction(setSelected('x'), ok), null);
  assert.deepEqual(gateSelectionAction(setSelected(null), ok), setSelected(null), 'the clear passes');
  assert.equal(gateSelectionAction(toggleSelected('x'), ok), null);
  assert.deepEqual(gateSelectionAction(setSelectedSet({ keys: ['a', 'x', 'b'], primary: 'x' }), ok).payload, { keys: ['a', 'b'], primary: null });
  assert.deepEqual(gateSelectionAction(setSelectedSet({ keys: ['a', 'b'], primary: 'a' }), ok), setSelectedSet({ keys: ['a', 'b'], primary: 'a' }));
  assert.equal(gateSelectionAction(setHover('x'), ok), null);
  assert.deepEqual(gateSelectionAction(setHover({ key: 'a', selector: '.c' }), ok), setHover({ key: 'a', selector: '.c' }));
  assert.equal(gateSelectionAction(setHoverSelector({ key: 'x', selector: '.c' }), ok), null);
  assert.deepEqual(gateSelectionAction(clearSelection(), ok), clearSelection(), 'an action without keys passes untouched');
  assert.deepEqual(gateSelectionAction({ type: 'drag/start', payload: 'x' }, ok), { type: 'drag/start', payload: 'x' });
  // The middleware is inert without an index (before the mirror boots).
  const passed = [];
  selectionGate(() => null, () => false)()((action) => passed.push(action))(setSelected('x'));
  assert.deepEqual(passed, [setSelected('x')]);
});

test('before the mirror boots the store gate is inert, as the pointer chain is', () => {
  assert.equal(projectionIndex(), null);
  store.dispatch(setSelected('block:anything'));
  assert.equal(store.getState().selection.selectedKey, 'block:anything');
  store.dispatch(clearSelection());
});

test('the index: the template elements allow nothing, the field block carries the body\'s content, the root its own', () => {
  boot();
  const index = projectionIndex();
  assert.deepEqual(index.get(SECTION_KEY).can, refused);
  assert.deepEqual(index.get(TEXT_BLOCK_KEY).can, refused);
  assert.deepEqual(index.get(FIELD_BLOCK_KEY).can, contentOnly);
  assert.deepEqual(index.get(ROOT_KEY).can, rootCan);
  assert.equal(index.has(BODY_KEY), false, 'the body has no row of its own: the field block presents it');
  assert.equal(selectable(SECTION_KEY), false);
  assert.equal(selectable(TEXT_BLOCK_KEY), false);
  assert.equal(selectable(FIELD_BLOCK_KEY), true);
  assert.equal(selectable(ROOT_KEY), true);
});

test('pointer resolution skips unselectable keys and lands on the row presenting the page field', () => {
  boot();
  const dom = canvas();
  // The raw chain under the body's leaf climbs through the field block
  // and the template section; the represented chain keeps only the
  // selectable, presented row: the field block, which IS the body.
  assert.deepEqual(chainKeys(dom.bodyLeaf), [BODY_KEY, FIELD_BLOCK_KEY, SECTION_KEY]);
  assert.deepEqual(representedChainKeys(dom.bodyLeaf), [FIELD_BLOCK_KEY]);
  assert.equal(resolve(dom.bodyLeaf), FIELD_BLOCK_KEY, 'the boundary stop is the outermost selectable key');
  assert.equal(resolve(dom.bodyLeaf, { policy: POLICY.LEAF }), FIELD_BLOCK_KEY);
  assert.equal(resolve(dom.body), FIELD_BLOCK_KEY);
  // Inside the template's text block nothing is selectable: the click
  // resolves to nothing, as a click on the bare canvas does.
  assert.deepEqual(representedChainKeys(dom.textLeaf), []);
  assert.equal(resolve(dom.textLeaf), null);
  assert.equal(resolve(dom.textBlock), null);
  assert.equal(resolve(dom.section), null, 'the template section itself is no stop');
  // A standing selection on the field block deepens nothing past it and
  // a drill into the refused section finds no stop inside it.
  assert.equal(resolve(dom.bodyLeaf, { selectedKeys: [FIELD_BLOCK_KEY] }), FIELD_BLOCK_KEY);
  assert.equal(resolve(dom.textLeaf, { drillKey: SECTION_KEY }), null);
});

test('the editing gate: the body\'s leaf edits through the field block, the template text does not', () => {
  boot();
  const dom = canvas();
  assert.equal(keyForEditableItem(dom.bodyLeaf), FIELD_BLOCK_KEY, 'the merged root promotes the leaf to the placement, whose content is open');
  assert.equal(keyForEditableItem(dom.textLeaf), null, 'the template block is refused everything');
  // The second door (a row-less field of a known subject) asks the
  // subject's verdict: the page's own body printed by the theme edits,
  // a hidden field of the refused template block does not.
  const main = new El({ 'data-selectable': ROOT_KEY, 'data-main-entity': '' });
  const themed = main.add(new El({ 'data-selectable': BODY_KEY })).add(new El({ 'data-editable': '' }));
  assert.equal(keyForEditableItem(themed), BODY_KEY);
  const hidden = main.add(new El({ 'data-selectable': `${TEXT_BLOCK_KEY}:field_text` })).add(new El({ 'data-editable': '' }));
  assert.equal(keyForEditableItem(hidden), null);
});

test('reach walks past unselectable rows: the arrows and the breadcrumb never stand on the template', () => {
  boot();
  const index = projectionIndex();
  const reach = reachPredicate();
  assert.equal(reachable(ROOT_KEY), true);
  assert.equal(reachable(FIELD_BLOCK_KEY), true);
  assert.equal(reachable(SECTION_KEY), false, 'drawn, but not reachable');
  assert.equal(reachable(TEXT_BLOCK_KEY), false);
  // The root's drawn rows hoist through the unreachable section to the
  // field block alone; the field block's reachable parent is the root.
  assert.deepEqual(reachableRows(index, reach, ROOT_KEY), [FIELD_BLOCK_KEY]);
  assert.equal(reachableParent(index, reach, FIELD_BLOCK_KEY), ROOT_KEY);
  assert.deepEqual(ancestryOf(index, reach, FIELD_BLOCK_KEY).map((crumb) => crumb.label), ['Plain page', 'Body']);
});

test('the store gate drops an unselectable key whoever dispatches: the slice, the facade, commitAddress', () => {
  boot();
  initSelectionFacade();
  const Selection = globalThis.Drupal.Daedalus.Selection;
  store.dispatch(clearSelection());

  store.dispatch(setSelected(SECTION_KEY));
  assert.equal(store.getState().selection.selectedKey, null, 'the template section is never the selection');
  store.dispatch(setSelected(FIELD_BLOCK_KEY));
  assert.equal(store.getState().selection.selectedKey, FIELD_BLOCK_KEY);
  store.dispatch(toggleSelected(TEXT_BLOCK_KEY));
  assert.deepEqual(store.getState().selection.selectedKeys, [FIELD_BLOCK_KEY], 'a toggle of a refused key changes nothing');
  store.dispatch(setSelectedSet({ keys: [SECTION_KEY, FIELD_BLOCK_KEY, ROOT_KEY], primary: SECTION_KEY }));
  assert.deepEqual(store.getState().selection.selectedKeys, [FIELD_BLOCK_KEY, ROOT_KEY]);
  assert.equal(store.getState().selection.selectedKey, ROOT_KEY, 'the dropped primary defaults to the last survivor');
  store.dispatch(setHover(SECTION_KEY));
  assert.equal(store.getState().selection.hoverKey, null, 'hover names what a click would select, and it would select nothing');
  store.dispatch(setHoverSelector({ key: TEXT_BLOCK_KEY, selector: '> p' }));
  assert.equal(store.getState().selection.hoverSelector, null);
  store.dispatch(setHover(FIELD_BLOCK_KEY));
  assert.equal(store.getState().selection.hoverKey, FIELD_BLOCK_KEY);

  // The facade and the in-app commit road ride the same gate.
  Selection.select(TEXT_BLOCK_KEY);
  assert.equal(Selection.getSelected(), ROOT_KEY);
  assert.equal(Selection.selectable(TEXT_BLOCK_KEY), false);
  assert.equal(Selection.selectable(FIELD_BLOCK_KEY), true);
  Selection.selectSet([SECTION_KEY, TEXT_BLOCK_KEY]);
  assert.deepEqual(Selection.getSelectedSet(), [], 'a set of refused keys is the empty set');
  commitAddress(SECTION_KEY, '> *');
  assert.equal(Selection.getSelected(), null);
  assert.equal(Selection.getTarget(), null);
  commitAddress(FIELD_BLOCK_KEY);
  assert.equal(Selection.getSelected(), FIELD_BLOCK_KEY);
  store.dispatch(clearSelection());
});

test('the tree row model marks the template rows inert and the page\'s rows live', () => {
  boot();
  const index = projectionIndex();
  assert.equal(rowInert(index, SECTION_KEY), true);
  assert.equal(rowInert(index, TEXT_BLOCK_KEY), true);
  assert.equal(rowInert(index, FIELD_BLOCK_KEY), false);
  assert.equal(rowInert(index, ROOT_KEY), false);
  assert.equal(rowInert(index, null), true, 'a keyless row stays inert');
  assert.equal(rowInert(null, FIELD_BLOCK_KEY), true, 'no index, nothing live');
});

test('the re-seat never lands on a refused row: a row-less key under the template answers nothing, the page\'s own falls to the root', async () => {
  const { reseatAddress } = await import('../../js/edit_mode/components/page-elements/reseat.js');
  boot();
  const index = projectionIndex();
  const reach = reachPredicate();
  const single = (selectedKey) => ({ selectedKey, selectedKeys: [selectedKey], target: null });
  // The template text block's field key has the template's refused
  // verdict as its subject: no view flip may seat the selection there.
  assert.equal(reseatAddress(index, reach, single(`${TEXT_BLOCK_KEY}:field_text`)), null);
  // The page's body is non-preserved (no row in any view); its subject,
  // the root, is the page's own and reachable.
  assert.equal(index.has(BODY_KEY), false);
  assert.equal(reseatAddress(index, reach, single(BODY_KEY)), ROOT_KEY);
  // A placed key moves nowhere.
  assert.equal(reseatAddress(index, reach, single(FIELD_BLOCK_KEY)), null);
});
