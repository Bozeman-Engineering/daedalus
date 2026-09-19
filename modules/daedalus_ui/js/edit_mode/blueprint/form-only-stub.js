/**
 * @file
 * The form-only stub, the blueprint's "identity without a value" node.
 *
 * daedalus_blueprint serializes every processable field definition into FULL
 * blueprints for structure completeness; a field the blueprint carries no
 * value for (empty, view-access filtered, no serializable representation)
 * gets a stub node, {_form_only: true, _label}, whose only editing
 * surface is the entity form. The server treats a stub ARRIVING in a
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
