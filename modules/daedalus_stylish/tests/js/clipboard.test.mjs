/**
 * Self-asserting tests for the clipboard door (js/src/clipboard.js): the
 * one file that names navigator.clipboard, probed by presence at call
 * time, never throwing.
 *
 * The three roads: an absent API (no navigator at all, and the browser's
 * plain-http shape, a navigator without a clipboard) answers FALSE from
 * clipboardAvailable(), rejects both calls with the editor's sentence,
 * never calls the write's producer and never throws out of the call; the
 * writeText road (no ClipboardItem interface) writes the produced text
 * after the producer's promise resolves and passes the browser's refusal
 * through unphrased; the ClipboardItem road hands a promise-backed item
 * to clipboard.write synchronously, inside the gesture, before the
 * producer's promise resolves.
 *
 * Node defines `navigator` itself (a getter-only accessor without a
 * clipboard, which is the http-browser shape), so the fakes mount through
 * Object.defineProperty and the original descriptor is restored after.
 *
 * Run directly: `node tests/js/clipboard.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import {
  clipboardAvailable,
  clipboardUnavailableText,
  readClipboardText,
  writeClipboardText,
} from '../../js/src/clipboard.js';

const SENTENCE =
  'Copying and pasting CSS needs a secure (https) page. Open this site over https, or use the Copy styles and Paste styles shortcuts within the page.';
const CSS = '#aaaaaaaa-1111-2222-3333-444444444444 {\n  color: red;\n}\n';

// The door reads Drupal.t at call time; the spec's t records what it is
// asked for and answers it unchanged.
const translated = [];
globalThis.Drupal = {
  t: (text) => {
    translated.push(text);
    return text;
  },
};

const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');

/**
 * Mounts a navigator (or none) and a ClipboardItem (or none) for one case.
 */
const mount = ({ navigator, ClipboardItem }) => {
  if (navigator === undefined) {
    delete globalThis.navigator;
  }
  else {
    Object.defineProperty(globalThis, 'navigator', {
      value: navigator,
      configurable: true,
      writable: true,
    });
  }
  if (ClipboardItem === undefined) {
    delete globalThis.ClipboardItem;
  }
  else {
    globalThis.ClipboardItem = ClipboardItem;
  }
};

const restore = () => {
  if (originalNavigator) {
    Object.defineProperty(globalThis, 'navigator', originalNavigator);
  }
  else {
    delete globalThis.navigator;
  }
  delete globalThis.ClipboardItem;
};

/**
 * A producer that counts its calls and resolves the CSS.
 */
const producer = () => {
  const produce = () => {
    produce.calls += 1;
    return Promise.resolve(CSS);
  };
  produce.calls = 0;
  return produce;
};

/**
 * The absent-API contract, shared by the two absent shapes.
 */
const assertAbsent = async () => {
  assert.equal(clipboardAvailable(), false);
  // Neither call throws: each answers a promise synchronously.
  const read = readClipboardText();
  assert.ok(read instanceof Promise, 'readClipboardText answers a promise');
  await assert.rejects(read, (error) => {
    assert.ok(error instanceof Error);
    assert.equal(error.message, SENTENCE);
    return true;
  });
  const produce = producer();
  const written = writeClipboardText(produce);
  assert.ok(written instanceof Promise, 'writeClipboardText answers a promise');
  await assert.rejects(written, (error) => {
    assert.ok(error instanceof Error);
    assert.equal(error.message, SENTENCE);
    return true;
  });
  assert.equal(produce.calls, 0, 'the producer is never called');
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('the sentence goes through Drupal.t at call time', () => {
  translated.length = 0;
  assert.equal(clipboardUnavailableText(), SENTENCE);
  assert.deepEqual(translated, [SENTENCE]);
});

test('no navigator at all: unavailable, both calls reject with the sentence, nothing thrown', async () => {
  mount({ navigator: undefined, ClipboardItem: undefined });
  await assertAbsent();
});

test('a navigator without a clipboard (a plain-http page): the same refusal', async () => {
  mount({ navigator: { userAgent: 'spec' }, ClipboardItem: undefined });
  await assertAbsent();
});

test('a clipboard without the method (a partial stub) rejects instead of throwing', async () => {
  mount({ navigator: { clipboard: {} }, ClipboardItem: undefined });
  assert.equal(clipboardAvailable(), true);
  const read = readClipboardText();
  assert.ok(read instanceof Promise);
  await assert.rejects(read, TypeError);
  const produce = producer();
  const written = writeClipboardText(produce);
  assert.ok(written instanceof Promise);
  await assert.rejects(written, TypeError);
});

test('the writeText road: the produced text lands after the producer resolves', async () => {
  const clipboard = { writes: [] };
  clipboard.writeText = (text) => {
    clipboard.writes.push(text);
    return Promise.resolve();
  };
  mount({ navigator: { clipboard }, ClipboardItem: undefined });
  assert.equal(clipboardAvailable(), true);
  const produce = producer();
  const written = writeClipboardText(produce);
  // The producer runs inside the gesture, synchronously.
  assert.equal(produce.calls, 1);
  assert.deepEqual(clipboard.writes, [], 'nothing is written before the text exists');
  await written;
  assert.deepEqual(clipboard.writes, [CSS]);
  assert.equal(produce.calls, 1);
});

test('the writeText road passes the browser\'s own refusal through', async () => {
  const refusal = new Error('Write permission denied.');
  refusal.name = 'NotAllowedError';
  mount({
    navigator: { clipboard: { writeText: () => Promise.reject(refusal) } },
    ClipboardItem: undefined,
  });
  await assert.rejects(writeClipboardText(producer()), (error) => error === refusal);
});

test('the writeText road passes the producer\'s own failure through, unwritten', async () => {
  const failure = new Error('The print could not be read.');
  const clipboard = { writes: [] };
  clipboard.writeText = (text) => {
    clipboard.writes.push(text);
    return Promise.resolve();
  };
  mount({ navigator: { clipboard }, ClipboardItem: undefined });
  await assert.rejects(
    writeClipboardText(() => Promise.reject(failure)),
    (error) => error === failure,
  );
  assert.deepEqual(clipboard.writes, []);
});

test('the ClipboardItem road: a promise-backed item reaches write inside the gesture', async () => {
  class FakeClipboardItem {
    constructor(items) {
      this.items = items;
    }
  }
  const clipboard = { written: null, writeTextCalls: 0 };
  clipboard.write = (items) => {
    clipboard.written = items;
    return Promise.resolve();
  };
  clipboard.writeText = () => {
    clipboard.writeTextCalls += 1;
    return Promise.resolve();
  };
  mount({ navigator: { clipboard }, ClipboardItem: FakeClipboardItem });
  const produce = producer();
  const written = writeClipboardText(produce);
  // write is called synchronously, before the producer's promise resolves:
  // the item carries a promise for its data.
  assert.equal(produce.calls, 1);
  assert.ok(Array.isArray(clipboard.written), 'write received its items synchronously');
  assert.equal(clipboard.written.length, 1);
  const [item] = clipboard.written;
  assert.ok(item instanceof FakeClipboardItem);
  assert.ok(item.items['text/plain'] instanceof Promise, 'the item\'s data is a promise');
  await written;
  const blob = await item.items['text/plain'];
  assert.ok(blob instanceof Blob);
  assert.equal(blob.type, 'text/plain');
  assert.equal(await blob.text(), CSS);
  assert.equal(clipboard.writeTextCalls, 0, 'writeText is the other road');
});

test('the ClipboardItem road passes the browser\'s refusal through', async () => {
  class FakeClipboardItem {
    constructor(items) {
      this.items = items;
    }
  }
  const refusal = new Error('Write permission denied.');
  refusal.name = 'NotAllowedError';
  mount({
    navigator: { clipboard: { write: () => Promise.reject(refusal) } },
    ClipboardItem: FakeClipboardItem,
  });
  await assert.rejects(writeClipboardText(producer()), (error) => error === refusal);
});

test('readClipboardText answers the clipboard\'s text, and its refusal', async () => {
  mount({
    navigator: { clipboard: { readText: () => Promise.resolve(CSS) } },
    ClipboardItem: undefined,
  });
  assert.equal(await readClipboardText(), CSS);
  const refusal = new Error('Read permission denied.');
  refusal.name = 'NotAllowedError';
  mount({
    navigator: { clipboard: { readText: () => Promise.reject(refusal) } },
    ClipboardItem: undefined,
  });
  await assert.rejects(readClipboardText(), (error) => error === refusal);
});

test('the globals are restored after the cases', () => {
  restore();
  assert.deepEqual(
    Object.getOwnPropertyDescriptor(globalThis, 'navigator'),
    originalNavigator,
  );
  assert.equal(typeof ClipboardItem, 'undefined');
  // Node's own navigator has no clipboard: the plain-http shape, unmounted.
  assert.equal(clipboardAvailable(), false);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    await run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.message ?? error);
  }
}
restore();
if (failures > 0) {
  console.error(`${failures} of ${tests.length} clipboard tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} clipboard tests passed.`);
