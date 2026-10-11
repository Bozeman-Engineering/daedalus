/**
 * Self-asserting tests for the peek's request (js/src/peek.js): the door's
 * URL carries the effective navigation mode as a query parameter, the
 * convention every daedalus_blueprint_api client follows (the cookie is
 * path-scoped to the edited page, so a request to the door's path must
 * carry the mode itself), and requestPeek reads the mode from the cookie
 * the way the mutation client does, 'none' when no cookie is set.
 *
 * The module reads Drupal.url and Drupal.Daedalus.getCookieValue at call
 * time and posts through fetch; the spec mounts stubs for all three and
 * records what the door is asked.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const cookies = {};
globalThis.Drupal = {
  url: (path) => `/${path}`,
  Daedalus: {
    getCookieValue: (name) => cookies[name],
  },
};

// Every fetch the module makes: the CSRF token's and the peek's. The token
// is fetched once per process (blueprint-gestures memoises it), so the
// peek's request is the last call recorded.
const calls = [];
globalThis.fetch = async (url, init) => {
  calls.push({ url, init });
  if (url === '/session/token') {
    return { ok: true, text: async () => 'token-1' };
  }
  return { ok: true, text: async () => '<html></html>' };
};

const { peekUrl, requestPeek } = await import('../../js/src/peek.js');

const context = { entityType: 'node', entityId: '7', viewMode: 'full' };
const request = { operations: [{ op: 'add_section', uuid: 'u1' }], show: 'container:u1' };

const lastPeek = () => calls[calls.length - 1];

test('peekUrl names the door and carries the mode it is given', () => {
  assert.equal(peekUrl(context, 'edit'), '/daedalus-blueprint/node/7/peek?navigationMode=edit');
  assert.equal(peekUrl(context, 'none'), '/daedalus-blueprint/node/7/peek?navigationMode=none');
});

test('with the cookie set, the peek request carries its mode', async () => {
  cookies.navigationMode = 'edit';
  const document = await requestPeek(context, request);
  assert.equal(document, '<html></html>');
  const { url, init } = lastPeek();
  assert.equal(url, '/daedalus-blueprint/node/7/peek?navigationMode=edit');
  assert.equal(init.method, 'POST');
  assert.equal(init.headers['X-CSRF-Token'], 'token-1');
  assert.deepEqual(JSON.parse(init.body), {
    operations: request.operations,
    show: request.show,
    view_mode: 'full',
  });
});

test('without a cookie, the peek request carries none', async () => {
  delete cookies.navigationMode;
  await requestPeek(context, request);
  assert.equal(lastPeek().url, '/daedalus-blueprint/node/7/peek?navigationMode=none');
});

test('the token is fetched once, the peek never without the mode parameter', () => {
  const tokenFetches = calls.filter(({ url }) => url === '/session/token');
  assert.equal(tokenFetches.length, 1);
  calls
    .filter(({ url }) => url !== '/session/token')
    .forEach(({ url }) => assert.match(url, /\?navigationMode=(edit|none)$/));
});
