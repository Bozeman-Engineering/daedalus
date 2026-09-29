/**
 * Self-asserting tests for the subject line's pure model
 * (js/src/subject-model.js): the element word (the node's minted name,
 * "Element" when the tree has no node) and the address chip (its face, its
 * map key, its lit state and its menu items: the own address first,
 * "This element" as the way back when a facet stands, then the ancestor
 * addresses in the set's order, a mintable one hinted "new rule").
 *
 * Run directly (`node --test tests/js/subject-model.test.mjs`) or via
 * the suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { elementWord, subjectChip } from '../../js/src/subject-model.js';
import { addressSet } from '../../js/src/write-address.js';
import { createMatcher } from '../../js/src/selector-matcher.js';

// Drupal.t at call time, placeholders substituted, so the sentences
// under test read as they render.
globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce(
      (out, [key, value]) => out.split(key).join(String(value)),
      text,
    ),
};

const PAGE_UUID = 'aaaaaaaa-0000-4000-8000-00000000000a';
const HERO_UUID = 'bbbbbbbb-0000-4000-8000-00000000000b';
const LINKED_UUID = 'cccccccc-0000-4000-8000-00000000000c';
const HEADING_UUID = 'dddddddd-0000-4000-8000-00000000000d';
const PAGE = `container:${PAGE_UUID}`;
const HERO = `container:${HERO_UUID}`;
const LINKED = `block:${LINKED_UUID}`;
const HEADING = `block:${HEADING_UUID}`;
const LINK = '.text-block-link';
const INSTANCE = '.s-ab12';

const TREE = [
  {
    kind: 'container',
    uuid: PAGE_UUID,
    layout_id: 'daedalus_stylish_container',
    label: 'Page',
    _element: { name: 'Page', type: 'Section', markup: { tag: 'section', parts: {} } },
    children: [
      {
        kind: 'container',
        uuid: HERO_UUID,
        layout_id: 'daedalus_stylish_container',
        label: 'Welcome',
        _element: { name: 'Welcome', type: 'Container', markup: { tag: 'section', parts: {} } },
        children: [
          {
            kind: 'block',
            uuid: LINKED_UUID,
            block_type: 'text',
            label: 'Read more',
            _element: { name: 'Read more', type: 'Text', markup: { tag: 'p', parts: { [LINK]: { tag: 'a' } } } },
          },
          {
            kind: 'block',
            uuid: HEADING_UUID,
            block_type: 'text',
            _element: { name: 'Text', type: 'Text', markup: { tag: 'h2', parts: {} } },
          },
        ],
      },
    ],
  },
];

// Strict, the text type and its link part each declaring both boxes
// (the served `pseudo` section under the host), so the doorways and the
// box rules are offered; BARE is the same guide declaring none.
const GUIDE = {
  enforcement: 'strict',
  kinds: {
    block: { grants: { typography: ['color', 'font-size'] } },
    container: { grants: {}, children: { grants: { layout: ['gap'] } } },
  },
  blockTypes: {
    text: {
      label: 'Text',
      grants: { typography: ['color', 'font-size', 'font-weight'] },
      pseudo: { before: { grants: {} }, after: { grants: {} } },
      elements: {
        [LINK]: {
          label: 'Link',
          grants: { typography: ['color', 'font-weight'] },
          pseudo: { before: { grants: {} }, after: { grants: {} } },
        },
      },
    },
  },
  base: {
    a: { grants: { typography: ['font-weight'] } },
  },
  tagLabels: { a: 'Link', p: 'Paragraph' },
  instances: { text: { tags: { p: 'Body text' } } },
  conditions: {},
};
const BARE = {
  ...GUIDE,
  blockTypes: {
    text: {
      label: 'Text',
      grants: GUIDE.blockTypes.text.grants,
      elements: { [LINK]: { label: 'Link', grants: GUIDE.blockTypes.text.elements[LINK].grants } },
    },
  },
};
const TYPES = { [LINKED_UUID]: 'text', [HEADING_UUID]: 'text' };
const LAYOUTS = {
  [PAGE_UUID]: 'daedalus_stylish_container',
  [HERO_UUID]: 'daedalus_stylish_container',
};
const MATCHER = createMatcher(TREE);

const set = (key, selector, map = {}) =>
  addressSet({
    guide: GUIDE,
    map,
    key,
    selector,
    tree: TREE,
    blockTypes: TYPES,
    sectionLayouts: LAYOUTS,
  });
const chip = (own, addresses, address = null, extra = {}) =>
  subjectChip({
    guide: GUIDE,
    matcher: MATCHER,
    own,
    addresses,
    address,
    blockTypes: TYPES,
    sectionLayouts: LAYOUTS,
    ...extra,
  });
const labels = (items) => items.map((item) => item.label);
const checked = (items) => items.filter((item) => item.checked).map((item) => item.label);

test('elementWord: the node\'s minted name, else Element', () => {
  assert.equal(elementWord(MATCHER, LINKED), 'Read more');
  assert.equal(elementWord(MATCHER, HERO), 'Welcome');
  // An unrenamed node's name is its type word (the annotator's rule,
  // delivered on the node: nothing here derives it).
  assert.equal(elementWord(MATCHER, HEADING), 'Text');
  // A key the tree does not carry, or no tree at all: the generic word.
  assert.equal(elementWord(MATCHER, 'container:99999999-0000-4000-8000-000000000009'), 'Element');
  assert.equal(elementWord(null, HEADING), 'Element');
  assert.equal(elementWord(null, 'field:aaaaaaaa-0000-4000-8000-00000000000a:body'), 'Element');
  // A key outside the grammar still words itself.
  assert.equal(elementWord(null, 'nonsense'), 'Element');
});

test('a root facet: "This element" faces the own address, unlit, its item checked, the ancestors after it', () => {
  const own = { key: HEADING, selector: null };
  const addresses = set(HEADING, null);
  const out = chip(own, addresses);
  assert.equal(out.face, 'This element');
  assert.equal(out.id, HEADING);
  assert.equal(out.lit, false);
  assert.deepEqual(labels(out.items), [
    'This element',
    'All children of Welcome',
    'Text blocks directly in Welcome',
    'Text blocks in Welcome',
    'Text blocks in Page',
    '+ Before',
    '+ After',
  ]);
  assert.deepEqual(checked(out.items), ['This element']);
  // No second "This element": the root's own entry is the way there.
  assert.equal(labels(out.items).filter((label) => label === 'This element').length, 1);
  assert.deepEqual(out.items[0].address, own);
  assert.deepEqual(out.items[1].address, { key: HERO, selector: '> *' });
  // A mintable address is hinted; the own address and the doorways
  // never are.
  assert.equal(out.items[0].hint, null);
  assert.deepEqual(out.items.slice(1).map((item) => item.hint), ['new rule', 'new rule', 'new rule', 'new rule', null, null]);
  // The doorways seat the root's own boxes (the anchor spelling).
  assert.deepEqual(out.items.slice(-2).map((item) => item.address), [
    { key: HEADING, selector: '&::before' },
    { key: HEADING, selector: '&::after' },
  ]);
});

test('the box doorways ride a root or part facet with a set, never a tag tier, a box, or a facet without a set', () => {
  // A part facet's doorways are the part's boxes.
  const part = chip({ key: LINKED, selector: LINK }, set(LINKED, LINK));
  assert.deepEqual(labels(part.items).slice(-2), ['+ Before', '+ After']);
  assert.deepEqual(part.items.slice(-2).map((item) => item.address), [
    { key: LINKED, selector: `${LINK}::before` },
    { key: LINKED, selector: `${LINK}::after` },
  ]);
  assert.deepEqual(part.items.slice(-2).map((item) => item.checked), [false, false]);
  // A tag tier offers none (its boxes are many; not a doorway).
  assert.equal(labels(chip({ key: LINKED, selector: 'a' }, set(LINKED, 'a')).items).some((label) => label.startsWith('+ ')), false);
  // Without a set (a posture that writes nowhere else) none either: the
  // seat would land where the panel cannot write.
  assert.equal(labels(chip({ key: HEADING, selector: null }, []).items).some((label) => label.startsWith('+ ')), false);
  assert.equal(labels(chip({ key: LINKED, selector: LINK }, []).items).some((label) => label.startsWith('+ ')), false);
  // On a strict site a box the host does not declare has no doorway:
  // the first write there would be refused (STYLE_PSEUDO_NOT_DECLARED).
  const bare = chip({ key: HEADING, selector: null }, set(HEADING, null), null, { guide: BARE });
  assert.equal(labels(bare.items).some((label) => label.startsWith('+ ')), false);
  assert.equal(labels(bare.items).length, 5);
  // A mode without rows for a box (basic) withholds the doorways too:
  // the set itself is untouched, only the two doorways drop.
  const basic = chip({ key: HEADING, selector: null }, set(HEADING, null), null, { boxes: false });
  assert.deepEqual(labels(basic.items), [
    'This element',
    'All children of Welcome',
    'Text blocks directly in Welcome',
    'Text blocks in Welcome',
    'Text blocks in Page',
  ]);
  const basicPart = chip({ key: LINKED, selector: LINK }, set(LINKED, LINK), null, { boxes: false });
  assert.equal(labels(basicPart.items).some((label) => label.startsWith('+ ')), false);
});

test('a box facet faces its box word, "This element" is the way back, the ancestors\' box rules follow, no doorway', () => {
  // The root's own box: "Before" alone, the element word being the host.
  const own = { key: HEADING, selector: '&::before' };
  const map = { [`${HERO}::> pb-text::before`]: { default: { content: '""', color: 'red' } } };
  const addresses = set(HEADING, '&::before', map);
  const out = chip(own, addresses);
  assert.equal(out.face, 'Before');
  assert.equal(out.id, `${HEADING}::&::before`);
  assert.equal(out.lit, false);
  // The existing rule, then the mintable ones the text type's declared
  // box admits (the children's box is always derived: never on strict).
  assert.deepEqual(labels(out.items), [
    'Before',
    'This element',
    'Text blocks directly in Welcome › Before',
    'Text blocks in Welcome › Before',
    'Text blocks in Page › Before',
  ]);
  assert.deepEqual(checked(out.items), ['Before']);
  assert.deepEqual(out.items[1].address, { key: HEADING, selector: null });
  assert.deepEqual(out.items.map((item) => item.hint), [null, null, null, 'new rule', 'new rule']);
  // Seated on the existing box rule the chip lights there.
  const seated = chip(own, addresses, { key: HERO, selector: '> pb-text::before' });
  assert.equal(seated.face, 'Text blocks directly in Welcome › Before');
  assert.equal(seated.id, `${HERO}::> pb-text::before`);
  assert.equal(seated.lit, true);
  assert.deepEqual(checked(seated.items), ['Text blocks directly in Welcome › Before']);
  // A part's box: the part's face then the box word.
  const after = chip({ key: LINKED, selector: `${LINK}::after` }, set(LINKED, `${LINK}::after`));
  assert.equal(after.face, 'This link › After');
  // (The base `a` tag declares no box here, so the tag rule's box is
  // never offered; the type-then-part chain lands on the declared part.)
  assert.deepEqual(labels(after.items).slice(0, 3), ['This link › After', 'This element', 'Text block links directly in Welcome › After']);
  // Without a set the box still faces itself with the way back.
  assert.deepEqual(labels(chip(own, []).items), ['Before', 'This element']);
});

test('a labeled root never repeats its name on the chip: the word carries it, the chip says "This element"', () => {
  const out = chip({ key: LINKED, selector: null }, set(LINKED, null));
  assert.equal(out.face, 'This element');
  assert.equal(elementWord(MATCHER, LINKED), 'Read more');
});

test('an existing ancestor rule reads without the hint and, seated, lights the chip and moves the check', () => {
  const own = { key: HEADING, selector: null };
  const map = { [`${HERO}::> pb-text`]: { default: { color: 'red' } } };
  const addresses = set(HEADING, null, map);
  const seated = { key: HERO, selector: '> pb-text' };
  const out = chip(own, addresses, seated);
  assert.equal(out.face, 'Text blocks directly in Welcome');
  assert.equal(out.id, `${HERO}::> pb-text`);
  assert.equal(out.lit, true);
  assert.deepEqual(checked(out.items), ['Text blocks directly in Welcome']);
  assert.equal(out.items.find((item) => item.label === 'Text blocks directly in Welcome').hint, null);
  // The own entry stays first and unchecked: the way back to the own key.
  assert.equal(out.items[0].label, 'This element');
  assert.equal(out.items[0].checked, false);
});

test('a part facet: "This link" faces the own address, "This element" is the way back, then the ancestors', () => {
  const own = { key: LINKED, selector: LINK };
  const addresses = set(LINKED, LINK, { [`${PAGE}::a`]: { default: { color: 'teal' } } });
  const out = chip(own, addresses);
  assert.equal(out.face, 'This link');
  assert.equal(out.id, `${LINKED}::${LINK}`);
  assert.equal(out.lit, false);
  assert.deepEqual(labels(out.items).slice(0, 4), [
    'This link',
    'This element',
    'Every link in Page',
    'Every link in Welcome',
  ]);
  assert.deepEqual(checked(out.items), ['This link']);
  // The way back reselects the bare element: a null selector on the key.
  assert.deepEqual(out.items[1], {
    label: 'This element',
    checked: false,
    hint: null,
    address: { key: LINKED, selector: null },
  });
  assert.deepEqual(out.items[2].address, { key: PAGE, selector: 'a' });
  assert.equal(out.items[2].hint, null);
  assert.equal(out.items[3].hint, 'new rule');
});

test('a tag facet faces "Every link here"; seated on an ancestor tag rule the chip lights at that rule', () => {
  const own = { key: LINKED, selector: 'a' };
  const addresses = set(LINKED, 'a', { [`${HERO}::a`]: { default: { color: 'teal' } } });
  const resting = chip(own, addresses);
  assert.equal(resting.face, 'Every link here');
  assert.deepEqual(labels(resting.items).slice(0, 3), ['Every link here', 'This element', 'Every link in Welcome']);
  const seated = chip(own, addresses, { key: HERO, selector: 'a' });
  assert.equal(seated.face, 'Every link in Welcome');
  assert.equal(seated.id, `${HERO}::a`);
  assert.equal(seated.lit, true);
  assert.deepEqual(checked(seated.items), ['Every link in Welcome']);
});

test('without an address set the chip still renders: the facet face and the way back alone', () => {
  // The root with no set (code mode today, a foreign entity, an editing
  // posture): one item, the chip a still readout.
  const root = chip({ key: HEADING, selector: null }, []);
  assert.deepEqual(root, {
    face: 'This element',
    id: HEADING,
    lit: false,
    items: [{ label: 'This element', checked: true, hint: null, address: { key: HEADING, selector: null } }],
  });
  // A part with no set: its face, and "This element" as the way back.
  const part = chip({ key: LINKED, selector: LINK }, []);
  assert.equal(part.face, 'This link');
  assert.equal(part.id, `${LINKED}::${LINK}`);
  assert.deepEqual(labels(part.items), ['This link', 'This element']);
  assert.deepEqual(checked(part.items), ['This link']);
});

test('a chain facet (a rule of the element\'s own, no address set) faces the rule\'s sentence in the owner', () => {
  const out = chip({ key: HERO, selector: '> pb-text' }, []);
  assert.equal(out.face, 'Text blocks directly in Welcome');
  assert.equal(out.id, `${HERO}::> pb-text`);
  assert.deepEqual(labels(out.items), ['Text blocks directly in Welcome', 'This element']);
  // The children rule reads the same grammar.
  assert.equal(chip({ key: HERO, selector: '> *' }, []).face, 'All children of Welcome');
  // Without a tree the owner has no node to be named from: the generic word.
  assert.equal(
    subjectChip({ guide: GUIDE, matcher: null, own: { key: HERO, selector: '> *' }, addresses: [], address: null }).face,
    'All children of the parent element',
  );
});

test('an instance part words itself from the markup annotation the caller passes', () => {
  // The annotation's instance rows (parts.js findInstancePart): the
  // minted class without its dot, the row's label, nested rows.
  const markup = {
    tag: 'p',
    parts: {},
    instances: [{ class: INSTANCE.slice(1), label: 'Body text', children: [] }],
  };
  const worded = chip({ key: LINKED, selector: INSTANCE }, [], null, { markup });
  assert.equal(worded.face, 'This body text');
  // The set's own entry reads the same words through the same argument.
  const addresses = addressSet({
    guide: GUIDE, map: {}, key: LINKED, selector: INSTANCE, tree: TREE, blockTypes: TYPES, sectionLayouts: LAYOUTS, markup,
  });
  assert.equal(addresses[0].label, 'This body text');
  assert.equal(chip({ key: LINKED, selector: INSTANCE }, addresses, null, { markup }).face, 'This body text');
});
