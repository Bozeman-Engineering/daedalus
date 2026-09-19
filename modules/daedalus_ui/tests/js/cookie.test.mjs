/**
 * Self-asserting tests for the cookie helpers.
 *
 * js/cookie.js is a plain script that hangs its API off the Drupal global
 * rather than an ES module, so the suite evaluates the file with a fake
 * Drupal and a fake document in scope. The fake document keeps a cookie jar
 * that honours the two attributes the helpers emit (path and max-age), so a
 * write, a read and a delete round-trip the way a browser would: a cookie is
 * keyed by name AND path, and only a write at its own path removes it.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const source = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), '../../js/cookie.js'),
  'utf8',
);

/**
 * Parses one document.cookie assignment into its parts.
 *
 * @param {string} written
 *   The assigned string.
 *
 * @returns {{name: string, value: string, attributes: object}}
 *   The name, value and attribute map (attribute names lower-cased).
 */
const parse = (written) => {
  const [pair, ...rest] = written.split(';').map((part) => part.trim());
  const eq = pair.indexOf('=');
  const attributes = {};
  rest.forEach((attribute) => {
    const [key, value] = attribute.split('=');
    attributes[key.toLowerCase()] = value;
  });
  return { name: pair.slice(0, eq), value: pair.slice(eq + 1), attributes };
};

/**
 * Loads cookie.js against a fresh fake page.
 *
 * @returns {{api: object, writes: object[], jar: Map}}
 *   The Drupal.Daedalus API, every parsed write in order, and the jar
 *   keyed by "name@path".
 */
const load = () => {
  const writes = [];
  const jar = new Map();
  const document = {
    get cookie() {
      // Like a browser, longer paths are listed first.
      return [...jar.entries()]
        .sort(([a], [b]) => b.split('@')[1].length - a.split('@')[1].length)
        .map(([, { name, value }]) => `${name}=${value}`)
        .join('; ');
    },
    set cookie(written) {
      const parsed = parse(written);
      writes.push(parsed);
      const key = `${parsed.name}@${parsed.attributes.path}`;
      if (parsed.attributes['max-age'] === '0') {
        jar.delete(key);
      }
      else {
        jar.set(key, { name: parsed.name, value: parsed.value });
      }
    },
  };
  const Drupal = {};
  // eslint-disable-next-line no-new-func
  new Function('Drupal', 'document', source)(Drupal, document);
  return { api: Drupal.Daedalus, writes, jar };
};

test('a write is site-wide and session-long unless told otherwise', () => {
  const { api, writes } = load();
  api.setCookieValue('activeTool', 'edit');
  assert.deepEqual(writes, [
    { name: 'activeTool', value: 'edit', attributes: { path: '/' } },
  ]);
});

test('a write honours an explicit path and lifetime', () => {
  const { api, writes } = load();
  api.setCookieValue('right_sidebar_width', '320', { maxAge: 31536000 });
  api.setCookieValue('navigationMode', 'edit', { path: '/node/1' });
  assert.deepEqual(writes, [
    { name: 'right_sidebar_width', value: '320', attributes: { path: '/', 'max-age': '31536000' } },
    { name: 'navigationMode', value: 'edit', attributes: { path: '/node/1' } },
  ]);
});

test('set and delete share the path, by default and when given one', () => {
  const { api, writes } = load();
  api.setCookieValue('activeTool', 'edit');
  api.deleteCookieValue('activeTool');
  api.setCookieValue('navigationMode', 'edit', { path: '/node/1' });
  api.deleteCookieValue('navigationMode', { path: '/node/1' });
  assert.equal(writes.length, 4);
  assert.equal(writes[1].attributes.path, writes[0].attributes.path);
  assert.equal(writes[1].attributes.path, '/');
  assert.equal(writes[3].attributes.path, writes[2].attributes.path);
  assert.equal(writes[3].attributes.path, '/node/1');
  // A delete is the one idiom: an empty value expiring now.
  [writes[1], writes[3]].forEach((deletion) => {
    assert.equal(deletion.value, '');
    assert.equal(deletion.attributes['max-age'], '0');
  });
});

test('a delete removes what a write at the same path set', () => {
  const { api, jar } = load();
  api.setCookieValue('activeTool', 'edit');
  assert.equal(api.getCookieValue('activeTool'), 'edit');
  api.deleteCookieValue('activeTool');
  assert.equal(api.getCookieValue('activeTool'), null);
  assert.equal(jar.size, 0);
});

test('a delete at another path leaves the cookie in place', () => {
  const { api } = load();
  api.setCookieValue('navigationMode', 'edit', { path: '/node/1' });
  api.deleteCookieValue('navigationMode');
  assert.equal(api.getCookieValue('navigationMode'), 'edit');
  api.deleteCookieValue('navigationMode', { path: '/node/1' });
  assert.equal(api.getCookieValue('navigationMode'), null);
});

test('an empty value is a write, not a delete', () => {
  const { api, writes, jar } = load();
  api.setCookieValue('left_top_sidebar', '');
  assert.deepEqual(writes, [
    { name: 'left_top_sidebar', value: '', attributes: { path: '/' } },
  ]);
  assert.equal(jar.size, 1);
  assert.equal(api.getCookieValue('left_top_sidebar'), null);
});

test('the reader finds a cookie among others and misses an absent one', () => {
  const { api } = load();
  api.setCookieValue('activeTool', 'selector');
  api.setCookieValue('navigationMode', 'edit', { path: '/node/1' });
  assert.equal(api.getCookieValue('activeTool'), 'selector');
  assert.equal(api.getCookieValue('navigationMode'), 'edit');
  assert.equal(api.getCookieValue('missing'), null);
});
