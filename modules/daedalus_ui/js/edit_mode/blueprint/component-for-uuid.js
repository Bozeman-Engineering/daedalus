/**
 * Finds an element node in the blueprint's lean node tree by its uuid.
 *
 * The delivered blueprint carries the spine's lean node tree as `_tree`:
 * kind-discriminated container/region/block nodes with ONE child axis at
 * every depth (no regions map), styles on the node, `_element` annotations
 * riding along. The walk is uniform (any node may match by uuid, since a
 * container's uuid is the element's uuid at every surface, and any node
 * may carry children, since region nodes are transparent grouping), so
 * the facade resolves both halves of the selectable vocabulary
 * (`block:{uuid}` and `container:{uuid}`) with one recursion. Moved here
 * from daedalus_edit's editor base when daedalus_layout's verbs became the
 * second consumer: mirror lookups are facade capability, not a per-module
 * helper, exactly as isFormOnlyStub is.
 *
 * @param {?Object} blueprint
 *   A blueprint (usually Drupal.Daedalus.Blueprint.getBlueprint()).
 * @param {string} uuid
 *   The element uuid (a selectable key's uuid segment).
 *
 * @return {?Object}
 *   The element node (a block node or a container node), or null.
 */
export const componentForUuid = (blueprint, uuid) => {
  const find = (nodes) => {
    for (const node of nodes ?? []) {
      if (node.uuid === uuid) {
        return node;
      }
      const nested = find(node.children);
      if (nested) {
        return nested;
      }
    }
    return null;
  };
  return find(blueprint?._tree);
};
