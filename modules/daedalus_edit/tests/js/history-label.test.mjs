/**
 * Self-asserting tests for the history labels the editing module writes:
 * the sample fill's (js/daedalus_edit/sample-fill.js fillWithSample) and
 * the editor base's (js/daedalus_edit/plugins/editor-plugin-base.js
 * commitLabel). A history label is plain text, escaped once where it is
 * shown, so a name reaches the mutation character for character.
 *
 * Drupal.t is stubbed with core's placeholder rule (Drupal.formatString):
 * an `@name` value is escaped, any other sigil passes through.
 *
 * Run with `npm test` from the daedalus root, or `node --test` on this
 * file.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const checkPlain = (text) =>
  String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';

let names = {};
const sent = [];

globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce(
      (out, [key, value]) =>
        out.split(key).join(key.startsWith('@') ? checkPlain(value) : value),
      text,
    ),
  Daedalus: {
    Blueprint: {
      rootContext: () => ({ entityType: 'node', entityId: '1' }),
      labelForKey: (key) => names[key],
      mutate: (mutation) => {
        sent.push(mutation);
        return Promise.resolve({});
      },
      reportFailure: () => {},
      getBlueprint: () => ({}),
      componentForUuid: (blueprint, uuid) =>
        uuid in names ? { _element: { name: names[uuid] } } : null,
    },
  },
};

const { fillWithSample } =
  await import('../../js/daedalus_edit/sample-fill.js');
const { EditorPluginBase } =
  await import('../../js/daedalus_edit/plugins/editor-plugin-base.js');

test('the sample fill labels its mutation in plain text', async () => {
  const key = `block:${BLOCK}:field_body`;
  names = { [key]: 'Q&A' };
  await fillWithSample(key);
  assert.equal(sent.at(-1).label, 'Filled Q&A with a sample');

  names = { [key]: 'Tim\'s "<b>"' };
  await fillWithSample(key);
  assert.equal(sent.at(-1).label, 'Filled Tim\'s "<b>" with a sample');
});

test('an editor labels its commit in plain text', () => {
  const editor = new EditorPluginBase();
  names = { [BLOCK]: 'Q&A' };
  assert.equal(editor.commitLabel({ componentUuid: BLOCK }, {}), 'Edited Q&A');

  names = { [BLOCK]: 'Tim\'s "<b>"' };
  assert.equal(
    editor.commitLabel({ componentUuid: BLOCK }, {}),
    'Edited Tim\'s "<b>"',
  );
});

test('an editor with no named component labels its commit as a block', () => {
  names = {};
  assert.equal(new EditorPluginBase().commitLabel({}, {}), 'Edited block');
});
