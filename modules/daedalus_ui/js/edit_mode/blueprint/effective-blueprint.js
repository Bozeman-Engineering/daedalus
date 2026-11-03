import { warnDraftKeyUnknown } from '../utilities/warn';

/**
 * The effective blueprint: the mirror with the open gesture's draft laid
 * over the one node being edited.
 *
 * The blueprint slice keeps two fields apart on purpose. `blueprint` is
 * the mirror, the server's last word, never written by a client. `draft`
 * is the open gesture's resolved markup for ONE selectable key: what the
 * element resolver's client twin computed for the previewed property bag
 * (the tag picked, the link typed) and morphed the canvas to, handed to
 * the store through the facade's draftMarkup door. The next mirror
 * replacement (the gesture's own commit landing, or any other mutation
 * response) clears it: server truth supersedes the draft.
 *
 * Every reader of the tree reads THIS selector, never the raw mirror: the
 * markup index, the projection index, the page-elements tree, the
 * selection chrome, the facade's getBlueprint and onBlueprintChange. A
 * reader therefore follows a gesture live (the panel row's tag fact, the
 * inspector's part offering, the write address) while the raw mirror
 * stays distinguishable as committed truth for the slice and the op queue.
 *
 * The overlay is a PATH COPY: the tree is shared by reference except
 * along the path from the root to the drafted node, and only that node's
 * `_element.markup` is replaced with `{...committed, ...draft.markup}`, a
 * shallow merge over the markup annotation alone. Nothing else on the node
 * changes (not `name`, not `fields`, not the lean data), so a structural
 * draft keeps the committed rich-text facts (`instances`, `tags`) and a
 * future formatted-text draft can ride the same slot.
 *
 * Memoized on the (mirror, draft) pair: an unchanged state returns the
 * same reference, so reference equality stays the app's change detection
 * (the indexes rebuild once per preview, useSelector re-renders once).
 * With no draft the mirror reference itself is the answer.
 *
 * Key forms: `entity:{root uuid}` overlays the root's `_element`;
 * `block:{uuid}` and `container:{uuid}` resolve through the lean node tree
 * (`layout`) at any depth, region nodes transparent. A draft whose key the
 * tree does not hold (the edited node left the page under a foreign
 * mutation and the resumed gesture re-previewed) yields the mirror
 * reference and warns once per (mirror, draft) pair, never throws: the
 * honest answer is the committed tree.
 */

let memo = { mirror: null, draft: null, effective: null };

/**
 * The drafted node's annotation with the draft merged over its markup.
 */
const overlayElement = (element, markup) => ({
  ...(element ?? {}),
  markup: { ...(element?.markup ?? {}), ...markup },
});

/**
 * Path-copies the lean node tree down to the node the key names, or
 * returns null when no node matches (the caller keeps the mirror).
 *
 * A `container:` key matches a container node of that uuid; a `block:`
 * key matches a block node (any non-container, non-region node) of that
 * uuid. Region nodes are transparent grouping: never a match, always
 * walked through.
 */
const overlayTree = (nodes, family, uuid, markup) => {
  if (!Array.isArray(nodes)) {
    return null;
  }
  for (let i = 0; i < nodes.length; i++) {
    const node = nodes[i];
    if (!node || typeof node !== 'object') {
      continue;
    }
    const isContainer = node.kind === 'container';
    const isRegion = node.kind === 'region';
    const matches =
      node.uuid === uuid &&
      !isRegion &&
      (family === 'container' ? isContainer : !isContainer);
    let replaced = null;
    if (matches) {
      replaced = { ...node, _element: overlayElement(node._element, markup) };
    } else if (isContainer || isRegion) {
      const children = overlayTree(node.children, family, uuid, markup);
      if (children) {
        replaced = { ...node, children };
      }
    }
    if (replaced) {
      const copy = nodes.slice();
      copy[i] = replaced;
      return copy;
    }
  }
  return null;
};

/**
 * The mirror with the draft laid over the drafted node, or null when the
 * draft's key names no node of the tree.
 */
const overlay = (mirror, root, draft) => {
  const segments = typeof draft.key === 'string' ? draft.key.split(':') : [];
  if (segments.length !== 2) {
    return null;
  }
  const [family, uuid] = segments;
  if (family === 'entity') {
    return root?.uuid && uuid === root.uuid
      ? { ...mirror, _element: overlayElement(mirror._element, draft.markup) }
      : null;
  }
  if (family !== 'block' && family !== 'container') {
    return null;
  }
  const layout = overlayTree(mirror.layout, family, uuid, draft.markup);
  return layout ? { ...mirror, layout } : null;
};

/**
 * The effective blueprint for a store state.
 *
 * @param {Object} state
 *   The root store state (the blueprint slice under `blueprint`).
 *
 * @return {?Object}
 *   The mirror reference when there is no draft (null before boot);
 *   otherwise the memoized path-copied tree carrying the draft.
 */
export const effectiveBlueprint = (state) => {
  const { blueprint: mirror, root, draft } = state.blueprint;
  if (!mirror || !draft) {
    return mirror;
  }
  if (memo.mirror === mirror && memo.draft === draft) {
    return memo.effective;
  }
  let effective = overlay(mirror, root, draft);
  if (!effective) {
    warnDraftKeyUnknown(draft.key);
    effective = mirror;
  }
  memo = { mirror, draft, effective };
  return effective;
};
