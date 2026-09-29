/**
 * Self-asserting tests for the row mark (widgets/lib/mark.js): the pure
 * markState over a row origin (fill = at the pose, hue = the supplier,
 * shape = a live slide, title = the sentence) and the Mark vnode's
 * classes, hue variable and title, including the slot contract a
 * dimension field relies on (cloneElement with `slide` stamps the
 * square on a mark the row handed in).
 *
 * Run directly: `node tests/js/mark.test.mjs`. Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { cloneElement, h } from 'preact';
import { registerJsx } from './jsx-loader.mjs';

registerJsx();
const { Mark, markState } = await import('../../js/src/widgets/lib/mark.js');

const tests = [];
const test = (name, run) => tests.push([name, run]);

const ELEMENT = 'hsl(213 90% 66%)';
const origin = (overrides = {}) => ({
  layer: 'element',
  tier: 'element',
  reference: null,
  color: ELEMENT,
  atPose: true,
  delivers: true,
  title: '8px set here at Hover',
  header: '8px set here at Hover',
  doorways: [],
  alsoAt: [],
  ...overrides,
});

// The rendered element: a Mark vnode (h(Mark, props)) called with its
// props the way the diff would, a direct call's span as it is.
const rendered = (vnode) =>
  typeof vnode.type === 'function' ? vnode.type(vnode.props) : vnode;
const classesOf = (vnode) => rendered(vnode).props.className.split(' ');

test('markState: set here at the pose is a filled mark in the supplier hue with the sentence', () => {
  assert.deepEqual(markState(origin()), { here: true, slide: false, color: ELEMENT, title: '8px set here at Hover' });
});

test('markState: a value cascading in from another condition is hollow in the hue', () => {
  const state = markState(origin({ atPose: false, title: '8px from Base' }));
  assert.equal(state.here, false);
  assert.equal(state.color, ELEMENT);
  assert.equal(state.title, '8px from Base');
});

test('markState: authored elsewhere with nothing delivering is hollow in the author hue; nothing authored is the resting ring', () => {
  const elsewhere = markState(origin({ atPose: false, delivers: false, color: 'hsl(1 70% 60%)', title: 'Set by Shy at Narrow, not here' }));
  assert.deepEqual([elsewhere.here, elsewhere.color], [false, 'hsl(1 70% 60%)']);
  const nothing = markState(origin({ atPose: false, delivers: false, color: null, title: 'Not set' }));
  assert.deepEqual(nothing, { here: false, slide: false, color: null, title: 'Not set' });
});

test('markState: the shape is the caller\'s live-scrub fact, independent of the origin', () => {
  assert.equal(markState(origin(), true).slide, true);
  assert.equal(markState(origin(), false).slide, false);
  assert.equal(markState(origin({ atPose: false, delivers: false, color: null }), true).slide, true, 'a live slide over an unset row is still a square');
  assert.equal(markState(origin(), 'yes').slide, false, 'only a boolean true slides');
});

test('markState: no origin at all is the resting ring with no sentence', () => {
  assert.deepEqual(markState(null), { here: false, slide: false, color: null, title: null });
  assert.deepEqual(markState(undefined, true), { here: false, slide: true, color: null, title: null });
});

test('Mark renders the shared dot footprint with the fill and shape modifiers', () => {
  const here = classesOf(Mark({ origin: origin() }));
  assert.ok(here.includes('daedalus-stylish-inspector__mark'));
  assert.ok(here.includes('daedalus-ui-dot'));
  assert.ok(here.includes('daedalus-stylish-inspector__mark--here'));
  assert.ok(!here.includes('daedalus-stylish-inspector__mark--slide'));
  const hollowSquare = classesOf(Mark({ origin: origin({ atPose: false }), slide: true }));
  assert.ok(!hollowSquare.includes('daedalus-stylish-inspector__mark--here'));
  assert.ok(hollowSquare.includes('daedalus-stylish-inspector__mark--slide'));
});

test('Mark carries the hue on --daedalus-stylish-origin itself, initial when nothing is authored', () => {
  assert.deepEqual(rendered(Mark({ origin: origin() })).props.style, { '--daedalus-stylish-origin': ELEMENT });
  assert.deepEqual(rendered(Mark({ origin: origin({ color: null }) })).props.style, { '--daedalus-stylish-origin': 'initial' });
  assert.deepEqual(rendered(Mark({})).props.style, { '--daedalus-stylish-origin': 'initial' });
});

test('Mark wears the origin sentence as its title, none without an origin, and is decorative', () => {
  const element = rendered(Mark({ origin: origin({ title: '#d357fe set here at Hover + Dark. Also set at Base, Hover' }) }));
  assert.equal(element.props.title, '#d357fe set here at Hover + Dark. Also set at Base, Hover');
  assert.equal(element.props['aria-hidden'], 'true');
  assert.equal(rendered(Mark({ origin: null })).props.title, undefined);
});

test('the slot contract: a dimension field clones the mark it is handed with its live-scrub fact', () => {
  const handed = h(Mark, { origin: origin() });
  // The row hands in a circle; the field stamps the square while the
  // scrub is live, the circle when it is inert, without touching the
  // origin.
  const live = cloneElement(handed, { slide: true });
  assert.ok(classesOf(live).includes('daedalus-stylish-inspector__mark--slide'));
  assert.ok(classesOf(live).includes('daedalus-stylish-inspector__mark--here'));
  const inert = cloneElement(handed, { slide: false });
  assert.ok(!classesOf(inert).includes('daedalus-stylish-inspector__mark--slide'));
  assert.equal(rendered(inert).props.title, '8px set here at Hover');
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
    console.error(error?.stack ?? error);
  }
}
if (failures) {
  console.error(`${failures} mark test(s) failed.`);
  process.exit(1);
}
console.log(`${tests.length} mark tests passed.`);
