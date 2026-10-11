/**
 * Self-asserting tests for the type-YAML → instance part join.
 *
 * Run directly (`node tests/js/parts.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite
 * so the join contract has backpressure. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { partsForKey, selectorLabel, findInstancePart, PART_SELECTOR, INSTANCE_PART } from '../../js/src/parts.js';
import { parseKey, declarationForKey, findElement, findPatternElement, flattenElements, componentBlockTypes, PART_PATTERN } from '../../js/src/style-guide.js';
import { addressLabel } from '../../js/src/write-address.js';

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';
const UUID_C = 'cccccccc-1111-2222-3333-444444444444';
const UUID_P = 'dddddddd-1111-2222-3333-444444444444';
const UUID_F = 'ffffffff-1111-2222-3333-444444444444';

const guide = {
  kinds: {
    block: {
      grants: { spacing: ['padding'] },
      elements: {
        '.content': { label: 'Content', grants: { spacing: ['padding'] } },
      },
    },
    container: {
      grants: { spacing: ['padding'] },
      elements: {
        '.section__inner': { grants: { spacing: ['padding'] } },
        // Not a single-class selector: unreachable as a style-map key, so
        // never a row.
        'div > .loose': { label: 'Loose' },
      },
    },
    field: { grants: { typography: ['font-size'] } },
  },
  blockTypes: {
    testimonial: {
      grants: { typography: ['font-size'] },
      elements: {
        '.testimonial__quote': { label: 'Quote text', grants: { typography: ['font-size'] } },
        '.testimonial__attribution': { label: 'Attribution', grants: { typography: ['font-size'] } },
        // A pattern part declaration: the open s-* instance namespace.
        '.s-*': { label: 'Text element', grants: { typography: ['font-size'] } },
      },
    },
    // A nested declaration tree with contains: slots, the client mirror of
    // the daedalus_stylish_nested_test card fixture (NestedStyleGuideTest).
    card: {
      grants: { typography: ['font-size'] },
      elements: {
        '.block__content': {
          label: 'Content',
          contains: 'fields',
          elements: {
            '.card__header': {
              label: 'Header',
              contains: 'field:field_title',
              grants: { typography: ['font-size'] },
              elements: {
                '.card__kicker': { label: 'Kicker' },
              },
            },
            '.card__body': { label: 'Body', contains: ['field:field_summary'] },
          },
        },
      },
    },
  },
  blockPlugins: {
    system_menu_block: {
      grants: { typography: ['font-size'] },
      elements: {
        '.menu-item': { label: 'Menu item', grants: { typography: ['font-size'] } },
      },
    },
  },
  layouts: {
    layout_twocol_section: {
      grants: { spacing: ['padding'] },
      elements: {
        '.daedalus-stylish-layout-container': { label: 'Layout container', grants: { sizing: ['width'] } },
      },
    },
  },
};

const blockTypes = { [UUID_A]: 'testimonial', [UUID_B]: null, [UUID_C]: 'card', [UUID_P]: 'plugin:system_menu_block' };

// The relation phrases are sentences (Drupal.t at call time); the stub
// substitutes placeholders so the words under test read as they render.
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
};

// A guide with labelled types and base tag words, for the relation words.
const related = {
  ...guide,
  blockTypes: {
    ...guide.blockTypes,
    text: { label: 'Text', elements: { '.text-block-link': { label: 'Link' } } },
    image: { label: 'Image' },
  },
  tagLabels: { a: 'Link', p: 'Paragraph' },
};
const sectionLayouts = { [UUID_B]: 'layout_twocol_section' };

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('declared block type joins its own elements, declaration order', () => {
  const parts = partsForKey(guide, `block:${UUID_A}`, blockTypes);
  assert.deepEqual(parts, [
    { id: '.testimonial__quote', label: 'Quote text' },
    { id: '.testimonial__attribution', label: 'Attribution' },
  ]);
});

test('plugin/undeclared block falls back to the universal block kind', () => {
  assert.deepEqual(partsForKey(guide, `block:${UUID_B}`, blockTypes), [
    { id: '.content', label: 'Content' },
  ]);
  // Unknown uuid: same fallback (the validator's rule).
  assert.deepEqual(partsForKey(guide, `block:${UUID_A.replace('a', 'c')}`, blockTypes), [
    { id: '.content', label: 'Content' },
  ]);
});

test('a tag compound labels by the guide\'s one tag word', () => {
  // The base word (guide.tagLabels, every closed tag) wins over the
  // instance lane's identity word for the same tag; a tag outside the
  // closed set reads the identity word; a tag no lane words spells itself.
  const worded = {
    ...guide,
    tagLabels: { a: 'Link', p: 'Paragraph' },
    instances: { '.s-*': { label: 'Text element', tags: { p: 'Body text', span: 'Span' } } },
  };
  assert.equal(selectorLabel(worded, `block:${UUID_A}`, 'a', blockTypes), 'Link');
  assert.equal(selectorLabel(worded, `block:${UUID_A}`, 'p', blockTypes), 'Paragraph');
  assert.equal(selectorLabel(worded, `block:${UUID_A}`, 'span', blockTypes), 'Span');
  assert.equal(selectorLabel(worded, `block:${UUID_A}`, 'div', blockTypes), 'div');
  // A tag after a type reads the same word (the fixture's testimonial
  // type carries no label, so the type spells itself).
  assert.equal(selectorLabel(worded, `container:${UUID_B}`, 'pb-testimonial a', blockTypes), 'pb-testimonial › Link');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, 'a', blockTypes), 'a');
});

test('declared plugin blocks join their blockPlugins declaration', () => {
  // The 'plugin:{base id}' discriminator routes to guide.blockPlugins,
  // mirroring StyleMapValidator's plugin-block resolution branch.
  assert.deepEqual(partsForKey(guide, `block:${UUID_P}`, blockTypes), [
    { id: '.menu-item', label: 'Menu item' },
  ]);
  assert.equal(selectorLabel(guide, `block:${UUID_P}`, '.menu-item', blockTypes), 'Menu item');
  const declaration = declarationForKey(guide, parseKey(`block:${UUID_P}`), 'plugin:system_menu_block');
  assert.deepEqual(declaration.grants, { typography: ['font-size'] });
  // An undeclared plugin falls back to the universal block kind.
  assert.deepEqual(
    declarationForKey({ ...guide, blockPlugins: {} }, parseKey(`block:${UUID_P}`), 'plugin:system_menu_block'),
    guide.kinds.block,
  );
});

test('componentBlockTypes maps plugin placements to plugin:{base id}', () => {
  // A field node names the page's field, never a per-bundle plugin id;
  // its base plugin is field_block, read off the node without a bundle.
  const blueprint = {
    layout: [{
      kind: 'container',
      uuid: UUID_B,
      layout_id: 'layout_onecol',
      children: [{
        kind: 'region',
        region: 'content',
        children: [
          { kind: 'block', uuid: UUID_A, block_type: 'testimonial' },
          { kind: 'block', uuid: UUID_P, plugin_id: 'system_menu_block:main' },
          { kind: 'block', uuid: UUID_F, field: 'body' },
          { kind: 'block', uuid: UUID_C },
        ],
      }],
    }],
  };
  assert.deepEqual(componentBlockTypes(blueprint), {
    [UUID_A]: 'testimonial',
    [UUID_P]: 'plugin:system_menu_block',
    [UUID_F]: 'plugin:field_block',
    [UUID_C]: null,
  });
});

test('kind declarations join non-block keys; labels fall back to the selector', () => {
  assert.deepEqual(partsForKey(guide, `container:${UUID_A}`, blockTypes), [
    { id: '.section__inner', label: '.section__inner' },
  ]);
});

test('declared layout joins its own elements; unresolved sections fall back to the kind', () => {
  assert.deepEqual(partsForKey(guide, `container:${UUID_B}`, blockTypes, sectionLayouts), [
    { id: '.daedalus-stylish-layout-container', label: 'Layout container' },
  ]);
  // No section → layout table: the universal section kind, exactly the
  // validator's degraded resolution.
  assert.deepEqual(partsForKey(guide, `container:${UUID_B}`, blockTypes), [
    { id: '.section__inner', label: '.section__inner' },
  ]);
  assert.equal(selectorLabel(guide, `container:${UUID_B}`, '.daedalus-stylish-layout-container', blockTypes, sectionLayouts), 'Layout container');
});

test('non-single-class selectors are filtered', () => {
  const ids = partsForKey(guide, `container:${UUID_A}`, blockTypes).map((part) => part.id);
  assert.ok(!ids.includes('div > .loose'));
  assert.equal(PART_SELECTOR.test('div > .loose'), false);
  assert.equal(PART_SELECTOR.test('.ok-Class_1'), true);
});

test('kinds without elements, sub keys, and unparseable keys have no parts', () => {
  assert.deepEqual(partsForKey(guide, `field:${UUID_A}:field_body`, blockTypes), []);
  assert.deepEqual(partsForKey(guide, `block:${UUID_A}::.testimonial__quote`, blockTypes), []);
  assert.deepEqual(partsForKey(guide, 'not a key', blockTypes), []);
  assert.deepEqual(partsForKey(guide, null, blockTypes), []);
});

test('block field keys style as fields — no block-type parts', () => {
  assert.deepEqual(partsForKey(guide, `block:${UUID_A}:field_quote`, blockTypes), []);
});

test('selectorLabel resolves the declared label, selector otherwise', () => {
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.testimonial__quote', blockTypes), 'Quote text');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.undeclared', blockTypes), '.undeclared');
});

test('empty guide payloads (PHP [] artifacts) are tolerated', () => {
  assert.deepEqual(partsForKey({}, `block:${UUID_A}`, {}), []);
  assert.deepEqual(partsForKey({ kinds: [] }, `block:${UUID_A}`, {}), []);
  assert.deepEqual(partsForKey({ kinds: { block: { elements: [] } } }, `block:${UUID_A}`, {}), []);
});

// The nested-tree twins of StyleGuide::findElement()/flattenElements()
// (NestedStyleGuideTest::testTreeHelpers is the PHP anchor; semantics and
// flatten order must match).

test('findElement finds a part at any depth, null when absent', () => {
  const card = guide.blockTypes.card;
  assert.equal(findElement(card, '.block__content').label, 'Content');
  assert.equal(findElement(card, '.card__header').label, 'Header');
  assert.equal(findElement(card, '.card__kicker').label, 'Kicker');
  assert.equal(findElement(card, '.card__nope'), null);

  // The flat shape is the degenerate case: one level behaves like a direct
  // elements[] lookup.
  const flat = { elements: { '.a': { label: 'A' }, '.b': { label: 'B' } } };
  assert.equal(findElement(flat, '.a').label, 'A');
  assert.equal(findElement(flat, '.c'), null);

  // No elements / PHP [] artifacts: nothing found.
  assert.equal(findElement({}, '.a'), null);
  assert.equal(findElement({ elements: [] }, '.a'), null);
  assert.equal(findElement(null, '.a'), null);
});

test('flattenElements lists every depth, parents before children, as authored', () => {
  const flat = flattenElements(guide.blockTypes.card);
  assert.deepEqual(Object.keys(flat), ['.block__content', '.card__header', '.card__kicker', '.card__body']);
  // Each part keeps its declaration as authored, contains slots and nested
  // elements included.
  assert.equal(flat['.block__content'].contains, 'fields');
  assert.deepEqual(flat['.card__body'].contains, ['field:field_summary']);
  assert.equal(flat['.block__content'].elements['.card__header'].label, 'Header');

  // Degenerate and empty shapes. Pattern parts flatten like any key: the
  // flat map is the raw declaration list, and row consumers filter by
  // PART_SELECTOR themselves.
  assert.deepEqual(Object.keys(flattenElements(guide.blockTypes.testimonial)), ['.testimonial__quote', '.testimonial__attribution', '.s-*']);
  assert.deepEqual(flattenElements({}), {});
  assert.deepEqual(flattenElements({ elements: [] }), {});
});

test('declarationForKey drills sub keys recursively; keys stay flat at any depth', () => {
  const header = declarationForKey(guide, parseKey(`block:${UUID_C}::.card__header`), 'card');
  assert.equal(header.label, 'Header');
  assert.equal(header.contains, 'field:field_title');
  const kicker = declarationForKey(guide, parseKey(`block:${UUID_C}::.card__kicker`), 'card');
  assert.equal(kicker.label, 'Kicker');
  // Undeclared parts still resolve to {} (grammar-only on open sites).
  assert.deepEqual(declarationForKey(guide, parseKey(`block:${UUID_C}::.card__nope`), 'card'), {});
  // The one-level (flat) drill is unchanged.
  assert.equal(declarationForKey(guide, parseKey(`block:${UUID_A}::.testimonial__quote`), 'testimonial').label, 'Quote text');
});

test('pattern parts never render as rows', () => {
  // '.s-*' declares a namespace, not a concrete part: PART_SELECTOR
  // filters it, so no consumer draws a row for the pattern itself (the
  // markup view's live rows carry the concrete instances instead).
  const ids = partsForKey(guide, `block:${UUID_A}`, blockTypes).map((part) => part.id);
  assert.ok(!ids.includes('.s-*'));
  assert.equal(PART_SELECTOR.test('.s-*'), false);
  assert.equal(PART_PATTERN.test('.s-*'), true);
  assert.equal(PART_PATTERN.test('.s-a3'), false);
});

test('findPatternElement resolves the namespace; findElement stays exact', () => {
  const testimonial = guide.blockTypes.testimonial;
  // The client twin of PageBuilderDeclarations::findPatternElement().
  assert.equal(findElement(testimonial, '.s-a3'), null);
  assert.equal(findPatternElement(testimonial, '.s-a3').label, 'Text element');
  // Outside the namespace, the bare prefix, or a non-class selector: no
  // match.
  assert.equal(findPatternElement(testimonial, '.sx-a3'), null);
  assert.equal(findPatternElement(testimonial, '.s-'), null);
  assert.equal(findPatternElement(testimonial, 'div > .s-a3'), null);
  assert.equal(findPatternElement({}, '.s-a3'), null);
});

test('declarationForKey drills instance parts through the pattern', () => {
  // A minted class resolves to the pattern's declaration (set refs
  // included), mirroring StyleMapValidator::resolveDeclaration().
  const instance = declarationForKey(guide, parseKey(`block:${UUID_A}::.s-a3`), 'testimonial');
  assert.equal(instance.label, 'Text element');
  assert.deepEqual(instance.grants, { typography: ['font-size'] });
  // Exact declarations always win over the pattern.
  assert.equal(declarationForKey(guide, parseKey(`block:${UUID_A}::.testimonial__quote`), 'testimonial').label, 'Quote text');
  // No pattern declared (the card type): undeclared parts still resolve
  // to {}.
  assert.deepEqual(declarationForKey(guide, parseKey(`block:${UUID_C}::.s-a3`), 'card'), {});
});

test('instance parts label from the markup annotation, pattern fallback', () => {
  // INSTANCE_PART is the client twin of StyleMapValidator::INSTANCE_PART
  // (the printer.js twin pattern).
  assert.equal(INSTANCE_PART.test('.s-a3'), true);
  assert.equal(INSTANCE_PART.test('.s-'), false);
  assert.equal(INSTANCE_PART.test('.s-A3'), false);
  assert.equal(INSTANCE_PART.test('.section'), false);
  // The annotation names the carrier: the stamped (translated) label wins,
  // nested carriers resolve through their parents.
  const markup = { tag: 'div', instances: [
    { class: 's-a3', tag: 'h2', label: 'Heading 2', children: [] },
    { class: 's-b4', tag: 'blockquote', label: 'Block quote', children: [
      { class: 's-c5', tag: 'p', label: 'Paragraph', children: [] },
    ] },
  ] };
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.s-a3', blockTypes, {}, markup), 'Heading 2');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.s-c5', blockTypes, {}, markup), 'Paragraph');
  assert.equal(findInstancePart(markup, '.s-c5').tag, 'p');
  assert.equal(findInstancePart(markup, '.s-zz'), null);
  // No annotation (the satellite window, an unspoken-for key): the label
  // degrades to the '.s-*' pattern declaration's label.
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.s-a3', blockTypes), 'Text element');
  // No pattern declared either: the selector stays the last resort.
  assert.equal(selectorLabel(guide, `block:${UUID_C}`, '.s-a3', blockTypes), '.s-a3');
});

test('selectorLabel words the relations: sibling combinators fold into their predecessor, qualifiers phrase after the subject', () => {
  const label = (selector) => selectorLabel(related, `container:${UUID_B}`, selector, blockTypes, sectionLayouts);
  // A sibling step is one group with its predecessor: the subject, then
  // "right after" (+) or "after" (~) the predecessor's noun phrase.
  assert.equal(label('> p + p'), 'Paragraph right after a paragraph');
  assert.equal(label('> p ~ p'), 'Paragraph after a paragraph');
  assert.equal(label('> p + *'), 'Children right after a paragraph');
  assert.equal(label('> pb-text:has(a) + pb-text'), 'Text blocks right after a text containing a link');
  // Qualifiers: :has phrases then :not phrases, a sibling-led :has
  // argument reading "before", an implied :not subject "without".
  assert.equal(label('> pb-text:has(a)'), 'Text blocks containing a link');
  assert.equal(label('> pb-text:has(.text-block-link)'), 'Text blocks containing a link');
  assert.equal(label('> pb-text:has(+ pb-image)'), 'Text blocks right before an image');
  assert.equal(label('> pb-text:has(~ pb-image)'), 'Text blocks before an image');
  assert.equal(label('> *:not(pb-image)'), 'Children except images');
  assert.equal(label('> *:not(pb-image):not(pb-video)'), 'Children except images except pb-videos');
  assert.equal(label('> pb-text:not(:has(a))'), 'Text blocks without a link');
  assert.equal(label('> pb-text:has(a):has(img)'), 'Text blocks containing a link containing an img');
  assert.equal(label('> pb-text:not(:has(+ pb-image))'), 'Text blocks not right before an image');
  // Descent steps still join with the chevron; the relation rides its
  // group.
  assert.equal(label('> pb-text .text-block-link ~ .text-block-link'), 'Text blocks › Link after a link');
  assert.equal(label('> pb-container:has(> pb-image) > *'), 'Containers containing an image › Children');
  assert.equal(label('> pb-text:has(a) .text-block-link'), 'Text blocks containing a link › Link');
  // The unrelated shapes read as before.
  assert.equal(label('> pb-text .text-block-link'), 'Text blocks › Link');
  assert.equal(label('> *'), 'Children');
  assert.equal(label('p'), 'Paragraph');
  // A terminal pseudo-element is one more step, its box word after the
  // host; the owner's own box (no host compound) is the word alone, the
  // word the page-elements tree gives the box's row.
  assert.equal(label('&::before'), 'Before');
  assert.equal(label('&::after'), 'After');
  assert.equal(label('> pb-text::before'), 'Text blocks › Before');
  assert.equal(label('> pb-text .text-block-link::after'), 'Text blocks › Link › After');
  assert.equal(label('> p + p::after'), 'Paragraph right after a paragraph › After');
  assert.equal(label('p::before'), 'Paragraph › Before');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.testimonial__quote::after', blockTypes), 'Quote text › After');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '&::before', blockTypes), 'Before');
  // Outside the grammar the spelling stays the last resort.
  assert.equal(label('&::marker'), '&::marker');
  assert.equal(label('& ::before'), '& ::before');
});

test('an instance class after a type never prints itself: the annotation row, else the covering namespace', () => {
  // A minted identity class is worn by one element in the world, so a
  // type context has no anatomy row for it; it labels through the same
  // instance path a bare instance part does (partLabel), never as `.s-p1`.
  const minted = {
    ...related,
    blockTypes: { ...related.blockTypes, formatted_text: { label: 'Formatted text' } },
    instances: { '.s-*': { label: 'Text element' } },
  };
  const key = `container:${UUID_B}`;
  const label = (guide, selector, markup = null) =>
    selectorLabel(guide, key, selector, blockTypes, sectionLayouts, markup);
  // No annotation in reach: the site namespace's word.
  assert.equal(label(minted, '> pb-formatted-text .s-p1'), 'Formatted text blocks › Text element');
  assert.equal(label(minted, 'pb-formatted-text .s-p1'), 'Formatted text blocks › Text element');
  assert.equal(label(minted, '> pb-formatted-text:has(.s-p1)'), 'Formatted text blocks containing a text element');
  assert.equal(label(minted, '> pb-formatted-text .s-p1 + .s-p1'), 'Formatted text blocks › Text element right after a text element');
  // The annotation row's word when markup is given.
  const markup = { tag: 'div', instances: [{ class: 's-p1', tag: 'p', label: 'Paragraph', children: [] }] };
  assert.equal(label(minted, '> pb-formatted-text .s-p1', markup), 'Formatted text blocks › Paragraph');
  // The type's own pattern part covers before the site namespace.
  const patterned = {
    ...minted,
    blockTypes: {
      ...minted.blockTypes,
      formatted_text: { label: 'Formatted text', elements: { '.s-*': { label: 'Rich text element' } } },
    },
  };
  assert.equal(label(patterned, '> pb-formatted-text .s-p1'), 'Formatted text blocks › Rich text element');
  // A declared part under the type still labels as authored.
  assert.equal(label(minted, '> pb-text .text-block-link'), 'Text blocks › Link');
  // The address sentence (write-address.js addressLabel) rides the same
  // words: the class of things reads as words, never the identity class.
  const sentence = (selector) => addressLabel(minted, { key, selector }, 'Welcome', blockTypes, sectionLayouts);
  assert.equal(sentence('pb-formatted-text .s-p1'), 'Formatted text block text elements in Welcome');
  assert.equal(sentence('> pb-formatted-text .s-p1'), 'Formatted text block text elements directly in Welcome');
  assert.ok(!sentence('> pb-formatted-text .s-p1').includes('.s-'));
  // No namespace declared anywhere: the spelling stays the last resort,
  // the same degrade a bare instance part has.
  const { instances, ...unminted } = minted;
  assert.equal(label(unminted, '> pb-formatted-text .s-p1'), 'Formatted text blocks › .s-p1');
});

test('partsForKey flattens the nested tree; row shape unchanged', () => {
  assert.deepEqual(partsForKey(guide, `block:${UUID_C}`, blockTypes), [
    { id: '.block__content', label: 'Content' },
    { id: '.card__header', label: 'Header' },
    { id: '.card__kicker', label: 'Kicker' },
    { id: '.card__body', label: 'Body' },
  ]);
  assert.equal(selectorLabel(guide, `block:${UUID_C}`, '.card__kicker', blockTypes), 'Kicker');
});

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
  console.error(`${failures} of ${tests.length} part-join tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} part-join tests passed.`);
