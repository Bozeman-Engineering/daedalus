/**
 * Self-asserting tests for the top bar's Save answer reader
 * (js/edit_mode/tool_manager/save-commit.js): a commit navigates to the
 * destination the server names, a refusal shows each of the server's
 * messages and navigates nowhere, and an answer that is not the route's
 * own JSON is reported and thrown.
 *
 * Run with `node --test tests/js/save-commit.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

globalThis.Drupal = {
  t: (string, args = {}) =>
    Object.entries(args).reduce(
      (text, [name, value]) => text.replace(name, value),
      string,
    ),
};

const { commitSave } =
  await import('../../js/edit_mode/tool_manager/save-commit.js');

const SAVE_URL = '/daedalus-edit/tempstore/save/node/1?destination=%2Fnode%2F1';

/**
 * The doors, recording what reaches them, over one canned answer.
 */
const doors = (response) => {
  const log = { posted: [], navigated: [], reported: [] };
  return {
    log,
    post: async (url) => {
      log.posted.push(url);
      return response;
    },
    navigate: (destination) => log.navigated.push(destination),
    report: (message) => log.reported.push(message),
  };
};

const json = (body, status) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

test('a commit navigates to the destination the server names', async () => {
  const door = doors(json({ destination: '/new-alias' }, 200));
  assert.equal(await commitSave(SAVE_URL, door), true);
  assert.deepEqual(door.log.posted, [SAVE_URL]);
  assert.deepEqual(door.log.navigated, ['/new-alias']);
  assert.deepEqual(door.log.reported, []);
});

test('a refusal shows every message and navigates nowhere', async () => {
  const messages = [
    'Title: This value should not be blank.',
    'Subtitle: This value is too long.',
  ];
  const door = doors(json({ reason: 'invalid', messages }, 422));
  assert.equal(await commitSave(SAVE_URL, door), false);
  assert.deepEqual(door.log.navigated, []);
  assert.deepEqual(door.log.reported, messages);
});

test('a page saved elsewhere is a refusal like any other', async () => {
  const door = doors(
    json({ reason: 'moved', messages: ['Page was saved elsewhere.'] }, 409),
  );
  assert.equal(await commitSave(SAVE_URL, door), false);
  assert.deepEqual(door.log.navigated, []);
  assert.deepEqual(door.log.reported, ['Page was saved elsewhere.']);
});

test("an answer that is not the route's JSON is reported and thrown", async () => {
  const door = doors(
    new Response('<html>Access denied</html>', {
      status: 403,
      headers: { 'Content-Type': 'text/html; charset=UTF-8' },
    }),
  );
  await assert.rejects(commitSave(SAVE_URL, door), /403/);
  assert.deepEqual(door.log.navigated, []);
  assert.equal(door.log.reported.length, 1);
  assert.match(door.log.reported[0], /403/);
});
