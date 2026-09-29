/**
 * Self-asserting tests for the client's reads of a served roster node.
 *
 * Run directly (`node tests/js/style-guide-refs.test.mjs`) or via
 * JsSuitesTest. ClientStyleGuide serves every node as the vocabulary
 * roster's resolved answers at its address (grants: group id => the
 * granted names; valueSpaces: property => the effective constraint
 * block), never the authored declaration. These tests hold the client
 * half of those reads (declaredSets, grantsOf, propertyGranted,
 * constraintsFor, buildSections) over the served shape, and the arrival
 * posture a built section projects into the collapse model (autoOpen:
 * the values signal and the pending-line signal over the section's
 * visible rows); the equivalence with the PHP roster is locked by
 * VocabularyRosterTest::testClientTwinEquivalence through
 * tests/js/vocabulary-cases.mjs. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { autoOpen, buildSections, constraintsFor, declaredSets, grantsOf, propertyGranted } from '../../js/src/style-guide.js';

const guide = {
  enforcement: 'guarded',
  groups: {
    spacing: {
      label: 'Spacing',
      properties: {
        padding: { label: 'Padding', type: 'dimension', repeat: 4 },
        gap: { label: 'Gap', type: 'dimension' },
      },
    },
    typography: {
      label: 'Typography',
      properties: {
        'font-size': { label: 'Font size', type: 'dimension' },
        color: { label: 'Text color', type: 'color' },
      },
    },
  },
  conditions: {
    hover: { label: 'Hover', wrapper: ':hover' },
  },
};

// A served node: curated grants, a generated (wide-tier) group's grant,
// and the value spaces across all of them (exactly ClientStyleGuide's
// serveNode shape).
const declaration = {
  grants: {
    typography: ['font-size', 'color'],
    spacing: ['padding', 'gap'],
    'wide:transitions': ['transition-duration', 'transition-property'],
  },
  valueSpaces: {
    padding: { max: '4rem', units: ['rem', 'px'] },
    'transition-duration': { allowed: ['0.2s'] },
  },
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('grantsOf and declaredSets read the served grants, {} and [] otherwise', () => {
  assert.deepEqual(grantsOf(declaration), declaration.grants);
  assert.deepEqual(declaredSets(declaration), ['typography', 'spacing', 'wide:transitions']);
  assert.deepEqual(declaredSets({}), []);
  assert.deepEqual(declaredSets(null), []);
  // A PHP-empty map and a malformed list are no grants.
  assert.deepEqual(grantsOf({ grants: [] }), {});
  assert.deepEqual(grantsOf({ grants: { spacing: 'padding' } }), {});
});

test('propertyGranted is the one grant read: curated and wide names alike', () => {
  assert.equal(propertyGranted(declaration, 'padding'), true);
  assert.equal(propertyGranted(declaration, 'transition-duration'), true);
  assert.equal(propertyGranted(declaration, 'transition-delay'), false, 'a wide name the generated group does not grant');
  assert.equal(propertyGranted(declaration, 'margin'), false);
  assert.equal(propertyGranted({}, 'padding'), false);
  assert.equal(propertyGranted(null, 'padding'), false);
});

test('constraintsFor is the one value-space read: curated and wide blocks alike', () => {
  assert.deepEqual(constraintsFor(declaration, 'padding'), { max: '4rem', units: ['rem', 'px'] });
  assert.deepEqual(constraintsFor(declaration, 'transition-duration'), { allowed: ['0.2s'] });
  assert.equal(constraintsFor(declaration, 'gap'), null);
  assert.equal(constraintsFor({}, 'padding'), null);
  assert.equal(constraintsFor(null, 'padding'), null);
  assert.equal(constraintsFor({ valueSpaces: [] }, 'padding'), null, 'a PHP-empty map');
});

test('buildSections projects granted groups in catalog order with value spaces', () => {
  const sections = buildSections(guide, declaration, { default: { padding: '2rem' } });
  // Catalog (panel) order, not grant order; the wide-tier group never
  // becomes a section (its definitions are server-only).
  assert.deepEqual(sections.map((section) => section.id), ['spacing', 'typography']);
  const spacing = sections[0];
  assert.deepEqual(spacing.rows.map((row) => row.property), ['padding', 'gap']);
  const [padding, gap] = spacing.rows;
  assert.deepEqual(padding.constraints, { max: '4rem', units: ['rem', 'px'] });
  assert.deepEqual(padding.values, [{ condition: 'default', value: '2rem' }]);
  assert.equal(gap.constraints, null);
  assert.equal(spacing.hasValues, true);
  assert.equal(sections[1].hasValues, false);
});

test('an ungranted group still sections when the entry sets its property', () => {
  const sections = buildSections(guide, { grants: { spacing: ['padding', 'gap'] } }, { default: { color: 'teal' } });
  assert.deepEqual(sections.map((section) => section.id), ['spacing', 'typography']);
  // Ungranted sections show only the set properties (current values
  // always display; the vocabulary does not open).
  assert.deepEqual(sections[1].rows.map((row) => row.property), ['color']);
});

test('a granted group rows only the names the node grants; set values keep their row', () => {
  // A narrowed grant serves exactly its names; the typography grant is
  // the whole group.
  const container = {
    grants: { spacing: ['gap'], typography: ['font-size', 'color'] },
  };
  const sections = buildSections(guide, container, null);
  assert.deepEqual(sections.map((section) => section.id), ['spacing', 'typography']);
  assert.deepEqual(sections[0].rows.map((row) => row.property), ['gap']);
  assert.deepEqual(sections[1].rows.map((row) => row.property), ['font-size', 'color']);
  // A committed ungranted value stays visible (and unsettable) in its
  // catalog position, exactly like a value in an ungranted group.
  const withValue = buildSections(guide, container, { default: { padding: '1rem' }, hover: { padding: '2rem' } });
  const spacing = withValue[0];
  assert.deepEqual(spacing.rows.map((row) => row.property), ['padding', 'gap']);
  assert.deepEqual(spacing.rows[0].values, [
    { condition: 'default', value: '1rem' },
    { condition: 'hover', value: '2rem' },
  ]);
  assert.equal(spacing.hasValues, true);
});

test('a property no catalog group owns lands in the trailing catch-all', () => {
  const sections = buildSections(guide, { grants: { spacing: ['padding', 'gap'] } }, { default: { 'text-wrap': 'balance' } });
  const other = sections.at(-1);
  assert.equal(other.id, '_other');
  assert.deepEqual(other.rows.map((row) => row.property), ['text-wrap']);
});

test('a node with no grants projects only value-bearing sections', () => {
  assert.deepEqual(buildSections(guide, {}, null), []);
  const sections = buildSections(guide, {}, { default: { gap: '1rem' } });
  assert.deepEqual(sections.map((section) => section.id), ['spacing']);
});

test('buildSections binds the grant at every placement depth', () => {
  // Placement curates offers and birth, never legality: the projection
  // takes no depth, and a narrowed grant rows the same for a page band
  // and a nested container.
  const container = { grants: { spacing: ['gap'] } };
  assert.equal(buildSections.length, 3, 'no depth argument exists');
  assert.deepEqual(buildSections(guide, container, null)[0].rows.map((row) => row.property), ['gap']);
  assert.deepEqual(buildSections(guide, { grants: { spacing: ['padding', 'gap'] } }, null)[0].rows.map((row) => row.property), ['padding', 'gap'],
    'the whole group rows the shorthand wherever the container sits');
});

// The arrival posture (autoOpen): the section's default in the collapse
// model, `folded: !autoOpen(...)`. The explicit postures over it are the
// model's (daedalus_ui tests/js/collapse.test.mjs owns that rule).
const visibleRows = [{ property: 'padding' }, { property: 'margin' }];
const noPending = () => null;
const pendingOn = (property) => (candidate) => (candidate.property === property ? 'set' : null);

test('autoOpen: an empty section with no signals arrives closed', () => {
  assert.equal(autoOpen({ hasValues: false }, visibleRows, { pendingFor: noPending }), false);
  assert.equal(autoOpen({ hasValues: false }, [], { pendingFor: noPending }), false);
});

test('autoOpen: shown values open the section (the stack\'s delivered entry: own, variant, skin or declared alike)', () => {
  assert.equal(autoOpen({ hasValues: true }, visibleRows, { pendingFor: noPending }), true);
});

test('autoOpen: a pending line on a visible row opens the section', () => {
  assert.equal(autoOpen({ hasValues: false }, visibleRows, { pendingFor: pendingOn('margin') }), true);
  // Only VISIBLE rows count: a pending line on a hidden row is not offered.
  assert.equal(autoOpen({ hasValues: false }, [{ property: 'padding' }], { pendingFor: pendingOn('margin') }), false);
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
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} style-guide-refs tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} style-guide-refs tests passed.`);
