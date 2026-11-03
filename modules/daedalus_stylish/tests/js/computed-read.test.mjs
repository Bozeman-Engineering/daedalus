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
import { lengthBases, pseudoOf, readComputed, resolveRoot, resolveTargets } from '../../js/src/computed-read.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const SECTION_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const SECTION_NESTED = 'bbbbbbbb-1111-2222-3333-444444444444';
const BLOCK_C = 'cccccccc-1111-2222-3333-444444444444';
const ENTITY_D = 'dddddddd-1111-2222-3333-444444444444';

// --- Fake DOM ---------------------------------------------------------------

/**
 * Matches one compound of the printed target grammar: `:scope`, `*`,
 * `.class`, [attr="value"], [attr^="prefix"], `:where(...)` and
 * `:is(a, b)` wrappers, glued compounds such as `:scope.cls`, and a bare
 * pseudo-class (`:hover`), which never matches: nothing on the fake
 * canvas is hovered, focused or active.
 */
const matchesCompound = (node, compound, scope) => {
  let rest = compound.trim();
  while (rest !== '') {
    if (rest === '*') {
      return true;
    }
    if (rest.startsWith(':scope')) {
      if (node !== scope) {
        return false;
      }
      rest = rest.slice(':scope'.length);
      continue;
    }
    if (/^:[a-z-]+(?![a-z(-])/.test(rest) && !/^:(where|is)\(/.test(rest)) {
      return false;
    }
    const nested = rest.match(/^:(where|is)\(/);
    if (nested) {
      // Peel one balanced group; its argument list (top-level commas)
      // matches when any argument does, and a glued remainder
      // (':scope:where(.cls)') continues after it.
      let depth = 0;
      let end = -1;
      for (let i = nested[0].length - 1; i < rest.length; i += 1) {
        if (rest[i] === '(') depth += 1;
        if (rest[i] === ')') {
          depth -= 1;
          if (depth === 0) {
            end = i;
            break;
          }
        }
      }
      const inner = rest.slice(nested[0].length, end);
      const args = [];
      let argDepth = 0;
      let current = '';
      for (const char of inner) {
        if (char === '(') argDepth += 1;
        if (char === ')') argDepth -= 1;
        if (char === ',' && argDepth === 0) {
          args.push(current);
          current = '';
        } else {
          current += char;
        }
      }
      args.push(current);
      if (!args.some((arg) => matchesCompound(node, arg, scope))) {
        return false;
      }
      rest = rest.slice(end + 1);
      continue;
    }
    if (rest.startsWith('.')) {
      const name = rest.match(/^\.[A-Za-z0-9_-]+/)[0];
      if (!node.classes.includes(name.slice(1))) {
        return false;
      }
      rest = rest.slice(name.length);
      continue;
    }
    const prefix = rest.match(/^\[([^\]^=]+)\^="([^"]*)"\]/);
    if (prefix) {
      if (!(node.attributes[prefix[1]] ?? '').startsWith(prefix[2])) {
        return false;
      }
      rest = rest.slice(prefix[0].length);
      continue;
    }
    const exact = rest.match(/^\[([^\]^=]+)="([^"]*)"\]/);
    if (exact) {
      if (node.attributes[exact[1]] !== exact[2]) {
        return false;
      }
      rest = rest.slice(exact[0].length);
      continue;
    }
    throw new Error(`Fake DOM cannot match selector: ${compound}`);
  };
  return true;
};

/**
 * Splits a complex selector into compounds with their combinators.
 */
const chainOf = (selector) => {
  const chain = [];
  let child = false;
  let depth = 0;
  let current = '';
  const flush = () => {
    if (current.trim() !== '') {
      chain.push({ child, compound: current.trim() });
      current = '';
      child = false;
    }
  };
  for (const char of selector) {
    if (char === '(') depth += 1;
    if (char === ')') depth -= 1;
    if (depth === 0 && char === '>') {
      flush();
      child = true;
      continue;
    }
    if (depth === 0 && /\s/.test(char)) {
      flush();
      continue;
    }
    current += char;
  }
  flush();
  return chain;
};

/**
 * Whether a node matches a complex selector, right to left, with `scope`
 * as the :scope root.
 */
const matchesChain = (node, chain, scope) => {
  const last = chain[chain.length - 1];
  if (!matchesCompound(node, last.compound, scope)) {
    return false;
  }
  if (chain.length === 1) {
    return true;
  }
  const rest = chain.slice(0, -1);
  if (last.child) {
    return node.parent ? matchesChain(node.parent, rest, scope) : false;
  }
  for (let ancestor = node.parent; ancestor; ancestor = ancestor.parent) {
    if (matchesChain(ancestor, rest, scope)) {
      return true;
    }
  }
  return false;
};

const matches = (node, selector, scope = node) =>
  selector.split(/,(?![^(]*\))/).some((one) => matchesChain(node, chainOf(one), scope));

const el = ({ selectable = null, element = null, classes = [], children = [] } = {}) => {
  const node = {
    classes,
    attributes: {
      ...(selectable === null ? {} : { 'data-selectable': selectable }),
      ...(element === null ? {} : { 'data-element': element }),
    },
    children,
    parent: null,
    parentElement: null,
    matches: (selector) => matches(node, selector),
    querySelector: (selector) => node.querySelectorAll(selector)[0] ?? null,
    querySelectorAll: (selector) => {
      const found = [];
      const walk = (parent) => parent.children.forEach((child) => {
        if (matches(child, selector, node)) {
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
    child.parentElement = node;
  });
  return node;
};

/**
 * The canvas: an entity root holding a section whose region carries an
 * own part match, a nested section (own root stamped, own part match), and a
 * block (own root stamped, own part match) — one .quote at every depth, so
 * the donut is the only thing separating the three.
 */
const buildCanvas = () => {
  const quoteInA = el({ classes: ['quote'] });
  const quoteInNested = el({ classes: ['quote'] });
  const quoteInBlock = el({ classes: ['quote'] });
  const block = el({ selectable: `block:${BLOCK_C}`, element: 'block_type:text', children: [quoteInBlock] });
  const nested = el({ selectable: `container:${SECTION_NESTED}`, element: 'layout:daedalus_stylish_container', children: [quoteInNested, block] });
  const section = el({ selectable: `container:${SECTION_A}`, element: 'layout:daedalus_stylish_container', children: [quoteInA, nested] });
  const canvas = el({ selectable: `entity:${ENTITY_D}`, element: 'kind:entity', children: [section] });
  return { canvas, section, nested, block, quoteInA, quoteInNested, quoteInBlock };
};

const resolveFrom = (canvas, key, selector = null, conditions = {}) => {
  globalThis.document = {
    querySelector: (selector) => (canvas.matches(selector) ? canvas : canvas.querySelector(selector)),
  };
  try {
    return resolveTargets(key, selector, conditions);
  }
  finally {
    delete globalThis.document;
  }
};

// The guide's condition table: hover and focus are posable states, a
// width band is not a pseudo-class at all.
const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width', band: { max: 767 } },
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
  const { canvas, section, nested, block } = buildCanvas();
  assert.equal(rootFrom(canvas, `container:${SECTION_A}`), section);
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

test('section, block, and entity keys resolve to their stamped roots', () => {
  const { canvas, section, block } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`), [section]);
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

test('a section-owned part stops at the nested section root (the donut)', () => {
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

test('a selector outside the grammar resolves to nothing', () => {
  const { canvas } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, 'div > > .quote'), []);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '.quote:has(:has(.x))'), []);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '::>'), []);
});

test('the children selector hits every direct child, nested roots included', () => {
  const { canvas, quoteInA, nested, quoteInNested, block } = buildCanvas();
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '> *'), [quoteInA, nested]);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_NESTED}`, '> *'), [quoteInNested, block]);
});

test('a type selector reaches by the element address, past nested roots', () => {
  const { canvas, nested, block } = buildCanvas();
  // Only the nested container is a direct child container of the section;
  // the text block sits one level down, reached by the descendant form.
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '> d-container'), [nested]);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '> d-text'), []);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, 'd-text'), [block]);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_NESTED}`, '> d-text'), [block]);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '> d-container > d-text'), [block]);
});

test('a part reaching by > shifts the donut: nested roots are its children', () => {
  const { canvas, section, nested, quoteInA } = buildCanvas();
  // The section wears the hoisted part class on its own root: the dual half.
  section.classes.push('region');
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '.region > *'), [quoteInA, nested]);
  // A part continuing by a descendant combinator reaches the whole subtree.
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '.region d-text').length, 1);
});

// --- The structural reach of a state-qualified rule -------------------------

test('a state qualifier resolves to the structural reach: the elements the rule hits once the state holds', () => {
  const { canvas, section, nested, block, quoteInA } = buildCanvas();
  const a = `container:${SECTION_A}`;
  // The reveal: `li:hover > ul`'s shape over the fixture. Nothing on the
  // canvas is hovered, and the rule's targets are still the text block
  // under the nested container, the elements the bands measure and the
  // pose stamps.
  assert.deepEqual(resolveFrom(canvas, a, '> d-container:hover > d-text', CONDITIONS), [block]);
  assert.deepEqual(resolveFrom(canvas, a, 'd-container:focus d-text', CONDITIONS), [block]);
  // The owner's own state is the owner; with a chain, the chain.
  assert.deepEqual(resolveFrom(canvas, a, '&:hover', CONDITIONS), [section]);
  assert.deepEqual(resolveFrom(canvas, a, '&:hover > d-container', CONDITIONS), [nested]);
  // A state the table does not publish is no state: it stays in the
  // print and hits only what really holds (nothing here).
  assert.deepEqual(resolveFrom(canvas, a, '> d-container:visited > d-text', CONDITIONS), []);
  // Without a table nothing is a state: the same rule hits nothing on an
  // unhovered canvas, as the delivered CSS would.
  assert.deepEqual(resolveFrom(canvas, a, '> d-container:hover > d-text'), []);
  assert.deepEqual(resolveFrom(canvas, a, '&:hover'), []);
  // A state whose compound is left with no address reaches nothing.
  assert.deepEqual(resolveFrom(canvas, a, '*:hover > d-text', CONDITIONS), []);
  // The donut still binds on the reach: the owner's part under its own
  // hover is the section's quote alone, never the nested roots'.
  assert.deepEqual(resolveFrom(canvas, a, '&:hover .quote', CONDITIONS), [quoteInA]);
});

// --- Generated boxes ---------------------------------------------------------

test('pseudoOf names the terminal box of a selector, null for an element target', () => {
  assert.equal(pseudoOf(null), null);
  assert.equal(pseudoOf(undefined), null);
  assert.equal(pseudoOf('.quote'), null);
  assert.equal(pseudoOf('> d-text'), null);
  assert.equal(pseudoOf('&::before'), 'before');
  assert.equal(pseudoOf('.quote::after'), 'after');
  assert.equal(pseudoOf('> d-text:has(a)::before'), 'before');
  // Any pseudo-element the browser spells is a box to name; outside the
  // grammar there is none (resolveTargets answers nothing for these; the
  // name is not a second verdict).
  assert.equal(pseudoOf('li::marker'), 'marker');
  assert.equal(pseudoOf('&::first-line'), 'first-line');
  assert.equal(pseudoOf('&::before::after'), null);
  assert.equal(pseudoOf('p ::before'), null);
  assert.equal(pseudoOf('::before'), null);
});

test('a generated box resolves to its hosts: the root for the anchor, every match for a part or type box', () => {
  const { canvas, section, block, quoteInA, quoteInBlock } = buildCanvas();
  // The anchor is the owner's own box: the host is the root itself.
  assert.deepEqual(resolveFrom(canvas, `block:${BLOCK_C}`, '&::before'), [block]);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '&::after'), [section]);
  // A part's box hosts on the part's matches, the donut holding exactly
  // as for the part.
  assert.deepEqual(resolveFrom(canvas, `block:${BLOCK_C}`, '.quote::after'), [quoteInBlock]);
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_A}`, '.quote::before'), [quoteInA]);
  // A type box hosts on the typed children.
  assert.deepEqual(resolveFrom(canvas, `container:${SECTION_NESTED}`, '> d-text::before'), [block]);
  // Any pseudo-element hosts on its matches; no host, no box; a pseudo
  // outside the grammar is no address.
  assert.deepEqual(resolveFrom(canvas, `block:${BLOCK_C}`, '&::marker'), [block]);
  assert.deepEqual(resolveFrom(canvas, `block:${'e'.repeat(8)}-1111-2222-3333-444444444444`, '&::before'), []);
  assert.deepEqual(resolveFrom(canvas, `block:${BLOCK_C}`, '&::before::after'), []);
});

test('readComputed reads a box through its host with the pseudo argument', () => {
  const calls = [];
  globalThis.getComputedStyle = (element, pseudo) => {
    calls.push([element, pseudo]);
    return { getPropertyValue: (property) => `${property}@${pseudo ?? 'element'}` };
  };
  const host = { id: 'host' };
  try {
    assert.deepEqual(readComputed(host, ['width'], 'before'), { width: 'width@::before' });
    assert.deepEqual(readComputed(host, ['width', 'content'], 'after'), { width: 'width@::after', content: 'content@::after' });
    assert.deepEqual(readComputed(host, ['width']), { width: 'width@element' });
    assert.deepEqual(readComputed(host, ['width'], null), { width: 'width@element' });
  }
  finally {
    delete globalThis.getComputedStyle;
  }
  assert.deepEqual(calls, [[host, '::before'], [host, '::after'], [host, null], [host, null]]);
});

test('lengthBases measures the root\'s font size for rem and the target\'s own for em, a box through its host', () => {
  const root = { id: 'root' };
  const host = { id: 'host' };
  const calls = [];
  globalThis.document = { documentElement: root };
  globalThis.getComputedStyle = (element, pseudo) => {
    calls.push([element, pseudo]);
    const size = element === root ? '20px' : pseudo === '::before' ? '10px' : '12px';
    return { getPropertyValue: (property) => (property === 'font-size' ? size : '') };
  };
  try {
    assert.deepEqual(lengthBases(host), { rootPx: 20, fontPx: 12 }, 'the page\'s root is what it measures, never an assumed 16');
    assert.deepEqual(lengthBases(host, 'before'), { rootPx: 20, fontPx: 10 }, 'a generated box\'s own font size, read with the pseudo argument');
    assert.deepEqual(lengthBases(host, null), { rootPx: 20, fontPx: 12 });
    globalThis.getComputedStyle = () => ({ getPropertyValue: () => '' });
    assert.deepEqual(lengthBases(host), { rootPx: null, fontPx: null }, 'an empty read (a detached node) measures nothing: null, not 0, so no conversion divides by it');
  }
  finally {
    delete globalThis.getComputedStyle;
    delete globalThis.document;
  }
  assert.deepEqual(calls.slice(0, 2), [[root, undefined], [host, null]], 'the root is read plain; the target with the pseudo argument like readComputed');
  assert.deepEqual(calls.slice(2, 4), [[root, undefined], [host, '::before']]);
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
