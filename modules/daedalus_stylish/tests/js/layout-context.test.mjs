/**
 * Self-asserting tests for the selection facts (layout-context.js):
 * the {self, parent} shape, the chrome-excluding hasText/childCount
 * checks, the caller-injected part-presence facts (resolved from the
 * markup annotation, not scraped here), and the read's degrade-to-null
 * contract.
 *
 * Run directly (`node tests/js/layout-context.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite so the facts contract has backpressure. Exits non-zero on
 * failure.
 *
 * The module touches the DOM only through a narrow surface (querySelector,
 * getComputedStyle, childNodes/children, matches, closest, getAttribute),
 * so the tests run under plain node against fake nodes implementing
 * exactly that surface, with document/window stubbed per read.
 */
import { strict as assert } from 'node:assert';
import {
  CHROME_SELECTOR,
  NON_BOX_SELECTOR,
  childCount,
  hasTextNode,
  readLayoutContext,
  selfFacts,
} from '../../js/src/layout-context.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- Fake DOM -------------------------------------------------------------

const text = (data) => ({ nodeType: 3, data });

/**
 * Matches the selector grammar this module actually uses: single class
 * selectors, bare tag names and [attr] / [attr="value"], comma-separated.
 */
const matchesOne = (node, selector) => {
  const trimmed = selector.trim();
  if (trimmed.startsWith('.')) {
    return node.classes.includes(trimmed.slice(1));
  }
  if (/^[a-z]+$/.test(trimmed)) {
    return node.tag === trimmed;
  }
  const attr = trimmed.match(/^\[([a-z-]+)(?:="(.*)")?\]$/);
  if (!attr) {
    throw new Error(`Fake DOM cannot match selector: ${trimmed}`);
  }
  if (!(attr[1] in node.attrs)) {
    return false;
  }
  return attr[2] === undefined || node.attrs[attr[1]] === attr[2];
};

const el = ({ tag = 'div', classes = [], attrs = {}, computed = {}, childNodes = [] } = {}) => {
  const node = {
    nodeType: 1,
    tag,
    classes,
    attrs,
    computed: { display: 'block', flexDirection: 'row', alignItems: 'normal', ...computed },
    childNodes,
    parentElement: null,
  };
  node.children = childNodes.filter((child) => child.nodeType === 1);
  for (const child of node.children) {
    child.parentElement = node;
  }
  node.matches = (selector) => selector.split(',').some((part) => matchesOne(node, part));
  node.getAttribute = (name) => node.attrs[name] ?? null;
  node.closest = (selector) => {
    let current = node;
    while (current) {
      if (current.matches(selector)) {
        return current;
      }
      current = current.parentElement;
    }
    return null;
  };
  // Descendants only, like the real Element#querySelector.
  node.querySelector = (selector) => {
    for (const child of node.children) {
      if (child.matches(selector)) {
        return child;
      }
      const hit = child.querySelector(selector);
      if (hit) {
        return hit;
      }
    }
    return null;
  };
  return node;
};

/**
 * Stubs document/window around a readLayoutContext call: querySelector
 * searches the given canvas root (self included, like the real document),
 * computed style comes from each fake node's own `computed`.
 */
const readFrom = (root, key, parts = {}, selectors = []) => {
  globalThis.document = {
    querySelector: (selector) => (root.matches(selector) ? root : root.querySelector(selector)),
  };
  globalThis.window = { getComputedStyle: (node) => node.computed };
  try {
    return readLayoutContext(key, parts, selectors);
  }
  finally {
    delete globalThis.document;
    delete globalThis.window;
  }
};

// --- hasTextNode / childCount ----------------------------------------------

test('hasTextNode: real text anywhere in the subtree, whitespace is not text', () => {
  assert.equal(hasTextNode(el({ childNodes: [text('Hello')] })), true);
  assert.equal(hasTextNode(el({ childNodes: [el({ childNodes: [el({ childNodes: [text('deep')] })] })] })), true);
  assert.equal(hasTextNode(el()), false);
  assert.equal(hasTextNode(el({ childNodes: [text('  \n\t ')] })), false);
});

test('hasTextNode: editor chrome subtrees never count as text', () => {
  const chromeOnly = el({
    childNodes: [
      el({ attrs: { 'data-contextual-id': 'block:...' }, childNodes: [text('Open configuration options')] }),
      el({ classes: ['daedalus-edit-validation-message'], childNodes: [text('This field is required.')] }),
      el({ classes: ['layout-builder-block__content-preview-placeholder-label'], childNodes: [text('Placeholder for the block')] }),
    ],
  });
  assert.equal(hasTextNode(chromeOnly), false);
  // Real text next to chrome still counts.
  const mixed = el({
    childNodes: [
      el({ classes: ['daedalus-edit-validation-message'], childNodes: [text('chrome')] }),
      el({ childNodes: [text('content')] }),
    ],
  });
  assert.equal(hasTextNode(mixed), true);
});

test('childCount: element children only, chrome excluded', () => {
  const region = el({
    childNodes: [
      text('\n  '),
      el({ attrs: { 'data-selectable': 'block:aaaa' } }),
      el({ classes: ['daedalus-ui-drop-placeholder'] }),
      el({ attrs: { 'data-selectable': 'block:bbbb' } }),
      text('\n'),
    ],
  });
  assert.equal(childCount(region), 2);
  assert.equal(childCount(el()), 0);
});

test('childCount: box-less elements never count as child boxes', () => {
  // The entity root as the canvas renders it: daedalus_stylish's inline stylesheet
  // beside the layout root. A grid gap ignores the style tag, so the
  // counter must too, or a one-band page would open the gap gate.
  const entity = el({
    childNodes: [
      el({ tag: 'style', attrs: { 'data-daedalus-stylish-entity': '' }, childNodes: [text('.x { gap: 1rem }')] }),
      el({ classes: ['daedalus-layout-layout'], childNodes: [el({ attrs: { 'data-selectable': 'container:s1' } })] }),
    ],
  });
  assert.equal(childCount(entity), 1);
  // Every member of the set, beside chrome, on one parent.
  const mixed = el({
    childNodes: [
      el({ tag: 'script' }),
      el({ tag: 'template', childNodes: [el(), el()] }),
      el({ tag: 'link', attrs: { rel: 'stylesheet' } }),
      el({ tag: 'style' }),
      el({ classes: ['daedalus-ui-drop-placeholder'] }),
      el({ tag: 'section', attrs: { 'data-selectable': 'container:s1' } }),
      el({ tag: 'section', attrs: { 'data-selectable': 'container:s2' } }),
    ],
  });
  assert.equal(childCount(mixed), 2);
});

test('selfFacts: partChildCount counts each declared part\'s OWN child boxes', () => {
  // The page as the canvas renders it: the entity root holds daedalus_stylish's
  // inline stylesheet beside the layout root, the layout root holds the
  // bands (and, mid-drag, the placeholder). The entity root's own count
  // is 1 (the wrapper); the bound part's is the band count, the fact the
  // page's Gap gate reads. Chrome and box-less children are skipped
  // exactly as childCount skips them.
  const page = (bands) => {
    const layoutRoot = el({
      classes: ['daedalus-layout-layout'],
      childNodes: [
        el({ classes: ['daedalus-ui-drop-placeholder'] }),
        ...bands.map((id) => el({ tag: 'section', attrs: { 'data-selectable': `container:${id}` } })),
      ],
    });
    return el({
      attrs: { 'data-selectable': 'entity:root' },
      childNodes: [
        el({ tag: 'style', attrs: { 'data-daedalus-stylish-entity': '' }, childNodes: [text('.x { gap: 1rem }')] }),
        layoutRoot,
      ],
    });
  };
  const two = selfFacts(page(['s1', 's2']), page([]).computed, { '.daedalus-layout-layout': true }, ['.daedalus-layout-layout']);
  assert.equal(two.childCount, 1);
  assert.deepEqual(two.partChildCount, { '.daedalus-layout-layout': 2 });
  // A one-band page: the wrapper's own one child, never climbed through
  // to the entity root or resolved through a flow container.
  const one = selfFacts(page(['s1']), page([]).computed, { '.daedalus-layout-layout': true }, ['.daedalus-layout-layout']);
  assert.deepEqual(one.partChildCount, { '.daedalus-layout-layout': 1 });
  // A declared selector with no canvas match is omitted (unknown), not
  // zero: existence is the presence map's question.
  const blank = selfFacts(page(['s1']), page([]).computed, { '.daedalus-layout-layout': true }, ['.daedalus-layout-layout', '.field__items']);
  assert.deepEqual(blank.partChildCount, { '.daedalus-layout-layout': 1 });
  // No selectors asked for: the empty map, whatever the canvas holds.
  assert.deepEqual(selfFacts(page(['s1', 's2']), page([]).computed).partChildCount, {});
});

test('selfFacts: partChildCount reads the part\'s FIRST match, the placeholder read\'s rule', () => {
  // A block with two fields, each wearing the field shell: the first
  // .field__items in document order answers, as the canvas placeholder
  // read and the bands' first match do.
  const block = el({
    attrs: { 'data-selectable': 'block:aaaa' },
    childNodes: [
      el({ classes: ['field'], childNodes: [el({ classes: ['field__items'], childNodes: [el(), el(), el()] })] }),
      el({ classes: ['field'], childNodes: [el({ classes: ['field__items'], childNodes: [el()] })] }),
    ],
  });
  assert.deepEqual(selfFacts(block, block.computed, {}, ['.field__items']).partChildCount, { '.field__items': 3 });
});

test('readLayoutContext: partChildCount rides self beside the presence map', () => {
  const layoutRoot = el({
    classes: ['daedalus-layout-layout'],
    childNodes: [
      el({ tag: 'section', attrs: { 'data-selectable': 'container:s1' } }),
      el({ tag: 'section', attrs: { 'data-selectable': 'container:s2' } }),
    ],
  });
  const root = el({
    attrs: { 'data-selectable': 'entity:root' },
    childNodes: [el({ tag: 'style' }), layoutRoot],
  });
  const context = readFrom(root, 'entity:root', { '.daedalus-layout-layout': true }, ['.daedalus-layout-layout']);
  assert.deepEqual(context.self, {
    display: 'block',
    flexDirection: 'row',
    isContainer: true,
    childCount: 1,
    hasText: false,
    parts: { '.daedalus-layout-layout': true },
    partChildCount: { '.daedalus-layout-layout': 2 },
  });
  // The default: no selectors, an empty map (the existing shape pins).
  assert.deepEqual(readFrom(root, 'entity:root').self.partChildCount, {});
});

test('hasTextNode: a stylesheet or script body is not page text', () => {
  const entity = el({
    childNodes: [
      el({ tag: 'style', childNodes: [text('.daedalus-layout-layout { gap: 2rem }')] }),
      el({ tag: 'script', childNodes: [text('window.x = 1;')] }),
      el({ tag: 'template', childNodes: [el({ childNodes: [text('Template copy')] })] }),
    ],
  });
  assert.equal(hasTextNode(entity), false);
  // Real text beside the style tag still counts.
  entity.childNodes.push(el({ childNodes: [text('Band copy')] }));
  assert.equal(hasTextNode(entity), true);
});

test('CHROME_SELECTOR names the known in-canvas chrome', () => {
  // A change here must be deliberate: gates read what a VISITOR would see.
  assert.ok(CHROME_SELECTOR.includes('[data-contextual-id]'));
  assert.ok(CHROME_SELECTOR.includes('.daedalus-edit-validation-message'));
  assert.ok(CHROME_SELECTOR.includes('.daedalus-ui-drop-placeholder'));
  assert.ok(CHROME_SELECTOR.includes('.layout-builder-block__content-preview-placeholder-label'));
  assert.ok(CHROME_SELECTOR.includes('.daedalus-ui-block-stand-in'));
});

test('NON_BOX_SELECTOR names the box-less tags', () => {
  // The set is the tags that generate no box and render no text; a
  // change here must be deliberate for the same reason as the chrome.
  assert.equal(NON_BOX_SELECTOR, 'style, script, template, link');
});

// --- selfFacts --------------------------------------------------------------

test('selfFacts: isContainer is structural (selectable descendants), not display', () => {
  const region = el({
    computed: { display: 'block' },
    childNodes: [el({ attrs: { 'data-selectable': 'block:aaaa' } })],
  });
  assert.deepEqual(selfFacts(region, region.computed), {
    display: 'block',
    flexDirection: 'row',
    isContainer: true,
    childCount: 1,
    hasText: false,
    parts: {},
    partChildCount: {},
  });
  // A text field full of paragraphs: markup is content, not structure.
  // flexDirection rides along for the position widget's parent mapping.
  const field = el({
    computed: { display: 'flex', flexDirection: 'column' },
    childNodes: [el({ childNodes: [text('One')] }), el({ childNodes: [text('Two')] })],
  });
  assert.deepEqual(selfFacts(field, field.computed), {
    display: 'flex',
    flexDirection: 'column',
    isContainer: false,
    childCount: 2,
    hasText: true,
    parts: {},
    partChildCount: {},
  });
});

// --- readLayoutContext -------------------------------------------------------

const canvas = () => {
  const block = el({
    attrs: { 'data-selectable': 'block:aaaa' },
    computed: { display: 'block' },
    childNodes: [
      el({ attrs: { 'data-selectable': 'block:aaaa:field' }, childNodes: [text('Body text')] }),
      el({ attrs: { 'data-contextual-id': 'x' }, childNodes: [text('Open configuration options')] }),
    ],
  });
  const sibling = el({ attrs: { 'data-selectable': 'block:bbbb' } });
  const region = el({
    attrs: { 'data-selectable': 'region:s1:content' },
    computed: { display: 'flex', flexDirection: 'column', alignItems: 'center' },
    childNodes: [block, sibling],
  });
  const root = el({ attrs: { 'data-selectable': 'entity:root' }, childNodes: [region] });
  return { root, region, block };
};

test('readLayoutContext: the full {self, parent} shape', () => {
  const { root } = canvas();
  assert.deepEqual(readFrom(root, 'block:aaaa'), {
    self: { display: 'block', flexDirection: 'row', isContainer: true, childCount: 1, hasText: true, parts: {}, partChildCount: {} },
    parent: { display: 'flex', flexDirection: 'column', alignItems: 'center', key: 'region:s1:content' },
  });
});

test('readLayoutContext: wrapper markup between child and selectable ancestor', () => {
  // Layout truth comes from the immediate parent box; the attribution key
  // from the nearest selectable ancestor above it.
  const block = el({ attrs: { 'data-selectable': 'block:aaaa' } });
  const wrapper = el({ computed: { display: 'grid' }, childNodes: [block] });
  const region = el({
    attrs: { 'data-selectable': 'region:s1:content' },
    computed: { display: 'block' },
    childNodes: [wrapper],
  });
  const context = readFrom(region, 'block:aaaa');
  assert.deepEqual(context.parent, {
    display: 'grid',
    flexDirection: 'row',
    alignItems: 'normal',
    key: 'region:s1:content',
  });
  assert.deepEqual(context.self, { display: 'block', flexDirection: 'row', isContainer: false, childCount: 0, hasText: false, parts: {}, partChildCount: {} });
});

test('readLayoutContext: unknown key or missing element is null, never a guess', () => {
  const { root } = canvas();
  assert.equal(readFrom(root, 'block:missing'), null);
  assert.equal(readFrom(root, ''), null);
  assert.equal(readFrom(root, null), null);
  assert.equal(readFrom(root, undefined), null);
});

test('readLayoutContext: a parentless element still reports self facts', () => {
  const { root } = canvas();
  const context = readFrom(root, 'entity:root');
  assert.equal(context.parent, null);
  assert.deepEqual(context.self, { display: 'block', flexDirection: 'row', isContainer: true, childCount: 1, hasText: true, parts: {}, partChildCount: {} });
});

test('readLayoutContext: self.parts carries the injected annotation facts', () => {
  // Presence is the MODEL's answer (markup-index partPresence over the
  // `_element.markup` annotation), resolved by the caller and carried on
  // the facts object untouched, this module never scrapes the DOM for it.
  const block = el({
    attrs: { 'data-selectable': 'block:aaaa' },
    childNodes: [el({ classes: ['text-block-link'], childNodes: [text('Read more')] })],
  });
  const root = el({ attrs: { 'data-selectable': 'entity:root' }, childNodes: [block] });
  const context = readFrom(root, 'block:aaaa', {
    '.text-block-link': true,
    '.image-block-image': false,
  });
  assert.deepEqual(context.self.parts, {
    '.text-block-link': true,
    '.image-block-image': false,
  });
});

// --- Runner ------------------------------------------------------------------

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
  console.error(`${failures} of ${tests.length} layout-context tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} layout-context tests passed.`);
