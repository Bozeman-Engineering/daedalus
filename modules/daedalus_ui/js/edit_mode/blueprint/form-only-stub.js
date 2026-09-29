/**
 * @file
 * The form-only stub, the blueprint's "identity without a value" node.
 *
 * daedalus_blueprint serializes every processable field definition into FULL
 * blueprints for structure completeness; a field the blueprint carries no
 * value for (empty, view-access filtered, no serializable representation)
 * gets a stub node, {_form_only: true, _label, _empty?}, whose only
 * editing surface is the entity form. The `_empty` flag rides only on a
 * stub for a field the user may view, and says whether the stub stands
 * for an empty field rather than a populated one the blueprint cannot
 * spell; the entity-form panel's field-state judge (daedalus_edit
 * field-state.js) is its reader. The server treats a stub ARRIVING in a
 * write as "leave this field alone" (PATCH omit semantics), so mirror
 * consumers must never let a stub masquerade as a field value: sending one
 * back, or spreading one into an outgoing value, makes the write a silent
 * no-op.
 *
 * @see \Drupal\daedalus_blueprint\Utility\FormOnlyStub
 */

/**
 * Whether a mirror field value is a form-only stub node.
 *
 * @param {*} value
 *   A field value read from the blueprint mirror.
 *
 * @return {boolean}
 *   TRUE for a stub.
 */
export const isFormOnlyStub = (value) =>
  !!value &&
  typeof value === 'object' &&
  !Array.isArray(value) &&
  value._form_only === true;
