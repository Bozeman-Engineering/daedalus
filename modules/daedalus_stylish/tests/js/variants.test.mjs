/**
 * Self-asserting tests for the client variant-scope mirror.
 *
 * Run directly (`node tests/js/variants.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite.
 * PHP is authoritative for scope semantics (StyleMapValidator::captureScope
 * / scopeMatches); these tests lock the mirror to the same rules, plus the
 * element-relative entry math the save/push/copy/paste gestures share.
 * Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  isVariantRef,
  entryRefs,
  entryVariants,
  orderedEntryRefs,
  orderedEntryVariants,
  axisDescription,
  axisLabel,
  axisOwns,
  axisVocabulary,
  shadowedProperties,
  captureScope,
  declaredDefaultEntry,
  skinDefaultEntry,
  scopeMatches,
  applicableVariants,
  elementEntries,
  hasCustomStyles,
  entriesAtKey,
  effectiveEntry,
  effectiveElementMap,
  findVariant,
  isShipped,
  locked,
  propertySuppliers,
  suppliedValue,
  subtreeSlotTable,
  captureEntries,
  captureSources,
  pushStyles,
  saveStyles,
  shadowClearingOps,
  stencilProvenance,
  variantFit,
  rankVariants,
} from '../../js/src/variant-scope.js';
import { formatSlotKey } from '../../js/src/slot-key.js';
import { layoutTree } from '../../js/src/style-guide.js';
import { computeOps } from '../../js/src/code/ops.js';
import { EMPTY_MATERIALS, roster, stencilRecord, variantRecord } from './materials-fixture.mjs';

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';
const UUID_P = 'cccccccc-1111-2222-3333-444444444444';
const blockTypes = { [UUID_A]: 'testimonial', [UUID_B]: null, [UUID_P]: 'plugin:system_menu_block' };
const sectionLayouts = { [UUID_A]: 'layout_twocol_section' };

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('captureScope mirrors the server vocabulary', () => {
  assert.equal(captureScope(`block:${UUID_A}`, blockTypes), 'block_type:testimonial');
  assert.equal(captureScope(`block:${UUID_B}`, blockTypes), 'kind:block');
  assert.equal(captureScope(`block:${UUID_A}`, {}), 'kind:block');
  assert.equal(captureScope(`container:${UUID_A}`, blockTypes), 'kind:container');
  assert.equal(captureScope(`entity:${UUID_A}`), 'kind:entity');
  assert.equal(captureScope(`field:${UUID_A}:field_body`), 'kind:field');
  // A field of an inline block styles (and scopes) as a field.
  assert.equal(captureScope(`block:${UUID_A}:field_quote`, blockTypes), 'kind:field');
  // Sub-element keys capture the parent's scope.
  assert.equal(captureScope(`block:${UUID_A}::.quote`, blockTypes), 'block_type:testimonial');
  // Sections scope per layout when the table resolves them, degrading to
  // the kind exactly like blocks.
  assert.equal(captureScope(`container:${UUID_A}`, blockTypes, sectionLayouts), 'layout:layout_twocol_section');
  assert.equal(captureScope(`container:${UUID_A}::.daedalus-stylish-layout-container`, blockTypes, sectionLayouts), 'layout:layout_twocol_section');
  assert.equal(captureScope(`container:${UUID_B}`, blockTypes, sectionLayouts), 'kind:container');
  assert.equal(captureScope('bogus:123'), null);
  // A plugin placement (the table's 'plugin:{base id}' discriminator)
  // captures the block family's other refinement; sub-element keys ride.
  assert.equal(captureScope(`block:${UUID_P}`, blockTypes), 'block_plugin:system_menu_block');
  assert.equal(captureScope(`block:${UUID_P}::.menu-item`, blockTypes), 'block_plugin:system_menu_block');
});

test('scopeMatches: one family rule — kind wildcard, exact refinements', () => {
  assert.equal(scopeMatches('block_type:testimonial', `block:${UUID_A}`, blockTypes), true);
  assert.equal(scopeMatches('kind:container', `container:${UUID_A}`), true);
  // The kind side (either one) matches inside the block family…
  assert.equal(scopeMatches('kind:block', `block:${UUID_A}`, blockTypes), true);
  assert.equal(scopeMatches('block_type:testimonial', `block:${UUID_B}`, blockTypes), true);
  // …but two RESOLVED block types never cross, and a section key never
  // pairs with the block family.
  assert.equal(scopeMatches('block_type:cta', `block:${UUID_A}`, blockTypes), false);
  assert.equal(scopeMatches('kind:block', `container:${UUID_A}`), false);
  assert.equal(scopeMatches('kind:container', 'bogus'), false);
  // Families never mix, degraded or resolved: blocks never hold layouts
  // (the old collapsed-layout-block bridge is gone with the concept).
  assert.equal(scopeMatches('kind:container', `block:${UUID_B}`, blockTypes), false);
  assert.equal(scopeMatches('layout:layout_onecol', `block:${UUID_B}`, blockTypes), false);
  assert.equal(scopeMatches('kind:container', `block:${UUID_A}`, blockTypes), false);
  // The section family follows the same rule (kernel mirror:
  // ElementStylesTest::testSectionLayoutResolution): "kind:container" on
  // either side is the wildcard — declared section-wide variants and
  // degraded layout resolution alike — while layouts must match exactly,
  // and families never mix.
  assert.equal(scopeMatches('layout:layout_twocol_section', `container:${UUID_A}`, blockTypes, sectionLayouts), true);
  assert.equal(scopeMatches('kind:container', `container:${UUID_A}`, blockTypes, sectionLayouts), true);
  assert.equal(scopeMatches('layout:layout_twocol_section', `container:${UUID_B}`, blockTypes, sectionLayouts), true);
  assert.equal(scopeMatches('layout:layout_onecol', `container:${UUID_A}`, blockTypes, sectionLayouts), false);
  assert.equal(scopeMatches('layout:layout_twocol_section', `block:${UUID_A}`, blockTypes, sectionLayouts), false);
  // Plugin placements (kernel mirror: DaedalusStylishVariantTest::testScopeCapture):
  // exact refinement and the kind wildcard match; the block family's two
  // refinements never pair with each other.
  assert.equal(scopeMatches('block_plugin:system_menu_block', `block:${UUID_P}`, blockTypes), true);
  assert.equal(scopeMatches('kind:block', `block:${UUID_P}`, blockTypes), true);
  assert.equal(scopeMatches('block_plugin:other_plugin', `block:${UUID_P}`, blockTypes), false);
  assert.equal(scopeMatches('block_type:testimonial', `block:${UUID_P}`, blockTypes), false);
  assert.equal(scopeMatches('block_plugin:system_menu_block', `block:${UUID_A}`, blockTypes), false);
  assert.equal(scopeMatches('block_plugin:system_menu_block', `container:${UUID_A}`, blockTypes, sectionLayouts), false);
  // base:{tag} variants match every element (kernel mirror:
  // ScopeValidationTest::testBaseScope): the tag is a live-DOM fact, so
  // the gate is offer-time (applicableVariants' tag filter), never here.
  assert.equal(scopeMatches('base:a', `block:${UUID_A}`, blockTypes), true);
  assert.equal(scopeMatches('base:a', `container:${UUID_A}`, blockTypes, sectionLayouts), true);
  assert.equal(scopeMatches('base:a', 'bogus'), false);
});

test('applicableVariants filters the store\'s variants by scope', () => {
  const variants = roster(
    variantRecord('cta_bold', { scope: 'block_type:cta' }),
    variantRecord('quote_soft', { scope: 'block_type:testimonial' }),
    variantRecord('any_block', { scope: 'kind:block' }),
    variantRecord('section_wide', { scope: 'kind:container' }),
    variantRecord('twocol_hero', { scope: 'layout:layout_twocol_section' }),
    variantRecord('onecol_hero', { scope: 'layout:layout_onecol' }),
    variantRecord('broken', { scope: null }),
  );
  assert.deepEqual(
    applicableVariants(variants, `block:${UUID_A}`, blockTypes).map((v) => v.id),
    ['quote_soft', 'any_block'],
  );
  // A resolved layout offers layout-exact variants plus section-wide ones.
  assert.deepEqual(
    applicableVariants(variants, `container:${UUID_A}`, blockTypes, sectionLayouts).map((v) => v.id),
    ['section_wide', 'twocol_hero'],
  );
  // Degraded resolution (no layout table) captures the kind, which is the
  // family wildcard: every section variant stays on offer.
  assert.deepEqual(
    applicableVariants(variants, `container:${UUID_A}`, blockTypes).map((v) => v.id),
    ['section_wide', 'twocol_hero', 'onecol_hero'],
  );
  assert.deepEqual(applicableVariants(EMPTY_MATERIALS, `container:${UUID_A}`), []);
});

test('applicableVariants gates base:{tag} variants on the rendered tag', () => {
  const variants = roster(
    variantRecord('quote_soft', { scope: 'block_type:testimonial' }),
    variantRecord('accent_link', { scope: 'base:a' }),
    variantRecord('display_heading', { scope: 'base:h2' }),
  );
  // The selection renders as an anchor: anchor variants join the offer
  // beside the family-matched ones; other tags' variants stay out.
  assert.deepEqual(
    applicableVariants(variants, `block:${UUID_A}`, blockTypes, {}, [], 'a').map((v) => v.id),
    ['quote_soft', 'accent_link'],
  );
  assert.deepEqual(
    applicableVariants(variants, `block:${UUID_A}`, blockTypes, {}, [], 'h2').map((v) => v.id),
    ['quote_soft', 'display_heading'],
  );
  // Unknown tag (no canvas: the satellite's degraded posture, or a div
  // root no base rule ever hits): base variants never over-offer.
  assert.deepEqual(
    applicableVariants(variants, `block:${UUID_A}`, blockTypes).map((v) => v.id),
    ['quote_soft'],
  );
  assert.deepEqual(
    applicableVariants(variants, `block:${UUID_A}`, blockTypes, {}, [], 'div').map((v) => v.id),
    ['quote_soft'],
  );
});

test('stencilProvenance resolves the nearest self-or-ancestor stamp', () => {
  const layout = [
    {
      uuid: UUID_A,
      layout_id: 'layout_onecol',
      stencil: 'cta_card',
      children: [
        { uuid: UUID_B, block_type: 'testimonial' },
        {
          uuid: 'cccccccc-1111-2222-3333-444444444444',
          layout_id: 'layout_onecol',
          stencil: 'inner_band',
          children: [{ uuid: 'dddddddd-1111-2222-3333-444444444444', block_type: 'basic' }],
        },
      ],
    },
    { uuid: 'eeeeeeee-1111-2222-3333-444444444444', layout_id: 'layout_onecol', children: [] },
  ];
  // The stamped root reads its own stamp; descendants inherit it — a
  // placement is the whole placed subtree.
  assert.equal(stencilProvenance(`container:${UUID_A}`, layout), 'cta_card');
  assert.equal(stencilProvenance(`block:${UUID_B}`, layout), 'cta_card');
  // Part keys resolve through their root.
  assert.equal(stencilProvenance(`block:${UUID_B}::.quote`, layout), 'cta_card');
  // A nested placement's own stamp shadows its ancestor's.
  assert.equal(stencilProvenance('container:cccccccc-1111-2222-3333-444444444444', layout), 'inner_band');
  assert.equal(stencilProvenance('block:dddddddd-1111-2222-3333-444444444444', layout), 'inner_band');
  // Unstamped, unknown, and treeless selections read as unprovenanced.
  assert.equal(stencilProvenance('container:eeeeeeee-1111-2222-3333-444444444444', layout), null);
  assert.equal(stencilProvenance('container:ffffffff-1111-2222-3333-444444444444', layout), null);
  assert.equal(stencilProvenance(`container:${UUID_A}`, []), null);
  assert.equal(stencilProvenance('bogus', layout), null);
});

test('applicableVariants gates stencil-owned variants on provenance', () => {
  const layout = [
    {
      uuid: UUID_A,
      layout_id: 'layout_twocol_section',
      stencil: 'cta_card',
      children: [{ uuid: UUID_B, block_type: null }],
    },
    { uuid: 'eeeeeeee-1111-2222-3333-444444444444', layout_id: 'layout_onecol', children: [] },
  ];
  // Ownership is the record's owned_by; an orphan owns by an id the
  // roster holds no stencil for (the ONE orphan rule, isOrphaned).
  const variants = roster(
    variantRecord('free_look', { scope: 'kind:container' }),
    variantRecord('card_look', { scope: 'kind:container', owned_by: 'cta_card' }),
    variantRecord('other_look', { scope: 'kind:container', owned_by: 'other_stencil' }),
    variantRecord('orphan_look', { scope: 'kind:container', owned_by: 'deleted_stencil' }),
    variantRecord('block_look', { scope: 'kind:block', owned_by: 'cta_card' }),
    stencilRecord('cta_card'),
    stencilRecord('other_stencil'),
  );
  // On the placement: unowned plus the owner's own; other owners and
  // orphans stay out.
  assert.deepEqual(
    applicableVariants(variants, `container:${UUID_A}`, {}, {}, layout).map((v) => v.id),
    ['free_look', 'card_look'],
  );
  // Ownership inherits down the placed subtree (the block inside).
  assert.deepEqual(
    applicableVariants(variants, `block:${UUID_B}`, {}, {}, layout).map((v) => v.id),
    ['block_look'],
  );
  // Off the placement, owned variants do not offer.
  assert.deepEqual(
    applicableVariants(variants, 'container:eeeeeeee-1111-2222-3333-444444444444', {}, {}, layout).map((v) => v.id),
    ['free_look'],
  );
  // No layout tree: the degraded posture never over-offers owned variants.
  assert.deepEqual(
    applicableVariants(variants, `container:${UUID_A}`).map((v) => v.id),
    ['free_look'],
  );
});

test('layoutTree keeps the stencil provenance stamps', () => {
  const blueprint = {
    layout_builder__layout: [
      {
        uuid: UUID_A,
        layout_id: 'layout_onecol',
        stencil: 'cta_card',
        children: [
          { uuid: UUID_B, block_type: 'testimonial', stencil: 'block_stencil' },
        ],
      },
    ],
  };
  const tree = layoutTree(blueprint);
  assert.equal(tree[0].stencil, 'cta_card');
  assert.equal(tree[0].children[0].stencil, 'block_stencil');
  // Unstamped nodes carry no stencil key at all.
  const bare = layoutTree({ layout_builder__layout: [{ uuid: UUID_A, layout_id: 'layout_onecol', children: [] }] });
  assert.equal('stencil' in bare[0], false);
});

const KEY = `block:${UUID_A}`;
const map = {
  [KEY]: { default: { padding: '2rem' } },
  [`${KEY}::.quote`]: { default: { 'font-style': 'italic' } },
  [`${KEY}:field_quote`]: { default: { color: 'red' } },
  [`container:${UUID_B}`]: { variants: [{ variant: 'section_wide', fragment: '' }] },
};

test('elementEntries extracts element-relative custom entries only', () => {
  assert.deepEqual(elementEntries(map, KEY), {
    '': { default: { padding: '2rem' } },
    '::.quote': { default: { 'font-style': 'italic' } },
  });
  // A variant reference is not custom styles.
  assert.deepEqual(elementEntries(map, `container:${UUID_B}`), {});
  assert.equal(hasCustomStyles(map, KEY), true);
  assert.equal(hasCustomStyles(map, `container:${UUID_B}`), false);
});

test('entriesAtKey projects relative entries onto full keys', () => {
  assert.deepEqual(entriesAtKey(KEY, { '': { default: { margin: '1rem' } }, '::.x': { hover: { color: 'red' } } }), {
    [KEY]: { default: { margin: '1rem' } },
    [`${KEY}::.x`]: { hover: { color: 'red' } },
  });
});

test('findVariant resolves by record id alone', () => {
  const variants = [
    { id: 'gritty', label: 'Gritty' },
    { id: 'sleek', label: 'Sleek' },
  ];
  assert.equal(findVariant(variants, 'gritty').label, 'Gritty');
  // The retired uuid spelling is unresolved, like a deleted variant.
  assert.equal(findVariant(variants, UUID_B), null);
  assert.equal(findVariant(variants, 'missing'), null);
  assert.equal(findVariant(null, 'gritty'), null);
});

test('effectiveEntry: the edit-mode resolver with provenance', () => {
  const variants = [
    {
      id: 'gritty',
      label: 'Gritty',
      value: {
        '': { default: { padding: '2rem', 'background-color': 'red' }, hover: { color: 'white' } },
        '::.quote': { default: { 'font-style': 'italic' } },
        'container(1)': { default: { display: 'flex' } },
      },
    },
  ];
  const refMap = { [`container:${UUID_B}`]: { variants: [{ variant: 'gritty', fragment: '' }] } };
  // A custom entry passes through, provenance-free.
  assert.deepEqual(effectiveEntry(map, KEY, variants), {
    entry: { default: { padding: '2rem' } },
    variant: null,
    reference: null,
  });
  // No entry at all: honestly nothing.
  assert.deepEqual(effectiveEntry(map, `entity:${UUID_A}`, variants), { entry: null, variant: null, reference: null });
  // An on-variant root resolves the variant's '' styles, condition-keyed.
  const root = effectiveEntry(refMap, `container:${UUID_B}`, variants);
  assert.deepEqual(root.entry, { default: { padding: '2rem', 'background-color': 'red' }, hover: { color: 'white' } });
  assert.equal(root.variant.label, 'Gritty');
  assert.equal(root.reference, 'gritty');
  // A part key resolves its '::.class' relative through the ROOT's ref.
  const part = effectiveEntry(refMap, `container:${UUID_B}::.quote`, variants);
  assert.deepEqual(part.entry, { default: { 'font-style': 'italic' } });
  assert.equal(part.reference, 'gritty');
  // A part the variant does not style: on the variant, but nothing to show.
  assert.deepEqual(effectiveEntry(refMap, `container:${UUID_B}::.other`, variants), {
    entry: null,
    variant: variants[0],
    reference: 'gritty',
  });
  // A uuid reference is unresolved (the findVariant semantics): the
  // provenance flag survives, the entry is honestly empty.
  assert.deepEqual(effectiveEntry({ [KEY]: { variants: [{ variant: UUID_A, fragment: '' }] } }, KEY, variants), { entry: null, variant: null, reference: UUID_A });
  // Unresolvable reference: provenance survives, the entry is honestly
  // empty (never the raw {variant} object leaking as styles).
  assert.deepEqual(effectiveEntry(refMap, `container:${UUID_B}`, []), { entry: null, variant: null, reference: 'gritty' });
  // Variant slot keys style CHILDREN via compiled selectors, never as the
  // child's own entry: a child key resolves against its OWN root only.
  assert.deepEqual(effectiveEntry(refMap, `container:${UUID_A}`, variants), { entry: null, variant: null, reference: null });
  // A block-field key is its own element root: the block's ref never
  // bleeds into it, and its own ref resolves.
  const fieldMap = { [`block:${UUID_A}`]: { variants: [{ variant: 'gritty', fragment: '' }] }, [`block:${UUID_A}:field_quote`]: { default: { color: 'red' } } };
  assert.deepEqual(effectiveEntry(fieldMap, `block:${UUID_A}:field_quote`, variants), {
    entry: { default: { color: 'red' } },
    variant: null,
    reference: null,
  });
  assert.equal(effectiveEntry(fieldMap, `block:${UUID_A}`, variants).reference, 'gritty');
  // Malformed input degrades to nothing.
  assert.deepEqual(effectiveEntry(refMap, null, variants), { entry: null, variant: null, reference: null });
});

test('effectiveElementMap resolves a root variant reference', () => {
  const variants = [
    { id: 'section_wide', value: { '': { default: { padding: '4rem' } }, '::.inner': { default: { margin: '0' } } } },
  ];
  const effective = effectiveElementMap(map, `container:${UUID_B}`, variants);
  assert.deepEqual(effective[`container:${UUID_B}`], { default: { padding: '4rem' } });
  assert.deepEqual(effective[`container:${UUID_B}::.inner`], { default: { margin: '0' } });
  // Non-referencing elements pass through untouched.
  assert.deepEqual(effectiveElementMap(map, KEY, variants), map);
  // Unknown reference: the ref key drops (nothing to resolve).
  const unknown = effectiveElementMap(map, `container:${UUID_B}`, []);
  assert.equal(unknown[`container:${UUID_B}`], undefined);
  // Override layers win at PART keys too (effectiveEntry's rule): a part
  // entry beside the worn variant merges OVER the variant's part styles
  // instead of being clobbered by them.
  const layered = {
    ...map,
    [`container:${UUID_B}::.inner`]: { default: { margin: '2rem' }, hover: { color: 'red' } },
  };
  assert.deepEqual(effectiveElementMap(layered, `container:${UUID_B}`, variants)[`container:${UUID_B}::.inner`], {
    default: { margin: '2rem' },
    hover: { color: 'red' },
  });
});

test('effectiveElementMap projects element relatives only, never slot keys', () => {
  // A composition variant's slot keys address CHILDREN through the staged
  // layout; concatenating them onto the element's key would mint malformed
  // map keys (the server's detach materialization
  // resolves them onto the children's own keys instead).
  const variants = [
    {
      id: 'hero',
      value: {
        '': { default: { padding: '4rem' } },
        '::.inner': { default: { margin: '0' } },
        'container(1)': { default: { display: 'flex' } },
        'block(text,2)': { default: { color: 'red' } },
        'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
        'field(field_x)': { default: { margin: '1rem' } },
      },
    },
  ];
  const refMap = { [`container:${UUID_B}`]: { variants: [{ variant: 'hero', fragment: '' }] } };
  assert.deepEqual(effectiveElementMap(refMap, `container:${UUID_B}`, variants), {
    [`container:${UUID_B}`]: { default: { padding: '4rem' } },
    [`container:${UUID_B}::.inner`]: { default: { margin: '0' } },
  });
});

test('paste ops: clipboard replaces the effective element styles', () => {
  const conditions = { hover: { label: 'Hover', wrapper: ':hover' }, narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)' } };
  const clipboard = { '': { default: { padding: '3rem' }, hover: { color: 'blue' } } };
  const ops = computeOps(KEY, map, entriesAtKey(KEY, clipboard), conditions);
  // Sets the pasted values, unsets what the clipboard doesn't carry —
  // including the whole sub-element entry.
  const byKey = Object.groupBy ? Object.groupBy(ops, (op) => op.key) : ops.reduce((acc, op) => ((acc[op.key] ??= []).push(op), acc), {});
  const rootOps = byKey[KEY] ?? [];
  const defaults = rootOps.find((op) => !op.condition);
  assert.deepEqual(defaults.properties, { padding: '3rem' });
  const hover = rootOps.find((op) => op.condition === 'hover');
  assert.deepEqual(hover.properties, { color: 'blue' });
  const sub = (byKey[`${KEY}::.quote`] ?? [])[0];
  assert.deepEqual(sub.properties, { 'font-style': null });
  // An identical clipboard produces no ops.
  const same = elementEntries(map, KEY);
  assert.deepEqual(computeOps(KEY, map, entriesAtKey(KEY, same), conditions), []);
});

// Composition capture: a container hosting two text blocks (a non-inline
// placement between them — transparent to slots AND indexes), and a
// nested container (containers all the way down) hosting an image block.
const SEC = 'cccccccc-1111-2222-3333-444444444444';
const T1 = 'dddddddd-1111-2222-3333-444444444444';
const PLUGIN = 'eeeeeeee-1111-2222-3333-444444444444';
const T2 = 'ffffffff-1111-2222-3333-444444444444';
const NSEC = 'cdcdcdcd-1111-2222-3333-444444444444';
const NSEC2 = 'abababab-1111-2222-3333-444444444444';
const IMG = 'efefefef-1111-2222-3333-444444444444';
const layout = [
  {
    uuid: SEC,
    layout_id: 'daedalus_stylish_container',
    children: [
      { uuid: T1, block_type: 'text' },
      { uuid: PLUGIN, block_type: null },
      { uuid: T2, block_type: 'text' },
      {
        uuid: NSEC,
        layout_id: 'daedalus_stylish_container',
        children: [{ uuid: IMG, block_type: 'image' }],
      },
      {
        uuid: NSEC2,
        layout_id: 'daedalus_stylish_container',
        children: [],
      },
    ],
  },
];

test('subtreeSlotTable mirrors the apply-time resolution contract', () => {
  const table = subtreeSlotTable(`container:${SEC}`, layout);
  const keys = Object.fromEntries(Object.entries(table).map(([k, segments]) => [k, formatSlotKey(segments)]));
  assert.deepEqual(keys, {
    [`block:${T1}`]: 'block(text,1)',
    // The non-inline placement is transparent: no slot, no index advance.
    [`block:${T2}`]: 'block(text,2)',
    // Nested containers count positionally — container(n) — and recursion
    // opens their own children under the container's path.
    [`container:${NSEC}`]: 'container(1)',
    [`block:${IMG}`]: 'container(1)/block(image,1)',
    [`container:${NSEC2}`]: 'container(2)',
  });
  // A nested container is an ordinary container root: its own subtree
  // opens at its children exactly like a top-level row's.
  const fromNested = subtreeSlotTable(`container:${NSEC}`, layout);
  assert.equal(formatSlotKey(fromNested[`block:${IMG}`]), 'block(image,1)');
  // A block root opens at its fields ([] lets block:{uuid}:{f}
  // keys translate); other root kinds carry no paths at all.
  assert.deepEqual(subtreeSlotTable(`block:${T2}`, layout), { [`block:${T2}`]: [] });
  assert.deepEqual(subtreeSlotTable(`entity:${SEC}`, layout), {});
  // A multi-region section carries no slot space: named slots left the
  // grammar with the region segment.
  const multi = [{ uuid: SEC, layout_id: 'layout_twocol_section', regions: { first: [{ uuid: T1, block_type: 'text' }] } }];
  assert.deepEqual(subtreeSlotTable(`container:${SEC}`, multi), {});
});

test('captureEntries walks the staged subtree into slot keys', () => {
  const subtreeMap = {
    [`container:${SEC}`]: { default: { padding: '0' } },
    [`container:${SEC}::.daedalus-stylish-layout-container`]: { default: { gap: '1rem' } },
    // Children pseudo-part entries ride their element like part entries:
    // the root's own '::.class::>' stays a relative suffix, a subtree
    // child's '::>' translates onto its slot key.
    [`container:${SEC}::.daedalus-stylish-layout-container::>`]: { default: { 'flex-grow': '1' } },
    [`block:${T2}`]: { default: { margin: '0' }, hover: { color: 'red' } },
    [`block:${T2}::.quote`]: { default: { 'font-style': 'italic' } },
    [`block:${T2}:field_body`]: { default: { color: 'blue' } },
    // Untranslatable children stay behind: a non-inline placement, an
    // element outside the subtree. A child wearing only its own variant
    // travels AS the reference (refs stay refs).
    [`block:${PLUGIN}`]: { default: { padding: '1rem' } },
    [`block:${T1}`]: { variants: [{ variant: 'card', fragment: '' }] },
    [`block:${UUID_B}`]: { default: { padding: '9rem' } },
    // The nested composition travels at its OWN slot keys — a nested
    // container is a distinct element with its own stamped root.
    [`container:${NSEC}`]: { default: { padding: '9px', margin: '1px' } },
    [`container:${NSEC}::.daedalus-stylish-layout-container`]: { default: { 'max-width': '40rem' } },
    [`container:${NSEC}::>`]: { default: { 'min-width': '0' } },
    [`block:${IMG}`]: { default: { 'border-radius': '4px' } },
  };
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    '::.daedalus-stylish-layout-container': { default: { gap: '1rem' } },
    '::.daedalus-stylish-layout-container::>': { default: { 'flex-grow': '1' } },
    'block(text,1)': { variants: [{ variant: 'card', fragment: '' }] },
    'block(text,2)': { default: { margin: '0' }, hover: { color: 'red' } },
    'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    'block(text,2)/field(field_body)': { default: { color: 'blue' } },
    'container(1)': { default: { padding: '9px', margin: '1px' } },
    'container(1)::.daedalus-stylish-layout-container': { default: { 'max-width': '40rem' } },
    'container(1)::>': { default: { 'min-width': '0' } },
    'container(1)/block(image,1)': { default: { 'border-radius': '4px' } },
  });
  // Captured slot keys order SHALLOW-FIRST: a parent's fragment precedes
  // its children's in the minted styles, so apply distributes the
  // parent's nested references before placing deeper fragments.
  const capturedKeys = Object.keys(captureEntries(subtreeMap, `container:${SEC}`, layout));
  assert.ok(capturedKeys.indexOf('container(1)') < capturedKeys.indexOf('container(1)/block(image,1)'));
  // From the nested container root: an ordinary container capture.
  assert.deepEqual(captureEntries(subtreeMap, `container:${NSEC}`, layout), {
    '': { default: { padding: '9px', margin: '1px' } },
    '::.daedalus-stylish-layout-container': { default: { 'max-width': '40rem' } },
    '::>': { default: { 'min-width': '0' } },
    'block(image,1)': { default: { 'border-radius': '4px' } },
  });
  // From a plain block root: its field entries become field(name) paths.
  assert.deepEqual(captureEntries(subtreeMap, `block:${T2}`, layout), {
    '': { default: { margin: '0' }, hover: { color: 'red' } },
    '::.quote': { default: { 'font-style': 'italic' } },
    'field(field_body)': { default: { color: 'blue' } },
  });
  // Without a layout tree, capture degrades to single-element entries.
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`, []), elementEntries(subtreeMap, `container:${SEC}`));
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`), elementEntries(subtreeMap, `container:${SEC}`));
});

test('capture: the root is overrides-only, children keep refs as refs', () => {
  // The root's own layered entry contributes its condition layers with the
  // references stripped (they fold or stay per saveStyles' axis rule); a
  // pure-reference root contributes nothing.
  const layered = {
    [KEY]: { variants: [{ variant: 'gritty', fragment: '' }], default: { padding: '3rem' } },
    [`${KEY}::.quote`]: { default: { color: 'red' } },
    [`container:${UUID_B}`]: { variants: [{ variant: 'section_wide', fragment: '' }] },
  };
  assert.deepEqual(elementEntries(layered, KEY), {
    '': { default: { padding: '3rem' } },
    '::.quote': { default: { color: 'red' } },
  });
  assert.equal(hasCustomStyles(layered, KEY), true);
  assert.deepEqual(elementEntries(layered, `container:${UUID_B}`), {});
  assert.equal(hasCustomStyles(layered, `container:${UUID_B}`), false);
  // The subtree walk: a layered child's overrides travel AND its worn root
  // references ride the captured fragment verbatim (refs stay refs: the
  // minted variant's fragments are entry-shaped). A distributed FRAGMENT
  // wearing never travels: it is owned by the composition that
  // distributed it (folded by saveStyles when replaced, left behind
  // otherwise).
  const subtreeMap = {
    [`container:${SEC}`]: { variants: [{ variant: 'hero', fragment: '' }], default: { padding: '0' } },
    [`block:${T2}`]: { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
    [`block:${T1}`]: { variants: [{ variant: 'card', fragment: '' }] },
    [`block:${IMG}`]: { variants: [{ variant: 'hero', fragment: 'container(1)/block(image,1)' }] },
  };
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    'block(text,1)': { variants: [{ variant: 'card', fragment: '' }] },
    'block(text,2)': { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
  });
});

test('capture strips the origin a distributed wear carries', () => {
  // THE card scenario: Card Left's apply nested "row" onto the card
  // wrapper (a nested container), stamped "via card_left". A capture from
  // the section writes the child's root references as {variant,
  // fragment} ONLY: a variant's nested list says what to distribute,
  // never who did, and the server's variant-styles lane rejects "via", so
  // an unstripped capture would refuse to save. The composition's own
  // fragment reference on the wrapper never travels, as ever. The map
  // is left as it was: the strip is the capture's, not the map's.
  const subtreeMap = {
    [`container:${SEC}`]: { variants: [{ variant: 'card_left', fragment: '' }], default: { padding: '0' } },
    [`container:${NSEC}`]: {
      variants: [
        { variant: 'row', fragment: '', via: 'card_left' },
        { variant: 'card_left', fragment: 'container(1)' },
      ],
      default: { margin: '1px' },
    },
    [`block:${T1}`]: { variants: [{ variant: 'card', fragment: '', via: 'card_left' }] },
  };
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    'block(text,1)': { variants: [{ variant: 'card', fragment: '' }] },
    'container(1)': { variants: [{ variant: 'row', fragment: '' }], default: { margin: '1px' } },
  });
  // The source walk strips too (shadow clearing reads the same buckets):
  // shallow-first, map order among equals, so the wrapper precedes the
  // block here.
  assert.deepEqual(captureSources(subtreeMap, `container:${SEC}`, layout).direct.map(({ entry }) => entry.variants), [
    [{ variant: 'row', fragment: '' }],
    [{ variant: 'card', fragment: '' }],
  ]);
  assert.deepEqual(subtreeMap[`container:${NSEC}`].variants[0], { variant: 'row', fragment: '', via: 'card_left' });
});

test('capture excludes instance parts (identity classes never travel)', () => {
  // Instance styles are instance-bound: minted ids differ per content
  // instance, so save/push leave '::.s-xx' entries behind in BOTH capture
  // buckets (the element's own parts and translated children), mirroring
  // the server's STYLE_INSTANCE_PART_IN_VARIANT gate.
  const map = {
    [`block:${T2}`]: { default: { margin: '0' } },
    [`block:${T2}::.quote`]: { default: { 'font-style': 'italic' } },
    [`block:${T2}::.s-a3`]: { default: { color: 'red' } },
    [`block:${T2}:field_body::.s-b4`]: { default: { color: 'blue' } },
    [`container:${SEC}`]: { default: { padding: '0' } },
  };
  assert.deepEqual(captureEntries(map, `block:${T2}`, layout), {
    '': { default: { margin: '0' } },
    '::.quote': { default: { 'font-style': 'italic' } },
  });
  assert.deepEqual(captureEntries(map, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    'block(text,2)': { default: { margin: '0' } },
    'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
  });
  // The exclusion is the capture walk's, not elementEntries': instance
  // styles are still the element's own custom styles (clipboard, styled
  // indicators stay honest).
  assert.deepEqual(elementEntries(map, `block:${T2}`)['::.s-a3'], { default: { color: 'red' } });
  assert.equal(hasCustomStyles({ [`block:${T2}::.s-a3`]: { default: { color: 'red' } } }, `block:${T2}`), true);
});

test('capture excludes tag-tier entries (host maps only)', () => {
  // Tag tiers live in host maps only: the server's SlotKey charset
  // rejects a dotless part in variant styles, so the capture walk leaves
  // '::p' entries behind in BOTH buckets or the save itself would be
  // rejected. Tag children ('::ul::>') ride the same exclusion; the '::>'
  // children pseudo-part itself still travels.
  const map = {
    [`block:${T2}`]: { default: { margin: '0' } },
    [`block:${T2}::p`]: { default: { 'font-size': '1.25rem' } },
    [`block:${T2}::ul::>`]: { default: { color: 'red' } },
    [`block:${T2}::>`]: { default: { gap: '1rem' } },
    [`container:${SEC}`]: { default: { padding: '0' } },
  };
  assert.deepEqual(captureEntries(map, `block:${T2}`, layout), {
    '': { default: { margin: '0' } },
    '::>': { default: { gap: '1rem' } },
  });
  assert.deepEqual(captureEntries(map, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    'block(text,2)': { default: { margin: '0' } },
    'block(text,2)::>': { default: { gap: '1rem' } },
  });
  // Still the element's own custom styles (clipboard, styled indicators).
  assert.equal(hasCustomStyles({ [`block:${T2}::p`]: { default: { color: 'red' } } }, `block:${T2}`), true);
});

test('saveStyles folds the replaced same-axis values beneath overrides', () => {
  const hero = {
    id: 'hero',
    group: 'style',
    value: {
      '': { default: { padding: '4rem', 'background-color': 'red' } },
      '::.inner': { default: { margin: '0' } },
      'block(text,2)': { default: { color: 'red', margin: '1rem' } },
      'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    },
  };
  const card = {
    id: 'card',
    group: 'style',
    value: { '': { default: { margin: '2rem', border: '1px solid black' } }, '::.badge': { default: { color: 'gold' } } },
  };
  const wide = { id: 'wide', group: 'width', value: { '': { default: { 'max-width': '60rem' } } } };
  const glow = { id: 'glow', group: 'width', value: { '': { default: { color: 'blue' } } } };
  const library = [hero, card, wide, glow];
  const saveMap = {
    // The root wears hero (style) and wide (width); its padding override
    // beats hero's.
    [`container:${SEC}`]: { variants: [{ variant: 'hero', fragment: '' }, { variant: 'wide', fragment: '' }], default: { padding: '0' } },
    // A carrier of hero's distributed fragment, NO overrides: the group
    // cleanup strips it on re-attach, so it folds anyway.
    [`block:${T2}`]: { variants: [{ variant: 'hero', fragment: 'block(text,2)' }] },
    // A captured child directly wearing another same-axis variant: refs
    // stay refs: the reference rides the captured fragment (distribution
    // re-places it as the pair), so card's values do NOT fold.
    [`block:${T1}`]: { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
    // A child wearing a CROSS-AXIS variant: exempt from folding, and its
    // reference travels beside its override.
    [`block:${IMG}`]: { variants: [{ variant: 'glow', fragment: '' }], default: { opacity: '0.5' } },
    // A child wearing only its own same-axis ROOT variant: the wearing
    // travels as a reference (it survives the re-attach as the pair).
    [`container:${NSEC}`]: { variants: [{ variant: 'card', fragment: '' }] },
  };
  const flattened = saveStyles(saveMap, `container:${SEC}`, layout, library, 'style');
  assert.deepEqual(flattened, {
    '': { default: { padding: '0', 'background-color': 'red' } },
    '::.inner': { default: { margin: '0' } },
    'block(text,2)': { default: { color: 'red', margin: '1rem' } },
    'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    'block(text,1)': { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
    'container(1)': { variants: [{ variant: 'card', fragment: '' }] },
    'container(1)/block(image,1)': { variants: [{ variant: 'glow', fragment: '' }], default: { opacity: '0.5' } },
  });
  // Saving on the OTHER axis flips the folding: wide folds beneath the
  // root, hero (now cross-axis) does not fold anywhere and T2's hero
  // fragment is not stripped by a width replacement, while glow (now the
  // save's own axis, but a ROOT wearing) still rides as a reference
  // (only the capture ROOT's saved axis folds; children's refs stay refs).
  assert.deepEqual(saveStyles(saveMap, `container:${SEC}`, layout, library, 'width'), {
    '': { default: { padding: '0', 'max-width': '60rem' } },
    'block(text,1)': { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
    'container(1)': { variants: [{ variant: 'card', fragment: '' }] },
    'container(1)/block(image,1)': { variants: [{ variant: 'glow', fragment: '' }], default: { opacity: '0.5' } },
  });
  // Flattened values have no override on the element, so the re-attach's
  // shadow clearing proposes nothing for them (unsets retire real
  // overrides only — the provenance walk reads the map, not the payload).
  const minted = { id: 'minted', group: 'style', value: flattened };
  const clearing = shadowClearingOps(saveMap, `container:${SEC}`, layout, minted, [...library, minted]);
  assert.equal(clearing.some((op) => op.key === `block:${T2}`), false);
  assert.deepEqual(clearing, [
    { op: 'update_style', key: `block:${T1}`, properties: { margin: null } },
    { op: 'update_style', key: `block:${IMG}`, properties: { opacity: null } },
    { op: 'update_style', key: `container:${SEC}`, properties: { padding: null } },
  ]);
});

test('saveStyles resolves the root wearing through its own fragment', () => {
  const hero = {
    id: 'hero',
    group: 'style',
    value: {
      '': { default: { padding: '4rem' } },
      'block(text,2)': { default: { color: 'red', margin: '1rem' } },
      'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    },
  };
  // Saving from a composition CHILD: its fragment wearing is replaced by
  // the direct apply, so exactly the fragment's suffix space folds.
  const fragMap = {
    [`block:${T2}`]: { variants: [{ variant: 'hero', fragment: 'block(text,2)' }], default: { color: 'blue' } },
  };
  assert.deepEqual(saveStyles(fragMap, `block:${T2}`, layout, [hero], 'style'), {
    '': { default: { color: 'blue', margin: '1rem' } },
    '::.quote': { default: { 'font-style': 'italic' } },
  });
  // No same-axis wearing anywhere, an empty library, or a stale
  // reference: the payload IS the overrides-only capture.
  assert.deepEqual(saveStyles(fragMap, `block:${T2}`, layout, [], 'style'), captureEntries(fragMap, `block:${T2}`, layout));
  assert.deepEqual(saveStyles(fragMap, `block:${T2}`, layout, [hero], 'width'), captureEntries(fragMap, `block:${T2}`, layout));
  // A worn same-axis PAIR at the capture root folds wholesale, in delivery
  // order: the nested root wearing's values slot BENEATH the fragment's
  // (roots deliver before fragments within an axis), captured overrides on
  // top, whatever the stored list order says.
  const card = {
    id: 'card',
    group: 'style',
    value: { '': { default: { margin: '2rem', border: '1px solid black' } }, '::.badge': { default: { color: 'gold' } } },
  };
  const pairMap = {
    [`block:${T2}`]: { variants: [{ variant: 'hero', fragment: 'block(text,2)' }, { variant: 'card', fragment: '' }], default: { color: 'blue' } },
  };
  assert.deepEqual(saveStyles(pairMap, `block:${T2}`, layout, [hero, card], 'style'), {
    '': { default: { color: 'blue', margin: '1rem', border: '1px solid black' } },
    '::.quote': { default: { 'font-style': 'italic' } },
    '::.badge': { default: { color: 'gold' } },
  });
});

test('pushStyles: attached merges over the variant, unattached replaces', () => {
  const variant = {
    id: 'hero',
    value: {
      '': { default: { padding: '2rem', color: 'blue' } },
      'container(1)': { default: { display: 'flex' } },
    },
  };
  const captured = { '': { default: { padding: '3rem' } }, '::.quote': { default: { margin: '0' } } };
  // Attached: the overrides-only capture merges OVER the variant's current
  // styles — untouched properties and untouched relative keys survive.
  assert.deepEqual(pushStyles(variant, { variants: [{ variant: 'hero', fragment: '' }] }, captured), {
    '': { default: { padding: '3rem', color: 'blue' } },
    'container(1)': { default: { display: 'flex' } },
    '::.quote': { default: { margin: '0' } },
  });
  // A uuid reference does not attach (the reference semantics): the
  // push replaces wholesale, like an unattached element's.
  assert.deepEqual(pushStyles(variant, { variants: [{ variant: UUID_A, fragment: '' }] }, captured), captured);
  // Unattached: the historic replacement semantics (which is also what
  // makes detach-edit-push remove a property from a variant).
  assert.deepEqual(pushStyles(variant, { default: { padding: '9rem' } }, captured), captured);
  assert.deepEqual(pushStyles(variant, null, captured), captured);
  // Captured child references ride the push: the fragment stays
  // entry-shaped, the captured "variants" list replacing the variant's
  // while untouched values survive beside it.
  const withRefs = { ...captured, 'container(1)': { variants: [{ variant: 'card', fragment: '' }] } };
  assert.deepEqual(pushStyles(variant, { variants: [{ variant: 'hero', fragment: '' }] }, withRefs)['container(1)'], {
    default: { display: 'flex' },
    variants: [{ variant: 'card', fragment: '' }],
  });
});

test('shadowClearingOps retires exactly the redundant overrides', () => {
  const hero = {
    id: 'hero',
    group: 'style',
    value: {
      '': { default: { padding: '0', gap: '1rem', 'row-gap': '8px' } },
      'block(text,2)': { default: { margin: '0', color: 'blue' } },
    },
  };
  const wide = { id: 'wide', group: 'width', value: { '': { default: { padding: '0' } } } };
  const card = { id: 'card', group: 'style', value: { '': { default: { margin: '0' } } } };
  const glow = { id: 'glow', group: 'width', value: { '': { default: { color: 'blue' } } } };
  const library = [hero, wide, card, glow];
  const clearMap = {
    // The root wears an other-axis reference whose styles overlap on
    // padding; its overrides: padding (redundant but contested), gap
    // (differing value — a live override), row-gap (redundant).
    [`container:${SEC}`]: { variants: [{ variant: 'wide', fragment: '' }], default: { padding: '0', gap: '2rem', 'row-gap': '8px' } },
    // A child on its own variants, unsets on the CONCRETE child key: color
    // is contested by the child's CROSS-AXIS reference and stays; margin
    // overlaps only the child's SAME-AXIS reference, which the re-attach's
    // distribution replaces (the unset is only proposed because hero holds
    // this child's slot path), so it clears.
    [`block:${T2}`]: { variants: [{ variant: 'card', fragment: '' }, { variant: 'glow', fragment: '' }], default: { margin: '0', color: 'blue' } },
  };
  assert.deepEqual(shadowClearingOps(clearMap, `container:${SEC}`, layout, hero, library), [
    { op: 'update_style', key: `block:${T2}`, properties: { margin: null } },
    { op: 'update_style', key: `container:${SEC}`, properties: { 'row-gap': null } },
  ]);
  // A same-axis root reference does not contest: the re-attach replaces
  // it, so its padding overlap no longer shields the override.
  const swapMap = { [`container:${SEC}`]: { variants: [{ variant: 'other_style', fragment: '' }], default: { padding: '0' } } };
  const otherStyle = { id: 'other_style', group: 'style', value: { '': { default: { padding: '0' } } } };
  assert.deepEqual(shadowClearingOps(swapMap, `container:${SEC}`, layout, hero, [hero, otherStyle]), [
    { op: 'update_style', key: `container:${SEC}`, properties: { padding: null } },
  ]);
  // Nothing captured, nothing covered, or nothing equal: no ops.
  assert.deepEqual(shadowClearingOps({}, `container:${SEC}`, layout, hero, library), []);
  assert.deepEqual(shadowClearingOps({ [`container:${SEC}`]: { default: { 'min-height': '4rem' } } }, `container:${SEC}`, layout, hero, library), []);
});

test('variantFit counts SLOTS (addressed elements), not style keys', () => {
  // A slot is a distinct base path: '' and its parts are ONE slot (the
  // root element), a child key and its part keys are that child's one
  // slot. Slot paths resolve when the layout has the addressed element;
  // a trailing field segment rides its block. Here: 7 style keys but 5
  // slots — root (with its part), container(1), block(text,2) (with its
  // part), the field, and the nested image block.
  assert.deepEqual(variantFit({
    '': { default: { padding: '0' } },
    '::.daedalus-stylish-layout-container': { default: { gap: '1rem' } },
    'container(1)': { default: { display: 'flex' } },
    'block(text,2)': { default: { margin: '0' } },
    'block(text,2)::.quote': { default: { color: 'red' } },
    'block(text,2)/field(field_body)': { default: { color: 'blue' } },
    'container(1)/block(image,1)': { default: { padding: '1px' } },
  }, `container:${SEC}`, layout), { resolved: 5, total: 5 });
  // Partial: a third text block and a container the layout lacks are
  // inert; a top-level image block does not exist either.
  assert.deepEqual(variantFit({
    '': { default: { padding: '0' } },
    'block(text,2)': { default: { margin: '0' } },
    'block(text,3)': { default: { margin: '0' } },
    'container(9)': { default: { display: 'flex' } },
    'block(image,1)': { default: { display: 'grid' } },
  }, `container:${SEC}`, layout), { resolved: 2, total: 5 });
  // A plain block target resolves its own field slots (delivery applies a
  // field slot whenever the block exists), not composition paths.
  assert.deepEqual(variantFit({
    '': { default: { margin: '0' } },
    'field(field_body)': { default: { color: 'blue' } },
  }, `block:${T2}`, layout), { resolved: 2, total: 2 });
  // Part-only richness never inflates the count: many keys, one element,
  // one slot — the "freshly saved reads partial" display bug's math half.
  assert.deepEqual(variantFit({
    '': { default: { padding: '0' } },
    '::.inner': { default: { margin: '0' } },
    '::.badge': { default: { color: 'red' } },
  }, `block:${T2}`, layout), { resolved: 1, total: 1 });
  // Without a layout tree, only the root element's slot resolves.
  assert.deepEqual(variantFit({
    '': { default: { padding: '0' } },
    '::.inner': { default: { margin: '0' } },
    'container(1)': { default: { display: 'flex' } },
  }, `container:${SEC}`, []), { resolved: 1, total: 2 });
  // No styles is a full fit of zero.
  assert.deepEqual(variantFit({}, `container:${SEC}`, layout), { resolved: 0, total: 0 });
});

test('variantFit: a fresh capture from the element is always a full fit', () => {
  // The save→rank round-trip: what captureEntries mints from this exact
  // element resolves completely against the same element and layout —
  // the picker may never badge a variant saved right here as partial.
  const map = {
    [`container:${SEC}`]: { default: { padding: '2rem' } },
    [`container:${SEC}::.daedalus-stylish-layout-container`]: { default: { gap: '1rem' } },
    [`block:${T1}`]: { default: { margin: '0' } },
    [`block:${T2}::.quote`]: { default: { color: 'red' } },
    [`block:${T2}:field_body`]: { default: { 'font-size': '1.2rem' } },
    [`container:${NSEC}`]: { default: { display: 'grid' } },
    [`block:${IMG}`]: { default: { 'border-radius': '8px' } },
  };
  const styles = captureEntries(map, `container:${SEC}`, layout);
  const fit = variantFit(styles, `container:${SEC}`, layout);
  assert.equal(fit.resolved, fit.total);
  assert.equal(fit.total > 1, true);
});

test('rankVariants: full resolves first, partials by fit, ties stable', () => {
  const variants = [
    { id: 'partial_low', scope: 'kind:container', value: { '': {}, 'container(8)': {}, 'container(9)': {} } },
    { id: 'full_a', scope: 'kind:container', value: { '': {}, 'container(1)': {} } },
    { id: 'partial_high', scope: 'kind:container', value: { '': {}, 'container(2)': {}, 'container(9)': {} } },
    { id: 'full_b', scope: 'kind:container', value: { '': {} } },
  ];
  const ranked = rankVariants(variants, `container:${SEC}`, layout);
  assert.deepEqual(ranked.map((entry) => entry.variant.id), ['full_a', 'full_b', 'partial_high', 'partial_low']);
  assert.deepEqual(ranked.map((entry) => [entry.resolved, entry.total]), [[2, 2], [1, 1], [2, 3], [1, 3]]);
  assert.deepEqual(rankVariants(null, `container:${SEC}`, layout), []);
});

test('rankVariants slot units survive part-heavy variants', () => {
  // Slot counting keeps a parts-rich single-element variant a FULL fit
  // even though it holds many style keys.
  const variants = [
    { id: 'parts_rich', scope: 'kind:container', value: { '': {}, '::.a': {}, '::.b': {}, 'container(9)': {} } },
  ];
  const [ranked] = rankVariants(variants, `container:${SEC}`, layout);
  assert.deepEqual([ranked.resolved, ranked.total], [1, 2]);
});

test('layoutTree pares the blueprint to what the walk needs', () => {
  const blueprint = {
    fields: { daedalus_stylish_styles: {} },
    layout_builder__layout: [
      {
        uuid: SEC,
        layout_id: 'layout_twocol_section',
        extra: 'noise',
        regions: {
          first: [
            {
              uuid: NSEC,
              layout_id: 'layout_onecol',
              label: 'Hero band',
              layout_settings: { label: 'Hero band' },
              regions: { content: [{ uuid: IMG, block_type: 'image', fields: { huge: 'payload' } }] },
            },
            { uuid: PLUGIN },
          ],
        },
      },
    ],
  };
  const tree = layoutTree(blueprint);
  assert.deepEqual(tree, [
    {
      uuid: SEC,
      layout_id: 'layout_twocol_section',
      regions: {
        first: [
          {
            uuid: NSEC,
            layout_id: 'layout_onecol',
            regions: { content: [{ uuid: IMG, block_type: 'image' }] },
          },
          { uuid: PLUGIN, block_type: null },
        ],
      },
    },
  ]);
  // The pared tree drives the same walk the raw blueprint shape does.
  const viaTree = subtreeSlotTable(`container:${SEC}`, tree);
  const viaRaw = subtreeSlotTable(`container:${SEC}`, blueprint.layout_builder__layout);
  assert.deepEqual(viaTree, viaRaw);
});

test('isVariantRef recognizes references only', () => {
  assert.equal(isVariantRef({ variants: [{ variant: 'x', fragment: '' }] }), true);
  // Layered entry: references coexist with condition overrides.
  assert.equal(isVariantRef({ variants: [{ variant: 'x', fragment: '' }], default: { padding: '1rem' } }), true);
  assert.equal(isVariantRef({ default: {} }), false);
  // The dead single-reference grammar is not a reference.
  assert.equal(isVariantRef({ variant: 'x' }), false);
  assert.equal(isVariantRef({ variants: [] }), false);
  assert.equal(isVariantRef(null), false);
  assert.equal(isVariantRef([]), false);
});

test('orderedEntryVariants sorts by axis position, unplaceable last', () => {
  const library = [
    // Machine-name order OPPOSES axis order (the fixture's trick), so
    // every assertion proves the axis sort did the work.
    { id: 'aa_pop', group: 'emphasis', value: {} },
    { id: 'zz_calm', group: 'style', value: {} },
    { id: 'mm_wide', group: 'width', value: {} },
  ];
  const axes = ['style', 'emphasis', 'width'];
  const entry = { variants: [{ variant: 'aa_pop', fragment: '' }, { variant: 'mm_wide', fragment: '' }, { variant: 'zz_calm', fragment: '' }] };
  assert.deepEqual(orderedEntryVariants(entry, library, axes), ['zz_calm', 'aa_pop', 'mm_wide']);
  // Unplaceable references (stale in the library, or axis outside the
  // vocabulary) sort last in stored-list order.
  assert.deepEqual(
    orderedEntryVariants({ variants: [{ variant: 'ghost', fragment: '' }, { variant: 'aa_pop', fragment: '' }, { variant: 'zz_calm', fragment: '' }] }, library, axes),
    ['zz_calm', 'aa_pop', 'ghost'],
  );
  assert.deepEqual(
    orderedEntryVariants({ variants: [{ variant: 'mm_wide', fragment: '' }, { variant: 'zz_calm', fragment: '' }] }, library, ['style']),
    ['zz_calm', 'mm_wide'],
  );
  // No vocabulary, or fewer than two references: stored order passes.
  assert.deepEqual(orderedEntryVariants(entry, library, null), ['aa_pop', 'mm_wide', 'zz_calm']);
  assert.deepEqual(orderedEntryVariants(entry, library, []), ['aa_pop', 'mm_wide', 'zz_calm']);
  assert.deepEqual(orderedEntryVariants({ variants: [{ variant: 'aa_pop', fragment: '' }] }, library, axes), ['aa_pop']);
  assert.deepEqual(orderedEntryVariants(null, library, axes), []);
});

test('orderedEntryRefs orders roots before fragments within an axis', () => {
  // The composition pair: a fragment reference and the root references
  // nested within its fragment share an axis; the nested root delivers
  // UNDER the fragment (its values are the captured overrides that
  // already beat it), regardless of stored list order — the server's
  // structural tiebreak, mirrored.
  const library = [
    { id: 'cta', group: 'style', value: {} },
    { id: 'card_left', group: 'style', value: {} },
  ];
  const axes = ['style'];
  assert.deepEqual(
    orderedEntryRefs({ variants: [{ variant: 'cta', fragment: 'block(text,1)' }, { variant: 'card_left', fragment: '' }] }, library, axes),
    [{ variant: 'card_left', fragment: '', via: null }, { variant: 'cta', fragment: 'block(text,1)', via: null }],
  );
  // Already root-first: stable.
  assert.deepEqual(
    orderedEntryRefs({ variants: [{ variant: 'card_left', fragment: '' }, { variant: 'cta', fragment: 'block(text,1)' }] }, library, axes),
    [{ variant: 'card_left', fragment: '', via: null }, { variant: 'cta', fragment: 'block(text,1)', via: null }],
  );
});

// A served root's axes as the roster carries them: axis => its label and
// depth stamp (ClientStyleGuide serves VocabularyRoster::axes()).
const axesOf = (names, rootOnly = []) => Object.fromEntries(names.map((axis) => [axis, { label: null, root_only: rootOnly.includes(axis), description: null, owns: [] }]));

test('axisVocabulary reads the served roster, style-first', () => {
  const guide = {
    kinds: { section: {}, block: {} },
    layouts: { layout_twocol_section: { axes: axesOf(['style', 'proportions', 'width']) } },
    blockTypes: { testimonial: { axes: axesOf(['style', 'emphasis']) } },
  };
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_A}`, blockTypes, sectionLayouts), ['style', 'proportions', 'width']);
  // Sub-element keys share their parent scope's vocabulary.
  assert.deepEqual(
    axisVocabulary(guide, `container:${UUID_A}::.daedalus-stylish-layout-container`, blockTypes, sectionLayouts),
    ['style', 'proportions', 'width'],
  );
  assert.deepEqual(axisVocabulary(guide, `block:${UUID_A}`, blockTypes, sectionLayouts), ['style', 'emphasis']);
  // Unresolved bundle/layout, undeclared axes, or an unparseable key: the
  // universal single-axis vocabulary.
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_A}`, blockTypes, {}), ['style']);
  assert.deepEqual(axisVocabulary(guide, `block:${UUID_B}`, blockTypes, sectionLayouts), ['style']);
  assert.deepEqual(axisVocabulary(guide, 'bogus:123'), ['style']);
});

test('axisDescription and axisOwns read the served axis facts, null and [] otherwise', () => {
  const guide = {
    kinds: { section: {}, block: {} },
    layouts: {
      layout_twocol_section: {
        axes: {
          style: { label: null, root_only: false, description: null, owns: [] },
          width: { label: 'Width', root_only: true, description: 'The band\'s content column.', owns: ['padding-left', 'padding-right'] },
        },
      },
    },
    blockTypes: { testimonial: { axes: axesOf(['style', 'emphasis']) } },
  };
  assert.equal(axisDescription(guide, `container:${UUID_A}`, 'width', blockTypes, sectionLayouts), 'The band\'s content column.');
  assert.deepEqual(axisOwns(guide, `container:${UUID_A}`, 'width', blockTypes, sectionLayouts), ['padding-left', 'padding-right']);
  // Sub-element keys read their root's axes.
  assert.deepEqual(axisOwns(guide, `container:${UUID_A}::.daedalus-stylish-layout-container`, 'width', blockTypes, sectionLayouts), ['padding-left', 'padding-right']);
  // An undescribed axis, a served entry without the keys, an unknown axis
  // and an unparseable key all answer the empty facts.
  assert.equal(axisDescription(guide, `container:${UUID_A}`, 'style', blockTypes, sectionLayouts), null);
  assert.deepEqual(axisOwns(guide, `container:${UUID_A}`, 'style', blockTypes, sectionLayouts), []);
  assert.equal(axisDescription(guide, `block:${UUID_A}`, 'emphasis', blockTypes, sectionLayouts), null);
  assert.deepEqual(axisOwns(guide, `block:${UUID_A}`, 'emphasis', blockTypes, sectionLayouts), []);
  assert.equal(axisDescription(guide, `container:${UUID_A}`, 'nope', blockTypes, sectionLayouts), null);
  assert.equal(axisDescription(guide, 'bogus:123', 'width'), null);
  assert.deepEqual(axisOwns(guide, 'bogus:123', 'width'), []);
});

test('axisLabel serves declared labels, else the capitalized machine name', () => {
  const guide = { axisLabels: { container_layout: 'Layout' } };
  // Declared label wins over the derived form.
  assert.equal(axisLabel(guide, 'container_layout'), 'Layout');
  // Derived: capitalize, underscores as spaces.
  assert.equal(axisLabel(guide, 'style'), 'Style');
  assert.equal(axisLabel(guide, 'my_axis'), 'My axis');
  // No guide (satellite pre-snapshot): the derived form still serves.
  assert.equal(axisLabel(null, 'container_layout'), 'Container layout');
});

test('axisVocabulary drops root-only axes at nested depth', () => {
  const UUID_C = 'cccccccc-1111-2222-3333-444444444444';
  const UUID_D = 'dddddddd-1111-2222-3333-444444444444';
  const guide = {
    kinds: { section: {} },
    layouts: {
      layout_twocol_section: { axes: axesOf(['style', 'proportions', 'width'], ['width']) },
      layout_onecol: { axes: axesOf(['style', 'width']) },
    },
  };
  const layouts = {
    [UUID_A]: 'layout_twocol_section',
    [UUID_C]: 'layout_twocol_section',
    [UUID_D]: 'layout_twocol_section',
  };
  // A pared layoutTree() shape: UUID_C nested in UUID_A, UUID_D in UUID_C.
  const tree = [{
    uuid: UUID_A,
    layout_id: 'layout_twocol_section',
    regions: {
      first: [{
        uuid: UUID_C,
        layout_id: 'layout_twocol_section',
        regions: { first: [{ uuid: UUID_D, layout_id: 'layout_twocol_section', regions: {} }] },
      }],
    },
  }];
  // Top-level: the full vocabulary, tree present or not.
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_A}`, {}, layouts, tree), ['style', 'proportions', 'width']);
  // Nested (any depth): root-only axes drop from what is OFFERED, and
  // sub-element keys share the filtered vocabulary.
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_C}`, {}, layouts, tree), ['style', 'proportions']);
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_D}`, {}, layouts, tree), ['style', 'proportions']);
  assert.deepEqual(
    axisVocabulary(guide, `container:${UUID_C}::.daedalus-stylish-layout-container`, {}, layouts, tree),
    ['style', 'proportions'],
  );
  // Unknown placement degrades to the ungated root posture (the server's
  // isNestedSection mirror): no tree, or a uuid the tree does not carry.
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_C}`, {}, layouts), ['style', 'proportions', 'width']);
  assert.deepEqual(
    axisVocabulary(guide, `container:${UUID_B}`, {}, { [UUID_B]: 'layout_twocol_section' }, tree),
    ['style', 'proportions', 'width'],
  );
  // A declaration with no root-only axes is untouched at nested depth.
  assert.deepEqual(
    axisVocabulary(guide, `container:${UUID_C}`, {}, { [UUID_C]: 'layout_onecol' }, tree),
    ['style', 'width'],
  );
});

test('effective resolution follows delivery order when axes are passed', () => {
  const library = [
    { id: 'aa_pop', group: 'emphasis', value: { '': { default: { color: 'red', padding: '1rem' } }, '::.quote': { default: { color: 'red' } } } },
    { id: 'zz_calm', group: 'style', value: { '': { default: { color: 'blue' } } } },
  ];
  const axes = ['style', 'emphasis'];
  // Stored so the two orders DISAGREE: list order ends on zz_calm (style),
  // delivery order ends on aa_pop (emphasis sorts after style).
  const flipped = { [KEY]: { variants: [{ variant: 'aa_pop', fragment: '' }, { variant: 'zz_calm', fragment: '' }] } };
  const refMap = { [KEY]: { variants: [{ variant: 'zz_calm', fragment: '' }, { variant: 'aa_pop', fragment: '' }] } };
  // List order: the later reference (zz_calm, style) wins the color.
  assert.equal(effectiveEntry(flipped, KEY, library).entry.default.color, 'blue');
  // Delivery order: emphasis sorts after style, so aa_pop wins, exactly
  // what the compiled/edit-mode CSS shows.
  assert.equal(effectiveEntry(flipped, KEY, library, axes).entry.default.color, 'red');
  assert.equal(effectiveEntry(flipped, KEY, library, axes).entry.default.padding, '1rem');
  // The provenance reference is the delivery-dominant layer.
  assert.equal(effectiveEntry(flipped, KEY, library, axes).reference, 'aa_pop');
  assert.equal(effectiveEntry(refMap, KEY, library, axes).reference, 'aa_pop');
  // effectiveElementMap speaks the same order.
  assert.equal(effectiveElementMap(flipped, KEY, library, axes)[KEY].default.color, 'red');
  assert.equal(effectiveElementMap(flipped, KEY, library)[KEY].default.color, 'blue');
});

test('shadowedProperties flags own tuples beating a worn variant', () => {
  const library = [
    { id: 'aa_pop', group: 'emphasis', value: { '': { default: { padding: '1rem' } } } },
    { id: 'zz_calm', group: 'style', value: { '': { default: { padding: '2rem', color: 'blue' }, hover: { color: 'navy' } }, '::.quote': { default: { 'font-style': 'italic' } } } },
  ];
  const axes = ['style', 'emphasis'];
  const shadowMap = {
    [KEY]: { variants: [{ variant: 'zz_calm', fragment: '' }, { variant: 'aa_pop', fragment: '' }], default: { padding: '3rem', margin: '0' }, hover: { color: 'black' } },
    [`${KEY}::.quote`]: { default: { 'font-style': 'normal', color: 'red' } },
  };
  // padding is defined by BOTH variants: the delivery winner (emphasis
  // after style) is reported; margin is nobody's.
  assert.deepEqual(shadowedProperties(shadowMap, KEY, library, axes), {
    default: { padding: 'aa_pop' },
    hover: { color: 'zz_calm' },
  });
  // Without the vocabulary the stored-list winner is reported instead.
  assert.deepEqual(shadowedProperties(shadowMap, KEY, library).default, { padding: 'aa_pop' });
  // A part key resolves through the ROOT's references at its own relative.
  assert.deepEqual(shadowedProperties(shadowMap, `${KEY}::.quote`, library, axes), {
    default: { 'font-style': 'zz_calm' },
  });
  // No references, no own entry, or nothing overlapping: {}.
  assert.deepEqual(shadowedProperties({ [KEY]: { default: { padding: '1rem' } } }, KEY, library, axes), {});
  assert.deepEqual(shadowedProperties({ [KEY]: { variants: [{ variant: 'zz_calm', fragment: '' }] } }, KEY, library, axes), {});
  assert.deepEqual(shadowedProperties({ [KEY]: { variants: [{ variant: 'zz_calm', fragment: '' }], default: { margin: '0' } } }, KEY, library, axes), {});
  assert.deepEqual(shadowedProperties(shadowMap, null, library, axes), {});
});

test('shadowedProperties matches on the longhand grain: an own padding beats a worn padding-inline', () => {
  const library = [
    { id: 'site', group: 'width', value: { '': { default: { 'padding-inline': 'var(--inset)' } } } },
  ];
  const worn = (own) => ({ [KEY]: { variants: [{ variant: 'site', fragment: '' }], default: own } });
  // The shorthand addresses all four sides, the inset the inline pair:
  // they meet, and the own property is the reported key.
  assert.deepEqual(shadowedProperties(worn({ padding: '3rem' }), KEY, library), { default: { padding: 'site' } });
  // A side outside the inset's grain shadows nothing; one inside it does.
  assert.deepEqual(shadowedProperties(worn({ 'padding-top': '3rem', 'padding-block': '1rem' }), KEY, library), {});
  assert.deepEqual(shadowedProperties(worn({ 'padding-left': '0' }), KEY, library), { default: { 'padding-left': 'site' } });
  // Outside the box families the match stays name-exact.
  assert.deepEqual(shadowedProperties(worn({ 'padding-inline': '0', color: 'red' }), KEY, library), { default: { 'padding-inline': 'site' } });
});

test('propertySuppliers reports the delivery-winning variant per tuple', () => {
  const library = [
    { id: 'aa_pop', group: 'emphasis', value: { '': { default: { padding: '1rem' } } } },
    { id: 'zz_calm', group: 'style', value: { '': { variants: [{ variant: 'aa_pop', fragment: '' }], default: { padding: '2rem', color: 'blue' }, hover: { color: 'navy' } }, '::.quote': { default: { 'font-style': 'italic' } } } },
  ];
  const axes = ['style', 'emphasis'];
  const map = {
    [KEY]: { variants: [{ variant: 'zz_calm', fragment: '' }, { variant: 'aa_pop', fragment: '' }], default: { padding: '3rem', margin: '0' } },
  };
  // Every variant-defined tuple is reported — shadowed by the own entry or
  // not (padding: the own 3rem shadows it, still attributed) — with the
  // delivery winner per property (emphasis sorts after style, so aa_pop
  // takes padding). A fragment entry's reserved `variants` list is not a
  // condition and never leaks in as one.
  assert.deepEqual(propertySuppliers(map, KEY, library, axes), {
    default: { padding: 'aa_pop', color: 'zz_calm' },
    hover: { color: 'zz_calm' },
  });
  // Without the vocabulary the stored list order decides.
  assert.deepEqual(propertySuppliers(map, KEY, library).default, { padding: 'aa_pop', color: 'zz_calm' });
  // A part key resolves through the ROOT's references at its own relative.
  assert.deepEqual(propertySuppliers(map, `${KEY}::.quote`, library, axes), {
    default: { 'font-style': 'zz_calm' },
  });
  // No references (or no key): {}.
  assert.deepEqual(propertySuppliers({ [KEY]: { default: { padding: '1rem' } } }, KEY, library, axes), {});
  assert.deepEqual(propertySuppliers(map, null, library, axes), {});
  // A distributed fragment reference supplies through ITS OWN fragment.
  const hero = { id: 'hero', group: 'style', value: { 'block(text,2)': { default: { color: 'red' } } } };
  const fragMap = { [`block:${T2}`]: { variants: [{ variant: 'hero', fragment: 'block(text,2)' }] } };
  assert.deepEqual(propertySuppliers(fragMap, `block:${T2}`, [hero], axes), { default: { color: 'hero' } });
});

test('entryVariants mirrors the server read semantics', () => {
  assert.deepEqual(entryVariants({ variants: [{ variant: 'a', fragment: '' }, { variant: 'b', fragment: '' }] }), ['a', 'b']);
  // Malformed items (the retired bare-string form included) are
  // ignored, order kept.
  assert.deepEqual(entryVariants({ variants: [{ variant: 'a', fragment: '' }, 'b', '', 5] }), ['a']);
  assert.deepEqual(entryVariants({ variants: 'a' }), []);
  assert.deepEqual(entryVariants({ default: { color: 'red' } }), []);
  assert.deepEqual(entryVariants(null), []);
  // A fragment reference counts as wearing its variant like a root one.
  assert.deepEqual(entryVariants({ variants: [{ variant: 'a', fragment: '' }, { variant: 'b', fragment: 'container(1)' }] }), ['a', 'b']);
});

test('entryRefs reads the one structured reference shape', () => {
  // A {variant, fragment} object passes through: one shape in, one out,
  // the origin slot null when the reference carries none (the server's
  // exact shape: "via" rides EVERY item).
  assert.deepEqual(entryRefs({ variants: [{ variant: 'a', fragment: '' }, { variant: 'b', fragment: 'block(text,1)' }] }), [
    { variant: 'a', fragment: '', via: null },
    { variant: 'b', fragment: 'block(text,1)', via: null },
  ]);
  // Malformed items are ignored, order kept (the server's emit-gate
  // posture): strings (the retired reference form), non-strings, empty
  // variant, missing fragment key.
  assert.deepEqual(entryRefs({ variants: ['a', 5, { variant: '', fragment: '' }, { variant: 'x' }, { variant: 'y', fragment: '' }] }), [
    { variant: 'y', fragment: '', via: null },
  ]);
  assert.deepEqual(entryRefs({ variants: 'a' }), []);
  assert.deepEqual(entryRefs(null), []);
  // isVariantRef speaks the same read: a fragment ref IS a wearing.
  assert.equal(isVariantRef({ variants: [{ variant: 'b', fragment: 'container(1)' }] }), true);
});

test('entryRefs carries the origin a distributed wear records', () => {
  // A wear a composition's apply distributed onto a child carries "via",
  // the composition's name; the read carries it through so a consumer
  // that rewrites the list cannot drop it by accident. An empty or
  // non-string "via" reads as no origin, the server's gate posture.
  assert.deepEqual(entryRefs({
    variants: [
      { variant: 'row', fragment: '', via: 'card_left' },
      { variant: 'card_left', fragment: 'container(1)' },
      { variant: 'glow', fragment: '', via: '' },
      { variant: 'calm', fragment: '', via: 7 },
    ],
  }), [
    { variant: 'row', fragment: '', via: 'card_left' },
    { variant: 'card_left', fragment: 'container(1)', via: null },
    { variant: 'glow', fragment: '', via: null },
    { variant: 'calm', fragment: '', via: null },
  ]);
  // The projections ignore it: an origin-bearing wear IS a wearing.
  assert.deepEqual(entryVariants({ variants: [{ variant: 'row', fragment: '', via: 'card_left' }] }), ['row']);
});

test('structured references resolve through their own fragment', () => {
  const hero = {
    id: 'hero',
    label: 'Hero',
    group: 'style',
    value: {
      '': { default: { padding: '4rem' } },
      '::.inner': { default: { margin: '0' } },
      'block(text,2)': { default: { color: 'red', margin: '1rem' } },
      'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    },
  };
  const library = [hero];
  const childKey = `block:${T2}`;
  const fragMap = {
    [childKey]: { variants: [{ variant: 'hero', fragment: 'block(text,2)' }], default: { color: 'blue' } },
  };
  // effectiveEntry: the fragment's own entry resolves at the carrier's
  // root, its part entries at the carrier's parts — with the carrier's
  // overrides on top (the canvas/panel agreement the rework exists for).
  const resolved = effectiveEntry(fragMap, childKey, library);
  assert.deepEqual(resolved.entry, { default: { color: 'blue', margin: '1rem' } });
  assert.equal(resolved.reference, 'hero');
  assert.equal(resolved.variant.label, 'Hero');
  assert.deepEqual(effectiveEntry(fragMap, `${childKey}::.quote`, library).entry, { default: { 'font-style': 'italic' } });
  // effectiveElementMap projects the fragment's suffix space, never the
  // variant's other fragments.
  const effective = effectiveElementMap(fragMap, childKey, library);
  assert.deepEqual(effective[childKey], { default: { color: 'blue', margin: '1rem' } });
  assert.deepEqual(effective[`${childKey}::.quote`], { default: { 'font-style': 'italic' } });
  assert.equal(effective[`${childKey}::.inner`], undefined);
  // shadowedProperties badges the carrier's own overrides against the
  // fragment's values, per-key uniform with root wearers.
  assert.deepEqual(shadowedProperties(fragMap, childKey, library), { default: { color: 'hero' } });
  // A root ('' fragment) reference resolves the variant's own root entry.
  const rootMap = { [`container:${SEC}`]: { variants: [{ variant: 'hero', fragment: '' }] } };
  assert.deepEqual(effectiveEntry(rootMap, `container:${SEC}`, library).entry, { default: { padding: '4rem' } });
});

test('orderedEntryRefs orders fragment refs by their variant axis', () => {
  const library = [
    { id: 'aa_pop', group: 'emphasis', value: {} },
    { id: 'zz_calm', group: 'style', value: {} },
  ];
  const axes = ['style', 'emphasis'];
  const entry = {
    variants: [
      { variant: 'aa_pop', fragment: 'container(1)' },
      { variant: 'zz_calm', fragment: '' },
    ],
  };
  // The fragment is a lookup key, never an ordering fact: the fragment
  // ref sorts by ITS variant's axis, and the projection agrees.
  assert.deepEqual(orderedEntryRefs(entry, library, axes), [
    { variant: 'zz_calm', fragment: '', via: null },
    { variant: 'aa_pop', fragment: 'container(1)', via: null },
  ]);
  assert.deepEqual(orderedEntryVariants(entry, library, axes), ['zz_calm', 'aa_pop']);
  // No vocabulary: stored order passes through, normalized.
  assert.deepEqual(orderedEntryRefs(entry, library, null), [
    { variant: 'aa_pop', fragment: 'container(1)', via: null },
    { variant: 'zz_calm', fragment: '', via: null },
  ]);
});

// --- skinDefaultEntry: the site defaults tier's provenance read. ---

test('skinDefaultEntry resolves an element scope against the skin defaults', () => {
  const guide = {
    skinDefaults: {
      'kind:container': {
        '': { default: { padding: '2rem', gap: '1rem' } },
        '::.daedalus-stylish-layout-container': { default: { 'max-width': '80rem' } },
      },
      'layout:twocol': { '': { default: { padding: '3rem' } } },
      'block_type:cta': { '': { default: { margin: '1rem' } } },
      'base:h2': { '': { default: { 'font-size': '2rem' } } },
    },
  };
  const layouts = { [UUID_A]: 'twocol' };
  // A refined section merges the refinement OVER the family kind
  // (delivery rank order: the refinement's block prints later and wins).
  assert.deepEqual(
    skinDefaultEntry(guide, `container:${UUID_A}`, {}, layouts),
    { default: { padding: '3rem', gap: '1rem' } },
  );
  // An unrefined section reads the kind layer alone.
  assert.deepEqual(
    skinDefaultEntry(guide, `container:${UUID_B}`, {}, {}),
    { default: { padding: '2rem', gap: '1rem' } },
  );
  // A drilled part reads its own relative key.
  assert.deepEqual(
    skinDefaultEntry(guide, `container:${UUID_B}::.daedalus-stylish-layout-container`, {}, {}),
    { default: { 'max-width': '80rem' } },
  );
  // Blocks resolve through the block family.
  assert.deepEqual(
    skinDefaultEntry(guide, `block:${UUID_A}`, { [UUID_A]: 'cta' }, {}),
    { default: { margin: '1rem' } },
  );
  // Plugin blocks resolve through the block family's other refinement,
  // merging their refinement over the kind:block layer.
  const pluginGuide = {
    skinDefaults: {
      'kind:block': { '': { default: { padding: '1rem', margin: '2rem' } } },
      'block_plugin:system_menu_block': { '': { default: { margin: '1rem' } } },
    },
  };
  assert.deepEqual(
    skinDefaultEntry(pluginGuide, `block:${UUID_P}`, blockTypes, {}),
    { default: { padding: '1rem', margin: '1rem' } },
  );
  // Nothing defaulted for the scope: null, and base scopes are never
  // captured by selectable elements.
  assert.equal(skinDefaultEntry(guide, `entity:${UUID_A}`, {}, {}), null);
  assert.equal(skinDefaultEntry({}, `container:${UUID_B}`, {}, {}), null);
  assert.equal(skinDefaultEntry(guide, null, {}, {}), null);
});

test('skinDefaultEntry layers base:{tag} under the element scopes', () => {
  const guide = {
    skinDefaults: {
      'kind:block': { '': { default: { 'font-size': '1rem' } } },
      'block_type:text': { '': { default: { 'line-height': '1.2' } } },
      'base:h2': {
        '': { default: { 'font-size': '2rem', 'font-weight': '600' } },
      },
    },
  };
  const types = { [UUID_A]: 'text' };
  // The rendered tag folds the base layer in UNDER kind and refinement
  // (delivery rank order: base rules are unscoped and lose to every
  // scoped block).
  assert.deepEqual(
    skinDefaultEntry(guide, `block:${UUID_A}`, types, {}, 'h2'),
    { default: { 'font-size': '1rem', 'line-height': '1.2', 'font-weight': '600' } },
  );
  // A tag the skin defaults nothing for adds nothing; no tag (satellite,
  // no canvas element) degrades the base layer away entirely.
  assert.deepEqual(
    skinDefaultEntry(guide, `block:${UUID_A}`, types, {}, 'div'),
    { default: { 'font-size': '1rem', 'line-height': '1.2' } },
  );
  assert.deepEqual(
    skinDefaultEntry(guide, `block:${UUID_A}`, types, {}),
    { default: { 'font-size': '1rem', 'line-height': '1.2' } },
  );
  // A base-only hit still renders provenance: a collapsed heading block
  // with no element-scope defaults reads the tag layer alone, at the ''
  // entry even when drilled into a part (base defaults are element-only;
  // the caller resolves the PART element's tag).
  const baseOnly = { skinDefaults: { 'base:h2': { '': { default: { 'font-weight': '600' } } } } };
  assert.deepEqual(
    skinDefaultEntry(baseOnly, `block:${UUID_B}`, {}, {}, 'h2'),
    { default: { 'font-weight': '600' } },
  );
  assert.deepEqual(
    skinDefaultEntry(baseOnly, `block:${UUID_B}::.quote`, {}, {}, 'h2'),
    { default: { 'font-weight': '600' } },
  );
});

// --- declaredDefaultEntry: the declaration's own defaults, beneath the skin. ---

test('declaredDefaultEntry layers the refinement scope over its family kind against the declared defaults', () => {
  const guide = {
    declaredDefaults: {
      'block_type:testimonial': {
        '': { default: { display: 'block', width: 'fit-content' } },
        '::.testimonial__quote': { default: { display: 'block' } },
      },
      'block_plugin:system_menu_block': { '': { default: { display: 'flex' } } },
      'layout:twocol': { '': { default: { display: 'grid' }, narrow: { display: 'block' } } },
    },
    // Skin defaults for the same scopes are the skin's read, never this
    // one's: the two tiers stay apart so the origin can name the supplier.
    skinDefaults: {
      'kind:block': { '': { default: { padding: '1rem' } } },
      'block_type:testimonial': { '': { default: { display: 'inline-block' } } },
      'base:h2': { '': { default: { 'font-size': '2rem' } } },
    },
  };
  const layouts = { [UUID_A]: 'twocol' };
  // A block type's root and a drilled part read their own relative keys.
  assert.deepEqual(
    declaredDefaultEntry(guide, `block:${UUID_A}`, blockTypes, {}),
    { default: { display: 'block', width: 'fit-content' } },
  );
  assert.deepEqual(
    declaredDefaultEntry(guide, `block:${UUID_A}::.testimonial__quote`, blockTypes, {}),
    { default: { display: 'block' } },
  );
  // A plugin block and a refined section resolve through their refinements.
  assert.deepEqual(
    declaredDefaultEntry(guide, `block:${UUID_P}`, blockTypes, {}),
    { default: { display: 'flex' } },
  );
  assert.deepEqual(
    declaredDefaultEntry(guide, `container:${UUID_A}`, {}, layouts),
    { default: { display: 'grid' }, narrow: { display: 'block' } },
  );
  // No kind in this payload and no base layer: an unresolved bundle
  // (kind:block) and an unrefined section read nothing, whatever the
  // skin defaults for the kind, and a part the declaration does not name
  // reads nothing.
  assert.equal(declaredDefaultEntry(guide, `block:${UUID_B}`, blockTypes, {}), null);
  assert.equal(declaredDefaultEntry(guide, `container:${UUID_B}`, {}, {}), null);
  assert.equal(declaredDefaultEntry(guide, `block:${UUID_A}::.other`, blockTypes, {}), null);
  assert.equal(declaredDefaultEntry(guide, `entity:${UUID_A}`, {}, {}), null);
  // No payload, no key: null, never a throw.
  assert.equal(declaredDefaultEntry({}, `block:${UUID_A}`, blockTypes, {}), null);
  assert.equal(declaredDefaultEntry(guide, null, blockTypes, {}), null);
  // The skin's read is unchanged by the declared tier's presence.
  assert.deepEqual(
    skinDefaultEntry(guide, `block:${UUID_A}`, blockTypes, {}),
    { default: { padding: '1rem', display: 'inline-block' } },
  );
});

test('declaredDefaultEntry reads a kind scope beneath its refinement, the delivery rank', () => {
  // The ownership law: a composed kind declares its root (the container's
  // display fact), any kind its declared parts (the entity's layout
  // root); the payload carries them in delivery rank order, kinds first.
  const guide = {
    declaredDefaults: {
      'kind:container': {
        '': { default: { display: 'grid', 'align-items': 'start' }, narrow: { display: 'block' } },
      },
      'layout:twocol': { '': { default: { display: 'flex' }, medium: { 'align-items': 'center' } } },
      'kind:entity': { '::.daedalus-layout-layout': { default: { display: 'grid' } } },
    },
  };
  const layouts = { [UUID_A]: 'twocol' };
  // A section whose layout declares no default reads the kind's alone.
  assert.deepEqual(
    declaredDefaultEntry(guide, `container:${UUID_B}`, {}, { [UUID_B]: 'onecol' }),
    { default: { display: 'grid', 'align-items': 'start' }, narrow: { display: 'block' } },
  );
  // So does an unrefined section (its scope IS the kind).
  assert.deepEqual(
    declaredDefaultEntry(guide, `container:${UUID_B}`, {}, {}),
    { default: { display: 'grid', 'align-items': 'start' }, narrow: { display: 'block' } },
  );
  // A layout default shadows the kind's per property, per condition: the
  // refinement's display wins at Base, the kind's align-items and narrow
  // block survive beneath, the refinement's Medium block joins.
  assert.deepEqual(
    declaredDefaultEntry(guide, `container:${UUID_A}`, {}, layouts),
    {
      default: { display: 'flex', 'align-items': 'start' },
      narrow: { display: 'block' },
      medium: { 'align-items': 'center' },
    },
  );
  // The entity has no refinement: its declared part reads the kind's
  // entry at the part's relative key; its root, undeclared, reads nothing.
  assert.deepEqual(
    declaredDefaultEntry(guide, `entity:${UUID_A}::.daedalus-layout-layout`, {}, {}),
    { default: { display: 'grid' } },
  );
  assert.equal(declaredDefaultEntry(guide, `entity:${UUID_A}`, {}, {}), null);
  // The block family: a kind:block entry would layer under a bundle the
  // same way; this payload carries none, so the bundle reads nothing.
  assert.equal(declaredDefaultEntry(guide, `block:${UUID_A}`, blockTypes, {}), null);
});

test('suppliedValue reads ONE worn reference\'s value at a tuple through its own fragment', () => {
  const library = [
    { id: 'aa_pop', group: 'emphasis', value: { '': { default: { padding: '1rem' } } } },
    { id: 'zz_calm', group: 'style', value: { '': { default: { padding: '2rem', color: 'blue' }, hover: { color: 'navy' } }, '::.quote': { default: { 'font-style': 'italic' } } } },
    { id: 'hero', group: 'style', value: { 'block(text,2)': { default: { color: 'red' } } } },
  ];
  const axes = ['style', 'emphasis'];
  const map = {
    [KEY]: { variants: [{ variant: 'zz_calm', fragment: '' }, { variant: 'aa_pop', fragment: '' }], default: { padding: '3rem' } },
    [`block:${UUID_B}`]: { variants: [{ variant: 'hero', fragment: 'block(text,2)' }] },
  };
  // The beaten layer's value, whichever reference the caller names (the
  // own 3rem shadows both; the provenance menu says what returns).
  assert.equal(suppliedValue(map, KEY, library, 'aa_pop', 'default', 'padding', axes), '1rem');
  assert.equal(suppliedValue(map, KEY, library, 'zz_calm', 'default', 'padding', axes), '2rem');
  assert.equal(suppliedValue(map, KEY, library, 'zz_calm', 'hover', 'color', axes), 'navy');
  // A part key resolves through the ROOT's references at its own relative.
  assert.equal(suppliedValue(map, `${KEY}::.quote`, library, 'zz_calm', 'default', 'font-style', axes), 'italic');
  // A distributed fragment reference supplies through ITS OWN fragment.
  assert.equal(suppliedValue(map, `block:${UUID_B}`, library, 'hero', 'default', 'color', axes), 'red');
  // Not worn here, nothing at the tuple, or no key: undefined.
  assert.equal(suppliedValue(map, KEY, library, 'hero', 'default', 'color', axes), undefined);
  assert.equal(suppliedValue(map, KEY, library, 'aa_pop', 'hover', 'padding', axes), undefined);
  assert.equal(suppliedValue(map, null, library, 'aa_pop', 'default', 'padding', axes), undefined);
});

test('isShipped and locked: two record facts, the lock is the updatable flag', () => {
  const site = variantRecord('mine');
  const shipped = variantRecord('theirs', { origin: 'shipped' });
  const open = variantRecord('theirs', { origin: 'shipped', updatable: true });
  assert.deepEqual([site, shipped, open].map(isShipped), [false, true, true]);
  // A site variant is never locked; a shipped one is locked on a consuming
  // site and open under the developer gate (the provider stamps the flag).
  assert.deepEqual([site, shipped, open].map(locked), [false, true, false]);
  // No record, or a record without the flag: nothing to write.
  assert.equal(isShipped(null), false);
  assert.equal(locked(null), true);
  assert.equal(locked({ id: 'bare', origin: 'user' }), true);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} variant tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} variant tests passed.`);
