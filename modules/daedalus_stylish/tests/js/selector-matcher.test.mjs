/**
 * Self-asserting tests for the selector matcher (js/src/selector-matcher.js):
 * the tree facts, the entity root of the document (the farthest
 * ancestor, the owner of the sections, keyless without a uuid), a
 * block's field as a side-table record (inside its block for reach,
 * never a node), the four combinators (child, descendant, next and
 * subsequent sibling), type and tag compounds, the `:has()`, `:not()`
 * and `:is()` qualifiers, the region wrappers of a multi-region layout,
 * reaches()
 * for the root, a tag inside and a part (after a type, or by the tag it
 * renders) and its relational limits, the terminal pseudo-element
 * (hosts for matching, agreement by name for reaches), the position
 * qualifiers (judged by node, reach-neutral on a tag, the qualified
 * anchor against the owner), and the roster over the facts
 * (typeOfAddress, presentRoster) the code view's completion reads.
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
  nthHolds,
  presentRoster,
  structuralHolds,
  typeMatches,
  typeOfAddress,
} from '../../js/src/selector-matcher.js';
import { parseSelector } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { DOCUMENT, KEYLESS, MAP } from './selector-matcher-fixture.mjs';

const matcher = createMatcher(DOCUMENT, MAP);

test('the tree facts: key, kind, address, tag, the tags inside, parts, the minted name, type and icon, place', () => {
  assert.deepEqual(matcher.facts('block:t3'), {
    key: 'block:t3',
    kind: 'block',
    address: 'block_type:text',
    tag: 'p',
    tags: ['em'],
    parts: {},
    wear: [],
    name: null,
    type: null,
    icon: null,
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
    wear: [],
    name: 'Hero',
    type: 'Section',
    icon: '/icons/section.svg',
    index: 0,
    count: 4,
    childCount: 5,
    parent: 'entity:page',
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
    wear: [],
    name: null,
    type: null,
    icon: null,
    index: 0,
    count: 2,
    childCount: 0,
    parent: 'container:sec-e',
  });
  // The wear: the classes an element entry's references stamp (the root
  // fragment's plain variant class, a distributed fragment's slot-encoded
  // one), a reference that cannot be a class name stamping nothing, a
  // rule key's wear stamping nothing, and no map wearing nothing.
  assert.deepEqual(matcher.facts('block:t11').wear, ['daedalus-stylish-variant--featured']);
  assert.deepEqual(matcher.facts('block:t10').wear, ['daedalus-stylish-variant--card--region-first--block-text-1']);
  assert.deepEqual(matcher.facts('block:t9').wear, []);
  assert.deepEqual(createMatcher(DOCUMENT).facts('block:t11').wear, []);
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
  // A field node names the page's field, never a per-bundle plugin id: its
  // address is the field_block base plugin, read off the node alone.
  const fielded = createMatcher([
    {
      kind: 'container',
      uuid: 'sec-f',
      layout_id: 'daedalus_stylish_container',
      children: [{ kind: 'block', uuid: 'fb', field: 'body' }],
    },
  ]);
  assert.equal(fielded.facts('block:fb').address, 'block_plugin:field_block');
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

test('ancestors are addressable keys, farthest first, the entity farthest; regions are transparent', () => {
  assert.deepEqual(matcher.ancestors('block:t3'), ['entity:page', 'container:sec-a', 'container:sec-b']);
  assert.deepEqual(matcher.ancestors('block:t5'), ['entity:page', 'container:sec-c']);
  assert.deepEqual(matcher.ancestors('container:sec-a'), ['entity:page']);
  assert.deepEqual(matcher.ancestors('entity:page'), []);
  assert.deepEqual(matcher.ancestors('block:ghost'), []);
});

test('the entity root: the document\'s one root, addressed by the bare kind, its bag its own markup alone', () => {
  const page = 'entity:page';
  assert.equal(matcher.has(page), true);
  // The root's facts: the entity kind's address, the minted words, a
  // tagless markup (no base tag, nothing inside), the sections its
  // children, nothing above it.
  assert.deepEqual(matcher.facts(page), {
    key: page,
    kind: 'entity',
    address: 'kind:entity',
    tag: null,
    tags: [],
    parts: {},
    wear: [],
    name: 'Welcome',
    type: 'Page',
    icon: '/icons/page.svg',
    index: 0,
    count: 1,
    childCount: 4,
    parent: null,
  });
  // The host's fields render outside the canvas root: a root annotated
  // with field markup folds none of it into its bag.
  const fielded = createMatcher([
    {
      kind: 'entity',
      uuid: 'host',
      _element: {
        markup: { tag: 'article', tags: ['span'] },
        fields: { title: { markup: { tags: ['h1'], instances: [{ class: 's-t1', tag: 'h1', children: [] }] } } },
      },
      children: [],
    },
  ]);
  assert.deepEqual(fielded.facts('entity:host').tags, ['span']);
  assert.deepEqual(fielded.facts('entity:host').parts, {});
  assert.equal(fielded.facts('entity:host').tag, 'article');
  // The ancestor walk ends at the entity (farthest first), so the stack
  // layers the page's rules beneath every section's.
  assert.deepEqual(matcher.ancestors('block:t1'), [page, 'container:sec-a']);
  // The page's `p` rule reaches the formatted text block's tag tier and
  // its minted paragraph (the symptom this node exists for), never the
  // block's div root; by the chain through its first section too; a `>`
  // into the tag is never backed by the tree (the paragraph is inside
  // the block, not the section's child).
  assert.equal(matcher.reaches(page, 'p', 'block:f', 'p'), true);
  assert.equal(matcher.reaches(page, 'p', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(page, 'p', 'block:f'), false);
  assert.equal(matcher.reaches(page, 'p', 'block:t1'), true);
  assert.equal(matcher.reaches(page, '> d-container p', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(page, '> d-container > d-container p', 'block:f', '.s-p1'), false);
  assert.equal(matcher.reaches(page, '> d-container > p', 'block:f', '.s-p1'), false);
  assert.equal(matcher.reaches(page, '> d-container > p', 'block:t1'), true);
  assert.equal(matcher.reaches(page, 'p', 'block:t6', '.s-q1'), true);
  // The sections are the root's children, in order; a position
  // qualifier on a top-level section reads its place among them; the
  // root itself stands at no position (it has no siblings to count).
  assert.deepEqual(matcher.matching(page, '> *'), ['container:sec-a', 'container:sec-c', 'container:sec-e', 'container:sec-g']);
  assert.deepEqual(matcher.matching(page, '> d-container'), ['container:sec-a', 'container:sec-c', 'container:sec-e', 'container:sec-g']);
  assert.deepEqual(matcher.matching(page, '> section'), ['container:sec-a', 'container:sec-e', 'container:sec-g']);
  assert.deepEqual(matcher.matching(page, '> * > d-text'), ['block:t1', 'block:t2', 'block:t7', 'block:t9', 'block:t10', 'block:t11']);
  assert.equal(matcher.matches(page, '> *:first-child', 'container:sec-a'), true);
  assert.equal(matcher.matches(page, '> *:first-child', 'container:sec-g'), false);
  assert.equal(matcher.matches(page, '> *:last-child', 'container:sec-g'), true);
  assert.deepEqual(matcher.matching(page, '> *:nth-child(even)'), ['container:sec-c', 'container:sec-g']);
  assert.deepEqual(matcher.matching(page, '&:only-child > *'), ['container:sec-a', 'container:sec-c', 'container:sec-e', 'container:sec-g']);
  // The entity is no section: a section's `d-container` prefix never
  // matches it, and no owner below it has it in scope.
  assert.equal(matcher.matches('container:sec-a', 'd-container', page), false);
  assert.equal(matcher.matching('container:sec-a', 'd-container > *').includes(page), false);
  assert.equal(matcher.matches(page, '> *', page), false);
});

test('a keyless root is structure nothing addresses: no key, no ancestor, the sections still its children', () => {
  const keyless = createMatcher(KEYLESS);
  assert.equal(keyless.has('entity:null'), false);
  assert.equal(keyless.has('entity:'), false);
  assert.equal(keyless.facts('container:sec-x').parent, null);
  assert.deepEqual(keyless.ancestors('block:tx'), ['container:sec-x']);
  assert.deepEqual(keyless.ancestors('container:sec-x'), []);
  // The sections keep their place among the root's children.
  assert.deepEqual(keyless.matching('container:sec-y', '&:last-child'), ['container:sec-y']);
  assert.deepEqual(keyless.matching('container:sec-x', '&:first-child > *'), ['block:tx']);
  assert.equal(keyless.matches('container:sec-x', 'p', 'block:tx'), true);
});

test('a block\'s field is inside its block: a side-table record, never a node', () => {
  const body = 'block:t6:body';
  const c = 'container:sec-c';
  // The open render's body field is a record: has() answers it, facts()
  // the field's own anatomy (the tags and parts of the field's markup
  // alone, the field root's stamp as its address, no tag, no words, no
  // place, its wear from the map, its block as parent).
  assert.equal(matcher.has(body), true);
  assert.deepEqual(matcher.facts(body), {
    key: body,
    kind: 'field',
    address: 'kind:field',
    tag: null,
    tags: ['a', 'p'],
    parts: { '.s-q1': 'p' },
    wear: ['daedalus-stylish-variant--featured'],
    name: null,
    type: null,
    icon: null,
    index: null,
    count: null,
    childCount: 0,
    parent: 'block:t6',
  });
  assert.deepEqual(createMatcher(DOCUMENT).facts(body).wear, []);
  // The block keeps folding its fields' markup into its own bag: the
  // record sits beside the fold, it does not replace it.
  assert.deepEqual(matcher.facts('block:t6').tags, ['a', 'p']);
  assert.deepEqual(matcher.facts('block:t6').parts, { '.s-q1': 'p' });
  // The field's ancestors are the block's ancestors plus the block,
  // farthest first: its rows inherit the block's rules and its ancestors'.
  assert.deepEqual(matcher.ancestors(body), ['entity:page', c, 'block:t6']);
  // reaches() judges the field's tags and parts with the block's node as
  // its place: the block's own `p` rule lands on the field's minted
  // paragraph, a section's `p` on the field's tag tier (and the page's),
  // a chain through the region to the block then by descent, never a
  // chain the block does not satisfy, never from out of scope, never
  // the root or its box (a field is not an element a rule targets).
  assert.equal(matcher.reaches('block:t6', 'p', body, '.s-q1'), true);
  assert.equal(matcher.reaches(c, 'p', body, 'p'), true);
  assert.equal(matcher.reaches(c, 'a', body, 'a'), true);
  assert.equal(matcher.reaches('entity:page', 'p', body, '.s-q1'), true);
  assert.equal(matcher.reaches(c, '> * > d-text p', body, '.s-q1'), true);
  assert.equal(matcher.reaches(c, '> * > d-text .s-q1', body, '.s-q1'), true);
  assert.equal(matcher.reaches(c, '> d-text p', body, 'p'), false);
  assert.equal(matcher.reaches(c, 'em', body, 'em'), false);
  assert.equal(matcher.reaches('container:sec-a', 'p', body, 'p'), false);
  assert.equal(matcher.reaches(c, 'p', body), false);
  assert.equal(matcher.reaches('block:t6', '&::before', body, '&::before'), false);
  // Never in the matching universe: the block's children are unchanged
  // by the record, no owner lists or targets it, and as an owner it
  // targets nothing.
  assert.deepEqual(matcher.matching('block:t6', '> *'), []);
  assert.deepEqual(matcher.matching(c, '> * > *'), ['block:t4', 'block:t5', 'block:t6']);
  assert.deepEqual(matcher.matching(c, 'd-text'), ['block:t4', 'block:t5', 'block:t6']);
  assert.equal(matcher.matches(c, '> *', body), false);
  assert.equal(matcher.matches(c, '> * > *', body), false);
  assert.equal(matcher.matches(c, 'p', body), false);
  assert.deepEqual(matcher.matching(body, '> *'), []);
  assert.equal(matcher.matches(body, 'p', body), false);
  assert.equal(matcher.reaches(body, 'p', body, 'p'), false);
  // A field the block does not annotate, a block annotating no fields,
  // and a malformed key are nothing.
  assert.equal(matcher.has('block:t6:title'), false);
  assert.equal(matcher.has('block:t1:body'), false);
  assert.equal(matcher.has('block:t6:'), false);
  assert.equal(matcher.facts('block:t6:title'), null);
  assert.deepEqual(matcher.ancestors('block:t6:title'), []);
  // A rootless document whose open render annotates three fields: one
  // with prose (a record with its anatomy), one presenting as a unit (a
  // record with an empty bag: it exists), one with no element in any
  // view (`element: false`: no record); beside it a keyless block, still
  // a node (it counts among the siblings) but carrying no records.
  const open = createMatcher([
    {
      kind: 'container',
      uuid: 'sec-o',
      layout_id: 'daedalus_stylish_container',
      _element: { markup: { tag: 'section' } },
      children: [
        {
          kind: 'block',
          uuid: 'o1',
          block_type: 'text',
          _element: {
            markup: { tag: 'div' },
            fields: {
              body: { markup: { tags: ['em'], instances: [{ class: 's-o1', tag: 'p', label: 'Paragraph', children: [] }] } },
              title: { presentation: 'unit' },
              hidden: { element: false },
            },
          },
        },
        { kind: 'block', uuid: null, block_type: 'text', _element: { fields: { body: { markup: { tags: ['p'] } } } } },
      ],
    },
  ]);
  assert.equal(open.has('block:o1:body'), true);
  assert.equal(open.has('block:o1:title'), true);
  assert.equal(open.has('block:o1:hidden'), false);
  assert.equal(open.has('block:o1:ghost'), false);
  assert.equal(open.has('block:null:body'), false);
  assert.equal(open.has('block::body'), false);
  assert.deepEqual(open.facts('block:o1:body').tags, ['em', 'p']);
  assert.deepEqual(open.facts('block:o1:body').parts, { '.s-o1': 'p' });
  assert.deepEqual(open.facts('block:o1:title').tags, []);
  assert.deepEqual(open.facts('block:o1:title').parts, {});
  assert.equal(open.facts('block:o1:title').parent, 'block:o1');
  assert.deepEqual(open.facts('block:o1').tags, ['em', 'p']);
  assert.equal(open.facts('block:o1').count, 2);
  assert.deepEqual(open.ancestors('block:o1:body'), ['container:sec-o', 'block:o1']);
  assert.equal(open.reaches('container:sec-o', 'em', 'block:o1:body', 'em'), true);
  assert.equal(open.reaches('container:sec-o', 'p', 'block:o1:body', '.s-o1'), true);
  assert.equal(open.reaches('container:sec-o', 'p', 'block:o1:title', 'p'), false);
  assert.equal(open.reaches('block:o1', '> d-text p', 'block:o1:body', 'p'), false);
  assert.deepEqual(open.matching('container:sec-o', '> *'), ['block:o1']);
  assert.deepEqual(open.matching('container:sec-o', '> *:last-child'), []);
});

test('the child combinator anchors to the owner; a type or tag filters it', () => {
  assert.deepEqual(matcher.matching('container:sec-a', '> *'), ['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p']);
  assert.deepEqual(matcher.matching('container:sec-a', '> d-text'), ['block:t1', 'block:t2']);
  assert.deepEqual(matcher.matching('container:sec-a', '> p'), ['block:t1']);
  assert.deepEqual(matcher.matching('container:sec-a', '> d-block'), ['block:t1', 'block:t2', 'block:f', 'block:p']);
  assert.deepEqual(matcher.matching('container:sec-a', '> d-container'), ['container:sec-b']);
  assert.deepEqual(matcher.matching('container:sec-a', '> * > *'), ['block:t3', 'block:img', 'container:sec-d']);
  assert.deepEqual(matcher.matching('container:sec-a', '> d-container > d-text'), ['block:t3']);
});

test('descendant compounds reach the whole subtree; the owner is in scope for a non-subject compound', () => {
  assert.deepEqual(matcher.matching('container:sec-a', 'd-text'), ['block:t1', 'block:t2', 'block:t3']);
  assert.deepEqual(matcher.matching('container:sec-a', 'p'), ['block:t1', 'block:t3']);
  assert.deepEqual(matcher.matching('container:sec-a', 'd-block'), ['block:t1', 'block:t2', 'block:f', 'block:t3', 'block:img', 'block:p']);
  // `d-container > *` prints without :scope, so the owner itself is the
  // container the first compound matches: its own children are targets,
  // as the browser sees them.
  assert.deepEqual(matcher.matching('container:sec-a', 'd-container > *'), [
    'block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:t3', 'block:img', 'container:sec-d', 'block:p',
  ]);
  assert.equal(matcher.matches('container:sec-a', 'd-container', 'container:sec-a'), true);
  assert.equal(matcher.matches('container:sec-a', '> d-text', 'block:t3'), false);
  assert.equal(matcher.matches('container:sec-b', '> d-text', 'block:t3'), true);
  // Out of scope: a sibling is never a target.
  assert.equal(matcher.matches('block:t1', '> *', 'block:t2'), false);
  assert.equal(matcher.matches('block:ghost', '> *', 'block:t2'), false);
});

test('region wrappers are the direct children of a multi-region section', () => {
  assert.deepEqual(matcher.matching('container:sec-c', '> *'), []);
  assert.deepEqual(matcher.matching('container:sec-c', '> d-text'), []);
  assert.deepEqual(matcher.matching('container:sec-c', '> * > *'), ['block:t4', 'block:t5', 'block:t6']);
  assert.deepEqual(matcher.matching('container:sec-c', 'd-text'), ['block:t4', 'block:t5', 'block:t6']);
});

test('a part class is not a tree fact: it matches no node, before or as the subject', () => {
  assert.deepEqual(matcher.matching('container:sec-a', '.cls'), []);
  assert.deepEqual(matcher.matching('container:sec-a', '.cls > *'), []);
  assert.deepEqual(matcher.matching('container:sec-e', '> d-text .text-block-link'), []);
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
  assert.equal(matcher.reaches('container:sec-a', 'd-text a', 'block:t1', 'a'), true);
  assert.equal(matcher.reaches('container:sec-a', '> d-text em', 'block:t3', 'em'), false);
  assert.equal(matcher.reaches('container:sec-a', 'd-text em', 'block:t3', 'em'), true);
  assert.equal(matcher.reaches('container:sec-a', 'd-formatted-text p', 'block:f', 'p'), true);
  assert.equal(matcher.reaches('container:sec-b', 'p', 'block:f', 'p'), false);
  assert.equal(matcher.reaches('container:sec-c', 'a', 'block:t6', 'a'), true);
  // A part after a type: the class subject reaches the part of the
  // element the type matches (a direct child by `>`, a nested one by
  // descent); a lone class is the owner's own anatomy.
  assert.equal(matcher.reaches('container:sec-e', '> d-text .text-block-link', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches('container:sec-e', '> d-text .text-block-link', 'block:t8', '.text-block-link'), false);
  assert.equal(matcher.reaches('container:sec-e', 'd-text > .text-block-link', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches('container:sec-e', '.text-block-link', 'block:t7', '.text-block-link'), false);
  // A chain or the children is a rule of its own, never a tier.
  assert.equal(matcher.reaches('container:sec-a', '> *', 'block:t1', '> *'), false);
});

test('reaches: a part by the tag it renders, by descent only, never a part declaring no tag', () => {
  const e = 'container:sec-e';
  // The container's `a` rule styles the link part's anchor: by descent
  // from the owner or from a prefix matching the element or an ancestor.
  assert.equal(matcher.reaches(e, 'a', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, '> d-text a', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, 'a', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, 'd-container a', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches(e, '> d-container a', 'block:t8', '.text-block-link'), true);
  // A child combinator into the tag cannot be backed by the tree.
  assert.equal(matcher.reaches(e, '> a', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, '> d-text > a', 'block:t7', '.text-block-link'), false);
  // The prefix must match the element or an ancestor; the rule must
  // scope the element.
  assert.equal(matcher.reaches(e, '> d-text a', 'block:t8', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, '> d-container a', 'block:t7', '.text-block-link'), false);
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
  assert.equal(matcher.reaches(e, 'd-text a', 'block:t8', 'a'), true);
  assert.equal(matcher.reaches(e, '> d-text a', 'block:t8', 'a'), false);
  assert.equal(matcher.reaches(e, 'em', 'block:t7', 'em'), false);
});

test('reaches: an identity class is judged by presence on the node, a declared class keeps the anatomy posture', () => {
  const a = 'container:sec-a';
  // The tag a minted instance is: the section's `p` rule styles the
  // formatted text block's minted paragraph exactly as it styles a link
  // part's anchor, by descent; the nested list item by its own tag, not
  // its parent's.
  assert.equal(matcher.reaches(a, 'p', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(a, 'd-formatted-text p', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(a, 'h2', 'block:f', '.s-p1'), false);
  assert.equal(matcher.reaches(a, 'li', 'block:f', '.s-li'), true);
  assert.equal(matcher.reaches(a, 'ul', 'block:f', '.s-li'), false);
  assert.equal(matcher.reaches('container:sec-c', 'p', 'block:t6', '.s-q1'), true);
  // The class subject: an identity class reaches the one node whose
  // parts carry it, never another node the type matches (the class is
  // worn by one element in the world) and never a class the node lacks.
  assert.equal(matcher.reaches(a, '> d-formatted-text .s-p1', 'block:f', '.s-p1'), true);
  assert.equal(matcher.reaches(a, '> d-formatted-text .s-p1', 'block:t1', '.s-p1'), false);
  assert.equal(matcher.reaches(a, '> d-block .s-p1', 'block:t1', '.s-p1'), false);
  assert.equal(matcher.reaches(a, '> d-formatted-text .s-p9', 'block:f', '.s-p9'), false);
  assert.equal(matcher.reaches(a, '> d-formatted-text .s-li', 'block:f', '.s-li'), true);
  // A declared class is the type's anatomy: the chain reaches a text
  // block that renders no link, a legal address either way.
  assert.equal(matcher.reaches(a, '> d-text .text-block-link', 'block:t2', '.text-block-link'), true);
  // The tree never matches a class in a chain; `:not()` judges it on the
  // node's own parts, an identity class included.
  assert.deepEqual(matcher.matching(a, '> d-formatted-text .s-p1'), []);
  assert.deepEqual(matcher.matching(a, '> d-block:not(.s-p1)'), ['block:t1', 'block:t2', 'block:p']);
  assert.deepEqual(matcher.matching(a, '> d-block:not(.s-p9)'), ['block:t1', 'block:t2', 'block:f', 'block:p']);
});

test('a sibling combinator steps along the parent\'s ordered children: `+` the previous sibling, `~` any earlier one', () => {
  const g = 'container:sec-g';
  assert.deepEqual(matcher.matching(g, '> p + p'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> p ~ p'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> p + *'), ['block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> p ~ *'), ['block:t10', 'block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, 'd-text ~ d-image'), ['block:img2']);
  assert.equal(matcher.matches(g, '> p + p', 'block:t10'), true);
  assert.equal(matcher.matches(g, '> p + p', 'block:t9'), false);
});

test(':has() anchors its argument at the qualified node, every lead relating to it; a tag by descent reads the bag', () => {
  const g = 'container:sec-g';
  // The linked block's part renders an anchor (the bag); the bag has no
  // depth, so `> a` on a block is never backed.
  assert.deepEqual(matcher.matching(g, '> d-text:has(a)'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> d-text:has(> a)'), []);
  // The heading is the text block right before the image; every text
  // block has an image somewhere after it.
  assert.deepEqual(matcher.matching(g, '> d-text:has(+ d-image)'), ['block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text:has(~ d-image)'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-container:has(> d-image)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:has(d-image)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> d-container:has(> d-image) > *'), ['block:img3']);
  assert.deepEqual(matcher.matching('container:sec-a', '> d-container:has(> d-image)'), ['container:sec-b']);
  // A region wrapper anchors a :has() like any node, though the regions
  // themselves are never listed (unaddressable).
  assert.deepEqual(matcher.matching('container:sec-c', '> *:has(> d-text)'), []);
  assert.deepEqual(matcher.matching('container:sec-c', '> *:has(> d-text) > *'), ['block:t4', 'block:t5', 'block:t6']);
});

test(':not() excludes what its compound matches on the node; a part class there is judged on the node\'s own anatomy', () => {
  const g = 'container:sec-g';
  assert.deepEqual(matcher.matching(g, '> *:not(d-image)'), ['block:t9', 'block:t10', 'block:t11', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:not(d-image):not(d-text)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> d-text:not(:has(a))'), ['block:t9', 'block:t11']);
  // A node whose parts carry the class (hoisted onto its root like the
  // heading's .featured, or nested inside it like the link part) is
  // excluded: the flattened parts do not tell the two apart, and
  // excluding both never over-claims.
  assert.deepEqual(matcher.matching(g, '> d-text:not(.featured)'), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(g, '> d-text:not(.text-block-link)'), ['block:t9', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text:has(a):not(.featured)'), ['block:t10']);
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
  assert.equal(matcher.reaches(g, '> d-text:has(a) .text-block-link', 'block:t10', '.text-block-link'), true);
  assert.equal(matcher.reaches(g, '> d-text:not(:has(a)) .text-block-link', 'block:t10', '.text-block-link'), false);
  assert.equal(matcher.reaches(g, '> d-text:has(a) a', 'block:t10', 'a'), true);
  assert.equal(matcher.reaches(g, '> d-text:has(a) a', 'block:t9', 'a'), false);
});

test('a terminal pseudo-element: matching answers the hosts, reaches requires the pseudos to agree by name', () => {
  const a = 'container:sec-a';
  const e = 'container:sec-e';
  const g = 'container:sec-g';
  // The tree never sees a generated box: a pseudo rule matches its
  // hosts, and the owner's own pseudo (the anchor, no compounds) the
  // owner alone.
  assert.deepEqual(matcher.matching(a, '> d-text::before'), ['block:t1', 'block:t2']);
  assert.deepEqual(matcher.matching('block:t1', '&::before'), ['block:t1']);
  assert.deepEqual(matcher.matching('block:ghost', '&::before'), []);
  assert.equal(matcher.matches('block:t1', '&::after', 'block:t1'), true);
  assert.equal(matcher.matches('block:t1', '&::after', 'block:t2'), false);
  assert.equal(matcher.matches(a, '&::after', 'block:t1'), false);
  assert.equal(matcher.matches(a, '> d-text::before', 'block:t1'), true);
  // The own pseudo: the element's own rule reaches its own box and only
  // that box; a rule for one box never lands on the root or the other
  // box, and a pseudo-free rule never lands on a box.
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t1', '&::before'), true);
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t1', '&::after'), false);
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t1'), false);
  assert.equal(matcher.reaches('block:t1', '&::before', 'block:t2', '&::before'), false);
  // An ancestor's `> d-text::before` reaches a text block child's
  // `&::before`, not its `&::after`, not its root, not a nested text
  // block; by descent it reaches the nested one too.
  assert.equal(matcher.reaches(a, '> d-text::before', 'block:t1', '&::before'), true);
  assert.equal(matcher.reaches(a, '> d-text::before', 'block:t1', '&::after'), false);
  assert.equal(matcher.reaches(a, '> d-text::before', 'block:t1'), false);
  assert.equal(matcher.reaches(a, '> d-text', 'block:t1', '&::before'), false);
  assert.equal(matcher.reaches(a, '> d-text::before', 'block:t3', '&::before'), false);
  assert.equal(matcher.reaches(a, 'd-text::after', 'block:t3', '&::after'), true);
  assert.equal(matcher.reaches(a, '> d-text::before', 'block:f', '&::before'), false);
  assert.equal(matcher.reaches('container:sec-b', '> d-text::before', 'block:t1', '&::before'), false);
  // A part's pseudo: the class subject's box, or the box of the tag the
  // part renders, reached exactly as the part is; the part alone is not
  // its box.
  assert.equal(matcher.reaches(e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link::before'), true);
  assert.equal(matcher.reaches(e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link::after'), false);
  assert.equal(matcher.reaches(e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches(e, '> d-text .text-block-link', 'block:t7', '.text-block-link::before'), false);
  assert.equal(matcher.reaches(e, '> d-text .text-block-link::before', 'block:t8', '.text-block-link::before'), false);
  assert.equal(matcher.reaches(e, 'd-text > .text-block-link::after', 'block:t8', '.text-block-link::after'), true);
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
  assert.equal(matcher.reaches(g, '> d-text:has(a)::before', 'block:t10', '&::before'), true);
  assert.equal(matcher.reaches(g, '> d-text:has(a)::before', 'block:t9', '&::before'), false);
  assert.equal(matcher.reaches(g, '> p + p a::before', 'block:t10', '.text-block-link::before'), true);
  assert.equal(matcher.reaches(g, '> p + p::before', 'block:t10', 'p::before'), false);
  // A position that is a rule of its own, pseudo or not, is never a
  // tier; a selector key with a pseudo reads its owner's place.
  assert.equal(matcher.reaches(a, '> *::before', 'block:t1', '> *::before'), false);
  assert.equal(matcher.reaches(a, '> d-text::before', 'block:t1', '> d-text::before'), false);
  // The qualified anchor's box: the owner's own box when the owner
  // stands at the position.
  assert.deepEqual(matcher.matching('block:t1', '&:first-child::before'), ['block:t1']);
  assert.deepEqual(matcher.matching('block:t2', '&:first-child::before'), []);
  assert.equal(matcher.reaches('block:t1', '&:first-child::before', 'block:t1', '&::before'), true);
  assert.equal(matcher.reaches('block:t1', '&:first-child::before', 'block:t1', '&::after'), false);
  assert.equal(matcher.reaches('block:t2', '&:first-child::before', 'block:t2', '&::before'), false);
  assert.equal(matcher.reaches('block:t2', '&:nth-child(even)::before', 'block:t2', '&::before'), true);
  // The parsed record carries the pseudo; a bare compounds array has
  // dropped it and is refused, as is a pseudo outside the grammar.
  assert.equal(matcher.reaches(a, parseSelector('> d-text::before'), 'block:t1', parseSelector('&::before')), true);
  assert.throws(() => matcher.reaches(a, parseSelector('> d-text::before').compounds, 'block:t1', '&::before'), TypeError);
  assert.equal(matcher.reaches(a, 'a::marker', 'block:t1', 'a::marker'), true, 'any pseudo-element the browser spells reaches by name');
  assert.equal(matcher.reaches(a, 'a::marker', 'block:t1', 'a::before'), false);
  assert.throws(() => matcher.reaches(a, 'a', 'block:t1', 'a::part(x)'), TypeError);
});

test('a position qualifier on a compound read by node is judged by the sibling index; a block is never empty', () => {
  const a = 'container:sec-a';
  const b = 'container:sec-b';
  // The hero section's five children: the parameterless positions.
  assert.deepEqual(matcher.matching(a, '> *:first-child'), ['block:t1']);
  assert.deepEqual(matcher.matching(a, '> *:last-child'), ['block:p']);
  assert.deepEqual(matcher.matching(a, '> *:not(:last-child)'), ['block:t1', 'block:t2', 'block:f', 'container:sec-b']);
  assert.deepEqual(matcher.matching(a, '> *:not(:first-child)'), ['block:t2', 'block:f', 'container:sec-b', 'block:p']);
  assert.deepEqual(matcher.matching('container:sec-c', '> * > *:only-child'), ['block:t4']);
  assert.deepEqual(matcher.matching('container:sec-c', '> * > *:not(:only-child)'), ['block:t5', 'block:t6']);
  // An+B over the 1-based position: odd and even as CSS reads them,
  // a step with an offset, a negative step, a bare integer, the
  // -last- forms from the end, an argument nothing satisfies.
  assert.deepEqual(matcher.matching(a, '> *:nth-child(odd)'), ['block:t1', 'block:f', 'block:p']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(even)'), ['block:t2', 'container:sec-b']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(2n+1)'), ['block:t1', 'block:f', 'block:p']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(3n+1)'), ['block:t1', 'container:sec-b']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(-n+2)'), ['block:t1', 'block:t2']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(n+4)'), ['container:sec-b', 'block:p']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(3)'), ['block:f']);
  assert.deepEqual(matcher.matching(a, '> *:nth-child(0)'), []);
  assert.deepEqual(matcher.matching(a, '> *:nth-last-child(1)'), ['block:p']);
  assert.deepEqual(matcher.matching(a, '> *:nth-last-child(2)'), ['container:sec-b']);
  assert.deepEqual(matcher.matching(a, '> *:nth-last-child(-n+2)'), ['container:sec-b', 'block:p']);
  // A type compound is judged at the node's place among ALL its
  // siblings, as the browser judges it.
  assert.deepEqual(matcher.matching(a, '> d-text:last-child'), []);
  assert.deepEqual(matcher.matching(a, '> d-text:nth-child(2)'), ['block:t2']);
  assert.deepEqual(matcher.matching(a, '> d-block:last-child'), ['block:p']);
  assert.deepEqual(matcher.matching(a, 'd-text:first-child'), ['block:t1', 'block:t3']);
  // A block's root holds its content, which the tree carries no nodes
  // for: a block is never empty; a container without children is.
  assert.deepEqual(matcher.matching(a, 'd-container:empty'), ['container:sec-d']);
  assert.deepEqual(matcher.matching(a, '> *:empty'), []);
  assert.deepEqual(matcher.matching(b, '> *:empty'), ['container:sec-d']);
  assert.deepEqual(matcher.matching(b, '> *:not(:empty)'), ['block:t3', 'block:img']);
  assert.deepEqual(matcher.matching(a, '> *:not(:empty)'), ['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p']);
  // Inside :has() the argument's compounds are judged the same way.
  assert.deepEqual(matcher.matching('container:sec-g', '> d-container:has(> *:only-child)'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(a, '> *:has(> *:nth-child(3))'), ['container:sec-b']);
  assert.deepEqual(matcher.matching(a, '> *:has(> *:nth-child(4))'), []);
});

test('the -of-type positions read the place among the siblings sharing the root tag; an unknown tag is of no type', () => {
  const a = 'container:sec-a';
  const g = 'container:sec-g';
  // The section: two paragraphs, a heading, a figure, a div.
  assert.deepEqual(matcher.matching(g, '> *:first-of-type'), ['block:t9', 'block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:last-of-type'), ['block:t10', 'block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:only-of-type'), ['block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:nth-of-type(2)'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> *:nth-last-of-type(2)'), ['block:t9']);
  assert.deepEqual(matcher.matching(g, '> *:nth-of-type(odd)'), ['block:t9', 'block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> d-text:first-of-type'), ['block:t9', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text:not(:first-of-type)'), ['block:t10']);
  // The hero section: the unannotated plugin block has no tag, so it
  // is never first of its type (and :not() of that holds).
  assert.deepEqual(matcher.matching(a, '> *:first-of-type'), ['block:t1', 'block:t2', 'block:f']);
  assert.deepEqual(matcher.matching(a, '> *:last-of-type'), ['block:t1', 'block:t2', 'container:sec-b']);
  assert.deepEqual(matcher.matching(a, '> *:not(:first-of-type)'), ['container:sec-b', 'block:p']);
});

test('a position qualifier on a tag compound is reach-neutral: the bag has no tag nodes', () => {
  const a = 'container:sec-a';
  const g = 'container:sec-g';
  // The tag compound matches its roots as if unqualified, whatever the
  // position says, inside :not() too; and a qualified tag subject is
  // still never answered inside a block.
  assert.deepEqual(matcher.matching(a, '> p:last-child'), ['block:t1']);
  assert.deepEqual(matcher.matching(g, '> p:first-child'), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(g, '> p:nth-child(2)'), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(g, '> p:not(:first-child)'), []);
  assert.deepEqual(matcher.matching(g, '> *:not(p:first-child)'), ['block:t11', 'block:img2', 'container:sec-h']);
  assert.equal(matcher.reaches(a, 'p:first-child', 'block:f', 'p'), false);
  assert.equal(matcher.reaches(a, 'p:first-child', 'block:t1'), true);
});

test('the qualified anchor matches the owner alone, its positions judged against the owner node', () => {
  const a = 'container:sec-a';
  const b = 'container:sec-b';
  const g = 'container:sec-g';
  assert.deepEqual(matcher.matching('block:t1', '&:first-child'), ['block:t1']);
  assert.deepEqual(matcher.matching('block:t2', '&:first-child'), []);
  assert.deepEqual(matcher.matching('block:t2', '&:nth-child(even)'), ['block:t2']);
  assert.deepEqual(matcher.matching('block:p', '&:last-child'), ['block:p']);
  assert.deepEqual(matcher.matching('block:t4', '&:only-child'), ['block:t4']);
  assert.deepEqual(matcher.matching('block:t5', '&:not(:only-child)'), ['block:t5']);
  assert.deepEqual(matcher.matching('container:sec-d', '&:empty'), ['container:sec-d']);
  assert.deepEqual(matcher.matching('container:sec-d', '&:last-child'), ['container:sec-d']);
  assert.deepEqual(matcher.matching(b, '&:empty'), []);
  assert.deepEqual(matcher.matching('block:t1', '&:empty'), []);
  assert.deepEqual(matcher.matching('block:t1', '&:not(:empty)'), ['block:t1']);
  assert.deepEqual(matcher.matching('block:t1', '&:nth-child(3n+1)'), ['block:t1']);
  assert.deepEqual(matcher.matching('block:t1', '&:not(:nth-child(3n+1))'), []);
  assert.deepEqual(matcher.matching('block:t8', '&:first-of-type'), ['block:t8']);
  assert.deepEqual(matcher.matching('block:ghost', '&:first-child'), []);
  assert.equal(matcher.matches('block:t1', '&:first-child', 'block:t1'), true);
  assert.equal(matcher.matches('block:t2', '&:first-child', 'block:t2'), false);
  // The anchor is the owner, never a descendant of it.
  assert.equal(matcher.matches(a, '&:first-child', 'block:t1'), false);
  // A chain behind the anchor: the owner's children when it is not the
  // last root section (the hero is the first of four, the last section
  // the last), its descendants, a nested section's children by its
  // own place among five.
  assert.deepEqual(matcher.matching(a, '&:not(:last-child) > *'), ['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p']);
  assert.deepEqual(matcher.matching(g, '&:not(:last-child) > *'), []);
  assert.deepEqual(matcher.matching(a, '&:first-child d-text'), ['block:t1', 'block:t2', 'block:t3']);
  assert.deepEqual(matcher.matching(b, '&:first-child > *'), []);
  assert.deepEqual(matcher.matching(b, '&:nth-child(4) > *'), ['block:t3', 'block:img', 'container:sec-d']);
  assert.deepEqual(matcher.matching(b, '&:nth-child(4) > *:not(:last-child)'), ['block:t3', 'block:img']);
  assert.equal(matcher.matches(a, '&:not(:last-child) > *', 'block:t1'), true);
  assert.equal(matcher.matches(g, '&:not(:last-child) > *', 'block:t9'), false);
  // reaches: the tag tier and a part behind the qualified anchor.
  assert.equal(matcher.reaches(a, '&:first-child p', 'block:f', 'p'), true);
  assert.equal(matcher.reaches(a, '&:first-child p', 'block:t1'), true);
  assert.equal(matcher.reaches(g, '&:first-child p', 'block:t9'), false);
  assert.equal(matcher.reaches(g, '&:not(:first-child) p', 'block:t9'), true);
  assert.equal(matcher.reaches('block:t7', '&:first-child .text-block-link', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches('block:t7', '&:last-child .text-block-link', 'block:t7', '.text-block-link'), false);
  assert.equal(matcher.reaches('block:t8', '&:only-child .text-block-link', 'block:t8', '.text-block-link'), true);
  assert.equal(matcher.reaches('container:sec-e', '&:nth-child(3) a', 'block:t7', '.text-block-link'), true);
  assert.equal(matcher.reaches('container:sec-e', '&:nth-child(2) a', 'block:t7', '.text-block-link'), false);
});

test('a class qualifier naming a variant class is judged by wear on a type compound; markup classes, attributes and states are reach-neutral', () => {
  const a = 'container:sec-a';
  const g = 'container:sec-g';
  const featured = 'daedalus-stylish-variant--featured';
  // The text blocks wearing Featured: the Section's heading wears it,
  // its two paragraphs do not; the family type reads the same wear; a
  // distributed fragment's class is the slot-encoded one; a variant
  // nobody wears reaches nothing; by descent from the hero, its first
  // text block.
  assert.deepEqual(matcher.matching(g, `> d-text.${featured}`), ['block:t11']);
  assert.deepEqual(matcher.matching(g, `> d-block.${featured}`), ['block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text.daedalus-stylish-variant--card--region-first--block-text-1'), ['block:t10']);
  assert.deepEqual(matcher.matching(g, '> d-text.daedalus-stylish-variant--ghost'), []);
  assert.deepEqual(matcher.matching(a, `d-text.${featured}`), ['block:t1']);
  // `:not()` of a variant class excludes the wearers (the class is a
  // node fact, like a part on the node's own anatomy); `:has()` reads
  // wear the same way.
  assert.deepEqual(matcher.matching(g, `> d-text:not(.${featured})`), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(a, `d-container:has(> d-text.${featured})`), ['container:sec-a']);
  // A markup class is no tree fact: reach-neutral on a type compound;
  // a variant class on a TAG compound is reach-neutral too (the bag has
  // no tag nodes); an attribute and a state are reach-neutral everywhere.
  assert.deepEqual(matcher.matching(g, '> d-text.active'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, `> p.${featured}`), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(g, '> d-text[aria-current="page"]'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text:hover'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text:visited'), ['block:t9', 'block:t10', 'block:t11']);
  // A neutral qualifier HOLDS, so its negation never does (the reading
  // T2 recorded for `> p:not(:first-child)`: `:not()` never over-claims).
  assert.deepEqual(matcher.matching(g, '> *:not(:hover)'), []);
  assert.deepEqual(matcher.matching(g, '> d-text:hover a'), []);
  // The anchor: the owner wears the class or does not; its state is
  // reach-neutral like any state.
  assert.deepEqual(matcher.matching('container:sec-h', `&.${featured} > *`), ['block:img3']);
  assert.deepEqual(matcher.matching(g, `&.${featured} > *`), []);
  assert.deepEqual(matcher.matching(g, '&:hover > d-image'), ['block:img2']);
  assert.deepEqual(matcher.matching(g, '&.open > d-image'), ['block:img2']);
  // A lone variant class is the grammar's PART record (a class pooled
  // into the subject) and keeps the anatomy reading: it matches no node
  // in a chain. Targeting by worn variant is spelled on a type compound.
  assert.deepEqual(matcher.matching(g, `> .${featured}`), []);
  // reaches: a wear-qualified prefix reaches the wearer's root and its
  // tag tier, never a non-wearer's; a state-qualified prefix reaches as
  // if unqualified.
  assert.equal(matcher.reaches(g, `> d-text.${featured}`, 'block:t11'), true);
  assert.equal(matcher.reaches(g, `> d-text.${featured}`, 'block:t10'), false);
  assert.equal(matcher.reaches(g, `> d-text.${featured} a`, 'block:t10', 'a'), false);
  assert.equal(matcher.reaches(g, '> d-text:hover a', 'block:t10', 'a'), true);
  assert.equal(matcher.reaches(g, '> d-text:hover', 'block:t11'), true);
  // Without a map nothing is worn.
  assert.deepEqual(createMatcher(DOCUMENT).matching(g, `> d-text.${featured}`), []);
});

test(':is() holds when any member matches the node, each member judged as :not()\'s compound is', () => {
  const a = 'container:sec-a';
  const g = 'container:sec-g';
  const page = 'entity:page';
  const featured = 'daedalus-stylish-variant--featured';
  const card = 'daedalus-stylish-variant--card--region-first--block-text-1';
  // Types: the fold of `d-text, d-image` reaches both kinds; tags by
  // root tag; a type member's position is judged by node where a tag
  // member's is reach-neutral (the `:not()` rule, member by member).
  assert.deepEqual(matcher.matching(g, '> *:is(d-text, d-image)'), ['block:t9', 'block:t10', 'block:t11', 'block:img2']);
  assert.deepEqual(matcher.matching(g, '> *:is(d-image, d-text)'), ['block:t9', 'block:t10', 'block:t11', 'block:img2']);
  assert.deepEqual(matcher.matching(g, '> *:is(p, h3)'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> *:is(d-text:first-child, d-image)'), ['block:t9', 'block:img2']);
  assert.deepEqual(matcher.matching(g, '> *:is(p:first-child, d-image)'), ['block:t9', 'block:t10', 'block:img2']);
  assert.deepEqual(matcher.matching(a, '*:is(d-text, d-image)'), ['block:t1', 'block:t2', 'block:t3', 'block:img']);
  // States are reach-neutral inside `:is()` as outside it: Lily's
  // compound reaches as `> d-text` does, and a member of states alone
  // holds as if absent (so, like `:not(:hover)`, nothing is excluded).
  assert.deepEqual(matcher.matching(g, '> d-text:is(:hover, :focus-within)'), ['block:t9', 'block:t10', 'block:t11']);
  assert.deepEqual(matcher.matching(g, '> d-text:is(:hover, :focus-within) a'), []);
  assert.equal(matcher.reaches(g, '> d-text:is(:hover, :focus-within) a', 'block:t10', 'a'), true);
  assert.equal(matcher.reaches(g, '> d-text:is(:hover, :focus-within) a', 'block:t9', 'a'), false);
  assert.equal(matcher.reaches(g, '> d-text:is(:hover, :focus-within)', 'block:t11'), true);
  assert.equal(matcher.reaches(g, '> *:is(d-text, d-image) a', 'block:t10', 'a'), true);
  assert.equal(matcher.reaches(g, '> *:is(d-text, d-image)', 'block:img2'), true);
  assert.equal(matcher.reaches(g, '> *:is(d-text, d-image)', 'container:sec-h'), false);
  // Classes: a class member is judged as a `:not()` argument is, by the
  // node's anatomy (a hoisted or declared part) or its wear (a variant
  // class); a class QUALIFIER on a type member by wear.
  assert.deepEqual(matcher.matching(g, `> *:is(.${featured}, .${card})`), ['block:t10', 'block:t11', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:is(.featured, d-image)'), ['block:t11', 'block:img2']);
  assert.deepEqual(matcher.matching(g, '> *:is(.text-block-link, d-image)'), ['block:t10', 'block:img2']);
  assert.deepEqual(matcher.matching(g, `> *:is(d-text.${featured}, d-image)`), ['block:t11', 'block:img2']);
  assert.deepEqual(matcher.matching(g, '> *:is(d-text.active, d-image)'), ['block:t9', 'block:t10', 'block:t11', 'block:img2']);
  // The host's by-node reading governs the members: on a tag host a
  // member's position is reach-neutral (`p:is(:first-child, :last-child)`
  // is as neutral as `p:last-child`); on `*` the positions are judged.
  assert.deepEqual(matcher.matching(g, '> p:is(:first-child, :last-child)'), ['block:t9', 'block:t10']);
  assert.deepEqual(matcher.matching(g, '> *:is(:first-child, :last-child)'), ['block:t9', 'container:sec-h']);
  assert.deepEqual(matcher.matching(g, '> *:is(:has(> d-image), :not(d-text))'), ['block:img2', 'container:sec-h']);
  // Inside `:has()`: the argument's compounds carry `:is()` like any
  // qualifier; a member compound never reads the bag (one unqualified
  // tag by descent is all the bag answers).
  assert.deepEqual(matcher.matching(g, '> d-container:has(> *:is(d-image, d-text))'), ['container:sec-h']);
  assert.deepEqual(matcher.matching(a, '> d-container:has(> *:is(d-image, d-text))'), ['container:sec-b']);
  assert.deepEqual(matcher.matching(page, '> *:has(> *:is(d-image, d-container))'), ['container:sec-a', 'container:sec-e', 'container:sec-g']);
  assert.deepEqual(matcher.matching(g, '> d-text:has(:is(a, em))'), []);
  // On the anchor: the owner judged by node against its own place.
  assert.deepEqual(matcher.matching(g, '&:is(:first-child, :last-child) > *'), ['block:t9', 'block:t10', 'block:t11', 'block:img2', 'container:sec-h']);
  assert.deepEqual(matcher.matching('container:sec-c', '&:is(:first-child, :last-child) > *'), []);
  assert.deepEqual(matcher.matching('container:sec-h', `&:is(.${featured}, .${card}) > *`), ['block:img3']);
  assert.equal(matcher.matches(g, '> *:is(d-text, d-image)', 'block:img2'), true);
  assert.equal(matcher.matches(g, '> *:is(d-text, d-image)', 'container:sec-h'), false);
});

test('structuralHolds and nthHolds over a place', () => {
  const place = { index: 3, count: 5, empty: false, typeIndex: 1, typeCount: 2 };
  assert.equal(structuralHolds({ name: 'last-child', argument: null }, { index: 2, count: 3, empty: false, typeIndex: null, typeCount: null }), true);
  assert.equal(structuralHolds({ name: 'nth-child', argument: '3n+1' }, place), true);
  assert.equal(structuralHolds({ name: 'nth-child', argument: '-n+3' }, place), false);
  assert.equal(structuralHolds({ name: 'nth-last-child', argument: '2' }, place), true);
  assert.equal(structuralHolds({ name: 'last-of-type', argument: null }, place), true);
  assert.equal(structuralHolds({ name: 'first-of-type', argument: null }, place), false);
  assert.equal(structuralHolds({ name: 'nth-of-type', argument: 'even' }, place), true);
  assert.equal(structuralHolds({ name: 'nth-last-of-type', argument: '1' }, place), true);
  assert.equal(structuralHolds({ name: 'only-of-type', argument: null }, place), false);
  // An unknown tag is of no type.
  const untyped = { index: 0, count: 1, empty: false, typeIndex: null, typeCount: null };
  assert.equal(structuralHolds({ name: 'first-of-type', argument: null }, untyped), false);
  assert.equal(structuralHolds({ name: 'last-of-type', argument: null }, untyped), false);
  assert.equal(structuralHolds({ name: 'only-of-type', argument: null }, untyped), false);
  assert.equal(structuralHolds({ name: 'nth-of-type', argument: '1' }, untyped), false);
  assert.equal(structuralHolds({ name: 'only-child', argument: null }, untyped), true);
  // An+B over the 1-based position, in the grammar's canonical spellings.
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('odd', p)), [true, false, true, false, true]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('even', p)), [false, true, false, true, false]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('n', p)), [true, true, true, true, true]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('-n+3', p)), [true, true, true, false, false]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('2n', p)), [false, true, false, true, false]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('n-1', p)), [true, true, true, true, true]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('-2n+5', p)), [true, false, true, false, true]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('3', p)), [false, false, true, false, false]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('0', p)), [false, false, false, false, false]);
  assert.deepEqual([1, 2, 3, 4, 5].map((p) => nthHolds('-n', p)), [false, false, false, false, false]);
  // A name or argument outside the grammar is a caller bug.
  assert.throws(() => structuralHolds({ name: 'hover', argument: null }, place), TypeError);
  assert.throws(() => nthHolds('3n + 1', 4), TypeError);
  assert.throws(() => nthHolds('foo', 1), TypeError);
});

test('a selector outside the grammar is a caller bug', () => {
  assert.throws(() => matcher.matching('container:sec-a', 'p:has(:has(a))'), TypeError);
  assert.throws(() => matcher.matches('container:sec-a', '', 'block:t1'), TypeError);
  assert.throws(() => matcher.matching('block:t1', '&'), TypeError);
  assert.throws(() => matcher.matching('container:sec-a', '> *:nth-child(foo)'), TypeError);
});

test('typeMatches: the two families by prefix, a block type exactly', () => {
  assert.equal(typeMatches('d-container', 'layout:x'), true);
  assert.equal(typeMatches('d-block', 'block_plugin:x'), true);
  assert.equal(typeMatches('d-block', 'layout:x'), false);
  assert.equal(typeMatches('d-formatted-text', 'block_type:formatted_text'), true);
  assert.equal(typeMatches('d-text', 'block_type:formatted_text'), false);
});

test('typeOfAddress: bundles by name, the kinds for layouts and plugins', () => {
  assert.equal(typeOfAddress('block_type:text'), 'd-text');
  assert.equal(typeOfAddress('block_type:formatted_text'), 'd-formatted-text');
  assert.equal(typeOfAddress('layout:daedalus_stylish_container'), 'd-container');
  assert.equal(typeOfAddress('block_plugin:system_menu_block'), 'd-block');
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
    types: ['d-text', 'd-container', 'd-block'],
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
  assert.deepEqual(inner, { types: ['d-text', 'd-container'], tags: ['h6', 'a', 'svg', 'div'] });
  assert.deepEqual(
    presentRoster(matcher.matching('container:sec-e', '> *').map((key) => matcher.facts(key))).tags,
    ['h6', 'div'],
  );
});
