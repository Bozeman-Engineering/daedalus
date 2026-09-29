/**
 * Self-asserting tests for the write address (js/src/write-address.js):
 * the address set's order (own key, existing ancestor rules nearest
 * ancestor first, mintable ones nearest ancestor first), the matcher's
 * reach gate, the strict-site declaredness filter (and the every-mode
 * tag rule), the labels, the element facets (root, declared part,
 * instance part, tag) against the chain facet's empty set, and the
 * grant judgement at an address (withheldAt).
 *
 * Run directly (`node --test tests/js/write-address.test.mjs`) or via
 * the suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  addressDeclaration,
  addressKey,
  addressLabel,
  addressSet,
  boxAddresses,
  boxLabel,
  facetOf,
  isAddress,
  ownerName,
  sameAddress,
  withheldAt,
  withheldReason,
} from '../../js/src/write-address.js';
import { createMatcher } from '../../js/src/selector-matcher.js';
import { variantRecord } from './materials-fixture.mjs';

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
const IMAGE_UUID = 'eeeeeeee-0000-4000-8000-00000000000e';
const PARA_UUID = 'ffffffff-0000-4000-8000-00000000000f';
const FORMATTED_UUID = '11111111-0000-4000-8000-000000000001';
const PAGE = `container:${PAGE_UUID}`;
const HERO = `container:${HERO_UUID}`;
const LINKED = `block:${LINKED_UUID}`;
const HEADING = `block:${HEADING_UUID}`;
const IMAGE = `block:${IMAGE_UUID}`;
const PARA = `block:${PARA_UUID}`;
const FORMATTED = `block:${FORMATTED_UUID}`;
const LINK = '.text-block-link';
// The formatted text block's annotation: the matcher fixture's block `f`
// (selector-matcher-fixture.mjs) carried here under a real uuid, since
// addressSet parses its key and the fixture's short keys never parse. A
// div root holding paragraphs and links, three minted instances (a
// paragraph, a heading, a list carrying a list item).
const FORMATTED_MARKUP = {
  tag: 'div',
  tags: ['p', 'a'],
  instances: [
    { class: 's-p1', tag: 'p', label: 'Paragraph', children: [] },
    { class: 's-h1', tag: 'h2', label: 'Heading 2', children: [] },
    { class: 's-ul', tag: 'ul', label: 'Bulleted list', children: [{ class: 's-li', tag: 'li', label: 'List item', children: [] }] },
  ],
};

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
            _element: { name: 'Text', type: 'Text', markup: { tag: 'p', tags: ['em'], parts: { [LINK]: { tag: 'a' } } } },
          },
          {
            kind: 'block',
            uuid: PARA_UUID,
            block_type: 'text',
            _element: { name: 'Text', type: 'Text', markup: { tag: 'p', parts: {} } },
          },
          {
            kind: 'block',
            uuid: HEADING_UUID,
            block_type: 'text',
            _element: { name: 'Text', type: 'Text', markup: { tag: 'h2', parts: {} } },
          },
          {
            kind: 'block',
            uuid: IMAGE_UUID,
            block_type: 'image',
            _element: { name: 'Image', type: 'Image', markup: { tag: 'figure', parts: {} } },
          },
          {
            kind: 'block',
            uuid: FORMATTED_UUID,
            block_type: 'formatted_text',
            _element: { name: 'Formatted text', type: 'Formatted text', markup: FORMATTED_MARKUP },
          },
        ],
      },
    ],
  },
];

const guideOf = (enforcement) => ({
  enforcement,
  kinds: {
    block: { grants: { typography: ['color', 'font-size'] } },
    container: { grants: {}, children: { grants: { layout: ['gap'] } } },
  },
  blockTypes: {
    text: {
      label: 'Text',
      grants: { typography: ['color', 'font-size', 'font-weight'] },
      elements: {
        [LINK]: { label: 'Link', grants: { typography: ['color', 'font-weight'] } },
      },
    },
    image: { label: 'Image', grants: { sizing: ['width'] } },
    formatted_text: { label: 'Formatted text', grants: { typography: ['color', 'font-size'] } },
  },
  base: {
    a: { grants: { typography: ['font-weight'] } },
    p: { grants: { typography: ['font-size'] } },
  },
  // The one word per closed base tag (ClientStyleGuide's tagLabels); the
  // instance lane's identity words answer only for a tag outside it, so
  // the paragraph reads its base word, never the rich-text one.
  tagLabels: { a: 'Link', p: 'Paragraph' },
  instances: { text: { tags: { p: 'Body text', em: 'Emphasis' } } },
  conditions: {},
});
const STRICT = guideOf('strict');
const OPEN = guideOf('open');
// A strict guide whose text type declares its before box, whose link
// part declares its after box, and whose base paragraph declares its
// before box (the served `pseudo` section under the host, T6's shape).
const BOXED = {
  ...STRICT,
  blockTypes: {
    ...STRICT.blockTypes,
    text: {
      ...STRICT.blockTypes.text,
      pseudo: { before: { grants: { typography: ['color'] } } },
      elements: {
        [LINK]: { ...STRICT.blockTypes.text.elements[LINK], pseudo: { after: { grants: {} } } },
      },
    },
  },
  base: { ...STRICT.base, p: { ...STRICT.base.p, pseudo: { before: { grants: {} } } } },
};
const TYPES = {
  [LINKED_UUID]: 'text',
  [PARA_UUID]: 'text',
  [HEADING_UUID]: 'text',
  [IMAGE_UUID]: 'image',
  [FORMATTED_UUID]: 'formatted_text',
};
const LAYOUTS = {
  [PAGE_UUID]: 'daedalus_stylish_container',
  [HERO_UUID]: 'daedalus_stylish_container',
};

const set = (guide, key, selector, map = {}, tree = TREE, markup = null) =>
  addressSet({ guide, map, key, selector, tree, blockTypes: TYPES, sectionLayouts: LAYOUTS, markup });
const keys = (addresses) => addresses.map(addressKey);

test('addressKey, sameAddress and isAddress speak the one key grammar', () => {
  assert.equal(addressKey({ key: HERO, selector: 'a' }), `${HERO}::a`);
  assert.equal(addressKey({ key: HERO, selector: null }), HERO);
  assert.equal(sameAddress({ key: HERO, selector: 'a' }, { key: HERO, selector: 'a' }), true);
  assert.equal(sameAddress({ key: HERO, selector: 'a' }, { key: HERO, selector: '> a' }), false);
  assert.equal(isAddress({ key: HERO, selector: '> pb-text' }), true);
  assert.equal(isAddress({ key: HERO, selector: '' }), false, 'an address always carries a selector');
  assert.equal(isAddress({ key: HERO, selector: '>pb-text' }), false, 'canonical spelling only');
  assert.equal(isAddress(null), false);
});

test('a root facet on a strict site: the own key, then the mintable rules the roster declares, nearest ancestor first', () => {
  const addresses = set(STRICT, HEADING, null);
  // The own key leads (the node has no label: the generic word), then
  // Welcome's rules (the direct parent: its children, the text blocks
  // directly in it, the text blocks anywhere in it), then Page's (its
  // children never reach the block through Welcome; its text blocks by
  // descent do). The heading's root tag h2 is no base tag, so no tag
  // rule is offered anywhere.
  assert.deepEqual(keys(addresses), [
    HEADING,
    `${HERO}::> *`,
    `${HERO}::> pb-text`,
    `${HERO}::pb-text`,
    `${PAGE}::pb-text`,
  ]);
  assert.deepEqual(addresses.map((address) => address.existing), [true, false, false, false, false]);
  assert.deepEqual(addresses.map((address) => address.label), [
    'This element',
    'All children of Welcome',
    'Text blocks directly in Welcome',
    'Text blocks in Welcome',
    'Text blocks in Page',
  ]);
});

test('a root facet whose tag the base declares offers the tag rule on every ancestor; an open site keeps grammar-only landings but never an undeclared tag', () => {
  const strict = set(STRICT, LINKED, null);
  assert.deepEqual(keys(strict), [
    LINKED,
    `${HERO}::> *`,
    `${HERO}::> pb-text`,
    `${HERO}::pb-text`,
    `${HERO}::p`,
    `${PAGE}::pb-text`,
    `${PAGE}::p`,
  ]);
  assert.equal(strict.find((address) => address.selector === 'p').label, 'Every paragraph in Welcome');
  // Undeclare the type on a strict site: its rules go, the rest stays.
  const undeclared = { ...STRICT, blockTypes: { image: STRICT.blockTypes.image } };
  assert.deepEqual(keys(set(undeclared, LINKED, null)), [LINKED, `${HERO}::> *`, `${HERO}::p`, `${PAGE}::p`]);
  // An open site keeps the type rules (grammar-only there) but still
  // drops an undeclared tag (refused in every mode).
  const openUndeclared = { ...OPEN, blockTypes: { image: OPEN.blockTypes.image } };
  assert.deepEqual(keys(set(openUndeclared, LINKED, null)), [
    LINKED,
    `${HERO}::> *`,
    `${HERO}::> pb-text`,
    `${HERO}::pb-text`,
    `${HERO}::p`,
    `${PAGE}::pb-text`,
    `${PAGE}::p`,
  ]);
  assert.deepEqual(keys(set(OPEN, HEADING, null)).filter((key) => key.endsWith('::h2')), [], 'h2 is no base tag: never offered');
});

test('existing ancestor rules come before the mintable ones, nearest ancestor first, deduplicated against the mint list', () => {
  const map = {
    [`${PAGE}::pb-text`]: { default: { color: 'red' } },
    [`${HERO}::> *`]: { default: { gap: '1rem' } },
    [`${HERO}::> pb-container > *`]: { default: { margin: '0' } },
  };
  const addresses = set(STRICT, LINKED, null, map);
  assert.deepEqual(keys(addresses), [
    LINKED,
    `${HERO}::> *`,
    `${PAGE}::pb-text`,
    `${HERO}::> pb-text`,
    `${HERO}::pb-text`,
    `${HERO}::p`,
    `${PAGE}::p`,
  ]);
  assert.deepEqual(addresses.map((address) => address.existing), [true, true, true, false, false, false, false]);
  // A rule that does not reach the element (Welcome's `> pb-container >
  // *` targets a nested container's children) is neither existing nor
  // mintable here.
  assert.equal(addresses.some((address) => address.selector === '> pb-container > *'), false);
});

test('a rule position is one existing address whether it wears, carries lines, or both: the group\'s own layer names it once', () => {
  const row = variantRecord('row', {
    label: 'Row',
    scope: 'kind:container',
    value: { '': { default: { gap: '1rem' } } },
  });
  const map = {
    // Wears only: no entry lines, the worn layer delivers.
    [`${HERO}::> *`]: { variants: [{ variant: 'row', fragment: '' }] },
    // Wears and carries lines: a group of two layers, one rule.
    [`${PAGE}::pb-text`]: { variants: [{ variant: 'row', fragment: '' }], default: { color: 'red' } },
  };
  const addresses = addressSet({
    guide: STRICT, map, key: LINKED, selector: null, tree: TREE, variants: [row], blockTypes: TYPES, sectionLayouts: LAYOUTS,
  });
  assert.deepEqual(keys(addresses).slice(0, 3), [LINKED, `${HERO}::> *`, `${PAGE}::pb-text`]);
  assert.deepEqual(addresses.slice(0, 3).map((address) => address.existing), [true, true, true]);
  assert.equal(keys(addresses).filter((key) => key === `${HERO}::> *`).length, 1);
  assert.equal(keys(addresses).filter((key) => key === `${PAGE}::pb-text`).length, 1);
});

test('a part facet: this link, the ancestors\' tag rule for the part\'s tag, and the type-then-part chains', () => {
  const addresses = set(STRICT, LINKED, LINK, { [`${PAGE}::a`]: { default: { color: 'teal' } } });
  assert.deepEqual(keys(addresses), [
    `${LINKED}::${LINK}`,
    `${PAGE}::a`,
    `${HERO}::a`,
    `${HERO}::> pb-text ${LINK}`,
    `${HERO}::pb-text ${LINK}`,
    `${PAGE}::pb-text ${LINK}`,
  ]);
  assert.deepEqual(addresses.map((address) => address.label), [
    'This link',
    'Every link in Page',
    'Every link in Welcome',
    'Text block links directly in Welcome',
    'Text block links in Welcome',
    'Text block links in Page',
  ]);
  assert.deepEqual(addresses[0], { key: LINKED, selector: LINK, label: 'This link', existing: true });
  // A part the annotation does not render (the heading block carries
  // no link) has no tag rule to offer; the type-then-part chains stay,
  // since the matcher's class branch reaches a declared part after a
  // matching type whether or not the annotation lists it (R1's posture:
  // the part is the type's anatomy, a legal address either way).
  assert.deepEqual(keys(set(STRICT, HEADING, LINK)), [
    `${HEADING}::${LINK}`,
    `${HERO}::> pb-text ${LINK}`,
    `${HERO}::pb-text ${LINK}`,
    `${PAGE}::pb-text ${LINK}`,
  ]);
});

test('an instance part facet: this paragraph, every paragraph here, then the ancestors\' tag rule and the type-then-tag chains; the identity class is never an address', () => {
  // A minted paragraph in the formatted text block: its class `.s-p1`
  // is worn by this one element in the world, so its class of things is
  // its tag. The own entry (worded by the annotation row), then the
  // block's own `p` tier (the nearest owner styling every paragraph in
  // it), then per ancestor the tag rule and the type-then-TAG chains,
  // the class substituted; Page's `> pb-formatted-text p` never reaches
  // the block (it sits in Welcome), so it is dropped like any chain.
  const addresses = set(STRICT, FORMATTED, '.s-p1', {}, TREE, FORMATTED_MARKUP);
  assert.deepEqual(keys(addresses), [
    `${FORMATTED}::.s-p1`,
    `${FORMATTED}::p`,
    `${HERO}::p`,
    `${HERO}::> pb-formatted-text p`,
    `${HERO}::pb-formatted-text p`,
    `${PAGE}::p`,
    `${PAGE}::pb-formatted-text p`,
  ]);
  assert.deepEqual(addresses.map((address) => address.label), [
    'This paragraph',
    'Every paragraph here',
    'Every paragraph in Welcome',
    'Formatted text block paragraphs directly in Welcome',
    'Formatted text block paragraphs in Welcome',
    'Every paragraph in Page',
    'Formatted text block paragraphs in Page',
  ]);
  assert.deepEqual(addresses.map((address) => address.existing), [true, false, false, false, false, false, false]);
  assert.equal(addresses.slice(1).some((address) => address.selector.includes('.s-')), false, 'the identity class is the own entry alone');
  // The own tier reads existing when the map holds it; an existing
  // ancestor rule follows it, before the mintable shapes.
  const held = set(STRICT, FORMATTED, '.s-p1', {
    [`${FORMATTED}::p`]: { default: { color: 'red' } },
    [`${PAGE}::p`]: { default: { color: 'teal' } },
  }, TREE, FORMATTED_MARKUP);
  assert.deepEqual(keys(held).slice(0, 3), [`${FORMATTED}::.s-p1`, `${FORMATTED}::p`, `${PAGE}::p`]);
  assert.deepEqual(held.map((address) => address.existing), [true, true, true, false, false, false, false]);
  // A minted tag the base does not declare (h2, the nested li) has no
  // class-of-things address at all: the own entry alone, the tag gate.
  assert.deepEqual(keys(set(STRICT, FORMATTED, '.s-h1', {}, TREE, FORMATTED_MARKUP)), [`${FORMATTED}::.s-h1`]);
  assert.deepEqual(keys(set(OPEN, FORMATTED, '.s-li', {}, TREE, FORMATTED_MARKUP)), [`${FORMATTED}::.s-li`]);
  // A box on an instance host: the host's shapes with the box appended,
  // the own tier's box first.
  const box = set(OPEN, FORMATTED, '.s-p1::after', {}, TREE, FORMATTED_MARKUP);
  assert.deepEqual(keys(box).slice(0, 3), [`${FORMATTED}::.s-p1::after`, `${FORMATTED}::p::after`, `${HERO}::p::after`]);
  assert.deepEqual(box.slice(0, 3).map((address) => address.label), [
    'This paragraph › After',
    'Every paragraph here › After',
    'Every paragraph in Welcome › After',
  ]);
  // A tag rule chosen from the set lands on the block's `p` TAG TIER,
  // never on the first minted paragraph: "every paragraph" is the tier,
  // the instance one member of it. A hand-typed identity-class chain
  // still lands on its instance, the one facet a class rule reaches.
  assert.deepEqual(facetOf({ tree: TREE, key: FORMATTED, target: { key: HERO, selector: 'p' } }), { key: FORMATTED, selector: 'p' });
  assert.deepEqual(facetOf({ tree: TREE, key: FORMATTED, target: { key: HERO, selector: '> pb-formatted-text p' } }), { key: FORMATTED, selector: 'p' });
  assert.deepEqual(facetOf({ tree: TREE, key: FORMATTED, target: { key: HERO, selector: 'p::after' } }), { key: FORMATTED, selector: 'p::after' });
  assert.deepEqual(facetOf({ tree: TREE, key: FORMATTED, target: { key: HERO, selector: 'h2' } }), { key: FORMATTED, selector: 'h2' });
  assert.deepEqual(facetOf({ tree: TREE, key: FORMATTED, target: { key: HERO, selector: '> pb-formatted-text .s-p1' } }), { key: FORMATTED, selector: '.s-p1' });
  // A declared part keeps its shapes: the class is the class of things,
  // and no own tag tier is offered (one link per element by design).
  const declared = set(STRICT, LINKED, LINK);
  assert.deepEqual(keys(declared), [
    `${LINKED}::${LINK}`,
    `${HERO}::a`,
    `${HERO}::> pb-text ${LINK}`,
    `${HERO}::pb-text ${LINK}`,
    `${PAGE}::a`,
    `${PAGE}::pb-text ${LINK}`,
  ]);
  assert.equal(declared.some((address) => address.key === LINKED && address.selector === 'a'), false);
});

test('facetOf names the facet a target reaches on the selected element: its own selector below, the reached root, part or tag tier above, null for no reach', () => {
  // No target, or one on the element itself: the own facet as given.
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: null }), { key: LINKED, selector: null });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: LINKED, selector: LINK } }), { key: LINKED, selector: LINK });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: LINKED, selector: '> pb-text' } }), { key: LINKED, selector: '> pb-text' });
  // An ancestor rule: the root when the rule matches the element
  // itself, so the switcher's set is the root's and the rows the root's.
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: '> pb-text' } }), { key: LINKED, selector: null });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: PAGE, selector: 'p' } }), { key: LINKED, selector: null });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: '> *' } }), { key: LINKED, selector: null });
  // "Every link in Welcome" chosen from the link part: the part whose
  // tag the rule names, so the rows stay the link's (the done-when).
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: 'a' } }), { key: LINKED, selector: LINK });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: `> pb-text ${LINK}` } }), { key: LINKED, selector: LINK });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: PAGE, selector: `pb-text ${LINK}` } }), { key: LINKED, selector: LINK });
  // A tag tier the annotation's bag carries, behind no part.
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: 'em' } }), { key: LINKED, selector: 'em' });
  // A rule reaching nothing of the element, an image rule on a text
  // block, an owner the tree lacks, no tree: null, the drop signal.
  assert.equal(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: '> pb-image' } }), null);
  assert.equal(facetOf({ tree: TREE, key: HEADING, target: { key: HERO, selector: 'a' } }), null);
  assert.equal(facetOf({ tree: TREE, key: LINKED, target: { key: 'container:00000000-0000-4000-8000-000000000000', selector: '> *' } }), null);
  assert.equal(facetOf({ tree: null, key: LINKED, target: { key: HERO, selector: '> *' } }), null);
  // An ancestor rule on a facet the set does not list still names its
  // facet here: membership is the set's judgement, reach is this one's.
  assert.deepEqual(facetOf({ tree: TREE, key: PARA, target: { key: HERO, selector: '> *' } }), { key: PARA, selector: null });
});

test('a tag facet: every such tag here, then the ancestors\' tag rule and the type-then-tag chains', () => {
  // The linked block carries `em` in its prose, but `em` is no base
  // tag: nothing mints. Its `p` tier reads the root tag, which the
  // tags-inside bag does not carry, so nothing reaches it either.
  assert.deepEqual(keys(set(STRICT, LINKED, 'em')), [`${LINKED}::em`]);
  assert.equal(set(STRICT, LINKED, 'em')[0].label, 'Every emphasis here');
  // A base tag inside: the part's anchor is `a` inside the block.
  const addresses = set(STRICT, LINKED, 'a');
  assert.deepEqual(keys(addresses), [
    `${LINKED}::a`,
    `${HERO}::a`,
    `${HERO}::> pb-text a`,
    `${HERO}::pb-text a`,
    `${PAGE}::a`,
    `${PAGE}::pb-text a`,
  ]);
  assert.equal(addresses[0].label, 'Every link here');
  assert.equal(addresses[2].label, 'Text block links directly in Welcome');
  assert.equal(addresses[3].label, 'Text block links in Welcome');
});

test('a chain facet, an unparseable key or facet has no address set; without a tree the set is the own key alone', () => {
  assert.deepEqual(set(STRICT, HERO, '> pb-text'), []);
  assert.deepEqual(set(STRICT, HERO, '> *'), []);
  assert.deepEqual(set(STRICT, LINKED, '> a'), []);
  assert.deepEqual(set(STRICT, 'nonsense', null), []);
  assert.deepEqual(set(STRICT, LINKED, ':::'), []);
  assert.deepEqual(set(STRICT, LINKED, null, {}, null), [
    { key: LINKED, selector: null, label: 'This element', existing: true },
  ]);
  // A key the tree does not carry: the own key alone.
  assert.deepEqual(keys(set(STRICT, 'block:99999999-0000-4000-8000-000000000009', null)), [
    'block:99999999-0000-4000-8000-000000000009',
  ]);
});

test('an existing relation rule lists as an address where the matcher says it reaches, worded by its relation', () => {
  // `> p + p` reaches the second p-rooted block only: it lists as an
  // existing address there, never on the first paragraph or the heading.
  const siblings = { [`${HERO}::> p + p`]: { default: { color: 'red' } } };
  const second = set(STRICT, PARA, null, siblings);
  assert.deepEqual(second[1], { key: HERO, selector: '> p + p', label: 'Paragraphs right after a paragraph in Welcome', existing: true });
  assert.equal(set(STRICT, LINKED, null, siblings).some((address) => address.selector === '> p + p'), false);
  assert.equal(set(STRICT, HEADING, null, siblings).some((address) => address.selector === '> p + p'), false);
  // `> *:not(pb-image)` reaches every child but the image block.
  const negated = { [`${HERO}::> *:not(pb-image)`]: { default: { color: 'red' } } };
  assert.deepEqual(set(STRICT, HEADING, null, negated)[1], {
    key: HERO,
    selector: '> *:not(pb-image)',
    label: 'Children except images in Welcome',
    existing: true,
  });
  assert.equal(set(STRICT, IMAGE, null, negated).some((address) => address.selector === '> *:not(pb-image)'), false);
  // `> pb-text:has(a)` reaches the linked block only.
  const linked = { [`${HERO}::> pb-text:has(a)`]: { default: { color: 'red' } } };
  assert.equal(set(STRICT, LINKED, null, linked)[1].label, 'Text blocks containing a link in Welcome');
  assert.equal(set(STRICT, PARA, null, linked).some((address) => address.selector === '> pb-text:has(a)'), false);
  // The mintable set never offers a relation: the rest of the set is
  // the fixed shapes, as without the rule.
  assert.deepEqual(keys(second).slice(2), keys(set(STRICT, PARA, null)).slice(1));
  // A qualified or sibling-reached facet is a rule of its own: no set.
  assert.deepEqual(set(STRICT, HERO, '> pb-text:has(a)'), []);
  assert.deepEqual(set(STRICT, HERO, '> p + p'), []);
});

test('a box facet: the box word, the ancestors\' rules ending in the same box that reach the host, then the host\'s shapes with the box appended', () => {
  // The root's own box on an open site: the anchor key leads, worded
  // by the box alone (the element word is the host), then Welcome's
  // shapes and Page's, each with the box appended, the same reach
  // judgement as the pseudo-free root (the matcher answers the host)
  // and the same declaredness (grammar-only but for the tag).
  const before = set(OPEN, LINKED, '&::before');
  assert.deepEqual(keys(before), [
    `${LINKED}::&::before`,
    `${HERO}::> *::before`,
    `${HERO}::> pb-text::before`,
    `${HERO}::pb-text::before`,
    `${HERO}::p::before`,
    `${PAGE}::pb-text::before`,
    `${PAGE}::p::before`,
  ]);
  assert.deepEqual(before[0], { key: LINKED, selector: '&::before', label: 'Before', existing: true });
  assert.deepEqual(before.map((address) => address.label), [
    'Before',
    'All children of Welcome › Before',
    'Text blocks directly in Welcome › Before',
    'Text blocks in Welcome › Before',
    'Every paragraph in Welcome › Before',
    'Text blocks in Page › Before',
    'Every paragraph in Page › Before',
  ]);
  assert.equal(set(OPEN, LINKED, '&::after')[0].label, 'After');
  assert.deepEqual(keys(set(OPEN, HEADING, '&::before')).filter((key) => key.endsWith('::h2::before')), [], 'h2 is no base tag: its box is never offered');
  // An existing rule ending in the SAME box lists first as existing; a
  // rule ending in the other box, or in no box, never reaches this one.
  const map = {
    [`${PAGE}::pb-text::before`]: { default: { content: '""', color: 'red' } },
    [`${HERO}::> pb-text::after`]: { default: { content: '""' } },
    [`${HERO}::> pb-text`]: { default: { color: 'blue' } },
  };
  const existing = set(OPEN, LINKED, '&::before', map);
  assert.deepEqual(keys(existing).slice(0, 3), [`${LINKED}::&::before`, `${PAGE}::pb-text::before`, `${HERO}::> *::before`]);
  assert.deepEqual(existing.map((address) => address.existing), [true, true, false, false, false, false, false]);
  assert.equal(existing.some((address) => address.selector === '> pb-text::after'), false);
  assert.equal(existing.some((address) => address.selector === '> pb-text'), false);
  assert.equal(set(OPEN, LINKED, '&::after', map).find((address) => address.selector === '> pb-text::after')?.existing, true);
  // A part's box: the part's face then the box, the tag rule and the
  // type-then-part chains each ending in the box.
  const partBox = set(OPEN, LINKED, `${LINK}::after`, { [`${PAGE}::a::after`]: { default: { content: '""' } } });
  assert.deepEqual(keys(partBox), [
    `${LINKED}::${LINK}::after`,
    `${PAGE}::a::after`,
    `${HERO}::a::after`,
    `${HERO}::> pb-text ${LINK}::after`,
    `${HERO}::pb-text ${LINK}::after`,
    `${PAGE}::pb-text ${LINK}::after`,
  ]);
  assert.deepEqual(partBox.map((address) => address.label), [
    'This link › After',
    'Every link in Page › After',
    'Every link in Welcome › After',
    'Text block links directly in Welcome › After',
    'Text block links in Welcome › After',
    'Text block links in Page › After',
  ]);
  // A tag tier's box reads the tier's face then the box.
  assert.equal(set(OPEN, LINKED, 'a::before')[0].label, 'Every link here › Before');
  assert.deepEqual(keys(set(OPEN, LINKED, 'a::before')).slice(0, 2), [`${LINKED}::a::before`, `${HERO}::a::before`]);
  // A strict site refuses every derived box (style-guide.js
  // declaredForKey, the roster's declared(): the server answers
  // STYLE_PSEUDO_NOT_DECLARED at the first write), so nothing mints
  // unless the host declares the box; the children's box is always
  // derived. An existing rule still lists: it is there.
  assert.deepEqual(keys(set(STRICT, LINKED, '&::before')), [`${LINKED}::&::before`]);
  assert.deepEqual(keys(set(STRICT, LINKED, '&::before', map)), [`${LINKED}::&::before`, `${PAGE}::pb-text::before`]);
  assert.deepEqual(keys(set(BOXED, LINKED, '&::before')), [
    `${LINKED}::&::before`,
    `${HERO}::> pb-text::before`,
    `${HERO}::pb-text::before`,
    `${HERO}::p::before`,
    `${PAGE}::pb-text::before`,
    `${PAGE}::p::before`,
  ]);
  assert.deepEqual(keys(set(BOXED, LINKED, '&::after')), [`${LINKED}::&::after`], 'the text type declares no after box');
  assert.deepEqual(keys(set(BOXED, LINKED, `${LINK}::after`)), [
    `${LINKED}::${LINK}::after`,
    `${HERO}::> pb-text ${LINK}::after`,
    `${HERO}::pb-text ${LINK}::after`,
    `${PAGE}::pb-text ${LINK}::after`,
  ], 'the link part declares its after box; the base a tag declares none');
  // A chain's box is a rule of its own: no set. Without a tree, the own
  // box alone.
  assert.deepEqual(set(OPEN, HERO, '> pb-text::before'), []);
  assert.deepEqual(set(OPEN, LINKED, '&::before', {}, null), [
    { key: LINKED, selector: '&::before', label: 'Before', existing: true },
  ]);
});

test('facetOf lands an ancestor box rule on the host facet\'s box; boxAddresses lists the doorways of a root or part facet', () => {
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: '> pb-text::before' } }), { key: LINKED, selector: '&::before' });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: '> *::after' } }), { key: LINKED, selector: '&::after' });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: 'a::after' } }), { key: LINKED, selector: `${LINK}::after` });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: PAGE, selector: `pb-text ${LINK}::before` } }), { key: LINKED, selector: `${LINK}::before` });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: 'em::before' } }), { key: LINKED, selector: 'em::before' });
  assert.deepEqual(facetOf({ tree: TREE, key: LINKED, target: { key: LINKED, selector: '&::after' } }), { key: LINKED, selector: '&::after' });
  // A box rule that reaches no host of the element: the drop signal.
  assert.equal(facetOf({ tree: TREE, key: LINKED, target: { key: HERO, selector: '> pb-image::before' } }), null);
  assert.equal(facetOf({ tree: TREE, key: HEADING, target: { key: HERO, selector: 'a::before' } }), null);
  // The doorways: the root's anchor boxes, a part's boxes; none for a
  // tag tier, a chain, or a box (a pseudo is terminal).
  const doors = (guide, key, selector) =>
    boxAddresses({ guide, key, selector, blockTypes: TYPES, sectionLayouts: LAYOUTS });
  assert.deepEqual(doors(OPEN, LINKED, null), [
    { key: LINKED, selector: '&::before', pseudo: 'before' },
    { key: LINKED, selector: '&::after', pseudo: 'after' },
  ]);
  assert.deepEqual(doors(OPEN, LINKED, LINK), [
    { key: LINKED, selector: `${LINK}::before`, pseudo: 'before' },
    { key: LINKED, selector: `${LINK}::after`, pseudo: 'after' },
  ]);
  assert.deepEqual(doors(OPEN, LINKED, 'a'), []);
  assert.deepEqual(doors(OPEN, HERO, '> pb-text'), []);
  assert.deepEqual(doors(OPEN, LINKED, '&::before'), []);
  assert.deepEqual(doors(OPEN, LINKED, `${LINK}::after`), []);
  assert.deepEqual(doors(OPEN, LINKED, ':::'), []);
  // The strict gate: a doorway to a box the host does not declare would
  // seat a target the first write is refused at, so it is withheld.
  assert.deepEqual(doors(STRICT, LINKED, null), []);
  assert.deepEqual(doors(STRICT, LINKED, LINK), []);
  assert.deepEqual(doors(BOXED, LINKED, null), [{ key: LINKED, selector: '&::before', pseudo: 'before' }]);
  assert.deepEqual(doors(BOXED, LINKED, LINK), [{ key: LINKED, selector: `${LINK}::after`, pseudo: 'after' }]);
  assert.deepEqual(doors(BOXED, IMAGE, null), [], 'the image type declares no box');
});

test('ownerName reads the node\'s minted name, else the generic word; addressLabel reads the selector shapes', () => {
  const matcher = createMatcher(TREE);
  assert.equal(ownerName(matcher, HERO), 'Welcome');
  assert.equal(ownerName(matcher, 'container:none'), 'the parent element');
  assert.equal(ownerName(null, 'block:none'), 'the parent element');
  const label = (selector) => addressLabel(STRICT, { key: HERO, selector }, 'Welcome', TYPES, LAYOUTS);
  assert.equal(label('> *'), 'All children of Welcome');
  assert.equal(label('a'), 'Every link in Welcome');
  assert.equal(label('span'), 'Every span in Welcome');
  assert.equal(label('pb-container'), 'Containers in Welcome');
  assert.equal(label('> pb-block'), 'Blocks directly in Welcome');
  assert.equal(label('pb-container > *'), 'Containers › Children in Welcome');
  assert.equal(label(`pb-text ${LINK}`), 'Text block links in Welcome');
  assert.equal(label('pb-text a'), 'Text block links in Welcome');
  // The child-combinated type reads "directly in", so the two shapes the
  // set offers side by side never share a label.
  assert.equal(label(`> pb-text ${LINK}`), 'Text block links directly in Welcome');
  assert.equal(label('> pb-text a'), 'Text block links directly in Welcome');
  assert.equal(label('pb-container a'), 'Container links in Welcome');
  // Relations: one group reads its plural subject and phrases; a part
  // or tag after a type carries its own relation; anything else falls
  // back to the chevron label.
  assert.equal(label('> p + p'), 'Paragraphs right after a paragraph in Welcome');
  assert.equal(label('> p ~ p'), 'Paragraphs after a paragraph in Welcome');
  assert.equal(label('> p + *'), 'Children right after a paragraph in Welcome');
  assert.equal(label('> pb-text:has(a)'), 'Text blocks containing a link in Welcome');
  assert.equal(label('> pb-text:has(+ pb-image)'), 'Text blocks right before an image in Welcome');
  assert.equal(label('> pb-text:not(:has(a))'), 'Text blocks without a link in Welcome');
  assert.equal(label('> *:not(pb-image)'), 'Children except images in Welcome');
  assert.equal(label('> pb-text:has(a) + pb-text'), 'Text blocks right after a text containing a link in Welcome');
  assert.equal(label(`> pb-text ${LINK} ~ ${LINK}`), 'Text block links after a link directly in Welcome');
  assert.equal(label('pb-text a:has(img)'), 'Text block links containing an img in Welcome');
  assert.equal(label(`> pb-text:has(a) ${LINK}`), 'Text blocks containing a link › Link in Welcome');
  // A rule ending in a generated box: the host's sentence, then the box
  // word as one more step (the label seam's chevron).
  assert.equal(label('> *::before'), 'All children of Welcome › Before');
  assert.equal(label('> pb-text::after'), 'Text blocks directly in Welcome › After');
  assert.equal(label('a::before'), 'Every link in Welcome › Before');
  assert.equal(label(`> pb-text ${LINK}::after`), 'Text block links directly in Welcome › After');
  assert.equal(label('> pb-text:has(a)::before'), 'Text blocks containing a link in Welcome › Before');
});

test('withheldAt judges the given properties at the address\'s landing declaration, the reason naming the owner', () => {
  const address = { key: HERO, selector: 'a' };
  assert.deepEqual(addressDeclaration(STRICT, address, TYPES, LAYOUTS), STRICT.base.a);
  const withheld = withheldAt({
    guide: STRICT,
    address,
    properties: ['color', 'font-weight', 'font-size'],
    blockTypes: TYPES,
    sectionLayouts: LAYOUTS,
    tree: TREE,
  });
  assert.deepEqual(Object.keys(withheld), ['color', 'font-size']);
  assert.equal(
    withheld.color,
    'color is not allowed at "a" on Welcome: the style guide does not grant it there. Choose this element alone to set it here.',
  );
  // The type-then-part chain lands on the part's own node.
  assert.deepEqual(
    withheldAt({
      guide: STRICT,
      address: { key: HERO, selector: `> pb-text ${LINK}` },
      properties: ['color', 'font-size'],
      blockTypes: TYPES,
      sectionLayouts: LAYOUTS,
    }),
    {
      'font-size':
        'font-size is not allowed at "> pb-text .text-block-link" on the parent element: the style guide does not grant it there. Choose this element alone to set it here.',
    },
  );
  // An undeclared landing grants nothing: everything is withheld.
  assert.deepEqual(
    Object.keys(withheldAt({ guide: STRICT, address: { key: HERO, selector: 'span' }, properties: ['color'] })),
    ['color'],
  );
  assert.equal(
    withheldReason('reach', { property: 'color', address, owner: 'Welcome', part: 'link' }),
    '"a" on Welcome does not reach the link this row styles; choose an address that does, or this element alone.',
  );
  assert.equal(boxLabel(STRICT, LINKED, LINK, TYPES, LAYOUTS), 'link');
  assert.equal(boxLabel(STRICT, LINKED, '.unknown', TYPES, LAYOUTS), '.unknown');
  assert.equal(boxLabel(STRICT, LINKED, ''), 'element');
});
