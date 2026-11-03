/**
 * Self-asserting tests for the chat interceptor installer
 * (js/daedalus-ai-interceptors.js).
 *
 * ai_chatbot assigns the <deep-chat> element's interceptors inside its own
 * Drupal.aiDeepchat.whenNeeded().then(), after behaviors return, so a
 * wrapper installed earlier wraps nothing and is overwritten. The helper
 * is the one road that waits for that promise and refuses to wrap nothing;
 * these cases pin the precondition (the throw) and the wrap (the build
 * receives ai's function, the element carries the result). The install
 * order itself is a browser fact pinned by DaedalusAiBehaviorTest.
 *
 * The file is a plain script hanging its API off the Drupal global, so the
 * suite evaluates it with a fake Drupal in scope, whenNeeded stubbed.
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
  join(dirname(fileURLToPath(import.meta.url)), '../../js/daedalus-ai-interceptors.js'),
  'utf8',
);

/**
 * Loads the script against a fake Drupal whose whenNeeded resolves at once.
 *
 * @returns {object}
 *   The fake Drupal, with Drupal.Daedalus.AiInterceptors attached and the
 *   elements whenNeeded was asked about under Drupal.aiDeepchat.asked.
 */
const load = () => {
  const asked = [];
  const Drupal = {
    Daedalus: {},
    aiDeepchat: {
      asked,
      whenNeeded: (element) => {
        asked.push(element);
        return Promise.resolve();
      },
    },
  };
  // eslint-disable-next-line no-new-func
  new Function('Drupal', source)(Drupal);
  return Drupal;
};

test('install rejects, naming the property, when the element has no interceptor to wrap', async () => {
  const Drupal = load();
  const element = {};
  let built = false;
  await assert.rejects(
    Drupal.Daedalus.AiInterceptors.install(element, 'responseInterceptor', () => {
      built = true;
      return () => {};
    }),
    { name: 'Error', message: /responseInterceptor/ },
  );
  assert.equal(built, false, 'nothing is built when there is nothing to wrap');
  assert.equal('responseInterceptor' in element, false, 'the element is left untouched');
});

test('install waits for whenNeeded on the element, hands the build ai\'s interceptor and puts the result on the element', async () => {
  const Drupal = load();
  const existing = (response) => ({ ...response, seen: 'ai' });
  const element = { responseInterceptor: existing };
  const received = [];
  const wrapper = (response) => existing({ ...response, seen: 'daedalus' });
  const result = Drupal.Daedalus.AiInterceptors.install(element, 'responseInterceptor', (given) => {
    received.push(given);
    return wrapper;
  });
  assert.equal(typeof result.then, 'function', 'install returns the promise');
  await result;
  assert.deepEqual(Drupal.aiDeepchat.asked, [element]);
  assert.deepEqual(received, [existing], 'the build receives the interceptor ai assigned');
  assert.equal(element.responseInterceptor, wrapper, 'the element carries the build\'s result');
});

test('the wrap happens after whenNeeded resolves, never before', async () => {
  const Drupal = load();
  let settle;
  Drupal.aiDeepchat.whenNeeded = () => new Promise((resolve) => {
    settle = resolve;
  });
  const element = {};
  const wrapper = () => {};
  const result = Drupal.Daedalus.AiInterceptors.install(element, 'requestInterceptor', () => wrapper);
  // ai_chatbot assigns its interceptor inside its own whenNeeded().then();
  // on the stub, that is any time before the promise settles.
  element.requestInterceptor = () => {};
  settle();
  await result;
  assert.equal(element.requestInterceptor, wrapper);
});
