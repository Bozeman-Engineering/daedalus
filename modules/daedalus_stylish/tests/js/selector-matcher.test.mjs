/**
 * Self-asserting tests for the selector matcher (js/src/selector-matcher.js):
 * the tree facts, the four combinators (child, descendant, next and
 * subsequent sibling), type and tag compounds, the `:has()` and `:not()`
 * qualifiers, the region wrappers of a multi-region layout, reaches()
 * for the root, a tag inside and a part (after a type, or by the tag it
 * renders) and its relational limits, the terminal pseudo-element
 * (hosts for matching, agreement by name for reaches), the structural
 * facts, and the
 * roster over the facts (typeOfAddress, presentRoster) the code view's
 * completion reads.
 *
 * Parity with the server's SelectorMatcher is SelectorMatcherTest's
 * business (selector-matcher-cases.mjs); this suite pins the client
 * reads on their own.
 *
 * Run directly (`node --test tests/js/selector-matcher.test.mjs`) or via
 * the suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  createMatcher,
  presentRoster,
  structuralHolds,
  typeMatches,
  typeOfAddress,
} from '../../js/src/selector-matcher.js';
import { parseSelector } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { TREE } from './selector-matcher-fixture.mjs';

const matcher = createMatcher(TREE);

test('the tree facts: key, kind, address, tag, the tags inside, parts, the minted name and type, place', () => {
  assert.deepEqual(matcher.facts('block:t3'), {
    key: 'block:t3',
    kind: 'block',
    address: 'block_type:text',
    tag: 'p',
    tags: ['em'],
    parts: {},
    name: null,
    type: null,
    index: 0,
    count: 3,
    childCount: 0,
    parent: 'container:sec-b',
  });
  assert.deepEqual(matcher.facts('container:sec-a'), {
    key: 'container:sec-a',
    kind: 'container',
    address: 'layout:daedalus_stylish_container',
    tag: 'section',
    tags: [],
    parts: {},
    name: 'Hero',
    type: 'Section',
    index: 0,
    count: 4,
    childCount: 5,
    parent: null,
  });
  // The anatomy: the declared part tree flattened (a nested part right
  // after its parent, with its own tag), the hoisted part at the root's
  // tag, the tagless part kept with null; every part tag joins the tags
  // inside, so the heading-rooted block carries an anchor.
  assert.deepEqual(matcher.facts('block:t7'), {
    key: 'block:t7',
    kind: 'block',
    address: 'block_type:text',
    tag: 'h6',
    tags: ['a', 'svg', 'h6'],
    parts: { '.text-block-link': 'a', '.text-block-link__icon': 'svg', '.text-block-note': null, '.text-block': 'h6' },
    name: null,
    type: null,
    index: 0,
    count: 2,
    childCount: 0,
    parent: 'container:sec-e',
  });
  // The minted words ride as facts: an unrenamed block's name is its type.
  assert.deepEqual([matcher.facts('block:t1').name, matcher.facts('block:t1').type], ['Intro', 'Text']);
  assert.deepEqual(matcher.facts('block:t8').tags, ['strong', 'a']);
  assert.deepEqual(matcher.facts('block:t8').parts, { '.text-block-link': 'a' });
  // The identity: the minted instances the rich text carries fold into
  // the parts as class → tag, a nested carrier right after its parent,
  // and their tags join the tags inside (the heading and the list are
  // known only through their instances here).
  assert.deepEqual(matcher.facts('block:f').parts, { '.s-p1': 'p', '.s-h1': 'h2', '.s-ul': 'ul', '.s-li': 'li' });
  assert.deepEqual(matcher.facts('block:f').tags, ['p', 'a', 'h2', 'ul', 'li']);
  // A plugin block's address strips the derivative; unannotated, it has
  // no tag. An open render's field tags and field instances count as
  // inside the block.
  assert.equal(matcher.facts('block:p').address, 'block_plugin:system_menu_block');
  assert.equal(matcher.facts('block:p').tag, null);
  assert.deepEqual(matcher.facts('block:t6').tags, ['a', 'p']);
  assert.deepEqual(matcher.facts('block:t6').parts, { '.s-q1': 'p' });
  // A region wrapper is a real sibling level: a block in one is
  // addressable through its section.
  assert.equal(matcher.facts('block:t4').parent, 'container:sec-c');
  assert.equal(matcher.facts('block:t4').count, 1);
  assert.equal(matcher.facts('block:ghost'), null);
  assert.equal(matcher.has('container:sec-d'), true);
  assert.equal(matcher.has('entity:root'), false);
});

test('ancestors are addressable keys, farthest first; regions are transparent', () => {
  assert.deepEqual(matcher.ancestors('block:t3'), ['container:sec-a', 'container:sec-b']);
  assert.deepEqual(matcher.ancestors('block:t5'), ['container:sec-c']);
  assert.deepEqual(matcher.ancestors('container:sec-a'), []);
  assert.deepEqual(matcher.ancestors('block:ghost'), []);
});

test('the child combinator anchors to the owner; a type or tag filters it', () => {
  assert.deepEqual(matcher.matching('container:sec-a', '> *'), ['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p']);
  assert.deepEqual(matcher.matching('container:sec-a', '> pb-text'), ['block:t1', 'block:t2']);
  assert.deepEqual(matcher.matching('container:sec-a', '> p'), ['block:t1']);
  assert.deepEqual(matcher.matching('container:sec-a', '> pb-block'), ['block:t1', 'block:t2', 'block:f', 'block:p']);
  assert.deepEqual(matcher.matching('container:sec-a', '> pb-container'), ['container:sec-b']);
  assert.deepEqual(matcher.matching('container:sec-a', '> * > *'), ['block:t3', 'block:img', 'container:sec-d']);
  assert.deepEqual(matcher.matching('container:sec-a', '> pb-container > pb-text'), ['block:t3']);
});

test('descendant compounds reach the whole subtree; the owner is in scope for a non-subject compound', () => {
  assert.deepEqual(matcher.matching('container:sec-a', 'pb-text'), ['block:t1', 'block:t2', 'block:t3']);
  assert.deepEqual(matcher.matching('container:sec-a', 'p'), ['block:t1', 'block:t3']);
  assert.deepEqual(matcher.matching('container:sec-a', 'pb-block'), ['block:t1', 'block:t2', 'block:f', 'block:t3', 'block:img', 'block:p']);
  // `pb-container > *` prints without :scope, so the owner itself is the
  // container the first compound matches: its own children are targets,
  // as the browser sees them.
  assert.deepEqual(matcher.matching('container:sec-a', 'pb-container > *'), [
    'block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:t3', 'block:img', 'container:sec-d', 'block:p',
  ]);
  assert.equal(matcher.matches('container:sec-a', 'pb-container', 'container:sec-a'), true);
  assert.equal(matcher.matches('container:sec-a', '> pb-text', 'block:t3'), false);
  assert.equal(matcher.matches('container:sec-b', '> pb-text', 'block:t3'), true);
  // Out of scope: a sibling is never a target.
  assert.equal(matcher.matches('block:t1', '> *', 'block:t2'), false);
  assert.equal(matcher.matches('block:ghost', '> *', 'block:t2'), false);
});

test('region wrappers are the direct children of a multi-region section', () => {
  assert.deepEqual(matcher.matching('container:sec-c', '> *'), []);
  assert.deepEqual(matcher.matching('container:sec-c', '> pb-text'), []);
  assert.deepEqual(matcher.matching('container:sec-c', '> * > *'), ['block:t4', 'block:t5', 'block:t6']);
  assert.deepEqual(matcher.matching('container:sec-c', 'pb-text'), ['block:t4', 'block:t5', 'block:t6']);
});

test('a part class is not a tree fact: it matches no node, before or as the subject', () => {
  assert.deepEqual(matcher.matching('container:sec-a', '.cls'), []);
  assert.deepEqual(matcher.matching('container:sec-a', '.cls > *'), []);
  assert.deepEqual(matcher.matching('container:sec-e', '> pb-text .text-block-link'), []);
  // A tag a part renders is inside its element, never a root.
  assert.deepEqual(matcher.matching('container:sec-e', 'a'), []);
  assert.deepEqual(matcher.matching('container:sec-e', '> *'), ['block:t7', 'container:sec-f']);
});

test('reaches: the root, a tag inside (never through a child combinator), a part after a type', () => {
  assert.equal(matcher.reaches('container:sec-a', 'p', 'block:t1'), true);
  assert.equal(matcher.reaches('container:sec-a', 'p', 'block:f'), false);
  assert.equal(matcher.reaches('container:sec-a', 'p', 'block:f', 'p'), true);
  assert.equal(matcher.reaches('container:sec-a', '> p', 'block:f', 'p'), false);
  assert.equal(matcher.reaches('container:sec-a', 'a', 'block:t1', 'a'), true);
  assert.equal(matcher.reaches('container:sec-a', 'a', 'block:t2', 'a'), false);
  assert.equal(matcher.reaches('container:sec-a', 'pb-text a', 'block:t1', 'a'), true);
  assert.equal(matcher.reaches('container:sec-a', '> pb-text em', 'block:t3', 'em'), false);
  assert.equal(matcher.reaches('container:sec-a', 'pb-text em', 'block:t3', 'em'), true);
  assert.equal(matcher.reaches('container:sec-a', 'pb-formatted-text p', 'block:f', 'p'), true);
  assert.equal(matcher.reaches('container:sec-b', 'p', 'block:f', 'p'), false);
  assert.equal(matcher.reaches('container:sec-c', 'a', 'block:t6', 'a'), true);
  // A part after a type: the class subject reaches the part of the
  // element the type matches (a direct child by `>`, a nested one by
  // descent); a lone class is the owner's own anatomy.
  assert.equal(matcher.reaches('container:sec-e', '> pb-text .text-block-link', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches('container:sec-e', '> pb-text .text-block-link', 'block:t8', '.text-block-link'), false);
  assert.equal(matcher.reaches('container:sec-e', 'pb-text > .text-block-link', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches('container:sec-e', '.text-block-link', 'block:t7', '.text-block-link'), false);
  // A chain or the children is a rule of its own, never a tier.
  assert.equal(matcher.reaches('container:sec-a', '> *', 'block:t1', '> *'), false);
});

test('reaches: a part by the tag it renders, by descent only, never a part declaring no tag', () => {
  const e = 'container:sec-e';
  // The container's `a` rule styles the link part's anchor: by descent
  // from the owner or from a prefix matching the element or an ancestor.
  assert.equal(matcher.reaches(e, 'a', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, '> pb-text a', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, 'a', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, 'pb-container a', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, '> pb-container a', 'block:t8', '.text-block-link'), true);
  // A child combinator into the tag cannot be backed by the tree.
  assert.equal(matcher.reaches(e, '> a', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, '> pb-text > a', 'block:t7', '.text-block-link'), false);
  // The prefix must match the element or an ancestor; the rule must
  // scope the element.
  assert.equal(matcher.reaches(e, '> pb-text a', 'block:t8', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, '> pb-container a', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches('container:sec-f', 'a', 'block:t7', '.text-block-link'), false);
  // A part declaring no tag is unknown, and an unknown never matches.
  assert.equal(matcher.reaches(e, 'small', 'block:t7', '.text-block-note'), false);
  assert.equal(matcher.reaches(e, 'a', 'block:t7', '.text-block-note'), false);
  // Its own tag, not a sibling part's; a nested part and a hoisted part
  // are parts like any other; an undeclared part is nothing.
  assert.equal(matcher.reaches(e, 'svg', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, 'svg', 'block:t7', '.text-block-link__icon'), true);
  assert.equal(matcher.reaches(e, 'h6', 'block:t7', '.text-block'), true);
  assert.equal(matcher.reaches(e, 'a', 'block:t7', '.ghost'), false);
  // The tag tier counts the parts' tags beside the prose's.
  assert.equal(matcher.reaches(e, 'a', 'block:t7', 'a'), true);
  assert.equal(matcher.reaches(e, 'pb-text a', 'block:t8', 'a'), true);
  assert.equal(matcher.reaches(e, '> pb-text a', 'block:t8', 'a'), false);
  assert.equal(matcher.reaches(e, 'em', 'block:t7', 'em'), false);
});

test('reaches: an identity class is judged by presence on the node, a declared class keeps the anatomy posture', () => {
  const a = 'container:sec-a';
  // The tag a minted instance is: the section's `p` rule styles the
  // formatted text block's minted paragraph exactly as it styles a link
  // part's anchor, by descent; the nested list item by its own tag, not
  // its parent's.
  assert.equal(matcher.reaches(a, 'p', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(a, 'pb-formatted-text p', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(a, 'h2', 'block:f', '.s-p1'), false);
  assert.equal(matcher.reaches(a, 'li', 'block:f', '.s-li'), true);
  assert.equal(matcher.reaches(a, 'ul', 'block:f', '.s-li'), false);
  assert.equal(matcher.reaches('container:sec-c', 'p', 'block:t6', '.s-q1'), true);
  // The class subject: an identity class reaches the one node whose
  // parts carry it, never another node the type matches (the class is
  // worn by one element in the world) and never a class the node lacks.
  assert.equal(matcher.reaches(a, '> pb-formatted-text .s-p1', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(a, '> pb-formatted-text .s-p1', 'block:t1', '.s-p1'), false);
  assert.equal(matcher.reaches(a, '> pb-block .s-p1', 'block:t1', '.s-p1'), false);
  assert.equal(matcher.reaches(a, '> pb-formatted-text .s-p9', 'block:f', '.s-p9'), false);
  assert.equal(matcher.reaches(a, '> pb-formatted-text .s-li', 'block:f', '.s-li'), true);
  // A declared class is the type's anatomy: the chain reaches a text
  // block that renders no link, a legal address either way.
  assert.equal(matcher.reaches(a, '> pb-text .text-block-link', 'block:t2', '.text-block-link'), true);
  // The tree never matches a class in a chain; `:not()` judges it on the
  // node's own parts, an identity class included.
  assert.deepEqual(matcher.matching(a, '> pb-formatted-text .s-p1'), []);
  assert.deepEqual(matcher.matching(a, '> pb-block:not(.s-p1)'), ['block:t1', 'block:t2', 'block:p']);
  assert.deepEqual(matcher.matching(a, '> pb-block:not(.s-p9)'), ['block:t1', 'block:t2', 'block:f', 'block:p']);
});

test('a sibling combinator steps along the parent\'s ordered children: `+` the previous sibling, `~` any earlier one', () => {
  const g = 'container:sec-g';
  assert.deepEqual(matcher.matching(g, '> p + p'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> p ~ p'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> p + *'), ['block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> p ~ *'), ['block:t10', 'block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, 'pb-text ~ pb-image'), ['block:img2']);
  assert.equal(matcher.matches(g, '> p + p', 'block:t10'), true);
  assert.equal(matcher.matches(g, '> p + p', 'block:t9'), false);
});

test(':has() anchors its argument at the qualified node, every lead relating to it; a tag by descent reads the bag', () => {
  const g = 'container:sec-g';
  // The linked block's part renders an anchor (the bag); the bag has no
  // depth, so `> a` on a block is never backed.
  assert.deepEqual(matcher.matching(g, '> pb-text:has(a)'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> pb-text:has(> a)'), []);
  // The heading is the text block right before the image; every text
  // block has an image somewhere after it.
  assert.deepEqual(matcher.matching(g, '> pb-text:has(+ pb-image)'), ['block:t11']);
  assert.deepEqual(matcher.matching(g, '> pb-text:has(~ pb-image)'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> pb-container:has(> pb-image)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:has(pb-image)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> pb-container:has(> pb-image) > *'), ['block:img3']);
  assert.deepEqual(matcher.matching('container:sec-a', '> pb-container:has(> pb-image)'), ['container:sec-b']);
  // A region wrapper anchors a :has() like any node, though the regions
  // themselves are never listed (unaddressable).
  assert.deepEqual(matcher.matching('container:sec-c', '> *:has(> pb-text)'), []);
  assert.deepEqual(matcher.matching('container:sec-c', '> *:has(> pb-text) > *'), ['block:t4', 'block:t5', 'block:t6']);
});

test(':not() excludes what its compound matches on the node; a part class there is judged on the node\'s own anatomy', () => {
  const g = 'container:sec-g';
  assert.deepEqual(matcher.matching(g, '> *:not(pb-image)'), ['block:t9', 'block:t10', 'block:t11', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:not(pb-image):not(pb-text)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> pb-text:not(:has(a))'), ['block:t9', 'block:t11']);
  // A node whose parts carry the class (hoisted onto its root like the
  // heading's .featured, or nested inside it like the link part) is
  // excluded: the flattened parts do not tell the two apart, and
  // excluding both never over-claims.
  assert.deepEqual(matcher.matching(g, '> pb-text:not(.featured)'), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(g, '> pb-text:not(.text-block-link)'), ['block:t9', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> pb-text:has(a):not(.featured)'), ['block:t10']);
});

test('reaches: a tag subject reached by a sibling step or carrying a qualifier is never answered inside; roots and parts behind a relational prefix are', () => {
  const g = 'container:sec-g';
  assert.equal(matcher.reaches(g, '> p + p', 'block:t10', 'p'), false);
  assert.equal(matcher.reaches(g, 'p:has(a)', 'block:t10', 'p'), false);
  assert.equal(matcher.reaches(g, '> p + p', 'block:t10'), true);
  assert.equal(matcher.reaches(g, '> p + p', 'block:t9'), false);
  assert.equal(matcher.reaches(g, '> p + p a', 'block:t10', '.text-block-link'), true);
  assert.equal(matcher.reaches(g, '> p + p a', 'block:t9', '.text-block-link'), false);
  assert.equal(matcher.reaches(g, '> p + p a', 'block:t10', 'a'), true);
  assert.equal(matcher.reaches(g, '> pb-text:has(a) .text-block-link', 'block:t10', '.text-block-link'), true);
  assert.equal(matcher.reaches(g, '> pb-text:not(:has(a)) .text-block-link', 'block:t10', '.text-block-link'), false);
  assert.equal(matcher.reaches(g, '> pb-text:has(a) a', 'block:t10', 'a'), true);
  assert.equal(matcher.reaches(g, '> pb-text:has(a) a', 'block:t9', 'a'), false);
});

test('a terminal pseudo-element: matching answers the hosts, reaches requires the pseudos to agree by name', () => {
  const a = 'container:sec-a';
  const e = 'container:sec-e';
  const g = 'container:sec-g';
  // The tree never sees a generated box: a pseudo rule matches its
  // hosts, and the owner's own pseudo (the anchor, no compounds) the
  // owner alone.
  assert.deepEqual(matcher.matching(a, '> pb-text::before'), ['block:t1', 'block:t2']);
  assert.deepEqual(matcher.matching('block:t1', '&::before'), ['block:t1']);
  assert.deepEqual(matcher.matching('block:ghost', '&::before'), []);
  assert.equal(matcher.matches('block:t1', '&::after', 'block:t1'), true);
  assert.equal(matcher.matches('block:t1', '&::after', 'block:t2'), false);
  assert.equal(matcher.matches(a, '&::after', 'block:t1'), false);
  assert.equal(matcher.matches(a, '> pb-text::before', 'block:t1'), true);
  // The own pseudo: the element's own rule reaches its own box and only
  // that box; a rule for one box never lands on the root or the other
  // box, and a pseudo-free rule never lands on a box.
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t1', '&::before'), true);
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t1', '&::after'), false);
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t1'), false);
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t2', '&::before'), false);
  // An ancestor's `> pb-text::before` reaches a text block child's
  // `&::before`, not its `&::after`, not its root, not a nested text
  // block; by descent it reaches the nested one too.
  assert.equal(matcher.reaches(a, '> pb-text::before', 'block:t1', '&::before'), true);
  assert.equal(matcher.reaches(a, '> pb-text::before', 'block:t1', '&::after'), false);
  assert.equal(matcher.reaches(a, '> pb-text::before', 'block:t1'), false);
  assert.equal(matcher.reaches(a, '> pb-text', 'block:t1', '&::before'), false);
  assert.equal(matcher.reaches(a, '> pb-text::before', 'block:t3', '&::before'), false);
  assert.equal(matcher.reaches(a, 'pb-text::after', 'block:t3', '&::after'), true);
  assert.equal(matcher.reaches(a, '> pb-text::before', 'block:f', '&::before'), false);
  assert.equal(matcher.reaches('container:sec-b', '> pb-text::before', 'block:t1', '&::before'), false);
  // A part's pseudo: the class subject's box, or the box of the tag the
  // part renders, reached exactly as the part is; the part alone is not
  // its box.
  assert.equal(matcher.reaches(e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link::before'), true);
  assert.equal(matcher.reaches(e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link::after'), false);
  assert.equal(matcher.reaches(e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, '> pb-text .text-block-link', 'block:t7', '.text-block-link::before'), false);
  assert.equal(matcher.reaches(e, '> pb-text .text-block-link::before', 'block:t8', '.text-block-link::before'), false);
  assert.equal(matcher.reaches(e, 'pb-text > .text-block-link::after', 'block:t8', '.text-block-link::after'), true);
  assert.equal(matcher.reaches(e, 'a::before', 'block:t7', '.text-block-link::before'), true);
  assert.equal(matcher.reaches(e, '> a::before', 'block:t7', '.text-block-link::before'), false);
  assert.equal(matcher.reaches(e, 'a::before', 'block:t7', '.text-block-note::before'), false);
  assert.equal(matcher.reaches(e, 'h6::after', 'block:t7', '.text-block::after'), true);
  // The tag tier's pseudo: the box of every anchor inside.
  assert.equal(matcher.reaches(a, 'a::before', 'block:t1', 'a::before'), true);
  assert.equal(matcher.reaches(a, 'a::before', 'block:t1', 'a'), false);
  assert.equal(matcher.reaches(a, 'a', 'block:t1', 'a::before'), false);
  assert.equal(matcher.reaches(a, 'a::before', 'block:t2', 'a::before'), false);
  // A chain's pseudo: the sibling step and the qualifier hold on the
  // host exactly as they do without the pseudo.
  assert.equal(matcher.reaches(g, '> p + p::after', 'block:t10', '&::after'), true);
  assert.equal(matcher.reaches(g, '> p + p::after', 'block:t9', '&::after'), false);
  assert.equal(matcher.reaches(g, '> pb-text:has(a)::before', 'block:t10', '&::before'), true);
  assert.equal(matcher.reaches(g, '> pb-text:has(a)::before', 'block:t9', '&::before'), false);
  assert.equal(matcher.reaches(g, '> p + p a::before', 'block:t10', '.text-block-link::before'), true);
  assert.equal(matcher.reaches(g, '> p + p::before', 'block:t10', 'p::before'), false);
  // A position that is a rule of its own, pseudo or not, is never a
  // tier; a selector key with a pseudo reads its owner's place.
  assert.equal(matcher.reaches(a, '> *::before', 'block:t1', '> *::before'), false);
  assert.equal(matcher.reaches(a, '> pb-text::before', 'block:t1', '> pb-text::before'), false);
  assert.equal(matcher.satisfies('block:t1::&::before', ':first-child'), true);
  assert.equal(matcher.satisfies('block:t2::.text-block-link::after', ':nth-child(even)'), true);
  // The parsed record carries the pseudo; a bare compounds array has
  // dropped it and is refused, as is a pseudo outside the grammar.
  assert.equal(matcher.reaches(a, parseSelector('> pb-text::before'), 'block:t1', parseSelector('&::before')), true);
  assert.throws(() => matcher.reaches(a, parseSelector('> pb-text::before').compounds, 'block:t1', '&::before'), TypeError);
  assert.throws(() => matcher.reaches(a, 'a', 'block:t1', 'a::marker'), TypeError);
});

test('structural facts read the sibling index; a block is never empty', () => {
  assert.equal(matcher.satisfies('block:t1', ':first-child'), true);
  assert.equal(matcher.satisfies('block:t1', ':nth-child(odd)'), true);
  assert.equal(matcher.satisfies('block:t2', ':nth-child(even)'), true);
  assert.equal(matcher.satisfies('block:p', ':last-child'), true);
  assert.equal(matcher.satisfies('block:t4', ':only-child'), true);
  assert.equal(matcher.satisfies('container:sec-d', ':empty'), true);
  assert.equal(matcher.satisfies('container:sec-d', ':last-child'), true);
  assert.equal(matcher.satisfies('container:sec-b', ':empty'), false);
  assert.equal(matcher.satisfies('block:t1', ':empty'), false);
  assert.equal(matcher.satisfies('block:t1::> *', ':first-child'), true);
  assert.equal(matcher.satisfies('block:t1', ':nth-child(3n+1)'), false);
  assert.equal(matcher.satisfies('block:ghost', ':first-child'), false);
  assert.equal(structuralHolds(':last-child', { index: 2, count: 3, empty: false }), true);
});

test('the negated facts hold where their base does not; an unknown wrapper stays unknown inside :not()', () => {
  // Every child of the hero section but the last is not the last child;
  // a selector key reads its owner's place here too.
  for (const key of ['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:t1::> *']) {
    assert.equal(matcher.satisfies(key, ':not(:last-child)'), true, key);
  }
  assert.equal(matcher.satisfies('block:p', ':not(:last-child)'), false);
  assert.equal(matcher.satisfies('container:sec-d', ':not(:last-child)'), false);
  assert.equal(matcher.satisfies('block:t1', ':not(:first-child)'), false);
  assert.equal(matcher.satisfies('block:t2', ':not(:first-child)'), true);
  assert.equal(matcher.satisfies('block:t4', ':not(:only-child)'), false);
  assert.equal(matcher.satisfies('block:t5', ':not(:only-child)'), true);
  assert.equal(matcher.satisfies('container:sec-b', ':not(:empty)'), true);
  assert.equal(matcher.satisfies('container:sec-d', ':not(:empty)'), false);
  // A block is never empty, so it is always not empty.
  assert.equal(matcher.satisfies('block:t1', ':not(:empty)'), true);
  assert.equal(matcher.satisfies('block:t1', ':not(:nth-child(3n+1))'), false);
  assert.equal(matcher.satisfies('block:ghost', ':not(:last-child)'), false);
  assert.equal(structuralHolds(':not(:last-child)', { index: 2, count: 3, empty: false }), false);
  assert.equal(structuralHolds(':not(:last-child)', { index: 1, count: 3, empty: false }), true);
});

test('a selector outside the grammar is a caller bug', () => {
  assert.throws(() => matcher.matching('container:sec-a', 'p:hover'), TypeError);
  assert.throws(() => matcher.matches('container:sec-a', '', 'block:t1'), TypeError);
});

test('typeMatches: the two families by prefix, a block type exactly', () => {
  assert.equal(typeMatches('pb-container', 'layout:x'), true);
  assert.equal(typeMatches('pb-block', 'block_plugin:x'), true);
  assert.equal(typeMatches('pb-block', 'layout:x'), false);
  assert.equal(typeMatches('pb-formatted-text', 'block_type:formatted_text'), true);
  assert.equal(typeMatches('pb-text', 'block_type:formatted_text'), false);
});

test('typeOfAddress: bundles by name, the kinds for layouts and plugins', () => {
  assert.equal(typeOfAddress('block_type:text'), 'pb-text');
  assert.equal(typeOfAddress('block_type:formatted_text'), 'pb-formatted-text');
  assert.equal(typeOfAddress('layout:daedalus_stylish_container'), 'pb-container');
  assert.equal(typeOfAddress('block_plugin:system_menu_block'), 'pb-block');
  assert.equal(typeOfAddress(null), null);
});

test('presentRoster: types and tags in first-seen order, inner tags on request', () => {
  const facts = [
    { address: 'block_type:text', tag: 'p', tags: ['a', 'strong'] },
    { address: 'block_type:text', tag: 'h2', tags: [] },
    { address: 'layout:daedalus_stylish_container', tag: 'div', tags: [] },
    { address: 'block_plugin:x', tag: null, tags: [] },
    null,
  ];
  assert.deepEqual(presentRoster(facts), {
    types: ['pb-text', 'pb-container', 'pb-block'],
    tags: ['p', 'h2', 'div'],
  });
  assert.deepEqual(presentRoster(facts, { inner: true }).tags, ['p', 'a', 'strong', 'h2', 'div']);
  assert.deepEqual(presentRoster(null), { types: [], tags: [] });
  // Over the matcher's facts the inner roster carries the parts' tags:
  // the Links section's children offer `a` though no prose links.
  const inner = presentRoster(
    matcher.matching('container:sec-e', '> *').map((key) => matcher.facts(key)),
    { inner: true },
  );
  assert.deepEqual(inner, { types: ['pb-text', 'pb-container'], tags: ['h6', 'a', 'svg', 'div'] });
  assert.deepEqual(
    presentRoster(matcher.matching('container:sec-e', '> *').map((key) => matcher.facts(key))).tags,
    ['h6', 'div'],
  );
});
