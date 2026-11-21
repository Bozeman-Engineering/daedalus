/**
 * Self-asserting tests for the one client token source (js/csrf.js):
 * Drupal.Daedalus.csrfToken fetches the session token once per document
 * however many writers ask and however they overlap, a failed token
 * fetch is not remembered and nothing is sent without a token, and
 * Drupal.Daedalus.post carries the token in the X-CSRF-Token header
 * with the body encoded by its kind (JSON, multipart, none).
 *
 * js/csrf.js is a plain script that hangs its API off the Drupal global
 * rather than an ES module, so the suite evaluates the file with a fake
 * Drupal in scope; each load is a fresh document with its own token.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { afterEach, test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const source = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), '../../js/csrf.js'),
  'utf8',
);

const originalFetch = globalThis.fetch;
afterEach(() => {
  globalThis.fetch = originalFetch;
});

/**
 * Loads the script against a fake Drupal: one document.
 *
 * @returns {object}
 *   Drupal.Daedalus.
 */
const load = () => {
  const Drupal = {
    url: (path) => `/base/${path}`,
    t: (text, args = {}) =>
      Object.entries(args).reduce((out, [key, value]) => out.replace(key, String(value)), text),
  };
  // eslint-disable-next-line no-new-func
  new Function('Drupal', source)(Drupal);
  return Drupal.Daedalus;
};

/**
 * Mounts a fetch that records every call and answers the token route.
 *
 * @param {Function} token
 *   Answers the token route's response; called once per token fetch.
 *
 * @returns {Array<{url: string, init: ?object}>}
 *   The calls, in order.
 */
const recordFetch = (token = () => ({ ok: true, text: async () => 'token-1' })) => {
  const calls = [];
  globalThis.fetch = async (url, init) => {
    calls.push({ url, init });
    return url === '/base/session/token' ? token() : { ok: true, status: 200 };
  };
  return calls;
};

const tokenFetches = (calls) => calls.filter(({ url }) => url === '/base/session/token');

test('the token is fetched once, for overlapping and for later callers', async () => {
  const calls = recordFetch();
  const api = load();
  const [first, second] = await Promise.all([api.csrfToken(), api.csrfToken()]);
  assert.equal(first, 'token-1');
  assert.equal(second, 'token-1');
  await Promise.all([api.post('/a', {}), api.post('/b', {})]);
  await api.post('/c', {});
  assert.equal(await api.csrfToken(), 'token-1');
  assert.equal(tokenFetches(calls).length, 1);
  assert.deepEqual(calls.map(({ url }) => url), ['/base/session/token', '/a', '/b', '/c']);
});

test('the root other scripts registered with is kept', () => {
  const Drupal = { Daedalus: { existing: true }, url: (path) => path, t: (text) => text };
  // eslint-disable-next-line no-new-func
  new Function('Drupal', source)(Drupal);
  assert.equal(Drupal.Daedalus.existing, true);
  assert.equal(typeof Drupal.Daedalus.post, 'function');
});

test('post sends an object as JSON with the token in the header', async () => {
  const calls = recordFetch();
  const response = await load().post('/write', { thread_id: 't1' });
  assert.deepEqual(response, { ok: true, status: 200 });
  const { url, init } = calls[calls.length - 1];
  assert.equal(url, '/write');
  assert.equal(init.method, 'POST');
  assert.deepEqual(init.headers, { 'X-CSRF-Token': 'token-1', 'Content-Type': 'application/json' });
  assert.deepEqual(JSON.parse(init.body), { thread_id: 't1' });
});

test('post sends a FormData untouched and names no content type', async () => {
  const calls = recordFetch();
  const form = new FormData();
  form.append('thread_id', 't1');
  await load().post('/upload', form);
  const { init } = calls[calls.length - 1];
  assert.equal(init.method, 'POST');
  assert.deepEqual(init.headers, { 'X-CSRF-Token': 'token-1' });
  assert.equal(init.body, form);
});

test('post with no body sends none, and carries the method it is given', async () => {
  const calls = recordFetch();
  const api = load();
  await api.post('/bare');
  assert.deepEqual(calls[calls.length - 1].init, { method: 'POST', headers: { 'X-CSRF-Token': 'token-1' } });
  await api.post('/record', null, { method: 'DELETE' });
  assert.deepEqual(calls[calls.length - 1].init, { method: 'DELETE', headers: { 'X-CSRF-Token': 'token-1' } });
  await api.post('/record', { label: 'x' }, { method: 'PATCH' });
  assert.equal(calls[calls.length - 1].init.method, 'PATCH');
  assert.equal(calls[calls.length - 1].init.body, '{"label":"x"}');
});

test('post asks for keepalive only when the caller does', async () => {
  const calls = recordFetch();
  const api = load();
  await api.post('/before-navigation', { batch_id: 'b1' }, { keepalive: true });
  const { init } = calls[calls.length - 1];
  assert.equal(init.keepalive, true);
  assert.equal(init.method, 'POST');
  assert.equal(init.headers['X-CSRF-Token'], 'token-1');
  await api.post('/ordinary', { batch_id: 'b1' });
  assert.equal('keepalive' in calls[calls.length - 1].init, false);
});

test('a refused token fetch rejects the write, sends nothing, and is asked again', async () => {
  let attempt = 0;
  const calls = recordFetch(() => {
    attempt += 1;
    return attempt === 1 ? { ok: false, status: 503, text: async () => '<html>down</html>' } : { ok: true, text: async () => 'token-2' };
  });
  const api = load();
  await assert.rejects(api.post('/write', {}), /The session token request failed \(503\)\./);
  assert.deepEqual(calls.map(({ url }) => url), ['/base/session/token']);
  await api.post('/write', {});
  assert.equal(tokenFetches(calls).length, 2);
  assert.equal(calls[calls.length - 1].init.headers['X-CSRF-Token'], 'token-2');
});

test('a token fetch the network failed is not remembered either', async () => {
  let attempt = 0;
  const calls = recordFetch(() => {
    attempt += 1;
    if (attempt === 1) {
      throw new TypeError('Failed to fetch');
    }
    return { ok: true, text: async () => 'token-3' };
  });
  const api = load();
  await assert.rejects(api.csrfToken(), /Failed to fetch/);
  assert.equal(await api.csrfToken(), 'token-3');
  assert.equal(tokenFetches(calls).length, 2);
});
