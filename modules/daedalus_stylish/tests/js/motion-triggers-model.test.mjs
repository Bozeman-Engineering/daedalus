/**
 * Self-asserting tests for the motion-triggers runtime's pure model
 * (motion-triggers-model.js): the two trigger grammars as the Animation
 * Triggers draft spells them, the activation range to IntersectionObserver
 * translation with its clamp, the action map over the Web Animations API,
 * and the binding rule (both markers naming one trigger).
 *
 * Run directly (`node tests/js/motion-triggers-model.test.mjs`) or with the
 * rest of the suite (`node --test tests/js/*.test.mjs`). Exits non-zero
 * on failure.
 */
import { strict as assert } from 'node:assert';
import {
  actionFor,
  bindingFor,
  observerOptions,
  parseAnimationTrigger,
  parseTimelineTrigger,
} from '../../js/src/motion-triggers-model.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

// A recording Animation double: the steps only touch these four members.
const animation = (state = {}) => {
  const calls = [];
  return {
    calls,
    currentTime: state.currentTime ?? 500,
    playbackRate: state.playbackRate ?? 1,
    playState: state.playState ?? 'running',
    play() { calls.push('play'); },
    pause() { calls.push('pause'); },
  };
};

test('parseTimelineTrigger: the curated stops parse start and end; an end name alone is its 100%', () => {
  const EXIT = { edge: 'exit', percent: 100 };
  assert.deepEqual(parseTimelineTrigger('--in-view view() entry 20% exit 100%'), { name: '--in-view', source: 'view()', range: { edge: 'entry', percent: 20, end: EXIT } });
  assert.deepEqual(parseTimelineTrigger('--in-view view() entry 50% exit 100%'), { name: '--in-view', source: 'view()', range: { edge: 'entry', percent: 50, end: EXIT } });
  assert.deepEqual(parseTimelineTrigger('--in-view view() contain 0% exit 100%'), { name: '--in-view', source: 'view()', range: { edge: 'contain', percent: 0, end: EXIT } });
  // Chrome serializes `exit 100%` as `exit`: the end name alone is its 100%.
  assert.deepEqual(parseTimelineTrigger('--in-view view() entry 20% exit').range, { edge: 'entry', percent: 20, end: EXIT });
  assert.deepEqual(parseTimelineTrigger('--t view() entry 20% cover 100%').range.end, { edge: 'cover', percent: 100 });
  assert.deepEqual(parseTimelineTrigger('--t view() entry 20% exit 80%').range.end, { edge: 'exit', percent: 80 });
  // Whitespace and case of the value as computed style hands it back.
  assert.deepEqual(parseTimelineTrigger('  --in-view   view()  cover 12.5%  exit  100% '), { name: '--in-view', source: 'view()', range: { edge: 'cover', percent: 12.5, end: EXIT } });
});

test('parseTimelineTrigger: a lone start takes its own name\'s 100% as the end (the bare start is NOT the curated stop)', () => {
  assert.deepEqual(parseTimelineTrigger('--in-view view() entry 20%').range, { edge: 'entry', percent: 20, end: { edge: 'entry', percent: 100 } });
  assert.deepEqual(parseTimelineTrigger('--in-view view() contain 0%').range, { edge: 'contain', percent: 0, end: { edge: 'contain', percent: 100 } });
  // A range name alone reads as its 0% at the start.
  assert.deepEqual(parseTimelineTrigger('--t view() entry').range, { edge: 'entry', percent: 0, end: { edge: 'entry', percent: 100 } });
  assert.deepEqual(parseTimelineTrigger('--t view() entry exit').range, { edge: 'entry', percent: 0, end: { edge: 'exit', percent: 100 } });
  // A bare percentage is a point on the whole timeline (cover); no range
  // at all (`normal`) is the whole timeline.
  assert.deepEqual(parseTimelineTrigger('--t view() entry 20% 80%').range.end, { edge: 'cover', percent: 80 });
  assert.deepEqual(parseTimelineTrigger('--t view() 10% 80%').range, { edge: 'cover', percent: 10, end: { edge: 'cover', percent: 80 } });
  assert.deepEqual(parseTimelineTrigger('--t view() 10%').range, { edge: 'cover', percent: 10, end: { edge: 'cover', percent: 100 } });
  assert.deepEqual(parseTimelineTrigger('--t view()').range, { edge: 'cover', percent: 0, end: { edge: 'cover', percent: 100 } });
});

test('parseTimelineTrigger: an active range is ignored; only the first list item counts; the source keeps its spelling', () => {
  assert.deepEqual(parseTimelineTrigger('--t view() entry 20% exit 100% / entry 0% exit 100%').range, { edge: 'entry', percent: 20, end: { edge: 'exit', percent: 100 } });
  assert.deepEqual(parseTimelineTrigger('--t view() entry 20% / contain').range, { edge: 'entry', percent: 20, end: { edge: 'entry', percent: 100 } });
  assert.equal(parseTimelineTrigger('--a view() entry 10% exit 100%, --b view() exit 0%').name, '--a');
  // A view() with arguments keeps its source spelling.
  assert.equal(parseTimelineTrigger('--t view(block) entry 20% exit 100%').source, 'view(block)');
});

test('parseTimelineTrigger: nothing to polyfill reads null', () => {
  assert.equal(parseTimelineTrigger('none'), null);
  assert.equal(parseTimelineTrigger(''), null);
  assert.equal(parseTimelineTrigger(undefined), null);
  assert.equal(parseTimelineTrigger('--t'), null);
  assert.equal(parseTimelineTrigger('in-view view() entry 20%'), null);
  assert.equal(parseTimelineTrigger('--t scroll() entry 20%'), null);
  assert.equal(parseTimelineTrigger('--t view() halfway 20%'), null);
  assert.equal(parseTimelineTrigger('--t view() entry 20'), null);
  assert.equal(parseTimelineTrigger('--t view() entry twenty%'), null);
  // A malformed or trailing end is malformed too.
  assert.equal(parseTimelineTrigger('--t view() entry 20% exit 100'), null);
  assert.equal(parseTimelineTrigger('--t view() entry 20% halfway 100%'), null);
  assert.equal(parseTimelineTrigger('--t view() entry 20% exit 100% entry'), null);
});

test('parseAnimationTrigger: one action binds the activation only, two bind both; the stop values', () => {
  assert.deepEqual(parseAnimationTrigger('--in-view play-once'), { name: '--in-view', enter: 'play-once', exit: 'none' });
  assert.deepEqual(parseAnimationTrigger('--in-view replay reset'), { name: '--in-view', enter: 'replay', exit: 'reset' });
  assert.deepEqual(parseAnimationTrigger('--in-view play-forwards play-backwards'), { name: '--in-view', enter: 'play-forwards', exit: 'play-backwards' });
  assert.deepEqual(parseAnimationTrigger(' --t  pause '), { name: '--t', enter: 'pause', exit: 'none' });
  // A third action and further list items are ignored.
  assert.deepEqual(parseAnimationTrigger('--t play pause reset'), { name: '--t', enter: 'play', exit: 'pause' });
  assert.equal(parseAnimationTrigger('--a play, --b pause').name, '--a');
});

test('parseAnimationTrigger: none, empty and malformed read null', () => {
  assert.equal(parseAnimationTrigger('none'), null);
  assert.equal(parseAnimationTrigger(''), null);
  assert.equal(parseAnimationTrigger(null), null);
  assert.equal(parseAnimationTrigger('--t'), null);
  assert.equal(parseAnimationTrigger('play-once'), null);
  assert.equal(parseAnimationTrigger('--t start'), null);
  assert.equal(parseAnimationTrigger('--t play stop'), null);
});

test('observerOptions: entry shrinks the root by the fraction of the element height, threshold 0; the end is not read (every end is honoured as exit 100%)', () => {
  // A 400px band in a 900px viewport: entry 20% is 80px in.
  assert.deepEqual(observerOptions({ edge: 'entry', percent: 20 }, 400, 900), { threshold: 0, rootMargin: '0px 0px -80px 0px' });
  // The curated stop and the bare start's native range (`entry 20% entry
  // 100%`) shape the same observer: the documented polyfill approximation.
  const curated = observerOptions(parseTimelineTrigger('--in-view view() entry 20% exit 100%').range, 400, 900);
  assert.deepEqual(curated, { threshold: 0, rootMargin: '0px 0px -80px 0px' });
  assert.deepEqual(observerOptions(parseTimelineTrigger('--in-view view() entry 20%').range, 400, 900), curated);
  assert.deepEqual(observerOptions(parseTimelineTrigger('--in-view view() entry 20% exit 40%').range, 400, 900), curated);
  assert.deepEqual(observerOptions({ edge: 'entry', percent: 50 }, 400, 900), { threshold: 0, rootMargin: '0px 0px -200px 0px' });
  assert.deepEqual(observerOptions({ edge: 'entry', percent: 0 }, 400, 900), { threshold: 0, rootMargin: '0px' });
  assert.deepEqual(observerOptions({ edge: 'entry-crossing', percent: 20 }, 400, 900).rootMargin, '0px 0px -80px 0px');
  // Fractions of a pixel round.
  assert.equal(observerOptions({ edge: 'entry', percent: 33 }, 100, 900).rootMargin, '0px 0px -33px 0px');
});

test('observerOptions: contain is fully entered plus the fraction of the free space; cover spans both', () => {
  // contain 0% = the end edge at the scrollport's end edge (400px in).
  assert.equal(observerOptions({ edge: 'contain', percent: 0 }, 400, 900).rootMargin, '0px 0px -400px 0px');
  // contain 100% = the start edge at the scrollport's start edge.
  assert.equal(observerOptions({ edge: 'contain', percent: 100 }, 400, 900).rootMargin, '0px 0px -899px 0px');
  assert.equal(observerOptions({ edge: 'contain', percent: 50 }, 400, 900).rootMargin, '0px 0px -650px 0px');
  // cover 50% on a 400px band is 650px in (half of the 1300px sweep).
  assert.equal(observerOptions({ edge: 'cover', percent: 50 }, 400, 900).rootMargin, '0px 0px -650px 0px');
  assert.equal(observerOptions({ edge: 'cover', percent: 0 }, 400, 900).rootMargin, '0px');
});

test('observerOptions: the clamp, a point above the scrollport fires at the top edge; a tall element never shrinks the root away', () => {
  // exit points sit above the scrollport's start edge: clamped to 1px.
  assert.equal(observerOptions({ edge: 'exit', percent: 0 }, 400, 900).rootMargin, '0px 0px -899px 0px');
  assert.equal(observerOptions({ edge: 'exit', percent: 50 }, 400, 900).rootMargin, '0px 0px -899px 0px');
  assert.equal(observerOptions({ edge: 'exit-crossing', percent: 100 }, 400, 900).rootMargin, '0px 0px -899px 0px');
  // A 2000px element in a 900px viewport: entry 100% would need the root
  // shrunk by 2000px; contain 0% likewise sits above the top.
  assert.equal(observerOptions({ edge: 'entry', percent: 100 }, 2000, 900).rootMargin, '0px 0px -899px 0px');
  assert.equal(observerOptions({ edge: 'entry', percent: 20 }, 2000, 900).rootMargin, '0px 0px -400px 0px');
  assert.equal(observerOptions({ edge: 'contain', percent: 0 }, 2000, 900).rootMargin, '0px 0px -899px 0px');
  assert.equal(observerOptions({ edge: 'cover', percent: 100 }, 400, 900).rootMargin, '0px 0px -899px 0px');
  // Degenerate geometry (an inline element, a zero viewport) still yields options.
  assert.deepEqual(observerOptions({ edge: 'entry', percent: 20 }, 0, 900), { threshold: 0, rootMargin: '0px' });
  assert.deepEqual(observerOptions({ edge: 'entry', percent: 20 }, 400, 0), { threshold: 0, rootMargin: '0px' });
});

test('actionFor: the Web Animations step per action', () => {
  // Forward play on a CSS-governed animation (the stamp lifted the hold,
  // rate 1, not API-paused) touches nothing: the first API call would take
  // the animation away from CSS and Chromium 136 then never cancels it on
  // animation-name removal.
  let a = animation();
  actionFor('play')(a);
  assert.deepEqual([a.calls, a.playbackRate, a.currentTime], [[], 1, 500]);
  a = animation({ playState: 'finished' });
  actionFor('play-once')(a);
  assert.deepEqual([a.calls, a.playbackRate], [[], 1]);
  // An API-paused animation (reset left it so) and a reversed rate need
  // the API.
  a = animation({ playState: 'paused', currentTime: 0 });
  actionFor('play')(a);
  assert.deepEqual([a.calls, a.playbackRate, a.currentTime], [['play'], 1, 0]);
  a = animation({ playState: 'paused' });
  actionFor('play-once')(a);
  assert.deepEqual([a.calls, a.playbackRate], [['play'], 1]);
  a = animation({ playbackRate: -1 });
  actionFor('play-forwards')(a);
  assert.deepEqual([a.calls, a.playbackRate], [['play'], 1]);
  a = animation();
  actionFor('play-backwards')(a);
  assert.deepEqual([a.calls, a.playbackRate], [['play'], -1]);
  a = animation({ playbackRate: -1 });
  actionFor('replay')(a);
  assert.deepEqual([a.calls, a.playbackRate, a.currentTime], [['play'], 1, 0]);
  a = animation({ playbackRate: -1 });
  actionFor('reset')(a);
  assert.deepEqual([a.calls, a.playbackRate, a.currentTime], [['pause'], 1, 0]);
  a = animation();
  actionFor('pause')(a);
  assert.deepEqual([a.calls, a.currentTime], [['pause'], 500]);
  assert.equal(actionFor('none'), null);
  assert.equal(actionFor('bogus'), null);
});

test('bindingFor: both markers must parse and name one trigger', () => {
  const bound = bindingFor('--in-view view() entry 20% exit 100%', '--in-view play-once');
  assert.deepEqual(bound.timeline.range, { edge: 'entry', percent: 20, end: { edge: 'exit', percent: 100 } });
  assert.deepEqual(bound.actions, { name: '--in-view', enter: 'play-once', exit: 'none' });
  assert.equal(bindingFor('--in-view view() entry 20% exit 100%', '--other play-once'), null);
  assert.equal(bindingFor('none', '--in-view play-once'), null);
  assert.equal(bindingFor('--in-view view() entry 20% exit 100%', 'none'), null);
  assert.equal(bindingFor('', ''), null);
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
  console.error(`${failures} of ${tests.length} motion-triggers-model tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} motion-triggers-model tests passed.`);
