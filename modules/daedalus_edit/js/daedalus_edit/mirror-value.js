/**
 * @file
 * Reads a form-edited leaf's field value from the blueprint mirror.
 *
 * The form path commits through the entity form, not the op spine, but its
 * open edit is still a DRAFT judged by the three-way rule (see
 * plugins/form-editor-base.js): base = the mirror value at edit open,
 * remote = the mirror value after a foreign response. Both sides come from
 * here, resolved by the leaf's edit identity (the 9-part data-daedalus-edit-id)
 * rather than parsed from the canvas:
 * - Root entity fields: `blueprint.fields[fieldName]` when the leaf's
 *   entity is the mirror's root (Blueprint.getRoot()).
 * - Inline block fields: the component's `block_fields[fieldName]`, the
 *   component found through the leaf's enclosing `block:{uuid}` selectable
 *   wrapper (the one addressing scheme; block_fields mirror the placement's
 *   block_content entity).
 * - The block label pseudo-field (`...::label::block_property`): the
 *   component's own `label`.
 * Anything else (a paragraph's field, a leaf outside any placement) has no
 * mirror value: null, which resume() reads as "nothing to compare, the
 * remote stands". A form-only stub (identity without a value) is null
 * for the same reason: there is no value to be the base of a comparison.
 */

/**
 * Value equality for mirror values (scalars, link objects, arrays, field
 * value bags).
 *
 * @param {*} a
 *   One value.
 * @param {*} b
 *   The other value.
 *
 * @return {boolean}
 *   TRUE when the values are structurally equal.
 */
export const equals = (a, b) => {
  if (a === b) {
    return true;
  }
  if (
    typeof a !== 'object' ||
    typeof b !== 'object' ||
    a === null ||
    b === null
  ) {
    return false;
  }
  const aKeys = Object.keys(a);
  const bKeys = Object.keys(b);
  return (
    aKeys.length === bKeys.length &&
    aKeys.every((key) => equals(a[key], b[key]))
  );
};

/**
 * The mirror value for a form-edited leaf's field.
 *
 * @param {EditableElement} EditableElement
 *   The form-edited leaf (editable-element.js). Its page element must be on
 *   the page for a block leaf: the component is addressed through it.
 *
 * @return {*}
 *   The mirror's value (the mirror's own object, not a copy; callers that
 *   keep it clone it), or null when the leaf has no mirror value.
 */
export const mirrorValue = (EditableElement) => {
  const Blueprint = Drupal.Daedalus.Blueprint;
  const blueprint = Blueprint.getBlueprint();
  const root = Blueprint.getRoot();
  const { entityTypeId, entityId, fieldName, mainProperty } =
    EditableElement.info;
  let value;
  if (
    root &&
    root.entityType === entityTypeId &&
    String(root.entityId) === String(entityId)
  ) {
    value = blueprint?.fields?.[fieldName];
  } else if (entityTypeId === 'block_content') {
    const wrapper = EditableElement.getPageElement()?.closest(
      '[data-selectable^="block:"]',
    );
    const uuid = wrapper?.getAttribute('data-selectable').split(':')[1];
    const component = uuid ? Blueprint.componentForUuid(blueprint, uuid) : null;
    if (component) {
      value =
        mainProperty === 'block_property'
          ? component[fieldName]
          : component.block_fields?.[fieldName];
    }
  }
  if (value === undefined || Blueprint.isFormOnlyStub(value)) {
    return null;
  }
  return value;
};
