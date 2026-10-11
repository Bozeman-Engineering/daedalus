/**
 * Self-asserting tests for the tree rows' rename grammar
 * (rename-commit.js) — the pure decisions the Preact tree row
 * (page-elements-tree.js) renders.
 *
 * Three renamable kinds, ONE op: section and block rows write their stored label
 * through `update_element {label}` (empty CLEARS back to the type word
 * the annotator mints for an unrenamed section, "Section" or
 * "Container"), while the root row is the entity's label field
 * presenting AS the entity row, so its commit is ONE `update_element` op
 * on the root element with properties keyed by the annotation's derived
 * edit_target, and an empty or unchanged commit CANCELS (the label field
 * is required — nothing to clear to).
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { rowRenamable, renameOperation } from '../../js/edit_mode/components/page-elements/rename-commit.js';

const ROOT = '99999999-1111-2222-3333-444444444444';
const SECTION = 'aaaaaaaa-1111-2222-3333-444444444444';

// Rows as the projection delivers them: `label` and `elementType` are the
// server-minted words, `customLabel` the raw stored label the rename input
// seeds from (empty for an unrenamed section, whose label is its type).
const rootNode = { key: `entity:${ROOT}`, kind: 'entity', label: 'Hello there', elementType: 'Page', children: [] };
const sectionNode = (customLabel = '') => ({
  key: `container:${SECTION}`,
  kind: 'container',
  label: customLabel || 'Section',
  elementType: 'Section',
  customLabel,
  children: [],
});
// A merged text block: its NAME is the annotator's excerpt of its content,
// its customLabel the stored configuration label (the rename seed), so the
// two never agree by construction.
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const blockNode = (customLabel = 'Text') => ({
  key: `block:${BLOCK}`,
  kind: 'block',
  label: 'Welcome to the page',
  elementType: 'Text',
  customLabel,
  presentation: 'merged',
  children: [],
});

test('the root row is renamable exactly when the annotation routes an edit target', () => {
  // The gate reads the blueprint ANNOTATION's edit_target, which the tree
  // passes alongside the node (Full structure nulls the projection's own
  // copy, and the root must stay renamable there).
  assert.equal(rowRenamable(rootNode, true, 'title'), true);
  assert.equal(rowRenamable(rootNode, true, null), false);
});

test('container and block rows are renamable at any depth; other kinds are not', () => {
  assert.equal(rowRenamable(sectionNode(), false, null), true);
  assert.equal(rowRenamable(blockNode(), false, null), true);
  // A keyless section (no daedalus_layout uuid) has no identity to commit against.
  assert.equal(rowRenamable({ key: null, kind: 'container', children: [] }, false, null), false);
  // An INERT row (an element the actor can do nothing to) takes no
  // gesture, rename included, whatever its kind.
  assert.equal(rowRenamable(blockNode(), false, null, true), false);
  assert.equal(rowRenamable(sectionNode(), false, null, true), false);
  assert.equal(rowRenamable({ key: `field:${ROOT}:title`, kind: 'field', children: [] }, false, null), false);
  // Inline entity rows (paragraphs) share the root's kind but are not the
  // root: no rename yet, even if an edit target leaks in.
  assert.equal(rowRenamable({ key: `entity:${SECTION}`, kind: 'entity', children: [] }, false, 'title'), false);
});

test('the root commit is ONE update_element op keyed by the edit target', () => {
  const operation = renameOperation(rootNode, '  New title  ', {
    isRoot: true,
    editTarget: 'title',
    currentTitle: 'Old title',
  });
  assert.deepEqual(operation, {
    op: 'update_element',
    element: ROOT,
    properties: { title: 'New title' },
  });
});

test('an empty or unchanged root commit cancels', () => {
  const context = { isRoot: true, editTarget: 'title', currentTitle: 'Old title' };
  // The label field is required: there is no fallback name to clear to.
  assert.equal(renameOperation(rootNode, '', context), null);
  assert.equal(renameOperation(rootNode, '   ', context), null);
  assert.equal(renameOperation(rootNode, 'Old title', context), null);
  // No edit target routed (nothing renamable to begin with): never an op.
  assert.equal(renameOperation(rootNode, 'New title', { isRoot: true, editTarget: null }), null);
});

test('a section commit writes the custom label through update_element', () => {
  const operation = renameOperation(sectionNode(), 'Hero', {});
  assert.deepEqual(operation, {
    op: 'update_element',
    element: SECTION,
    properties: { label: 'Hero' },
  });
});

test('an empty section commit clears the custom label; unchanged is a no-op', () => {
  // Unlike the root, sections HAVE a fallback (the minted type word), so
  // empty means clear — a real op — while unchanged commits nothing. The
  // anchor is the raw custom label, never the displayed label: an
  // unrenamed section shows "Section" but stores nothing.
  assert.deepEqual(renameOperation(sectionNode('Hero'), '', {}), {
    op: 'update_element',
    element: SECTION,
    properties: { label: '' },
  });
  assert.equal(renameOperation(sectionNode('Hero'), 'Hero', {}), null);
  assert.equal(renameOperation(sectionNode(''), '  ', {}), null);
  // Typing the displayed type word into an unrenamed section IS a rename
  // (it stores "Section" as a custom label); only the stored anchor is
  // compared.
  assert.deepEqual(renameOperation(sectionNode(''), 'Section', {}), {
    op: 'update_element',
    element: SECTION,
    properties: { label: 'Section' },
  });
});

test('a block commit writes the configured label through update_element, anchored on the stored label', () => {
  // The same op as a section's: the component configuration's label
  // (BlueprintOperations update_element on a block). The anchor is the
  // STORED label, never the shown name: a merged block names itself by
  // its content, so typing that excerpt in IS a rename.
  assert.deepEqual(renameOperation(blockNode('Text'), '  Hero copy  ', {}), {
    op: 'update_element',
    element: BLOCK,
    properties: { label: 'Hero copy' },
  });
  assert.deepEqual(renameOperation(blockNode('Text'), 'Welcome to the page', {}), {
    op: 'update_element',
    element: BLOCK,
    properties: { label: 'Welcome to the page' },
  });
});

test('an unchanged block commit is a no-op; empty clears the label', () => {
  assert.equal(renameOperation(blockNode('Text'), 'Text', {}), null);
  assert.equal(renameOperation(blockNode('Text'), '  Text ', {}), null);
  assert.equal(renameOperation(blockNode(''), '', {}), null);
  // Empty clears (the row falls back to the name the annotator mints
  // without a label), the section precedent.
  assert.deepEqual(renameOperation(blockNode('Text'), '', {}), {
    op: 'update_element',
    element: BLOCK,
    properties: { label: '' },
  });
});
