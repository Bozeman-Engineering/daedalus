/**
 * Self-asserting tests for the hosted form's mid-edit text format switch
 * (js/daedalus_edit/format-switch.js) over the shared filter round trip
 * (js/daedalus_edit/filter-xss.js).
 *
 * The contract: when the format selector moves to a format with an editor,
 * the textarea's value passes the filter round trip for that format BEFORE
 * anything is written to the page element, and only the answer is written.
 * A switch to a format with no editor writes nothing to the page. A
 * refused or failed request leaves the page element and the standing
 * editor alone.
 *
 * The switch is driven with plain objects: a textarea and a page element
 * that record every write, the editor machinery as recording callbacks,
 * and the real filterXss over a stubbed fetch.
 *
 * Run with `npm test` from the daedalus root, or `node --test` on this
 * file.
 */
import { strict as assert } from 'node:assert';
import { beforeEach, test } from 'node:test';

import { filterXss } from '../../js/daedalus_edit/filter-xss.js';
import { switchFormat } from '../../js/daedalus_edit/format-switch.js';

const STORED = '<p onclick="window.marked = 1">Stored</p>';
const FILTERED = '<p>Stored</p>';

/** The ordered record of everything the switch did. */
let log;

/** The stubbed route's answer: {ok, status, body}. */
let answer;

/** A classList that records nothing but keeps membership. */
const classList = () => {
  const names = new Set();
  return {
    add: (name) => names.add(name),
    remove: (name) => names.delete(name),
    contains: (name) => names.has(name),
  };
};

/** A page element whose innerHTML writes are logged. */
const pageElementDouble = (markup) => {
  let html = markup;
  return {
    classList: classList(),
    get innerHTML() {
      return html;
    },
    set innerHTML(value) {
      log.push(['write', value]);
      html = value;
    },
  };
};

/** A textarea holding a value. */
const textareaDouble = (value) => ({
  value,
  classList: classList(),
  focus: () => log.push(['focus']),
});

/** The switch's collaborators, recording; overridable per test. */
const machinery = (overrides = {}) => ({
  liveData: () => null,
  filter: filterXss,
  detach: () => {
    log.push(['detach']);
    return Promise.resolve();
  },
  attach: (config) => {
    log.push(['attach', config]);
  },
  saveMarkup: () => log.push(['saveMarkup']),
  active: () => true,
  ...overrides,
});

beforeEach(() => {
  log = [];
  answer = { ok: true, status: 200, body: FILTERED };
  globalThis.drupalSettings = {
    editor: {
      formats: {
        rich: { isXssSafe: false },
        safe: { isXssSafe: true },
      },
    },
  };
  globalThis.Drupal = { url: (path) => `/${path}` };
  globalThis.fetch = (url, options) => {
    log.push(['fetch', url, options.method, Object.fromEntries(options.body)]);
    return Promise.resolve({
      ok: answer.ok,
      status: answer.status,
      json: () => Promise.resolve(answer.body),
    });
  };
});

test('a switch to a format with an editor filters before the element is written', async () => {
  const textarea = textareaDouble(STORED);
  const pageElement = pageElementDouble('<p>Rendered</p>');
  const config = drupalSettings.editor.formats.rich;

  await switchFormat({
    textarea,
    pageElement,
    format: 'rich',
    previousFormat: 'plain',
    editorConfig: config,
    ...machinery(),
  });

  assert.deepEqual(log, [
    [
      'fetch',
      '/editor/filter_xss/rich',
      'POST',
      { value: STORED, original_format_id: 'plain' },
    ],
    ['detach'],
    ['saveMarkup'],
    ['write', FILTERED],
    ['attach', config],
  ]);
  assert.equal(pageElement.innerHTML, FILTERED);
  assert.equal(textarea.value, FILTERED, 'the textarea carries the seed');
  assert.ok(pageElement.classList.contains('daedalus-edit-inline-editing'));
  assert.ok(textarea.classList.contains('daedalus-edit-hidden'));
});

test("the live editor's data is what is filtered", async () => {
  const textarea = textareaDouble(STORED);
  const pageElement = pageElementDouble('');

  await switchFormat({
    textarea,
    pageElement,
    format: 'rich',
    previousFormat: 'other',
    editorConfig: {},
    ...machinery({ liveData: () => '<p>Typed</p>' }),
  });

  assert.deepEqual(log[0][3], {
    value: '<p>Typed</p>',
    original_format_id: 'other',
  });
});

test('an answer of false passes the value through, still after the request', async () => {
  answer.body = false;
  const textarea = textareaDouble('<p>Kept</p>');
  const pageElement = pageElementDouble('');

  await switchFormat({
    textarea,
    pageElement,
    format: 'rich',
    previousFormat: 'plain',
    editorConfig: {},
    ...machinery(),
  });

  assert.deepEqual(
    log.map(([step]) => step),
    ['fetch', 'detach', 'saveMarkup', 'write', 'attach'],
  );
  assert.equal(pageElement.innerHTML, '<p>Kept</p>');
});

test('an editor that guarantees safe markup skips the request', async () => {
  const textarea = textareaDouble('<p>Kept</p>');
  const pageElement = pageElementDouble('');

  await switchFormat({
    textarea,
    pageElement,
    format: 'safe',
    previousFormat: 'plain',
    editorConfig: {},
    ...machinery(),
  });

  assert.deepEqual(
    log.map(([step]) => step),
    ['detach', 'saveMarkup', 'write', 'attach'],
  );
});

test('a refused request writes nothing and leaves the editor standing', async () => {
  answer = { ok: false, status: 403, body: null };
  const textarea = textareaDouble(STORED);
  const pageElement = pageElementDouble('<p>Rendered</p>');

  await assert.rejects(
    switchFormat({
      textarea,
      pageElement,
      format: 'rich',
      previousFormat: 'plain',
      editorConfig: {},
      ...machinery(),
    }),
    /editor\/filter_xss\/rich failed with status 403/,
  );

  assert.deepEqual(
    log.map(([step]) => step),
    ['fetch'],
  );
  assert.equal(pageElement.innerHTML, '<p>Rendered</p>');
  assert.equal(textarea.value, STORED);
});

test('an edit that ended during the request is left alone', async () => {
  const textarea = textareaDouble(STORED);
  const pageElement = pageElementDouble('<p>Rendered</p>');

  await switchFormat({
    textarea,
    pageElement,
    format: 'rich',
    previousFormat: 'plain',
    editorConfig: {},
    ...machinery({ active: () => false }),
  });

  assert.deepEqual(
    log.map(([step]) => step),
    ['fetch'],
  );
  assert.equal(pageElement.innerHTML, '<p>Rendered</p>');
});

test('a switch to a format with no editor writes nothing to the page', async () => {
  const textarea = textareaDouble(STORED);
  textarea.classList.add('daedalus-edit-hidden');
  const pageElement = pageElementDouble('<p>Rendered</p>');
  pageElement.classList.add('daedalus-edit-inline-editing');

  await switchFormat({
    textarea,
    pageElement,
    format: 'plain',
    previousFormat: 'rich',
    editorConfig: undefined,
    ...machinery({ liveData: () => '<p>Typed</p>' }),
  });

  assert.deepEqual(log, [['detach'], ['focus']]);
  assert.equal(textarea.value, '<p>Typed</p>');
  assert.equal(pageElement.innerHTML, '<p>Rendered</p>');
  assert.ok(!textarea.classList.contains('daedalus-edit-hidden'));
  assert.ok(!pageElement.classList.contains('daedalus-edit-inline-editing'));
});
