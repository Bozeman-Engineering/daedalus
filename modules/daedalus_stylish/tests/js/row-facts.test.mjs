/**
 * Self-asserting tests for the variant row-facts provider
 * (js/src/row-facts.js) and the doors seam it runs through
 * (js/src/inspector-doors.js): what a page elements row says about its
 * styling and where its doorways lead.
 *
 * Covered: the facts for a key with declarations of its own wearing two
 * variants (a dot per worn variant in its layer hue titled with its
 * label, then the element dot), none for a bare key, a part address read
 * as its own entry; the doorways (per worn variant "Edit {label}" and the
 * Outline toggle, checked from the paint state, then "Show its own styles
 * in code" for an element styled on itself; none for a bare key); the
 * bound provider's id, reads and announcements; the doors in page (the
 * panel opened first, a refused open leaving the session unopened),
 * popped out (routed over the pop-out leader) and the code door's
 * selection, mode and place; the seam's routed door.
 *
 * Run directly (`node tests/js/row-facts.test.mjs`) or via the suite
 * glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  VARIANTS_PROVIDER_ID,
  createVariantDoors,
  createVariantRowFacts,
  outlinedIn,
  variantDoorways,
  variantFacts,
} from '../../js/src/row-facts.js';
import {
  DOOR_EDIT,
  DOOR_OUTLINE,
  mountDoors,
  openDocument,
  runDoor,
  toggleOutline,
} from '../../js/src/inspector-doors.js';
import { ELEMENT_LAYER, layerColor, variantLayerId } from '../../js/src/code/layers.js';

// Drupal.t at call time, placeholders substituted the way
// Drupal.formatString does; formatPlural picks the form and substitutes
// @count.
const substitute = (text, args) =>
  Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text);
globalThis.Drupal = {
  t: (text, args = {}) => substitute(text, args),
  formatPlural: (count, singular, plural, args = {}) =>
    substitute(count === 1 ? singular : plural, { '@count': count, ...args }),
};

let current = null;
const test = (name, run) => {
  current = name;
  run();
  process.stdout.write(`ok - ${name}\n`);
};
process.on('uncaughtException', (error) => {
  process.stderr.write(`FAIL - ${current}\n${error.stack ?? error.message}\n`);
  process.exit(1);
});

// --- fixtures -------------------------------------------------------------

const KEY = 'block:44444444-4444-4444-4444-444444444444';
const BARE = 'block:55555555-5555-5555-5555-555555555555';
const WORN_ONLY = 'block:66666666-6666-6666-6666-666666666666';
const PART = `${KEY}::.icon`;

const VARIANTS = [
  { id: 'card', label: 'Card', kind: 'variant' },
  { id: 'wide', label: 'Wide (1024px)', kind: 'variant' },
];

// KEY: four own declarations over two conditions plus one on a part
// (counted at the element, as foldSummary reads), wearing card and glow
// (glow is not in the roster: a stale wearing still reads by its id).
const MAP = {
  [KEY]: {
    variants: [{ variant: 'card', fragment: '' }, { variant: 'glow', fragment: '' }],
    default: { color: 'red', padding: '1rem' },
    hover: { color: 'blue', background: 'white' },
  },
  [PART]: {
    variants: [{ variant: 'wide', fragment: '' }],
    default: { width: '2rem' },
  },
  [WORN_ONLY]: { variants: [{ variant: 'wide', fragment: '' }] },
};

const roster = (variants) => ({ ofKind: (kind) => (kind === 'variant' ? variants : []) });

// --- facts ----------------------------------------------------------------

test('facts: a dot per worn variant in its layer hue titled with its label, then the element dot', () => {
  assert.deepEqual(variantFacts(MAP, KEY, VARIANTS), [
    { mark: 'dot', hue: layerColor(variantLayerId('card')), title: 'Wears Card' },
    { mark: 'dot', hue: layerColor(variantLayerId('glow')), title: 'Wears glow' },
    { mark: 'dot', hue: layerColor(ELEMENT_LAYER), title: 'Styled on itself: 5 declarations' },
  ]);
  // Worn only: no element dot. One declaration: the singular form.
  assert.deepEqual(variantFacts(MAP, WORN_ONLY, VARIANTS), [
    { mark: 'dot', hue: layerColor(variantLayerId('wide')), title: 'Wears Wide (1024px)' },
  ]);
  assert.deepEqual(variantFacts({ [KEY]: { default: { color: 'red' } } }, KEY, VARIANTS), [
    { mark: 'dot', hue: layerColor(ELEMENT_LAYER), title: 'Styled on itself: 1 declaration' },
  ]);
});

test('facts: a bare key has none; a part address reads its own entry', () => {
  assert.deepEqual(variantFacts(MAP, BARE, VARIANTS), []);
  assert.deepEqual(variantFacts({}, KEY, VARIANTS), []);
  assert.deepEqual(variantFacts(null, KEY, VARIANTS), []);
  assert.deepEqual(variantFacts(MAP, PART, VARIANTS), [
    { mark: 'dot', hue: layerColor(variantLayerId('wide')), title: 'Wears Wide (1024px)' },
    { mark: 'dot', hue: layerColor(ELEMENT_LAYER), title: 'Styled on itself: 1 declaration' },
  ]);
});

// --- doorways -------------------------------------------------------------

const recordingDoors = (outlined = () => false) => {
  const calls = [];
  return {
    calls,
    doors: {
      outlined,
      edit: (...args) => calls.push(['edit', ...args]),
      outline: (...args) => calls.push(['outline', ...args]),
      showCode: (...args) => calls.push(['showCode', ...args]),
    },
  };
};

test('doorways: per worn variant Edit and Outline (named when several are worn), then the code door for an element styled on itself', () => {
  const { calls, doors } = recordingDoors((reference) => reference === 'glow');
  const items = variantDoorways(MAP, KEY, VARIANTS, doors);
  assert.deepEqual(items.map((item) => item.label), [
    'Edit Card',
    'Outline everything wearing Card',
    'Edit glow',
    'Outline everything wearing glow',
    'Show its own styles in code',
  ]);
  // The Outline items are checkable, checked where the paint says so; the
  // others are plain.
  assert.deepEqual(items.map((item) => item.checked), [undefined, false, undefined, true, undefined]);
  items.forEach((item) => assert.equal(typeof item.title, 'string'));
  items.forEach((item) => item.run());
  assert.deepEqual(calls, [
    ['edit', 'card', KEY],
    ['outline', 'card'],
    ['edit', 'glow', KEY],
    ['outline', 'glow'],
    ['showCode', KEY],
  ]);
});

test('doorways: one worn variant reads "wearing it"; worn only has no code door; a bare key has none', () => {
  const { doors } = recordingDoors();
  assert.deepEqual(variantDoorways(MAP, WORN_ONLY, VARIANTS, doors).map((item) => item.label), [
    'Edit Wide (1024px)',
    'Outline everything wearing it',
  ]);
  assert.deepEqual(variantDoorways(MAP, BARE, VARIANTS, doors), []);
  // Own styles alone: the code door only.
  assert.deepEqual(
    variantDoorways({ [KEY]: { default: { color: 'red' } } }, KEY, VARIANTS, doors).map((item) => item.label),
    ['Show its own styles in code'],
  );
});

test('outlinedIn: a toggled layer for the reference, never the hover flash', () => {
  const paint = {
    layers: [
      { id: variantLayerId('card'), variant: 'card', label: 'Card', flash: false },
      { id: variantLayerId('wide'), variant: 'wide', label: 'Wide', flash: true },
    ],
  };
  assert.equal(outlinedIn(paint, 'card'), true);
  assert.equal(outlinedIn(paint, 'wide'), false);
  assert.equal(outlinedIn(paint, 'glow'), false);
  assert.equal(outlinedIn(null, 'card'), false);
});

// --- the bound provider ---------------------------------------------------

test('provider: reads the source per call, checks Outline off the paint state, announces on the map and the roster', () => {
  let map = MAP;
  let variants = VARIANTS;
  let paint = null;
  const mapListeners = [];
  const rosterListeners = [];
  const source = {
    getStyleMap: () => map,
    getMaterials: () => roster(variants),
    getCanvasPaint: () => paint,
    onStyleMapChange: (callback) => mapListeners.push(callback),
    onMaterialsChange: (callback) => rosterListeners.push(callback),
  };
  const { calls, doors } = recordingDoors();
  const provider = createVariantRowFacts({ source, doors });
  assert.equal(provider.id, VARIANTS_PROVIDER_ID);
  assert.equal(provider.id, 'daedalus_stylish.variants');

  let announced = 0;
  provider.subscribe(() => {
    announced += 1;
  });
  assert.equal(mapListeners.length, 1);
  assert.equal(rosterListeners.length, 1);
  mapListeners[0]();
  rosterListeners[0]();
  assert.equal(announced, 2);

  assert.deepEqual(provider.facts(WORN_ONLY).map((fact) => fact.title), ['Wears Wide (1024px)']);
  // A renamed variant reads its new label on the next read.
  variants = [{ id: 'wide', label: 'Wide', kind: 'variant' }];
  assert.deepEqual(provider.facts(WORN_ONLY).map((fact) => fact.title), ['Wears Wide']);
  // A map change reads through too.
  map = {};
  assert.deepEqual(provider.facts(WORN_ONLY), []);
  assert.deepEqual(provider.doorways(WORN_ONLY), []);
  map = MAP;

  // Outline's checkmark follows the source's paint, not the inspector.
  assert.equal(provider.doorways(WORN_ONLY)[1].checked, false);
  paint = { layers: [{ id: variantLayerId('wide'), variant: 'wide', label: 'Wide', flash: false }] };
  assert.equal(provider.doorways(WORN_ONLY)[1].checked, true);
  // The provider's own doors ride the given ones, outlined excepted.
  provider.doorways(WORN_ONLY)[0].run();
  assert.deepEqual(calls, [['edit', 'wide', WORN_ONLY]]);
});

// --- the doors --------------------------------------------------------------

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const fakeInspector = () => {
  const calls = [];
  const unmount = mountDoors({
    openDocument: (reference, anchor) => calls.push(['openDocument', reference, anchor]),
    toggleOutline: (reference) => calls.push(['toggleOutline', reference]),
  });
  return { calls, unmount };
};

const fakeSource = (place = { place: 'styles', editing: null }) => {
  const calls = [];
  return {
    calls,
    select: (key) => calls.push(['select', key]),
    getPlace: () => place,
    setPlace: (next) => calls.push(['setPlace', next]),
  };
};

const fakeMode = () => {
  const calls = [];
  return { calls, set: (mode) => calls.push(mode) };
};

const fakePopout = (poppedOut) => {
  const calls = [];
  return { calls, isPoppedOut: () => poppedOut, openDoor: (payload) => calls.push(payload) };
};

await (async () => {
  current = 'doors in page: Edit opens the panel first and enters through the seam; a refused open leaves it unopened';
  const inspector = fakeInspector();
  const opens = [];
  let opened = true;
  const openPanel = () => {
    opens.push('open');
    return Promise.resolve(opened);
  };
  const doors = createVariantDoors({ source: fakeSource(), popout: fakePopout(false), mode: fakeMode(), openPanel });
  doors.edit('card', KEY);
  await flush();
  assert.deepEqual(opens, ['open']);
  assert.deepEqual(inspector.calls, [['openDocument', 'card', KEY]]);
  opened = false;
  doors.edit('wide', KEY);
  await flush();
  assert.deepEqual(opens, ['open', 'open']);
  assert.deepEqual(inspector.calls, [['openDocument', 'card', KEY]]);
  // Outline needs no panel: the paint is on the page.
  doors.outline('card');
  assert.deepEqual(opens, ['open', 'open']);
  assert.deepEqual(inspector.calls, [['openDocument', 'card', KEY], ['toggleOutline', 'card']]);
  inspector.unmount();
  // With no inspector mounted the seam answers false and nothing runs.
  assert.equal(openDocument('card', KEY), false);
  assert.equal(toggleOutline('card'), false);
  process.stdout.write(`ok - ${current}\n`);
})();

test('doors popped out: Edit and Outline route over the pop-out leader, nothing runs here', () => {
  const inspector = fakeInspector();
  const popout = fakePopout(true);
  let opens = 0;
  const doors = createVariantDoors({
    source: fakeSource(),
    popout,
    mode: fakeMode(),
    openPanel: () => {
      opens += 1;
      return Promise.resolve(true);
    },
  });
  doors.edit('card', KEY);
  doors.outline('card');
  assert.deepEqual(popout.calls, [
    { door: DOOR_EDIT, reference: 'card', key: KEY },
    { door: DOOR_OUTLINE, reference: 'card', key: null },
  ]);
  assert.deepEqual(inspector.calls, []);
  assert.equal(opens, 0);
  inspector.unmount();
});

test('the code door: select, code mode, the styling place only when elsewhere, the panel open in page and not popped out', () => {
  let opens = 0;
  const openPanel = () => {
    opens += 1;
    return Promise.resolve(true);
  };
  // Already on the styling place: the place is left alone.
  const home = fakeSource({ place: 'styles', editing: 'card' });
  const mode = fakeMode();
  createVariantDoors({ source: home, popout: fakePopout(false), mode, openPanel }).showCode(KEY);
  assert.deepEqual(home.calls, [['select', KEY]]);
  assert.deepEqual(mode.calls, ['code']);
  assert.equal(opens, 1);
  // From the catalog: lands on styles with the editing reference kept.
  const away = fakeSource({ place: 'materials', editing: 'card' });
  createVariantDoors({ source: away, popout: null, mode: fakeMode(), openPanel }).showCode(KEY);
  assert.deepEqual(away.calls, [['select', KEY], ['setPlace', { place: 'styles', editing: 'card' }]]);
  assert.equal(opens, 2);
  // Popped out: the satellite shows it; no panel to open here.
  const popped = fakeSource();
  createVariantDoors({ source: popped, popout: fakePopout(true), mode: fakeMode(), openPanel }).showCode(KEY);
  assert.deepEqual(popped.calls, [['select', KEY]]);
  assert.equal(opens, 2);
});

test('the seam: a routed door runs the mounted inspector\'s door; an unknown door or none mounted does nothing', () => {
  assert.equal(runDoor({ door: DOOR_EDIT, reference: 'card', key: KEY }), false);
  const inspector = fakeInspector();
  assert.equal(runDoor({ door: DOOR_EDIT, reference: 'card', key: KEY }), true);
  assert.equal(runDoor({ door: DOOR_OUTLINE, reference: 'wide' }), true);
  assert.equal(runDoor({ door: 'elsewhere', reference: 'wide' }), false);
  assert.deepEqual(inspector.calls, [['openDocument', 'card', KEY], ['toggleOutline', 'wide']]);
  // A later mount's slot is left alone by an earlier unmount.
  const later = fakeInspector();
  inspector.unmount();
  assert.equal(toggleOutline('card'), true);
  assert.deepEqual(later.calls, [['toggleOutline', 'card']]);
  later.unmount();
  assert.equal(toggleOutline('card'), false);
});

process.stdout.write('row-facts suite passed\n');
