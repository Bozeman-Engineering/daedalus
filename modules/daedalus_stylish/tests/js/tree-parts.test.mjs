/**
 * Self-asserting tests for the part behavior's resolution seam and the
 * canvas selector paint riding it: selectorFor's scoped-rule grammar (dual
 * selector, container donut, key escaping), resolve's non-Element
 * degradation and its walk under the registry's injected placement gate
 * (a hoisted part the model does not place resolves nothing, a nested one
 * resolves, instance carriers gate the same way), matches' host-plus-box
 * answer, generated's delivered boxes, and the paint adapter's facet
 * rendering (hover and selection tags built through selectorFor, hover
 * yielding to the selection on the same part).
 *
 * Run directly (`node tests/js/tree-parts.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 *
 * The paint adapter touches the DOM only through its <style> tags, so a
 * few-line stub suffices. resolve's walk uses closest(), contains(),
 * classList, getAttribute and parentElement over class and attribute
 * selectors only, and matches' read (computed-read's resolveTargets)
 * adds `:scope`-glued compounds and a descendant querySelectorAll, so a
 * small fake element tree drives both; the browser seam (annotation ->
 * index -> registry predicate -> this walk) is FJS's.
 */
import { strict as assert } from 'node:assert';
import { roster, variantRecord } from './materials-fixture.mjs';

// The paint adapter mints a <style> tag per painted facet; the stubs
// record what it appends. matches resolves the owner's root through
// document.querySelector: a test seats a canvas root here for the read.
const appended = [];
const canvas = { root: null };
globalThis.document = {
  head: { appendChild: (tag) => appended.push(tag) },
  querySelector: (selector) => {
    if (!canvas.root) {
      return null;
    }
    return canvas.root.matches(selector)
      ? canvas.root
      : (canvas.root.querySelectorAll(selector)[0] ?? null);
  },
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
 * The fake canvas element: exactly the DOM surface resolve's walk and
 * matches' read use. Selectors are compounds of `:scope` (the element
 * itself, as the DOM's matches() reads it), `.class`, `[attr]` and
 * `[attr="value"]`, glued, optionally comma-separated; querySelectorAll
 * takes the one descendant shape resolveTargets issues (`:scope X`).
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
      let rest = simple.trim();
      while (rest !== '') {
        if (rest.startsWith(':scope')) {
          rest = rest.slice(':scope'.length);
          continue;
        }
        const cls = rest.match(/^\.[A-Za-z0-9_-]+/);
        if (cls) {
          if (!this.classList.includes(cls[0].slice(1))) {
            return false;
          }
          rest = rest.slice(cls[0].length);
          continue;
        }
        const attribute = rest.match(/^\[([^\]=]+)(?:="([^"]*)")?\]/);
        if (attribute) {
          const [, name, value] = attribute;
          if (!(name in this.attributes) || (value !== undefined && this.attributes[name] !== value)) {
            return false;
          }
          rest = rest.slice(attribute[0].length);
          continue;
        }
        throw new Error(`FakeElement.matches: unsupported selector ${rest}`);
      }
      return true;
    });
  }

  querySelectorAll(selector) {
    const rest = selector.replace(/^:scope\s+/, '');
    const found = [];
    const walk = (node) => {
      for (const child of node.children) {
        if (child.matches(rest)) {
          found.push(child);
        }
        walk(child);
      }
    };
    walk(this);
    return found;
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
const { createCanvasSelectorPaint } = await import('../../js/src/canvas-selector-paint.js');
const { CONTAINER_SCOPE_LIMIT } = await import('../../js/src/printer.js');
const { resolveTargets } = await import('../../js/src/computed-read.js');

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
// and facade getters reading the stub's committed selection, subject
// facet and write address.
const spine = { selected: null, target: null, address: null, hoverListener: null, targetListener: null };
const app = {
  Selection: {
    getSelected: () => spine.selected,
    getTarget: () => spine.target,
    getAddress: () => spine.address,
  },
  onHoverSelectorChange: (callback) => {
    spine.hoverListener = callback;
  },
  onTargetChange: (callback) => {
    spine.targetListener = callback;
  },
};
createCanvasSelectorPaint({ app, behavior });
const hoverTo = (hoverSelector) => spine.hoverListener(hoverSelector);
const fire = () =>
  spine.targetListener({ selected: spine.selected, target: spine.target, address: spine.address });
// A selection write, or a facet seat on the selected key: either drops
// the address (the slice's rule), the facet write keeps the selection.
const selectTo = (key, selector) => {
  spine.selected = key;
  spine.target = selector ? { key, selector } : null;
  spine.address = null;
  fire();
};
// A write address under the standing selection (the switcher's pick),
// fired the way the facade fires it: selection and facet unchanged.
const addressTo = (key, selector) => {
  spine.address = key === null ? null : { key, selector };
  fire();
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

test('matches is the DOM twin of selectorFor: computed-read\'s resolveTargets plus the box it addresses', () => {
  // The same host targets and donut answer both; a consumer that
  // measures the matches and then prints through selectorFor sees one
  // reach. A generated box has no node: its elements are the HOSTS (the
  // root for the anchor, every `.quote` for the part's box) and `pseudo`
  // names the box for the computed read; an element selector carries
  // null there.
  const quote = el('span', { classes: ['quote'] });
  const root = el('div', { attributes: { 'data-selectable': BLOCK } }).append(el('p').append(quote));
  el('div', { attributes: { 'data-main-entity': '' } }).append(root);
  canvas.root = root;
  try {
    assert.deepEqual(behavior.matches(BLOCK, '.quote'), { elements: [quote], pseudo: null });
    assert.deepEqual(behavior.matches(BLOCK, '.quote').elements, resolveTargets(BLOCK, '.quote'));
    assert.deepEqual(behavior.matches(BLOCK, '&::before'), { elements: [root], pseudo: 'before' });
    assert.deepEqual(behavior.matches(BLOCK, '.quote::after'), { elements: [quote], pseudo: 'after' });
    assert.deepEqual(behavior.matches(BLOCK, '.quote::after').elements, resolveTargets(BLOCK, '.quote::after'));
    // Outside the grammar: nothing, and no box either (`::marker` is not
    // an address here).
    assert.deepEqual(behavior.matches(BLOCK, 'div > .loose'), { elements: [], pseudo: null });
    assert.deepEqual(behavior.matches(BLOCK, '&::marker'), { elements: [], pseudo: null });
  }
  finally {
    canvas.root = null;
  }
  // Off the canvas the box is still named: the read has no host, the
  // consumer still knows what it asked for.
  assert.deepEqual(behavior.matches(BLOCK, '&::before'), { elements: [], pseudo: 'before' });
});

// The generated boxes: a behavior over a source carrying a map, a
// materials roster and a tree (the stack's inputs), the image block
// under a container.
const generatedWith = ({ map = {}, variants = [], tree = [] } = {}) =>
  createPartBehavior({
    source: {
      getBlockTypes: () => ({ [UUID_A]: 'image' }),
      getSectionLayouts: () => ({ [UUID_B]: 'daedalus_stylish_container' }),
      getStyleMap: () => map,
      getMaterials: () => roster(variants),
      getTree: () => tree,
    },
    guide: { kinds: {}, blockTypes: { image: { elements: {} } } },
  }).generated;
const BEFORE_ROW = { id: '&::before', pseudo: 'before', label: 'Before' };
const AFTER_ROW = { id: '&::after', pseudo: 'after', label: 'After' };

test('generated lists nothing where no layer delivers a box, and nothing for a key that owns none', () => {
  assert.deepEqual(generatedWith()(BLOCK), []);
  // Styles at the box without `content` are not a box: it exists iff
  // the cascade sets content.
  assert.deepEqual(generatedWith({ map: { [`${BLOCK}::&::before`]: { default: { width: '1rem' } } } })(BLOCK), []);
  // A key already carrying a selector owns no boxes of its own (a
  // pseudo-element is terminal); nor does a key outside the grammar.
  assert.deepEqual(generatedWith({ map: { [`${BLOCK}::&::before`]: { default: { content: '""' } } } })(`${BLOCK}::.quote`), []);
  assert.deepEqual(generatedWith()('nonsense'), []);
});

test('generated lists the owner\'s box born with content, in grammar order, with the row words', () => {
  assert.deepEqual(
    generatedWith({ map: { [`${BLOCK}::&::before`]: { default: { content: '""' } } } })(BLOCK),
    [BEFORE_ROW],
  );
  // Map order does not matter: before, then after.
  assert.deepEqual(
    generatedWith({
      map: {
        [`${BLOCK}::&::after`]: { default: { content: '""', color: 'red' } },
        [`${BLOCK}::&::before`]: { hover: { content: '""' } },
      },
    })(BLOCK),
    [BEFORE_ROW, AFTER_ROW],
  );
});

test('content none is no box, and a state that switches it on is one under that state', () => {
  assert.deepEqual(
    generatedWith({ map: { [`${BLOCK}::&::after`]: { default: { content: 'none', width: '1rem' } } } })(BLOCK),
    [],
  );
  assert.deepEqual(
    generatedWith({ map: { [`${BLOCK}::&::after`]: { default: { content: ' NONE ' }, hover: { content: '""' } } } })(BLOCK),
    [AFTER_ROW],
  );
});

test('a worn look\'s box fragment delivers a box the owner never wrote', () => {
  const glow = variantRecord('glow', {
    scope: 'block_type:image',
    value: {
      '': { default: { color: 'gold' } },
      '::&::after': { default: { content: '""', 'background-color': 'gold' } },
    },
  });
  const map = { [BLOCK]: { variants: [{ variant: 'glow', fragment: '' }] } };
  assert.deepEqual(generatedWith({ map, variants: [glow] })(BLOCK), [AFTER_ROW]);
  // The owner's own `content: none` at the box beats the fragment's.
  assert.deepEqual(
    generatedWith({ map: { ...map, [`${BLOCK}::&::after`]: { default: { content: 'none' } } }, variants: [glow] })(BLOCK),
    [],
  );
});

test('an ancestor rule ending in the box delivers it on every element it reaches', () => {
  const tree = [
    {
      kind: 'container',
      uuid: UUID_B,
      layout_id: 'daedalus_stylish_container',
      children: [{ kind: 'block', uuid: UUID_A, block_type: 'image' }],
    },
  ];
  const generated = generatedWith({
    tree,
    map: {
      [`${CONTAINER}::> pb-image::before`]: { default: { content: '""' } },
      // A rule at the other box without content styles nothing into
      // existence.
      [`${CONTAINER}::> pb-image::after`]: { default: { width: '2rem' } },
    },
  });
  assert.deepEqual(generated(BLOCK), [BEFORE_ROW]);
  // The container itself owns neither: the rule is its children's box.
  assert.deepEqual(generated(CONTAINER), []);
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

test('any relative selector builds the printers\' targets: children, types, tags', () => {
  // The children of a container: no donut (`>` self-limits), the
  // universal compound carries no weight, so `:scope` prints bare.
  const children = behavior.selectorFor(CONTAINER, '> *', 'outline: 1px solid red;');
  assert.equal(
    children,
    `@scope ([data-selectable="${CONTAINER}"]) {\n  :scope > * {\n    outline: 1px solid red;\n  }\n}`,
  );
  // A type compound selects by the element address stamp, the one
  // weighted link; a text block's face lights up, never the container's.
  const typed = behavior.selectorFor(CONTAINER, '> pb-text', 'color: red;');
  assert.equal(
    typed,
    `@scope ([data-selectable="${CONTAINER}"]) {\n  :where(:scope) > [data-element="block_type:text"] {\n    color: red;\n  }\n}`,
  );
  // A tag reaches the whole subtree (doctrine B: no donut for tags).
  const tag = behavior.selectorFor(CONTAINER, 'p', 'color: red;');
  assert.equal(tag, `@scope ([data-selectable="${CONTAINER}"]) {\n  p {\n    color: red;\n  }\n}`);
  // A part reaching by `>` keeps the child-shifted donut, as delivered.
  const shifted = behavior.selectorFor(CONTAINER, '.cols > *', 'gap: 0;');
  assert.ok(shifted.startsWith(`@scope ([data-selectable="${CONTAINER}"]) to (${CONTAINER_SCOPE_LIMIT.split(', ').map((m) => `${m} > *`).join(', ')}) {`));
  assert.ok(shifted.includes(':scope:where(.cols) > *'));
});

test('a selector outside the grammar builds nothing', () => {
  assert.equal(behavior.selectorFor(BLOCK, 'div > .loose', 'color: red;'), null);
  assert.equal(behavior.selectorFor(BLOCK, '.two.classes', 'color: red;'), null);
  assert.equal(behavior.selectorFor(BLOCK, 'a:hover', 'color: red;'), null);
  // The canonical spelling only: the facade canonicalizes before the
  // facet is seated, so a lenient spelling here is a caller bug.
  assert.equal(behavior.selectorFor(BLOCK, '>*', 'color: red;'), null);
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
  hoverTo({ key: BLOCK, selector: '.quote' });
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-selector-paint'], 'hover');
  assert.ok(tag.textContent.startsWith(`@scope ([data-selectable="${BLOCK}"])`));
  assert.ok(tag.textContent.includes('dashed'));
  assert.ok(tag.textContent.includes('--daedalus-selection-muted'));
  hoverTo(null);
  assert.ok(tag.removed);
});

test('the styling target paints its own tag in the selection color', () => {
  selectTo(BLOCK, '.quote');
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-selector-paint'], 'selected');
  assert.ok(tag.textContent.includes('var(--daedalus-selection,'));
  assert.ok(!tag.textContent.includes('--daedalus-selection-muted'));
  selectTo(BLOCK, null);
  assert.ok(tag.removed);
});

test('hovering the target\'s selector paints no hover box over the target', () => {
  selectTo(BLOCK, '.quote');
  const before = appended.length;
  hoverTo({ key: BLOCK, selector: '.quote' });
  assert.equal(appended.length, before);
  // Dropping the target while the hover stands re-paints the hover box.
  selectTo(BLOCK, null);
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-selector-paint'], 'hover');
  hoverTo(null);
  assert.ok(tag.removed);
});

test('a type target on a container paints every matching child', () => {
  // The done-when's shape: seating '> pb-text' on a container outlines
  // all its text blocks through the one printed target.
  selectTo(CONTAINER, '> pb-text');
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-selector-paint'], 'selected');
  assert.ok(tag.textContent.includes(':where(:scope) > [data-element="block_type:text"]'));
  assert.ok(!tag.textContent.includes(' to ('));
  selectTo(CONTAINER, null);
  assert.ok(tag.removed);
});

test('a write address paints its every match in the write slot, dotted, beside the facet\'s dashed box', () => {
  // The done-when's shape: a link selected, "Every link in Welcome"
  // chosen; the container's `a` rule outlines every anchor in it. Two
  // facts: the part's dashed box stays inside the rule's dotted reach
  // (the element's solid outline stays too, from the element spine).
  selectTo(BLOCK, '.quote');
  const selected = appended.at(-1);
  addressTo(CONTAINER, 'a');
  const tag = appended.at(-1);
  assert.equal(tag.attributes['data-daedalus-stylish-selector-paint'], 'write');
  assert.equal(tag.textContent, `@scope ([data-selectable="${CONTAINER}"]) {\n  a {\n    outline: 2px dotted var(--daedalus-selection, #0071e3) !important;\n    outline-offset: 5px !important;\n  }\n}`);
  assert.ok(!selected.removed);
  // A new address replaces the slot's tag; the dashed box is untouched.
  addressTo(CONTAINER, '> pb-text .quote');
  assert.ok(tag.removed);
  assert.ok(!selected.removed);
  const next = appended.at(-1);
  assert.equal(next.attributes['data-daedalus-stylish-selector-paint'], 'write');
  assert.ok(next.textContent.includes(':where(:scope) > :where([data-element="block_type:text"]) .quote'));
  // Dropping the address (the switcher's own entry, address(null))
  // empties the write slot alone; the dashed box stands as it was.
  addressTo(null);
  assert.ok(next.removed);
  assert.ok(!selected.removed);
  // An address on the selected key itself (an instance part's own tag
  // tier) is a dotted box like any other.
  addressTo(BLOCK, 'p');
  const own = appended.at(-1);
  assert.equal(own.attributes['data-daedalus-stylish-selector-paint'], 'write');
  assert.ok(own.textContent.startsWith(`@scope ([data-selectable="${BLOCK}"])`));
  // A selection move (the spine drops facet and address) empties both.
  selectTo(BLOCK, null);
  assert.ok(own.removed);
  assert.ok(selected.removed);
  assert.ok(appended.every((entry) => entry.removed));
});

test('a hover facet outside the grammar injects no tag', () => {
  const before = appended.length;
  hoverTo({ key: BLOCK, selector: 'div > .loose' });
  assert.equal(appended.length, before);
});

test('the label seam words a selector the way the inspector chip does', () => {
  // The breadcrumb's target crumb reads this (daedalus_ui label-for-key.js
  // targetLabel): a declared part by its declared label, the children
  // and a type by the vocabulary's words, and a selector the words
  // cannot place by its raw spelling (selectorLabel's own fallback).
  assert.equal(behavior.label(BLOCK, IMAGE_PART), 'Image');
  assert.equal(typeof behavior.label(CONTAINER, '> *'), 'string');
  assert.notEqual(behavior.label(CONTAINER, '> *'), '');
  assert.equal(behavior.label(BLOCK, '.quote'), '.quote');
  // A generated box: the word generated(key) gives its tree row (the
  // owner's own), a part's box behind its part.
  assert.equal(behavior.label(BLOCK, '&::before'), 'Before');
  assert.equal(behavior.label(BLOCK, '&::after'), 'After');
  assert.equal(behavior.label(BLOCK, `${IMAGE_PART}::after`), 'Image › After');
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
