/**
 * Self-asserting tests for the follow-up chat's request body
 * (js/daedalus-ai-followup-chat.js).
 *
 * The follow-up lane POSTs chat turns itself, outside deep-chat's own
 * request path, so it is the one place daedalus could spell ai_chatbot's
 * chat-turn request contract a second time. These cases lock that it does
 * not: the body is the element's connect.additionalBodyProps (the object
 * ChatSidebar::build() renders and ChatSidebarTest pins against ai_chatbot's
 * processor plugin) plus the thread id and the one user message, and loop()
 * sends exactly that body to connect.url.
 *
 * The file is a plain script hanging its API off the Drupal global, so the
 * suite evaluates it with a fake Drupal in scope.
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
  join(dirname(fileURLToPath(import.meta.url)), '../../js/daedalus-ai-followup-chat.js'),
  'utf8',
);

/**
 * Loads the script against a fake Drupal.
 *
 * @param {object} drupalSettings
 *   The drupalSettings the script sees.
 *
 * @returns {object}
 *   The fake Drupal, with Drupal.Daedalus.AiFollowup attached.
 */
const load = (drupalSettings = {}) => {
  const Drupal = {
    Daedalus: { logger: () => ({ log() {} }) },
    AjaxCommands: { prototype: {} },
    behaviors: { deepChatToggle: { processing: false, chats: [] } },
    t: (text) => text,
  };
  // eslint-disable-next-line no-new-func
  new Function('jQuery', 'Drupal', 'drupalSettings', 'once', source)({}, Drupal, drupalSettings, () => []);
  return Drupal;
};

/**
 * The connect object as the sidebar renders it (drupal/ai 1.5's shape).
 *
 * @returns {object}
 *   A fresh connect object.
 */
const connect = () => ({
  url: '/api/deepchat?token=abc',
  method: 'POST',
  additionalBodyProps: {
    chat_processor_plugin: 'ai_assistant_api_processor',
    plugin_configuration: {
      assistant_id: 'daedalus',
      stream_output: false,
      verbose_mode: true,
      show_structured_results: false,
    },
    stream: false,
    contexts: { current_route: '/node/5' },
  },
});

test('buildRequestBody spreads the connect body props and adds the thread id and the one user message', () => {
  const { Daedalus } = load();
  const body = Daedalus.AiFollowup.buildRequestBody(connect(), 'assistant_thread_2_0', 'Paint the hero');
  assert.deepEqual(body, {
    chat_processor_plugin: 'ai_assistant_api_processor',
    plugin_configuration: {
      assistant_id: 'daedalus',
      stream_output: false,
      verbose_mode: true,
      show_structured_results: false,
    },
    stream: false,
    contexts: { current_route: '/node/5' },
    thread_id: 'assistant_thread_2_0',
    messages: [{ role: 'user', text: 'Paint the hero' }],
  });
});

test('the body carries no pre-1.5 top-level key: the shape is the connect object\'s, never spelled here', () => {
  const { Daedalus } = load({
    ai_deepchat: {
      assistant_id: 'daedalus',
      thread_id: 'assistant_thread_2_0',
      show_copy_icon: true,
      structured_results: false,
      verbose_mode: true,
    },
  });
  const body = Daedalus.AiFollowup.buildRequestBody(connect(), 'assistant_thread_2_0', 'x');
  ['assistant_id', 'structured_results', 'show_copy_icon', 'verbose_mode'].forEach((key) => {
    assert.equal(key in body, false, `${key} rides only inside plugin_configuration, if at all`);
  });
  assert.equal(body.plugin_configuration.assistant_id, 'daedalus');
});

test('buildRequestBody is pure: the connect object is left as rendered', () => {
  const { Daedalus } = load();
  const given = connect();
  Daedalus.AiFollowup.buildRequestBody(given, 'assistant_thread_2_0', 'x');
  assert.deepEqual(given, connect());
});

test('a thread id ai_chatbot wrote into the connect yields to the live one the caller passes', () => {
  const { Daedalus } = load();
  const given = connect();
  given.additionalBodyProps.thread_id = 'assistant_thread_2_0';
  const body = Daedalus.AiFollowup.buildRequestBody(given, 'assistant_thread_2_1', 'x');
  assert.equal(body.thread_id, 'assistant_thread_2_1');
});

test('loop posts buildRequestBody\'s body to connect.url, dummy_loading on a continuation, and renders the final html', async () => {
  const Drupal = load({ ai_deepchat: { thread_id: 'assistant_thread_2_0' } });
  const { AiFollowup } = Drupal.Daedalus;
  const calls = [];
  const answers = [
    { should_continue: true, html: '<p>step</p>' },
    { should_continue: false, html: '<p>done</p>' },
  ];
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    calls.push({ url, init });
    const data = answers.shift();
    return { ok: true, json: async () => data };
  };
  const updates = [];
  let finished;
  const done = new Promise((resolve) => {
    finished = resolve;
  });
  const element = {
    connect: connect(),
    updateMessage: (message, index) => updates.push({ message, index }),
    disableSubmitButton: (disabled) => {
      if (!disabled) {
        finished();
      }
    },
  };
  try {
    AiFollowup.inProgress = true;
    AiFollowup.loop(element, { batchId: 'b1' }, 3, 'Paint the hero');
    await done;
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(calls.length, 2);
  calls.forEach(({ url, init }) => {
    assert.equal(url, '/api/deepchat?token=abc');
    assert.equal(init.method, 'POST');
    assert.equal(init.credentials, 'same-origin');
    assert.deepEqual(init.headers, { 'Content-Type': 'application/json' });
  });
  assert.deepEqual(
    JSON.parse(calls[0].init.body),
    AiFollowup.buildRequestBody(connect(), 'assistant_thread_2_0', 'Paint the hero'),
  );
  assert.deepEqual(
    JSON.parse(calls[1].init.body),
    AiFollowup.buildRequestBody(connect(), 'assistant_thread_2_0', 'dummy_loading'),
  );
  assert.deepEqual(updates[updates.length - 1], { message: { role: 'ai', html: '<p>done</p>' }, index: 3 });
  assert.equal(AiFollowup.inProgress, false);
  assert.equal(Drupal.behaviors.deepChatToggle.processing, false);
});
