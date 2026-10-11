/**
 * Self-asserting tests for the basic-mode control projection.
 *
 * Run directly (`node tests/js/controls.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite
 * so the control-model contract has backpressure. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { compoundOperations, createControlWidgetRegistry, controlModels, controlSections, groupControls, litValues, ownAtPose, resolveCandidates, resolveGroup, styledAt, withheldReasonFor } from '../../js/src/controls.js';
import { originFor } from '../../js/src/choice-row-model.js';
import { buildStack, editableLayer } from '../../js/src/style-stack.js';
import { EMPTY_MATERIALS } from './materials-fixture.mjs';

// Drupal.t at call time: the projection labels every candidate box
// (write-address.js boxLabel, "element" for the root) and the origins
// speak sentences; placeholders substituted the way Drupal.formatString
// does, without escaping (no `@` value here carries markup).
globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
};

// A fake model's read with a map-layer winner (the element's own entry
// at Base), the registry tests' "a value is set" shape.
const ownRead = (value) => {
  const entry = { layer: 0, id: 'element', tier: 'element', variant: null, condition: 'default', value };
  return { winner: entry, beneath: [], own: entry };
};
const ownReads = (values) => Object.fromEntries(Object.entries(values).map(([property, value]) => [property, ownRead(value)]));
// A read whose winner is a tier beneath the map (the skin): shown lit,
// but never "set".
const skinRead = (value) => ({
  winner: { layer: 0, id: 'skin:block_type:image', tier: 'skin', variant: null, condition: 'default', value },
  beneath: [],
  own: null,
});

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const BLOCK = `block:${UUID_A}`;

/**
 * A guide payload shaped like ClientStyleGuide::build() ships it: effective
 * block-type declarations (controls: as authored) + the controlWidgets
 * definition table.
 */
// Served nodes carry the roster's grants (group id => the granted names).
const LAYOUT = ['justify-content', 'align-items', 'width'];
// A served root's axes as the roster carries them: axis => its label and
// depth stamp (ClientStyleGuide serves VocabularyRoster::axes()).
const axesOf = (names, rootOnly = []) => Object.fromEntries(names.map((axis) => [axis, { label: null, root_only: rootOnly.includes(axis) }]));

const guide = {
  kinds: {
    block: {
      grants: { layout: LAYOUT },
    },
  },
  blockTypes: {
    image: {
      grants: { layout: LAYOUT },
      elements: {
        '.mock-container': { label: 'Container', grants: { layout: LAYOUT } },
        '.mock-image': { label: 'Image', grants: { layout: LAYOUT } },
      },
      controls: {
        alignment: {
          label: 'Alignment',
          widget: 'flex_align',
          element: ['.mock-container'],
        },
        width: {
          // No binding label: falls back to the widget definition's label.
          widget: 'width',
          element: ['.mock-image'],
          // A binding override: the widget files under layout, this
          // control moves to surface.
          section: 'surface',
        },
      },
    },
  },
  controlWidgets: {
    flex_align: { label: 'Flex align', section: 'layout', properties: ['justify-content', 'align-items'] },
    width: { label: 'Width', section: 'layout', properties: ['width'] },
  },
  // Authored out of weight order on purpose: the roster sorts by weight.
  controlSections: {
    layout: { label: 'Layout', weight: 20 },
    text: { label: 'Text', weight: 0 },
    surface: { label: 'Surface', weight: 10 },
  },
};

const blockTypes = { [UUID_A]: 'image' };

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('registry: register/get/has, unknown ids are null, last write wins', () => {
  const registry = createControlWidgetRegistry();
  const first = () => 'first';
  const second = () => 'second';
  assert.equal(registry.get('flex_align'), null);
  assert.equal(registry.has('flex_align'), false);
  registry.register('flex_align', first);
  assert.equal(registry.get('flex_align'), first);
  assert.equal(registry.has('flex_align'), true);
  registry.register('flex_align', second);
  assert.equal(registry.get('flex_align'), second);
});

test('offered: applicable OR value-set, and a set value always wins', () => {
  // The offer rule (guide-sanctioned AND canvas-applicable, OR a value
  // already set) with its value-set half enforced HERE so no widget can
  // ever hide a set value.
  const registry = createControlWidgetRegistry();
  registry.register('gated', () => 'gated', { applicable: (self) => !self || self.hasText });
  registry.register('plain', () => 'plain');
  const empty = { reads: {}, styledElsewhere: false };
  const text = { self: { hasText: true } };
  const noText = { self: { hasText: false } };
  // No gate registered: always offered (flex_align/width today).
  assert.equal(registry.offered('plain', empty, noText), true);
  // The gate decides only when nothing is set.
  assert.equal(registry.offered('gated', empty, text), true);
  assert.equal(registry.offered('gated', empty, noText), false);
  // A set owned value must always be visible and unsettable.
  assert.equal(registry.offered('gated', { reads: ownReads({ 'text-align': 'center' }), styledElsewhere: false }, noText), true);
  // A tier delivering alone (the skin's default) is not a set value: the
  // gate still decides.
  assert.equal(registry.offered('gated', { reads: { 'text-align': skinRead('center') }, styledElsewhere: false }, noText), false);
  assert.equal(styledAt({ 'text-align': skinRead('center') }), false);
  assert.equal(styledAt(ownReads({ 'text-align': 'center' })), true);
  // styledElsewhere is set styling the current pose does not show (other
  // compounds, an unresolvable variant); hiding the control would hide
  // the rows whose marks tell that story.
  assert.equal(registry.offered('gated', { reads: {}, styledElsewhere: true }, noText), true);
});

test('offered: unknown context reaches the gate as null self', () => {
  // The registry never guesses for the gate: a null layout (no canvas
  // element, a satellite pre-broadcast) passes null through, and the gates
  // themselves degrade to offering (textGate(null) === true).
  const registry = createControlWidgetRegistry();
  const received = [];
  registry.register('probe', () => 'probe', {
    applicable: (self) => {
      received.push(self);
      return true;
    },
  });
  const empty = { reads: {}, styledElsewhere: false };
  assert.equal(registry.offered('probe', empty, null), true);
  assert.equal(registry.offered('probe', empty, {}), true);
  assert.deepEqual(received, [null, null]);
});

test('offered: the gate receives parent facts too (position gates on them)', () => {
  // Gates are (self, parent): a parent-context gate hides its widget when
  // the surroundings are unknown, while a set value still always offers.
  const registry = createControlWidgetRegistry();
  registry.register('parent_gated', () => 'x', {
    applicable: (self, parent) => parent !== null && parent.display !== undefined,
  });
  const empty = { reads: {}, styledElsewhere: false };
  assert.equal(registry.offered('parent_gated', empty, { self: {}, parent: { display: 'block' } }), true);
  assert.equal(registry.offered('parent_gated', empty, { self: {}, parent: null }), false);
  assert.equal(registry.offered('parent_gated', empty, null), false);
  assert.equal(registry.offered('parent_gated', { reads: ownReads({ 'align-self': 'center' }), styledElsewhere: false }, null), true);
});

// A hand-built model over one part candidate with a presence fact, the
// registry tests' element-bound shape.
const boundTo = (element, present, extra = {}) => ({
  candidates: [{ element, key: `${BLOCK}::${element}`, present, stack: null }],
  home: present === false ? null : { element, key: `${BLOCK}::${element}`, stack: null },
  reads: {},
  styledElsewhere: false,
  ...extra,
});

test('offered: the gate receives the control model, so an element-bound gate reads its home part', () => {
  // Gates are (self, parent, model): the gap widget's gate must know
  // WHICH box the control addresses to read that part's child count
  // (self.partChildCount) rather than the selection's own: the model's
  // home box, the first present candidate. The value-set override still
  // rides above it.
  const registry = createControlWidgetRegistry();
  const seen = [];
  registry.register('bound_gated', () => 'x', {
    applicable: (self, parent, model) => {
      seen.push(model.home.element);
      return (self?.partChildCount?.[model.home.element] ?? 2) > 1;
    },
  });
  const bound = boundTo('.daedalus-layout-layout', true);
  const oneBand = { self: { childCount: 1, parts: { '.daedalus-layout-layout': true }, partChildCount: { '.daedalus-layout-layout': 1 } }, parent: null };
  const twoBands = { self: { ...oneBand.self, partChildCount: { '.daedalus-layout-layout': 2 } }, parent: null };
  assert.equal(registry.offered('bound_gated', bound, oneBand), false);
  assert.equal(registry.offered('bound_gated', bound, twoBands), true);
  assert.deepEqual(seen, ['.daedalus-layout-layout', '.daedalus-layout-layout']);
  assert.equal(registry.offered('bound_gated', { ...bound, reads: ownReads({ gap: '1rem' }) }, oneBand), true);
  assert.equal(seen.length, 2);
});

test('offered: a binding whose every candidate is absent hides absolutely — no value-set override', () => {
  // The part-presence gate: presence is an existence question, so a
  // control whose every candidate is a part absent from the canvas (the
  // projection stamped `present: false` from self.parts) hides even with
  // values set or styledElsewhere flagged. The value-set override belongs to
  // widget APPLICABILITY gates only (the tests above).
  const registry = createControlWidgetRegistry();
  registry.register('width', () => 'width');
  const absent = boundTo('.mock-image', false);
  const present = boundTo('.mock-image', true);
  assert.equal(registry.offered('width', present, {}), true);
  assert.equal(registry.offered('width', absent, {}), false);
  // Set values and styledElsewhere do NOT resurrect a missing target.
  assert.equal(registry.offered('width', { ...absent, reads: ownReads({ width: '50%' }) }, {}), false);
  assert.equal(registry.offered('width', { ...absent, styledElsewhere: true }, {}), false);
  // One present candidate among absent ones keeps the binding: the root
  // is always present, so a list naming it can never hide this way.
  const rootToo = {
    ...absent,
    candidates: [...absent.candidates, { element: '', key: BLOCK, present: true, stack: null }],
    home: { element: '', key: BLOCK, stack: null },
  };
  assert.equal(registry.offered('width', rootToo, {}), true);
  // A model without candidates (a host-built one) never consults presence.
  assert.equal(registry.offered('width', { reads: {}, styledElsewhere: false }, {}), true);
});

test('offered: unknown presence degrades to offering, only positive absence hides', () => {
  // Matches the gates' unknown-context posture: a candidate whose
  // presence the projection could not learn (a null layout, a context
  // without parts facts, a parts map that never mentions the selector: a
  // satellite pre-broadcast, a guide/facts skew) carries null and offers
  // rather than guesses.
  const registry = createControlWidgetRegistry();
  registry.register('width', () => 'width');
  assert.equal(registry.offered('width', boundTo('.mock-image', null), null), true);
  assert.equal(registry.offered('width', boundTo('.mock-image', undefined), {}), true);
});

test('offered: presence outranks the gate, and gates still see element-bound models', () => {
  // Order matters: absence hides BEFORE the value-set override or the
  // gate runs; with the part present the pipeline falls through to the
  // ordinary rule (gate consulted when nothing set, set value wins).
  const registry = createControlWidgetRegistry();
  const gateCalls = [];
  registry.register('gated_width', () => 'x', {
    applicable: (self) => {
      gateCalls.push(self);
      return false;
    },
  });
  const present = { self: { hasText: false, parts: { '.mock-image': true } } };
  assert.equal(registry.offered('gated_width', boundTo('.mock-image', false), present), false);
  // The gate never ran for the absent part.
  assert.equal(gateCalls.length, 0);
  // Present part, nothing set: the gate decides.
  assert.equal(registry.offered('gated_width', boundTo('.mock-image', true), present), false);
  assert.equal(gateCalls.length, 1);
  // Present part, value set: the widget-gate value-set override holds.
  assert.equal(registry.offered('gated_width', boundTo('.mock-image', true, { reads: ownReads({ width: '50%' }) }), present), true);
  assert.equal(gateCalls.length, 1);
});

// The projection with a layout context and a drilled part: the trailing
// arguments, named.
const project = (guide, key, styleMap, { blockTypes: types = blockTypes, variants = [], condition = 'default', context = null, targetSelector = null, tree = null } = {}) =>
  controlModels(guide, key, types, styleMap, {}, variants, condition, context, targetSelector, tree);

test('offered: the element-bound Image width model from controlModels gates on presence', () => {
  // End-to-end over the real projection: the guide fixture's width
  // control is bound to '.mock-image', and its model carries the part's
  // presence (read from the layout context at projection) into the gate.
  const registry = createControlWidgetRegistry();
  registry.register('width', () => 'width');
  const styleMap = { [`${BLOCK}::.mock-image`]: { default: { width: '50%' } } };
  const present = project(guide, BLOCK, styleMap, { context: { self: { parts: { '.mock-image': true } } } }).find((model) => model.id === 'width');
  assert.equal(present.home.element, '.mock-image');
  assert.deepEqual(present.candidates.map((candidate) => [candidate.element, candidate.present]), [['.mock-image', true]]);
  assert.deepEqual(litValues(present.reads), { width: '50%' });
  assert.equal(registry.offered('width', present, {}), true);
  // The svg-media case: part absent, control hidden despite the set value.
  const absent = project(guide, BLOCK, styleMap, { context: { self: { parts: { '.mock-image': false } } } }).find((model) => model.id === 'width');
  assert.equal(absent.home, null);
  assert.deepEqual(absent.keys, {});
  assert.equal(registry.offered('width', absent, {}), false);
  // No context at all: unknown presence, offered.
  const unknown = project(guide, BLOCK, styleMap).find((model) => model.id === 'width');
  assert.equal(unknown.candidates[0].present, null);
  assert.equal(registry.offered('width', unknown, null), true);
});

test('registries are isolated: the factory shares nothing', () => {
  const a = createControlWidgetRegistry();
  const b = createControlWidgetRegistry();
  a.register('width', () => 'a');
  assert.equal(b.get('width'), null);
});

test('models come out in declaration order with the full join', () => {
  const models = controlModels(guide, BLOCK, blockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['alignment', 'width']);
  const [alignment, width] = models;
  assert.equal(alignment.label, 'Alignment');
  assert.equal(alignment.widget, 'flex_align');
  assert.equal(alignment.home.element, '.mock-container');
  assert.equal(alignment.home.key, `${BLOCK}::.mock-container`);
  assert.deepEqual(alignment.definition, guide.controlWidgets.flex_align);
  assert.deepEqual(litValues(alignment.reads), {});
  // No group table / no constraints in this guide: the channel is present
  // but empty, so widgets can always read it.
  assert.deepEqual(alignment.constraints, {});
  assert.equal(alignment.styledElsewhere, false);
  // The unposed editing compound echoes on the model.
  assert.equal(alignment.condition, 'default');
  // Label fallback: binding label missing → widget definition label.
  assert.equal(width.label, 'Width');
  assert.equal(width.home.key, `${BLOCK}::.mock-image`);
  // The section: the widget's default, unless the binding moves it.
  assert.equal(alignment.section, 'layout');
  assert.equal(width.section, 'surface');
});

test('section: an undefined widget or an empty override degrades to null / the widget', () => {
  const bare = {
    ...guide,
    blockTypes: {
      image: {
        controls: {
          ghost: { label: 'Ghost', widget: 'no_such_widget', element: [''] },
          blank: { label: 'Blank', widget: 'width', section: '', element: [''] },
        },
      },
    },
  };
  const [ghost, blank] = controlModels(bare, BLOCK, blockTypes, {});
  assert.equal(ghost.section, null);
  assert.equal(blank.section, 'layout');
});

test('controlSections: the roster in weight order; absent or malformed is empty', () => {
  assert.deepEqual(controlSections(guide).map((section) => section.id), ['text', 'surface', 'layout']);
  assert.deepEqual(controlSections(guide)[0], { id: 'text', label: 'Text', weight: 0 });
  assert.deepEqual(controlSections({}), []);
  assert.deepEqual(controlSections({ controlSections: [] }), []);
  // A section without a label reads by id; a non-object entry is skipped.
  assert.deepEqual(
    controlSections({ controlSections: { motion: { weight: 30 }, junk: 'no' } }).map((section) => section.label),
    ['motion'],
  );
});

test('groupControls: roster order, non-empty only, declaration order within, unrostered trail unheaded', () => {
  const sections = controlSections(guide);
  const models = [
    { id: 'a', section: 'layout' },
    { id: 'b', section: 'text' },
    { id: 'c', section: 'layout' },
    { id: 'd', section: null },
    { id: 'e', section: 'sparkle' },
  ];
  const groups = groupControls(models, sections);
  assert.deepEqual(groups.map((group) => group.id), ['text', 'layout', null]);
  assert.deepEqual(groups.map((group) => group.label), ['Text', 'Layout', null]);
  assert.deepEqual(groups.map((group) => group.models.map((model) => model.id)), [['b'], ['a', 'c'], ['d', 'e']]);
  // Nothing unrostered, nothing trailing; nothing at all, no groups.
  assert.deepEqual(groupControls(models.slice(0, 3), sections).map((group) => group.id), ['text', 'layout']);
  assert.deepEqual(groupControls([], sections), []);
  // No roster: every model trails in the one unheaded group.
  assert.deepEqual(groupControls(models, []).map((group) => [group.label, group.models.length]), [[null, 5]]);
});

test('groupControls: a kept section survives empty and leads the roster; keep never invents an unrostered group', () => {
  const sections = controlSections(guide);
  const models = [
    { id: 'a', section: 'text' },
    { id: 'b', section: 'surface' },
  ];
  // The Layout section's host carries structure of its own, so the
  // group renders (empty) for the host to fill, and structure reads
  // FIRST: the hosted group leads every roster section.
  assert.deepEqual(
    groupControls(models, sections, ['layout']).map((group) => [group.id, group.models.length]),
    [['layout', 0], ['text', 1], ['surface', 1]],
  );
  // Kept and populated: the same one group, models intact, still leading.
  assert.deepEqual(
    groupControls([...models, { id: 'c', section: 'layout' }], sections, ['layout']).map((group) => [group.id, group.models.length]),
    [['layout', 1], ['text', 1], ['surface', 1]],
  );
  // Two hosted sections order among themselves by the roster, ahead of
  // the rest; the unheaded trailing group still trails.
  assert.deepEqual(
    groupControls([...models, { id: 'd', section: null }], sections, ['layout', 'text']).map((group) => group.id),
    ['text', 'layout', 'surface', null],
  );
  // Nothing bound at all: the kept group alone.
  assert.deepEqual(groupControls([], sections, ['layout']).map((group) => group.id), ['layout']);
  // A kept id the roster lacks is nothing to render; the unheaded
  // trailing group is never kept.
  assert.deepEqual(groupControls([], sections, ['sparkle']), []);
  assert.deepEqual(groupControls([], [], ['layout']), []);
});

test('reads cover the owned properties alone; the winners light, stringified', () => {
  const styleMap = {
    [`${BLOCK}::.mock-container`]: {
      default: {
        'justify-content': 'center',
        // Not owned by the flex_align fixture: never surfaces in its values.
        padding: '2rem',
      },
    },
  };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.deepEqual(Object.keys(alignment.reads), ['justify-content', 'align-items'], 'one read per owned property');
  assert.deepEqual(litValues(alignment.reads), { 'justify-content': 'center' });
  assert.equal(alignment.reads['justify-content'].winner.tier, 'element');
  assert.equal(ownAtPose(alignment.reads['justify-content'], 'default'), true, 'the own entry delivers at the pose: unsettable');
  assert.equal(alignment.reads['align-items'].winner, null, 'nothing delivers: honestly unset');
  // The stack rides whole: the own layer last, editable.
  assert.equal(alignment.home.stack.key, `${BLOCK}::.mock-container`);
  assert.deepEqual(alignment.home.stack.layers.map((layer) => layer.id), ['element']);
});

test('styledElsewhere flags an owned property under a non-default condition', () => {
  const styleMap = {
    [`${BLOCK}::.mock-container`]: {
      default: { 'justify-content': 'center' },
      hover: { 'align-items': 'flex-end' },
    },
  };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.equal(alignment.styledElsewhere, true);
  // The default values still show — the hint is additive, never a veil.
  assert.deepEqual(litValues(alignment.reads), { 'justify-content': 'center' });
});

test('styledElsewhere ignores condition values of properties the widget does not own', () => {
  const styleMap = {
    [`${BLOCK}::.mock-container`]: {
      default: {},
      hover: { padding: '1rem' },
    },
  };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.equal(alignment.styledElsewhere, false);
});

test('each control reads its OWN bound element, not its siblings', () => {
  const styleMap = {
    [`${BLOCK}::.mock-image`]: {
      default: { width: '50%' },
      narrow: { width: '100%' },
    },
  };
  const [alignment, width] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.deepEqual(litValues(alignment.reads), {});
  assert.equal(alignment.styledElsewhere, false);
  assert.deepEqual(litValues(width.reads), { width: '50%' });
  assert.equal(width.styledElsewhere, true);
});

test('the posed compound threads through: reads taken at it, condition echoed', () => {
  // The one write rule: basic reads (and the pipeline writes) at the
  // editing compound the canvas pose derives, not a pinned default.
  const styleMap = {
    [`${BLOCK}::.mock-image`]: {
      default: { width: '50%' },
      narrow: { width: '100%' },
    },
  };
  const [alignment, width] = controlModels(guide, BLOCK, blockTypes, styleMap, {}, [], 'narrow');
  assert.equal(width.condition, 'narrow');
  assert.deepEqual(litValues(width.reads), { width: '100%' });
  assert.equal(width.reads.width.winner.condition, 'narrow');
  // Posed at narrow with only a Base block, the Base value cascades in
  // as the lit value (inherited from Base, not unsettable here).
  const baseOnly = { [`${BLOCK}::.mock-image`]: { default: { width: '50%' } } };
  const [, cascaded] = controlModels(guide, BLOCK, blockTypes, baseOnly, {}, [], 'narrow');
  assert.deepEqual(litValues(cascaded.reads), { width: '50%' });
  assert.equal(cascaded.reads.width.winner.condition, 'default');
  assert.equal(ownAtPose(cascaded.reads.width, 'narrow'), false);
  // Every model echoes the compound, styled at it or not.
  assert.equal(alignment.condition, 'narrow');
  assert.deepEqual(litValues(alignment.reads), {});
});

test('styledElsewhere is pose-relative: styling elsewhere flags, the posed compound never', () => {
  const styleMap = {
    [`${BLOCK}::.mock-image`]: {
      default: { width: '50%' },
      narrow: { width: '100%' },
    },
  };
  // Posed at narrow, the default block is "more elsewhere" — default is
  // just another compound (of zero parts) once the pose is the target.
  const [, width] = controlModels(guide, BLOCK, blockTypes, styleMap, {}, [], 'narrow');
  assert.equal(width.styledElsewhere, true);
  // Styled ONLY at the posed compound: everything shows, no hint.
  const narrowOnly = { [`${BLOCK}::.mock-image`]: { narrow: { width: '100%' } } };
  const [, posedOnly] = controlModels(guide, BLOCK, blockTypes, narrowOnly, {}, [], 'narrow');
  assert.equal(posedOnly.styledElsewhere, false);
  assert.deepEqual(litValues(posedOnly.reads), { width: '100%' });
});

test('PHP empty-array entries ([] instead of {}) read as no values', () => {
  const styleMap = { [`${BLOCK}::.mock-container`]: [] };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.deepEqual(litValues(alignment.reads), {});
  assert.equal(alignment.styledElsewhere, false);
});

test('no controls anywhere: undeclared block type, kinds, bad keys, sub keys', () => {
  // A block whose type declares no controls (falls back to kinds.block).
  assert.deepEqual(controlModels(guide, BLOCK, {}, {}), []);
  // Non-block kinds declare no controls in this guide.
  assert.deepEqual(controlModels(guide, `entity:${UUID_A}`, blockTypes, {}), []);
  // Unparseable and sub-element keys have no controls of their own.
  assert.deepEqual(controlModels(guide, 'garbage', blockTypes, {}), []);
  assert.deepEqual(controlModels(guide, null, blockTypes, {}), []);
  assert.deepEqual(controlModels(guide, `${BLOCK}::.mock-image`, blockTypes, {}), []);
});

test('kinds may declare controls too — resolution is declaration-shaped', () => {
  const kindGuide = {
    ...guide,
    kinds: {
      block: {
        elements: { '.inner': { grants: { layout: LAYOUT } } },
        controls: {
          inner_width: { label: 'Inner width', widget: 'width', element: ['.inner'] },
        },
      },
    },
  };
  // An untyped (plugin) block resolves against the universal block kind.
  const models = controlModels(kindGuide, BLOCK, {}, {});
  assert.deepEqual(models.map((model) => model.id), ['inner_width']);
  assert.equal(models[0].home.key, `${BLOCK}::.inner`);
});

const ENTITY = `entity:${UUID_A}`;

test('a non-block key resolves kind-level controls as root bindings', () => {
  // A kind control with no element key binds the root, so an entity kind
  // control targets the plain selectable key and reads the entity
  // entry's own values from the style map.
  const entityGuide = {
    ...guide,
    kinds: {
      ...guide.kinds,
      entity: {
        grants: { layout: LAYOUT },
        controls: {
          width: { label: 'Page width', widget: 'width', element: [''] },
        },
      },
    },
  };
  const styleMap = { [ENTITY]: { default: { width: '80%' } } };
  const models = controlModels(entityGuide, ENTITY, blockTypes, styleMap);
  assert.deepEqual(models.map((model) => model.id), ['width']);
  const [width] = models;
  assert.equal(width.label, 'Page width');
  assert.equal(width.home.element, '');
  assert.equal(width.home.key, ENTITY);
  assert.deepEqual(litValues(width.reads), { width: '80%' });
  assert.equal(width.styledElsewhere, false);
});

test('an entity kind control may bind a declared part: the page\'s Gap over the layout root', () => {
  // The server accepts an element key on a kind control when the kind's
  // own declaration declares the part (the layout root daedalus_layout renders);
  // the client resolves it like any part binding: the key is the part's,
  // the values, grants and constraints the part's, never the root's.
  const SPACING = ['gap', 'row-gap', 'column-gap'];
  const entityGuide = {
    ...guide,
    kinds: {
      ...guide.kinds,
      entity: {
        grants: { spacing: ['padding', 'gap'] },
        elements: {
          '.daedalus-layout-layout': {
            label: 'Layout',
            grants: { spacing: SPACING },
            valueSpaces: { gap: { min: 0 } },
          },
        },
        controls: {
          gap: { label: 'Gap', widget: 'gap', element: ['.daedalus-layout-layout'], section: 'layout' },
        },
      },
    },
    controlWidgets: {
      ...guide.controlWidgets,
      gap: { label: 'Gap', section: 'layout', properties: SPACING },
    },
  };
  const styleMap = {
    [ENTITY]: { default: { gap: '1rem' } },
    [`${ENTITY}::.daedalus-layout-layout`]: { default: { gap: '2rem' }, hover: { 'row-gap': '3rem' } },
  };
  const models = controlModels(entityGuide, ENTITY, blockTypes, styleMap);
  assert.deepEqual(models.map((model) => model.id), ['gap']);
  const [gap] = models;
  assert.equal(gap.label, 'Gap');
  assert.equal(gap.home.element, '.daedalus-layout-layout');
  assert.equal(gap.home.key, `${ENTITY}::.daedalus-layout-layout`);
  assert.deepEqual(litValues(gap.reads), { gap: '2rem' });
  assert.deepEqual(gap.granted, SPACING);
  assert.deepEqual(gap.constraints, { gap: { min: 0 } });
  assert.equal(gap.section, 'layout');
  // A longhand set at another compound flags the hint like any owned
  // property.
  assert.equal(gap.styledElsewhere, true);
});

test('a raw false tombstone in a kind declaration suppresses silently', () => {
  // Kind declarations reach the client unmerged, so a `false` control is
  // raw tombstone syntax, not malformed input: dropped without a model,
  // valid siblings untouched.
  const tombstoneGuide = {
    ...guide,
    kinds: {
      ...guide.kinds,
      entity: {
        grants: { layout: LAYOUT },
        controls: {
          spacing: false,
          width: { label: 'Page width', widget: 'width', element: [''] },
        },
      },
    },
  };
  const models = controlModels(tombstoneGuide, ENTITY, blockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['width']);
});

test('a false tombstone kept in an effective block-type declaration suppresses', () => {
  // ClientStyleGuide ships EFFECTIVE block-type declarations and keeps the
  // tombstone in them (suppressed and never-declared are different states
  // server-side); the client must still render nothing for it.
  const tombstoneGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: {
          alignment: false,
          width: { widget: 'width', element: ['.mock-image'] },
        },
      },
    },
  };
  const models = controlModels(tombstoneGuide, BLOCK, blockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['width']);
});

test('a declaration whose every control is tombstoned models nothing', () => {
  const allFalseGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: { alignment: false, width: false },
      },
    },
  };
  assert.deepEqual(controlModels(allFalseGuide, BLOCK, blockTypes, {}), []);
});

// A guide whose image block declares one part-bound and one ROOT-bound
// control (no element key): the root binding's write key is the plain
// selectable key — the block's own map entry.
const rootGuide = {
  ...guide,
  blockTypes: {
    image: {
      ...guide.blockTypes.image,
      controls: {
        alignment: {
          label: 'Alignment',
          widget: 'flex_align',
          element: ['.mock-container'],
        },
        width: { label: 'Width', widget: 'width', element: [''] },
      },
    },
  },
};

test('a root binding (no element) targets the plain selectable key', () => {
  const styleMap = {
    [BLOCK]: { default: { width: '50%', padding: '1rem' } },
    [`${BLOCK}::.mock-container`]: { default: { 'justify-content': 'center' } },
  };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap);
  assert.equal(width.home.element, '');
  assert.equal(width.home.key, BLOCK);
  assert.deepEqual(litValues(width.reads), { width: '50%' });
  assert.equal(width.styledElsewhere, false);
  // The part-bound sibling is untouched by the root grammar.
  assert.equal(alignment.home.element, '.mock-container');
  assert.equal(alignment.home.key, `${BLOCK}::.mock-container`);
  assert.deepEqual(litValues(alignment.reads), { 'justify-content': 'center' });
});

// The constraint channel: a guide whose group table + declarations carry
// constraints. The image block constrains width differently on its root
// and its image part; the entity kind constrains it too (a root-bound
// kind control reads the kind's own node).
const constrainedGuide = {
  groups: {
    sizing: { label: 'Sizing', properties: { width: { label: 'Width', type: 'dimension' } } },
    layout: { label: 'Layout', properties: { 'justify-content': {}, 'align-items': {} } },
  },
  kinds: {
    block: { grants: { sizing: ['width'] } },
    entity: {
      grants: { sizing: ['width'] },
      valueSpaces: { width: { allowed: ['40%', '80%'] } },
      controls: {
        width: { label: 'Page width', widget: 'width', element: [''] },
      },
    },
  },
  blockTypes: {
    image: {
      grants: { sizing: ['width'], layout: ['justify-content', 'align-items'] },
      valueSpaces: { width: { allowed: ['25%', '50%'] } },
      elements: {
        '.mock-image': {
          grants: { sizing: ['width'] },
          valueSpaces: { width: { max: '600px', units: ['px'] } },
        },
        '.mock-container': {
          grants: { layout: ['justify-content', 'align-items'] },
        },
      },
      controls: {
        width: { label: 'Width', widget: 'width', element: [''] },
        image_width: { label: 'Image width', widget: 'width', element: ['.mock-image'] },
        alignment: { label: 'Alignment', widget: 'flex_align', element: ['.mock-container'] },
      },
    },
  },
  controlWidgets: {
    width: { label: 'Width', properties: ['width'] },
    flex_align: { label: 'Flex align', properties: ['justify-content', 'align-items'] },
  },
};

test('constraints resolve against the bound TARGET, root and part apart', () => {
  const [root, part, alignment] = controlModels(constrainedGuide, BLOCK, blockTypes, {});
  // Root binding: the effective declaration's own constraints.
  assert.deepEqual(root.constraints, { width: { allowed: ['25%', '50%'] } });
  // Part binding: the elements entry's constraints, never the parent's —
  // and every constraint shape passes through ({max, units} here), not
  // just allowed; widgets pick what they use.
  assert.deepEqual(part.constraints, { width: { max: '600px', units: ['px'] } });
  // Owned properties with no constraints stay absent: presence IS the signal.
  assert.deepEqual(alignment.constraints, {});
});

test('kind-level root controls resolve the kind declaration\'s constraints', () => {
  const [width] = controlModels(constrainedGuide, ENTITY, blockTypes, {});
  assert.deepEqual(width.constraints, { width: { allowed: ['40%', '80%'] } });
});

test('site constraint defaults stand in wherever a node authors no block', () => {
  // The offer vocabulary's lookup layer (resolvedConstraintsFor): the
  // payload's constraintDefaults are the base layer under every bound
  // target's own constraints, so a site-level binding shapes stop rows
  // everywhere without per-node authoring. Node blocks win WHOLE.
  const guide = {
    ...constrainedGuide,
    constraintDefaults: {
      width: { scale: 'size' },
      'justify-content': { allowed: ['start', 'center'] },
    },
  };
  const [root, part, alignment] = controlModels(guide, BLOCK, blockTypes, {});
  // Both width targets author their own blocks: the default never leaks in.
  assert.deepEqual(root.constraints, { width: { allowed: ['25%', '50%'] } });
  assert.deepEqual(part.constraints, { width: { max: '600px', units: ['px'] } });
  // The alignment part authors nothing: the site default reaches its row.
  assert.deepEqual(alignment.constraints, { 'justify-content': { allowed: ['start', 'center'] } });
});

test('propertyDefinitions carry the group table\'s per-property type facts', () => {
  const [root, part, alignment] = controlModels(constrainedGuide, BLOCK, blockTypes, {});
  // Definitions are guide-global facts: root and part bindings read the
  // same group-table entry (constraints differ per declaration,
  // definitions never do). The widget layer's type channel — a dimension
  // reveal reads type/negative/number for clamping and units.
  assert.deepEqual(root.propertyDefinitions, { width: { label: 'Width', type: 'dimension' } });
  assert.deepEqual(part.propertyDefinitions, root.propertyDefinitions);
  assert.deepEqual(alignment.propertyDefinitions, { 'justify-content': {}, 'align-items': {} });
  // No group table in the guide: the channel is present but empty, so
  // widgets can always read it.
  const [plain] = controlModels(guide, BLOCK, blockTypes, {});
  assert.deepEqual(plain.propertyDefinitions, {});
});

test('granted curates the owned properties to the target\'s grant', () => {
  // The bound target's served node grants exactly the names the roster
  // resolved (a narrowed grant already reduced server-side); the model
  // carries the granted subset so widgets (the spacing padding row)
  // curate their rows to it. A target granting the widget's whole
  // family grants everything the widget owns.
  const narrowed = {
    ...guide,
    groups: {
      layout: { label: 'Layout', properties: { 'justify-content': {}, 'align-items': {}, width: {} } },
    },
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        elements: {
          '.mock-container': {
            label: 'Container',
            grants: { layout: ['justify-content'] },
          },
          '.mock-image': { label: 'Image', grants: { layout: LAYOUT } },
        },
      },
    },
  };
  const [alignment, width] = controlModels(narrowed, BLOCK, blockTypes, {});
  assert.deepEqual(alignment.granted, ['justify-content'], 'the narrowed grant withholds align-items');
  assert.deepEqual(width.granted, ['width'], 'the whole family on the image part: everything owned is granted');
  const [plainAlignment] = controlModels(guide, BLOCK, blockTypes, {});
  assert.deepEqual(plainAlignment.granted, ['justify-content', 'align-items']);
});

test('a NESTED part candidate resolves grants and constraints against its own node', () => {
  // Parts nest (the theme's menu declares .menu > .menu-item >
  // .menu-link, and the menu's bindings address .menu-link), and the
  // server validates candidates over the same depth-first find. The
  // projection must resolve the candidate's node the same way: a flat
  // lookup would find nothing, grant nothing and constrain nothing, and
  // the spacing widget would drop its padding row on a fully granted part.
  const nested = {
    ...guide,
    groups: {
      layout: { label: 'Layout', properties: { 'justify-content': {}, 'align-items': {}, width: {} } },
    },
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        elements: {
          '.mock-container': {
            label: 'Container',
            grants: { layout: LAYOUT },
            elements: {
              '.mock-item': {
                label: 'Item',
                grants: { layout: LAYOUT },
                elements: {
                  '.mock-image': {
                    label: 'Image',
                    grants: { layout: ['width'] },
                    valueSpaces: { width: { min: 0 } },
                  },
                },
              },
            },
          },
        },
      },
    },
  };
  const width = controlModels(nested, BLOCK, blockTypes, {}).find((model) => model.id === 'width');
  assert.equal(width.home.element, '.mock-image');
  assert.deepEqual(width.granted, ['width'], 'the nested part\'s own grant, found at depth');
  assert.deepEqual(width.constraints, { width: { min: 0 } }, 'the nested part\'s own constraints, found at depth');
});

test('an UNRESOLVED variant reference sets styledElsewhere (no library, no lies)', () => {
  // A variant reference carries no inline styles — the styling lives in
  // the library. With no library (still fetching, or the variant is gone)
  // the widget shows no values but MUST NOT read as "no styles": styledElsewhere
  // renders the honesty hint.
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap);
  assert.equal(width.home.key, BLOCK);
  assert.deepEqual(litValues(width.reads), {});
  assert.equal(width.styledElsewhere, true);
});

test('an unresolvable reference keeps its hint beside own posed values', () => {
  // Own values at the compound do not retire the hint: the variant's
  // styling stays unshowable no matter what the own entry carries.
  const styleMap = {
    [BLOCK]: {
      variants: [{ variant: 'hero_wide', fragment: '' }],
      default: { width: '50%' },
    },
  };
  const [, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap);
  assert.deepEqual(litValues(width.reads), { width: '50%' });
  assert.equal(width.styledElsewhere, true);
});

// The resolver join: the store's variant records, whose variant
// styles the element's root ('') and its container part.
const library = [{
  id: 'hero_wide',
  label: 'Hero wide',
  scope: 'block_type:image',
  value: {
    '': { default: { width: '80%', padding: '2rem' } },
    '::.mock-container': { default: { 'justify-content': 'center' } },
  },
}];

test('a RESOLVED variant reference lights its values, root and part bindings', () => {
  // A worn variant is a layer of the stack: an on-variant element's
  // widgets light the variant's values exactly like a custom-styled
  // one's, the read's winner naming the variant layer (the row's mark
  // wears its hue). No styledElsewhere: the values ARE shown now. Not
  // unsettable: the own entry says nothing here.
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap, {}, library);
  assert.deepEqual(litValues(width.reads), { width: '80%' });
  assert.equal(width.reads.width.winner.tier, 'variant');
  assert.equal(width.reads.width.winner.variant, 'hero_wide');
  assert.equal(ownAtPose(width.reads.width, 'default'), false);
  assert.deepEqual(width.home.stack.layers.map((layer) => layer.id), ['variant:hero_wide', 'element']);
  assert.equal(width.styledElsewhere, false);
  // The part binding resolves the variant's element-relative part styles.
  assert.deepEqual(litValues(alignment.reads), { 'justify-content': 'center' });
  assert.equal(alignment.styledElsewhere, false);
});

test('the stack carries the skin beneath the variant for a root and a part binding', () => {
  // The bound TARGET's stack, built for the model's key itself: a root
  // binding stacks the skin's root block, the variant's root layer and
  // the root's own entry; a part binding the skin's `::.part` block,
  // the variant's part layer and the part's own entry, never the root's.
  // Every per-side and per-property read (the box and stroke models,
  // the rows) is over this one stack.
  const skinned = {
    ...rootGuide,
    skinDefaults: {
      'block_type:image': {
        '': { default: { width: '60%' } },
        '::.mock-container': { default: { 'justify-content': 'start' } },
      },
    },
  };
  const styleMap = {
    [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }], default: { width: '50%' } },
    [`${BLOCK}::.mock-container`]: { narrow: { 'justify-content': 'end' } },
  };
  const [alignment, width] = controlModels(skinned, BLOCK, blockTypes, styleMap, {}, library);
  assert.deepEqual(width.home.stack.layers.map((layer) => layer.id), ['skin:block_type:image', 'variant:hero_wide', 'element']);
  assert.deepEqual(width.home.stack.layers.map((layer) => layer.styles), [
    { default: { width: '60%' } },
    { default: { width: '80%', padding: '2rem' } },
    { default: { width: '50%' } },
  ], 'root: the skin\'s root block, the variant\'s root layer, the root\'s own entry');
  assert.deepEqual(alignment.home.stack.layers.map((layer) => layer.id), ['skin:block_type:image', 'variant:hero_wide', 'element']);
  assert.deepEqual(alignment.home.stack.layers.map((layer) => layer.styles), [
    { default: { 'justify-content': 'start' } },
    { default: { 'justify-content': 'center' } },
    { narrow: { 'justify-content': 'end' } },
  ], 'part: the skin\'s part block, the variant\'s part layer, the part\'s own entry');
  assert.equal(width.home.stack.layers[1].label, 'Hero wide', 'a variant layer carries its label for the marks');
  // The skin's value is a lit winner where nothing above it delivers:
  // the part's justify-content at Base is the variant's center (the own
  // Narrow block does not reach Base), the root's width the own 50%.
  assert.equal(width.reads.width.winner.tier, 'element', 'root: the own width beats the variant and the skin');
  assert.deepEqual(width.reads.width.beneath.map((entry) => entry.value), ['80%', '60%'], 'what returns on unset, nearest first');
  assert.equal(alignment.reads['justify-content'].winner.value, 'center', 'part: the variant beats the skin');
  const [partSkin] = controlModels(skinned, BLOCK, blockTypes, { [BLOCK]: {} }, {}, library);
  assert.equal(partSkin.reads['justify-content'].winner.tier, 'skin', 'part: with no wear, the skin lights');
  assert.equal(partSkin.reads['justify-content'].winner.value, 'start');
  // No variants, no skin: the own layer alone.
  const [, bare] = controlModels(rootGuide, BLOCK, blockTypes, { [BLOCK]: { default: { width: '50%' } } });
  assert.deepEqual(bare.home.stack.layers.map((layer) => layer.id), ['element']);
  assert.equal(bare.home.stack.skin, false);
});

test('the choice row\'s origin at a pose: the defaults tier delivers from Base, lit, and the sentence says where', () => {
  // The basic rows' origin derivation (choice-row-model.js originFor
  // over the model's stack read), posed at Medium: nothing in the map
  // sets max-width or display, so the skin's Base block delivers
  // max-width and the image declaration's Base block display, each lit
  // as the row's value and named with where it delivers from. The
  // sentences are display vocabulary, so Drupal.t is scoped to this
  // test alone: controlModels itself must never need it.
  const posed = {
    ...rootGuide,
    conditions: { medium: { label: 'Medium', axis: 'width' } },
    skinDefaults: { 'block_type:image': { '': { default: { 'max-width': '90%' } } } },
    declaredDefaults: { 'block_type:image': { '': { default: { display: 'block' } } } },
  };
  const styleMap = { [BLOCK]: { default: { width: '50%' } } };
  const [, width] = controlModels(posed, BLOCK, blockTypes, styleMap, {}, [], 'medium');
  // A row over an owned property hands its read; a row over another
  // property of the same target reads the stack itself.
  const rowAt = (model, condition, property) => originFor({
    guide: posed, materials: EMPTY_MATERIALS, condition, property, stack: model.home.stack, read: model.reads[property] ?? null,
  });
  assert.deepEqual(width.home.stack.layers.map((layer) => layer.id), ['declared:block_type:image', 'skin:block_type:image', 'element']);
  const skin = rowAt(width, 'medium', 'max-width');
  assert.equal(skin.delivers, true);
  assert.equal(skin.layer, 'skin:block_type:image');
  assert.equal(skin.title, '90% from the active skin at Base');
  assert.deepEqual(skin.doorways.map((doorway) => doorway.id), ['skin'], 'the skin session, never a drive');
  const declared = rowAt(width, 'medium', 'display');
  assert.equal(declared.delivers, true);
  assert.equal(declared.layer, 'declared:block_type:image');
  assert.equal(declared.title, 'block from the image block type declaration at Base');
  assert.deepEqual(declared.doorways.map((doorway) => [doorway.id, doorway.scope, doorway.property]), [['declared', 'block_type:image', 'display']], 'a declaration is code: the one doorway opens the Law tab at it');
  // The map's own Base value still beats the tier at Medium: lit,
  // inherited from Base, not unsettable at Medium.
  assert.equal(rowAt(width, 'medium', 'width').title, '50% from Base');
  assert.equal(litValues(width.reads).width, '50%');
  assert.equal(ownAtPose(width.reads.width, 'medium'), false);
  // Nothing anywhere: not set.
  assert.equal(rowAt(width, 'medium', 'color').delivers, false);
  assert.equal(rowAt(width, 'medium', 'color').title, 'Not set');
  // Posed at Base the tail drops: the delivering condition is the pose.
  const [, atBase] = controlModels(posed, BLOCK, blockTypes, styleMap, {}, [], 'default');
  assert.equal(rowAt(atBase, 'default', 'display').title, 'block from the image block type declaration');
  assert.equal(rowAt(atBase, 'default', 'max-width').title, '90% from the active skin');
  // A value set at the pose is the row's own, unsettable.
  assert.equal(rowAt(atBase, 'default', 'width').atPose, true);
  assert.equal(ownAtPose(atBase.reads.width, 'default'), true);
  // An own value over the skin's at the pose: overriding, with the
  // unset doorway naming what returns.
  const overGuide = { ...posed, skinDefaults: { 'block_type:image': { '': { default: { width: '60%' } } } } };
  const [, over] = controlModels(overGuide, BLOCK, blockTypes, styleMap, {}, [], 'default');
  const overriding = rowAt(over, 'default', 'width');
  assert.equal(overriding.atPose, true);
  assert.equal(overriding.title, "Your 50% overrides the active skin's 60%");
  assert.deepEqual(overriding.doorways.map((doorway) => doorway.id), ['unset', 'skin']);
  assert.deepEqual(over.reads.width.beneath.map((entry) => [entry.tier, entry.value]), [['skin', '60%']]);
});

test('a resolved variant with nothing for the bound part reads as unset', () => {
  const partless = [{ ...library[0], value: { '': { default: { width: '80%' } } } }];
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap, {}, partless);
  assert.deepEqual(litValues(width.reads), { width: '80%' });
  // The variant simply does not style the part: honest empty, no hint.
  assert.deepEqual(litValues(alignment.reads), {});
  assert.equal(alignment.styledElsewhere, false);
});

test('a resolved variant\'s conditions flag styledElsewhere like an element\'s own; a tier\'s never do', () => {
  const conditioned = [{
    ...library[0],
    value: {
      '': { default: { width: '80%' }, narrow: { width: '100%' } },
      '::.mock-container': { narrow: { 'justify-content': 'safe center' } },
    },
  }];
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap, {}, conditioned);
  assert.deepEqual(litValues(width.reads), { width: '80%' });
  assert.equal(width.styledElsewhere, true);
  assert.equal(alignment.styledElsewhere, true);
  // A skin's Narrow block is a default beneath the map, not styling
  // the hint should send the author to: no hint.
  const skinned = { ...rootGuide, skinDefaults: { 'block_type:image': { '': { narrow: { width: '100%' } } } } };
  const [, tiered] = controlModels(skinned, BLOCK, blockTypes, { [BLOCK]: {} }, {}, []);
  assert.equal(tiered.styledElsewhere, false);
  assert.equal(tiered.reads.width.winner, null, 'the Narrow block does not reach Base');
});

test('cross-axis overlap resolves in DELIVERY order, not stored-list order', () => {
  // The controls lane reads through effectiveEntry WITH the scope's axis
  // vocabulary: two worn variants overlapping on an owned property resolve
  // to the delivery winner (axis-declaration order, later axis beats
  // earlier), matching the inspector and code-view lanes. Stored-list
  // order is deliberately the OPPOSITE here: loud (emphasis, position 1)
  // is FIRST in the list, calm (style, position 0) last, so a list-order
  // merge would show calm's width. Delivery sorts loud last; loud wins.
  const axedGuide = {
    ...rootGuide,
    blockTypes: {
      image: {
        ...rootGuide.blockTypes.image,
        axes: axesOf(['style', 'emphasis']),
      },
    },
  };
  const axed = [
    {
      id: 'loud',
      label: 'Loud',
      scope: 'block_type:image',
      group: 'emphasis',
      value: { '': { default: { width: '100%' } } },
    },
    {
      id: 'calm',
      label: 'Calm',
      scope: 'block_type:image',
      group: 'style',
      value: { '': { default: { width: '25%' } } },
    },
  ];
  const styleMap = { [BLOCK]: { variants: [{ variant: 'loud', fragment: '' }, { variant: 'calm', fragment: '' }] } };
  const [, width] = controlModels(axedGuide, BLOCK, blockTypes, styleMap, {}, axed);
  assert.deepEqual(litValues(width.reads), { width: '100%' });
});

test('root styledElsewhere still flags conditions, same as parts', () => {
  const conditioned = { [BLOCK]: { default: { width: '50%' }, narrow: { width: '100%' } } };
  assert.equal(controlModels(rootGuide, BLOCK, blockTypes, conditioned)[1].styledElsewhere, true);
});

test('the served element is a list: a scalar, an absent, an empty or a non-string entry is malformed', () => {
  // The client receives lists only (the server normalizes every binding
  // before serving it), so the pre-list spellings are malformed input
  // here, not shorthands: an absent key, a string, null, a number, an
  // empty list, a list with a non-string entry. The root is spelled [''].
  const brokenGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: {
          absent_element: { widget: 'width' },
          string_element: { widget: 'width', element: '.mock-image' },
          empty_string: { widget: 'width', element: '' },
          null_element: { widget: 'width', element: null },
          numeric_element: { widget: 'width', element: 3 },
          empty_list: { widget: 'width', element: [] },
          numeric_entry: { widget: 'width', element: ['', 3] },
          root: { widget: 'width', element: [''] },
        },
      },
    },
  };
  const models = controlModels(brokenGuide, BLOCK, blockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['root']);
  assert.equal(models[0].home.element, '');
  assert.equal(models[0].home.key, BLOCK);
});

test('malformed bindings are dropped, valid siblings survive', () => {
  const brokenGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: {
          not_a_map: 'nope',
          no_widget: { element: ['.mock-image'] },
          bad_element: { widget: 'width', element: ['div > .loose'] },
          bad_second: { widget: 'width', element: ['.mock-image', 'div > .loose'] },
          good: { label: 'Good', widget: 'width', element: ['.mock-image'] },
        },
      },
    },
  };
  const models = controlModels(brokenGuide, BLOCK, blockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['good']);
});

test('an unknown widget id still models (label falls back to the id, no owned values)', () => {
  const strangerGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: {
          mystery: { widget: 'not_installed', element: ['.mock-image'] },
        },
      },
    },
  };
  const styleMap = {
    [`${BLOCK}::.mock-image`]: { default: { width: '50%' }, hover: { width: '25%' } },
  };
  const [mystery] = controlModels(strangerGuide, BLOCK, blockTypes, styleMap);
  assert.equal(mystery.label, 'not_installed');
  assert.equal(mystery.definition, null);
  // No definition → no owned properties → no reads, nothing to flag from
  // structured conditions (the registry gate keeps it unrendered anyway).
  assert.deepEqual(mystery.reads, {});
  assert.equal(mystery.styledElsewhere, false);
});

// ---- Instance-part sub keys: the instances lane's curated surface. -------
// An instance part (a minted text element, 'block:{uuid}::.s-a3') models
// the covering namespace's controls, each bound to the part key ITSELF —
// basic mode styles the selected paragraph, never its owner. Row selection
// and canvas opt+click both land on the same drilled key, so one
// projection serves both panels.

const INSTANCE = `${BLOCK}::.s-a3`;
const instanceGuide = {
  groups: {
    typography: {
      label: 'Typography',
      properties: { 'font-size': { label: 'Font size', type: 'dimension' }, color: { label: 'Text color', type: 'color' } },
    },
  },
  kinds: { block: {}, field: {} },
  blockTypes: {
    formatted_text: {
      controls: {
        // The block's OWN root control: it must never leak onto a
        // drilled instance part, and the part's controls never onto the
        // root.
        width: { label: 'Width', widget: 'width', element: [''] },
      },
    },
  },
  instances: {
    '.s-*': {
      label: 'Text element',
      grants: { typography: ['font-size', 'color'] },
      valueSpaces: { 'font-size': { scale: 'type' } },
      controls: {
        typography: { label: 'Typography', widget: 'typography', element: [''] },
        text_color: { label: 'Text color', widget: 'text_color', element: [''] },
      },
    },
  },
  controlWidgets: {
    width: { label: 'Width', properties: ['width'] },
    typography: { label: 'Typography', properties: ['font-size', 'font-weight', 'line-height'] },
    text_color: { label: 'Text color', properties: ['color'] },
  },
};
const textBlockTypes = { [UUID_A]: 'formatted_text' };

test('an instance-part key models the namespace controls, bound to the part key', () => {
  const styleMap = {
    [BLOCK]: { default: { width: '50%' } },
    [INSTANCE]: { default: { 'font-size': '1.25rem', color: 'teal' } },
  };
  const models = controlModels(instanceGuide, INSTANCE, textBlockTypes, styleMap);
  assert.deepEqual(models.map((model) => model.id), ['typography', 'text_color']);
  const [typography, textColor] = models;
  // Root-bound onto the part: element is null, the key IS the drilled key.
  assert.equal(typography.home.element, '');
  assert.equal(typography.home.key, INSTANCE);
  assert.deepEqual(litValues(typography.reads), { 'font-size': '1.25rem' });
  assert.deepEqual(litValues(textColor.reads), { color: 'teal' });
  // The owner's root control never leaks onto the part…
  assert.equal(models.some((model) => model.id === 'width'), false);
  // …and the part's controls never onto the root.
  assert.deepEqual(controlModels(instanceGuide, BLOCK, textBlockTypes, styleMap).map((model) => model.id), ['width']);
});

test('both owner shapes resolve: field-key instance parts model identically', () => {
  // Open-render carriers ride field keys ('block:{uuid}:{field}::.s-a3');
  // the instances fallback covers ANY owner kind, same as the validator.
  const fieldInstance = `block:${UUID_A}:field_text::.s-a3`;
  const models = controlModels(instanceGuide, fieldInstance, textBlockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['typography', 'text_color']);
  assert.equal(models[0].home.key, fieldInstance);
});

test('constraints and definitions resolve against the namespace declaration', () => {
  const [typography] = controlModels(instanceGuide, INSTANCE, textBlockTypes, {});
  assert.deepEqual(typography.constraints, { 'font-size': { scale: 'type' } });
  assert.deepEqual(typography.propertyDefinitions, { 'font-size': { label: 'Font size', type: 'dimension' } });
});

test('the posed compound threads through instance parts, styledElsewhere included', () => {
  const styleMap = {
    [INSTANCE]: {
      default: { 'font-size': '1rem' },
      narrow: { 'font-size': '0.875rem' },
    },
  };
  const posed = controlModels(instanceGuide, INSTANCE, textBlockTypes, {}, {}, [], 'narrow');
  assert.equal(posed[0].condition, 'narrow');
  const [typography] = controlModels(instanceGuide, INSTANCE, textBlockTypes, styleMap);
  assert.deepEqual(litValues(typography.reads), { 'font-size': '1rem' });
  assert.equal(typography.styledElsewhere, true, 'the narrow styling flags at the default pose');
});

test('the owner\'s own part declarations shadow the namespace (shell subs model nothing)', () => {
  // The one resolution rule: exact part, then owner pattern part, then
  // instances. Either owner rung makes the sub a SHELL part, whose
  // curated surface is the owner's lift — never controls of its own.
  const exact = {
    ...instanceGuide,
    blockTypes: {
      formatted_text: {
        ...instanceGuide.blockTypes.formatted_text,
        elements: { '.s-a3': { label: 'Shell' } },
      },
    },
  };
  assert.deepEqual(controlModels(exact, INSTANCE, textBlockTypes, {}), []);
  const pattern = {
    ...instanceGuide,
    blockTypes: {
      formatted_text: {
        ...instanceGuide.blockTypes.formatted_text,
        elements: { '.s-*': { label: 'Owner namespace' } },
      },
    },
  };
  assert.deepEqual(controlModels(pattern, INSTANCE, textBlockTypes, {}), []);
});

test('no covering namespace, children keys: sub keys model nothing', () => {
  // An undeclared namespace resolves no controls (the advanced grammar
  // still styles it on open sites; basic just has nothing curated)…
  const bare = { ...instanceGuide, instances: {} };
  assert.deepEqual(controlModels(bare, INSTANCE, textBlockTypes, {}), []);
  // …the namespace prefix alone is not an instance of it…
  assert.deepEqual(controlModels(instanceGuide, `${BLOCK}::.s-`, textBlockTypes, {}), []);
  // …and the '> *' children selector is no basic control target.
  assert.deepEqual(controlModels(instanceGuide, `${INSTANCE}::> *`, textBlockTypes, {}), []);
});

test('instance controls are root-bound: an element binding is malformed and drops', () => {
  // Mirrors the server rule (an instance is content; there are no parts
  // to drill into): the malformed binding drops, valid siblings survive,
  // and a FALSE tombstone suppresses silently like everywhere else.
  const withElement = {
    ...instanceGuide,
    instances: {
      '.s-*': {
        ...instanceGuide.instances['.s-*'],
        controls: {
          ...instanceGuide.instances['.s-*'].controls,
          bad: { label: 'Bad', widget: 'width', element: ['.inner'] },
          text_color: false,
        },
      },
    },
  };
  const models = controlModels(withElement, INSTANCE, textBlockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['typography']);
});

test('compoundOperations: one wire op per change, default condition omitted', () => {
  // A gesture spanning boxes (a background replacement on a linked
  // block: the gradient placed on the anchor, the root's authored band
  // cleared beside it), one change per key in one batch (one undo
  // label, atomic server-side). 'default' stays off the wire, matching
  // commit().
  const ops = compoundOperations([
    { key: `${BLOCK}::.text-block-link`, properties: { 'background-image': 'var(--swatch-hero_fade)' } },
    { key: BLOCK, properties: { 'background-color': null } },
  ]);
  assert.deepEqual(ops, [
    { op: 'update_style', key: `${BLOCK}::.text-block-link`, properties: { 'background-image': 'var(--swatch-hero_fade)' } },
    { op: 'update_style', key: BLOCK, properties: { 'background-color': null } },
  ]);
});

test('compoundOperations: a non-default condition rides its wire op', () => {
  const ops = compoundOperations([
    { key: BLOCK, condition: 'narrow', properties: { width: '100%' } },
  ]);
  assert.deepEqual(ops, [
    { op: 'update_style', key: BLOCK, condition: 'narrow', properties: { width: '100%' } },
  ]);
});

test('a container\'s granted subset is the same at every placement depth', () => {
  // Placement curates offers and birth, never legality: the served node
  // is depth-blind and so is its grant. A narrowed grant binds the band
  // and the container nested inside it alike, and the layout tree (the
  // axis vocabulary's depth read) never enters the grant.
  const ROOT = 'cccccccc-1111-2222-3333-444444444444';
  const NESTED = 'dddddddd-1111-2222-3333-444444444444';
  const SPACING = ['padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-inline', 'gap'];
  const container = {
    grants: { spacing: SPACING },
    axes: axesOf(['style']),
    controls: { spacing: { label: 'Spacing', widget: 'spacing_scale', element: [''] } },
  };
  const guideFor = (declaration) => ({
    kinds: { container: declaration },
    groups: {
      spacing: { properties: { padding: {}, 'padding-top': {}, 'padding-bottom': {}, 'padding-left': {}, 'padding-inline': {}, gap: {} } },
    },
    controlWidgets: {
      spacing_scale: { label: 'Spacing', properties: ['padding', 'padding-top', 'padding-bottom', 'padding-left', 'gap'] },
    },
  });
  const layout = [{ kind: 'container', uuid: ROOT, layout_id: 'layout_onecol', children: [{ kind: 'container', uuid: NESTED, layout_id: 'layout_onecol', children: [] }] }];
  const granted = (guide, uuid, tree) => controlModels(guide, `container:${uuid}`, {}, {}, {}, [], 'default', null, null, tree)
    .find((model) => model.id === 'spacing').granted;

  const narrowed = guideFor({ ...container, grants: { spacing: ['padding-top', 'padding-bottom', 'padding-inline', 'gap'] } });
  assert.deepEqual(granted(narrowed, ROOT, layout), ['padding-top', 'padding-bottom', 'gap'], 'the band offers the declared sides');
  assert.deepEqual(granted(narrowed, NESTED, layout), ['padding-top', 'padding-bottom', 'gap'], 'nested: the same grant');
  assert.deepEqual(granted(narrowed, NESTED, []), ['padding-top', 'padding-bottom', 'gap'], 'no tree: the same grant');

  // The whole group (the shipped container's grant): four sides and the
  // shorthand for the band and the nested card alike.
  const whole = guideFor(container);
  const everything = ['padding', 'padding-top', 'padding-bottom', 'padding-left', 'gap'];
  assert.deepEqual(granted(whole, ROOT, layout), everything, 'the band offers four sides');
  assert.deepEqual(granted(whole, NESTED, layout), everything);
});

// The address rule (resolveCandidates) over the text block's two boxes:
// the tag root (`p`/`h2`) and the anchor nested inside it when linked.
// A binding lists the link first, the root second, and every case
// below builds the two candidates' REAL stacks (buildStack over a map,
// a worn Button variant keyed at the anchor like the shipped one, and a
// skin) so the rule is exercised over the reads the rows light from.
const LINK = `${BLOCK}::.text-block-link`;
const textTypes = { [UUID_A]: 'text' };
const BACKGROUND = ['background-color', 'background-image'];
const TYPOGRAPHY = ['font-family', 'font-size', 'font-weight'];
const COLOR = ['color'];
// The shipped Button variant's shape: every style at the anchor, nothing
// at the root.
const buttonLibrary = [{
  id: 'button',
  label: 'Button',
  scope: 'block_type:text',
  value: { '::.text-block-link': { default: { 'background-color': '#1a1a2e', 'font-weight': '600' } } },
}];
const wearingButton = { variants: [{ variant: 'button', fragment: '' }] };
// A skin with a root Size (the kind block) and a link color (the
// `base:a` block, keyed by the anchor's declared tag).
const textSkin = {
  skinDefaults: {
    'block_type:text': { '': { default: { 'font-size': '1.25rem' } } },
    'base:a': { '': { default: { color: 'blue' } } },
  },
};
// The tree the candidates' tags come from: the text block renders as a
// p root with its link part declared as an anchor.
const textTree = [
  { kind: 'block', uuid: UUID_A, block_type: 'text', _element: { markup: { tag: 'p', parts: { '.text-block-link': { tag: 'a' } } } } },
];
const textCandidate = (guide, styleMap, key, element, present) => ({
  element,
  key,
  write: key,
  // The projection's box label (write-address.js boxLabel: the declared
  // part label, "element" for the root); the address rule's "Also set on".
  label: element === '' ? 'element' : 'link',
  present,
  stack: buildStack({ guide, map: styleMap, key, variants: buttonLibrary, blockTypes: textTypes, tree: textTree }),
});
// The text block's binding: the link first, the root second.
const linkThenRoot = (styleMap, { guide = {}, linked = true } = {}) => [
  textCandidate(guide, styleMap, LINK, '.text-block-link', linked),
  textCandidate(guide, styleMap, BLOCK, '', true),
];

test('resolveCandidates: a plain linked block with nothing authored addresses the link (rule A)', () => {
  // Nothing on either box, no skin: every property falls to the first
  // present candidate, so a background pick makes a pill on the anchor.
  const { keys, stacks, reads } = resolveCandidates(linkThenRoot({ [BLOCK]: {} }), BACKGROUND, 'default');
  assert.deepEqual(keys, { 'background-color': LINK, 'background-image': LINK });
  assert.equal(stacks['background-color'].key, LINK);
  assert.equal(reads['background-color'].winner, null, 'honestly unset');
  assert.deepEqual(litValues(reads), {});
  assert.equal(reads['background-color'].alsoOn, null);
  assert.equal(reads['background-image'].alsoOn, null);
});

test('resolveCandidates: a band authored before the link keeps reading the root', () => {
  // The orphan case: a root background set while unlinked still paints
  // once the block is linked, so the one row reads it (authored beats
  // list order) and writes it back where it lives. The image, authored
  // nowhere, still means the anchor.
  const styleMap = { [BLOCK]: { default: { 'background-color': 'gold' }, hover: { 'background-image': 'none' } } };
  const { keys, reads } = resolveCandidates(linkThenRoot(styleMap), BACKGROUND, 'default');
  assert.deepEqual(keys, { 'background-color': BLOCK, 'background-image': LINK });
  assert.equal(reads['background-color'].winner.tier, 'element');
  assert.equal(reads['background-color'].winner.value, 'gold');
  assert.equal(ownAtPose(reads['background-color'], 'default'), true, 'unsettable at the pose where it lives');
  assert.equal(reads['background-color'].alsoOn, null, 'the one authored value is the shown one');
  // The root's Hover image is not authored AT THIS POSE: it neither
  // pulls the image row to the root nor counts as a second box here
  // (other-pose styling is the row origin's story, not this rule's).
  assert.equal(reads['background-image'].winner, null);
  assert.equal(reads['background-image'].alsoOn, null);
});

test('resolveCandidates: Button worn, Size from the root skin and Weight from the anchor', () => {
  // One typography binding, rows on different boxes: the skin's root
  // Size is a tier value (level 2) that nothing authored beats, the
  // Button's Weight is authored at the anchor (level 1), and the Font,
  // delivered nowhere, means the anchor (level 3).
  const styleMap = { [BLOCK]: wearingButton };
  const { keys, stacks, reads } = resolveCandidates(linkThenRoot(styleMap, { guide: textSkin }), TYPOGRAPHY, 'default');
  assert.deepEqual(keys, { 'font-family': LINK, 'font-size': BLOCK, 'font-weight': LINK });
  assert.equal(reads['font-size'].winner.tier, 'skin');
  assert.equal(reads['font-size'].winner.value, '1.25rem');
  assert.equal(reads['font-weight'].winner.tier, 'variant');
  assert.equal(reads['font-weight'].winner.variant, 'button');
  assert.equal(reads['font-family'].winner, null);
  assert.deepEqual(litValues(reads), { 'font-size': '1.25rem', 'font-weight': '600' });
  // Each property's stack is its own box's, so a per-part reader over
  // stacks[property] sees the right layers.
  assert.equal(stacks['font-size'].key, BLOCK);
  assert.deepEqual(stacks['font-size'].layers.map((layer) => layer.id), ['skin:block_type:text', 'variant:button', 'element']);
  assert.equal(stacks['font-weight'].key, LINK);
  assert.deepEqual(Object.values(reads).map((read) => read.alsoOn), [null, null, null], 'a tier value on the other box hides nothing');
});

test('resolveCandidates: Button plus a root band resolves to the anchor and names the band as alsoOn', () => {
  // Two authored values for one property: the Button's link background
  // and the root's own band. List order decides within the authored
  // level (the anchor), and the band is the second authored value the
  // row's menu must name ("Also set on element"), by the other
  // candidate's label and its raw value; the image, authored nowhere,
  // names none.
  const styleMap = { [BLOCK]: { ...wearingButton, default: { 'background-color': 'gold' } } };
  const { keys, reads } = resolveCandidates(linkThenRoot(styleMap, { guide: textSkin }), BACKGROUND, 'default');
  assert.equal(keys['background-color'], LINK);
  assert.equal(reads['background-color'].winner.tier, 'variant');
  assert.equal(reads['background-color'].winner.value, '#1a1a2e');
  assert.deepEqual(reads['background-color'].alsoOn, { label: 'element', value: 'gold' });
  assert.equal(reads['background-image'].alsoOn, null);
});

test('resolveCandidates: the Weight write target is the anchor, an own override over the Button', () => {
  // Write where you read: the key a Weight gesture commits to is the
  // resolved candidate's, the anchor's, so the write lands above the
  // Button's layer on the box that paints it, never on the root where
  // the anchor's direct rule would hide it.
  const styleMap = { [BLOCK]: wearingButton };
  const { keys, stacks, reads } = resolveCandidates(linkThenRoot(styleMap, { guide: textSkin }), ['font-weight'], 'default');
  assert.equal(keys['font-weight'], LINK);
  assert.equal(stacks['font-weight'].key, LINK);
  assert.equal(stacks['font-weight'].layers[stacks['font-weight'].layers.length - 1].editable, true);
  assert.equal(ownAtPose(reads['font-weight'], 'default'), false, 'the anchor entry says nothing yet: an edit writes an own override, not an unset');
  assert.deepEqual(reads['font-weight'].beneath, [], 'nothing beneath the Button at the anchor');
});

test('resolveCandidates: a root Text color beats the anchor\'s skin link color', () => {
  // The printer co-targets an authored root color into the link above
  // the link's skin default, so the row reads the root (authored, level
  // 1) over the anchor's `base:a` blue (a tier, level 2) and writes the
  // root. With no authored color anywhere the anchor's skin blue lights
  // from the anchor.
  const { keys, reads } = resolveCandidates(linkThenRoot({ [BLOCK]: { default: { color: 'crimson' } } }, { guide: textSkin }), COLOR, 'default');
  assert.deepEqual(keys, { color: BLOCK });
  assert.equal(reads.color.winner.tier, 'element');
  assert.equal(reads.color.winner.value, 'crimson');
  assert.equal(reads.color.alsoOn, null, 'the skin blue beneath is not a hidden authored value');
  const bare = resolveCandidates(linkThenRoot({ [BLOCK]: {} }, { guide: textSkin }), COLOR, 'default');
  assert.deepEqual(bare.keys, { color: LINK });
  assert.equal(bare.reads.color.winner.tier, 'skin');
  assert.equal(bare.reads.color.winner.id, 'skin:base:a');
  assert.equal(bare.reads.color.winner.value, 'blue');
});

test('resolveCandidates: an absent first candidate falls to the root, its orphan styles out of play', () => {
  // The unlinked text block: the anchor is absent (layout.self.parts
  // says false), so even an orphan background it kept from its linked
  // days neither reads nor flags; every property means the root.
  const styleMap = { [BLOCK]: {}, [LINK]: { default: { 'background-color': '#111' } } };
  const { keys, stacks, reads } = resolveCandidates(linkThenRoot(styleMap, { linked: false }), BACKGROUND, 'default');
  assert.deepEqual(keys, { 'background-color': BLOCK, 'background-image': BLOCK });
  assert.equal(stacks['background-color'].key, BLOCK);
  assert.equal(reads['background-color'].winner, null);
  assert.equal(reads['background-color'].alsoOn, null, 'an absent part paints nothing, so nothing is hidden');
  // Unknown presence (null) counts as present: the anchor is addressed.
  const unknown = resolveCandidates(linkThenRoot(styleMap, { linked: null }), BACKGROUND, 'default');
  assert.equal(unknown.keys['background-color'], LINK);
  assert.equal(unknown.reads['background-color'].winner.value, '#111');
});

test('resolveCandidates: no present candidate resolves nothing', () => {
  // Every candidate absent (or none at all): nothing to address, empty
  // maps, no hint; the caller hides the binding.
  const styleMap = { [BLOCK]: { default: { 'background-color': 'gold' } } };
  const candidates = linkThenRoot(styleMap, { linked: false }).map((candidate) => ({ ...candidate, present: false }));
  assert.deepEqual(resolveCandidates(candidates, BACKGROUND, 'default'), { keys: {}, stacks: {}, reads: {} });
  assert.deepEqual(resolveCandidates([], BACKGROUND, 'default'), { keys: {}, stacks: {}, reads: {} });
  assert.equal(styledAt(resolveCandidates(candidates, BACKGROUND, 'default').reads), false);
});

// The group form of the address rule (resolveGroup): the ONE box a
// gesture's set values land on, over the same two candidates. Every
// multi-property basic gesture is a recipe, so the pipeline routes the
// set as a group and never property by property; these cases pin the
// group's answer beside the per-property answers it overrides.
const TRIO = ['color', 'background-image', 'background-clip'];
const STROKE = ['border-width', 'border-style', 'border-color'];
const MEDIA = ['background-image', 'background-size', 'background-repeat', 'background-position'];

test('resolveGroup: the gradient-text trio lands on the root that authored the color', () => {
  // The toy the rule exists for: a root color authored, a gradient pick
  // on the linked block. Per property the trio splits (color to the
  // root, the two background members to the anchor, where nothing is
  // authored), which paints nothing; as a group the root is the first
  // box where ANY member is authored, so the whole recipe lands there.
  const styleMap = { [BLOCK]: { default: { color: 'crimson' } } };
  const candidates = linkThenRoot(styleMap, { guide: textSkin });
  assert.deepEqual(resolveCandidates(candidates, TRIO, 'default').keys, { color: BLOCK, 'background-image': LINK, 'background-clip': LINK }, 'per property the recipe splits');
  assert.equal(resolveGroup(candidates, TRIO, 'default'), BLOCK, 'as a group it lands on the root');
  // The group order is the member order's inverse too: the authored
  // member may sit anywhere in the set.
  assert.equal(resolveGroup(candidates, ['background-clip', 'background-image', 'color'], 'default'), BLOCK);
});

test('resolveGroup: all members unauthored land on the anchor, the first present box', () => {
  // Nothing authored anywhere: level 3, list order, the link first (the
  // empty state's meaning: a pick on a linked block makes a pill). The
  // anchor's skin blue (a tier at the first candidate) agrees.
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: {} }), TRIO, 'default'), LINK);
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: {} }, { guide: textSkin }), TRIO, 'default'), LINK);
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: {} }), ['background-image'], 'default'), LINK, 'a set of one is the per-property answer');
});

test('resolveGroup: a stroke with a width authored on the root takes a fresh color pick to the root', () => {
  // The stroke row's completion rule (a width without a style paints
  // nothing) makes the trio one gesture; the width already on the root
  // pulls the style and the color there with it, never a color alone
  // on the anchor beside a width on the root.
  const styleMap = { [BLOCK]: { default: { 'border-width': '2px' } } };
  const candidates = linkThenRoot(styleMap);
  assert.deepEqual(resolveCandidates(candidates, STROKE, 'default').keys, { 'border-width': BLOCK, 'border-style': LINK, 'border-color': LINK });
  assert.equal(resolveGroup(candidates, STROKE, 'default'), BLOCK);
});

test('resolveGroup: a media pick with a size authored on the root lands the image and its companions together', () => {
  // The media seed (image plus size, repeat and position) is a recipe:
  // without the companions the browser tiles the image. A companion
  // already authored on the root is the group's authored member.
  const styleMap = { [BLOCK]: { default: { 'background-size': 'cover' } } };
  const candidates = linkThenRoot(styleMap);
  assert.equal(resolveCandidates(candidates, ['background-image'], 'default').keys['background-image'], LINK, 'the image alone would mean the anchor');
  assert.equal(resolveGroup(candidates, MEDIA, 'default'), BLOCK);
});

test('resolveGroup: a background replacement is the one legitimate multi-key batch', () => {
  // A root band authored, a gradient pick: the pick's set member (the
  // image) means the anchor (nothing authored for it: first present),
  // while its null member (the color it replaces) is a removal and
  // routes per property to the root, where the band lives. Two keys,
  // one atomic batch (compoundOperations), the root's band cleared and
  // the gradient placed on the anchor.
  const styleMap = { [BLOCK]: { default: { 'background-color': 'gold' } } };
  const candidates = linkThenRoot(styleMap);
  const image = resolveGroup(candidates, ['background-image'], 'default');
  const color = resolveCandidates(candidates, ['background-color'], 'default').keys['background-color'];
  assert.equal(image, LINK);
  assert.equal(color, BLOCK);
  assert.deepEqual(compoundOperations([
    { key: image, properties: { 'background-image': 'var(--swatch-hero_fade)' } },
    { key: color, properties: { 'background-color': null } },
  ]), [
    { op: 'update_style', key: LINK, properties: { 'background-image': 'var(--swatch-hero_fade)' } },
    { op: 'update_style', key: BLOCK, properties: { 'background-color': null } },
  ]);
  // A color pick over the band is a set of one and lands where the
  // band lives: the replacement writes back to the root, one key.
  assert.equal(resolveGroup(candidates, ['background-color'], 'default'), BLOCK);
});

test('resolveGroup: a tier-delivered member decides before list order, an authored member before a tier', () => {
  // Nothing authored: the skin's base:a border-style on the anchor is a
  // tier at the first candidate, so the stroke lands on the anchor
  // (level 2, and level 3 would agree). The same tier on the ROOT
  // (the kind block) outranks the anchor's list position: the stroke
  // lands on the root. An authored member on the anchor (level 1)
  // outranks the root's tier.
  const anchorTier = { skinDefaults: { 'base:a': { '': { default: { 'border-style': 'solid' } } } } };
  const rootTier = { skinDefaults: { 'block_type:text': { '': { default: { 'border-style': 'solid' } } } } };
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: {} }, { guide: anchorTier }), STROKE, 'default'), LINK);
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: {} }, { guide: rootTier }), STROKE, 'default'), BLOCK);
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: {}, [LINK]: { default: { 'border-color': 'red' } } }, { guide: rootTier }), STROKE, 'default'), LINK);
  // The worn Button (authored at the anchor) is a level-1 member for a
  // background group even beside a root band: list order within the
  // authored level, the same answer the per-property rule gives.
  assert.equal(resolveGroup(linkThenRoot({ [BLOCK]: { ...wearingButton, default: { 'background-color': 'gold' } } }), ['background-color', 'background-image'], 'default'), LINK);
});

test('resolveGroup: presence and reach filter the candidates; nothing addressable is null', () => {
  // The unlinked block: the absent anchor takes no part, so the group
  // lands on the root even with the anchor's orphan authored. A
  // candidate beyond the write address's reach (write null) takes no
  // part either, and its write key is what the group answers with.
  const styleMap = { [BLOCK]: {}, [LINK]: { default: { color: 'red' } } };
  assert.equal(resolveGroup(linkThenRoot(styleMap, { linked: false }), TRIO, 'default'), BLOCK);
  assert.equal(resolveGroup(linkThenRoot(styleMap, { linked: null }), TRIO, 'default'), LINK, 'unknown presence counts as present');
  const [link, root] = linkThenRoot(styleMap);
  assert.equal(resolveGroup([{ ...link, write: null }, root], TRIO, 'default'), BLOCK);
  assert.equal(resolveGroup([{ ...link, write: 'container:X::> pb-text .text-block-link' }, root], TRIO, 'default'), 'container:X::> pb-text .text-block-link');
  assert.equal(resolveGroup([{ ...link, write: null }, { ...root, write: null }], TRIO, 'default'), null);
  assert.equal(resolveGroup([{ ...link, present: false }, { ...root, present: false }], TRIO, 'default'), null);
  assert.equal(resolveGroup([], TRIO, 'default'), null);
  assert.equal(resolveGroup([link, root], [], 'default'), null, 'an empty set (a gesture of removals alone) has no group');
});

// ---- The projection over a two-box binding: the text block's declaration
// under the form (the link first, the root second), through controlModels
// with a layout context, a tag reader and a drilled part.
const SURFACE = ['background-color', 'background-image'];
const textGuide = {
  kinds: { block: { grants: { surface: SURFACE, typography: TYPOGRAPHY, sizing: ['width'] } } },
  blockTypes: {
    text: {
      grants: { surface: SURFACE, typography: TYPOGRAPHY, sizing: ['width'] },
      valueSpaces: { 'background-color': { allowed: ['gold', 'crimson'] } },
      elements: {
        '.text-block-link': {
          label: 'Link',
          grants: { surface: SURFACE, typography: ['font-weight', 'font-size'] },
          valueSpaces: { 'background-color': { scale: 'surface' }, 'background-image': { allowed: ['none'] } },
        },
      },
      controls: {
        background: { label: 'Background', widget: 'background', element: ['.text-block-link', ''] },
        typography: { label: 'Typography', widget: 'typography', element: ['.text-block-link', ''] },
        width: { label: 'Width', widget: 'width', element: [''] },
        // Declared root-first: the drill's reorder case.
        fill: { label: 'Fill', widget: 'background', element: ['', '.text-block-link'] },
      },
    },
  },
  controlWidgets: {
    background: { label: 'Background', section: 'surface', properties: SURFACE },
    typography: { label: 'Typography', section: 'text', properties: TYPOGRAPHY },
    width: { label: 'Width', section: 'layout', properties: ['width'] },
  },
  ...textSkin,
};
const linked = { self: { parts: { '.text-block-link': true } } };
const unlinked = { self: { parts: { '.text-block-link': false } } };
const projectText = (styleMap, options = {}) =>
  project(textGuide, BLOCK, styleMap, { blockTypes: textTypes, variants: buttonLibrary, context: linked, tree: textTree, ...options });

test('controlModels: a two-box binding resolves per property; the home is the first present box', () => {
  // The Button worn at the anchor plus a root band: Background reads the
  // anchor (list order within the authored level) and names the band on
  // the row's read as alsoOn, by the root's box label ("element",
  // write-address.js boxLabel) and its value; a second box is an address
  // fact, not other-pose styling, so styledElsewhere stays false.
  // Typography splits, Size from the root's skin tier and Weight from
  // the anchor's Button layer, nothing named (a tier value on the other
  // box hides nothing). The home box is the anchor for both.
  const styleMap = { [BLOCK]: { ...wearingButton, default: { 'background-color': 'gold' } } };
  const models = projectText(styleMap);
  assert.deepEqual(models.map((model) => model.id), ['background', 'typography', 'width', 'fill']);
  const [background, typography, width] = models;
  assert.deepEqual(background.candidates.map((candidate) => [candidate.element, candidate.key, candidate.label, candidate.present]), [['.text-block-link', LINK, 'link', true], ['', BLOCK, 'element', true]]);
  assert.deepEqual(background.home, { element: '.text-block-link', key: LINK, stack: background.candidates[0].stack });
  assert.deepEqual(background.keys, { 'background-color': LINK, 'background-image': LINK });
  assert.equal(background.stacks['background-color'].key, LINK);
  assert.equal(background.reads['background-color'].winner.value, '#1a1a2e');
  assert.equal(background.reads['background-color'].winner.variant, 'button');
  assert.deepEqual(background.reads['background-color'].alsoOn, { label: 'element', value: 'gold' }, 'the root band is a second authored value, named on the read');
  assert.equal(background.reads['background-image'].alsoOn, null);
  assert.equal(background.styledElsewhere, false, 'a second box is not other-pose styling');
  assert.deepEqual(typography.keys, { 'font-family': LINK, 'font-size': BLOCK, 'font-weight': LINK });
  assert.deepEqual(litValues(typography.reads), { 'font-size': '1.25rem', 'font-weight': '600' });
  assert.equal(typography.stacks['font-size'].key, BLOCK);
  assert.equal(typography.stacks['font-weight'].key, LINK);
  assert.deepEqual(Object.values(typography.reads).map((read) => read.alsoOn), [null, null, null]);
  assert.equal(typography.styledElsewhere, false);
  // The one-candidate root binding is the shape it always was.
  assert.deepEqual(width.candidates.map((candidate) => [candidate.element, candidate.present]), [['', true]]);
  assert.deepEqual(width.home, { element: '', key: BLOCK, stack: width.candidates[0].stack });
  assert.deepEqual(width.keys, { width: BLOCK });
  // The reads ARE the resolver's: one read per owned property, none twice.
  assert.deepEqual(Object.keys(background.reads), SURFACE);
  // The tree keyed the anchor's skin base:a layer (the part's declared
  // tag), the root's p.
  assert.deepEqual(background.candidates[0].stack.layers.map((layer) => layer.id), ['skin:base:a', 'variant:button', 'element']);
});

test('controlModels: the unlinked block addresses the root; the anchor\'s orphan is out of play', () => {
  // The layout context says the anchor is absent: every property means
  // the root, the home is the root, an orphan anchor background neither
  // reads nor flags, and the binding stays offered (the root is present).
  const registry = createControlWidgetRegistry();
  registry.register('background', () => 'x');
  const styleMap = { [BLOCK]: {}, [LINK]: { default: { 'background-color': '#111' }, hover: { 'background-image': 'none' } } };
  const [background] = projectText(styleMap, { context: unlinked });
  assert.deepEqual(background.candidates.map((candidate) => candidate.present), [false, true]);
  assert.deepEqual(background.home, { element: '', key: BLOCK, stack: background.candidates[1].stack });
  assert.deepEqual(background.keys, { 'background-color': BLOCK, 'background-image': BLOCK });
  assert.equal(background.reads['background-color'].winner, null);
  assert.equal(background.reads['background-color'].alsoOn, null, 'an absent box names nothing');
  assert.equal(background.styledElsewhere, false, 'an absent box hides nothing, at this pose or another');
  assert.equal(registry.offered('background', background, unlinked), true);
  // Unknown presence (no context): the anchor is addressed and its
  // orphan reads, and its other-pose styling keeps the control offered.
  const [unknown] = projectText(styleMap, { context: null });
  assert.equal(unknown.candidates[0].present, null);
  assert.equal(unknown.keys['background-color'], LINK);
  assert.equal(unknown.reads['background-color'].winner.value, '#111');
  assert.equal(unknown.reads['background-color'].alsoOn, null, 'one authored value: nothing on a second box');
  assert.equal(unknown.styledElsewhere, true);
});

test('controlModels: styledElsewhere reads other-pose styling on every present box', () => {
  // Nothing authored at the pose anywhere, so every property means the
  // anchor; the root's Hover image is styling the pose does not show and
  // flags the hint from the second candidate.
  const styleMap = { [BLOCK]: { hover: { 'background-image': 'none' } } };
  const [background] = projectText(styleMap);
  assert.deepEqual(background.keys, { 'background-color': LINK, 'background-image': LINK });
  assert.equal(background.styledElsewhere, true);
  const [unlinkedBackground] = projectText(styleMap, { context: unlinked });
  assert.equal(unlinkedBackground.styledElsewhere, true, 'the root is the present box: its own other-pose styling flags');
});

test('controlModels: constraints and grants follow the resolved box per property', () => {
  // Background color authored on the root reads the root: its constraints
  // are the root declaration's, while the image, meaning the anchor, takes
  // the link's; the grant is judged per property against the same box
  // (the link grants no Font, so a Font resolving there is withheld).
  const styleMap = { [BLOCK]: { default: { 'background-color': 'gold' } } };
  const [background, typography] = projectText(styleMap);
  assert.deepEqual(background.keys, { 'background-color': BLOCK, 'background-image': LINK });
  assert.deepEqual(background.constraints, {
    'background-color': { allowed: ['gold', 'crimson'] },
    'background-image': { allowed: ['none'] },
  });
  assert.deepEqual(background.granted, SURFACE);
  assert.deepEqual(typography.keys, { 'font-family': LINK, 'font-size': BLOCK, 'font-weight': LINK });
  assert.deepEqual(typography.granted, ['font-size', 'font-weight']);
});

test('controlModels: a drilled shell part filters the bindings and forces the part first', () => {
  // Drilling the anchor: bindings listing it render with it first
  // (declaration order otherwise), the root-only Width hides.
  const styleMap = { [BLOCK]: { default: { 'background-color': 'gold' } } };
  const models = projectText(styleMap, { targetSelector: '.text-block-link' });
  assert.deepEqual(models.map((model) => model.id), ['background', 'typography', 'fill']);
  const fill = models.find((model) => model.id === 'fill');
  assert.deepEqual(fill.candidates.map((candidate) => candidate.element), ['.text-block-link', '']);
  assert.equal(fill.home.element, '.text-block-link');
  // Forced first, but authored still outranks list order: the root's
  // own band keeps reading the root.
  assert.deepEqual(fill.keys, { 'background-color': BLOCK, 'background-image': LINK });
  // Undrilled, the declaration's order stands.
  const plain = projectText(styleMap).find((model) => model.id === 'fill');
  assert.deepEqual(plain.candidates.map((candidate) => candidate.element), ['', '.text-block-link']);
  assert.equal(plain.home.element, '');
  assert.deepEqual(plain.keys, { 'background-color': BLOCK, 'background-image': BLOCK });
  // A part no binding lists: nothing renders (the panel's "no style
  // options" state).
  assert.deepEqual(projectText(styleMap, { targetSelector: '.text-block-other' }), []);
  // An instance drill ignores the part: its key already is the part.
  assert.deepEqual(
    project(instanceGuide, INSTANCE, {}, { blockTypes: textBlockTypes, targetSelector: '.s-a3' }).map((model) => model.id),
    ['typography', 'text_color'],
  );
});

// ---- The group's landing ON THE MODEL (style-widget-drift T10): the box
// a gesture's SET values land on is the model's answer (`landing`, the
// group form of the address rule over the model's own candidates), so a
// widget that must read the landing box's own layer (the stroke patches'
// expansion layer) never reaches for a per-property key. The border
// stroke over the same two boxes, the link first and the root second.
const TOP_SIDE = ['border-top-width', 'border-top-style', 'border-top-color'];
const strokeGuide = {
  kinds: { block: { grants: { surface: [...STROKE, ...TOP_SIDE] } } },
  blockTypes: {
    text: {
      grants: { surface: [...STROKE, ...TOP_SIDE] },
      elements: { '.text-block-link': { label: 'Link', grants: { surface: [...STROKE, ...TOP_SIDE] } } },
      controls: { border: { label: 'Border', widget: 'corners_border', element: ['.text-block-link', ''] } },
    },
  },
  controlWidgets: { corners_border: { label: 'Border', section: 'surface', properties: [...STROKE, ...TOP_SIDE] } },
};
const projectStroke = (styleMap) =>
  project(strokeGuide, BLOCK, styleMap, { blockTypes: textTypes, variants: buttonLibrary, context: linked, tree: textTree });

test('landing: a colour authored on the first box moves a whole stroke off the width\'s read box, and the model answers it', () => {
  // Colour authored on the link (the first candidate), width on the root
  // (the second). Per property the width READS from the root
  // (keys['border-width']); a whole stroke (a typed width over an unset
  // style completes to width, style and colour) LANDS on the link, the
  // first box any of its parts is authored on. A widget expanding its
  // patch against the own layer at keys['border-width'] reads the root
  // while its write lands on the link: confirmed here, and the model's
  // `landing` is the one place the widget asks instead.
  const styleMap = { [LINK]: { default: { 'border-color': 'red' } }, [BLOCK]: { default: { 'border-width': '2px' } } };
  const [border] = projectStroke(styleMap);
  assert.equal(border.keys['border-width'], BLOCK);
  assert.equal(border.keys['border-color'], LINK);
  assert.equal(resolveGroup(border.candidates, STROKE, 'default'), LINK, 'confirmed: the write key is not keys[border-width]');
  assert.equal(border.landing(STROKE), LINK, 'the model answers the group\'s box');
  assert.equal(border.landing(['border-width']), BLOCK, 'a set of one lands where it reads');
  assert.equal(border.landing(['border-color']), LINK);
  // A ring's side write: no longhand is authored on either box and no
  // tier delivers one, so the set lands on the first present box, the
  // pipeline's own answer; the ring's expansion layer is that box's.
  assert.equal(border.landing(TOP_SIDE), LINK);
  // Removals alone have no group (each lands on keys[property]); the
  // home box stands in, and the pipeline never asks for one.
  assert.equal(border.landing([]), border.home.key);
  // The unlinked block: the absent anchor takes no part, so the stroke
  // lands on the root, its colour orphan out of play.
  const [unlinkedBorder] = project(strokeGuide, BLOCK, styleMap, { blockTypes: textTypes, variants: buttonLibrary, context: unlinked, tree: textTree });
  assert.equal(unlinkedBorder.landing(STROKE), BLOCK);
});

// ---- A container's rule key (a chain facet seated from a code-mode
// row header) models the landing type's controls with each part's
// presence read off the matcher, and a child's own rows read the
// container's rule as the inherited origin. ----------------------------
const CONTAINER_UUID = 'dddddddd-1111-2222-3333-444444444444';
const CHILD_UUID = 'cccccccc-1111-2222-3333-444444444444';
const LINKED_UUID = 'eeeeeeee-1111-2222-3333-444444444444';
const CONTAINER = `container:${CONTAINER_UUID}`;
const CHILD = `block:${CHILD_UUID}`;
const TARGET = `${CONTAINER}::> pb-text`;
const childrenGuide = {
  kinds: {
    block: { grants: { typography: ['color'] } },
    container: { grants: { layout: ['gap'] }, children: { grants: { layout: ['gap'] } } },
  },
  blockTypes: {
    text: {
      label: 'Text',
      grants: { typography: ['color'] },
      elements: { '.text-block-link': { label: 'Link', grants: { typography: ['color'] } } },
      controls: {
        text_color: { label: 'Text color', widget: 'text_color', element: ['.text-block-link', ''] },
      },
    },
  },
  controlWidgets: { text_color: { label: 'Text color', section: 'text', properties: ['color'] } },
  controlSections: { text: { label: 'Text', weight: 0 } },
  conditions: {},
};
const childTypes = { [CHILD_UUID]: 'text', [LINKED_UUID]: 'text' };
const childrenTree = (linked) => [
  {
    kind: 'container',
    uuid: CONTAINER_UUID,
    layout_id: 'daedalus_stylish_container',
    label: 'Hero',
    _element: { name: 'Hero', type: 'Section', markup: { tag: 'section', parts: {} } },
    children: [
      { kind: 'block', uuid: CHILD_UUID, block_type: 'text', _element: { name: 'Text', type: 'Text', markup: { tag: 'p', parts: {} } } },
      {
        kind: 'block',
        uuid: LINKED_UUID,
        block_type: 'text',
        _element: { name: 'Text', type: 'Text', markup: { tag: 'p', parts: linked ? { '.text-block-link': { tag: 'a' } } : {} } },
      },
    ],
  },
];
// The selection context handed in names the CONTAINER's own facts (the
// mirror's answer for the selected element); a rule key must never read
// it, so every projection here passes one that would mislead.
const containerContext = { self: { parts: { '.text-block-link': true } } };
const projectRule = (styleMap, tree) =>
  controlModels(childrenGuide, TARGET, childTypes, styleMap, {}, [], 'default', containerContext, null, tree);

test('a rule key models the landing type\'s controls over the container\'s rule, a part present where a matched child carries it', () => {
  const [model, ...rest] = projectRule({}, childrenTree(true));
  assert.deepEqual(rest, []);
  assert.equal(model.id, 'text_color');
  assert.equal(model.section, 'text');
  // The candidates ride the rule's selector: the part continues the
  // chain by descent (never a second `::`), the root is the rule key.
  // The second text block renders the anchor, so the matcher's answer
  // over the matched set is present.
  assert.deepEqual(model.candidates.map((candidate) => [candidate.element, candidate.key, candidate.present]), [
    ['.text-block-link', `${TARGET} .text-block-link`, true],
    ['', TARGET, true],
  ]);
  assert.deepEqual(model.granted, ['color']);
  // Nothing authored anywhere: the declaration's order stands, the link
  // first.
  assert.equal(model.keys.color, `${TARGET} .text-block-link`);
  // No tree (a satellite before its broadcast): the part's presence is
  // unknown, never the selection context's answer.
  const [treeless] = projectRule({}, null);
  assert.deepEqual(treeless.candidates.map((candidate) => candidate.present), [null, true]);
  assert.equal(treeless.keys.color, `${TARGET} .text-block-link`);
  // A tree that does not carry the owner answers unknown too.
  const [unknownOwner] = projectRule({}, []);
  assert.deepEqual(unknownOwner.candidates.map((candidate) => candidate.present), [null, true]);
});

test('a rule key\'s absent part drops out and the rows address the rule itself', () => {
  // No matched child renders the anchor: the matcher's answer over the
  // known set drops the link candidate, so a pick writes at
  // container:X::> pb-text and paints on every text block.
  const [model] = projectRule({}, childrenTree(false));
  assert.deepEqual(model.candidates.map((candidate) => candidate.present), [false, true]);
  assert.equal(model.home.element, '');
  assert.equal(model.keys.color, TARGET);
  // The rule's own entry is the row's value, unsettable at the pose.
  const [set] = projectRule({ [TARGET]: { default: { color: 'red' } } }, childrenTree(false));
  assert.equal(litValues(set.reads).color, 'red');
  assert.equal(ownAtPose(set.reads.color, 'default'), true);
  assert.equal(set.home.stack.rootKey, CONTAINER);
  assert.equal(set.home.stack.relative, '::> pb-text');
  // The matched set is the RULE's, not the container's children as a
  // whole: a rule matching nothing that renders the anchor reads absent
  // even when a sibling outside the match does.
  const [outside] = controlModels(childrenGuide, TARGET, childTypes, {}, {}, [], 'default', null, null, [
    {
      ...childrenTree(false)[0],
      children: [
        { kind: 'block', uuid: CHILD_UUID, block_type: 'text', _element: { markup: { tag: 'p', parts: {} } } },
        { kind: 'block', uuid: LINKED_UUID, block_type: 'image', _element: { markup: { tag: 'figure', parts: { '.text-block-link': { tag: 'a' } } } } },
      ],
    },
  ]);
  assert.deepEqual(outside.candidates.map((candidate) => candidate.present), [false, true]);
});

test('a bare children rule and a tag land on declarations without controls', () => {
  assert.deepEqual(controlModels(childrenGuide, `${CONTAINER}::> *`, childTypes, {}, {}, [], 'default', null, null, childrenTree(false)), []);
  assert.deepEqual(controlModels(childrenGuide, `${CONTAINER}::> p`, childTypes, {}), []);
  // A shell part still models nothing of its own (the owner's lift).
  assert.deepEqual(controlModels(childrenGuide, `${CHILD}::.text-block-link`, childTypes, {}), []);
});

test('a child\'s own rows read the container\'s rule as the inherited origin', () => {
  const tree = childrenTree(false);
  const context = { self: { parts: { '.text-block-link': false } } };
  const rule = { [TARGET]: { default: { color: 'red' } } };
  const project = (styleMap) =>
    controlModels(childrenGuide, CHILD, childTypes, styleMap, {}, [], 'default', context, null, tree)[0];
  const inherited = project(rule);
  assert.equal(inherited.keys.color, CHILD, 'the row still writes the child itself');
  assert.equal(inherited.reads.color.winner.tier, 'ancestor');
  assert.equal(litValues(inherited.reads).color, 'red');
  assert.equal(ownAtPose(inherited.reads.color, 'default'), false);
  const origin = originFor({
    guide: childrenGuide, materials: EMPTY_MATERIALS, condition: 'default', property: 'color', stack: inherited.home.stack, read: inherited.reads.color,
  });
  assert.equal(origin.delivers, true);
  assert.equal(origin.title, 'red inherited from Hero (> pb-text)');
  assert.deepEqual(origin.doorways.map((doorway) => doorway.id), ['ancestor']);
  // The child's own color wins by proximity: overriding, with the
  // container's value named beneath.
  const own = project({ ...rule, [CHILD]: { default: { color: 'blue' } } });
  assert.equal(own.reads.color.winner.tier, 'element');
  const overriding = originFor({
    guide: childrenGuide, materials: EMPTY_MATERIALS, condition: 'default', property: 'color', stack: own.home.stack, read: own.reads.color,
  });
  assert.equal(overriding.atPose, true);
  assert.equal(overriding.title, "Your blue overrides Hero's red (> pb-text)");
  // Without the tree the ancestor tier is out of reach: the row reads
  // unset (a satellite before its broadcast).
  const treeless = controlModels(childrenGuide, CHILD, childTypes, rule, {}, [], 'default', context)[0];
  assert.equal(treeless.reads.color.winner, null);
});

test('a variant the container\'s rule wears reaches a child as an ancestor layer, never as a wearing: not set here, no honesty hint', () => {
  const tree = childrenTree(false);
  const context = { self: { parts: { '.text-block-link': false } } };
  // A text look with a Narrow block: at its wearer the block flags
  // styledElsewhere (styling the pose does not show).
  const accent = [{
    id: 'accent',
    label: 'Accent',
    scope: 'block_type:text',
    value: { '': { default: { color: 'red' }, narrow: { color: 'blue' } } },
  }];
  const styleMap = { [TARGET]: { variants: [{ variant: 'accent', fragment: '' }] } };
  const [child] = controlModels(childrenGuide, CHILD, childTypes, styleMap, {}, accent, 'default', context, null, tree);
  // The child's row lights the rule's worn value, the winner naming the
  // variant through the ancestor tier (the mark can wear its hue)...
  assert.equal(litValues(child.reads).color, 'red');
  assert.equal(child.reads.color.winner.tier, 'ancestor');
  assert.equal(child.reads.color.winner.variant, 'accent');
  assert.deepEqual(child.home.stack.layers.filter((layer) => layer.tier === 'variant'), []);
  // ...but nothing is SET on the child (mapWinner: the element's own
  // entry or its own wearings only), nothing is unsettable, and the
  // rule's Narrow block is not this element's styling elsewhere.
  assert.equal(styledAt(child.reads), false);
  assert.equal(ownAtPose(child.reads.color, 'default'), false);
  assert.equal(child.styledElsewhere, false);
  // At the rule itself the same reference IS a wearing: set, and its
  // Narrow block flags the hint.
  const [rule] = controlModels(childrenGuide, TARGET, childTypes, styleMap, {}, accent, 'default', containerContext, null, tree);
  assert.equal(rule.reads.color.winner.tier, 'variant');
  assert.equal(styledAt(rule.reads), true);
  assert.equal(rule.styledElsewhere, true);
});

// --- the write address ------------------------------------------------------

const ADDRESS_GUIDE = {
  ...childrenGuide,
  enforcement: 'strict',
  kinds: {
    block: { grants: { typography: ['color', 'font-size', 'font-weight'] } },
    container: { grants: {}, children: { grants: { layout: ['gap'] } } },
  },
  blockTypes: {
    text: {
      label: 'Text',
      grants: { typography: ['color', 'font-size', 'font-weight'] },
      valueSpaces: { 'font-size': { scale: 'type' } },
      elements: { '.text-block-link': { label: 'Link', grants: { typography: ['color', 'font-weight'] }, valueSpaces: { 'font-weight': { allowed: ['400', '700'] } } } },
      controls: {
        text_color: { label: 'Text color', widget: 'text_color', element: ['.text-block-link', ''] },
        typography: { label: 'Typography', widget: 'typography', element: ['', '.text-block-link'] },
        link_weight: { label: 'Link weight', widget: 'link_weight', element: ['.text-block-link'] },
      },
    },
  },
  base: { a: { grants: { typography: ['font-weight'] }, valueSpaces: { 'font-weight': { allowed: ['700'] } } } },
  controlWidgets: {
    text_color: { label: 'Text color', section: 'text', properties: ['color'] },
    typography: { label: 'Typography', section: 'text', properties: ['font-size', 'font-weight'] },
    link_weight: { label: 'Link weight', section: 'text', properties: ['font-weight'] },
  },
};
const LINKED_CHILD = `block:${LINKED_UUID}`;
const LINKED_LINK = `${LINKED_CHILD}::.text-block-link`;
const TEXT_RULE = `${CONTAINER}::> pb-text`;
const linkedContext = { self: { parts: { '.text-block-link': true } } };
const projectAt = (writeAddress, { styleMap = {}, targetSelector = null, tree = childrenTree(true), key = LINKED_CHILD, context = linkedContext } = {}) =>
  controlModels(ADDRESS_GUIDE, key, childTypes, styleMap, {}, [], 'default', context, targetSelector, tree, writeAddress);
const byId = (models) => Object.fromEntries(models.map((model) => [model.id, model]));

// Two projections are the same model when their data agree and their
// `landing` closures answer alike (over the owned set and each property
// alone; a function never compares by value).
const sameModels = (actual, expected) => {
  const data = ({ landing, ...rest }) => rest;
  assert.deepEqual(actual.map(data), expected.map(data));
  actual.forEach((model, index) => {
    const owned = Object.keys(model.keys);
    const sets = [owned, ...owned.map((property) => [property])];
    assert.deepEqual(sets.map((set) => model.landing(set)), sets.map((set) => expected[index].landing(set)));
  });
};

test('writeAddress: null, a malformed address and the facet\'s own key all give today\'s model, withheld empty', () => {
  const plain = projectAt(null);
  assert.deepEqual(plain.map((model) => model.withheld), [{}, {}, {}]);
  sameModels(projectAt({ key: LINKED_CHILD, selector: null }), plain);
  sameModels(projectAt({ key: LINKED_CHILD, selector: '>pb-text' }), plain);
  sameModels(projectAt('nonsense'), plain);
  const drilled = projectAt(null, { targetSelector: '.text-block-link' });
  sameModels(projectAt({ key: LINKED_CHILD, selector: '.text-block-link' }, { targetSelector: '.text-block-link' }), drilled);
  // A rule key takes an address like any facet (the pins below); one
  // naming its own key is the own key.
  const rule = controlModels(ADDRESS_GUIDE, TEXT_RULE, childTypes, {}, {}, [], 'default', null, null, childrenTree(true));
  sameModels(controlModels(ADDRESS_GUIDE, TEXT_RULE, childTypes, {}, {}, [], 'default', null, null, childrenTree(true), { key: CONTAINER, selector: '> pb-text' }), rule);
});

test('writeAddress: a tag-tier subject rebases its root candidate to the address, as the root facet does; without one it writes at its own rule key', () => {
  // A base tag declaring basic controls (the authored surface a tier
  // needs to have rows at all): the link tier `block::a` under the
  // section's "every link in Section" address.
  const tierGuide = {
    ...ADDRESS_GUIDE,
    base: {
      a: {
        ...ADDRESS_GUIDE.base.a,
        controls: { link_weight: { label: 'Link weight', widget: 'link_weight', element: [''] } },
      },
    },
  };
  const TIER = `${LINKED_CHILD}::a`;
  const tierAt = (writeAddress) =>
    byId(controlModels(tierGuide, TIER, childTypes, {}, {}, [], 'default', null, null, childrenTree(true), writeAddress));
  const own = tierAt(null);
  assert.equal(own.link_weight.keys['font-weight'], TIER, 'without an address the tier writes at its own rule key');
  assert.deepEqual(own.link_weight.candidates.map((candidate) => candidate.write), [TIER]);
  const rebased = tierAt({ key: CONTAINER, selector: 'a' });
  assert.equal(rebased.link_weight.keys['font-weight'], `${CONTAINER}::a`, 'the root candidate (the rule itself) rebases to the address');
  assert.equal(rebased.link_weight.landing(['font-weight']), `${CONTAINER}::a`);
  assert.deepEqual(rebased.link_weight.candidates.map((candidate) => [candidate.key, candidate.write]), [[TIER, `${CONTAINER}::a`]], 'the reads stay the tier\'s, the write moves');
  assert.equal(rebased.link_weight.candidates[0].stack.layers.find((layer) => layer.editable)?.id, `ancestor:${CONTAINER}::a`, 'the address\'s layer is the editable one in the tier\'s stack');
  assert.deepEqual(rebased.link_weight.withheld, {}, 'base `a` grants weight, so nothing is withheld at the address');
  // An address naming the tier's own key is the own key: today's model.
  sameModels(Object.values(tierAt({ key: LINKED_CHILD, selector: 'a' })), Object.values(own));
});

test('writeAddress: a chain subject takes an address the same way (no mode decides whether one may stand)', () => {
  // The container's `> pb-text` rule under its own `> *`: the text
  // type's root-bound rows rebase to the children rule, the part-bound
  // ones continue it by descent where the matcher confirms the reach.
  const rule = byId(controlModels(ADDRESS_GUIDE, TEXT_RULE, childTypes, {}, {}, [], 'default', null, null, childrenTree(true)));
  assert.equal(rule.typography.keys['font-size'], TEXT_RULE);
  const under = byId(controlModels(ADDRESS_GUIDE, TEXT_RULE, childTypes, {}, {}, [], 'default', null, null, childrenTree(true), { key: CONTAINER, selector: '> *' }));
  assert.equal(under.typography.keys['font-size'], `${CONTAINER}::> *`, 'the root candidate rebases to the address');
  assert.equal(under.typography.candidates.find((candidate) => candidate.element === '').write, `${CONTAINER}::> *`);
  // A rule's root is its owner, not one matched element, so the matcher
  // cannot confirm a part chain under the address for it: the part-bound
  // row is honestly beyond reach (no write key, withheld with the reach
  // reason), never guessed.
  assert.equal(under.link_weight.candidates[0].write, null);
  assert.equal(typeof under.link_weight.withheld['font-weight'], 'string');
  // The children rule grants gap alone: the typography rows are
  // withheld there with the grant reason, the reads unchanged.
  assert.equal(typeof under.typography.withheld['font-size'], 'string');
  assert.deepEqual(under.typography.reads['font-size'].winner, rule.typography.reads['font-size'].winner);
});

test('writeAddress: a root facet rebases every candidate to the address, the facet\'s own to the address itself, a part by descent', () => {
  const address = { key: CONTAINER, selector: '> pb-text' };
  const { text_color: color, typography, link_weight: link } = byId(projectAt(address));
  // The candidates still read the SELECTED element's boxes (their keys
  // and stacks are the element's); the write keys are the rebased ones.
  assert.deepEqual(color.candidates.map((candidate) => candidate.key), [LINKED_LINK, LINKED_CHILD]);
  assert.deepEqual(color.candidates.map((candidate) => candidate.write), [`${TEXT_RULE} .text-block-link`, TEXT_RULE], 'each candidate carries its rebased write key');
  assert.equal(color.keys.color, `${TEXT_RULE} .text-block-link`, 'nothing authored: the link first, continued by descent');
  assert.equal(resolveGroup(color.candidates, ['color'], 'default'), `${TEXT_RULE} .text-block-link`, 'the group answers with the rebased key');
  assert.equal(color.landing(['color']), `${TEXT_RULE} .text-block-link`, 'the model\'s landing is the rebased key too');
  assert.deepEqual(typography.keys, { 'font-size': TEXT_RULE, 'font-weight': TEXT_RULE });
  assert.equal(link.keys['font-weight'], `${TEXT_RULE} .text-block-link`);
  assert.deepEqual(color.home, { element: '.text-block-link', key: LINKED_LINK, stack: color.candidates[0].stack });
  // Grants stay the own key's; the address grants everything here.
  assert.deepEqual(color.granted, ['color']);
  assert.deepEqual(typography.granted, ['font-size', 'font-weight']);
  assert.deepEqual([color.withheld, typography.withheld, link.withheld], [{}, {}, {}]);
  // Constraints are the rebased landing's (the text type's for the
  // root, the type's part for the link), not the element's own.
  assert.deepEqual(typography.constraints, { 'font-size': { scale: 'type' } });
  assert.deepEqual(link.constraints, { 'font-weight': { allowed: ['400', '700'] } });
  // The editable layer of every candidate stack is the address's
  // (virtual, nothing written yet): own reads null, the winner is unchanged.
  const editable = (stack) => stack.layers[editableLayer(stack)];
  assert.equal(editable(color.stacks.color).id, `ancestor:${TEXT_RULE} .text-block-link`);
  assert.equal(editable(typography.stacks['font-size']).id, `ancestor:${TEXT_RULE}`);
  assert.equal(color.stacks.color.layers[color.stacks.color.layers.length - 1].editable, false);
  assert.equal(ownAtPose(color.reads.color, 'default'), false);
  assert.deepEqual(litValues(color.reads), {});
  // Written at the address, the rule delivers and is the row's own.
  const written = byId(projectAt(address, { styleMap: { [`${TEXT_RULE} .text-block-link`]: { default: { color: 'red' } } } })).text_color;
  assert.equal(written.reads.color.winner.tier, 'ancestor');
  assert.equal(litValues(written.reads).color, 'red');
  assert.equal(ownAtPose(written.reads.color, 'default'), true);
  assert.equal(written.reads.color.own.value, 'red');
  // The element's own value still wins the read but is no longer own.
  const overriding = byId(projectAt(address, { styleMap: { [`${TEXT_RULE} .text-block-link`]: { default: { color: 'red' } }, [LINKED_LINK]: { default: { color: 'blue' } } } })).text_color;
  assert.equal(overriding.reads.color.winner.tier, 'element');
  assert.equal(overriding.reads.color.own.value, 'red');
});

test('writeAddress: a granted property the address\'s landing withholds is reported, the rest of the model unchanged', () => {
  // The link facet under the section's `a` rule: the drilled link
  // rebases to the address itself, the root candidate is beyond a tag
  // address (no grammar continues `a` to the root) and drops out of the
  // routing; base `a` grants font-weight alone.
  const address = { key: CONTAINER, selector: 'a' };
  const models = byId(projectAt(address, { targetSelector: '.text-block-link' }));
  assert.deepEqual(Object.keys(models), ['text_color', 'typography', 'link_weight']);
  assert.equal(models.text_color.keys.color, `${CONTAINER}::a`);
  assert.deepEqual(models.text_color.granted, ['color']);
  assert.deepEqual(models.text_color.withheld, {
    color: 'color is not allowed at "a" on Hero: the style guide does not grant it there. Choose this element alone to set it here.',
  });
  // Typography drilled to the link: font-size is not the LINK\'s grant
  // (hidden as ever, never withheld); font-weight is, and `a` grants it.
  assert.deepEqual(models.typography.granted, ['font-weight']);
  assert.deepEqual(models.typography.withheld, {});
  assert.deepEqual(models.typography.keys, { 'font-size': `${CONTAINER}::a`, 'font-weight': `${CONTAINER}::a` });
  assert.deepEqual(models.typography.constraints, { 'font-weight': { allowed: ['700'] } }, 'the address\'s constraints');
  assert.deepEqual(models.link_weight.withheld, {});
  // The children rule grants gap alone: a root facet under it withholds
  // color (the part candidate cannot continue `> *`, the root lands on
  // the children section).
  const children = byId(projectAt({ key: CONTAINER, selector: '> *' })).text_color;
  assert.equal(children.keys.color, `${CONTAINER}::> *`);
  assert.equal(children.withheld.color, 'color is not allowed at "> *" on Hero: the style guide does not grant it there. Choose this element alone to set it here.');
});

test('writeAddress: a binding none of whose boxes the address reaches withholds every granted property with the reach reason', () => {
  // No tree: the matcher cannot confirm the part chain, so the part-only
  // binding is out of reach; the two-box bindings route to the root.
  const address = { key: CONTAINER, selector: '> pb-text' };
  const models = byId(projectAt(address, { tree: null }));
  assert.equal(models.text_color.keys.color, TEXT_RULE);
  assert.deepEqual(models.text_color.withheld, {});
  assert.equal(models.link_weight.keys['font-weight'], LINKED_LINK, 'the reads still address the element');
  assert.deepEqual(models.link_weight.candidates.map((candidate) => candidate.write), [null], 'beyond the address: no write key');
  assert.equal(resolveGroup(models.link_weight.candidates, ['font-weight'], 'default'), null, 'and no group answer');
  assert.deepEqual(models.link_weight.withheld, {
    'font-weight': '"> pb-text" on the parent element does not reach the link this row styles; choose an address that does, or this element alone.',
  });
  assert.deepEqual(models.link_weight.granted, ['font-weight']);
  // With the tree the chain is confirmed and the row writes there.
  assert.equal(byId(projectAt(address)).link_weight.keys['font-weight'], `${TEXT_RULE} .text-block-link`);
});

test('withheldReasonFor: the first withheld property\'s reason among a row\'s properties, null when none', () => {
  const model = { withheld: { 'border-style': 'no style', 'border-color': 'no color' } };
  assert.equal(withheldReasonFor(model, ['border-width', 'border-style', 'border-color']), 'no style');
  assert.equal(withheldReasonFor(model, ['border-width']), null);
  assert.equal(withheldReasonFor({}, ['border-width']), null);
  assert.equal(withheldReasonFor(null, null), null);
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
  console.error(`${failures} of ${tests.length} controls tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} controls tests passed.`);
