/**
 * Self-asserting tests for the chat's attachment request encoding
 * (js/daedalus-ai-attachment-request.js).
 *
 * The file is a plain script hanging its API off the Drupal global, so the
 * suite evaluates it with a fake Drupal in scope. The multipart body under
 * test is built the way deep-chat 2.2.1's createCustomFormDataBody builds
 * it (files under `files`, the body props stringified, `message<n>` JSON
 * strings, the last message only when it has text), on node's own
 * FormData and File.
 *
 * Run with `npm test` from the daedalus root (the root sweep picks up
 * modules/star/tests/js/star.test.mjs), or `node --test` on this file.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const source = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), '../../js/daedalus-ai-attachment-request.js'),
  'utf8',
);

/**
 * Loads the script against a fake Drupal.
 *
 * @returns {object}
 *   Drupal.Daedalus.AiAttachments.
 */
const load = () => {
  const Drupal = {
    Daedalus: {},
    url: (path) => `/${path}`,
    t: (text, args = {}) =>
      Object.entries(args).reduce((out, [key, value]) => out.replace(key, String(value)), text),
  };
  // eslint-disable-next-line no-new-func
  new Function('Drupal', source)(Drupal);
  return Drupal.Daedalus.AiAttachments;
};

/**
 * Builds the multipart body deep-chat hands the interceptor.
 *
 * @param {object} body
 *   The connect's additionalBodyProps.
 * @param {object[]} messages
 *   The messages, the last one the user's turn.
 * @param {File[]} files
 *   The attached files.
 *
 * @returns {FormData}
 *   The body.
 */
const multipart = (body, messages, files) => {
  const formData = new FormData();
  files.forEach((file) => formData.append('files', file));
  Object.keys(body).forEach((key) => formData.append(key, String(body[key])));
  let index = 0;
  messages.slice(0, messages.length - 1).forEach((message) => {
    formData.append(`message${(index += 1)}`, JSON.stringify(message));
  });
  const last = messages[messages.length - 1];
  if (last.text) {
    const { files: dropped, ...rest } = last;
    formData.append(`message${(index += 1)}`, JSON.stringify(rest));
  }
  return formData;
};

const svg = new File(['<svg xmlns="http://www.w3.org/2000/svg"/>'], 'hero.svg', { type: 'image/svg+xml' });
const csv = new File(['a,b\n1,2\n'], 'rows.csv', { type: 'text/csv' });

const bodyProps = {
  assistant_id: 'chatbot',
  thread_id: 'assistant_thread_2_0',
  stream: false,
  contexts: { current_route: '/node/5' },
};

test('the multipart body reads back as its messages in order and its files', () => {
  const api = load();
  const history = [
    { role: 'user', text: 'first' },
    { role: 'ai', html: '<p>hi</p>' },
    { role: 'user', text: 'Use these', files: [{ name: 'hero.svg' }] },
  ];
  const { messages, files } = api.readMultipart(multipart(bodyProps, history, [svg, csv]));
  assert.deepEqual(messages, [
    { role: 'user', text: 'first' },
    { role: 'ai', html: '<p>hi</p>' },
    { role: 'user', text: 'Use these' },
  ]);
  assert.deepEqual(files.map((file) => file.name), ['hero.svg', 'rows.csv']);
});

test('a files-only send reads back without the last message', () => {
  const api = load();
  const { messages, files } = api.readMultipart(
    multipart(bodyProps, [{ role: 'user', text: '', files: [{ name: 'hero.svg' }] }], [svg]),
  );
  assert.deepEqual(messages, []);
  assert.equal(files.length, 1);
});

test('encode: a JSON string body with the text messages, the body props and the contexts', () => {
  const api = load();
  const { body, headers } = api.encode({
    messages: [
      { role: 'ai', html: '<p>hi</p>' },
      { role: 'user', text: 'Use these', files: [{ name: 'hero.svg', type: 'image/svg+xml' }] },
    ],
    bodyProps,
    contexts: { selected_elements: [{ key: 'block:1', label: 'Hero', number: 1 }], view_mode: 'full' },
    attachments: ['hero.svg', 'rows.csv'],
    headers: { 'X-Existing': 'kept' },
  });
  assert.equal(typeof body, 'string');
  assert.deepEqual(headers, { 'X-Existing': 'kept', 'Content-Type': 'application/json' });
  assert.deepEqual(JSON.parse(body), {
    messages: [
      { role: 'ai', html: '<p>hi</p>' },
      { role: 'user', text: 'Use these' },
    ],
    assistant_id: 'chatbot',
    thread_id: 'assistant_thread_2_0',
    stream: false,
    contexts: {
      current_route: '/node/5',
      selected_elements: [{ key: 'block:1', label: 'Hero', number: 1 }],
      view_mode: 'full',
      attachments: ['hero.svg', 'rows.csv'],
    },
  });
});

test('encode keeps the body props typed: stream stays the boolean false, never the multipart "false"', () => {
  const api = load();
  const { body } = api.encode({
    messages: [{ role: 'user', text: 'x' }],
    bodyProps,
    contexts: {},
    attachments: [],
  });
  assert.equal(JSON.parse(body).stream, false);
});

test('encode restores the user turn a files-only send lost', () => {
  const api = load();
  const { body } = api.encode({
    messages: [{ role: 'user', text: 'earlier' }, { role: 'ai', html: '<p>ok</p>' }],
    bodyProps,
    contexts: {},
    attachments: ['hero.svg'],
  });
  const { messages } = JSON.parse(body);
  assert.deepEqual(messages[messages.length - 1], { role: 'user', text: '' });
  assert.equal(messages.length, 3);
  const { body: empty } = api.encode({ messages: [], bodyProps, contexts: {}, attachments: ['hero.svg'] });
  assert.deepEqual(JSON.parse(empty).messages, [{ role: 'user', text: '' }]);
});

test('the round trip: read the multipart, encode the JSON turn', () => {
  const api = load();
  const history = [{ role: 'user', text: 'Use these', files: [{ name: 'hero.svg' }] }];
  const { messages } = api.readMultipart(multipart(bodyProps, history, [svg]));
  const { body } = api.encode({ messages, bodyProps, contexts: { view_mode: 'full' }, attachments: ['hero.svg'] });
  assert.deepEqual(JSON.parse(body).messages, [{ role: 'user', text: 'Use these' }]);
  assert.deepEqual(JSON.parse(body).contexts, { current_route: '/node/5', view_mode: 'full', attachments: ['hero.svg'] });
});

test('oversized names every file over the ceiling and nothing under it', () => {
  const api = load();
  const big = new File([new Uint8Array(200)], 'big.png', { type: 'image/png' });
  assert.deepEqual(api.oversized([svg, big, csv], 100), ['big.png']);
  assert.deepEqual(api.oversized([svg, csv], 1024), []);
});
