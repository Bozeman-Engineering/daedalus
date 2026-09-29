import { componentForUuid } from './component-for-uuid';

/**
 * Whether a selectable key names a field of a subject the mirror knows.
 *
 * A SUBJECT is an entity the spine addresses: the root (its fields are the
 * blueprint's `fields` map, keyed `field:{root}:{name}`) or a block
 * placement (its block_content's fields are the component node's
 * `block_fields`, keyed `block:{uuid}:{name}`). "Known" is membership in
 * the mirror, the model of record delivered with the same render that
 * produced the attributed markup, NOT the panel's projection: the
 * projection is display inference (which fields earn a row of their own in
 * the tree), while a rendered leaf carrying one of these keys is rendered
 * evidence that the field is on the page. The editing gate
 * (selection/resolver.js keyForEditableItem) consults this so a field the
 * tree folds (a non-preserved root field on a Layout Builder display, a
 * display-hidden block field) still edits in place wherever a theme, a
 * field block or a display actually rendered it.
 *
 * Pure over the mirror: no DOM, no store.
 *
 * @param {?Object} blueprint
 *   The delivered blueprint (the mirror).
 * @param {?{uuid: string}} root
 *   The root identity envelope.
 * @param {?string} key
 *   A selectable key.
 *
 * @return {boolean}
 *   True for `field:{root}:{name}` with `name` in the root's fields, and for
 *   `block:{uuid}:{name}` with `name` in the placed block's fields; false
 *   for every other key, before the mirror boots, and for a foreign host's
 *   keys (a placement outside this root's tree).
 */
export const isSubjectField = (blueprint, root, key) => {
  if (!blueprint || !root || typeof key !== 'string') {
    return false;
  }
  const [kind, uuid, name, ...rest] = key.split(':');
  if (!uuid || !name || rest.length > 0) {
    return false;
  }
  const own = (fields) =>
    Object.prototype.hasOwnProperty.call(fields ?? {}, name);
  if (kind === 'field') {
    return uuid === root.uuid && own(blueprint.fields);
  }
  if (kind === 'block') {
    return own(componentForUuid(blueprint, uuid)?.block_fields);
  }
  return false;
};
