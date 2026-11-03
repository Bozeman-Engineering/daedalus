/**
 * THE document tree: the delivered blueprint's layout with the host above it.
 *
 * The blueprint's `layout` is the spine's lean node tree, the serializer's
 * canonical shape: the sections at the top, kind-discriminated
 * container/region/block nodes below, `_element` annotations riding along.
 * That is what every layout walker reads (the projection, the markup
 * index, componentForUuid, variant capture, composition slots, the dialect
 * and the instantiator on the server), and it stays exactly that.
 *
 * A reader asking about CONTAINMENT needs one more node: in the DOM the
 * host entity's canvas root wrapper (`data-selectable="entity:{uuid}"`)
 * stands above the sections, so a rule written on the entity reaches every
 * element on the page. The document tree is that view: ONE top-level node
 * of kind `entity`, keyed by the host's uuid, carrying the root's own
 * `_element` annotation (the words, the entity kind's markup, the field
 * facts; the annotator stamps them on the blueprint root), with the layout
 * as its children. daedalus_stylish's selector matcher and its style stack
 * read the document; nothing else does, and the entity node never enters
 * `layout` (layoutOf() reads it back out for the walkers that want the
 * sections at the top). The PHP twin is NodeTreeBuilder::document()
 * (daedalus_blueprint): the two answer the same shape for the same
 * blueprint.
 *
 * The root's uuid is not on the blueprint: the serializer emits no root
 * identity, so it travels beside the blueprint on the envelope (the mirror's
 * `root`, read through getRoot(); the host's uuid() on the server), and the
 * caller hands it in. A host with no uuid (null) still gets the root node,
 * keyless: the matcher treats a node without a uuid as structure nothing
 * addresses, so the sections keep their ancestry and nothing names the root.
 *
 * @param {?Object} blueprint
 *   A delivered blueprint (Drupal.Daedalus.Blueprint.getBlueprint()), or
 *   null before the mirror boots.
 * @param {?string} rootUuid
 *   The host's uuid (Drupal.Daedalus.Blueprint.getRoot()?.uuid), or null.
 *
 * @return {Array<object>}
 *   `[{kind: 'entity', uuid, _element, children}]`: `uuid` the root uuid
 *   given (null when none), `_element` the blueprint root's annotation as
 *   delivered (null when the blueprint is unannotated), `children` the
 *   blueprint's `layout` array itself (empty when the document has no
 *   sections). `[]` for a null blueprint.
 */
export const documentTree = (blueprint, rootUuid = null) => {
  if (!blueprint || typeof blueprint !== 'object') {
    return [];
  }
  return [
    {
      kind: 'entity',
      uuid: typeof rootUuid === 'string' && rootUuid !== '' ? rootUuid : null,
      _element: blueprint._element ?? null,
      children: Array.isArray(blueprint.layout) ? blueprint.layout : [],
    },
  ];
};

/**
 * The layout a document holds: the entity root's children, the sections
 * at the top.
 *
 * The inverse read of documentTree(), for a reader that holds the
 * document and needs the serializer's shape back (a depth read such as
 * variant-scope.js isNestedSectionUuid, which asks whether a section
 * sits below the top level and would read every section as nested
 * under the entity root; a satellite deriving its layout from the one
 * broadcast document). The array is the very `layout` the document was
 * derived from, never a copy. `[]` for an empty document (a null
 * blueprint's) and for anything whose root is not the entity node.
 *
 * @param {?Array<object>} document
 *   A document tree (documentTree()).
 *
 * @return {Array<object>}
 *   The layout: the sections at the top.
 */
export const layoutOf = (document) => {
  const root = Array.isArray(document) ? document[0] : null;
  return root?.kind === 'entity' && Array.isArray(root.children) ? root.children : [];
};
