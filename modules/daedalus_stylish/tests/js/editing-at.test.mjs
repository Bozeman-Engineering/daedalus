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
  effectiveWidth,
  stateConditionNames,
  widthConditionFor,
  vectorCondition,
  conditionPose,
  poseableAxisConditions,
  styledConditions,
  conditionRenders,
  stateQualifiers,
  qualifierStates,
  literalClauses,
} from '../../js/src/editing-at.js';
import {
  conditionAxisOrder,
  parseClause,
} from '../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';
import { buildStack, deliveredEntry } from '../../js/src/style-stack.js';
import { variantRecord } from './materials-fixture.mjs';

// The shipped condition table's shape and order ((weight, id)-ordered, so
// axes first-appear as state, width, scheme, motion), 'mobile' standing in
// for the transitional @media width vocabulary the canvas cannot pose (a
// viewport query derives no band). The model reads the band and the
// preference the clause grammar derives from each wrapper, never the
// wrapper text itself.
const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
  medium: { label: 'Medium', wrapper: '@container (min-width: 768px) and (max-width: 1023px)', axis: 'width' },
  mobile: { label: 'Mobile', wrapper: '@media (max-width: 767px)', axis: 'width' },
  dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  'reduced-motion': { label: 'Reduced motion', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion' },
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

test('preference parts read the preference the clause grammar derives from the wrapper', () => {
  // A light-default site's twin vocabulary, a wrapper with a media type
  // before its feature and one spelled without the space: the derivation
  // reads the feature test out of each.
  const conditions = {
    light: { label: 'Light mode', wrapper: '@media screen and (prefers-color-scheme: light)', axis: 'scheme' },
    dark: { label: 'Dark mode', wrapper: '@media (prefers-color-scheme:dark)', axis: 'scheme' },
    calm: { label: 'Calm', wrapper: '@media (prefers-reduced-motion: reduce)', axis: 'motion' },
    // A scheme-axis condition whose preference the emulation cannot force
    // (no vector value makes "more" true): unposable, and no forced value
    // derives it.
    dim: { label: 'Dim', wrapper: '@media (prefers-contrast: more)', axis: 'scheme' },
  };
  assert.equal(vectorCondition({ scheme: 'light' }, null, conditions), 'light');
  assert.equal(vectorCondition({ scheme: 'dark' }, null, conditions), 'dark');
  assert.equal(vectorCondition({ motion: 'reduce' }, null, conditions), 'calm');
  assert.deepEqual(conditionPose('light', conditions).vector.scheme, 'light');
  assert.deepEqual(conditionPose('calm', conditions).vector.motion, 'reduce');
  assert.equal(conditionPose('dim', conditions).complete, false);
  assert.deepEqual(poseableAxisConditions('scheme', conditions), ['light', 'dark']);
  // A wrapper deriving no forceable preference contributes nothing.
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

test('state qualifiers: a published state on a top-level compound, by compound, never inside :has() or :not()', () => {
  // The reveal: hover on the `li`, compound 0; two states on two
  // compounds in chain order; the anchor is compound 0.
  assert.deepEqual(stateQualifiers('li:hover > ul', CONDITIONS), [{ index: 0, state: 'hover' }]);
  assert.deepEqual(stateQualifiers('> d-text:hover a:focus', CONDITIONS), [
    { index: 0, state: 'hover' },
    { index: 1, state: 'focus' },
  ]);
  assert.deepEqual(stateQualifiers('&:hover > ul', CONDITIONS), [{ index: 0, state: 'hover' }]);
  assert.deepEqual(stateQualifiers('a:focus:hover', CONDITIONS), [
    { index: 0, state: 'focus' },
    { index: 0, state: 'hover' },
  ]);
  // Not states: a position, a class, an attribute, an unpublished
  // pseudo-class, a state inside :has() or :not() (no prefix to stamp).
  assert.deepEqual(stateQualifiers('> *:first-child', CONDITIONS), []);
  assert.deepEqual(stateQualifiers('li.active[aria-current="page"] > ul', CONDITIONS), []);
  assert.deepEqual(stateQualifiers('a:visited', CONDITIONS), []);
  assert.deepEqual(stateQualifiers('&:focus-within > ul', CONDITIONS), []);
  assert.deepEqual(stateQualifiers('> d-text:has(a:hover)', CONDITIONS), []);
  assert.deepEqual(stateQualifiers('> *:not(:hover)', CONDITIONS), []);
  // A state inside an `:is()` member stands on the member's host
  // compound and is found with that compound's index (the reveal
  // `.menu-item:is(:focus-within, :hover) > ul` poses hover on its
  // `.menu-item`; an unpublished member poses nothing); members in the
  // grammar's sorted order; `:is()` under `:has()` still has no prefix.
  assert.deepEqual(stateQualifiers('.menu-item:is(:focus-within, :hover) > ul', CONDITIONS), [
    { index: 0, state: 'hover' },
  ]);
  assert.deepEqual(stateQualifiers('> d-text:is(:focus, :hover) a', CONDITIONS), [
    { index: 0, state: 'focus' },
    { index: 0, state: 'hover' },
  ]);
  assert.deepEqual(stateQualifiers('> d-text:has(*:is(a:hover, em))', CONDITIONS), []);
  // The owner itself, no text, text outside the grammar: nothing.
  assert.deepEqual(stateQualifiers(null, CONDITIONS), []);
  assert.deepEqual(stateQualifiers('', CONDITIONS), []);
  assert.deepEqual(stateQualifiers('div > > p', CONDITIONS), []);
  // The state is the TABLE's name, found by wrapper, never the
  // pseudo-class word; a pseudo wrapper off the state axis is no state.
  const renamed = {
    hovered: { label: 'Hovered', wrapper: ':hover', axis: 'state' },
    odd: { label: 'Odd', wrapper: ':focus', axis: 'width' },
  };
  assert.deepEqual(stateQualifiers('li:hover > a:focus', renamed), [{ index: 0, state: 'hovered' }]);
  assert.deepEqual(stateQualifiers('li:hover > ul', {}), []);
  // The states a selector lives at, each once, in table order.
  assert.deepEqual(qualifierStates('> d-text:focus a:hover', CONDITIONS), ['hover', 'focus']);
  assert.deepEqual(qualifierStates('li:hover > ul:hover', CONDITIONS), ['hover']);
  assert.deepEqual(qualifierStates('.menu-item:is(:focus-within, :hover) > ul', CONDITIONS), ['hover']);
  assert.deepEqual(qualifierStates('> d-text', CONDITIONS), []);
  assert.deepEqual(qualifierStates(null, CONDITIONS), []);
});

test('a row renders exactly when the canvas stands at its compound', () => {
  // A pose: lit at its compound, nowhere else (a position is selector
  // text, never a condition, so nothing but the pose decides).
  assert.equal(conditionRenders('hover', 'hover'), true);
  assert.equal(conditionRenders('hover', 'default'), false);
  assert.equal(conditionRenders('hover', 'hover+narrow'), false);
  assert.equal(conditionRenders('hover+narrow', 'hover+narrow'), true);
  assert.equal(conditionRenders('narrow', 'hover+narrow'), false);
  // 'default' is the absence of conditions: never lit.
  assert.equal(conditionRenders('default', 'default'), false);
  assert.equal(conditionRenders(null, 'hover'), false);
});

// ---------------------------------------------------------------------------
// Literal clauses in use: the WHEN a site admitted beside its references.
// ---------------------------------------------------------------------------

const BAND = '@container (min-width: 400px) and (max-width: 480px)';
const SECTION = 'container:11111111-1111-1111-1111-111111111111';
const MAP = {
  [SECTION]: {
    default: { gap: '1rem' },
    [BAND]: { gap: 'var(--space-8)' },
    // An emptied block holds nothing: not in use.
    '@container (min-width: 900px)': {},
    // A literal equal to a published wrapper IS the reference, never a
    // literal clause.
    '@container (max-width: 767px)': { gap: '0' },
  },
  [`${SECTION}::> d-text`]: {
    ':checked': { color: 'red' },
    'hover+@media (prefers-contrast: more)': { color: 'blue' },
  },
  // Another element's rows are not this element's.
  'block:22222222-2222-2222-2222-222222222222': {
    '@supports (display: grid)': { display: 'grid' },
  },
};
const LITERALS = literalClauses(MAP, SECTION, CONDITIONS);

test('literal clauses in use: the element\'s own and its rules\' literals, each once, map order', () => {
  assert.deepEqual(
    LITERALS.map(({ kind, text }) => [kind, text]),
    [
      ['literal', BAND],
      ['literal', ':checked'],
      ['literal', '@media (prefers-contrast: more)'],
    ],
  );
  // The band and the stamp ride the records the chip row and the ruler read.
  assert.deepEqual(LITERALS[0].band, { min: 400, max: 480 });
  assert.equal(LITERALS[1].form, 'pseudo');
  // Nothing selected, nothing in use; a map without the key the same.
  assert.deepEqual(literalClauses(MAP, null, CONDITIONS), []);
  assert.deepEqual(literalClauses({}, SECTION, CONDITIONS), []);
});

test('a literal band in use is the write target where it holds, the reference elsewhere', () => {
  // 440 sits inside narrow (≤767) AND the literal (400..480): the
  // cascade delivers the literal last, so it is the write target.
  assert.equal(widthConditionFor(440, CONDITIONS, LITERALS), BAND);
  assert.equal(vectorCondition({ width: 440 }, null, CONDITIONS, LITERALS), BAND);
  // Outside the literal's band the reference answers as before.
  assert.equal(widthConditionFor(300, CONDITIONS, LITERALS), 'narrow');
  assert.equal(widthConditionFor(800, CONDITIONS, LITERALS), 'medium');
  // Without the literals in use (another element selected) 440 is narrow.
  assert.equal(widthConditionFor(440, CONDITIONS), 'narrow');
  // Two literals holding: the later in map order wins, as the cascade does.
  const inner = parseClause('@container (min-width: 420px) and (max-width: 460px)', CONDITIONS);
  assert.equal(widthConditionFor(440, CONDITIONS, [LITERALS[0], inner]), inner.text);
  assert.equal(widthConditionFor(440, CONDITIONS, [inner, LITERALS[0]]), BAND);
  // The compound spells canonically: the published state axis first,
  // the literal width clause where the reference would stand.
  assert.equal(
    vectorCondition({ width: 440, scheme: 'dark' }, 'hover', CONDITIONS, LITERALS),
    `hover+${BAND}+dark`,
  );
});

test('a literal state in use is posed by its stamp word', () => {
  // The force state carries the stamp word the printed dual matches
  // (`checked`); the write target is the clause's text.
  assert.equal(vectorCondition(null, 'checked', CONDITIONS, LITERALS), ':checked');
  // Not in use, the word is no state.
  assert.equal(vectorCondition(null, 'checked', CONDITIONS), 'default');
  // A published name still spells itself.
  assert.equal(vectorCondition(null, 'hover', CONDITIONS, LITERALS), 'hover');
});

test('a literal preference in use answers to the emulation vector by its feature', () => {
  // No published `light`: a literal light-scheme clause in use is what
  // forcing light writes at; the preference's FEATURE decides, whatever
  // axis name the clause carries.
  const light = parseClause('@media (prefers-color-scheme: light)', CONDITIONS);
  assert.equal(vectorCondition({ scheme: 'light' }, null, CONDITIONS, [light]), light.text);
  // A published condition testing the same wins over a literal in use.
  assert.equal(vectorCondition({ scheme: 'dark' }, null, CONDITIONS, [parseClause('@media (prefers-color-scheme: dark)', {})]), 'dark');
  // A preference no vector axis emulates contributes nothing.
  assert.equal(vectorCondition({ scheme: 'dark' }, null, CONDITIONS, [LITERALS[2]]), 'dark');
});

test('drive the canvas to a literal: the band\'s midpoint, the stamp word, the forced value', () => {
  assert.deepEqual(conditionPose(BAND, CONDITIONS), {
    vector: { width: 440, scheme: 'auto', motion: 'auto' },
    state: null,
    complete: true,
  });
  assert.deepEqual(conditionPose(':checked', CONDITIONS), {
    vector: { width: null, scheme: 'auto', motion: 'auto' },
    state: 'checked',
    complete: true,
  });
  assert.deepEqual(conditionPose('@media (prefers-color-scheme: light)', CONDITIONS).vector.scheme, 'light');
  assert.deepEqual(conditionPose('@media (prefers-reduced-motion: no-preference)', CONDITIONS).vector.motion, 'full');
  // The mixed compound poses every clause.
  assert.deepEqual(conditionPose(`hover+${BAND}+dark`, CONDITIONS), {
    vector: { width: 440, scheme: 'dark', motion: 'auto' },
    state: 'hover',
    complete: true,
  });
  // What the canvas cannot pose: a @media width, a supports test, a
  // preference no axis emulates, a state no stamp stands in for.
  for (const clause of ['@media (min-width: 900px)', '@supports (display: grid)', '@media (prefers-contrast: more)', ':visited', ':target']) {
    const pose = conditionPose(clause, CONDITIONS);
    assert.equal(pose.complete, false, clause);
    assert.equal(pose.state, null, clause);
    assert.equal(pose.vector.width, null, clause);
  }
});

test('drive then derive round-trips every posable literal in use', () => {
  for (const clause of [BAND, ':checked', `hover+${BAND}+dark`]) {
    const pose = conditionPose(clause, CONDITIONS);
    assert.equal(pose.complete, true, clause);
    assert.equal(vectorCondition(pose.vector, pose.state, CONDITIONS, LITERALS), clause);
  }
  // A literal row lights exactly when the canvas stands at it.
  const posed = vectorCondition({ width: 440 }, null, CONDITIONS, LITERALS);
  assert.equal(conditionRenders(BAND, posed), true);
  assert.equal(conditionRenders('narrow', posed), false);
});

test('the axis selects still offer the published references alone', () => {
  // A literal in use is a chip entry (chip-row-model.js), never a
  // published offer.
  assert.deepEqual(poseableAxisConditions('width', CONDITIONS), ['narrow', 'medium']);
});
