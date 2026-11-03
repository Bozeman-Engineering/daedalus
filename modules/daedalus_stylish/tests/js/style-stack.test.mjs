/**
 * Self-asserting tests for the style stack (js/src/style-stack.js): the
 * builder's layer order, part suffix and fragment resolution, the base
 * layer keyed by the read subject's tag from the document (an element's
 * root tag, a part's declared tag, none for a rule), the ancestor tier
 * over the document tree (the host entity above its sections: an entity
 * rule is the farthest ancestor layer, a field-keyed row carries its
 * block's rules beneath the sections'),
 * the session overlay, the write address's editable layer, raw styles; layersAt's
 * index winners and lost marks; stackAt's cascade across layers and
 * conditions, own against inherited.
 *
 * Parity with the server's StyleStack is StackEquivalenceTest's business
 * (stack-cases.mjs); this suite pins the client-only reads and the
 * shapes the rows and the code view consume.
 *
 * Run directly (`node --test tests/js/style-stack.test.mjs`) or via the
 * suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  TIER_ANCESTOR, TIER_DECLARED, TIER_ELEMENT, TIER_SKIN, TIER_VARIANT, buildStack, conditionValues, deliveredEntry, editableLayer, layerAt, layersAt, offerTag, selectorSuffixes, skinScopesAt, stackAt, subjectTag,
} from '../../js/src/style-stack.js';
import { variantRecord } from './materials-fixture.mjs';

const UUID = '11111111-1111-1111-1111-111111111111';
const KEY = `block:${UUID}`;
const QUOTE = `${KEY}::.testimonial__quote`;
const CHILD = 'block:22222222-2222-2222-2222-222222222222';

const CONDITIONS = {
  hover: { label: 'Hover', axis: 'state' },
  medium: { label: 'Medium', axis: 'width' },
  narrow: { label: 'Narrow', axis: 'width' },
  dark: { label: 'Dark', axis: 'scheme' },
};

const GUIDE = {
  conditions: CONDITIONS,
  declaredDefaults: {
    'kind:block': { '': { default: { margin: '0' } } },
    'block_type:testimonial': {
      '': { default: { display: 'block', padding: '0.5rem' } },
      '::.testimonial__quote': { default: { 'font-style': 'italic' } },
    },
  },
  skinDefaults: {
    'base:h2': { '': { default: { 'font-size': '2rem' } } },
    'kind:block': { '': { default: { padding: '0.75rem' }, narrow: { padding: '0.25rem' } } },
    'block_type:testimonial': { '': { default: { 'background-color': '#fff' } } },
    'base:p': { '': { default: { 'line-height': '1.6' } } },
  },
};

const VARIANTS = [
  variantRecord('sleek', {
    label: 'Sleek',
    scope: 'block_type:testimonial',
    description: 'Quiet.',
    value: {
      '': { default: { padding: '1rem', color: '#111' }, hover: { color: '#333' }, variants: [] },
      '::.testimonial__quote': { default: { 'font-size': '1.5rem' } },
    },
  }),
  variantRecord('loud', {
    label: 'Loud',
    group: 'emphasis',
    scope: 'block_type:testimonial',
    value: { '': { default: { padding: '2rem', 'font-weight': 'bold' }, dark: { color: '#eee' } } },
  }),
  variantRecord('duo', {
    label: 'Duo',
    scope: 'kind:container',
    value: {
      '': { default: { gap: '1rem' } },
      'block(testimonial,1)': { default: { color: 'red' }, variants: [{ variant: 'sleek', fragment: '' }] },
      'block(testimonial,1)::.testimonial__quote': { default: { color: 'crimson' } },
    },
  }),
  variantRecord('card_left', { label: 'Card Left', scope: 'kind:container' }),
];

const BLOCK_TYPES = { [UUID]: 'testimonial' };
const AXES = ['style', 'emphasis'];

const build = (overrides = {}) => buildStack({
  guide: GUIDE,
  map: {},
  key: KEY,
  variants: VARIANTS,
  axes: AXES,
  blockTypes: BLOCK_TYPES,
  ...overrides,
});

const ids = (stack) => stack.layers.map((layer) => layer.id);

// --- conditionValues ---------------------------------------------------------

test('conditionValues reads values only: reserved keys, nulls and nested values drop, strings trim', () => {
  assert.deepEqual(conditionValues({
    variants: [{ variant: 'x', fragment: '' }],
    default: { padding: ' 1rem ', color: null, nested: { a: 1 }, size: 12 },
    hover: 'not-a-block',
    narrow: {},
  }), { default: { padding: '1rem', size: '12' } });
  assert.deepEqual(conditionValues(null), {});
  assert.deepEqual(conditionValues([]), {});
});

// --- buildStack: layer order and identity -------------------------------------

test('the layers stack bottom to top: declared tiers, skin tiers, worn references in delivery order, own', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: 'loud', fragment: '' }, { variant: 'sleek', fragment: '' }],
      default: { padding: '3rem' },
    },
  };
  const stack = build({ map });
  assert.deepEqual(ids(stack), [
    'declared:kind:block',
    'declared:block_type:testimonial',
    'skin:kind:block',
    'skin:block_type:testimonial',
    'variant:sleek',
    'variant:loud',
    'element',
  ]);
  assert.deepEqual(stack.layers.map((layer) => layer.tier), [
    TIER_DECLARED, TIER_DECLARED, TIER_SKIN, TIER_SKIN, TIER_VARIANT, TIER_VARIANT, TIER_ELEMENT,
  ]);
  assert.deepEqual(stack.layers.map((layer) => layer.editable), [false, false, false, false, false, false, true]);
  assert.deepEqual({ key: stack.key, rootKey: stack.rootKey, relative: stack.relative, scope: stack.scope }, {
    key: KEY, rootKey: KEY, relative: '', scope: 'block_type:testimonial',
  });
  assert.deepEqual(stack.baseTags, ['h2', 'p']);
  assert.equal(stack.skin, true);
});

test('a tier layer carries its declaring scope; a variant layer its roster facts', () => {
  const map = { [KEY]: { variants: [{ variant: 'sleek', fragment: '' }] } };
  const stack = build({ map });
  assert.deepEqual(stack.layers[1], {
    id: 'declared:block_type:testimonial',
    tier: 'declared',
    scope: 'block_type:testimonial',
    variant: null,
    fragment: null,
    via: null,
    viaLabel: null,
    label: null,
    description: null,
    axis: null,
    resolved: true,
    editable: false,
    carrier: null,
    styles: { default: { display: 'block', padding: '0.5rem' } },
  });
  assert.deepEqual(stack.layers[4], {
    id: 'variant:sleek',
    tier: 'variant',
    scope: 'block_type:testimonial',
    variant: 'sleek',
    fragment: '',
    via: null,
    viaLabel: null,
    label: 'Sleek',
    description: 'Quiet.',
    axis: 'style',
    resolved: true,
    editable: false,
    // The address wearing the reference: the key's own root entry.
    carrier: { key: KEY, selector: null },
    // Raw as stored: both conditions, the nested reference list stripped.
    styles: { default: { padding: '1rem', color: '#111' }, hover: { color: '#333' } },
  });
});

test('styles are raw: every stored condition rides its layer, no cascade, no marks', () => {
  const map = { [KEY]: { default: { padding: '3rem' }, 'narrow+dark': { padding: '0' }, variants: [] } };
  const stack = build({ map });
  const own = stack.layers[stack.layers.length - 1];
  assert.deepEqual(own.styles, { default: { padding: '3rem' }, 'narrow+dark': { padding: '0' } });
  assert.deepEqual(stack.layers[2].styles, { default: { padding: '0.75rem' }, narrow: { padding: '0.25rem' } });
});

test('a tier that declares nothing at the key is not a layer; the own layer always is', () => {
  const stack = buildStack({
    guide: GUIDE,
    map: {},
    key: 'entity:33333333-3333-3333-3333-333333333333',
    variants: VARIANTS,
  });
  assert.deepEqual(ids(stack), ['element']);
  assert.deepEqual(stack.layers[0].styles, {});
  assert.equal(stack.scope, 'kind:entity');
});

test('without the floor only the map layers stack; the skin facts still ride the stack', () => {
  const map = { [KEY]: { variants: [{ variant: 'sleek', fragment: '' }] } };
  const stack = build({ map, floor: false });
  assert.deepEqual(ids(stack), ['variant:sleek', 'element']);
  assert.equal(stack.skin, true);
  assert.deepEqual(stack.baseTags, ['h2', 'p']);
});

test('no skin in the payload: no skin layers, no base tags, skin false; the declared tier stays', () => {
  const stack = build({ guide: { ...GUIDE, skinDefaults: [] } });
  assert.deepEqual(ids(stack), ['declared:kind:block', 'declared:block_type:testimonial', 'element']);
  assert.equal(stack.skin, false);
  assert.deepEqual(stack.baseTags, []);
});

// --- buildStack: the base tag --------------------------------------------------

// The block alone in a document under a keyless root (a host with no
// uuid: structure nothing addresses), rendering as `tag` with the given
// declared parts (the annotation's markup facts the read subject's tag
// comes from).
const rooted = (tag, parts = {}) => [
  {
    kind: 'entity',
    uuid: null,
    _element: null,
    children: [
      { kind: 'block', uuid: UUID, block_type: 'testimonial', _element: { markup: { tag, parts } } },
    ],
  },
];

test('the document\'s rendered tag adds the skin base layer beneath the skin scoped blocks; an uncarried or unknown tag, or no document, adds nothing', () => {
  assert.deepEqual(ids(build({ document: rooted('h2') })), [
    'declared:kind:block',
    'declared:block_type:testimonial',
    'skin:base:h2',
    'skin:kind:block',
    'skin:block_type:testimonial',
    'element',
  ]);
  const base = build({ document: rooted('h2') }).layers[2];
  assert.equal(base.tier, TIER_SKIN);
  assert.equal(base.scope, 'base:h2');
  assert.deepEqual(base.styles, { default: { 'font-size': '2rem' } });
  assert.deepEqual(ids(build({ document: rooted('div') })), ids(build()));
  assert.deepEqual(ids(build({ document: rooted(null) })), ids(build()));
  assert.deepEqual(ids(build({ document: [] })), ids(build()));
  assert.equal(ids(build()).some((id) => id.startsWith('skin:base:')), false);
  assert.equal(subjectTag(rooted('h2'), KEY), 'h2');
  assert.equal(subjectTag(null, KEY), null);
  assert.equal(subjectTag(rooted('h2'), CHILD), null);
});

test('offerTag: the subject\'s tag where there is one, a tag rule\'s own tag, nothing for a type rule or the children', () => {
  const document = rooted('h2', { '.testimonial__quote': { tag: 'p' } });
  assert.equal(offerTag(document, KEY), 'h2');
  assert.equal(offerTag(document, QUOTE), 'p');
  assert.equal(offerTag(document, `${KEY}::a`), 'a');
  assert.equal(offerTag(document, `${KEY}::> d-text a`), 'a');
  assert.equal(offerTag(document, `${KEY}::> d-text`), null);
  assert.equal(offerTag(document, `${KEY}::> *`), null);
  // A tag rule answers its tag without a document; an element without one
  // has no subject tag to answer.
  assert.equal(offerTag(null, `${KEY}::a`), 'a');
  assert.equal(offerTag(null, KEY), null);
});

test('a part key reads its own declared tag at the root position, never the root\'s; a tagless part, a tag tier and a chain read no base layer', () => {
  const document = rooted('h2', { '.testimonial__quote': { tag: 'p' } });
  const part = build({ key: QUOTE, document });
  assert.deepEqual(ids(part), ['declared:block_type:testimonial', 'skin:base:p', 'element']);
  assert.deepEqual(part.layers[1].styles, { default: { 'line-height': '1.6' } });
  assert.equal(subjectTag(document, QUOTE), 'p');
  // The part declares no tag: its subject's tag is unknown.
  assert.deepEqual(ids(build({ key: QUOTE, document: rooted('h2', { '.testimonial__quote': {} }) })), ['declared:block_type:testimonial', 'element']);
  assert.equal(subjectTag(rooted('h2', { '.testimonial__quote': {} }), QUOTE), null);
  // A rule has no one subject: the tag tier reads no base:p for all the
  // skin carries one, nor does the children chain.
  assert.equal(ids(build({ key: `${KEY}::p`, document })).some((id) => id.startsWith('skin:base:')), false);
  assert.equal(ids(build({ key: `${KEY}::> *`, document })).some((id) => id.startsWith('skin:base:')), false);
  assert.equal(subjectTag(document, `${KEY}::p`), null);
  assert.equal(subjectTag(document, `${KEY}::> *`), null);
  assert.equal(subjectTag(document, `${KEY}::> d-text .testimonial__quote`), null);
  // A floorless read carries no base layer whatever the document says.
  assert.deepEqual(ids(build({ document, floor: false })), ['element']);
});

test('a generated box has no subject: no base layer for the owner\'s own box nor a tagged part\'s, and no base offer behind a tag', () => {
  const document = rooted('h2', { '.testimonial__quote': { tag: 'p' } });
  // The box renders as no tag: the skin's base:h2 and base:p blocks
  // style the host and the part, never their boxes.
  assert.deepEqual(ids(build({ key: `${KEY}::&::before`, document })), ['element']);
  assert.deepEqual(ids(build({ key: `${QUOTE}::after`, document })), ['element']);
  assert.equal(subjectTag(document, `${KEY}::&::before`), null);
  assert.equal(subjectTag(document, `${QUOTE}::after`), null);
  assert.equal(subjectTag(document, `${KEY}::p::before`), null);
  assert.equal(offerTag(document, `${KEY}::&::after`), null);
  assert.equal(offerTag(document, `${QUOTE}::before`), null);
  assert.equal(offerTag(document, `${KEY}::a::before`), null);
  assert.equal(offerTag(document, `${KEY}::> d-text a::after`), null);
});

// --- buildStack: parts, fragments, tags, children ----------------------------

test('a part key resolves through the ROOT wearing at its own suffix, own part entry on top', () => {
  const map = {
    [KEY]: { variants: [{ variant: 'loud', fragment: '' }, { variant: 'sleek', fragment: '' }] },
    [QUOTE]: { default: { color: 'blue' } },
  };
  const stack = build({ map, key: QUOTE });
  assert.deepEqual({ rootKey: stack.rootKey, relative: stack.relative, scope: stack.scope }, {
    rootKey: KEY, relative: '::.testimonial__quote', scope: 'block_type:testimonial',
  });
  assert.deepEqual(ids(stack), ['declared:block_type:testimonial', 'variant:sleek', 'variant:loud', 'element']);
  assert.deepEqual(stack.layers[0].styles, { default: { 'font-style': 'italic' } });
  assert.deepEqual(stack.layers[1].styles, { default: { 'font-size': '1.5rem' } });
  assert.deepEqual(stack.layers[2].styles, {}, 'a reference supplying nothing at the part is still a layer, empty');
  assert.deepEqual(stack.layers[3].styles, { default: { color: 'blue' } });
});

test('a distributed {variant, fragment} reference resolves ITS fragment at the relative position', () => {
  const map = {
    [CHILD]: { variants: [{ variant: 'duo', fragment: 'block(testimonial,1)' }] },
    [`${CHILD}::.testimonial__quote`]: { default: { 'font-size': '1rem' } },
  };
  const root = build({ map, key: CHILD, blockTypes: {} });
  assert.deepEqual(ids(root), ['declared:kind:block', 'skin:kind:block', 'variant:duo', 'element'], 'an unresolved bundle reads the kind tiers alone');
  assert.equal(root.layers[2].fragment, 'block(testimonial,1)');
  assert.deepEqual(root.layers[2].styles, { default: { color: 'red' } }, 'the nested reference list is not a value');
  const part = build({ map, key: `${CHILD}::.testimonial__quote`, blockTypes: {} });
  assert.deepEqual(ids(part), ['variant:duo', 'element']);
  assert.deepEqual(part.layers[0].styles, { default: { color: 'crimson' } });
});

test('::p and ::> * keys are plain suffixes: they resolve like parts and capture the root scope', () => {
  const map = {
    [KEY]: { variants: [{ variant: 'sleek', fragment: '' }] },
    [`${KEY}::p`]: { default: { 'line-height': '1.4' } },
    [`${KEY}::> *`]: { default: { 'min-width': '0' } },
  };
  const tagged = build({ map, key: `${KEY}::p` });
  assert.deepEqual({ relative: tagged.relative, scope: tagged.scope }, { relative: '::p', scope: 'block_type:testimonial' });
  assert.deepEqual(ids(tagged), ['variant:sleek', 'element']);
  assert.deepEqual(tagged.layers[0].styles, {});
  assert.deepEqual(tagged.layers[1].styles, { default: { 'line-height': '1.4' } });
  const children = build({ map, key: `${KEY}::> *` });
  assert.equal(children.relative, '::> *');
  assert.deepEqual(children.layers[children.layers.length - 1].styles, { default: { 'min-width': '0' } });
});

test('a stale reference is a layer that resolves nothing; a via wearing names its origin', () => {
  const map = {
    [KEY]: {
      variants: [
        { variant: 'ghost', fragment: '' },
        { variant: 'sleek', fragment: '', via: 'card_left' },
        { variant: 'loud', fragment: '', via: 'gone' },
      ],
    },
  };
  const stack = build({ map, floor: false });
  assert.deepEqual(ids(stack), ['variant:sleek', 'variant:loud', 'variant:ghost', 'element'], 'unplaceable references sort last');
  const [sleek, loud, ghost] = stack.layers;
  assert.deepEqual({ via: sleek.via, viaLabel: sleek.viaLabel }, { via: 'card_left', viaLabel: 'Card Left' });
  assert.deepEqual({ via: loud.via, viaLabel: loud.viaLabel }, { via: 'gone', viaLabel: null }, 'a stale origin is inert, never hidden');
  assert.deepEqual(ghost, {
    id: 'variant:ghost',
    tier: 'variant',
    scope: null,
    variant: 'ghost',
    fragment: '',
    via: null,
    viaLabel: null,
    label: null,
    description: null,
    axis: null,
    resolved: false,
    editable: false,
    carrier: { key: KEY, selector: null },
    styles: {},
  });
});

test('a composition pair is two layers with one id, so winners are indexes', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: 'duo', fragment: 'block(testimonial,1)' }, { variant: 'duo', fragment: '' }],
    },
  };
  const stack = build({ map, floor: false, axes: ['style'] });
  assert.deepEqual(ids(stack), ['variant:duo', 'variant:duo', 'element']);
  // Roots before fragments within an axis: the fragment's captured
  // overrides deliver over the variant nested within it.
  assert.deepEqual(stack.layers.map((layer) => layer.fragment), ['', 'block(testimonial,1)', null]);
  assert.deepEqual(stack.layers[0].styles, { default: { gap: '1rem' } });
  assert.deepEqual(stack.layers[1].styles, { default: { color: 'red' } });
});

// --- buildStack: the session overlay -----------------------------------------

test('an open session makes the edited reference the editable layer carrying the staged document', () => {
  const map = { [KEY]: { variants: [{ variant: 'sleek', fragment: '' }], default: { padding: '3rem' } } };
  const session = {
    reference: 'sleek',
    pending: {
      kind: 'update',
      moves: [],
      lines: [
        { id: 'p1', relative: '', condition: 'default', property: 'padding', value: '4rem' },
        { id: 'p2', relative: '', condition: 'default', property: 'color', value: null },
        { id: 'p3', relative: '', condition: 'narrow', property: 'gap', value: ' 2px ' },
        { id: 'p4', relative: '::.testimonial__quote', condition: 'default', property: 'color', value: 'red' },
      ],
    },
  };
  const stack = build({ map, floor: false, session });
  assert.deepEqual(ids(stack), ['variant:sleek', 'element']);
  assert.deepEqual(stack.layers.map((layer) => layer.editable), [true, false]);
  assert.equal(editableLayer(stack), 0);
  assert.deepEqual(stack.layers[0].styles, {
    default: { padding: '4rem' },
    hover: { color: '#333' },
    narrow: { gap: '2px' },
  }, 'the set replaces, the unset removes, other slots stay out');
  assert.deepEqual(stack.layers[1].styles, { default: { padding: '3rem' } });

  // The part key reads the document at its own slot.
  const part = build({ map, key: QUOTE, floor: false, session });
  assert.deepEqual(part.layers[0].styles, { default: { 'font-size': '1.5rem', color: 'red' } });
  assert.equal(part.layers[0].editable, true);

  // A session on a reference this root does not wear leaves the own layer editable.
  const elsewhere = build({ map, floor: false, session: { reference: 'loud', pending: session.pending } });
  assert.deepEqual(elsewhere.layers.map((layer) => layer.editable), [false, true]);

  // No pending session: the stored document is the editable layer.
  const stored = build({ map, floor: false, session: { reference: 'sleek', pending: null } });
  assert.deepEqual(stored.layers[0].styles, { default: { padding: '1rem', color: '#111' }, hover: { color: '#333' } });
  assert.equal(stored.layers[0].editable, true);
});

test('an open skin session makes its scope\'s skin layer the editable document, the own layer yielding, the staged document at the slot; a scope this key does not read leaves the own layer editable', () => {
  const pending = {
    kind: 'skin',
    moves: [],
    scope: 'base:h2',
    lines: [
      { id: 'p1', relative: '', condition: 'default', property: 'font-size', value: '3rem' },
      { id: 'p2', relative: '', condition: 'narrow', property: 'font-size', value: '2.5rem' },
    ],
  };
  const map = { [KEY]: { default: { padding: '3rem' } } };
  const stack = build({ map, document: rooted('h2'), session: { reference: 'skin:base:h2', pending } });
  assert.deepEqual(ids(stack), ['declared:kind:block', 'declared:block_type:testimonial', 'skin:base:h2', 'skin:kind:block', 'skin:block_type:testimonial', 'element']);
  assert.deepEqual(stack.layers.map((layer) => layer.editable), [false, false, true, false, false, false]);
  assert.equal(editableLayer(stack), 2);
  assert.deepEqual(stack.layers[2].styles, { default: { 'font-size': '3rem' }, narrow: { 'font-size': '2.5rem' } }, 'the staged set replaces the stored value, a staged condition joins');
  // The wearer's own entry sits above the document: the read says so.
  assert.equal(stackAt(stack, 'padding', 'default', CONDITIONS).winner.layer, 5);
  assert.equal(stackAt(stack, 'font-size', 'default', CONDITIONS).own.value, '3rem');

  // An element scope's document reads at the key's relative: the part
  // key reads its own slot, empty here, and the layer still stands as
  // the editable one (a worn reference's layer does too).
  const kind = { reference: 'skin:kind:block', pending: { kind: 'skin', moves: [], scope: 'kind:block', lines: [] } };
  const part = build({ map, key: QUOTE, session: kind });
  const kindLayer = part.layers.find((layer) => layer.id === 'skin:kind:block');
  assert.deepEqual(kindLayer.styles, {});
  assert.equal(kindLayer.editable, true);
  assert.equal(part.layers[part.layers.length - 1].editable, false);
  const root = build({ map, session: kind });
  assert.deepEqual(root.layers.find((layer) => layer.id === 'skin:kind:block').styles, { default: { padding: '0.75rem' }, narrow: { padding: '0.25rem' } });

  // No stored value for a staged property: the staged line joins the
  // stored ones as the document.
  const minted = build({ map, document: rooted('p'), session: { reference: 'skin:base:p', pending: { kind: 'skin', moves: [], scope: 'base:p', lines: [{ id: 'x', relative: '', condition: 'default', property: 'color', value: 'red' }] } } });
  assert.deepEqual(minted.layers.find((layer) => layer.id === 'skin:base:p').styles, { default: { 'line-height': '1.6', color: 'red' } });

  // A scope this key does not read (the document says the tag is a div, the
  // scope is a paragraph's): no document layer, the own layer editable.
  const elsewhere = build({ map, document: rooted('div'), session: { reference: 'skin:base:p', pending: null } });
  assert.equal(elsewhere.layers.some((layer) => layer.id === 'skin:base:p'), false);
  assert.equal(elsewhere.layers[elsewhere.layers.length - 1].editable, true);
  // A floorless build carries no skin document.
  assert.deepEqual(ids(build({ map, floor: false, session: { reference: 'skin:base:h2', pending } })), ['element']);
});

test('skinScopesAt answers the scopes a key\'s stack reads skin blocks for: the subject\'s tag, the family kind, the refinement', () => {
  assert.deepEqual(skinScopesAt({ key: KEY, blockTypes: BLOCK_TYPES, document: rooted('h2') }), ['base:h2', 'kind:block', 'block_type:testimonial']);
  assert.deepEqual(skinScopesAt({ key: KEY, blockTypes: BLOCK_TYPES }), ['kind:block', 'block_type:testimonial']);
  // The tag's scope answers whatever the tag (the skin may mint a block
  // for it later; the doorway itself only ever offers a scope the skin
  // delivers), the stack's base layer keying on the skin's block.
  assert.deepEqual(skinScopesAt({ key: KEY, blockTypes: BLOCK_TYPES, document: rooted('div') }), ['base:div', 'kind:block', 'block_type:testimonial']);
  // A part facet reads the part's tag.
  assert.deepEqual(skinScopesAt({ key: QUOTE, blockTypes: BLOCK_TYPES, document: rooted('h2', { '.testimonial__quote': { tag: 'p' } }) }), ['base:p', 'kind:block', 'block_type:testimonial']);
  assert.deepEqual(skinScopesAt({ key: 'bogus' }), []);
});

// --- layersAt ------------------------------------------------------------------

test('layersAt marks index winners at one exact condition; the last defining layer wins', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: 'loud', fragment: '' }, { variant: 'sleek', fragment: '' }],
      default: { padding: '3rem' },
    },
  };
  const stack = build({ map });
  // declared:kind:block(0) declared:testimonial(1) skin:kind:block(2)
  // skin:testimonial(3) sleek(4) loud(5) element(6).
  assert.deepEqual(layersAt(stack, 'default'), [
    { layer: 0, property: 'margin', value: '0', lost: false, winner: null },
    { layer: 1, property: 'display', value: 'block', lost: false, winner: null },
    { layer: 1, property: 'padding', value: '0.5rem', lost: true, winner: 6 },
    { layer: 2, property: 'padding', value: '0.75rem', lost: true, winner: 6 },
    { layer: 3, property: 'background-color', value: '#fff', lost: false, winner: null },
    { layer: 4, property: 'padding', value: '1rem', lost: true, winner: 6 },
    { layer: 4, property: 'color', value: '#111', lost: false, winner: null },
    { layer: 5, property: 'padding', value: '2rem', lost: true, winner: 6 },
    { layer: 5, property: 'font-weight', value: 'bold', lost: false, winner: null },
    { layer: 6, property: 'padding', value: '3rem', lost: false, winner: null },
  ]);
  // Conditions stay distinct: no cascade at an exact read.
  assert.deepEqual(layersAt(stack, 'hover'), [
    { layer: 4, property: 'color', value: '#333', lost: false, winner: null },
  ]);
  assert.deepEqual(layersAt(stack, 'narrow'), [
    { layer: 2, property: 'padding', value: '0.25rem', lost: false, winner: null },
  ]);
  assert.deepEqual(layersAt(stack, 'medium'), []);
  assert.equal(layerAt(stack, 6).id, 'element');
  assert.equal(layerAt(stack, 9), null);
  assert.equal(layerAt(stack, null), null);
});

test('deliveredEntry projects layersAt per stored condition: the winning value per property, every tier included', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: 'loud', fragment: '' }, { variant: 'sleek', fragment: '' }],
      default: { padding: '3rem' },
    },
  };
  // The advanced rows' roster: a style that applies shows, whichever
  // layer sets it (the declared margin, the skin's background, Sleek's
  // hover colour), and the winner's value is the one carried.
  assert.deepEqual(deliveredEntry(build({ map })), {
    default: {
      margin: '0',
      display: 'block',
      padding: '3rem',
      'background-color': '#fff',
      color: '#111',
      'font-weight': 'bold',
    },
    narrow: { padding: '0.25rem' },
    hover: { color: '#333' },
    dark: { color: '#eee' },
  });
  // Floorless: the map's layers only; an empty stack delivers nothing.
  assert.deepEqual(Object.keys(deliveredEntry(build({ map, floor: false }))), ['default', 'hover', 'dark']);
  assert.deepEqual(deliveredEntry(build({ floor: false })), {});
});

test('layersAt on a composition pair tells the two same-id layers apart by index', () => {
  const pair = [
    variantRecord('duo', {
      scope: 'kind:container',
      value: { '': { default: { color: 'a' } }, 'block(testimonial,1)': { default: { color: 'b' } } },
    }),
  ];
  const map = {
    [KEY]: { variants: [{ variant: 'duo', fragment: '' }, { variant: 'duo', fragment: 'block(testimonial,1)' }] },
  };
  const stack = buildStack({ guide: {}, map, key: KEY, variants: pair, axes: ['style'] });
  assert.deepEqual(layersAt(stack, 'default'), [
    { layer: 0, property: 'color', value: 'a', lost: true, winner: 1 },
    { layer: 1, property: 'color', value: 'b', lost: false, winner: null },
  ]);
});

// --- stackAt -------------------------------------------------------------------

test('stackAt: the topmost delivering layer wins at the pose; beneath lists the rest nearest first', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: 'loud', fragment: '' }, { variant: 'sleek', fragment: '' }],
      default: { padding: '3rem' },
    },
  };
  const stack = build({ map });
  const read = stackAt(stack, 'padding', 'default', CONDITIONS);
  assert.deepEqual(read.winner, { layer: 6, id: 'element', tier: 'element', variant: null, condition: 'default', value: '3rem' });
  assert.deepEqual(read.beneath.map((entry) => [entry.id, entry.value]), [
    ['variant:loud', '2rem'],
    ['variant:sleek', '1rem'],
    ['skin:kind:block', '0.75rem'],
    ['declared:block_type:testimonial', '0.5rem'],
  ]);
  assert.deepEqual(read.own, read.winner);

  // Inherited: a variant's value wins where the own layer sets nothing.
  const weight = stackAt(stack, 'font-weight', 'default', CONDITIONS);
  assert.deepEqual(weight.winner, { layer: 5, id: 'variant:loud', tier: 'variant', variant: 'loud', condition: 'default', value: 'bold' });
  assert.deepEqual(weight.beneath, []);
  assert.equal(weight.own, null);

  // The skin's value is a lit winner like any other layer's.
  const bg = stackAt(stack, 'background-color', 'default', CONDITIONS);
  assert.deepEqual(bg.winner, { layer: 3, id: 'skin:block_type:testimonial', tier: 'skin', variant: null, condition: 'default', value: '#fff' });

  // Nothing delivers: honestly unset.
  assert.deepEqual(stackAt(stack, 'gap', 'default', CONDITIONS), { winner: null, beneath: [], own: null });
});

test('stackAt cascades within each layer: a posed compound reads subset blocks, default everywhere', () => {
  const map = {
    [KEY]: {
      variants: [{ variant: 'loud', fragment: '' }, { variant: 'sleek', fragment: '' }],
      default: { padding: '3rem' },
      medium: { padding: '2.5rem' },
    },
  };
  const stack = build({ map });
  // At Narrow the own default still delivers (a Base value posed at
  // Narrow is the row's lit value, own hue); the skin's narrow block
  // sits beneath it.
  const narrow = stackAt(stack, 'padding', 'narrow', CONDITIONS);
  assert.deepEqual(narrow.winner, { layer: 6, id: 'element', tier: 'element', variant: null, condition: 'default', value: '3rem' });
  assert.deepEqual(narrow.own, narrow.winner);
  assert.deepEqual(narrow.beneath.map((entry) => [entry.id, entry.condition, entry.value]), [
    ['variant:loud', 'default', '2rem'],
    ['variant:sleek', 'default', '1rem'],
    ['skin:kind:block', 'narrow', '0.25rem'],
    ['declared:block_type:testimonial', 'default', '0.5rem'],
  ]);
  // At Medium the own medium block is the exact pose's own value.
  const medium = stackAt(stack, 'padding', 'medium', CONDITIONS);
  assert.deepEqual(medium.own, { layer: 6, id: 'element', tier: 'element', variant: null, condition: 'medium', value: '2.5rem' });
  // A compound pose reads its subset blocks: Narrow + Dark still sees
  // Loud's dark colour and the skin's narrow padding.
  const color = stackAt(stack, 'color', 'narrow+dark', CONDITIONS);
  assert.deepEqual(color.winner, { layer: 5, id: 'variant:loud', tier: 'variant', variant: 'loud', condition: 'dark', value: '#eee' });
  assert.deepEqual(color.beneath.map((entry) => [entry.id, entry.condition, entry.value]), [['variant:sleek', 'default', '#111']]);
  // Hover is not a subset of Narrow: Sleek's hover colour never reaches it.
  assert.equal(stackAt(stack, 'color', 'narrow', CONDITIONS).winner.value, '#111');
  assert.equal(stackAt(stack, 'color', 'hover', CONDITIONS).winner.value, '#333');
});

test('stackAt reads own from the editable layer: the session document in a session', () => {
  const map = { [KEY]: { variants: [{ variant: 'sleek', fragment: '' }], default: { padding: '3rem' } } };
  const session = {
    reference: 'sleek',
    pending: { kind: 'update', moves: [], lines: [{ id: 'p1', relative: '', condition: 'default', property: 'padding', value: '4rem' }] },
  };
  const stack = build({ map, floor: false, session });
  const read = stackAt(stack, 'padding', 'default', CONDITIONS);
  // The wearer's own layer still wins delivery; the document's value is
  // the editable layer's, beneath it.
  assert.deepEqual(read.winner, { layer: 1, id: 'element', tier: 'element', variant: null, condition: 'default', value: '3rem' });
  assert.deepEqual(read.own, { layer: 0, id: 'variant:sleek', tier: 'variant', variant: 'sleek', condition: 'default', value: '4rem' });
  assert.deepEqual(read.beneath, [read.own]);
});

// --- buildStack: the ancestor tier -------------------------------------------

// A page ("Welcome", the host entity, keyed by the envelope's root uuid
// and tagless as an entity kind's markup usually is): section A
// (labelled) holding section B holding the testimonial block T (a
// paragraph root whose prose links and carries paragraphs) and a second
// block U, whose open-render `body` field mints one paragraph
// (`.s-abc`), the row an open render hangs under the FIELD key. The
// layout is the serializer's shape; the document is the matcher's view
// of it (documentTree): the entity above the sections.
const PAGE = 'entity:eeeeeeee-0000-4000-8000-00000000000e';
const SEC_A = 'container:aaaaaaaa-0000-4000-8000-00000000000a';
const SEC_B = 'container:bbbbbbbb-0000-4000-8000-00000000000b';
const U = 'block:44444444-4444-4444-4444-444444444444';
const FIELD_ROW = `${U}:body::.s-abc`;
const LAYOUT = [
  {
    kind: 'container',
    uuid: SEC_A.slice('container:'.length),
    layout_id: 'daedalus_stylish_container',
    label: 'Hero',
    _element: { name: 'Hero', type: 'Section' },
    children: [
      {
        kind: 'container',
        uuid: SEC_B.slice('container:'.length),
        layout_id: 'daedalus_stylish_container',
        _element: { name: 'Container', type: 'Container' },
        children: [
          { kind: 'block', uuid: UUID, block_type: 'testimonial', _element: { name: 'Testimonial', type: 'Testimonial', markup: { tag: 'p', tags: ['a', 'p'] } } },
          {
            kind: 'block',
            uuid: U.slice('block:'.length),
            block_type: 'testimonial',
            _element: {
              name: 'Testimonial',
              type: 'Testimonial',
              markup: { tag: 'p' },
              fields: { body: { markup: { tags: ['p'], instances: [{ class: 's-abc', tag: 'p', label: 'Paragraph', children: [] }] } } },
            },
          },
        ],
      },
    ],
  },
];
const DOCUMENT = [
  {
    kind: 'entity',
    uuid: PAGE.slice('entity:'.length),
    _element: { name: 'Welcome', type: 'Page', icon: '/icons/page.svg', markup: {} },
    children: LAYOUT,
  },
];
const ROW = variantRecord('row', {
  label: 'Row',
  scope: 'kind:container',
  value: { '': { default: { display: 'flex' } }, '::> *': { default: { display: 'block', color: 'green', 'flex-grow': '1' } } },
});
const ancestorArgs = (map, overrides = {}) => ({
  guide: { ...GUIDE, conditions: CONDITIONS },
  map,
  key: KEY,
  variants: [...VARIANTS, ROW],
  axes: AXES,
  blockTypes: BLOCK_TYPES,
  document: DOCUMENT,
  ...overrides,
});

test('ancestor rules reaching the key sit between the skin tier and the worn references, farthest ancestor first, a group per position', () => {
  const map = {
    [`${SEC_A}::> d-container > *`]: { default: { gap: '1rem' } },
    [SEC_B]: { variants: [{ variant: 'row', fragment: '' }] },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
    [KEY]: { variants: [{ variant: 'sleek', fragment: '' }], default: { padding: '3rem' } },
  };
  const stack = buildStack(ancestorArgs(map));
  assert.deepEqual(ids(stack), [
    'declared:kind:block',
    'declared:block_type:testimonial',
    'skin:base:p',
    'skin:kind:block',
    'skin:block_type:testimonial',
    `ancestor:${SEC_A}::> d-container > *`,
    `ancestor:${SEC_B}::> *|row`,
    `ancestor:${SEC_B}::> *`,
    'variant:sleek',
    'element',
  ]);
  const [outer, worn, inner] = stack.layers.slice(5, 8);
  assert.deepEqual(outer, {
    id: `ancestor:${SEC_A}::> d-container > *`,
    tier: TIER_ANCESTOR,
    scope: SEC_A,
    variant: null,
    fragment: null,
    via: null,
    viaLabel: null,
    label: 'Hero',
    owner: 'Hero',
    description: null,
    axis: null,
    resolved: true,
    editable: false,
    carrier: null,
    styles: { default: { gap: '1rem' } },
  });
  // The inner position is a group: the owner's worn fragment at the
  // rule's position (Row's `::> *` entry, carried by the owner's root)
  // beneath the rule's own entry, each with its identity.
  assert.deepEqual(worn, {
    id: `ancestor:${SEC_B}::> *|row`,
    tier: TIER_ANCESTOR,
    scope: SEC_B,
    variant: 'row',
    fragment: '',
    via: null,
    viaLabel: null,
    label: 'Row',
    owner: 'Container',
    description: '',
    axis: 'style',
    resolved: true,
    editable: false,
    carrier: { key: SEC_B, selector: null },
    styles: { default: { display: 'block', color: 'green', 'flex-grow': '1' } },
  });
  // An own ancestor layer is labelled by its owner's minted name.
  assert.deepEqual([inner.label, inner.owner], ['Container', 'Container']);
  assert.equal(inner.carrier, null);
  assert.deepEqual(inner.styles, { default: { color: 'red' } });
  // The posed read: an ancestor wins where nothing above it delivers,
  // own beats worn inside the group (the rule's color over Row's), a worn
  // reference beats the tier (F3: ancestors sit beneath the own
  // wearings, Sleek's color over the section's), the own layer beats
  // everything.
  assert.equal(stackAt(stack, 'gap', 'default', CONDITIONS).winner.id, `ancestor:${SEC_A}::> d-container > *`);
  assert.equal(stackAt(stack, 'flex-grow', 'default', CONDITIONS).winner.id, `ancestor:${SEC_B}::> *|row`);
  assert.equal(stackAt(stack, 'color', 'default', CONDITIONS).winner.id, 'variant:sleek');
  assert.deepEqual(stackAt(stack, 'color', 'default', CONDITIONS).beneath.map((read) => read.id), [`ancestor:${SEC_B}::> *`, `ancestor:${SEC_B}::> *|row`]);
  assert.equal(stackAt(stack, 'padding', 'default', CONDITIONS).winner.id, 'element');
});

test('a position-qualified ancestor rule reaches the element by its place, whole or not at all', () => {
  // Position is selector text: the rules the fact-condition convergence
  // writes (`> *:first-child`, `> *:last-child`) are rules of their own,
  // each a layer where the matcher says the element's place satisfies
  // it, absent where it does not; nothing folds.
  const map = {
    [`${SEC_B}::> *`]: { default: { color: 'red', margin: '0' }, hover: { margin: '1px' } },
    [`${SEC_B}::> *:first-child`]: { default: { color: 'blue', outline: '1px solid' }, hover: { color: 'white' } },
    [`${SEC_B}::> *:last-child`]: { default: { color: 'black' } },
  };
  const ancestors = (stack) => stack.layers.filter((layer) => layer.tier === TIER_ANCESTOR).map((layer) => [layer.id, layer.styles]);
  const first = buildStack(ancestorArgs(map));
  assert.deepEqual(ancestors(first), [
    [`ancestor:${SEC_B}::> *`, { default: { color: 'red', margin: '0' }, hover: { margin: '1px' } }],
    [`ancestor:${SEC_B}::> *:first-child`, { default: { color: 'blue', outline: '1px solid' }, hover: { color: 'white' } }],
  ]);
  assert.equal(stackAt(first, 'color', 'default', CONDITIONS).winner.id, `ancestor:${SEC_B}::> *:first-child`);
  const last = buildStack(ancestorArgs(map, { key: U, blockTypes: { [U.slice('block:'.length)]: 'testimonial' } }));
  assert.deepEqual(ancestors(last), [
    [`ancestor:${SEC_B}::> *`, { default: { color: 'red', margin: '0' }, hover: { margin: '1px' } }],
    [`ancestor:${SEC_B}::> *:last-child`, { default: { color: 'black' } }],
  ]);
  assert.equal(stackAt(last, 'color', 'default', CONDITIONS).winner.id, `ancestor:${SEC_B}::> *:last-child`);
});

test('a sibling rule reaches the second sibling alone; a :has() rule the linked one; a negated position reaches every child but the last', () => {
  const map = {
    [`${SEC_B}::> *`]: { default: { margin: '0' } },
    [`${SEC_B}::> *:not(:last-child)`]: { default: { 'margin-bottom': '24px' } },
    [`${SEC_B}::> p + p`]: { default: { 'margin-top': '40px' } },
    [`${SEC_B}::> d-testimonial:has(a)`]: { default: { color: 'teal' } },
  };
  const ancestors = (stack) => ids(stack).filter((id) => id.startsWith('ancestor:'));
  const layer = (stack, id) => stack.layers.find((candidate) => candidate.id === id);
  const first = buildStack(ancestorArgs(map));
  const last = buildStack(ancestorArgs(map, { key: U, blockTypes: { [U.slice('block:'.length)]: 'testimonial' } }));
  // The first block: not after a p-root, its prose links, not the last.
  assert.deepEqual(ancestors(first), [`ancestor:${SEC_B}::> *`, `ancestor:${SEC_B}::> *:not(:last-child)`, `ancestor:${SEC_B}::> d-testimonial:has(a)`]);
  assert.deepEqual(layer(first, `ancestor:${SEC_B}::> *:not(:last-child)`).styles, { default: { 'margin-bottom': '24px' } });
  // The second: right after a p-root, no link, the last child.
  assert.deepEqual(ancestors(last), [`ancestor:${SEC_B}::> *`, `ancestor:${SEC_B}::> p + p`]);
  assert.deepEqual(layer(last, `ancestor:${SEC_B}::> *`).styles, { default: { margin: '0' } });
  assert.deepEqual(layer(last, `ancestor:${SEC_B}::> p + p`).styles, { default: { 'margin-top': '40px' } });
  assert.equal(stackAt(last, 'margin-top', 'default', CONDITIONS).winner.id, `ancestor:${SEC_B}::> p + p`);
  assert.equal(stackAt(first, 'margin-top', 'default', CONDITIONS).winner, null);
});

test('a tag tier and a part key take the ancestor rules reaching inside; a chain key, a floorless read and no document take none', () => {
  const map = {
    [`${SEC_A}::a`]: { default: { 'text-decoration': 'none' } },
    [`${SEC_A}::> a`]: { default: { 'text-decoration': 'underline' } },
    [`${SEC_A}::d-testimonial a`]: { default: { color: 'teal' } },
    [`${SEC_B}::> d-testimonial .testimonial__quote`]: { default: { color: 'crimson' } },
    [`${SEC_B}::.testimonial__quote`]: { default: { color: 'navy' } },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
  };
  assert.deepEqual(ids(buildStack(ancestorArgs(map, { key: `${KEY}::a` }))), [
    `ancestor:${SEC_A}::a`,
    `ancestor:${SEC_A}::d-testimonial a`,
    'element',
  ]);
  assert.deepEqual(ids(buildStack(ancestorArgs(map, { key: QUOTE }))), [
    'declared:block_type:testimonial',
    `ancestor:${SEC_B}::> d-testimonial .testimonial__quote`,
    'element',
  ]);
  assert.deepEqual(ids(buildStack(ancestorArgs(map, { key: `${KEY}::> *` }))), ['element']);
  assert.deepEqual(ids(buildStack(ancestorArgs(map, { floor: false }))), ['element']);
  assert.deepEqual(ids(buildStack(ancestorArgs(map, { document: null }))).filter((id) => id.startsWith('ancestor:')), []);
  // A key the document does not carry has no ancestors.
  assert.deepEqual(ids(buildStack(ancestorArgs(map, { key: CHILD, blockTypes: {} }))).filter((id) => id.startsWith('ancestor:')), []);
});

test('the entity is the farthest ancestor: a rule on the page reaches the block\'s root and its tag tier as the first layer, named by the page; a keyless root names nothing', () => {
  const map = {
    [`${PAGE}::p`]: { default: { border: '2px solid red' } },
    [`${PAGE}::> d-container > *`]: { default: { gap: '2rem' } },
    [`${SEC_A}::p`]: { default: { margin: '0' } },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
  };
  const ancestors = (stack) => ids(stack).filter((id) => id.startsWith('ancestor:'));
  // The block's root is a paragraph: the page's `p` rule reaches it
  // before every section's, farthest first.
  const root = buildStack(ancestorArgs(map));
  assert.deepEqual(ancestors(root), [`ancestor:${PAGE}::p`, `ancestor:${SEC_A}::p`, `ancestor:${SEC_B}::> *`]);
  const page = root.layers.find((layer) => layer.id === `ancestor:${PAGE}::p`);
  assert.deepEqual([page.scope, page.label, page.owner, page.styles], [PAGE, 'Welcome', 'Welcome', { default: { border: '2px solid red' } }]);
  assert.equal(stackAt(root, 'border', 'default', CONDITIONS).winner.id, `ancestor:${PAGE}::p`);
  // The block's `::p` tier (the paragraphs inside it): the same rule,
  // the symptom's surface (a border on every paragraph the canvas
  // paints, now an inherited layer the panel reads).
  assert.deepEqual(ancestors(buildStack(ancestorArgs(map, { key: `${KEY}::p` }))), [`ancestor:${PAGE}::p`, `ancestor:${SEC_A}::p`]);
  // The page's `> d-container > *` rule lands on the nested section (a
  // child of a top-level container), never on the block two levels
  // down: the entity's children are the sections, exactly as the canvas
  // root wrapper's are.
  assert.deepEqual(ancestors(buildStack(ancestorArgs(map, { key: SEC_B }))), [`ancestor:${PAGE}::> d-container > *`]);
  assert.equal(ancestors(root).includes(`ancestor:${PAGE}::> d-container > *`), false);
  // The page itself has no ancestors; its own key still stacks.
  assert.deepEqual(ancestors(buildStack(ancestorArgs(map, { key: PAGE }))), []);
  assert.equal(subjectTag(DOCUMENT, PAGE), null);
  // A keyless root (a host with no uuid): the sections keep their
  // ancestry, and the page's rules, addressed to nothing, lay no layer.
  const keyless = [{ ...DOCUMENT[0], uuid: null }];
  assert.deepEqual(ancestors(buildStack(ancestorArgs(map, { document: keyless }))), [`ancestor:${SEC_A}::p`, `ancestor:${SEC_B}::> *`]);
});

test('a field-keyed row (an open render\'s paragraph) carries its block\'s own tag rule beneath the page\'s and the sections\'', () => {
  const map = {
    [`${PAGE}::p`]: { default: { border: '2px solid red' } },
    [`${SEC_A}::p`]: { default: { margin: '0' } },
    [`${SEC_B}::p`]: { default: { 'line-height': '1.5' } },
    [`${U}::p`]: { default: { color: 'navy' } },
    [`${U}::a`]: { default: { color: 'teal' } },
  };
  const stack = buildStack(ancestorArgs(map, { key: FIELD_ROW, blockTypes: { [U.slice('block:'.length)]: 'testimonial' } }));
  // The field is inside its block: the block's `p` rule is the nearest
  // ancestor layer, the sections' and the page's farther; the block's
  // `a` rule reaches no paragraph.
  assert.deepEqual(ids(stack).filter((id) => id.startsWith('ancestor:')), [
    `ancestor:${PAGE}::p`,
    `ancestor:${SEC_A}::p`,
    `ancestor:${SEC_B}::p`,
    `ancestor:${U}::p`,
  ]);
  const block = stack.layers.find((layer) => layer.id === `ancestor:${U}::p`);
  assert.deepEqual([block.scope, block.label, block.owner], [U, 'Testimonial', 'Testimonial']);
  assert.equal(stackAt(stack, 'color', 'default', CONDITIONS).winner.id, `ancestor:${U}::p`);
  assert.equal(stackAt(stack, 'border', 'default', CONDITIONS).winner.id, `ancestor:${PAGE}::p`);
  assert.equal(ids(stack).at(-1), 'element');
  // The row's subject is the minted paragraph: its tag keys the base
  // layer the way a declared part's does.
  assert.equal(subjectTag(DOCUMENT, FIELD_ROW), 'p');
});

test('selectorSuffixes lists a key\'s worn fragment suffixes first, then the map\'s, deduplicated; none under a selector key', () => {
  const map = {
    [SEC_B]: { variants: [{ variant: 'row', fragment: '' }] },
    [`${SEC_B}::> d-testimonial`]: { default: { color: 'red' } },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
  };
  assert.deepEqual(selectorSuffixes(map, SEC_B, [...VARIANTS, ROW], ['style']), ['::> *', '::> d-testimonial']);
  assert.deepEqual(selectorSuffixes(map, `${SEC_B}::> *`, [...VARIANTS, ROW], ['style']), []);
});

// --- wear at a rule ----------------------------------------------------------

// A container look with a text-block sub-entry: the root's wear delivers
// at the `> d-testimonial` rule position too.
const SECTION = variantRecord('section', {
  label: 'Section',
  scope: 'kind:container',
  value: {
    '': { default: { gap: '3rem' } },
    '::> d-testimonial': { default: { color: 'green', margin: '1rem' } },
  },
});
const RULE = `${SEC_B}::> d-testimonial`;
const ruleArgs = (map, overrides = {}) => ancestorArgs(map, { variants: [...VARIANTS, ROW, SECTION], ...overrides });

// A testimonial look with its own generated box: a fragment at the
// pseudo key, delivered wherever the look is worn (the root's
// `::&::before`, a rule's `::> d-testimonial::before` by continuation).
const GLOW = variantRecord('glow', {
  label: 'Glow',
  scope: 'block_type:testimonial',
  value: {
    '': { default: { color: 'orange' } },
    '::&::before': { default: { content: '""', 'background-color': 'gold' }, hover: { 'background-color': 'goldenrod' } },
  },
});

test('a rule key\'s stack carries its worn layer beneath its own entry, and the root\'s wear at the rule position between them', () => {
  const map = {
    [RULE]: { variants: [{ variant: 'loud', fragment: '' }], default: { padding: '5rem' } },
  };
  // The rule's own stack: no floor tier declares anything at the rule,
  // no ancestor tier (a rule is no tier of the element), the worn
  // variant's root entry beneath the rule's own.
  const own = buildStack(ruleArgs(map, { key: RULE }));
  assert.deepEqual(ids(own), ['variant:loud', 'element']);
  assert.deepEqual(own.layers[0].styles, { default: { padding: '2rem', 'font-weight': 'bold' }, dark: { color: '#eee' } });
  assert.equal(own.layers[0].scope, 'block_type:testimonial');
  assert.equal(own.layers[0].axis, 'emphasis');
  assert.deepEqual(own.layers[1].styles, { default: { padding: '5rem' } });
  assert.equal(own.layers[1].editable, true);
  assert.equal(stackAt(own, 'padding', 'default', CONDITIONS).winner.id, 'element');
  assert.equal(stackAt(own, 'font-weight', 'default', CONDITIONS).winner.id, 'variant:loud');
  // The owner wearing a variant with an entry at the rule's selector:
  // rule wear prints before the element lanes, so the owner's wear sits
  // over the rule's own wear and under the rule's entry.
  const wornOwner = buildStack(ruleArgs({ ...map, [SEC_B]: { variants: [{ variant: 'section', fragment: '' }] } }, { key: RULE }));
  assert.deepEqual(ids(wornOwner), ['variant:loud', 'variant:section', 'element']);
  assert.deepEqual(wornOwner.layers[1].styles, { default: { color: 'green', margin: '1rem' } });
  // A stale reference at the rule is a layer that resolves nothing.
  const stale = buildStack(ruleArgs({ [RULE]: { variants: [{ variant: 'ghost', fragment: '' }] } }, { key: RULE }));
  assert.deepEqual(ids(stale), ['variant:ghost', 'element']);
  assert.equal(stale.layers[0].resolved, false);
});

test('a session anchored at a rule makes the rule\'s worn layer the editable one, carrying the staged document', () => {
  const map = {
    [RULE]: { variants: [{ variant: 'loud', fragment: '' }], default: { padding: '5rem' } },
  };
  const session = {
    reference: 'loud',
    pending: {
      kind: 'update',
      moves: [],
      lines: [{ id: 'p1', relative: '', condition: 'default', property: 'padding', value: '4rem' }],
    },
  };
  // The rule's own stack in the session: the worn layer carries the
  // staged document at the variant's root (the rule's matches ARE the
  // root) and is the editable layer; the rule's entry is not.
  const stack = buildStack(ruleArgs(map, { key: RULE, session }));
  assert.deepEqual(ids(stack), ['variant:loud', 'element']);
  assert.deepEqual(stack.layers.map((layer) => layer.editable), [true, false]);
  assert.equal(editableLayer(stack), 0);
  assert.deepEqual(stack.layers[0].styles, { default: { padding: '4rem', 'font-weight': 'bold' }, dark: { color: '#eee' } });
  // Own beats worn by order: the rule's entry still wins padding over
  // the document, the honesty read the rows strike through with.
  assert.equal(stackAt(stack, 'padding', 'default', CONDITIONS).winner.id, 'element');
  // A session on a reference no carrier of the rule wears leaves the
  // rule's own layer editable.
  const elsewhere = buildStack(ruleArgs(map, { key: RULE, session: { reference: 'sleek', pending: null } }));
  assert.deepEqual(elsewhere.layers.map((layer) => layer.editable), [false, true]);
});

test('a member\'s ancestor tier holds the rule as a group: its worn variants with their identity and carrier, then its own layer, own over worn by order', () => {
  const map = {
    [RULE]: { variants: [{ variant: 'loud', fragment: '' }], default: { padding: '5rem' } },
    [KEY]: { default: { color: 'navy' } },
  };
  const stack = buildStack(ruleArgs(map));
  const ancestors = stack.layers.filter((layer) => layer.tier === TIER_ANCESTOR);
  assert.deepEqual(ancestors.map((layer) => layer.id), [`ancestor:${RULE}|loud`, `ancestor:${RULE}`]);
  const [worn, own] = ancestors;
  // The worn layer: the variant's identity as its own wearing would
  // carry it, scoped to the OWNER like the own layer (placeEditable
  // ranks by scope), the carrier the rule that wears it, never editable.
  assert.deepEqual(worn, {
    id: `ancestor:${RULE}|loud`,
    tier: TIER_ANCESTOR,
    scope: SEC_B,
    variant: 'loud',
    fragment: '',
    via: null,
    viaLabel: null,
    label: 'Loud',
    owner: 'Container',
    description: '',
    axis: 'emphasis',
    resolved: true,
    editable: false,
    carrier: { key: SEC_B, selector: '> d-testimonial' },
    styles: { default: { padding: '2rem', 'font-weight': 'bold' }, dark: { color: '#eee' } },
  });
  assert.deepEqual(own.styles, { default: { padding: '5rem' } });
  assert.equal(own.variant, null);
  assert.equal(own.carrier, null);
  assert.equal(stackAt(stack, 'font-weight', 'default', CONDITIONS).winner.id, `ancestor:${RULE}|loud`);
  assert.equal(stackAt(stack, 'padding', 'default', CONDITIONS).winner.id, `ancestor:${RULE}`);
  assert.deepEqual(stackAt(stack, 'padding', 'default', CONDITIONS).beneath.map((read) => read.id), [`ancestor:${RULE}|loud`, 'skin:kind:block', 'declared:block_type:testimonial']);
  assert.equal(stackAt(stack, 'color', 'default', CONDITIONS).winner.id, 'element');
  // The honesty read every "this element wears" surface makes (the
  // Advanced roster's `worn`, the candidate resolver, the AI door):
  // the member's stack carries NO variant-tier layer; the rule's wear
  // is an ancestor-tier fact, read by its identity, never as a wearing.
  assert.equal(stack.layers.some((layer) => layer.tier === TIER_VARIANT), false);
  assert.equal(stack.layers.some((layer) => layer.variant !== null), true);
  // A rule wearing only (no entry of its own) is still a group: the
  // worn layer delivers, and the own layer rides it empty (the rule
  // exists; the switcher lists it).
  const wornOnly = buildStack(ruleArgs({ [RULE]: { variants: [{ variant: 'loud', fragment: '' }] } }));
  const group = wornOnly.layers.filter((layer) => layer.tier === TIER_ANCESTOR);
  assert.deepEqual(group.map((layer) => [layer.id, layer.styles]), [
    [`ancestor:${RULE}|loud`, { default: { padding: '2rem', 'font-weight': 'bold' }, dark: { color: '#eee' } }],
    [`ancestor:${RULE}`, {}],
  ]);
});

test('a group exists only when one of its layers delivers; a worn layer joins it only where it delivers, the own layer always', () => {
  // A rule wearing a variant that styles only a part (nothing at the
  // rule's own position) and carrying no entry: no group at the member's
  // root (the fold is empty for both layers), while the part takes the
  // continued group with the own layer riding empty.
  const partOnly = variantRecord('quote_only', {
    label: 'Quote only',
    scope: 'block_type:testimonial',
    value: { '::.testimonial__quote': { default: { 'font-style': 'italic' } } },
  });
  const wearing = { [RULE]: { variants: [{ variant: 'quote_only', fragment: '' }] } };
  const nothing = buildStack(ruleArgs(wearing, { variants: [...VARIANTS, ROW, SECTION, partOnly] }));
  assert.equal(ids(nothing).some((id) => id.startsWith('ancestor:')), false);
  const part = buildStack(ruleArgs(wearing, { key: QUOTE, variants: [...VARIANTS, ROW, SECTION, partOnly] }));
  assert.deepEqual(part.layers.filter((layer) => layer.tier === TIER_ANCESTOR).map((layer) => [layer.id, layer.styles]), [
    [`ancestor:${RULE} .testimonial__quote|quote_only`, { default: { 'font-style': 'italic' } }],
    [`ancestor:${RULE} .testimonial__quote`, {}],
  ]);
  // An empty entry beside a stale reference: nothing delivers, no group.
  const empty = buildStack(ruleArgs({ [RULE]: { variants: [{ variant: 'ghost', fragment: '' }], default: {} } }));
  assert.equal(ids(empty).some((id) => id.startsWith('ancestor:')), false);
  // The same stale reference beside a delivering entry: the group exists
  // on the own layer alone. A reference is a fact of its carrier (the
  // rule's own stack lists it, stale or empty); a member's tier carries
  // what reaches the member, so the stale reference stays out, and the
  // owner's root wear (Row) joins only the position its fragment
  // delivers at (`> *`, through Row's `::> *` entry, whose own layer
  // rides empty though the map has no `> *` entry), never the rule's.
  const stale = buildStack(ruleArgs({
    [RULE]: { variants: [{ variant: 'ghost', fragment: '' }], default: { padding: '5rem' } },
    [SEC_B]: { variants: [{ variant: 'row', fragment: '' }] },
  }));
  const group = stale.layers.filter((layer) => layer.tier === TIER_ANCESTOR);
  assert.deepEqual(group.map((layer) => [layer.id, layer.styles]), [
    [`ancestor:${SEC_B}::> *|row`, { default: { display: 'block', color: 'green', 'flex-grow': '1' } }],
    [`ancestor:${SEC_B}::> *`, {}],
    [`ancestor:${RULE}`, { default: { padding: '5rem' } }],
  ]);
  const own = buildStack(ruleArgs({ [RULE]: { variants: [{ variant: 'ghost', fragment: '' }], default: { padding: '5rem' } } }, { key: RULE }));
  assert.deepEqual(own.layers.map((layer) => [layer.id, layer.resolved]), [['variant:ghost', false], ['element', true]]);
});

test('a worn look\'s position-qualified fragments continue under the rule to the members their place satisfies', () => {
  // The keys the fact-condition convergence writes into a variant
  // (`::&:first-child`, `::&:last-child`) are fragments of the look;
  // worn at a rule they rebase onto the rule's landing
  // (`> d-testimonial:first-child`) and reach the member whose place
  // satisfies them, as a group the rule carries; the rule's own
  // qualified entry is a rule of its own.
  const map = {
    [RULE]: { variants: [{ variant: 'places', fragment: '' }], default: { margin: '0' } },
    [`${RULE}:last-child`]: { default: { margin: '8px' } },
  };
  const places = variantRecord('places', {
    label: 'Places',
    scope: 'block_type:testimonial',
    value: {
      '': { default: { color: 'red' } },
      '::&:first-child': { default: { color: 'blue', outline: '1px solid' } },
      '::&:last-child': { default: { color: 'black' } },
    },
  });
  const args = (overrides) => ruleArgs(map, { variants: [...VARIANTS, ROW, SECTION, places], ...overrides });
  const ancestors = (stack) => stack.layers.filter((layer) => layer.tier === TIER_ANCESTOR).map((layer) => [layer.id, layer.styles]);
  // The first block: the look's root entry and its first-child fragment
  // (each carried by the rule), the rule's own margin; never the
  // last-child fragment nor the rule's last-child entry.
  const first = buildStack(args({}));
  assert.deepEqual(ancestors(first), [
    [`ancestor:${RULE}:first-child|places`, { default: { color: 'blue', outline: '1px solid' } }],
    [`ancestor:${RULE}:first-child`, {}],
    [`ancestor:${RULE}|places`, { default: { color: 'red' } }],
    [`ancestor:${RULE}`, { default: { margin: '0' } }],
  ]);
  assert.equal(stackAt(first, 'color', 'default', CONDITIONS).winner.id, `ancestor:${RULE}|places`);
  // The last block: the other way round, and the rule's own last-child
  // entry beside it.
  const last = buildStack(args({ key: U, blockTypes: { [U.slice('block:'.length)]: 'testimonial' } }));
  assert.deepEqual(ancestors(last), [
    [`ancestor:${RULE}:last-child|places`, { default: { color: 'black' } }],
    [`ancestor:${RULE}:last-child`, { default: { margin: '8px' } }],
    [`ancestor:${RULE}|places`, { default: { color: 'red' } }],
    [`ancestor:${RULE}`, { default: { margin: '0' } }],
  ]);
});

test('a rule\'s worn "::sel" entry continues under the rule by descent: the part reads it as a worn ancestor layer carried by the rule, the continued key as its own wear', () => {
  const map = {
    [RULE]: { variants: [{ variant: 'sleek', fragment: '' }] },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
  };
  // The suffix listing: the continued selector first (rule wear prints
  // first), then the map's own rules.
  assert.deepEqual(selectorSuffixes(map, SEC_B, [...VARIANTS, ROW, SECTION], ['style']), ['::> d-testimonial .testimonial__quote', '::> d-testimonial', '::> *']);
  // The quote part: the container's continued position reaches it as a
  // group whose worn layer is carried by the RULE (`> d-testimonial`,
  // where the wear bar shows Sleek), not by the continued position, and
  // whose own layer rides empty (the map carries no entry there:
  // existence is the group's, styles are the map's).
  const part = buildStack(ruleArgs(map, { key: QUOTE }));
  assert.deepEqual(ids(part), [
    'declared:block_type:testimonial',
    `ancestor:${RULE} .testimonial__quote|sleek`,
    `ancestor:${RULE} .testimonial__quote`,
    'element',
  ]);
  assert.deepEqual(part.layers[1].styles, { default: { 'font-size': '1.5rem' } });
  assert.deepEqual(part.layers[1].carrier, { key: SEC_B, selector: '> d-testimonial' });
  assert.equal(part.layers[1].variant, 'sleek');
  assert.deepEqual(part.layers[2].styles, {});
  // The continued key's own stack: sleek's part entry is the layer,
  // read at the rule's continuation and carried by the rule, beneath
  // the key's own entry.
  const continued = buildStack(ruleArgs({ ...map, [`${RULE} .testimonial__quote`]: { default: { color: 'plum' } } }, { key: `${RULE} .testimonial__quote` }));
  assert.deepEqual(ids(continued), ['variant:sleek', 'element']);
  assert.deepEqual(continued.layers[0].styles, { default: { 'font-size': '1.5rem' } });
  assert.deepEqual(continued.layers[0].carrier, { key: SEC_B, selector: '> d-testimonial' });
  assert.deepEqual(continued.layers[1].styles, { default: { color: 'plum' } });
  // The block itself takes the rule's root entry (sleek's padding), not
  // its part entry, with the rule's empty own layer riding.
  const block = buildStack(ruleArgs(map));
  assert.deepEqual(block.layers.filter((layer) => layer.tier === TIER_ANCESTOR).map((layer) => [layer.id, layer.styles]), [
    [`ancestor:${RULE}|sleek`, { default: { padding: '1rem', color: '#111' }, hover: { color: '#333' } }],
    [`ancestor:${RULE}`, {}],
    [`ancestor:${SEC_B}::> *`, { default: { color: 'red' } }],
  ]);
});

// --- generated boxes ----------------------------------------------------------

test('a generated box takes the ancestor rules ending in the same box (a position-qualified one where the place holds), a worn look\'s fragment at the box and its own lines; pseudo-free rules and the other box never', () => {
  const map = {
    [`${SEC_A}::d-testimonial::before`]: { default: { 'background-color': 'teal' } },
    [`${SEC_A}::d-testimonial:first-child::before`]: { default: { width: '2rem' } },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
    [`${SEC_B}::> *::after`]: { default: { content: '""' } },
    [`${RULE} .testimonial__quote::after`]: { default: { content: '""', color: 'plum' } },
    [RULE]: { variants: [{ variant: 'glow', fragment: '' }] },
    [KEY]: { variants: [{ variant: 'glow', fragment: '' }], default: { color: 'navy' } },
    [`${KEY}::&::before`]: { default: { content: '""', width: '1rem' } },
  };
  const args = (overrides = {}) => ruleArgs(map, { variants: [...VARIANTS, ROW, SECTION, GLOW], ...overrides });
  const ancestors = (stack) => ids(stack).filter((id) => id.startsWith('ancestor:'));
  // The block's own `&::before`: no floor (a box has no tier block and
  // no tag), the outer section's descent rule for the box and its
  // position-qualified twin (the block is the first child), Glow's box
  // fragment worn at the inner section's rule continued under it as
  // `> d-testimonial::before` (the anchor binds to the rule's landing;
  // the rule carries it, the own layer rides empty), then the block's
  // own wearing read at the box and its own lines. The pseudo-free
  // rules (`> *`, `> d-testimonial`) and the `::after` box deliver
  // nothing here.
  const before = buildStack(args({ key: `${KEY}::&::before` }));
  assert.deepEqual(ids(before), [
    `ancestor:${SEC_A}::d-testimonial::before`,
    `ancestor:${SEC_A}::d-testimonial:first-child::before`,
    `ancestor:${RULE}::before|glow`,
    `ancestor:${RULE}::before`,
    'variant:glow',
    'element',
  ]);
  assert.deepEqual({ rootKey: before.rootKey, relative: before.relative, scope: before.scope }, { rootKey: KEY, relative: '::&::before', scope: 'block_type:testimonial' });
  assert.deepEqual(before.layers[0].styles, { default: { 'background-color': 'teal' } });
  assert.deepEqual(before.layers[1].styles, { default: { width: '2rem' } });
  assert.deepEqual(before.layers[2].styles, { default: { content: '""', 'background-color': 'gold' }, hover: { 'background-color': 'goldenrod' } });
  assert.deepEqual(before.layers[2].carrier, { key: SEC_B, selector: '> d-testimonial' });
  assert.equal(before.layers[2].variant, 'glow');
  assert.deepEqual(before.layers[3].styles, {});
  assert.deepEqual(before.layers[4].styles, before.layers[2].styles);
  assert.deepEqual(before.layers[4].carrier, { key: KEY, selector: null });
  assert.deepEqual(before.layers[5].styles, { default: { content: '""', width: '1rem' } });
  assert.equal(before.layers[5].editable, true);
  assert.equal(stackAt(before, 'background-color', 'default', CONDITIONS).winner.id, 'variant:glow');
  assert.deepEqual(stackAt(before, 'background-color', 'default', CONDITIONS).beneath.map((read) => read.id), [`ancestor:${RULE}::before|glow`, `ancestor:${SEC_A}::d-testimonial::before`]);
  assert.equal(stackAt(before, 'width', 'default', CONDITIONS).winner.id, 'element');
  assert.equal(stackAt(before, 'background-color', 'hover', CONDITIONS).winner.value, 'goldenrod');
  // The host's own lines are the host's: the box takes no color from
  // the block's key nor from Glow's root entry.
  assert.equal(stackAt(before, 'color', 'default', CONDITIONS).winner, null);
  // The other box: the inner section's `> *::after` rule alone; the
  // wearing is a layer of the box too, empty (a reference is a fact of
  // its carrier).
  const after = buildStack(args({ key: `${KEY}::&::after` }));
  assert.deepEqual(ids(after), [`ancestor:${SEC_B}::> *::after`, 'variant:glow', 'element']);
  assert.deepEqual(after.layers[0].styles, { default: { content: '""' } });
  assert.deepEqual(after.layers[1].styles, {});
  // The host itself takes none of the box rules: its own tier is the
  // pseudo-free rules, Glow's root entry under the rule and its own.
  assert.deepEqual(ancestors(buildStack(args())), [`ancestor:${SEC_B}::> *`, `ancestor:${RULE}|glow`, `ancestor:${RULE}`]);
  // The last child's box: the descent rule's position-qualified twin
  // never reaches it, the continued fragment does, nothing worn or
  // owned at it.
  const last = buildStack(args({ key: `${U}::&::before`, blockTypes: { [U.slice('block:'.length)]: 'testimonial' } }));
  assert.deepEqual(ids(last), [`ancestor:${SEC_A}::d-testimonial::before`, `ancestor:${RULE}::before|glow`, `ancestor:${RULE}::before`, 'element']);
  assert.deepEqual(last.layers[0].styles, { default: { 'background-color': 'teal' } });
  // A part's box: the rule ending in the part's box reaches it as the
  // part's rules do; the part itself takes none of it.
  const partBox = buildStack(args({ key: `${QUOTE}::after` }));
  assert.deepEqual(ids(partBox), [`ancestor:${RULE} .testimonial__quote::after`, 'variant:glow', 'element']);
  assert.deepEqual(partBox.layers[0].styles, { default: { content: '""', color: 'plum' } });
  assert.deepEqual(ancestors(buildStack(args({ key: QUOTE }))), []);
  // A floorless read and no document carry no box rules either.
  assert.deepEqual(ids(buildStack(args({ key: `${KEY}::&::before`, floor: false }))), ['variant:glow', 'element']);
  assert.deepEqual(ancestors(buildStack(args({ key: `${KEY}::&::before`, document: null }))), []);
});

test('selectorSuffixes lists a generated box as a sibling suffix of the root: a rule\'s worn box continued under the rule first, then a worn fragment\'s box, then the map\'s; none under a box', () => {
  const map = {
    [SEC_B]: { variants: [{ variant: 'row', fragment: '' }] },
    [RULE]: { variants: [{ variant: 'glow', fragment: '' }] },
    [`${SEC_B}::> *::after`]: { default: { content: '""' } },
    [`${SEC_B}::&::before`]: { default: { content: '""' } },
  };
  assert.deepEqual(selectorSuffixes(map, SEC_B, [...VARIANTS, ROW, GLOW], ['style']), [
    '::> d-testimonial::before',
    '::> *',
    '::> d-testimonial',
    '::> *::after',
    '::&::before',
  ]);
  assert.deepEqual(selectorSuffixes({ [KEY]: { variants: [{ variant: 'glow', fragment: '' }] } }, KEY, [...VARIANTS, GLOW], AXES), ['::&::before']);
  assert.deepEqual(selectorSuffixes(map, `${SEC_B}::&::before`, [...VARIANTS, ROW, GLOW], ['style']), []);
  assert.deepEqual(selectorSuffixes(map, `${RULE}::before`, [...VARIANTS, ROW, GLOW], ['style']), []);
});

// --- the write address: the editable layer ---------------------------------

test('a write address marks its existing ancestor layer editable and the own layer not; own reads from it', () => {
  const map = {
    [`${SEC_B}::> *`]: { default: { color: 'red' }, hover: { color: 'pink' } },
    [KEY]: { variants: [{ variant: 'sleek', fragment: '' }], default: { padding: '3rem' } },
  };
  const address = { key: SEC_B, selector: '> *' };
  const stack = buildStack(ancestorArgs(map, { editable: address }));
  const id = `ancestor:${SEC_B}::> *`;
  assert.deepEqual(ids(stack), [
    'declared:kind:block',
    'declared:block_type:testimonial',
    'skin:base:p',
    'skin:kind:block',
    'skin:block_type:testimonial',
    id,
    'variant:sleek',
    'element',
  ]);
  assert.deepEqual(stack.layers.filter((layer) => layer.editable).map((layer) => layer.id), [id]);
  assert.equal(editableLayer(stack), 5);
  // The layer is the existing one, untouched but for the mark.
  assert.deepEqual(stack.layers[5].styles, { default: { color: 'red' }, hover: { color: 'pink' } });
  assert.equal(stack.layers[5].label, 'Container', 'labelled by its owner\'s minted name');
  // The rows' read: the winner is what it always is; `own` is now the
  // address's block at the pose (the Unset verb's test), and the
  // element's own delivery no longer counts as own.
  const color = stackAt(stack, 'color', 'default', CONDITIONS);
  assert.equal(color.winner.id, 'variant:sleek');
  assert.deepEqual(color.own, { layer: 5, id, tier: TIER_ANCESTOR, variant: null, condition: 'default', value: 'red' });
  assert.equal(stackAt(stack, 'color', 'hover', CONDITIONS).own.value, 'pink');
  const padding = stackAt(stack, 'padding', 'default', CONDITIONS);
  assert.equal(padding.winner.id, 'element');
  assert.equal(padding.own, null);
  // Without the address the same map reads as before: own is the element's.
  const plain = buildStack(ancestorArgs(map));
  assert.equal(plain.layers[5].editable, false);
  assert.equal(stackAt(plain, 'padding', 'default', CONDITIONS).own.id, 'element');
});

test('an address with no entry yet is a virtual empty editable ancestor layer at its delivery position', () => {
  const map = {
    [`${SEC_A}::> d-container > *`]: { default: { gap: '1rem' } },
    [`${SEC_B}::> *`]: { default: { color: 'red' } },
  };
  const outer = buildStack(ancestorArgs(map, { editable: { key: SEC_A, selector: 'd-testimonial' } }));
  assert.deepEqual(ids(outer).slice(5), [
    `ancestor:${SEC_A}::> d-container > *`,
    `ancestor:${SEC_A}::d-testimonial`,
    `ancestor:${SEC_B}::> *`,
    'element',
  ]);
  assert.deepEqual(outer.layers[6], {
    id: `ancestor:${SEC_A}::d-testimonial`,
    tier: TIER_ANCESTOR,
    scope: SEC_A,
    variant: null,
    fragment: null,
    via: null,
    viaLabel: null,
    label: 'Hero',
    owner: 'Hero',
    description: null,
    axis: null,
    resolved: true,
    editable: true,
    carrier: null,
    styles: {},
  });
  assert.equal(editableLayer(outer), 6);
  assert.equal(stackAt(outer, 'color', 'default', CONDITIONS).own, null, 'nothing written there yet');
  const inner = buildStack(ancestorArgs(map, { editable: { key: SEC_B, selector: '> d-testimonial' } }));
  assert.deepEqual(ids(inner).slice(5), [
    `ancestor:${SEC_A}::> d-container > *`,
    `ancestor:${SEC_B}::> *`,
    `ancestor:${SEC_B}::> d-testimonial`,
    'element',
  ]);
  assert.equal(inner.layers[7].editable, true);
  assert.equal(inner.layers[8].editable, false);
  // An owner the document does not place among the ancestors sits nearest
  // the own entry, and a tag tier takes the address too.
  const stray = buildStack(ancestorArgs(map, { editable: { key: 'container:elsewhere', selector: 'p' } }));
  assert.deepEqual(ids(stray).slice(7), ['ancestor:container:elsewhere::p', 'element']);
  assert.equal(stray.layers[7].label, null);
  const tier = buildStack(ancestorArgs({ [`${SEC_A}::a`]: { default: { color: 'teal' } } }, { key: `${KEY}::a`, editable: { key: SEC_A, selector: 'a' } }));
  assert.deepEqual(tier.layers.map((layer) => [layer.id, layer.editable]), [[`ancestor:${SEC_A}::a`, true], ['element', false]]);
});

test('an address naming the key itself keeps the own layer; a floorless or documentless build carries no editable layer under an address', () => {
  const own = buildStack(ancestorArgs({}, { key: QUOTE, editable: { key: KEY, selector: '.testimonial__quote' } }));
  assert.equal(own.layers[own.layers.length - 1].editable, true);
  assert.equal(own.layers.some((layer) => layer.tier === TIER_ANCESTOR), false);
  const address = { key: SEC_B, selector: '> *' };
  const floorless = buildStack(ancestorArgs({}, { floor: false, editable: address }));
  assert.deepEqual(ids(floorless), ['element']);
  assert.equal(editableLayer(floorless), -1);
  const documentless = buildStack(ancestorArgs({}, { document: null, editable: address }));
  assert.equal(editableLayer(documentless), -1);
  // A malformed address is no address.
  assert.equal(editableLayer(buildStack(ancestorArgs({}, { editable: { key: SEC_B } }))), 5);
  assert.equal(editableLayer(buildStack(ancestorArgs({}, { editable: { key: SEC_B, selector: '' } }))), 5);
});
