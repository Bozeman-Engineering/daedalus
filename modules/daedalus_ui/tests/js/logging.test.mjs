/**
 * Self-asserting tests for the shared per-module logger.
 *
 * js/logging.js is a plain script that hangs its API off the Drupal global
 * rather than an ES module, so the suite evaluates the file with fake
 * Drupal, localStorage and console bindings in scope. That also makes the
 * localStorage contract (one key, seeded at load) directly testable.
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root (the bare
 * directory form would try to execute non-test helpers as tests).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const source = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), '../../js/logging.js'),
  'utf8',
);

const STORAGE_KEY = 'daedalusUiLogging';

/**
 * Loads logging.js against a fresh fake page.
 *
 * @param {object|null} store
 *   Seed contents for the fake localStorage, or null for storage that
 *   throws on every access (the private-mode case).
 */
const load = (store = {}) => {
  const lines = [];
  const localStorage = store === null
    ? {
      getItem: () => {
        throw new Error('storage disabled');
      },
      setItem: () => {
        throw new Error('storage disabled');
      },
    }
    : {
      getItem: (key) => (key in store ? store[key] : null),
      setItem: (key, value) => {
        store[key] = value;
      },
    };
  const Drupal = {};
  // Same realm as the assertions (a vm context would hand back arrays whose
  // prototype is not this realm's, which deepEqual rejects), with the page
  // globals the file reads supplied as parameters.
  // eslint-disable-next-line no-new-func
  new Function('Drupal', 'localStorage', 'console', source)(
    Drupal,
    localStorage,
    { log: (...args) => lines.push(args) },
  );
  return { api: Drupal.Daedalus, lines, store };
};

test('a module logs nothing until it is enabled', () => {
  const { api, lines } = load();
  const logger = api.logger('daedalus_stylish');
  logger.log('apply', 'Applying variant');
  assert.deepEqual(lines, []);
  assert.equal(logger.enabled, false);
});

test('an enabled module prints [module:tag] message', () => {
  const { api, lines } = load();
  const logger = api.logger('daedalus_stylish');
  api.logging.enable('daedalus_stylish');
  logger.log('apply', 'Applying variant');
  assert.deepEqual(lines, [['[daedalus_stylish:apply] Applying variant']]);
  assert.equal(logger.enabled, true);
});

test('data rides along as a second console argument', () => {
  const { api, lines } = load();
  const logger = api.logger('daedalus_ai');
  api.logging.enable('daedalus_ai');
  const data = { keys: ['a'] };
  logger.log('send', 'Sending message', data);
  assert.deepEqual(lines, [['[daedalus_ai:send] Sending message', data]]);
});

test('enabling one module leaves the others quiet', () => {
  const { api, lines } = load();
  api.logging.enable('daedalus_stylish');
  api.logger('daedalus_ai').log('send', 'Sending message');
  assert.deepEqual(lines, []);
});

test('a bare enable() turns everything on', () => {
  const { api, lines } = load();
  api.logging.enable();
  api.logger('daedalus_ai').log('send', 'Sending message');
  api.logger('daedalus_stylish').log('apply', 'Applying variant');
  assert.equal(lines.length, 2);
  assert.deepEqual(api.logging.status().enabled, ['*']);
});

test('disable(module) silences just that module', () => {
  const { api, lines } = load();
  api.logging.enable('daedalus_stylish');
  api.logging.enable('daedalus_ai');
  api.logging.disable('daedalus_stylish');
  api.logger('daedalus_stylish').log('apply', 'Applying variant');
  api.logger('daedalus_ai').log('send', 'Sending message');
  assert.deepEqual(lines, [['[daedalus_ai:send] Sending message']]);
});

test('a bare disable() clears the wildcard and everything else', () => {
  const { api, lines } = load();
  api.logging.enable();
  api.logging.enable('daedalus_stylish');
  api.logging.disable();
  api.logger('daedalus_stylish').log('apply', 'Applying variant');
  assert.deepEqual(lines, []);
  assert.deepEqual(api.logging.status().enabled, []);
});

test('status() reports the enabled names and every module holding a logger', () => {
  const { api } = load();
  api.logger('daedalus_stylish');
  api.logger('daedalus_ai');
  api.logging.enable('daedalus_stylish');
  assert.deepEqual(api.logging.status(), {
    enabled: ['daedalus_stylish'],
    modules: ['daedalus_stylish', 'daedalus_ai'],
  });
});

test('the enabled list persists in one localStorage key', () => {
  const { api, store } = load();
  api.logging.enable('daedalus_stylish');
  assert.deepEqual(Object.keys(store), [STORAGE_KEY]);
  assert.deepEqual(JSON.parse(store[STORAGE_KEY]), ['daedalus_stylish']);

  // A reload of the same page sees the module still enabled.
  const reloaded = load(store);
  reloaded.api.logger('daedalus_stylish').log('apply', 'Applying variant');
  assert.deepEqual(reloaded.lines, [['[daedalus_stylish:apply] Applying variant']]);
});

test('junk in the storage key leaves logging off rather than breaking', () => {
  const { api, lines } = load({ [STORAGE_KEY]: 'not json' });
  api.logger('daedalus_stylish').log('apply', 'Applying variant');
  assert.deepEqual(lines, []);
  assert.deepEqual(api.logging.status().enabled, []);
});

test('unavailable storage still logs, it just cannot persist', () => {
  const { api, lines } = load(null);
  api.logging.enable('daedalus_stylish');
  api.logger('daedalus_stylish').log('apply', 'Applying variant');
  assert.deepEqual(lines, [['[daedalus_stylish:apply] Applying variant']]);
});

test('repeat logger() calls hand back the same instance', () => {
  const { api } = load();
  assert.equal(api.logger('daedalus_stylish'), api.logger('daedalus_stylish'));
});

test('a logger grabbed before enablement starts printing on enable', () => {
  const { api, lines } = load();
  const logger = api.logger('daedalus_stylish');
  const { log } = logger;
  api.logging.enable('daedalus_stylish');
  // Destructured too: the logger must not depend on its own `this`.
  log('apply', 'Applying variant');
  assert.deepEqual(lines, [['[daedalus_stylish:apply] Applying variant']]);
});
