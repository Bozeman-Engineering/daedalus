/**
 * Self-asserting tests for the shown-document row (shown-rows.js) and its
 * outline posture (tree-outline.js shownRow): the tree following the
 * render under every door.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  SHOWN_TYPE,
  shownDoorUuid,
  shownDoorways,
  shownRows,
} from '../../js/edit_mode/components/page-elements/shown-rows.js';
import { registerPortalAction } from '../../js/edit_mode/components/portal/portal-actions.js';
import { shownGlyph } from '../../js/edit_mode/components/page-elements/row-glyphs.js';
import { isCollapsed } from '../../js/outline/collapse.js';

// tree-outline.js imports the collapse model without an extension (the
// bundler's spelling), so the suite adds one where node's resolver fails.
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

const { outlineOf, shownRow } = await import(
  '../../js/edit_mode/components/page-elements/tree-outline.js'
);

const DOOR = '11111111-5107-4000-8000-444444444444';

/**
 * The spine's showing as the annotator delivers it on a join
 * (`_element.showing`: Showing::toArray() plus the portal's url).
 */
const showing = (shows, overrides = {}) => ({
  shows,
  document: { entity_type: 'blueprint', id: '1', label: 'Header' },
  blueprint: 'header',
  portal: { entity_type: 'blueprint', entity_id: '1', label: 'Header', url: '/blueprint/1' },
  claimable: true,
  ...overrides,
});

/**
 * A slot door, as the lean tree prints it with the T3 words.
 */
const door = (showingFact) => ({
  kind: 'block',
  uuid: DOOR,
  plugin_id: 'daedalus_slot:header',
  slot: 'header',
  _element: {
    name: 'Header slot',
    type: 'Slot',
    icon: '/slot.svg',
    ...(showingFact ? { showing: showingFact } : {}),
  },
});

test('a door showing its default grows one shown-document row named by the blueprint', () => {
  const fact = showing('default');
  const rows = shownRows(door(fact));
  assert.equal(rows.length, 1);
  const [row] = rows;
  // The row's kind: shown, not a model row (no key) and not a pseudo row.
  assert.equal(row.shown, true);
  assert.equal(row.key, undefined);
  assert.equal(row.pseudo, undefined);
  // Its words: the shown document's label as its name (the spine's), the
  // tree's one minted type word, the link glyph.
  assert.equal(row.label, 'Header');
  assert.equal(row.elementType, SHOWN_TYPE);
  assert.equal(row.elementType, 'Blueprint, by reference');
  assert.equal(row.icon, shownGlyph());
  // The door it sits under and the showing it adapts, for the doorways.
  assert.equal(row.parentKey, `block:${DOOR}`);
  assert.equal(row.showing, fact);
  // A leaf: nothing of the shown document is this host's.
  assert.deepEqual(row.children, []);
});

test('no row without a default: fill, route, nothing, no showing, no document', () => {
  assert.deepEqual(shownRows(door(showing('fill'))), []);
  assert.deepEqual(shownRows(door(showing('route'))), []);
  assert.deepEqual(shownRows(door(showing('nothing', { document: null, blueprint: null, portal: null, claimable: false }))), []);
  // A plugin block that is no join carries no showing at all.
  assert.deepEqual(shownRows(door(null)), []);
  // A default the spine could not name (no document) has nothing to row.
  assert.deepEqual(shownRows(door(showing('default', { document: null }))), []);
  // No annotation at all (a region, a bare node).
  assert.deepEqual(shownRows({ kind: 'block', uuid: DOOR }), []);
  assert.deepEqual(shownRows(null), []);
});

test('the row rides a default whatever the portal: the portal gates the doorway, not the row', () => {
  // An actor who may not update the blueprint still sees what the door
  // shows (the row); only Edit is withheld.
  const rows = shownRows(door(showing('default', { portal: null, claimable: false })));
  assert.equal(rows.length, 1);
  assert.equal(rows[0].label, 'Header');
});

test('the outline gives the row an id keyed by its door and a leaf posture', () => {
  const [row] = shownRows(door(showing('default')));
  const outlineRow = shownRow(row);
  assert.deepEqual(outlineRow, { id: `shown:block:${DOOR}`, foldable: false, folded: true });
  // Through outlineOf, under a door row, as the tree projects it.
  const outline = outlineOf({
    key: 'entity:page',
    kind: 'entity',
    label: 'Page',
    presentation: 'open',
    children: [{
      key: `block:${DOOR}`,
      kind: 'block',
      label: 'Header slot',
      presentation: 'open',
      children: [row],
    }],
  });
  const doorOutline = outline[0].children[0];
  assert.equal(doorOutline.id, `block:${DOOR}`);
  assert.equal(doorOutline.foldable, true, 'the door folds over its shown row');
  assert.deepEqual(doorOutline.children.map((node) => node.id), [`shown:block:${DOOR}`]);
  assert.equal(doorOutline.children[0].foldable, false);
  assert.equal(isCollapsed(null, doorOutline.children[0]), true, 'a leaf reads collapsed');
  assert.equal(isCollapsed(null, doorOutline), false, 'the door opens by default');
});

test('the doorways: Edit through the one leave, only with a portal url', () => {
  const left = [];
  globalThis.Drupal = {
    t: (value) => value,
    Daedalus: { Portal: { leave: (url) => left.push(url) } },
  };
  const items = shownDoorways(showing('default'));
  assert.equal(items.length, 1);
  assert.equal(items[0].label, 'Edit');
  items[0].run();
  assert.deepEqual(left, ['/blueprint/1']);
  // No portal (the actor may not update the blueprint), no url: no doorway.
  assert.deepEqual(shownDoorways(showing('default', { portal: null })), []);
  assert.deepEqual(shownDoorways(showing('default', { portal: { entity_type: 'blueprint', entity_id: '1', label: 'Header', url: null } })), []);
  assert.deepEqual(shownDoorways(null), []);
  delete globalThis.Drupal;
});

test('the registered doorways follow Edit, bound to the door the row sits under', () => {
  const runs = [];
  globalThis.Drupal = {
    t: (value) => value,
    Daedalus: { Portal: { leave: () => {} } },
  };
  const unregister = registerPortalAction({
    id: 'test.claim',
    label: 'Detach into this slot',
    icon: '<svg/>',
    applies: (fact) => fact.claimable === true,
    run: (fact, uuid) => runs.push([fact.blueprint, uuid]),
  });
  const [row] = shownRows(door(showing('default')));
  assert.equal(shownDoorUuid(row), DOOR, 'the door uuid is the parentKey minus its block: prefix');
  const items = shownDoorways(row.showing, shownDoorUuid(row));
  assert.deepEqual(items.map((item) => item.label), ['Edit', 'Detach into this slot']);
  assert.equal(items[1].icon, '<svg/>');
  items[1].run();
  assert.deepEqual(runs, [['header', DOOR]]);
  // Without a portal the claim still applies (an editor may claim a header
  // they may not edit): the registered doorway alone.
  assert.deepEqual(
    shownDoorways(showing('default', { portal: null }), DOOR).map((item) => item.label),
    ['Detach into this slot'],
  );
  // The spine refused the claim (the frame's own canvas, a linked
  // placement): Edit alone.
  assert.deepEqual(
    shownDoorways(showing('default', { claimable: false }), DOOR).map((item) => item.label),
    ['Edit'],
  );
  unregister();
  delete globalThis.Drupal;
});
