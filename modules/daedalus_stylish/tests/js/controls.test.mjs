/**
 * Self-asserting tests for the basic-mode control projection.
 *
 * Run directly (`node tests/js/controls.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit suite
 * so the control-model contract has backpressure. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { compoundOperations, createControlWidgetRegistry, controlModels, controlSections, groupControls } from '../../js/src/controls.js';
import { originFor } from '../../js/src/choice-row-model.js';
import { EMPTY_MATERIALS } from './materials-fixture.mjs';

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
          element: '.mock-container',
        },
        width: {
          // No binding label: falls back to the widget definition's label.
          widget: 'width',
          element: '.mock-image',
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
  const empty = { values: {}, hasMore: false };
  const text = { self: { hasText: true } };
  const noText = { self: { hasText: false } };
  // No gate registered: always offered (flex_align/width today).
  assert.equal(registry.offered('plain', empty, noText), true);
  // The gate decides only when nothing is set.
  assert.equal(registry.offered('gated', empty, text), true);
  assert.equal(registry.offered('gated', empty, noText), false);
  // A set owned value must always be visible and unsettable.
  assert.equal(registry.offered('gated', { values: { 'text-align': 'center' }, hasMore: false }, noText), true);
  // hasMore is set styling the current pose does not show (other
  // compounds, an unresolvable variant); hiding the control would
  // orphan its honesty hint.
  assert.equal(registry.offered('gated', { values: {}, hasMore: true }, noText), true);
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
  const empty = { values: {}, hasMore: false };
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
  const empty = { values: {}, hasMore: false };
  assert.equal(registry.offered('parent_gated', empty, { self: {}, parent: { display: 'block' } }), true);
  assert.equal(registry.offered('parent_gated', empty, { self: {}, parent: null }), false);
  assert.equal(registry.offered('parent_gated', empty, null), false);
  assert.equal(registry.offered('parent_gated', { values: { 'align-self': 'center' }, hasMore: false }, null), true);
});

test('offered: the gate receives the control model, so an element-bound gate reads its bound part', () => {
  // Gates are (self, parent, model): the gap widget's gate must know
  // WHICH element the control binds to read that part's child count
  // (self.partChildCount) rather than the selection's own; the value-set
  // override still rides above it.
  const registry = createControlWidgetRegistry();
  const seen = [];
  registry.register('bound_gated', () => 'x', {
    applicable: (self, parent, model) => {
      seen.push(model.element);
      return (self?.partChildCount?.[model.element] ?? 2) > 1;
    },
  });
  const bound = { element: '.daedalus-layout-layout', values: {}, hasMore: false };
  const oneBand = { self: { childCount: 1, parts: { '.daedalus-layout-layout': true }, partChildCount: { '.daedalus-layout-layout': 1 } }, parent: null };
  const twoBands = { self: { ...oneBand.self, partChildCount: { '.daedalus-layout-layout': 2 } }, parent: null };
  assert.equal(registry.offered('bound_gated', bound, oneBand), false);
  assert.equal(registry.offered('bound_gated', bound, twoBands), true);
  assert.deepEqual(seen, ['.daedalus-layout-layout', '.daedalus-layout-layout']);
  assert.equal(registry.offered('bound_gated', { ...bound, values: { gap: '1rem' } }, oneBand), true);
  assert.equal(seen.length, 2);
});

test('offered: an absent bound part hides absolutely — no value-set override', () => {
  // The part-presence gate: presence is an existence question, so an
  // element-bound control whose part is absent from the canvas
  // (self.parts[selector] === false) hides even with values set or
  // hasMore flagged. The value-set override belongs to widget
  // APPLICABILITY gates only (the tests above).
  const registry = createControlWidgetRegistry();
  registry.register('width', () => 'width');
  const bound = { element: '.mock-image', values: {}, hasMore: false };
  const absent = { self: { parts: { '.mock-image': false } } };
  const present = { self: { parts: { '.mock-image': true } } };
  assert.equal(registry.offered('width', bound, present), true);
  assert.equal(registry.offered('width', bound, absent), false);
  // Set values and hasMore do NOT resurrect a missing target.
  assert.equal(registry.offered('width', { ...bound, values: { width: '50%' } }, absent), false);
  assert.equal(registry.offered('width', { ...bound, hasMore: true }, absent), false);
  // A root binding (element null/absent) never consults presence.
  assert.equal(registry.offered('width', { element: null, values: {}, hasMore: false }, absent), true);
});

test('offered: unknown presence degrades to offering, only positive absence hides', () => {
  // Matches the gates' unknown-context posture: a null layout, a context
  // without parts facts, or a parts map that never mentions the selector
  // (a satellite pre-broadcast, a guide/facts skew) offers rather than
  // guesses.
  const registry = createControlWidgetRegistry();
  registry.register('width', () => 'width');
  const bound = { element: '.mock-image', values: {}, hasMore: false };
  assert.equal(registry.offered('width', bound, null), true);
  assert.equal(registry.offered('width', bound, {}), true);
  assert.equal(registry.offered('width', bound, { self: {} }), true);
  assert.equal(registry.offered('width', bound, { self: { parts: {} } }), true);
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
  const bound = { element: '.mock-image', values: {}, hasMore: false };
  const absent = { self: { parts: { '.mock-image': false } } };
  const present = { self: { hasText: false, parts: { '.mock-image': true } } };
  assert.equal(registry.offered('gated_width', bound, absent), false);
  // The gate never ran for the absent part.
  assert.equal(gateCalls.length, 0);
  // Present part, nothing set: the gate decides.
  assert.equal(registry.offered('gated_width', bound, present), false);
  assert.equal(gateCalls.length, 1);
  // Present part, value set: the widget-gate value-set override holds.
  assert.equal(registry.offered('gated_width', { ...bound, values: { width: '50%' } }, present), true);
  assert.equal(gateCalls.length, 1);
});

test('offered: the element-bound Image width model from controlModels gates on presence', () => {
  // End-to-end over the real projection: the guide fixture's width
  // control is bound to '.mock-image', and its model carries that element
  // into the presence gate.
  const registry = createControlWidgetRegistry();
  registry.register('width', () => 'width');
  const styleMap = { [`${BLOCK}::.mock-image`]: { default: { width: '50%' } } };
  const width = controlModels(guide, BLOCK, blockTypes, styleMap).find((model) => model.id === 'width');
  assert.equal(width.element, '.mock-image');
  assert.deepEqual(width.values, { width: '50%' });
  assert.equal(registry.offered('width', width, { self: { parts: { '.mock-image': true } } }), true);
  // The svg-media case: part absent, control hidden despite the set value.
  assert.equal(registry.offered('width', width, { self: { parts: { '.mock-image': false } } }), false);
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
  assert.equal(alignment.element, '.mock-container');
  assert.equal(alignment.key, `${BLOCK}::.mock-container`);
  assert.deepEqual(alignment.definition, guide.controlWidgets.flex_align);
  assert.deepEqual(alignment.values, {});
  // No group table / no constraints in this guide: the channel is present
  // but empty, so widgets can always read it.
  assert.deepEqual(alignment.constraints, {});
  assert.equal(alignment.hasMore, false);
  // The unposed editing compound echoes on the model.
  assert.equal(alignment.condition, 'default');
  // Label fallback: binding label missing → widget definition label.
  assert.equal(width.label, 'Width');
  assert.equal(width.key, `${BLOCK}::.mock-image`);
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
          ghost: { label: 'Ghost', widget: 'no_such_widget' },
          blank: { label: 'Blank', widget: 'width', section: '' },
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

test('values carry only owned default-condition properties, stringified', () => {
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
  assert.deepEqual(alignment.values, { 'justify-content': 'center' });
  // The resolved entry rides whole and order-preserving beside the owned
  // projection — the per-side consumers' (box-model) read.
  assert.deepEqual(alignment.entry, {
    default: { 'justify-content': 'center', padding: '2rem' },
  });
});

test('entry is null when the bound element has no styles', () => {
  const [alignment] = controlModels(guide, BLOCK, blockTypes, {});
  assert.equal(alignment.entry, null);
});

test('hasMore flags an owned property under a non-default condition', () => {
  const styleMap = {
    [`${BLOCK}::.mock-container`]: {
      default: { 'justify-content': 'center' },
      hover: { 'align-items': 'flex-end' },
    },
  };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.equal(alignment.hasMore, true);
  // The default values still show — the hint is additive, never a veil.
  assert.deepEqual(alignment.values, { 'justify-content': 'center' });
});

test('hasMore ignores condition values of properties the widget does not own', () => {
  const styleMap = {
    [`${BLOCK}::.mock-container`]: {
      default: {},
      hover: { padding: '1rem' },
    },
  };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.equal(alignment.hasMore, false);
});

test('each control reads its OWN bound element, not its siblings', () => {
  const styleMap = {
    [`${BLOCK}::.mock-image`]: {
      default: { width: '50%' },
      narrow: { width: '100%' },
    },
  };
  const [alignment, width] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.deepEqual(alignment.values, {});
  assert.equal(alignment.hasMore, false);
  assert.deepEqual(width.values, { width: '50%' });
  assert.equal(width.hasMore, true);
});

test('the posed compound threads through: values read at it, condition echoed', () => {
  // The one write rule: basic reads (and the pipeline writes) at the
  // editing compound the canvas pose derives, not a pinned default.
  const styleMap = {
    [`${BLOCK}::.mock-image`]: {
      default: { width: '50%' },
      narrow: { width: '100%' },
    },
  };
  const [alignment, width] = controlModels(guide, BLOCK, blockTypes, styleMap, {}, [], [], 'narrow');
  assert.equal(width.condition, 'narrow');
  assert.deepEqual(width.values, { width: '100%' });
  // Every model echoes the compound, styled at it or not.
  assert.equal(alignment.condition, 'narrow');
  assert.deepEqual(alignment.values, {});
});

test('hasMore is pose-relative: styling elsewhere flags, the posed compound never', () => {
  const styleMap = {
    [`${BLOCK}::.mock-image`]: {
      default: { width: '50%' },
      narrow: { width: '100%' },
    },
  };
  // Posed at narrow, the default block is "more elsewhere" — default is
  // just another compound (of zero parts) once the pose is the target.
  const [, width] = controlModels(guide, BLOCK, blockTypes, styleMap, {}, [], [], 'narrow');
  assert.equal(width.hasMore, true);
  // Styled ONLY at the posed compound: everything shows, no hint.
  const narrowOnly = { [`${BLOCK}::.mock-image`]: { narrow: { width: '100%' } } };
  const [, posedOnly] = controlModels(guide, BLOCK, blockTypes, narrowOnly, {}, [], [], 'narrow');
  assert.equal(posedOnly.hasMore, false);
  assert.deepEqual(posedOnly.values, { width: '100%' });
});

test('PHP empty-array entries ([] instead of {}) read as no values', () => {
  const styleMap = { [`${BLOCK}::.mock-container`]: [] };
  const [alignment] = controlModels(guide, BLOCK, blockTypes, styleMap);
  assert.deepEqual(alignment.values, {});
  assert.equal(alignment.hasMore, false);
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
          inner_width: { label: 'Inner width', widget: 'width', element: '.inner' },
        },
      },
    },
  };
  // An untyped (plugin) block resolves against the universal block kind.
  const models = controlModels(kindGuide, BLOCK, {}, {});
  assert.deepEqual(models.map((model) => model.id), ['inner_width']);
  assert.equal(models[0].key, `${BLOCK}::.inner`);
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
          width: { label: 'Page width', widget: 'width' },
        },
      },
    },
  };
  const styleMap = { [ENTITY]: { default: { width: '80%' } } };
  const models = controlModels(entityGuide, ENTITY, blockTypes, styleMap);
  assert.deepEqual(models.map((model) => model.id), ['width']);
  const [width] = models;
  assert.equal(width.label, 'Page width');
  assert.equal(width.element, null);
  assert.equal(width.key, ENTITY);
  assert.deepEqual(width.values, { width: '80%' });
  assert.equal(width.hasMore, false);
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
          gap: { label: 'Gap', widget: 'gap', element: '.daedalus-layout-layout', section: 'layout' },
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
  assert.equal(gap.element, '.daedalus-layout-layout');
  assert.equal(gap.key, `${ENTITY}::.daedalus-layout-layout`);
  assert.deepEqual(gap.values, { gap: '2rem' });
  assert.deepEqual(gap.granted, SPACING);
  assert.deepEqual(gap.constraints, { gap: { min: 0 } });
  assert.equal(gap.section, 'layout');
  // A longhand set at another compound flags the hint like any owned
  // property.
  assert.equal(gap.hasMore, true);
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
          width: { label: 'Page width', widget: 'width' },
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
          width: { widget: 'width', element: '.mock-image' },
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
          element: '.mock-container',
        },
        width: { label: 'Width', widget: 'width' },
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
  assert.equal(width.element, null);
  assert.equal(width.key, BLOCK);
  assert.deepEqual(width.values, { width: '50%' });
  assert.equal(width.hasMore, false);
  // The part-bound sibling is untouched by the root grammar.
  assert.equal(alignment.element, '.mock-container');
  assert.equal(alignment.key, `${BLOCK}::.mock-container`);
  assert.deepEqual(alignment.values, { 'justify-content': 'center' });
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
        width: { label: 'Page width', widget: 'width' },
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
        width: { label: 'Width', widget: 'width' },
        image_width: { label: 'Image width', widget: 'width', element: '.mock-image' },
        alignment: { label: 'Alignment', widget: 'flex_align', element: '.mock-container' },
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

test('an UNRESOLVED variant reference sets hasMore (no library, no lies)', () => {
  // A variant reference carries no inline styles — the styling lives in
  // the library. With no library (still fetching, or the variant is gone)
  // the widget shows no values but MUST NOT read as "no styles": hasMore
  // renders the honesty hint.
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap);
  assert.equal(width.key, BLOCK);
  assert.deepEqual(width.values, {});
  assert.equal(width.hasMore, true);
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
  assert.deepEqual(width.values, { width: '50%' });
  assert.equal(width.hasMore, true);
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

test('a RESOLVED variant reference populates values, root and part bindings', () => {
  // In edit mode a variant is a preset: the controls read the referenced
  // variant's values through effectiveEntry, so an on-variant element's
  // widgets populate exactly like a custom-styled one's (the panel-level
  // provenance notice says where the values come from). No hasMore: the
  // values ARE shown now.
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap, {}, library);
  assert.deepEqual(width.values, { width: '80%' });
  assert.equal(width.hasMore, false);
  // The part binding resolves the variant's element-relative part styles.
  assert.deepEqual(alignment.values, { 'justify-content': 'center' });
  assert.equal(alignment.hasMore, false);
});

test('the model carries provenance for a root and a part binding', () => {
  // The bound TARGET's origin inputs, read for the model's key itself:
  // a root binding attributes the root's own entry and the variant's
  // root layer, a part binding the part's own entry and the variant's
  // part layer, never the root's. The skin defaults resolve the part
  // key's `::.part` relative the same way.
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
  assert.equal(width.provenance.own, styleMap[BLOCK], 'root: the own entry is the root map entry');
  assert.equal(width.provenance.suppliers.default.width, 'hero_wide', 'root: the variant supplies width at Base');
  assert.equal(width.provenance.shadowed.default.width, 'hero_wide', 'root: the own width at Base shadows the variant');
  assert.deepEqual(width.provenance.skinEntry, { default: { width: '60%' } }, 'root: the skin defaults for the block type');
  assert.equal(width.provenance.labelOf('hero_wide'), 'Hero wide');
  assert.equal(width.provenance.valueOf('hero_wide', 'default', 'width'), '80%', 'what returns on unset');
  assert.equal(alignment.provenance.own, styleMap[`${BLOCK}::.mock-container`], 'part: the own entry is the part map entry');
  assert.equal(alignment.provenance.suppliers.default['justify-content'], 'hero_wide', 'part: the variant\'s part layer supplies');
  assert.equal(alignment.provenance.suppliers.default.width, undefined, 'part: the root\'s width is not the part\'s');
  assert.deepEqual(alignment.provenance.shadowed, {}, 'part: an own value at Narrow shadows nothing the variant sets there');
  assert.deepEqual(alignment.provenance.skinEntry, { default: { 'justify-content': 'start' } }, 'part: the skin\'s part defaults');
  assert.equal(alignment.provenance.valueOf('hero_wide', 'default', 'justify-content'), 'center');
  // No variants, no skin: the tables are empty and the skin entry null.
  const [, bare] = controlModels(rootGuide, BLOCK, blockTypes, { [BLOCK]: { default: { width: '50%' } } });
  assert.deepEqual(bare.provenance.suppliers, {});
  assert.deepEqual(bare.provenance.shadowed, {});
  assert.equal(bare.provenance.skinEntry, null);
  assert.equal(bare.provenance.labelOf('missing'), 'missing', 'an unresolvable reference labels as itself');
});

test('the choice row\'s origin at a pose: the defaults tier delivers from Base and the sentence says where', () => {
  // The basic rows' origin derivation (choice-row-model.js originFor)
  // over the model's own provenance, posed at Medium: nothing in the
  // map sets max-width or display, so the skin's Base block delivers
  // max-width and the image declaration's Base block display, each
  // named with where it delivers from. The sentences are display
  // vocabulary, so Drupal.t is scoped to this test alone: controlModels
  // itself must never need it.
  const posed = {
    ...rootGuide,
    conditions: { medium: { label: 'Medium', axis: 'width' } },
    skinEditUrl: '/admin/appearance/skins/1',
    skinDefaults: { 'block_type:image': { '': { default: { 'max-width': '90%' } } } },
    declaredDefaults: { 'block_type:image': { '': { default: { display: 'block' } } } },
  };
  const styleMap = { [BLOCK]: { default: { width: '50%' } } };
  const [, width] = controlModels(posed, BLOCK, blockTypes, styleMap, {}, [], [], 'medium');
  const rowAt = (model, condition, property) => originFor({
    guide: posed, materials: EMPTY_MATERIALS, condition, property, entry: model.entry, provenance: model.provenance,
  });
  globalThis.Drupal = {
    t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
  };
  try {
    const skin = rowAt(width, 'medium', 'max-width');
    assert.equal(skin.posture, 'inherited');
    assert.equal(skin.layer, 'skin');
    assert.equal(skin.title, '90% from the active skin at Base');
    assert.deepEqual(skin.doorways.map((doorway) => doorway.id), ['skin'], 'the skin form, never a drive');
    // The origin carries the ghost from the same read: the raw value
    // the sentence names is what the empty row rests on.
    assert.equal(skin.ghost, '90%');
    const declared = rowAt(width, 'medium', 'display');
    assert.equal(declared.posture, 'inherited');
    assert.equal(declared.layer, 'declared');
    assert.equal(declared.title, 'block from the image block type declaration at Base');
    assert.deepEqual(declared.doorways.map((doorway) => [doorway.id, doorway.disabled]), [['declared', true]], 'a declaration is code: the one doorway is inert');
    assert.equal(declared.ghost, 'block');
    // The map's own Base value still beats the tier at Medium.
    assert.equal(rowAt(width, 'medium', 'width').title, '50% from Base');
    assert.equal(rowAt(width, 'medium', 'width').ghost, '50%');
    // Nothing anywhere: a quiet origin with no ghost.
    assert.equal(rowAt(width, 'medium', 'color').posture, 'own');
    assert.equal(rowAt(width, 'medium', 'color').ghost, null);
    // Posed at Base the tail drops: the delivering condition is the pose.
    const [, atBase] = controlModels(posed, BLOCK, blockTypes, styleMap, {}, [], [], 'default');
    assert.equal(rowAt(atBase, 'default', 'display').title, 'block from the image block type declaration');
    assert.equal(rowAt(atBase, 'default', 'max-width').title, '90% from the active skin');
    assert.equal(rowAt(atBase, 'default', 'max-width').ghost, '90%');
    // A value set at the pose is the row's own; the resting value is itself.
    assert.equal(rowAt(atBase, 'default', 'width').posture, 'own');
    assert.equal(rowAt(atBase, 'default', 'width').ghost, '50%');
  }
  finally {
    delete globalThis.Drupal;
  }
});

test('a resolved variant with nothing for the bound part reads as unset', () => {
  const partless = [{ ...library[0], value: { '': { default: { width: '80%' } } } }];
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap, {}, partless);
  assert.deepEqual(width.values, { width: '80%' });
  // The variant simply does not style the part: honest empty, no hint.
  assert.deepEqual(alignment.values, {});
  assert.equal(alignment.hasMore, false);
});

test('a resolved variant\'s conditions flag hasMore like an element\'s own', () => {
  const conditioned = [{
    ...library[0],
    value: {
      '': { default: { width: '80%' }, narrow: { width: '100%' } },
      '::.mock-container': { narrow: { 'justify-content': 'safe center' } },
    },
  }];
  const styleMap = { [BLOCK]: { variants: [{ variant: 'hero_wide', fragment: '' }] } };
  const [alignment, width] = controlModels(rootGuide, BLOCK, blockTypes, styleMap, {}, conditioned);
  assert.deepEqual(width.values, { width: '80%' });
  assert.equal(width.hasMore, true);
  assert.equal(alignment.hasMore, true);
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
  assert.deepEqual(width.values, { width: '100%' });
});

test('root hasMore still flags conditions, same as parts', () => {
  const conditioned = { [BLOCK]: { default: { width: '50%' }, narrow: { width: '100%' } } };
  assert.equal(controlModels(rootGuide, BLOCK, blockTypes, conditioned)[1].hasMore, true);
});

test('a PRESENT but empty/non-string element is malformed, not a root binding', () => {
  const brokenGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: {
          empty_element: { widget: 'width', element: '' },
          null_element: { widget: 'width', element: null },
          numeric_element: { widget: 'width', element: 3 },
          root: { widget: 'width' },
        },
      },
    },
  };
  const models = controlModels(brokenGuide, BLOCK, blockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['root']);
});

test('malformed bindings are dropped, valid siblings survive', () => {
  const brokenGuide = {
    ...guide,
    blockTypes: {
      image: {
        ...guide.blockTypes.image,
        controls: {
          not_a_map: 'nope',
          no_widget: { element: '.mock-image' },
          bad_element: { widget: 'width', element: 'div > .loose' },
          good: { label: 'Good', widget: 'width', element: '.mock-image' },
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
          mystery: { widget: 'not_installed', element: '.mock-image' },
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
  // No definition → no owned properties → nothing to read or flag from
  // structured conditions (the registry gate keeps it unrendered anyway).
  assert.deepEqual(mystery.values, {});
  assert.equal(mystery.hasMore, false);
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
        width: { label: 'Width', widget: 'width' },
      },
    },
  },
  instances: {
    '.s-*': {
      label: 'Text element',
      grants: { typography: ['font-size', 'color'] },
      valueSpaces: { 'font-size': { scale: 'type' } },
      controls: {
        typography: { label: 'Typography', widget: 'typography' },
        text_color: { label: 'Text color', widget: 'text_color' },
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
  assert.equal(typography.element, null);
  assert.equal(typography.key, INSTANCE);
  assert.deepEqual(typography.values, { 'font-size': '1.25rem' });
  assert.deepEqual(textColor.values, { color: 'teal' });
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
  assert.equal(models[0].key, fieldInstance);
});

test('constraints and definitions resolve against the namespace declaration', () => {
  const [typography] = controlModels(instanceGuide, INSTANCE, textBlockTypes, {});
  assert.deepEqual(typography.constraints, { 'font-size': { scale: 'type' } });
  assert.deepEqual(typography.propertyDefinitions, { 'font-size': { label: 'Font size', type: 'dimension' } });
});

test('the posed compound threads through instance parts, hasMore included', () => {
  const styleMap = {
    [INSTANCE]: {
      default: { 'font-size': '1rem' },
      narrow: { 'font-size': '0.875rem' },
    },
  };
  const posed = controlModels(instanceGuide, INSTANCE, textBlockTypes, {}, {}, [], [], 'narrow');
  assert.equal(posed[0].condition, 'narrow');
  const [typography] = controlModels(instanceGuide, INSTANCE, textBlockTypes, styleMap);
  assert.deepEqual(typography.values, { 'font-size': '1rem' });
  assert.equal(typography.hasMore, true, 'the narrow styling flags at the default pose');
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
  // …and the '::>' children pseudo-key is the tag tier's land, not a
  // control target.
  assert.deepEqual(controlModels(instanceGuide, `${INSTANCE}::>`, textBlockTypes, {}), []);
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
          bad: { label: 'Bad', widget: 'width', element: '.inner' },
          text_color: false,
        },
      },
    },
  };
  const models = controlModels(withElement, INSTANCE, textBlockTypes, {});
  assert.deepEqual(models.map((model) => model.id), ['typography']);
});

test('compoundOperations: one wire op per change, default condition omitted', () => {
  // The compound gesture: the widget's own change first, the
  // parent-keyed companion after it, both in one batch (one undo label,
  // atomic server-side). 'default' stays off the wire, matching commit().
  const ops = compoundOperations([
    { key: BLOCK, properties: { 'align-self': 'center' } },
    { key: ENTITY, properties: { display: 'grid' } },
  ]);
  assert.deepEqual(ops, [
    { op: 'update_style', key: BLOCK, properties: { 'align-self': 'center' } },
    { op: 'update_style', key: ENTITY, properties: { display: 'grid' } },
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
    controls: { spacing: { label: 'Spacing', widget: 'spacing_scale' } },
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
  const layout = [{ uuid: ROOT, layout_id: 'layout_onecol', children: [{ uuid: NESTED, layout_id: 'layout_onecol', children: [] }] }];
  const granted = (guide, uuid, tree) => controlModels(guide, `container:${uuid}`, {}, {}, {}, [], tree)
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
