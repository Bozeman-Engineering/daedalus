/**
 * Self-asserting tests for the panel's field-state judge
 * (js/daedalus_edit/field-state.js).
 *
 * Two pure decisions: (mirror value, canvas leaf present) to one of hidden
 * / widget / widget-with-sample-verb, and which revealed widget keys a
 * mutation response's targets name (the refetch decision). The model reads
 * plain values, so these tests drive it with plain values: the mirror's
 * stub grammar ({_form_only, _label, _empty?}), serialized values, and the
 * payload's target shapes ({uuid, field_name}, sentinels, style keys).
 *
 * Run with `npm test` from the daedalus root (the root sweep picks up
 * modules/star/tests/js/star.test.mjs), or `node --test` on this file.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';

import {
  HIDDEN,
  WIDGET,
  WIDGET_SAMPLE,
  fieldState,
  isEmptyValue,
  targetedKeys,
} from '../../js/daedalus_edit/field-state.js';

const ROOT = '99999999-1111-2222-3333-444444444444';
const BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';
const OTHER = 'cccccccc-1111-2222-3333-444444444444';

/** A stub for a visible, empty field. */
const emptyStub = { _form_only: true, _label: 'Related', _empty: true };

/** A stub for a populated field the blueprint cannot spell, or a hidden one. */
const bareStub = { _form_only: true, _label: 'Reference' };

test('a canvas leaf hides the widget whatever the mirror says', () => {
  [
    'A subtitle',
    { value: '<p>Body</p>', format: 'basic_html' },
    [{ target_id: 3 }],
    emptyStub,
    bareStub,
    undefined,
    null,
  ].forEach((value) => {
    assert.equal(
      fieldState({ value, leaf: true }),
      HIDDEN,
      `leaf present, value ${JSON.stringify(value)}`,
    );
  });
});

test('no leaf and a serialized value reveals the widget without the verb', () => {
  ['A subtitle', 7, { value: '<p>Body</p>', format: 'basic_html' }, [{ target_id: 3 }], { uri: 'https://example.com', title: 'Example' }].forEach(
    (value) => {
      assert.equal(
        fieldState({ value, leaf: false }),
        WIDGET,
        `value ${JSON.stringify(value)}`,
      );
    },
  );
});

test('no leaf and a stub flagged empty reveals the widget with the sample verb', () => {
  assert.equal(fieldState({ value: emptyStub, leaf: false }), WIDGET_SAMPLE);
});

test('a stub without the flag reads as present: no sample verb offered', () => {
  // A populated field the blueprint cannot spell, and a view-access-filtered
  // field, stub identically without the flag; a sample fill would REPLACE
  // the value, so the verb is withheld.
  assert.equal(fieldState({ value: bareStub, leaf: false }), WIDGET);
  assert.equal(
    fieldState({ value: { _form_only: true, _label: 'X', _empty: false }, leaf: false }),
    WIDGET,
  );
});

test('no mirror node reads as present', () => {
  // A non-processable field the advanced panes edit (path, status) has no
  // node; the label pseudo-field of a placement with no label is undefined.
  assert.equal(fieldState({ value: undefined, leaf: false }), WIDGET);
  assert.equal(fieldState({ leaf: false }), WIDGET);
});

test("emptiness is only a stub's flag, never a value's shape", () => {
  // The serializer never spells an empty field as '', [] or null (it stubs
  // it), so these are values as far as the judge is concerned.
  ['', [], null, 0, false].forEach((value) => {
    assert.equal(fieldState({ value, leaf: false }), WIDGET, `value ${JSON.stringify(value)}`);
    assert.equal(isEmptyValue(value), false);
  });
  // A flag on a non-stub object is not the serializer's.
  assert.equal(isEmptyValue({ _empty: true }), false);
  assert.equal(isEmptyValue([{ _form_only: true, _empty: true }]), false);
  assert.equal(isEmptyValue(emptyStub), true);
  assert.equal(isEmptyValue(bareStub), false);
});

test('a root field target names the revealed root widget', () => {
  const keys = [`field:${ROOT}:field_related`, `field:${ROOT}:field_tags`];
  assert.deepEqual(
    targetedKeys([{ uuid: ROOT, field_name: 'field_related' }], keys),
    [`field:${ROOT}:field_related`],
  );
});

test('a block field target and a label target name the block widgets', () => {
  const keys = [`block:${BLOCK}:field_media`, `block:${BLOCK}:label`];
  assert.deepEqual(
    targetedKeys([{ uuid: BLOCK, field_name: 'field_media' }], keys),
    [`block:${BLOCK}:field_media`],
  );
  assert.deepEqual(
    targetedKeys([{ uuid: BLOCK, field_name: 'label' }], keys),
    [`block:${BLOCK}:label`],
  );
});

test('the answer is the keys the targets name, in form order', () => {
  const keys = [
    `field:${ROOT}:field_a`,
    `field:${ROOT}:field_b`,
    `field:${ROOT}:field_c`,
  ];
  assert.deepEqual(
    targetedKeys(
      [
        { uuid: ROOT, field_name: 'field_c' },
        { uuid: ROOT, field_name: 'field_a' },
        { uuid: ROOT, field_name: 'field_hidden' },
      ],
      keys,
    ),
    [`field:${ROOT}:field_a`, `field:${ROOT}:field_c`],
  );
});

test('a target on another subject with the same field name is not a match', () => {
  assert.deepEqual(
    targetedKeys([{ uuid: OTHER, field_name: 'field_related' }], [`field:${ROOT}:field_related`]),
    [],
  );
  assert.deepEqual(
    targetedKeys([{ uuid: ROOT, field_name: 'field_media' }], [`block:${BLOCK}:field_media`]),
    [],
  );
});

test('sentinel targets change no field value and match nothing', () => {
  const keys = [`field:${ROOT}:field_related`, `block:${BLOCK}:field_media`];
  ['_added', '_removed', '_restructured', '_reordered'].forEach((sentinel) => {
    assert.deepEqual(targetedKeys([{ uuid: ROOT, field_name: sentinel }], keys), [], sentinel);
    assert.deepEqual(targetedKeys([{ uuid: BLOCK, field_name: sentinel }], keys), [], sentinel);
  });
});

test("a style write's string target matches nothing, even a widget's own key", () => {
  const keys = [`field:${ROOT}:field_related`, `block:${BLOCK}:field_media`];
  assert.deepEqual(targetedKeys([`field:${ROOT}:field_related`, `block:${BLOCK}`], keys), []);
});

test('no targets, no keys, unkeyed widgets and malformed inputs answer empty', () => {
  const keys = [`field:${ROOT}:field_related`];
  assert.deepEqual(targetedKeys([], keys), []);
  assert.deepEqual(targetedKeys(undefined, keys), []);
  assert.deepEqual(targetedKeys([{ uuid: ROOT, field_name: 'field_related' }], []), []);
  assert.deepEqual(targetedKeys([{ uuid: ROOT, field_name: 'field_related' }], undefined), []);
  // An unkeyed panel widget (a paragraph's) passes null and never matches;
  // a two-part or four-part key names no field.
  assert.deepEqual(
    targetedKeys(
      [{ uuid: ROOT, field_name: 'field_related' }],
      [null, `entity:${ROOT}`, `field:${ROOT}:field_related:extra`],
    ),
    [],
  );
  // Targets missing a half, or not objects, are skipped rather than thrown on.
  assert.deepEqual(
    targetedKeys([{ uuid: ROOT }, { field_name: 'field_related' }, null, 7], keys),
    [],
  );
});
