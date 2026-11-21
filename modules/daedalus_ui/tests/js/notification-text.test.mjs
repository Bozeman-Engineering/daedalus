/**
 * Self-asserting tests for the notification display's text contract.
 *
 * A message is text from the caller to the screen. Three places hold the
 * contract and each is run here as it ships:
 *
 * - js/messenger.js, the sink: the toast and the live-region announcement
 *   (core's Drupal.Message and Drupal.announce both write HTML) take the
 *   message escaped, and the persisted history keeps the text itself.
 * - js/sidebar/plugins/notifications-sidebar.js, the history's reader:
 *   every part of a stored entry it prints is escaped, since the entry
 *   comes out of browser storage.
 * - js/edit_mode/ops/failure-report.js, the op failure report: the
 *   server's message and hint are handed to the display as they are, so
 *   the one escape is the sink's and nothing is escaped twice.
 *
 * The messenger is a native module over the Drupal, document, window and
 * localStorage globals; the sidebar is a classic script evaluated with its
 * three arguments; the failure report is bundle source with webpack-style
 * extensionless imports, so a resolve hook stands in for its one import
 * (the selection resolver, which pulls the whole app store).
 *
 * Run with `node --test tests/js/notification-text.test.mjs` from the
 * daedalus root's module path, or `npm test`.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const js = join(here, '..', '..', 'js');

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.endsWith('/selection/resolver')) {
      return {
        url: 'data:text/javascript,export const elementForKey = () => null;',
        shortCircuit: true,
      };
    }
    return nextResolve(specifier, context);
  },
});

// Core's Drupal.checkPlain (web/core/misc/drupal.js), its five
// replacements verbatim, so the expected strings are what a browser shows.
const checkPlain = (text) =>
  text
    .toString()
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const MARKUP = '<img src="x" onerror="window.shown = 1"> "Tom & Jerry\'s"';
const ESCAPED =
  '&lt;img src=&quot;x&quot; onerror=&quot;window.shown = 1&quot;&gt; &quot;Tom &amp; Jerry&#39;s&quot;';

/**
 * Stands up the globals js/messenger.js runs over and imports it fresh.
 *
 * @return {Promise<{messenger: object, added: Array, announced: Array}>}
 *   The messenger and what core's toast and announcer were handed. A
 *   toast core was asked to add counts as showing, so the same message
 *   again is a repeat.
 */
const bootMessenger = async () => {
  const added = [];
  const announced = [];
  const showing = new Set();
  const stored = new Map();

  const toast = (id) => ({
    getAttribute: () => id,
    classList: { add() {}, remove() {} },
    getBoundingClientRect: () => ({}),
    scrollIntoView() {},
    remove() {},
  });
  const body = {};
  const wrapper = { classList: { add() {} }, parentElement: body };
  const list = {
    parentElement: wrapper,
    querySelectorAll: () => [...showing].map(toast),
    querySelector: (selector) => {
      const id = /data-drupal-message-id="([^"]*)"/.exec(selector)[1];
      return toast(id.replace(/\\/g, ''));
    },
  };

  class Message {
    add(text, options) {
      added.push({ text, options });
      showing.add(options.id);
    }

    static defaultWrapper() {
      return list;
    }

    static announce(text, options) {
      announced.push({ text, options });
    }
  }

  globalThis.Drupal = { checkPlain, Message };
  globalThis.document = { body, querySelector: () => null };
  globalThis.CSS = { escape: (value) => value };
  globalThis.localStorage = {
    getItem: (key) => (stored.has(key) ? stored.get(key) : null),
    setItem: (key, value) => stored.set(key, value),
  };
  globalThis.StorageEvent = class {
    constructor(type, init) {
      Object.assign(this, { type }, init);
    }
  };
  globalThis.window = {
    location: { href: 'http://example.test/' },
    dispatchEvent() {},
  };

  // A fresh module instance per boot: the query makes the URL new.
  await import(`../../js/messenger.js?boot=${Math.random()}`);
  return { messenger: globalThis.Drupal.Daedalus.messenger, added, announced };
};

const tearDown = () => {
  ['Drupal', 'document', 'CSS', 'localStorage', 'StorageEvent', 'window'].forEach(
    (name) => delete globalThis[name],
  );
};

test('the messenger shows a message as text and keeps the text in its history', async () => {
  const { messenger, added, announced } = await bootMessenger();
  try {
    messenger.message(MARKUP, 'error', { duration: -1 });

    assert.equal(added.length, 1);
    assert.equal(added[0].text, ESCAPED, 'the toast is handed the escaped text');
    assert.equal(added[0].options.type, 'error');

    const history = messenger.history();
    assert.equal(history.length, 1);
    assert.equal(history[0].message, MARKUP, 'the history holds the text itself, escaped by whoever prints it');

    // The same message again is a repeat: restarted and announced, and
    // the announcement (a live region written as HTML) is escaped too.
    messenger.message(MARKUP, 'error', { duration: -1 });
    assert.equal(added.length, 1, 'a repeat adds no second toast');
    assert.deepEqual(announced, [{ text: ESCAPED, options: { type: 'error' } }]);
  } finally {
    tearDown();
  }
});

test('server display markup reaches the toast; the announcement and the history keep the text', async () => {
  const { messenger, added, announced } = await bootMessenger();
  try {
    const html = 'Saved. <a href="/node/1">View</a>';
    messenger.message('Saved. View', 'status', { duration: -1, html });

    assert.equal(added[0].text, html, 'the toast is handed the markup the server filtered');
    assert.equal(messenger.history()[0].message, 'Saved. View', 'the history holds the text');

    messenger.message('Saved. View', 'status', { duration: -1, html });
    assert.equal(added.length, 1, 'the same text is the same message');
    assert.deepEqual(announced, [{ text: 'Saved. View', options: { type: 'status' } }]);
  } finally {
    tearDown();
  }
});

test('a plain sentence reaches the toast unchanged', async () => {
  const { messenger, added } = await bootMessenger();
  try {
    messenger.message('Saving failed.', 'error', { duration: -1 });
    assert.equal(added[0].text, 'Saving failed.');
  } finally {
    tearDown();
  }
});

test('the notifications panel escapes every part of a stored entry it prints', () => {
  const source = readFileSync(
    join(js, 'sidebar', 'plugins', 'notifications-sidebar.js'),
    'utf8',
  );
  const Drupal = {
    checkPlain,
    t: (text) => text,
    theme: {},
    behaviors: {},
    Daedalus: {
      DefaultSidebar: class {},
      SidebarManager: { registerPlugin() {} },
    },
  };
  const window = { addEventListener() {} };
  // The file is `((..) => {..})(jQuery, Drupal, once);`.
  new Function('jQuery', 'Drupal', 'once', 'window', source)(
    null,
    Drupal,
    () => [],
    window,
  );

  const html = Drupal.theme.notificationItem(
    {
      message: MARKUP,
      type: 'error"><script>window.shown = 2</script>',
      timestamp: 0,
      target: 'key"><b>',
    },
    'Just <now>',
  );

  assert.ok(html.includes(ESCAPED), 'the message is printed escaped');
  assert.ok(!html.includes('<img'), 'no element comes out of the message');
  assert.ok(!html.includes('<script'), 'no element comes out of the type');
  assert.ok(!html.includes('<b>'), 'no element comes out of the target');
  assert.ok(html.includes('Just &lt;now&gt;'), 'the time is printed escaped');
  assert.ok(
    html.includes('notification-item--error&quot;&gt;'),
    'the type cannot close the class attribute',
  );
});

test('the op failure report hands the server\'s words to the display as text', async () => {
  const shown = [];
  globalThis.Drupal = {
    formatPlural: (count, one, many) =>
      count === 1 ? one : many.replace('@count', count),
    Daedalus: {
      ModeManager: {
        getPlugin: () => ({
          message: (text, type, options, key) =>
            shown.push({ text, type, options, key }),
        }),
      },
    },
  };
  try {
    const { reportMutationFailure } = await import(
      '../../js/edit_mode/ops/failure-report.js'
    );

    const error = new Error('fallback');
    error.payload = {
      errors: [{ message: `No block named ${MARKUP}.`, hint: 'Try <b>another</b>.' }],
    };
    error.abandonedCount = 2;
    reportMutationFailure(error, 'block:1');

    assert.deepEqual(shown, [
      {
        text: `No block named ${MARKUP}. Try <b>another</b>. 2 follow-on changes were undone.`,
        type: 'error',
        options: {},
        key: 'block:1',
      },
    ]);

    // A quiet tail rejection shows nothing.
    const tail = new Error('abandoned');
    tail.abandoned = true;
    reportMutationFailure(tail, 'block:2');
    assert.equal(shown.length, 1);
  } finally {
    delete globalThis.Drupal;
  }
});
