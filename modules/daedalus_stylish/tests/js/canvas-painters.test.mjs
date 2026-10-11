/**
 * Self-asserting tests for the canvas-highlight painter registry:
 * property → painter resolution (padding/margin sides, gap axes,
 * painterless properties resolve to nothing) and the per-match
 * highlight arithmetic (per-painter grouping and dedup, canonical part
 * order, axis masking, zero-size rects dropped, the lazy childRects
 * contract).
 *
 * Run directly: `node tests/js/canvas-painters.test.mjs`. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { painterFor, highlightRects } from '../../js/src/canvas-painters.js';
import { createCanvasSelectorPaint } from '../../js/src/canvas-selector-paint.js';
import { SIDES } from '../../js/src/widgets/lib/box-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const rect = (left, top, width, height) => ({ left, top, width, height });
const fakeComputed = (map) => ({ getPropertyValue: (property) => map[property] ?? '' });

/**
 * A measurement context whose childRects trap asserts the lazy
 * contract: painters that do not need children must never walk them.
 */
const context = ({ box = rect(100, 50, 200, 100), computed = {}, childRects = null } = {}) => ({
  rect: box,
  computed: fakeComputed(computed),
  childRects: childRects ?? (() => {
    throw new Error('childRects walked by a painter that does not need children');
  }),
});

const PADDED = {
  'padding-top': '10px', 'padding-right': '20px', 'padding-bottom': '0px', 'padding-left': '40px',
};

// --- painterFor -------------------------------------------------------------

test('painterFor maps box properties to their painter and sides', () => {
  assert.deepEqual(painterFor('padding'), { kind: 'padding', parts: SIDES });
  assert.deepEqual(painterFor('padding-top'), { kind: 'padding', parts: ['top'] });
  assert.deepEqual(painterFor('margin'), { kind: 'margin', parts: SIDES });
  assert.deepEqual(painterFor('margin-left'), { kind: 'margin', parts: ['left'] });
});

test('painterFor maps logical box longhands to their physical sides', () => {
  assert.deepEqual(painterFor('padding-inline'), { kind: 'padding', parts: ['right', 'left'] });
  assert.deepEqual(painterFor('padding-block'), { kind: 'padding', parts: ['top', 'bottom'] });
  assert.deepEqual(painterFor('margin-inline'), { kind: 'margin', parts: ['right', 'left'] });
  assert.equal(painterFor('padding-inline-start'), null);
});

test('painterFor maps gap properties to their axes', () => {
  assert.deepEqual(painterFor('gap'), { kind: 'gap', parts: ['row', 'column'] });
  assert.deepEqual(painterFor('row-gap'), { kind: 'gap', parts: ['row'] });
  assert.deepEqual(painterFor('column-gap'), { kind: 'gap', parts: ['column'] });
});

test('painterFor resolves nothing for painterless properties', () => {
  ['color', 'font-size', 'background', 'box-shadow', 'border-radius']
    .forEach((property) => {
      assert.equal(painterFor(property), null, property);
    });
});

// --- highlightRects: padding ------------------------------------------------

test('highlightRects paints only the addressed padding sides', () => {
  const bands = highlightRects(['padding-top', 'padding-left'], context({ computed: PADDED }));
  assert.deepEqual(bands, [
    { kind: 'padding', ...rect(100, 50, 200, 10) },
    { kind: 'padding', ...rect(100, 60, 40, 90) },
  ]);
});

test('highlightRects drops zero-size bands rather than paint empties', () => {
  assert.deepEqual(highlightRects(['padding-bottom'], context({ computed: PADDED })), []);
});

test('highlightRects merges a family into one deduped pass in canonical order', () => {
  // Shorthand + a longhand it already covers: four bands, not five, and
  // sides come out in SIDES order regardless of property order.
  const bands = highlightRects(['padding-left', 'padding'], context({
    computed: {
      'padding-top': '10px', 'padding-right': '10px', 'padding-bottom': '10px', 'padding-left': '10px',
    },
  }));
  assert.equal(bands.length, 4);
  assert.deepEqual(bands[0], { kind: 'padding', ...rect(100, 50, 200, 10) });
  assert.ok(bands.every((band) => band.kind === 'padding'));
});

test('highlightRects starts padding bands inside the borders', () => {
  const bands = highlightRects(['padding-top'], context({
    box: rect(0, 0, 100, 100),
    computed: {
      'padding-top': '10px',
      'border-top-width': '5px', 'border-right-width': '5px',
      'border-bottom-width': '5px', 'border-left-width': '5px',
    },
  }));
  assert.deepEqual(bands, [{ kind: 'padding', ...rect(5, 5, 90, 10) }]);
});

// --- highlightRects: margin -------------------------------------------------

test('highlightRects paints margin bands outside the border box', () => {
  const bands = highlightRects(['margin'], context({
    computed: {
      'margin-top': '10px', 'margin-right': '20px', 'margin-bottom': '30px', 'margin-left': '40px',
    },
  }));
  assert.deepEqual(bands, [
    { kind: 'margin', ...rect(60, 40, 260, 10) },
    { kind: 'margin', ...rect(300, 50, 20, 100) },
    { kind: 'margin', ...rect(60, 150, 260, 30) },
    { kind: 'margin', ...rect(60, 50, 40, 100) },
  ]);
});

test('highlightRects paints nothing for negative or auto margins', () => {
  const bands = highlightRects(['margin'], context({
    computed: {
      'margin-top': '-16px', 'margin-right': 'auto', 'margin-bottom': '12px', 'margin-left': '0px',
    },
  }));
  // Only the honest side renders, and it claims no absent-side corners.
  assert.deepEqual(bands, [{ kind: 'margin', ...rect(100, 150, 200, 12) }]);
});

// --- highlightRects: gap ----------------------------------------------------

const GRID_CHILDREN = [
  rect(0, 0, 40, 40), rect(50, 0, 40, 40),
  rect(0, 50, 40, 40), rect(50, 50, 40, 40),
];
const GRID_GAPS = { 'row-gap': '10px', 'column-gap': '10px' };

test('highlightRects masks gap strips to the addressed axis', () => {
  const grid = () => context({ computed: GRID_GAPS, childRects: () => GRID_CHILDREN });
  assert.equal(highlightRects(['gap'], grid()).length, 3);
  assert.deepEqual(
    highlightRects(['row-gap'], grid()),
    [{ kind: 'gap', ...rect(0, 40, 90, 10) }],
  );
  assert.equal(highlightRects(['column-gap'], grid()).length, 2);
});

test('highlightRects walks children lazily — only gap painters need them', () => {
  // context()'s default childRects throws; padding/margin must not call it.
  const bands = highlightRects(['padding-top', 'margin-top'], context({
    computed: { ...PADDED, 'margin-top': '8px' },
  }));
  assert.equal(bands.length, 2);
});

// --- highlightRects: painterless and empty specs ----------------------------

test('highlightRects silently paints nothing for painterless properties', () => {
  // No painter runs at all: an empty context would throw if one did.
  assert.deepEqual(highlightRects(['color', 'font-size'], {}), []);
  assert.deepEqual(highlightRects([], {}), []);
  assert.deepEqual(highlightRects(null, {}), []);
});

test('highlightRects mixes families in one call, each tagged with its kind', () => {
  const bands = highlightRects(['padding-top', 'margin-top'], context({
    computed: { ...PADDED, 'margin-top': '8px' },
  }));
  assert.deepEqual(bands.map((band) => band.kind), ['padding', 'margin']);
  assert.deepEqual(bands[1], { kind: 'margin', ...rect(100, 42, 200, 8) });
});

// --- border ---------------------------------------------------------------

test('painterFor maps the three border families to the one border painter', () => {
  assert.deepEqual(painterFor('border-width'), { kind: 'border', parts: SIDES });
  assert.deepEqual(painterFor('border-top-width'), { kind: 'border', parts: ['top'] });
  assert.deepEqual(painterFor('border-left-style'), { kind: 'border', parts: ['left'] });
  assert.deepEqual(painterFor('border-color'), { kind: 'border', parts: SIDES });
  assert.deepEqual(painterFor('border-inline-color'), { kind: 'border', parts: ['right', 'left'] });
  assert.equal(painterFor('border'), null, 'the cross-family shorthand is wide tier, unpainted');
  assert.equal(painterFor('border-radius'), null);
  assert.equal(painterFor('outline-width'), null, 'outline has no sides');
});

test('highlightRects paints border bands between the border box and the padding box', () => {
  const computed = {
    'border-top-width': '5px', 'border-right-width': '2px', 'border-bottom-width': '0px', 'border-left-width': '10px',
  };
  const bands = highlightRects(
    ['border-top-width', 'border-left-color', 'border-bottom-style'],
    context({ box: rect(0, 0, 100, 100), computed }),
  );
  assert.deepEqual(bands, [
    { kind: 'border', ...rect(0, 0, 100, 5) },
    { kind: 'border', ...rect(0, 5, 10, 95) },
  ], 'three families, one pass in side order, the borderless bottom dropped');
  // A side's three longhands (the ring's highlight claim) are one band.
  assert.deepEqual(
    highlightRects(['border-top-width', 'border-top-style', 'border-top-color'], context({ box: rect(0, 0, 100, 100), computed })),
    [{ kind: 'border', ...rect(0, 0, 100, 5) }],
  );
});

// --- the selector paint (canvas-selector-paint.js) --------------------------

/**
 * A document double: <style> tags land in `head`, and `remove` lifts
 * them back out, so the slots' tags can be read by their data attribute.
 */
const fakeDocument = () => {
  const head = [];
  const document = {
    head: { appendChild: (tag) => head.push(tag) },
    createElement: () => {
      const tag = {
        attributes: {},
        textContent: '',
        setAttribute: (name, value) => { tag.attributes[name] = value; },
        remove: () => { head.splice(head.indexOf(tag), 1); },
      };
      return tag;
    },
  };
  const slots = () => Object.fromEntries(head.map((tag) => [tag.attributes['data-daedalus-stylish-selector-paint'], tag.textContent]));
  return { document, slots };
};

/**
 * A spine double: the three facts and the two subscriptions the paint
 * reads, with `fire` helpers; `selectorFor` prints the pair and the
 * treatment's first word so each slot's tag names what it paints.
 */
const fakeApp = () => {
  const app = {
    target: null,
    address: null,
    hoverListeners: [],
    targetListeners: [],
    Selection: {
      getTarget: () => app.target,
      getAddress: () => app.address,
    },
    onHoverSelectorChange: (callback) => app.hoverListeners.push(callback),
    onTargetChange: (callback) => app.targetListeners.push(callback),
    fireTargetChange: (selected, target, address) => {
      app.target = target;
      app.address = address;
      app.targetListeners.forEach((callback) => callback({ selected, target, address }));
    },
    fireHover: (hovered) => app.hoverListeners.forEach((callback) => callback(hovered)),
  };
  return app;
};
const behavior = {
  selectorFor: (key, selector, treatment) =>
    selector === 'unprintable' ? null : `${key}::${selector} ${treatment.split(' ')[2]}`,
};

test('the selector paint renders the subject facet dashed and the write address dotted, together, each slot emptying with its fact', () => {
  const { document, slots } = fakeDocument();
  const held = globalThis.document;
  globalThis.document = document;
  try {
    const app = fakeApp();
    createCanvasSelectorPaint({ app, behavior });
    const BLOCK = 'block:a';
    const SECTION = 'container:w';
    // A minted list item selected under "every list item in Welcome":
    // the item's own box dashed INSIDE the rule's dotted reach, both at
    // once (two facts, two slots).
    app.fireTargetChange(BLOCK, { key: BLOCK, selector: '.s-abc' }, { key: SECTION, selector: 'li' });
    assert.deepEqual(slots(), {
      selected: `${BLOCK}::.s-abc dashed`,
      write: `${SECTION}::li dotted`,
    });
    // Dropping the address (the chip's own entry) empties the dotted
    // slot alone; the facet keeps its dashed box.
    app.fireTargetChange(BLOCK, { key: BLOCK, selector: '.s-abc' }, null);
    assert.deepEqual(slots(), { selected: `${BLOCK}::.s-abc dashed` });
    // An address on the bare element (no facet) paints dotted alone.
    app.fireTargetChange(BLOCK, null, { key: SECTION, selector: '> *' });
    assert.deepEqual(slots(), { write: `${SECTION}::> * dotted` });
    // An address on the selected key itself (an instance part's own tag
    // tier) is a dotted box like any other.
    app.fireTargetChange(BLOCK, { key: BLOCK, selector: '.s-abc' }, { key: BLOCK, selector: 'li' });
    assert.deepEqual(slots(), {
      selected: `${BLOCK}::.s-abc dashed`,
      write: `${BLOCK}::li dotted`,
    });
    // A selection move clears both (the spine fires with neither).
    app.fireTargetChange(SECTION, null, null);
    assert.deepEqual(slots(), {});
    // A pair the behavior cannot print paints nothing and throws nothing.
    app.fireTargetChange(BLOCK, { key: BLOCK, selector: 'unprintable' }, null);
    assert.deepEqual(slots(), {});
  } finally {
    globalThis.document = held;
  }
});

test('the hover box yields to the facet AND to the address on the same pair, and returns when the pair moves', () => {
  const { document, slots } = fakeDocument();
  const held = globalThis.document;
  globalThis.document = document;
  try {
    const app = fakeApp();
    createCanvasSelectorPaint({ app, behavior });
    const BLOCK = 'block:a';
    const SECTION = 'container:w';
    app.fireHover({ key: SECTION, selector: 'li' });
    assert.deepEqual(slots(), { hover: `${SECTION}::li dashed` });
    // The hovered pair becomes the address: the dotted box stands, the
    // muted hover box goes.
    app.fireTargetChange(BLOCK, { key: BLOCK, selector: '.s-abc' }, { key: SECTION, selector: 'li' });
    assert.deepEqual(slots(), {
      selected: `${BLOCK}::.s-abc dashed`,
      write: `${SECTION}::li dotted`,
    });
    // Hovering the facet's own pair paints no extra box either.
    app.fireHover({ key: BLOCK, selector: '.s-abc' });
    assert.equal(slots().hover, undefined);
    // A hover elsewhere paints beside both.
    app.fireHover({ key: SECTION, selector: '> *' });
    assert.equal(slots().hover, `${SECTION}::> * dashed`);
    // The address drops while the hover stands on its old pair: the
    // hover box returns.
    app.fireHover({ key: SECTION, selector: 'li' });
    app.fireTargetChange(BLOCK, { key: BLOCK, selector: '.s-abc' }, null);
    assert.equal(slots().hover, `${SECTION}::li dashed`);
    app.fireHover(null);
    assert.equal(slots().hover, undefined);
  } finally {
    globalThis.document = held;
  }
});

// --- runner ---------------------------------------------------------------

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failed += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failed > 0) {
  console.error(`${failed} of ${tests.length} tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} tests passed.`);
