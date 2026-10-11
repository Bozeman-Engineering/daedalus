/**
 * Self-asserting tests for the peek's cache (js/src/model/peek-cache.js):
 * a miss answers undefined, a put is a hit, a put over a face replaces
 * the held document, drop with a predicate sheds only the matching
 * faces, clear empties, and size counts the faces held.
 *
 * Run with `node --test "modules/daedalus_layout/tests/js/*.test.mjs"`
 * from the daedalus root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

const { createPeekCache } = await import('../../js/src/model/peek-cache.js');

const document = (text) => `<!doctype html><body>${text}</body>`;

test('a face never peeked is a miss: undefined, nothing held', () => {
  const cache = createPeekCache();
  assert.equal(cache.get('blueprint:hero'), undefined);
  assert.equal(cache.size(), 0);
});

test('a put is a hit: the document comes back under its face', () => {
  const cache = createPeekCache();
  cache.put('blueprint:hero', document('Hero'));
  assert.equal(cache.get('blueprint:hero'), document('Hero'));
  // The key is the face alone: another face is still a miss.
  assert.equal(cache.get('system_powered_by_block'), undefined);
});

test('a put over a face replaces what was held, not beside it', () => {
  const cache = createPeekCache();
  cache.put('blueprint:hero', document('Hero'));
  cache.put('blueprint:hero', document('Hero, restyled'));
  assert.equal(cache.get('blueprint:hero'), document('Hero, restyled'));
  assert.equal(cache.size(), 1);
});

test('drop with a predicate sheds only the matching faces', () => {
  const cache = createPeekCache();
  cache.put('blueprint:hero', document('Hero'));
  cache.put('blueprint:card', document('Card'));
  cache.put('system_powered_by_block', document('Powered by'));
  cache.put('field_block:node:page:title', document('Title'));
  const dropped = cache.drop((faceId) => faceId.startsWith('blueprint:'));
  assert.equal(dropped, 2);
  assert.equal(cache.get('blueprint:hero'), undefined);
  assert.equal(cache.get('blueprint:card'), undefined);
  assert.equal(cache.get('system_powered_by_block'), document('Powered by'));
  assert.equal(cache.get('field_block:node:page:title'), document('Title'));
  assert.equal(cache.size(), 2);
  // A predicate matching nothing sheds nothing.
  assert.equal(cache.drop((faceId) => faceId.startsWith('blueprint:')), 0);
  assert.equal(cache.size(), 2);
});

test('clear empties: every face is a miss again', () => {
  const cache = createPeekCache();
  cache.put('blueprint:hero', document('Hero'));
  cache.put('system_powered_by_block', document('Powered by'));
  cache.clear();
  assert.equal(cache.size(), 0);
  assert.equal(cache.get('blueprint:hero'), undefined);
  assert.equal(cache.get('system_powered_by_block'), undefined);
  // Clearing an empty cache is a harmless no, and it can be filled again.
  cache.clear();
  cache.put('blueprint:hero', document('Hero'));
  assert.equal(cache.get('blueprint:hero'), document('Hero'));
});

test('size counts the faces held, through puts, replacements and drops', () => {
  const cache = createPeekCache();
  assert.equal(cache.size(), 0);
  cache.put('a', document('A'));
  assert.equal(cache.size(), 1);
  cache.put('b', document('B'));
  assert.equal(cache.size(), 2);
  cache.put('a', document('A again'));
  assert.equal(cache.size(), 2);
  cache.drop((faceId) => faceId === 'a');
  assert.equal(cache.size(), 1);
  cache.clear();
  assert.equal(cache.size(), 0);
});
