/**
 * Self-asserting tests for the "Editing at" model (editing-at.js): the
 * vector -> compound derivation the inspector writes at, and the reverse
 * "drive the canvas there" pose.
 *
 * Run directly (`node tests/js/editing-at.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  DEFAULT_VECTOR,
  conditionAxisOrder,
  effectiveWidth,
  stateConditionNames,
  widthConditionFor,
  vectorCondition,
  conditionPose,
  poseableAxisConditions,
  styledConditions,
} from '../../js/src/editing-at.js';

// The shipped condition table's shape and order ((weight, id)-ordered, so
// axes first-appear as state, width, scheme, motion), 'mobile' standing in
// for the transitional @media width vocabulary the canvas cannot pose (no
// band fact). The structured facts (band, preference) are what the model
// reads; the wrapper strings ride along for the printer only.
const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width', band: { max: 767 } },
  medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width', band: { min: 768, max: 1023 } },
  mobile: { label: 'Mobile', wrapper: '@media (max-width: 767px)', axis: 'width' },
  dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme', preference: { feature: 'prefers-color-scheme', value: 'dark' } },
  'reduced-motion': { label: 'Reduced motion', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion', preference: { feature: 'prefers-reduced-motion', value: 'reduce' } },
};

test('axis order derives from first appearance, never hardcoded', () => {
  assert.deepEqual(conditionAxisOrder(CONDITIONS), ['state', 'width', 'scheme', 'motion']);
  // A reordered table reorders the axes — the payload is the authority.
  assert.deepEqual(
    conditionAxisOrder({ dark: CONDITIONS.dark, hover: CONDITIONS.hover }),
    ['scheme', 'state'],
  );
  assert.deepEqual(conditionAxisOrder({}), []);
});

test('state vocabulary is the state-axis condition names', () => {
  assert.deepEqual(stateConditionNames(CONDITIONS), ['hover', 'focus']);
  assert.deepEqual(stateConditionNames({}), []);
});

test('effective width: posed wins, measured answers when unposed', () => {
  // A posed width beats the measurement (the pose IS the stage's width).
  assert.equal(effectiveWidth({ width: 375, measured: 1440 }), 375);
  // Unposed: the measured stage width riding the snapshot answers.
  assert.equal(effectiveWidth({ width: null, measured: 900 }), 900);
  // Neither (no facade, a satellite before its first snapshot): null.
  assert.equal(effectiveWidth({ width: null }), null);
  assert.equal(effectiveWidth(null), null);
});

test('preference parts read the structured fact, never the wrapper text', () => {
  // A light-default site's twin vocabulary and a wrapper spelling the old
  // regex would have misread: the fact decides.
  const conditions = {
    light: { label: 'Light mode', wrapper: '@media screen and (prefers-color-scheme: light)', axis: 'scheme', preference: { feature: 'prefers-color-scheme', value: 'light' } },
    dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme:dark)', axis: 'scheme', preference: { feature: 'prefers-color-scheme', value: 'dark' } },
    calm: { label: 'Calm', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion', preference: { feature: 'prefers-reduced-motion', value: 'reduce' } },
    // A wrapper alone, no fact: unposable, and no forced value derives it.
    dim: { label: 'Dim', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  };
  assert.equal(vectorCondition({ scheme: 'light' }, null, conditions), 'light');
  assert.equal(vectorCondition({ scheme: 'dark' }, null, conditions), 'dark');
  assert.equal(vectorCondition({ motion: 'reduce' }, null, conditions), 'calm');
  assert.deepEqual(conditionPose('light', conditions).vector.scheme, 'light');
  assert.deepEqual(conditionPose('calm', conditions).vector.motion, 'reduce');
  assert.equal(conditionPose('dim', conditions).complete, false);
  assert.deepEqual(poseableAxisConditions('scheme', conditions), ['light', 'dark']);
  // Without any facts the vector's preference axes contribute nothing.
  assert.equal(vectorCondition({ scheme: 'dark' }, null, { dim: conditions.dim }), 'default');
});

test('width maps through the bands, base above them all', () => {
  // An unmeasured canvas poses no width condition.
  assert.equal(widthConditionFor(null, CONDITIONS), null);
  // Inside the narrow band (inclusive ceiling).
  assert.equal(widthConditionFor(375, CONDITIONS), 'narrow');
  assert.equal(widthConditionFor(767, CONDITIONS), 'narrow');
  // The medium band is a true range: both edges inclusive.
  assert.equal(widthConditionFor(768, CONDITIONS), 'medium');
  assert.equal(widthConditionFor(800, CONDITIONS), 'medium');
  assert.equal(widthConditionFor(1023, CONDITIONS), 'medium');
  // Above every band's ceiling: base. No width condition exists there.
  assert.equal(widthConditionFor(1024, CONDITIONS), null);
  assert.equal(widthConditionFor(2000, CONDITIONS), null);
});

test('the resting pose edits at default', () => {
  assert.equal(vectorCondition(DEFAULT_VECTOR, null, CONDITIONS), 'default');
  // No vector at all (a page without the facade) degrades the same way.
  assert.equal(vectorCondition(null, null, CONDITIONS), 'default');
});

test('unposed, the measured width drives the write target', () => {
  // The unposed canvas in the base band writes base.
  assert.equal(vectorCondition({ width: null, measured: 1440 }, null, CONDITIONS), 'default');
  // A window narrower than the base band's floor honestly edits the band
  // it renders — write target = rendered band, no see/write divergence.
  assert.equal(vectorCondition({ width: null, measured: 900 }, null, CONDITIONS), 'medium');
  assert.equal(vectorCondition({ width: null, measured: 600 }, null, CONDITIONS), 'narrow');
  // A pose always beats the measurement.
  assert.equal(vectorCondition({ width: 375, measured: 1440 }, null, CONDITIONS), 'narrow');
});

test('single-axis poses derive single conditions', () => {
  assert.equal(vectorCondition({ width: 375 }, null, CONDITIONS), 'narrow');
  assert.equal(vectorCondition({ width: 900 }, null, CONDITIONS), 'medium');
  // A width in the base band writes at default, like the unposed canvas.
  assert.equal(vectorCondition({ width: 1440 }, null, CONDITIONS), 'default');
  assert.equal(vectorCondition({ scheme: 'dark' }, null, CONDITIONS), 'dark');
  assert.equal(vectorCondition({ motion: 'reduce' }, null, CONDITIONS), 'reduced-motion');
  assert.equal(vectorCondition(null, 'hover', CONDITIONS), 'hover');
  // Forced values with no matching condition contribute nothing: 'light'
  // and 'full' force real preferences, but no condition names them.
  assert.equal(vectorCondition({ scheme: 'light' }, null, CONDITIONS), 'default');
  assert.equal(vectorCondition({ motion: 'full' }, null, CONDITIONS), 'default');
});

test('the full pose derives the canonical compound, state first', () => {
  assert.equal(
    vectorCondition({ width: 375, scheme: 'dark', motion: 'reduce' }, 'hover', CONDITIONS),
    'hover+narrow+dark+reduced-motion',
  );
});

test('a force state that is not a state condition is ignored', () => {
  assert.equal(vectorCondition(null, 'narrow', CONDITIONS), 'default');
  assert.equal(vectorCondition(null, 'bogus', CONDITIONS), 'default');
});

test('driving to default resets every axis', () => {
  assert.deepEqual(conditionPose('default', CONDITIONS), {
    vector: { width: null, scheme: 'auto', motion: 'auto' },
    state: null,
    complete: true,
  });
});

test('driving to a compound poses its parts, defaults the rest', () => {
  // A width part poses its band's midpoint (narrow: 0..767 -> 384).
  assert.deepEqual(conditionPose('narrow+dark', CONDITIONS), {
    vector: { width: 384, scheme: 'dark', motion: 'auto' },
    state: null,
    complete: true,
  });
  assert.deepEqual(conditionPose('hover', CONDITIONS), {
    vector: { width: null, scheme: 'auto', motion: 'auto' },
    state: 'hover',
    complete: true,
  });
  // Medium's midpoint: 768..1023 -> 896.
  assert.deepEqual(conditionPose('medium', CONDITIONS).vector.width, 896);
  assert.deepEqual(conditionPose('reduced-motion', CONDITIONS).vector.motion, 'reduce');
});

test('viewport width conditions cannot be posed: honest incompleteness', () => {
  const pose = conditionPose('mobile', CONDITIONS);
  assert.equal(pose.complete, false);
  assert.equal(pose.vector.width, null);
  // The mappable parts of a mixed compound still pose.
  const mixed = conditionPose('hover+mobile', CONDITIONS);
  assert.equal(mixed.state, 'hover');
  assert.equal(mixed.complete, false);
  // Unknown parts degrade the same way.
  assert.equal(conditionPose('bogus', CONDITIONS).complete, false);
});

test('drive then derive round-trips every poseable condition', () => {
  for (const condition of ['default', 'narrow', 'medium', 'dark', 'reduced-motion', 'hover', 'hover+narrow+dark+reduced-motion']) {
    const pose = conditionPose(condition, CONDITIONS);
    assert.equal(pose.complete, true, condition);
    assert.equal(vectorCondition(pose.vector, pose.state, CONDITIONS), condition);
  }
});

test('axis selects offer only what the canvas can pose', () => {
  // 'mobile' is a width condition, but viewport-owned: excluded.
  assert.deepEqual(poseableAxisConditions('width', CONDITIONS), ['narrow', 'medium']);
  assert.deepEqual(poseableAxisConditions('scheme', CONDITIONS), ['dark']);
  assert.deepEqual(poseableAxisConditions('motion', CONDITIONS), ['reduced-motion']);
  assert.deepEqual(poseableAxisConditions('state', CONDITIONS), ['hover', 'focus']);
  assert.deepEqual(poseableAxisConditions('bogus', CONDITIONS), []);
  assert.deepEqual(poseableAxisConditions('width', null), []);
});

test('styled conditions list what carries styles, nothing else', () => {
  assert.deepEqual(
    styledConditions({
      variants: [{ variant: 'hero', fragment: '' }],
      default: { padding: '1rem' },
      hover: { color: 'red' },
      'narrow+dark': { color: 'white' },
      narrow: {},
    }),
    ['hover', 'narrow+dark'],
  );
  assert.deepEqual(styledConditions(null), []);
  assert.deepEqual(styledConditions({}), []);
});

