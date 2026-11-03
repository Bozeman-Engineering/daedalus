/**
 * The condition clause grammar's client twin (condition-clause.js) over
 * the shared battery (daedalus_ui/tests/js/condition-clause-cases.mjs, the record the
 * PHP side reads too): the top-level split, every clause kind with its
 * derived band and preference, the canonical order and the axis order.
 * PHP is authoritative (ConditionClauseTest).
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import {
  CONTAINER,
  LITERAL,
  MEDIA,
  PSEUDO,
  REFERENCE,
  SUPPORTS,
  UNKNOWN,
  canonicalCompound,
  clauseBand,
  clauseFeatures,
  clausePosable,
  clausePreference,
  clauseStamp,
  compoundParts,
  conditionAxisOrder,
  parseClause,
  parseCompound,
} from '../../js/edit_mode/materials/condition-clause.js';
import { AXES, CANONICALS, CLAUSES, POSES, SPLITS, TABLES } from './condition-clause-cases.mjs';

const table = (name) => TABLES[name ?? 'default'].conditions;

test('the client twin splits every battery case as recorded', () => {
  assert.ok(SPLITS.length > 0, 'the battery carries split cases');
  SPLITS.forEach(({ name, text, answer }) => {
    assert.deepEqual(compoundParts(text), answer, name);
  });
});

test('the client twin reads every clause as recorded', () => {
  assert.ok(CLAUSES.length > 0, 'the battery carries clause cases');
  CLAUSES.forEach(({ name, text, table: tableName, answer }) => {
    assert.deepEqual(parseClause(text, table(tableName)), answer, name);
  });
});

test('the client twin canonicalizes every compound as recorded, idempotently', () => {
  assert.ok(CANONICALS.length > 0, 'the battery carries canonical cases');
  CANONICALS.forEach(({ name, text, table: tableName, answer }) => {
    assert.equal(canonicalCompound(text, table(tableName)), answer, name);
    assert.equal(canonicalCompound(answer, table(tableName)), answer, `${name} (idempotent)`);
  });
});

test('the client twin orders the axes as recorded', () => {
  AXES.forEach(({ name, table: tableName, answer }) => {
    assert.deepEqual(conditionAxisOrder(table(tableName)), answer, name);
  });
});

test('the client twin poses every battery case as recorded', () => {
  assert.ok(POSES.length > 0, 'the battery carries pose cases');
  POSES.forEach(({ name, text, table: tableName, answer }) => {
    const clause = parseClause(text, table(tableName));
    assert.deepEqual(
      { posable: clausePosable(clause), stamp: clauseStamp(clause), features: clauseFeatures(clause.css ?? clause.text) },
      answer,
      name,
    );
  });
});

test('every record is one of the three kinds in one of the four forms, and a compound parses clause by clause', () => {
  const kinds = new Set([REFERENCE, LITERAL, UNKNOWN]);
  const forms = new Set([PSEUDO, MEDIA, CONTAINER, SUPPORTS, null]);
  CLAUSES.forEach(({ name, answer }) => {
    assert.ok(kinds.has(answer.kind), name);
    assert.ok(forms.has(answer.form), name);
    assert.deepEqual(Object.keys(answer), ['kind', 'text', 'form', 'axis', 'css', 'band', 'preference'], name);
    if (answer.kind === UNKNOWN) {
      assert.equal(answer.axis, null, name);
      assert.equal(answer.css, null, name);
    }
  });
  const parsed = parseCompound('dark+narrow+bogus', table());
  assert.deepEqual(parsed.map(({ kind }) => kind), [REFERENCE, REFERENCE, UNKNOWN]);
  assert.deepEqual(parsed.map(({ text }) => text), ['dark', 'narrow', 'bogus']);
});

test('the derived twins read the wrapper text and nothing else', () => {
  assert.deepEqual(clauseBand('@container (max-width: 767px)'), { max: 767 });
  assert.deepEqual(clauseBand('@container (min-width: 768px) and (max-width: 1023px)'), { min: 768, max: 1023 });
  assert.equal(clauseBand('@media (max-width: 767px)'), null, 'a viewport band is not posable');
  assert.equal(clauseBand(':hover'), null);
  assert.deepEqual(clausePreference('@media (prefers-color-scheme: dark)'), { feature: 'prefers-color-scheme', value: 'dark' });
  assert.equal(clausePreference('@media (prefers-color-scheme: dark) and (hover: hover)'), null, 'one feature only');
  assert.equal(clausePreference('@container (max-width: 767px)'), null);
  assert.equal(clausePreference('@media (hover: hover)'), null, 'not a preference');
});
