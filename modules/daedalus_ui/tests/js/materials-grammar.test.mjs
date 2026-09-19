/**
 * Self-asserting tests for the value grammar's client twin and the roster
 * index it resolves through: the client-side semantics the round-trip
 * lock cannot see (loud refusals, the index's read surface, the record
 * convenience on a resolved value). The equivalence test
 * (ValueGrammarTest::testClientTwinEquivalence) drives both grammars with
 * the shared battery through materials-cases.mjs.
 *
 * Run with `npm test` (node --test tests/js/*.test.mjs) from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  KIND, SLOT, attrSlot, canonical, isInlineKind, isMaterialKind, isSlot, references, resolveValue, slotAttribute, slotScope, spell, spellGenerate, spellMedia,
} from '../../js/edit_mode/materials/grammar.js';
import { createRoster, rootOnly, scopesPair, wears } from '../../js/edit_mode/materials/roster.js';

const record = (fields) => ({
  kind: 'token', id: '', label: '', description: '', value: null, group: null, spellings: [], scope: null, applies: [],
  owned_by: null, published: true, weight: 0, icon: null, root_kind: null, origin: 'user', updatable: true, ...fields,
});

/**
 * The reference set: TestMaterialsProvider::referenceRecords() as delivered.
 */
const RECORDS = [
  record({ kind: 'token', id: 'color/accent', label: 'accent', value: { default: '#1475ad', dark: '#52b3eb' }, group: 'color', spellings: { style: 'var(--color-accent)' }, origin: 'shipped' }),
  record({ kind: 'swatch', id: 'lime_green', label: 'Lime green', value: '#4dff8c', group: 'color', spellings: { style: 'var(--swatch-lime_green)' } }),
  record({ kind: 'variant', id: 'button', label: 'Button', group: 'style', spellings: { class: 'button' }, scope: 'block_type:text', origin: 'shipped' }),
  record({ kind: 'variant', id: 'container_full', label: 'Full width', group: 'width', spellings: { 'attr:kind:container:width': 'full' }, scope: 'kind:container', applies: { depth: 'root' }, weight: -10, origin: 'shipped' }),
  record({ kind: 'variant', id: 'block_full', label: 'Full width', group: 'width', spellings: { 'attr:kind:block:width': 'full' }, scope: 'kind:block', origin: 'shipped' }),
  record({ kind: 'variant', id: 'hero_glow', label: 'Hero glow', group: 'style', spellings: { class: 'hero_glow' }, scope: 'kind:container', owned_by: 'hero', origin: 'ai' }),
  record({ kind: 'variant', id: 'link_quiet', label: 'Quiet link', group: 'style', spellings: { class: 'link_quiet' }, scope: 'base:a' }),
  record({ kind: 'stencil', id: 'hero', label: 'Hero', spellings: { stencil: 'hero' }, icon: '/modules/x/assets/hero.svg', root_kind: 'section', origin: 'shipped', updatable: false }),
  record({ kind: 'animation', id: 'fade_in', label: 'Fade in', description: 'Fade the element in from transparent.', value: { '0%': { opacity: '0' }, '100%': { opacity: '1' } }, spellings: { animation: 'motion-fade_in' }, origin: 'shipped' }),
];

const roster = () => createRoster(RECORDS);

test('slots: the five shapes, nothing else; the attribute slot is scoped', () => {
  assert.equal(attrSlot('kind:container', 'width'), 'attr:kind:container:width');
  assert.equal(slotAttribute('attr:kind:container:width'), 'width');
  assert.equal(slotScope('attr:kind:container:width'), 'kind:container');
  assert.equal(slotAttribute('attr:block_type:testimonial:emphasis'), 'emphasis');
  assert.equal(slotScope('attr:block_type:testimonial:emphasis'), 'block_type:testimonial');
  assert.equal(slotAttribute('class'), null);
  assert.equal(slotScope('class'), null);
  for (const malformed of ['attr:width', 'attr:', 'attr::width', 'attr:kind::width', 'attr:kind:container:']) {
    assert.equal(slotAttribute(malformed), null, `${malformed} names no attribute`);
    assert.equal(slotScope(malformed), null, `${malformed} names no scope`);
    assert.ok(!isSlot(malformed), `${malformed} is no slot`);
  }
  assert.ok(isSlot(SLOT.STYLE) && isSlot(SLOT.CLASS) && isSlot(SLOT.STENCIL) && isSlot(SLOT.ANIMATION) && isSlot('attr:kind:container:width'));
  assert.ok(!isSlot('field') && !isSlot(undefined));
  assert.throws(() => resolveValue('x', 'field', roster()), /Unknown value slot "field"/);
  assert.throws(() => references('x', 'attr:width', roster()), /Unknown value slot "attr:width"/);
});

test('kind predicates split materials from inline values', () => {
  assert.ok(isMaterialKind(KIND.TOKEN) && isMaterialKind(KIND.STENCIL) && isMaterialKind(KIND.ANIMATION));
  assert.ok(isInlineKind(KIND.LITERAL) && isInlineKind(KIND.MEDIA) && isInlineKind(KIND.GENERATE));
  assert.ok(!isMaterialKind(KIND.UNKNOWN) && !isInlineKind(KIND.UNKNOWN));
});

test('a resolved material carries its record; inline values carry none', () => {
  const r = roster();
  const swatch = resolveValue('var(--swatch-lime_green)', SLOT.STYLE, r);
  assert.equal(swatch.kind, KIND.SWATCH);
  assert.equal(swatch.record, r.get('swatch', 'lime_green'));
  assert.equal(resolveValue(' var( --color-accent ) ', SLOT.STYLE, r).kind, KIND.TOKEN, 'whitespace inside var() is tolerated; the spelling is canonical');
  assert.equal(resolveValue(' var( --color-accent ) ', SLOT.STYLE, r).spelling, 'var(--color-accent)');
  const media = resolveValue('url("media:ABC-123")', SLOT.STYLE, r);
  assert.deepEqual(media, { kind: KIND.MEDIA, id: 'abc-123', spelling: "url('media:abc-123')", record: null });
  assert.equal(resolveValue("url('generate: a calm lake ')", SLOT.STYLE, r).id, 'a calm lake');
  assert.equal(resolveValue('url(https://x/y.png)', SLOT.STYLE, r).kind, KIND.LITERAL, 'a file url is a literal the parser may refuse; it is no reference');
  assert.deepEqual(resolveValue('', SLOT.CLASS, r), { kind: KIND.LITERAL, id: null, spelling: '', record: null });
  assert.equal(resolveValue(null, SLOT.STYLE, r).spelling, '');
});

test('unknown is a reference-shaped value the roster lacks, never a literal', () => {
  const r = roster();
  assert.deepEqual(resolveValue('var(--swatch-limegreen)', SLOT.STYLE, r), { kind: KIND.UNKNOWN, id: '--swatch-limegreen', spelling: 'var(--swatch-limegreen)', record: null });
  assert.equal(resolveValue('hero', SLOT.CLASS, r).kind, KIND.UNKNOWN, 'slots are separate vocabularies: the stencil "hero" is no class');
  assert.equal(resolveValue('hero', SLOT.STENCIL, r).kind, KIND.STENCIL);
  assert.equal(resolveValue('full', attrSlot('kind:container', 'width'), r).id, 'container_full');
  assert.equal(resolveValue('full', attrSlot('kind:block', 'width'), r).id, 'block_full', 'an attribute value resolves in its scope: the same spelling on another scope is that scope\'s variant');
  assert.equal(resolveValue('full', attrSlot('kind:container', 'height'), r).kind, KIND.UNKNOWN);
  assert.equal(resolveValue('var(--color-accent, red)', SLOT.STYLE, r).kind, KIND.LITERAL, 'a var() with a fallback is a compound; its reference is listed by references()');
});

test('the animation slot resolves a keyframes name by its prefixed ident; the style slot never does', () => {
  const r = roster();
  assert.equal(resolveValue(' motion-fade_in ', SLOT.ANIMATION, r).id, 'fade_in');
  assert.equal(resolveValue('motion-fade_in', SLOT.ANIMATION, r).record, r.get('animation', 'fade_in'));
  assert.equal(resolveValue('spin', SLOT.ANIMATION, r).kind, KIND.UNKNOWN, 'a plain ident names no material; whether it is a theme keyframes is the consumer\'s rule');
  assert.equal(resolveValue('motion-fade_in', SLOT.STYLE, r).kind, KIND.LITERAL, 'a bare ident is property-scoped: only a consumer that knows the property is animation-* asks the animation slot');
  assert.deepEqual(references(' motion-fade_in ', SLOT.ANIMATION, r).map((ref) => [ref.kind, ref.id]), [['animation', 'fade_in']]);
  assert.equal(canonical(' motion-fade_in ', SLOT.ANIMATION, r), 'motion-fade_in');
  assert.equal(spell(r.get('animation', 'fade_in'), SLOT.ANIMATION), 'motion-fade_in');
});

test('references walks a compound style value and a class list in source order', () => {
  const r = roster();
  const refs = references("1px solid var(--swatch-lime_green), linear-gradient(var(--color-accent, red), url(x.png)), url('media:M1'), url('generate:sky')", SLOT.STYLE, r);
  assert.deepEqual(refs.map((ref) => [ref.kind, ref.id]), [
    ['swatch', 'lime_green'], ['token', 'color/accent'], ['media', 'm1'], ['generate', 'sky'],
  ]);
  assert.deepEqual(references('button  nope hero_glow', SLOT.CLASS, r).map((ref) => ref.kind), ['variant', 'unknown', 'variant']);
  assert.deepEqual(references('  ', SLOT.CLASS, r), []);
  assert.deepEqual(references('', attrSlot('kind:container', 'width'), r), []);
  assert.deepEqual(references(' full ', attrSlot('kind:block', 'width'), r).map((ref) => ref.id), ['block_full']);
  assert.equal(references(' hero ', SLOT.STENCIL, r)[0].kind, KIND.STENCIL);
  assert.deepEqual(references('red', SLOT.STYLE, r), [], 'a literal carries no reference');
});

test('spell reads the provider spelling and refuses a slot the material lacks', () => {
  const r = roster();
  assert.equal(spell(r.get('swatch', 'lime_green'), SLOT.STYLE), 'var(--swatch-lime_green)');
  assert.equal(spell(r.get('variant', 'container_full'), attrSlot('kind:container', 'width')), 'full');
  assert.equal(spell(r.get('variant', 'block_full'), attrSlot('kind:block', 'width')), 'full');
  assert.throws(() => spell(r.get('swatch', 'lime_green'), SLOT.CLASS), /swatch "lime_green" has no spelling in the class slot; it spells in: style\./);
  assert.throws(() => spell(record({ kind: 'variant', id: 'axis_only' }), SLOT.CLASS), /it spells in: \(no slot\)/);
  assert.equal(spellMedia(' ABC '), "url('media:abc')");
  assert.equal(spellGenerate('  a dog  '), "url('generate:a dog')");
});

test('the roster index: read surface and loud refusals', () => {
  const r = roster();
  assert.deepEqual(r.all().map((x) => x.id), ['color/accent', 'lime_green', 'button', 'container_full', 'block_full', 'hero_glow', 'link_quiet', 'hero', 'fade_in']);
  assert.deepEqual(r.ofKind('variant').map((x) => x.id), ['button', 'container_full', 'block_full', 'hero_glow', 'link_quiet']);
  assert.deepEqual(r.ofKind('nothing'), []);
  assert.ok(r.has('stencil', 'hero') && !r.has('class', 'hero'));
  assert.equal(r.get('token', 'nope'), null);
  assert.deepEqual(Object.keys(r.spellings(SLOT.CLASS)), ['button', 'hero_glow', 'link_quiet']);
  assert.deepEqual(r.spellings('attr:kind:container:nothing'), {});
  assert.deepEqual(Object.keys(r.spellings('attr:kind:container:width')), ['full']);
  assert.deepEqual(Object.keys(r.spellings('attr:kind:block:width')), ['full'], 'two scopes spell one value on one attribute name: two slots, no refusal');
  assert.throws(() => createRoster([RECORDS[3], record({ kind: 'variant', id: 'container_wide', label: 'Wide', spellings: { 'attr:kind:container:width': 'full' }, scope: 'kind:container' })]), /both spell "full" in the attr:kind:container:width slot/);
  assert.throws(() => createRoster([record({ kind: 'variant', id: 'x', label: 'X', spellings: { 'attr:kind:container:width': 'full' }, scope: 'kind:block' })]), /names scope "kind:container", but the record's scope is "kind:block"/);
  assert.throws(() => createRoster([record({ kind: 'variant', id: 'x', label: 'X', spellings: { 'attr:width': 'full' }, scope: 'kind:block' })]), /names scope "null"/);
  assert.ok(!r.isEmpty() && createRoster([]).isEmpty());
  assert.throws(() => createRoster([record({ kind: 'paint', id: 'x' })]), /material kind only/);
  assert.throws(() => createRoster([RECORDS[1], RECORDS[1]]), /Two swatch records share the id "lime_green"/);
  assert.throws(() => createRoster([RECORDS[2], record({ kind: 'stencil', id: 'button', spellings: { class: 'button' } })]), /both spell "button" in the class slot/);
});

test('applicability: scope family, root-only depth, stencil ownership', () => {
  const r = roster();
  const { applicable } = r;
  const button = r.get('variant', 'button');
  const full = r.get('variant', 'container_full');
  const glow = r.get('variant', 'hero_glow');
  const quiet = r.get('variant', 'link_quiet');
  assert.ok(applicable(button, 'block_type:text') && applicable(button, 'kind:block'));
  assert.ok(!applicable(button, 'block_type:image') && !applicable(button, 'kind:container'));
  assert.ok(applicable(full, 'layout:layout_onecol', true) && !applicable(full, 'layout:layout_onecol', false));
  assert.ok(applicable(button, 'block_type:text', false), 'depth gates root-only variants only');
  assert.ok(!applicable(glow, 'kind:container') && !applicable(glow, 'kind:container', true, 'call_to_action'));
  assert.ok(applicable(glow, 'kind:container', true, 'hero') && applicable(button, 'block_type:text', true, 'hero'));
  assert.ok(applicable(quiet, 'block_type:text') && applicable(quiet, 'layout:layout_onecol', false));
  assert.ok(applicable(r.get('swatch', 'lime_green'), 'block_type:image', false) && applicable(r.get('stencil', 'hero'), 'kind:container', false));
  assert.ok(applicable(r.get('animation', 'fade_in'), 'block_type:image', false), 'non-variant kinds apply everywhere');
  assert.ok(!applicable(record({ kind: 'variant', id: 'scopeless' }), 'kind:block'), 'a variant with no scope applies nowhere');
  assert.ok(rootOnly(full) && !rootOnly(button) && !rootOnly(r.get('stencil', 'hero')), 'rootOnly reads the depth stamp');
  assert.deepEqual(wears(r.get('stencil', 'hero')), [], 'a stencil captured with no wearings wears nothing');
});

test('applicability, the stencil case: a band (its root wears a root-only variant) places at top level only', () => {
  // Stencils beyond the reference set: a band wearing the root-only width
  // variant, a card wearing a plain style, one wearing a name no record
  // answers, and one wearing both a plain style and the root-only width.
  const stencil = (id, worn) => record({
    kind: 'stencil', id, label: id, spellings: { stencil: id }, root_kind: 'section', applies: { wears: worn },
  });
  const r = createRoster([
    ...RECORDS,
    stencil('band', ['container_full']),
    stencil('card', ['button']),
    stencil('orphan', ['retired_width']),
    stencil('dressed_band', ['button', 'container_full']),
  ]);
  const { applicable, isBand, rootOnlyWears } = r;
  const band = r.get('stencil', 'band');
  const card = r.get('stencil', 'card');
  const orphan = r.get('stencil', 'orphan');
  const dressed = r.get('stencil', 'dressed_band');
  assert.ok(applicable(band, 'kind:container', true) && !applicable(band, 'kind:container', false), 'a band places at top level, never nested');
  assert.ok(applicable(card, 'kind:container', true) && applicable(card, 'kind:container', false), 'a stencil wearing no root-only variant places anywhere');
  assert.ok(applicable(orphan, 'kind:container', false), 'a wearing that names no record has no opinion');
  assert.ok(applicable(r.get('stencil', 'hero'), 'kind:container', false), 'a stencil wearing nothing places anywhere');
  assert.ok(applicable(dressed, 'kind:container', true) && !applicable(dressed, 'kind:container', false), 'one root-only wearing among plain ones makes a band');
  assert.ok(applicable(band, 'kind:container', false, 'hero') === false, 'ownership never admits a band nested');
  assert.ok(isBand(band) && isBand(dressed) && !isBand(card) && !isBand(orphan) && !isBand(r.get('stencil', 'hero')));
  assert.ok(!isBand(r.get('variant', 'container_full')), 'only a stencil is ever a band');
  assert.deepEqual([...rootOnlyWears(dressed).keys()], ['container_full'], 'the root-only wearings are named, in wearing order');
  assert.equal(rootOnlyWears(dressed).get('container_full'), r.get('variant', 'container_full'), 'each names its variant record');
  assert.deepEqual([...rootOnlyWears(card).keys()], []);
  // The band fact is the ROSTER's: the same record in a roster whose width
  // variant is not root-only is no band.
  const relaxed = createRoster([
    ...RECORDS.filter((entry) => entry.id !== 'container_full'),
    record({ kind: 'variant', id: 'container_full', label: 'Full width', group: 'width', spellings: { 'attr:kind:container:width': 'full' }, scope: 'kind:container', origin: 'shipped' }),
    stencil('band', ['container_full']),
  ]);
  assert.ok(!relaxed.isBand(relaxed.get('stencil', 'band')) && relaxed.applicable(relaxed.get('stencil', 'band'), 'kind:container', false));
});

test('scopesPair: the family rule (MaterialsRosterTest::scopePairs)', () => {
  const cases = [
    ['block_type:text', 'block_type:text', true],
    ['kind:container', 'layout:layout_onecol', true],
    ['layout:layout_onecol', 'kind:container', true],
    ['layout:layout_onecol', 'layout:layout_twocol_section', false],
    ['kind:block', 'block_type:cta', true],
    ['block_type:cta', 'kind:block', true],
    ['block_plugin:menu', 'kind:block', true],
    ['block_type:cta', 'block_plugin:menu', false],
    ['block_type:cta', 'kind:container', false],
    ['kind:container', 'kind:block', false],
    ['base:a', 'block_type:cta', true],
    ['base:h2', 'layout:layout_onecol', true],
    ['block_type:cta', 'base:a', false],
    ['kind:field', 'kind:entity', false],
    ['kind:field', 'kind:field', true],
  ];
  for (const [variant, element, expected] of cases) {
    assert.equal(scopesPair(variant, element), expected, `${variant} vs ${element}`);
  }
});

test('canonical rewrites references only, byte-identical elsewhere', () => {
  const r = roster();
  assert.equal(canonical('linear-gradient(red, blue), url("media:ABC")', SLOT.STYLE, r), "linear-gradient(red, blue), url('media:abc')");
  assert.equal(canonical('var( --color-accent, red) url(x.png)', SLOT.STYLE, r), 'var(--color-accent, red) url(x.png)', 'a var() head normalizes, its fallback and a file url() stay');
  assert.equal(canonical("url(generate: a dog )", SLOT.STYLE, r), "url('generate:a dog')");
  assert.equal(canonical('  button   nope ', SLOT.CLASS, r), 'button nope', 'a class list is its tokens, unknown ones kept');
  assert.equal(canonical(' hero ', SLOT.STENCIL, r), 'hero');
  assert.equal(canonical('', SLOT.STYLE, r), '');
});
