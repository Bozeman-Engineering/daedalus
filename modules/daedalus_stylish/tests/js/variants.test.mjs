/**
 * Self-asserting tests for the client variant-scope mirror.
 *
 * Run directly (`node tests/js/variants.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite.
 * PHP is authoritative for scope semantics (StyleMapValidator::captureScope
 * / scopeMatches); these tests lock the mirror to the same rules, plus the
 * element-relative entry math the save/push gestures share.
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
  captureScope,
  scopeMatches,
  applicableVariants,
  continuedKey,
  elementEntries,
  hasCustomStyles,
  relativeUnder,
  findVariant,
  isShipped,
  locked,
  subtreeSlotTable,
  captureEntries,
  captureSources,
  pushStyles,
  saveStyles,
  shadowClearingOps,
  blueprintProvenance,
  variantFit,
  rankVariants,
} from '../../js/src/variant-scope.js';
import { formatSlotKey } from '../../js/src/slot-key.js';
import { EMPTY_MATERIALS, roster, blueprintRecord, variantRecord } from './materials-fixture.mjs';

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
  // A rule key captures its LANDING, the element its last compound
  // names (PHP mirror: VocabularyRoster::landingScope()): a type's root
  // address, a tag's base tag; a part, the children and a subjectless
  // compound land on no element scope.
  assert.equal(captureScope(`block:${UUID_A}::.quote`, blockTypes), null);
  assert.equal(captureScope(`container:${UUID_A}::> pb-testimonial`, blockTypes), 'block_type:testimonial');
  assert.equal(captureScope(`container:${UUID_A}::> pb-text > pb-container`), 'kind:container');
  assert.equal(captureScope(`container:${UUID_A}::a`), 'base:a');
  assert.equal(captureScope(`container:${UUID_A}::> p + p`), 'base:p');
  assert.equal(captureScope(`container:${UUID_A}::> pb-text .link`), null);
  assert.equal(captureScope(`container:${UUID_A}::> *`), null);
  assert.equal(captureScope(`container:${UUID_A}::> *:not(pb-image)`), null);
  // A generated box is no element scope, behind a type, a tag or the
  // owner's own anchor: no variant pairs at a pseudo rule.
  assert.equal(captureScope(`container:${UUID_A}::> pb-testimonial::before`, blockTypes), null);
  assert.equal(captureScope(`container:${UUID_A}::a::after`), null);
  assert.equal(captureScope(`block:${UUID_A}::&::before`, blockTypes), null);
  // Sections scope per layout when the table resolves them, degrading to
  // the kind exactly like blocks.
  assert.equal(captureScope(`container:${UUID_A}`, blockTypes, sectionLayouts), 'layout:layout_twocol_section');
  assert.equal(captureScope(`container:${UUID_A}::.daedalus-stylish-layout-container`, blockTypes, sectionLayouts), null);
  assert.equal(captureScope(`container:${UUID_B}`, blockTypes, sectionLayouts), 'kind:container');
  assert.equal(captureScope('bogus:123'), null);
  // A plugin placement (the table's 'plugin:{base id}' discriminator)
  // captures the block family's other refinement; sub-element keys ride.
  assert.equal(captureScope(`block:${UUID_P}`, blockTypes), 'block_plugin:system_menu_block');
  assert.equal(captureScope(`block:${UUID_P}::.menu-item`, blockTypes), null);
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
  // A rule pairs by its landing: a text variant wears at the text rule,
  // never the container's; nothing pairs at a part or the children.
  assert.equal(scopeMatches('block_type:testimonial', `container:${UUID_A}::> pb-testimonial`), true);
  assert.equal(scopeMatches('kind:block', `container:${UUID_A}::> pb-testimonial`), true);
  assert.equal(scopeMatches('kind:container', `container:${UUID_A}::> pb-testimonial`), false);
  assert.equal(scopeMatches('kind:container', `container:${UUID_A}::> pb-container`), true);
  assert.equal(scopeMatches('base:a', `container:${UUID_A}::a`), true);
  assert.equal(scopeMatches('kind:block', `container:${UUID_A}::a`), false);
  assert.equal(scopeMatches('kind:container', `container:${UUID_A}::> *`), false);
  assert.equal(scopeMatches('block_type:testimonial', `block:${UUID_A}::.quote`, blockTypes), false);
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

test('blueprintProvenance resolves the nearest self-or-ancestor stamp', () => {
  const layout = [
    {
      kind: 'container',
      uuid: UUID_A,
      layout_id: 'layout_onecol',
      blueprint: 'cta_card',
      children: [
        { kind: 'block', uuid: UUID_B, block_type: 'testimonial' },
        {
          kind: 'container',
          uuid: 'cccccccc-1111-2222-3333-444444444444',
          layout_id: 'layout_onecol',
          blueprint: 'inner_band',
          children: [{ kind: 'block', uuid: 'dddddddd-1111-2222-3333-444444444444', block_type: 'basic' }],
        },
      ],
    },
    { kind: 'container', uuid: 'eeeeeeee-1111-2222-3333-444444444444', layout_id: 'layout_onecol', children: [] },
  ];
  // The stamped root reads its own stamp; descendants inherit it — a
  // placement is the whole placed subtree.
  assert.equal(blueprintProvenance(`container:${UUID_A}`, layout), 'cta_card');
  assert.equal(blueprintProvenance(`block:${UUID_B}`, layout), 'cta_card');
  // Part keys resolve through their root.
  assert.equal(blueprintProvenance(`block:${UUID_B}::.quote`, layout), 'cta_card');
  // A nested placement's own stamp shadows its ancestor's.
  assert.equal(blueprintProvenance('container:cccccccc-1111-2222-3333-444444444444', layout), 'inner_band');
  assert.equal(blueprintProvenance('block:dddddddd-1111-2222-3333-444444444444', layout), 'inner_band');
  // Unstamped, unknown, and treeless selections read as unprovenanced.
  assert.equal(blueprintProvenance('container:eeeeeeee-1111-2222-3333-444444444444', layout), null);
  assert.equal(blueprintProvenance('container:ffffffff-1111-2222-3333-444444444444', layout), null);
  assert.equal(blueprintProvenance(`container:${UUID_A}`, []), null);
  assert.equal(blueprintProvenance('bogus', layout), null);
});

test('applicableVariants gates blueprint-owned variants on provenance', () => {
  const layout = [
    {
      kind: 'container',
      uuid: UUID_A,
      layout_id: 'layout_twocol_section',
      blueprint: 'cta_card',
      children: [{ kind: 'region', region: 'first', children: [{ kind: 'block', uuid: UUID_B, plugin_id: 'system_menu_block:main' }] }],
    },
    { kind: 'container', uuid: 'eeeeeeee-1111-2222-3333-444444444444', layout_id: 'layout_onecol', children: [] },
  ];
  // Ownership is the record's owned_by; an orphan owns by an id the
  // roster holds no blueprint for (the ONE orphan rule, isOrphaned).
  const variants = roster(
    variantRecord('free_look', { scope: 'kind:container' }),
    variantRecord('card_look', { scope: 'kind:container', owned_by: 'cta_card' }),
    variantRecord('other_look', { scope: 'kind:container', owned_by: 'other_blueprint' }),
    variantRecord('orphan_look', { scope: 'kind:container', owned_by: 'deleted_blueprint' }),
    variantRecord('block_look', { scope: 'kind:block', owned_by: 'cta_card' }),
    blueprintRecord('cta_card'),
    blueprintRecord('other_blueprint'),
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

test('continuedKey and relativeUnder: an element appends its relatives, a rule continues them by descent', () => {
  const RULE = `container:${UUID_A}::> pb-text`;
  assert.equal(continuedKey(KEY, ''), KEY);
  assert.equal(continuedKey(KEY, '::.x'), `${KEY}::.x`);
  assert.equal(continuedKey(RULE, ''), RULE);
  assert.equal(continuedKey(RULE, '::.x'), `${RULE} .x`);
  assert.equal(continuedKey(RULE, '::> pb-image'), `${RULE} > pb-image`);
  // A suffix the grammar refuses under the rule (a class after a tag).
  assert.equal(continuedKey(`container:${UUID_A}::a`, '::.icon'), null);
  assert.equal(relativeUnder(KEY, `${KEY}::.x`), '::.x');
  assert.equal(relativeUnder(KEY, KEY), null);
  assert.equal(relativeUnder(KEY, `${KEY}:field_x`), null);
  assert.equal(relativeUnder(RULE, `${RULE} .x`), '::.x');
  assert.equal(relativeUnder(RULE, `${RULE} > pb-image`), '::> pb-image');
  assert.equal(relativeUnder(RULE, RULE), null);
  assert.equal(relativeUnder(RULE, `container:${UUID_A}::> pb-image`), null);
  assert.equal(relativeUnder(RULE, `container:${UUID_A}`), null);
});

test('elementEntries at a rule key: the rule\'s entry and its continuations by descent', () => {
  const RULE = `container:${UUID_A}::> pb-text`;
  const ruleMap = {
    [`container:${UUID_A}`]: { default: { padding: '1rem' } },
    [RULE]: { variants: [{ variant: 'gritty', fragment: '' }], default: { color: 'red' } },
    [`${RULE} .quote`]: { default: { 'font-style': 'italic' } },
    [`container:${UUID_A}::> pb-image`]: { default: { margin: '0' } },
  };
  assert.deepEqual(elementEntries(ruleMap, RULE), {
    '': { default: { color: 'red' } },
    '::.quote': { default: { 'font-style': 'italic' } },
  });
  assert.equal(hasCustomStyles(ruleMap, RULE), true);
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
    kind: 'container',
    uuid: SEC,
    layout_id: 'daedalus_stylish_container',
    children: [
      { kind: 'block', uuid: T1, block_type: 'text' },
      { kind: 'block', uuid: PLUGIN, plugin_id: 'system_menu_block:main' },
      { kind: 'block', uuid: T2, block_type: 'text' },
      {
        kind: 'container',
        uuid: NSEC,
        layout_id: 'daedalus_stylish_container',
        children: [{ kind: 'block', uuid: IMG, block_type: 'image' }],
      },
      {
        kind: 'container',
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
  // A multi-region container carries no slot space: its children are
  // region nodes, named slots that left the grammar with the region
  // segment.
  const multi = [{
    kind: 'container',
    uuid: SEC,
    layout_id: 'layout_twocol_section',
    children: [{ kind: 'region', region: 'first', children: [{ kind: 'block', uuid: T1, block_type: 'text' }] }],
  }];
  assert.deepEqual(subtreeSlotTable(`container:${SEC}`, multi), {});
});

test('captureEntries walks the staged subtree into slot keys', () => {
  const subtreeMap = {
    [`container:${SEC}`]: { default: { padding: '0' } },
    [`container:${SEC}::.daedalus-stylish-layout-container`]: { default: { gap: '1rem' } },
    // Children entries ride their element like part entries: the root's
    // own '::.class > *' stays a relative suffix, a subtree child's
    // '::> *' translates onto its slot key.
    [`container:${SEC}::.daedalus-stylish-layout-container > *`]: { default: { 'flex-grow': '1' } },
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
    [`container:${NSEC}::> *`]: { default: { 'min-width': '0' } },
    [`block:${IMG}`]: { default: { 'border-radius': '4px' } },
  };
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    '::.daedalus-stylish-layout-container': { default: { gap: '1rem' } },
    '::.daedalus-stylish-layout-container > *': { default: { 'flex-grow': '1' } },
    'block(text,1)': { variants: [{ variant: 'card', fragment: '' }] },
    'block(text,2)': { default: { margin: '0' }, hover: { color: 'red' } },
    'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    'block(text,2)/field(field_body)': { default: { color: 'blue' } },
    'container(1)': { default: { padding: '9px', margin: '1px' } },
    'container(1)::.daedalus-stylish-layout-container': { default: { 'max-width': '40rem' } },
    'container(1)::> *': { default: { 'min-width': '0' } },
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
    '::> *': { default: { 'min-width': '0' } },
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

test('capture keeps a rule entry\'s references as refs, own and children\'s, the origin stripped', () => {
  // A rule's wear is part of the look: the section's `> pb-text` rule
  // wears "card" (delivered at every text child), a child's `> a` rule
  // wears "link". Both travel as the captured '::selector' entry's
  // reference list beside its overrides, exactly as a child's root
  // references do; the capture root's own references stay behind, and
  // a fragment reference at a rule never travels.
  const subtreeMap = {
    [`container:${SEC}`]: { variants: [{ variant: 'hero', fragment: '' }], default: { padding: '0' } },
    [`container:${SEC}::> pb-text`]: {
      variants: [{ variant: 'card', fragment: '', via: 'hero' }, { variant: 'hero', fragment: 'block(text,1)' }],
      default: { margin: '0' },
    },
    [`container:${SEC}::p`]: { variants: [{ variant: 'prose', fragment: '' }] },
    [`block:${T2}::> a`]: { variants: [{ variant: 'link', fragment: '' }], default: { color: 'teal' } },
  };
  assert.deepEqual(captureEntries(subtreeMap, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    '::> pb-text': { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
    '::p': { variants: [{ variant: 'prose', fragment: '' }] },
    'block(text,2)::> a': { variants: [{ variant: 'link', fragment: '' }], default: { color: 'teal' } },
  });
  assert.deepEqual(captureSources(subtreeMap, `container:${SEC}`, layout).own.map(({ key, entry }) => [key, entry.variants ?? null]), [
    [`container:${SEC}`, null],
    [`container:${SEC}::> pb-text`, [{ variant: 'card', fragment: '' }]],
    [`container:${SEC}::p`, [{ variant: 'prose', fragment: '' }]],
  ]);
  // elementEntries stays the values-only read.
  assert.deepEqual(elementEntries(subtreeMap, `container:${SEC}`), {
    '': { default: { padding: '0' } },
    '::> pb-text': { default: { margin: '0' } },
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

test('capture keeps every selector but instance parts (structure transfers)', () => {
  // Tags, types and the children are structural relations and travel
  // with the look, in BOTH buckets; only an instance part (a minted
  // identity, wherever it stands in the selector) stays behind, since
  // the server rejects it in variant styles.
  const map = {
    [`block:${T2}`]: { default: { margin: '0' } },
    [`block:${T2}::p`]: { default: { 'font-size': '1.25rem' } },
    [`block:${T2}::ul > *`]: { default: { color: 'red' } },
    [`block:${T2}::> *`]: { default: { gap: '1rem' } },
    [`block:${T2}::.s-a3`]: { default: { color: 'teal' } },
    [`container:${SEC}`]: { default: { padding: '0' } },
    [`container:${SEC}::> pb-text`]: { default: { color: 'navy' } },
    [`container:${SEC}::> pb-text .s-b4`]: { default: { color: 'olive' } },
  };
  assert.deepEqual(captureEntries(map, `block:${T2}`, layout), {
    '': { default: { margin: '0' } },
    '::p': { default: { 'font-size': '1.25rem' } },
    '::ul > *': { default: { color: 'red' } },
    '::> *': { default: { gap: '1rem' } },
  });
  assert.deepEqual(captureEntries(map, `container:${SEC}`, layout), {
    '': { default: { padding: '0' } },
    '::> pb-text': { default: { color: 'navy' } },
    'block(text,2)': { default: { margin: '0' } },
    'block(text,2)::p': { default: { 'font-size': '1.25rem' } },
    'block(text,2)::ul > *': { default: { color: 'red' } },
    'block(text,2)::> *': { default: { gap: '1rem' } },
  });
  // Still the element's own custom styles (clipboard, styled indicators).
  assert.equal(hasCustomStyles({ [`block:${T2}::.s-a3`]: { default: { color: 'red' } } }, `block:${T2}`), true);
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

test('captureSources at a rule key: the entry and its continuations by descent, no subtree', () => {
  // The editing session's anchor when a rule wears the document: its
  // own sources are the rule's entry and the keys continued under it by
  // descent, at the relatives delivery prints them; a sibling rule and
  // the subtree's elements are not the rule's (a variant is saved from
  // an element, so direct is empty).
  const rule = `container:${SEC}::> pb-text`;
  const map = {
    [rule]: { variants: [{ variant: 'card', fragment: '' }], default: { margin: '0' } },
    [`${rule} .quote`]: { variants: [{ variant: 'prose', fragment: '', via: 'card' }], default: { color: 'navy' } },
    [`container:${SEC}::p`]: { default: { margin: '1px' } },
    [`block:${T1}`]: { default: { padding: '0' } },
  };
  const { own, direct } = captureSources(map, rule, layout);
  assert.deepEqual(own, [
    { key: rule, relative: '', entry: { default: { margin: '0' } } },
    { key: `${rule} .quote`, relative: '::.quote', entry: { variants: [{ variant: 'prose', fragment: '' }], default: { color: 'navy' } } },
  ]);
  assert.deepEqual(direct, []);
});

test('shadowClearingOps at a rule anchor retires the rule\'s redundant overrides and its continuations\'', () => {
  const rule = `container:${SEC}::> pb-text`;
  const look = {
    id: 'text_look',
    group: 'style',
    value: { '': { default: { padding: '0', gap: '1rem' } }, '::.quote': { default: { color: 'navy' } } },
  };
  const map = {
    // padding equals the pushed value (retires), gap differs (stays).
    [rule]: { variants: [{ variant: 'text_look', fragment: '' }], default: { padding: '0', gap: '2rem' } },
    // The continuation: color retires, margin is not covered.
    [`${rule} .quote`]: { default: { color: 'navy', margin: '0' } },
    // A sibling rule is no continuation of the anchor.
    [`container:${SEC}::p`]: { default: { padding: '0' } },
  };
  assert.deepEqual(shadowClearingOps(map, rule, layout, look, [look]), [
    { op: 'update_style', key: rule, properties: { padding: null } },
    { op: 'update_style', key: `${rule} .quote`, properties: { color: null } },
  ]);
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

test('axisVocabulary at a rule key reads the landing\'s vocabulary, root-only axes dropped', () => {
  const guide = {
    kinds: { section: {}, block: {}, container: { axes: axesOf(['style', 'container_layout', 'width'], ['width']) } },
    layouts: { layout_twocol_section: { axes: axesOf(['style', 'proportions', 'width'], ['width']) } },
    blockTypes: { text: { axes: axesOf(['style', 'emphasis']) }, testimonial: { axes: axesOf(['style', 'emphasis']) } },
    base: { p: { axes: axesOf(['style', 'prose']) } },
  };
  // A type chain lands on the type's own declaration.
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_A}::> pb-text`, blockTypes, sectionLayouts), ['style', 'emphasis']);
  // A container rule lands on kind:container: root-only axes never offer
  // at a rule (its matches are never top-level).
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_A}::> pb-container`, blockTypes, sectionLayouts), ['style', 'container_layout']);
  // A tag tier lands on the base tag's declaration.
  assert.deepEqual(axisVocabulary(guide, `block:${UUID_A}::p`, blockTypes, sectionLayouts), ['style', 'prose']);
  // A part lands on no element scope: its root's vocabulary, as before.
  assert.deepEqual(axisVocabulary(guide, `block:${UUID_A}::.quote`, blockTypes, sectionLayouts), ['style', 'emphasis']);
  assert.deepEqual(axisVocabulary(guide, `container:${UUID_A}::> *`, blockTypes, sectionLayouts), ['style', 'proportions', 'width']);
  // A landing declaring no axes: the universal vocabulary.
  assert.deepEqual(axisVocabulary(guide, `block:${UUID_A}::a`, blockTypes, sectionLayouts), ['style']);
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
  // The lean node tree: UUID_C nested in UUID_A's first region, UUID_D in
  // UUID_C's.
  const region = (name, children) => ({ kind: 'region', region: name, children });
  const tree = [{
    kind: 'container',
    uuid: UUID_A,
    layout_id: 'layout_twocol_section',
    children: [
      region('first', [{
        kind: 'container',
        uuid: UUID_C,
        layout_id: 'layout_twocol_section',
        children: [region('first', [{ kind: 'container', uuid: UUID_D, layout_id: 'layout_twocol_section', children: [region('first', []), region('second', [])] }]), region('second', [])],
      }]),
      region('second', []),
    ],
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
