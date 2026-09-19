/**
 * Self-asserting tests for the media library modal helper.
 *
 * js/media-library.js is a plain script that hangs its API off the Drupal
 * global rather than an ES module, so the suite evaluates the file with a
 * fake Drupal in scope whose ajax() records the options it was built with
 * and whether execute() ran. The dialog shape is the contract: it is core's
 * MediaLibraryUiBuilder::dialogOptions() shape, and the callers (daedalus_edit's
 * image block editor, daedalus_stylish's inspector pickers) and the FunctionalJavascript
 * tier key off the media-library-widget-modal class, so the suite locks it.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const source = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), '../../js/media-library.js'),
  'utf8',
);

/**
 * Loads media-library.js against a fresh fake Drupal.
 *
 * @returns {{api: object, calls: object[]}}
 *   The Drupal.Daedalus API and every ajax() call in order, each with
 *   the options it was built with and an `executed` flag.
 */
const load = () => {
  const calls = [];
  const Drupal = {
    ajax: (options) => {
      const call = { options, executed: false };
      calls.push(call);
      return {
        execute: () => {
          call.executed = true;
        },
      };
    },
    t: (text) => text,
  };
  // eslint-disable-next-line no-new-func
  new Function('Drupal', source)(Drupal);
  return { api: Drupal.Daedalus, calls };
};

test('the modal opens the given URL as one executed AJAX modal', () => {
  const { api, calls } = load();
  api.openMediaLibraryModal('/media-library?state=abc');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].executed, true);
  assert.equal(calls[0].options.url, '/media-library?state=abc');
  assert.equal(calls[0].options.dialogType, 'modal');
});

test("the dialog wears core's media library shape", () => {
  const { api, calls } = load();
  api.openMediaLibraryModal('/media-library');
  assert.deepEqual(calls[0].options.dialog, {
    classes: { 'ui-dialog': 'media-library-widget-modal' },
    title: 'Add or select media',
    height: '75%',
    width: '75%',
  });
  assert.deepEqual(calls[0].options.progress, { type: 'fullscreen' });
});

test('each call opens its own URL', () => {
  const { api, calls } = load();
  api.openMediaLibraryModal('/first');
  api.openMediaLibraryModal('/second');
  assert.deepEqual(calls.map((call) => call.options.url), ['/first', '/second']);
});
