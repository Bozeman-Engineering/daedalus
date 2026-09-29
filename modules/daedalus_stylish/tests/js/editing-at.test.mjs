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
  isFactCondition,
  factConditionNames,
  satisfiedFacts,
  conditionRenders,
} from '../../js/src/editing-at.js';
import { buildStack, deliveredEntry } from '../../js/src/style-stack.js';
import { variantRecord } from './materials-fixture.mjs';

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

test('the Styled-at chips read the map\'s stack: worn conditions beside the own, never the floor\'s', () => {
  const uuid = '11111111-1111-1111-1111-111111111111';
  const key = `block:${uuid}`;
  const guide = {
    conditions: CONDITIONS,
    declaredDefaults: { 'kind:block': { '': { default: { margin: '0' }, medium: { margin: '1rem' } } } },
    skinDefaults: { 'kind:block': { '': { default: { padding: '0.75rem' }, narrow: { padding: '0.25rem' } } } },
  };
  const variants = [
    variantRecord('loud', {
      label: 'Loud',
      scope: 'block_type:testimonial',
      value: { '': { default: { 'font-weight': 'bold' }, dark: { color: '#eee' } } },
    }),
  ];
  const map = {
    [key]: {
      variants: [{ variant: 'loud', fragment: '' }],
      default: { padding: '3rem' },
      hover: { color: 'red' },
    },
  };
  const args = { guide, map, key, variants, axes: ['style'], blockTypes: { [uuid]: 'testimonial' } };
  // The floorless stack is the chips' read: the own entry's hover, the
  // worn variant's dark, and neither tier's condition.
  assert.deepEqual(styledConditions(deliveredEntry(buildStack({ ...args, floor: false }))), ['dark', 'hover']);
  // The full stack would name the skin's narrow and the declaration's
  // medium, which is why the chips never read it.
  assert.deepEqual(styledConditions(deliveredEntry(buildStack(args))), ['medium', 'narrow', 'dark', 'hover']);
});


// The shipped table with the structural axis between state and width:
// facts flagged by the payload, never posable.
const WITH_FACTS = {
  hover: CONDITIONS.hover,
  focus: CONDITIONS.focus,
  'first-child': { label: 'First child', wrapper: ':first-child', axis: 'structural', fact: true },
  'last-child': { label: 'Last child', wrapper: ':last-child', axis: 'structural', fact: true },
  'only-child': { label: 'Only child', wrapper: ':only-child', axis: 'structural', fact: true },
  empty: { label: 'Empty', wrapper: ':empty', axis: 'structural', fact: true },
  odd: { label: 'Odd', wrapper: ':nth-child(odd)', axis: 'structural', fact: true },
  even: { label: 'Even', wrapper: ':nth-child(even)', axis: 'structural', fact: true },
  narrow: CONDITIONS.narrow,
  medium: CONDITIONS.medium,
  dark: CONDITIONS.dark,
  'reduced-motion': CONDITIONS['reduced-motion'],
};

// The lean node tree (the blueprint's `_tree`, selector-matcher.js's
// input): a container section holding three blocks and an empty nested
// section, a multi-region section holding one block per region wrapper.
const LAYOUT = [
  {
    kind: 'container',
    uuid: 'sec-1',
    layout_id: 'daedalus_stylish_container',
    children: [
      { kind: 'block', uuid: 'blk-a', block_type: 'text' },
      { kind: 'block', uuid: 'blk-b', block_type: 'text' },
      { kind: 'block', uuid: 'blk-c', block_type: 'image' },
      { kind: 'container', uuid: 'sec-nested', layout_id: 'daedalus_stylish_container', children: [] },
    ],
  },
  {
    kind: 'container',
    uuid: 'sec-2',
    layout_id: 'layout_twocol',
    children: [
      { kind: 'region', region: 'first', children: [{ kind: 'block', uuid: 'blk-d', block_type: 'text' }] },
      {
        kind: 'region',
        region: 'second',
        children: [
          { kind: 'block', uuid: 'blk-e', block_type: 'text' },
          { kind: 'block', uuid: 'blk-f', block_type: 'text' },
        ],
      },
    ],
  },
];

test('facts are the payload flag, listed apart from the state vocabulary', () => {
  assert.equal(isFactCondition('first-child', WITH_FACTS), true);
  assert.equal(isFactCondition('hover', WITH_FACTS), false);
  assert.equal(isFactCondition('bogus', WITH_FACTS), false);
  assert.deepEqual(factConditionNames(WITH_FACTS), ['first-child', 'last-child', 'only-child', 'empty', 'odd', 'even']);
  assert.deepEqual(factConditionNames(CONDITIONS), []);
  // The state vocabulary (the force-state menu) never carries a fact.
  assert.deepEqual(stateConditionNames(WITH_FACTS), ['hover', 'focus']);
  // The structural axis takes its canonical place between state and width.
  assert.deepEqual(conditionAxisOrder(WITH_FACTS), ['state', 'structural', 'width', 'scheme', 'motion']);
});

test('a fact never derives from the vector: no pose reaches it', () => {
  // A full pose with a forced state derives the same compound whether or
  // not the table declares facts.
  assert.equal(vectorCondition({ width: 375, scheme: 'dark', motion: 'auto' }, 'hover', WITH_FACTS), 'hover+narrow+dark');
  // A fact handed as the force state is not a state: ignored.
  assert.equal(vectorCondition({ width: null }, 'first-child', WITH_FACTS), 'default');
  // The structural axis offers nothing to pose.
  assert.deepEqual(poseableAxisConditions('structural', WITH_FACTS), []);
});

test('driving to a compound skips its facts, complete', () => {
  // A pure fact drives the canvas nowhere: the resting pose, complete
  // (the fact is the selection's place, not a canvas axis).
  assert.deepEqual(conditionPose('first-child', WITH_FACTS), {
    vector: { ...DEFAULT_VECTOR },
    state: null,
    complete: true,
  });
  // A mixed compound poses its posable parts and skips the fact.
  assert.deepEqual(conditionPose('hover+odd+narrow', WITH_FACTS), {
    vector: { ...DEFAULT_VECTOR, width: 384 },
    state: 'hover',
    complete: true,
  });
});

test('satisfied facts read the sibling index from the blueprint tree', () => {
  const facts = (key) => [...satisfiedFacts(LAYOUT, key, WITH_FACTS)].sort();
  // Among a children section's four nodes: first, odd; middle even;
  // the nested empty section is last, even, and empty.
  assert.deepEqual(facts('block:blk-a'), ['first-child', 'odd']);
  assert.deepEqual(facts('block:blk-b'), ['even']);
  assert.deepEqual(facts('block:blk-c'), ['odd']);
  assert.deepEqual(facts('container:sec-nested'), ['empty', 'even', 'last-child']);
  // A region wrapper's nodes are one sibling list each: alone in a
  // region is first, last, only, odd.
  assert.deepEqual(facts('block:blk-d'), ['first-child', 'last-child', 'odd', 'only-child']);
  assert.deepEqual(facts('block:blk-f'), ['even', 'last-child']);
  // Root sections are siblings of one another; a block is never empty
  // (the tree carries no content).
  assert.deepEqual(facts('container:sec-1'), ['first-child', 'odd']);
  assert.deepEqual(facts('container:sec-2'), ['even', 'last-child']);
  // A selector key reads its owner's place; keys the tree does not carry
  // satisfy nothing; a table without facts yields nothing.
  assert.deepEqual(facts('block:blk-a::> *'), ['first-child', 'odd']);
  assert.deepEqual(facts('entity:root'), []);
  assert.deepEqual(facts('block:ghost'), []);
  assert.deepEqual(facts(null), []);
  assert.deepEqual([...satisfiedFacts(LAYOUT, 'block:blk-a', CONDITIONS)], []);
  assert.deepEqual([...satisfiedFacts([], 'block:blk-a', WITH_FACTS)], []);
});

test('a row renders when its posable parts are posed and its facts hold', () => {
  const satisfied = new Set(['first-child', 'odd']);
  // A pure pose: lit exactly at its compound (the old rule).
  assert.equal(conditionRenders('hover', 'hover', satisfied, WITH_FACTS), true);
  assert.equal(conditionRenders('hover', 'default', satisfied, WITH_FACTS), false);
  assert.equal(conditionRenders('hover', 'hover+narrow', satisfied, WITH_FACTS), false);
  // A pure fact: lit when satisfied, at any pose; unlit otherwise.
  assert.equal(conditionRenders('first-child', 'default', satisfied, WITH_FACTS), true);
  assert.equal(conditionRenders('first-child', 'hover', satisfied, WITH_FACTS), false);
  assert.equal(conditionRenders('last-child', 'default', satisfied, WITH_FACTS), false);
  // A mixed compound needs both halves.
  assert.equal(conditionRenders('hover+first-child', 'hover', satisfied, WITH_FACTS), true);
  assert.equal(conditionRenders('hover+first-child', 'default', satisfied, WITH_FACTS), false);
  assert.equal(conditionRenders('hover+even', 'hover', satisfied, WITH_FACTS), false);
  assert.equal(conditionRenders('odd+narrow', 'narrow', satisfied, WITH_FACTS), true);
  // 'default' is the absence of conditions: never lit.
  assert.equal(conditionRenders('default', 'default', satisfied, WITH_FACTS), false);
  // Without facts declared, a fact-looking part is just an unposed part.
  assert.equal(conditionRenders('first-child', 'default', new Set(), CONDITIONS), false);
});
