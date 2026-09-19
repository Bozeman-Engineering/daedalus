/**
 * Canvas target resolution (computed-read.js resolveTargets), self-asserting
 * under plain node: run `node tests/js/computed-read.test.mjs`, exit code is
 * the failure.
 *
 * Pins the embedded-model contract: a selectable key — a NESTED section's
 * included — is stamped on the canvas verbatim and IS the @scope root, so
 * resolution needs no scope-key translation. Also pins the donut boundary:
 * a section-owned part never matches inside a nested section or block root,
 * exactly as the printed CSS cannot reach there.
 *
 * The module touches the DOM only through a narrow surface (querySelector,
 * querySelectorAll, closest, contains), so the tests run against fake nodes
 * implementing exactly that surface, with document stubbed per read — the
 * layout-context.test.mjs precedent.
 */
import { strict as assert } from 'node:assert';
import { resolveRoot, resolveTargets } from '../../js/src/computed-read.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const SECTION_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const SECTION_NESTED = 'bbbbbbbb-1111-2222-3333-444444444444';
const BLOCK_C = 'cccccccc-1111-2222-3333-444444444444';
const ENTITY_D = 'dddddddd-1111-2222-3333-444444444444';

// --- Fake DOM ---------------------------------------------------------------

/**
 * Matches the selector grammar this module actually uses: `.class`,
 * [attr="value"] and [attr^="prefix"], comma-separated.
 */
const matchesOne = (node, selector) => {
  const trimmed = selector.trim();
  if (trimmed.startsWith('.')) {
    return node.classes.includes(trimmed.slice(1));
  }
  const prefix = trimmed.match(/^\[([^\]^=]+)\^="([^"]*)"\]$/);
  if (prefix) {
    return (node.attributes[prefix[1]] ?? '').startsWith(prefix[2]);
  }
  const exact = trimmed.match(/^\[([^\]^=]+)="([^"]*)"\]$/);
  if (exact) {
    return node.attributes[exact[1]] === exact[2];
  }
  throw new Error(`Fake DOM cannot match selector: ${trimmed}`);
};

const matches = (node, selector) => selector.split(',').some((one) => matchesOne(node, one));

const el = ({ selectable = null, classes = [], children = [] } = {}) => {
  const node = {
    classes,
    attributes: selectable === null ? {} : { 'data-selectable': selectable },
    children,
    parent: null,
    matches: (selector) => matches(node, selector),
    querySelector: (selector) => node.querySelectorAll(selector)[0] ?? null,
    querySelectorAll: (selector) => {
      const found = [];
      const walk = (parent) => parent.children.forEach((child) => {
        if (matches(child, selector)) {
          found.push(child);
        }
        walk(child);
      });
      walk(node);
      return found;
    },
    closest: (selector) => {
      for (let current = node; current; current = current.parent) {
        if (matches(current, selector)) {
          return current;
        }
      }
      return null;
    },
    contains: (other) => {
      for (let current = other; current; current = current.parent) {
        if (current === node) {
          return true;
        }
      }
      return false;
    },
  };
  children.forEach((child) => {
    child.parent = node;
  });
  return node;
};

/**
 * The canvas: an entity root holding a section band whose region carries an
 * own part match, a nested section (own root stamped, own part match), and a
 * block (own root stamped, own part match) — one .quote at every depth, so
 * the donut is the only thing separating the three.
 */
const buildCanvas = () => {
  const quoteInA = el({ classes: ['quote'] });
  const quoteInNested = el({ classes: ['quote'] });
  const quoteInBlock = el({ classes: ['quote'] });
  const block = el({ selectable: `block:${BLOCK_C}`, children: [quoteInBlock] });
  const nested = el({ selectable: `container:${SECTION_NESTED}`, children: [quoteInNested, block] });
  const band = el({ selectable: `container:${SECTION_A}`, children: [quoteInA, nested] });
  const canvas = el({ selectable: `entity:${ENTITY_D}`, children: [band] });
  return { canvas, band, nested, block, quoteInA, quoteInNested, quoteInBlock };
};

const resolveFrom = (canvas, key, sub = null) => {
  globalThis.document = {
    querySelector: (selector) => (canvas.matches(selector) ? canvas : canvas.querySelector(selector)),
  };
  try {
    return resolveTargets(key, sub);
  }
  finally {
    delete globalThis.document;
  }
};

// --- Root resolution --------------------------------------------------------

const rootFrom = (canvas, key) => {
  globalThis.document = {
    querySelector: (selector) => (canvas.matches(selector) ? canvas : canvas.querySelector(selector)),
  };
  try {
    return resolveRoot(key);
  }
  finally {
    delete globalThis.document;
  }
};

test('resolveRoot finds the stamped root for a key, null when absent', () => {
  const { canvas, band, nested, block } = buildCanvas();
  assert.equal(rootFrom(canvas, `container:${SECTION_A}`), band);
  assert.equal(rootFrom(canvas, `container:${SECTION_NESTED}`), nested);
  assert.equal(rootFrom(canvas, `block:${BLOCK_C}`), block);
  assert.equal(rootFrom(canvas, `entity:${ENTITY_D}`), canvas);
  assert.equal(rootFrom(canvas, `container:${'e'.repeat(8)}-1111-2222-3333-444444444444`), null);
});

test('resolveRoot guards non-string and empty keys without touching the DOM', () => {
  globalThis.document = {
    querySelector: () => {
      throw new Error('resolveRoot must not query for a guarded key');
    },
  };
  try {
    assert.equal(resolveRoot(''), null);
    assert.equal(resolveRoot(null), null);
    assert.equal(resolveRoot(undefined), null);
    assert.equal(resolveRoot(42), null);
  }
  finally {
    delete globalThis.document;
  }
});

test('resolveRoot escapes quotes and backslashes in the attribute value', () => {
  const seen = [];
  globalThis.document = {
    querySelector: (selector) => {
      seen.push(selector);
      return null;
    },
  };
  try {
    resolveRoot('a"b\\c');
  }
  finally {
    delete globalThis.document;
  }
  assert.deepEqual(seen, ['[data-selectable="a\\"b\\\\c"]']);
});

// --- Whole-element resolution -----------------------------------------------

test('a nested section key resolves to its own stamped root, no translation', () => {
  const { canvas, nested } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_NESTED}`), [nested]);
});

test('band, block, and entity keys resolve to their stamped roots', () => {
  const { canvas, band, block } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`), [band]);
  assert.deepEqual(resolveFrom(canvas, `block:${BLOCK_C}`), [block]);
  assert.deepEqual(resolveFrom(canvas, `entity:${ENTITY_D}`), [canvas]);
});

test('a key absent from the canvas resolves to nothing', () => {
  const { canvas } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${'e'.repeat(8)}-1111-2222-3333-444444444444`), []);
  assert.deepEqual(resolveFrom(canvas, ''), []);
  assert.deepEqual(resolveFrom(canvas, null), []);
});

// --- Part resolution and the donut boundary ----------------------------------

test('a band-owned part stops at the nested section root (the donut)', () => {
  const { canvas, quoteInA } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '.quote'), [quoteInA]);
});

test('a nested-section-owned part matches its own subtree, not its blocks', () => {
  const { canvas, quoteInNested } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_NESTED}`, '.quote'), [quoteInNested]);
});

test('a block-owned part is not donut-limited', () => {
  const { canvas, quoteInBlock } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `block:${BLOCK_C}`, '.quote'), [quoteInBlock]);
});

test('an illegal part selector resolves to nothing', () => {
  const { canvas } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, 'div .quote'), []);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '.quote>.x'), []);
});

// --- Runner -----------------------------------------------------------------

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} computed-read tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} computed-read tests passed.`);
