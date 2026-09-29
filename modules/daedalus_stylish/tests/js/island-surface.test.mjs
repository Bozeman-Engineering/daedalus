/**
 * Self-asserting tests for the island surface
 * (js/src/code/island-surface.js): the print (the marks turned into
 * static DOM: the run label before a run's first line, the line classes
 * and hue, the winner chip) over a document stand-in, and the wake:
 * where the caret lands for an offset, an island-resolved placement, and
 * the surface's own fresh-line placement (the drafted island's wake), in
 * both postures.
 *
 * Run directly (`node tests/js/island-surface.test.mjs`) or via the
 * suite glob. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { EditorState } from '@codemirror/state';
import { history, undoDepth } from '@codemirror/commands';
import { FRESH_LINE, createIslandSurface, runLabel } from '../../js/src/code/island-surface.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// --- fixtures -------------------------------------------------------------

// The printer builds DOM through the global document; the stand-in
// records what a browser would render: elements with a class, a
// dataset, an inline style, children in order and a text.
const element = (tag) => {
  const node = {
    tag,
    className: '',
    dataset: {},
    style: {},
    attributes: {},
    title: '',
    children: [],
    listeners: {},
    get textContent() {
      return node.children.map((child) => (typeof child === 'string' ? child : child.textContent)).join('');
    },
    set textContent(text) {
      node.children = [text];
    },
    append: (...nodes) => {
      node.children.push(...nodes);
    },
    setAttribute: (name, value) => {
      node.attributes[name] = value;
    },
    addEventListener: (type, handler) => {
      node.listeners[type] = handler;
    },
  };
  return node;
};
globalThis.document = {
  createElement: element,
  createTextNode: (text) => text,
};
globalThis.Drupal = { t: (text) => text };

// The container the surface owns: the wake swaps the view's dom in; the
// pointerdown listener is registered and never fired here.
const containerFor = () => {
  const container = { children: [], listeners: {} };
  container.replaceChildren = (...nodes) => {
    container.children = nodes;
  };
  container.addEventListener = (type, handler) => {
    container.listeners[type] = handler;
  };
  return container;
};

// A view over a state: focus counted, dispatch applied through the
// state (history included), the dom a marker the container receives.
const viewFor = (initial) => {
  const view = {
    state: initial,
    dom: { view: true },
    focused: 0,
    focus() {
      view.focused += 1;
    },
    dispatch(spec) {
      view.state = view.state.update(spec).state;
    },
  };
  return view;
};

// A surface over a printed text; the island's resolver answers `end`
// and `firstLine` the way element-view's placementAnchor does.
const surfaceFor = (text, { onView = null } = {}) => {
  const container = containerFor();
  const views = [];
  const surface = createIslandSurface(container, {
    buildState: () => EditorState.create({ doc: text, extensions: [history()] }),
    buildView: (state) => {
      const view = viewFor(state);
      views.push(view);
      onView?.(view);
      return view;
    },
    resolveAnchor: (state, placement) => {
      switch (placement.edge) {
        case 'end':
          return state.doc.length;
        case 'firstLine':
          return Math.min(state.doc.line(1).from + (placement.column ?? 0), state.doc.line(1).to);
        default:
          return 0;
      }
    },
  });
  return { surface, container, views };
};

const caret = (view) => {
  const head = view.state.selection.main.head;
  const line = view.state.doc.lineAt(head);
  return { head, line: line.number, column: head - line.from, lineText: line.text };
};

const FLOOR = 'display: block;\npadding: 1rem;';

// --- the print: marks as static DOM -----------------------------------------

const printOf = (container) => container.children[0];
const classesOf = (node) => node.className.split(' ').filter(Boolean);

test('the print places a run label before the run\'s first line, carrying the layer id, the hue dot and the rail hue', () => {
  const { surface, container } = surfaceFor(FLOOR);
  surface.print(['display: block;', 'padding: 1rem;'], [
    { classes: ['daedalus-stylish-island__line--delivered'], attributes: { style: '--daedalus-stylish-line-hue: teal', title: 'Supplied by the active skin (kind:block).' }, winner: null, pending: null, run: { label: 'skin, block kind', layer: 'skin:kind:block', color: 'teal', first: true } },
    { classes: ['daedalus-stylish-island__line--delivered'], attributes: { style: '--daedalus-stylish-line-hue: teal', title: 'Supplied by the active skin (kind:block).' }, winner: null, pending: null, run: null },
  ]);
  const print = printOf(container);
  assert.equal(print.className, 'daedalus-stylish-island__print');
  const [run, first, second] = print.children;
  assert.equal(run.className, 'daedalus-stylish-island__run');
  assert.equal(run.dataset.layer, 'skin:kind:block');
  // The label carries its run's hue as the lines do: the rail runs
  // through it.
  assert.equal(run.attributes.style, '--daedalus-stylish-line-hue: teal');
  assert.deepEqual(classesOf(run.children[0]), ['daedalus-stylish-island__run-dot', 'daedalus-ui-dot']);
  assert.equal(run.children[0].style.background, 'teal');
  assert.equal(run.textContent, 'skin, block kind');
  // The lines keep their index, classes, hue and title; only one label.
  assert.deepEqual([first.dataset.line, second.dataset.line], ['0', '1']);
  assert.deepEqual(classesOf(first), ['daedalus-stylish-island__line', 'daedalus-stylish-island__line--delivered']);
  assert.equal(first.attributes.style, '--daedalus-stylish-line-hue: teal');
  assert.equal(first.title, 'Supplied by the active skin (kind:block).');
  assert.equal(second.textContent, 'padding: 1rem;');
  assert.equal(print.children.length, 3);
});

test('a run per layer: two labels for two runs, each before its own first line; an own line with no title', () => {
  const { surface, container } = surfaceFor(FLOOR);
  surface.print(['color: red;', 'padding: 3rem;', 'margin: 0;'], [
    { classes: ['daedalus-stylish-island__line--delivered'], attributes: { style: '--daedalus-stylish-line-hue: pink', title: 'Supplied by the variant "Card left".' }, winner: null, pending: null, run: { label: 'Card left', layer: 'variant:card_left', color: 'pink', first: true } },
    { classes: [], attributes: { style: '--daedalus-stylish-line-hue: blue' }, winner: null, pending: null, run: { label: 'this element', layer: 'element', color: 'blue', first: true } },
    { classes: [], attributes: { style: '--daedalus-stylish-line-hue: blue' }, winner: null, pending: null, run: null },
  ]);
  const children = printOf(container).children;
  assert.deepEqual(children.map((node) => node.className.split(' ')[0]), [
    'daedalus-stylish-island__run', 'daedalus-stylish-island__line',
    'daedalus-stylish-island__run', 'daedalus-stylish-island__line', 'daedalus-stylish-island__line',
  ]);
  assert.equal(children[2].textContent, 'this element');
  assert.equal(children[3].title, '');
  assert.equal(children[3].attributes.style, '--daedalus-stylish-line-hue: blue');
});

test('a lost line carries the winner chip after its text; a null mark prints plain', () => {
  const { surface, container } = surfaceFor(FLOOR);
  surface.print(['padding: 3rem;', 'gap: 1rem;'], [
    { classes: ['daedalus-stylish-island__line--lost'], attributes: null, winner: { label: 'Card left', color: 'pink' }, pending: null, run: null },
    null,
  ]);
  const [lost, plain] = printOf(container).children;
  assert.deepEqual(classesOf(lost), ['daedalus-stylish-island__line', 'daedalus-stylish-island__line--lost']);
  assert.equal(lost.children[0], 'padding: 3rem;');
  const chip = lost.children[1];
  assert.deepEqual(classesOf(chip), ['daedalus-stylish-island__winner', 'daedalus-ui-chip', 'daedalus-ui-chip--muted']);
  assert.equal(chip.children[0].style.background, 'pink');
  assert.equal(chip.textContent, 'Card left');
  assert.equal(plain.className, 'daedalus-stylish-island__line');
  assert.equal(plain.textContent, 'gap: 1rem;');
});

test('an empty island prints the placeholder line and no run', () => {
  const { surface, container } = surfaceFor('');
  surface.print([''], []);
  const children = printOf(container).children;
  assert.equal(children.length, 1);
  assert.deepEqual(classesOf(children[0]), ['daedalus-stylish-island__line', 'daedalus-stylish-island__line--placeholder']);
});

test('the run label builder is the one DOM the widget and the printer share', () => {
  const run = runLabel('Hero band', 'orange', 'variant:hero_band');
  assert.equal(run.className, 'daedalus-stylish-island__run');
  assert.equal(run.dataset.layer, 'variant:hero_band');
  assert.equal(run.children[0].style.background, 'orange');
  assert.equal(run.textContent, 'Hero band');
});

// --- the offset and the island's placements ---------------------------------

test('a wake at an offset builds the view with the caret there, focused, its dom in the container', () => {
  const { surface, container, views } = surfaceFor(FLOOR);
  assert.equal(surface.live, null);
  const view = surface.wake({ anchor: 3 });
  assert.equal(views.length, 1);
  assert.equal(surface.live, view);
  assert.deepEqual(container.children, [view.dom]);
  assert.equal(view.focused, 1);
  assert.deepEqual(caret(view), { head: 3, line: 1, column: 3, lineText: 'display: block;' });
  assert.equal(view.state.doc.toString(), FLOOR);
});

test('an offset past the end clamps to the document length; an empty wake is offset 0', () => {
  assert.equal(caret(surfaceFor(FLOOR).surface.wake({ anchor: 999 })).head, FLOOR.length);
  assert.equal(caret(surfaceFor(FLOOR).surface.wake()).head, 0);
});

test('a placement resolves through the island\'s resolver against the fresh state', () => {
  const end = surfaceFor(FLOOR).surface.wake({ placement: { edge: 'end' } });
  assert.equal(caret(end).head, FLOOR.length);
  const first = surfaceFor(FLOOR).surface.wake({ placement: { edge: 'firstLine', column: 4 } });
  assert.deepEqual(caret(first), { head: 4, line: 1, column: 4, lineText: 'display: block;' });
});

test('a live view re-wakes in place: focused again, the caret moved, no second view built', () => {
  const { surface, views } = surfaceFor(FLOOR);
  const view = surface.wake({ anchor: 0 });
  assert.equal(surface.wake({ placement: { edge: 'end' } }), view);
  assert.equal(views.length, 1);
  assert.equal(view.focused, 2);
  assert.equal(caret(view).head, FLOOR.length);
});

// --- the fresh line (the drafted island's wake) -----------------------------

test('the fresh line: a drafted island printing floor lines wakes with the caret on an empty last line after them', () => {
  const view = surfaceFor(FLOOR).surface.wake({ placement: { edge: FRESH_LINE } });
  assert.equal(view.state.doc.toString(), `${FLOOR}\n`);
  assert.equal(view.state.doc.lines, 3);
  assert.deepEqual(caret(view), { head: FLOOR.length + 1, line: 3, column: 0, lineText: '' });
  // The floor text is untouched: typing lands on the new line, never
  // joined to the first floor line.
  assert.equal(view.state.doc.line(1).text, 'display: block;');
  assert.equal(view.state.doc.line(2).text, 'padding: 1rem;');
});

test('the fresh line on an empty island inserts nothing: the caret is the empty document', () => {
  const view = surfaceFor('').surface.wake({ placement: { edge: FRESH_LINE } });
  assert.equal(view.state.doc.toString(), '');
  assert.deepEqual(caret(view), { head: 0, line: 1, column: 0, lineText: '' });
});

test('the fresh line reuses an empty last line rather than adding a second', () => {
  const view = surfaceFor(`${FLOOR}\n`).surface.wake({ placement: { edge: FRESH_LINE } });
  assert.equal(view.state.doc.toString(), `${FLOOR}\n`);
  assert.deepEqual(caret(view), { head: FLOOR.length + 1, line: 3, column: 0, lineText: '' });
});

test('the wake\'s newline is not the user\'s: undo has nothing to take back', () => {
  const view = surfaceFor(FLOOR).surface.wake({ placement: { edge: FRESH_LINE } });
  assert.equal(undoDepth(view.state), 0);
  // A live re-wake appends through dispatch and stays out of history too.
  const { surface } = surfaceFor(FLOOR);
  const live = surface.wake({ anchor: 0 });
  surface.wake({ placement: { edge: FRESH_LINE } });
  assert.equal(live.state.doc.toString(), `${FLOOR}\n`);
  assert.deepEqual(caret(live), { head: FLOOR.length + 1, line: 3, column: 0, lineText: '' });
  assert.equal(undoDepth(live.state), 0);
});

test('the fresh line never reaches the island\'s resolver', () => {
  const container = containerFor();
  let resolved = 0;
  const surface = createIslandSurface(container, {
    buildState: () => EditorState.create({ doc: FLOOR }),
    buildView: (state) => viewFor(state),
    resolveAnchor: () => {
      resolved += 1;
      return 0;
    },
  });
  surface.wake({ placement: { edge: FRESH_LINE } });
  assert.equal(resolved, 0);
  surface.wake({ placement: { edge: 'end' } });
  assert.equal(resolved, 1);
});

// --- runner -----------------------------------------------------------------

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    process.stdout.write(`ok - ${name}\n`);
  } catch (error) {
    failed += 1;
    process.stdout.write(`not ok - ${name}\n${error.stack ?? error}\n`);
  }
}
if (failed > 0) {
  process.stderr.write(`${failed} island-surface test(s) failed.\n`);
  process.exit(1);
}
