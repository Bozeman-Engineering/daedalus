/**
 * Self-asserting tests for the document notice's pure model
 * (js/edit_mode/components/page-elements/document-notice-model.js): the
 * host-level verdict the envelope delivers, worded as one line per
 * scenario refusal, with the portal only when the verdict carries one
 * that leads somewhere.
 *
 * Run with `node --test tests/js/document-notice.test.mjs` from the
 * module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

globalThis.Drupal = {
  t: (string, args = {}) =>
    Object.entries(args).reduce((text, [name, value]) => text.replace(name, value), string),
};

const { noticeLines } = await import('../../js/edit_mode/components/page-elements/document-notice-model.js');

const can = { content: true, configure: false, style: true, arrange: false, insert: false };
const owner = { entity_type: 'entity_view_display', entity_id: 'node.page.default', label: 'Page' };
const foreign = (context) => ({
  code: 'FOREIGN_DOCUMENT',
  capabilities: ['configure', 'arrange', 'insert'],
  message: 'The layout of this node belongs to the template entity_view_display node.page.default, which it renders but does not own.',
  hint: 'Edit this node\'s own fields here; edit its layout on the template.',
  context,
});

test('nothing refused, no notice; a verdict not yet delivered, no notice', () => {
  assert.deepEqual(noticeLines({ can, refused: [] }), []);
  assert.deepEqual(noticeLines(null), []);
  assert.deepEqual(noticeLines(undefined), []);
});

test('a foreign document names the template, with the portal only when the verdict carries one with a url', () => {
  // An editor who may not open the template: no portal in the verdict.
  assert.deepEqual(noticeLines({ can, refused: [foreign({ owner })] }), [
    { code: 'FOREIGN_DOCUMENT', text: 'Layout from the template Page.', portal: null },
  ]);
  // A developer who may: the portal, with its url.
  const portal = { ...owner, url: '/admin/structure/types/manage/page/display/default/layout' };
  assert.deepEqual(noticeLines({ can, refused: [foreign({ owner, portal })] }), [
    {
      code: 'FOREIGN_DOCUMENT',
      text: 'Layout from the template Page.',
      portal: { label: 'Edit the template', url: portal.url },
    },
  ]);
  // A portal the site has no route for (no field_ui) is no door.
  assert.equal(noticeLines({ can, refused: [foreign({ owner, portal: { ...owner, url: null } })] })[0].portal, null);
});

test('a translation and a workspace are worded; a permission refusal is not the notice\'s to explain', () => {
  const lines = noticeLines({
    can,
    refused: [
      { code: 'ACCESS_DENIED_PERMISSION', capabilities: ['style'], message: 'Styling requires a permission.', hint: '', context: { permissions: ['style any elements'] } },
      { code: 'WORKSPACE_REQUIRED', capabilities: ['content', 'configure', 'style', 'arrange', 'insert'], message: 'Content changes require an active workspace.', hint: '', context: {} },
      { code: 'LAYOUT_NOT_TRANSLATABLE', capabilities: ['configure', 'style', 'arrange', 'insert'], message: 'The layout is shared.', hint: '', context: {} },
    ],
  });
  assert.deepEqual(lines.map((line) => line.code), ['LAYOUT_NOT_TRANSLATABLE', 'WORKSPACE_REQUIRED'], 'worded in scenario order, the permission refusal left to the surfaces that do not mount');
  assert.equal(lines[0].text, 'Layout and styles are shared across translations: edit them on the original.');
  assert.equal(lines[1].text, 'Changes require an active workspace.');
  assert.deepEqual(lines.map((line) => line.portal), [null, null]);
});
