/**
 * @file
 * The panel's field-state judge, and which revealed widgets a mutation
 * response left stale.
 *
 * One judge of field state. Per subject field the entity-form panel shows,
 * two facts decide how its widget presents, and both are the client's:
 *
 * - The MIRROR VALUE, the field's node in the blueprint mirror (the root
 *   entity's `fields[name]`, a block placement's `block_fields[name]`, the
 *   placement's own `label` for the label pseudo-field). The spine's word
 *   on whether the field has a value: a serialized value means present; a
 *   form-only stub (`{_form_only: true, _label, _empty?}`, the mirror's
 *   "identity without a value" node, see daedalus_ui's form-only-stub.js)
 *   means the blueprint carries no value, and its `_empty` flag, emitted
 *   only for a field the user may view, says whether that is because the
 *   field is empty. A stub without the flag (a populated field the
 *   blueprint cannot spell, or a field the user cannot view) reads as
 *   present, because the only thing the third state does is offer to
 *   REPLACE the value with a sample. No node at all (a non-processable
 *   field the form's advanced panes edit, the label pseudo-field of a
 *   placement with no label) reads as present for the same reason.
 * - Whether a CANVAS LEAF carries the field's identity, a rendered fact the
 *   caller measures on the live document (a
 *   `[data-daedalus-edit-field-value-wrapper]` outside the form-cache mount
 *   whose id opens with the wrapper's `{type}::{id}::{field}::` prefix).
 *
 * The three states:
 * - HIDDEN: a leaf exists; the leaf's editor owns the field and the widget
 *   row hides, whatever the mirror says (the render is the evidence).
 * - WIDGET: no leaf and the field has a value; the widget is the editor.
 * - WIDGET_SAMPLE: no leaf and the field is empty; the widget is the
 *   editor and carries the "Fill with sample" verb, whose commit is the
 *   spine's `_sample` request on the field (sample-fill.js). The judge
 *   does not ask whether the site can answer: the request's ladder
 *   entrance may invent a value where the field has no policy, and a
 *   field it cannot fill (a configured generator with no candidate, or no
 *   policy and no type sample) refuses with NO_SAMPLE_VALUE, leaving the
 *   widget as it was.
 *
 * The server builds no "Add field" button and decides nothing here: the
 * button was a build-time judge that disagreed with the attach-time reveal
 * the moment a value was typed. The wrapper of the CURRENT edit is the
 * caller's to exempt before consulting the judge (a form-swap edit detaches
 * the leaf and moves the widget onto the canvas for the edit's duration,
 * which is not "no leaf").
 *
 * The second decision here is the client-side successor of server-side
 * form eviction: after a mutation response applies, the revealed widgets
 * whose field the response's targets name hold a stale value (the cached
 * form was built before the write), so the panel refetches the subject's
 * form when targetedKeys() answers non-empty.
 *
 * Pure: no DOM, no store, no Drupal global. The panel's wiring measures the
 * inputs; the node spec (tests/js/field-state.test.mjs) drives it with
 * plain values.
 */

/**
 * A canvas leaf carries the field; the widget row hides.
 */
export const HIDDEN = 'hidden';

/**
 * No leaf and a value; the widget is the editor.
 */
export const WIDGET = 'widget';

/**
 * No leaf and no value; the widget is the editor and offers a sample fill.
 */
export const WIDGET_SAMPLE = 'widget-sample';

/**
 * Whether a mirror value is a form-only stub node.
 *
 * The stub's grammar is daedalus_ui's (form-only-stub.js, exposed at
 * runtime as Drupal.Daedalus.Blueprint.isFormOnlyStub); this judge reads it
 * directly so it stays pure.
 *
 * @param {*} value
 *   A mirror value.
 *
 * @return {boolean}
 */
const isStub = (value) =>
  !!value &&
  typeof value === 'object' &&
  !Array.isArray(value) &&
  value._form_only === true;

/**
 * Whether a mirror value says its field is empty.
 *
 * Only a stub's own flag counts: the mirror never spells emptiness any
 * other way (an empty field serializes as a stub, never as '', [] or
 * null), and a flag on anything but a stub is not the serializer's.
 *
 * @param {*} value
 *   A mirror value.
 *
 * @return {boolean}
 *   True for a stub flagged empty.
 */
export const isEmptyValue = (value) => isStub(value) && value._empty === true;

/**
 * The state of a subject field's panel widget.
 *
 * @param {{value: *, leaf: boolean}} facts
 *   - value: the field's mirror value (undefined when the mirror has no
 *     node for the field).
 *   - leaf: whether a canvas leaf carries the field's identity.
 *
 * @return {string}
 *   HIDDEN, WIDGET or WIDGET_SAMPLE.
 */
export const fieldState = ({ value, leaf }) => {
  if (leaf) {
    return HIDDEN;
  }
  return isEmptyValue(value) ? WIDGET_SAMPLE : WIDGET;
};

/**
 * The (uuid, field) pair a session key addresses, or null.
 *
 * A panel widget's key is `field:{root}:{f}`, `block:{c}:{f}` or
 * `block:{c}:label`; the kind is the session's business, the pair is what
 * a response target names.
 *
 * @param {?string} key
 *   A session key.
 *
 * @return {?string}
 *   "{uuid}:{field}", or null for anything that is not a three-part key.
 */
const pairOf = (key) => {
  if (typeof key !== 'string') {
    return null;
  }
  const [, uuid, name, ...rest] = key.split(':');
  return uuid && name && rest.length === 0 ? `${uuid}:${name}` : null;
};

/**
 * The revealed widget keys a mutation response's targets name.
 *
 * A target `{uuid, field_name}` names the field whose value the write
 * changed on the addressed entity (the root uuid for a root field, the
 * component uuid for a block field or the placement label). Nothing else
 * changes a field's value: a sentinel field_name (`_added`, `_removed`,
 * `_restructured`, `_reordered`; underscore-prefixed, which a field name
 * never is) marks a structural change, and a bare string target is a
 * style write's selectable key. Neither leaves a widget stale, so neither
 * matches. (`_reordered` carries no field name, so a reordered reference
 * field's widget is not refreshed by this rule; paragraphs are a later
 * topic.)
 *
 * @param {Array<{uuid: string, field_name: string}|string>} targets
 *   The response payload's `targets`.
 * @param {Array<?string>} keys
 *   The subject's revealed widget keys, in form order (an unkeyed widget
 *   passes null and never matches).
 *
 * @return {string[]}
 *   The keys the targets name, in the caller's order; refetch the subject's
 *   form when non-empty.
 */
export const targetedKeys = (targets, keys) => {
  const named = new Set();
  (targets ?? []).forEach((target) => {
    if (
      target &&
      typeof target === 'object' &&
      typeof target.uuid === 'string' &&
      typeof target.field_name === 'string' &&
      !target.field_name.startsWith('_')
    ) {
      named.add(`${target.uuid}:${target.field_name}`);
    }
  });
  if (named.size === 0) {
    return [];
  }
  return (keys ?? []).filter((key) => {
    const pair = pairOf(key);
    return pair !== null && named.has(pair);
  });
};
