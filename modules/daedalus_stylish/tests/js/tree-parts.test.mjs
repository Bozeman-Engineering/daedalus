/**
 * Self-asserting tests for the part behavior's resolution seam and the
 * canvas part paint riding it: selectorFor's scoped-rule grammar (dual
 * selector, container donut, key escaping), resolve's non-Element
 * degradation and its walk under the registry's injected placement gate
 * (a hoisted part the model does not place resolves nothing, a nested one
 * resolves, instance carriers gate the same way), and the paint adapter's
 * facet rendering (hover and selection tags built through selectorFor,
 * hover yielding to the selection on the same part).
 *
 * Run directly (`node tests/js/tree-parts.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 *
 * The paint adapter touches the DOM only through its <style> tags, so a
 * few-line stub suffices. resolve's walk uses closest(), contains(),
 * classList, getAttribute and parentElement over class and attribute
 * selectors only, so a small fake element tree drives it; the browser
 * seam (annotation -> index -> registry predicate -> this walk) is FJS's.
 */
import { strict as assert } from 'node:assert';

// The paint adapter mints a <style> tag per painted facet; the stubs
// record what it appends.
const appended = [];
globalThis.document = {
  head: { appendChild: (tag) => appended.push(tag) },
  createElement: () => {
    const tag = {
      isConnected: true,
      textContent: '',
      attributes: {},
      setAttribute(name, value) {
        this.attributes[name] = value;
      },
      remove() {
        this.removed = true;
      },
    };
    return tag;
  },
};

/**
 * The fake canvas element: exactly the DOM surface resolve's walk reads.
 * Selectors are the two shapes the walk uses, `.class` and `[attr]`,
 * optionally comma-separated.
 */
class FakeElement {
  constructor(tag, { classes = [], attributes = {} } = {}) {
    this.tag = tag;
    this.classList = [...classes];
    this.attributes = { ...attributes };
    this.parentElement = null;
    this.children = [];
  }

  append(...children) {
    for (const child of children) {
      child.parentElement = this;
      this.children.push(child);
    }
    return this;
  }

  getAttribute(name) {
    return this.attributes[name] ?? null;
  }

  matches(selector) {
    return selector.split(',').some((simple) => {
      const one = simple.trim();
      if (one.startsWith('.')) {
        return this.classList.includes(one.slice(1));
      }
      const attribute = one.match(/^\[([^\]=]+)\]$/);
      if (attribute) {
        return attribute[1] in this.attributes;
      }
      throw new Error(`FakeElement.matches: unsupported selector ${one}`);
    });
  }

  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) {
        return node;
      }
    }
    return null;
  }

  contains(node) {
    for (let cursor = node; cursor; cursor = cursor.parentElement) {
      if (cursor === this) {
        return true;
      }
    }
    return false;
  }
}
globalThis.Element = FakeElement;
globalThis.Drupal = { t: (text) => text };

const { createPartBehavior } = await import('../../js/src/tree-parts.js');
const { createCanvasPartPaint } = await import('../../js/src/canvas-part-paint.js');
const { CONTAINER_SCOPE_LIMIT } = await import('../../js/src/printer.js');

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';
const BLOCK = `block:${UUID_A}`;
const CONTAINER = `container:${UUID_B}`;
const IMAGE_PART = '.image-block-image';

const behavior = createPartBehavior({
  source: {
    getBlockTypes: () => ({ [UUID_A]: 'image' }),
    getSectionLayouts: () => ({}),
  },
  guide: {
    kinds: {},
    blockTypes: {
      image: {
        elements: {
          [IMAGE_PART]: { label: 'Image' },
        },
      },
    },
  },
});

// A canvas: the main entity wrapper around one image block root. The
// unlinked form hoists the part class onto the root <img> itself (the
// server's ElementResolver hoisting rule); the linked form nests the
// picture under an anchor. Returns the pointer target of each.
const el = (tag, options) => new FakeElement(tag, options);
const hoistedCanvas = () => {
  const root = el('img', { classes: ['image-block-image'], attributes: { 'data-selectable': BLOCK } });
  el('div', { attributes: { 'data-main-entity': '' } }).append(root);
  return root;
};
const nestedCanvas = () => {
  const img = el('img', { classes: ['image-block-image'] });
  const root = el('div', { attributes: { 'data-selectable': BLOCK } }).append(el('a').append(img));
  el('div', { attributes: { 'data-main-entity': '' } }).append(root);
  return img;
};
const carrierCanvas = () => {
  const li = el('li', { classes: ['s-cd'] });
  const root = el('div', { attributes: { 'data-selectable': BLOCK } }).append(el('ul', { classes: ['s-ab'] }).append(li));
  el('div', { attributes: { 'data-main-entity': '' } }).append(root);
  return li;
};
const presented = () => true;
const placedOnly = (...addresses) => (key, id) => addresses.includes(`${key}::${id}`);

// A minimal spine stub: captured subscriptions the tests fire by hand,
// and facade getters reading the stub's committed pair.
const spine = { selected: { key: null, part: null }, hoverListener: null, partListener: null };
const app = {
  Selection: {
    getSelected: () => spine.selected.key,
    getSelectedPart: () => spine.selected.part,
  },
  onHoverPartChange: (callback) => {
    spine.hoverListener = callback;
  },
  onPartChange: (callback) => {
    spine.partListener = callback;
  },
};
createCanvasPartPaint({ app, behavior });
const hoverTo = (hoverPart) => spine.hoverListener(hoverPart);
const selectTo = (key, part) => {
  spine.selected = { key, part };
  spine.partListener({ key, part });
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('selectorFor scopes a block-owned part with the printers\' dual selector', () => {
  const rule = behavior.selectorFor(BLOCK, '.quote', 'outline: 1px solid red;');
  assert.equal(
    rule,
    `@scope ([data-selectable="${BLOCK}"]) {\n  :scope.quote, .quote {\n    outline: 1px solid red;\n  }\n}`,
  );
});

test('a container-owned part takes the container donut limit', () => {
  const rule = behavior.selectorFor(CONTAINER, '.section__inner', 'color: red;');
  assert.ok(rule.startsWith(`@scope ([data-selectable="${CONTAINER}"]) to (${CONTAINER_SCOPE_LIMIT}) {`));
  assert.ok(rule.includes(':scope.section__inner, .section__inner'));
});

test('instance parts build rules like declared parts', () => {
  const rule = behavior.selectorFor(BLOCK, '.s-a1b2', 'color: red;');
  assert.ok(rule.includes(':scope.s-a1b2, .s-a1b2'));
});

test('a non-part id builds nothing', () => {
  assert.equal(behavior.selectorFor(BLOCK, 'div > .loose', 'color: red;'), null);
  assert.equal(behavior.selectorFor(BLOCK, 'quote', 'color: red;'), null);
  assert.equal(behavior.selectorFor(BLOCK, '.two.classes', 'color: red;'), null);
});

test('the scope attribute value escapes quote and backslash', () => {
  const rule = behavior.selectorFor('bad"key\\', '.x', '');
  assert.ok(rule.includes('[data-selectable="bad\\"key\\\\"]'));
});

test('resolve degrades to null off the DOM', () => {
  assert.equal(behavior.resolve(null), null);
  assert.equal(behavior.resolve('not an element'), null);
  assert.equal(behavior.resolve(undefined), null);
});

test('a nested part resolves when the model places it', () => {
  const img = nestedCanvas();
  assert.deepEqual(
    behavior.resolve(img, presented, placedOnly(`${BLOCK}::${IMAGE_PART}`)),
    { parentKey: BLOCK, id: IMAGE_PART, hit: img },
  );
  // The owner gate still stands in front of the placement gate.
  assert.equal(behavior.resolve(img, () => false, placedOnly(`${BLOCK}::${IMAGE_PART}`)), null);
});

test('a hoisted part the model does not place resolves nothing', () => {
  const root = hoistedCanvas();
  // The DOM alone cannot tell the hoisted part from a nested one:
  // closest() and self-inclusive contains() both land on the root, so an
  // ungated walk offers the part on its own root.
  assert.deepEqual(behavior.resolve(root), { parentKey: BLOCK, id: IMAGE_PART, hit: root });
  // The index has no `key::part` row for it, so the gate drops it and the
  // click falls to the block itself.
  assert.equal(behavior.resolve(root, presented, placedOnly()), null);
});

test('instance carriers gate on placement; the deepest placed carrier wins', () => {
  const li = carrierCanvas();
  const ul = li.parentElement;
  assert.deepEqual(
    behavior.resolve(li, presented, placedOnly(`${BLOCK}::.s-ab`, `${BLOCK}::.s-cd`)),
    { parentKey: BLOCK, id: '.s-cd', hit: li },
  );
  // A carrier the model does not place is passed over, not a dead end.
  assert.deepEqual(
    behavior.resolve(li, presented, placedOnly(`${BLOCK}::.s-ab`)),
    { parentKey: BLOCK, id: '.s-ab', hit: ul },
  );
  assert.equal(behavior.resolve(li, presented, placedOnly()), null);
});

test('the hover facet paints through selectorFor byte for byte', () => {
  hoverTo({ key: BLOCK, part: '.quote' });
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-part-paint'], 'hover');
  assert.ok(tag.textContent.startsWith(`@scope ([data-selectable="${BLOCK}"])`));
  assert.ok(tag.textContent.includes('dashed'));
  assert.ok(tag.textContent.includes('--daedalus-selection-muted'));
  hoverTo(null);
  assert.ok(tag.removed);
});

test('the selection facet paints its own tag in the selection color', () => {
  selectTo(BLOCK, '.quote');
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-part-paint'], 'selected');
  assert.ok(tag.textContent.includes('var(--daedalus-selection,'));
  assert.ok(!tag.textContent.includes('--daedalus-selection-muted'));
  selectTo(BLOCK, null);
  assert.ok(tag.removed);
});

test('hovering the selected part paints no hover box over the selection', () => {
  selectTo(BLOCK, '.quote');
  const before = appended.length;
  hoverTo({ key: BLOCK, part: '.quote' });
  assert.equal(appended.length, before);
  // Deselecting while the hover stands re-paints the hover box.
  selectTo(BLOCK, null);
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-part-paint'], 'hover');
  hoverTo(null);
  assert.ok(tag.removed);
});

test('a non-part hover facet injects no tag', () => {
  const before = appended.length;
  hoverTo({ key: BLOCK, part: 'div > .loose' });
  assert.equal(appended.length, before);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    await run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} tree-parts tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} tree-parts tests passed.`);
