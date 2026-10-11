/**
 * The capability gate's pure model: what an actor may do to an element.
 *
 * The spine decides (daedalus_blueprint's capability resolver, enforced
 * at the op door) and publishes its verdict with every read: the
 * annotator copies it onto every owning node as `_element.can`, the five
 * capabilities (content, configure, style, arrange, insert) to booleans,
 * and the projection carries it onto every row, field rows and parts
 * inheriting their element's. Nothing on the client interprets a cause
 * (ownership, a translation, a workspace, a role) itself: every gesture
 * (verbs, drags, drops, rename, paste, the inspector) asks this one gate,
 * and a key with no published verdict (no projection row, or a mirror not
 * yet booted) is refused everything, the same closed default the op door
 * applies to an element it cannot locate.
 *
 * This file is the pure model, imported by the stylish satellite bundle
 * too (no store, no index); the live read over the projection index is
 * capable.js beside it.
 */

/**
 * The five capabilities, the spine's closed vocabulary in its order.
 */
export const CAPABILITIES = ['content', 'configure', 'style', 'arrange', 'insert'];

/**
 * Whether a value is one of the five capabilities.
 *
 * @param {*} capability
 *
 * @return {boolean}
 */
export const isCapability = (capability) => CAPABILITIES.includes(capability);

/**
 * The subject a row-less field key belongs to, as the key the index places.
 *
 * The root row for a root field (`field:{root}:{name}`), the placement
 * for a block field (`block:{uuid}:{name}`); null for a key that is no
 * field key. A field the projection folds (a root field on a Layout
 * Builder display a theme prints anyway, a display-hidden block field, the
 * one field of a unit block) has no row of its own, but the op door judges
 * a write to it as content of its subject, so the gate answers the
 * subject's verdict: the same inheritance the index applies to the field
 * rows it does place. The re-seat (page-elements/reseat.js) reads the same
 * subject for a selected key a view flip left in no index.
 *
 * @param {string} key
 *   A selectable key.
 *
 * @return {?string}
 */
export const subjectKeyOf = (key) => {
  const [kind, uuid, ...name] = key.split(':');
  if (!uuid || name.length === 0) {
    return null;
  }
  if (kind === 'field') {
    return `entity:${uuid}`;
  }
  return kind === 'block' ? `block:${uuid}` : null;
};

/**
 * The verdict recorded for an address in a projection index, or null.
 *
 * A part address (`key::selector`) answers its owning element's verdict;
 * a field key the index places no row for answers its subject's
 * (subjectKeyOf).
 *
 * @param {?Map<string, {can?: ?object}>} index
 *   The projection index, or null before the mirror boots.
 * @param {?string} key
 *   The selectable key or part address.
 *
 * @return {?Object<string, boolean>}
 *   The five capabilities to booleans, or null when the index places no
 *   verdict for the address.
 */
export const verdictIn = (index, key) => {
  if (!index || !key) {
    return null;
  }
  const entry = index.get(key);
  if (entry) {
    return entry.can ?? null;
  }
  const at = key.indexOf('::');
  if (at !== -1) {
    return verdictIn(index, key.slice(0, at));
  }
  const subject = subjectKeyOf(key);
  return subject ? (index.get(subject)?.can ?? null) : null;
};

/**
 * Whether the verdict a projection index records for an address allows a
 * capability.
 *
 * @param {?Map<string, {can?: ?object}>} index
 *   The projection index, or null before the mirror boots.
 * @param {?string} key
 *   The selectable key or part address.
 * @param {string} capability
 *   One of CAPABILITIES.
 *
 * @return {boolean}
 *   True only when the index records the verdict and it allows the
 *   capability; false for an unknown address.
 */
export const capableIn = (index, key, capability) =>
  verdictIn(index, key)?.[capability] === true;

/**
 * Whether the verdict a projection index records for an address allows
 * anything at all: the one definition of SELECTABLE.
 *
 * An element the actor can do nothing to is not a selection target
 * (Tim, 2026-10-01): a selection the user can act on nowhere is a dead
 * end, and a frozen page should read as a form in place. The page
 * elements panel still shows the element (inert, still expandable), and
 * everything that lands a selection (the pointer chain, the editing
 * gate, reach, commitAddress, the store's selection gate) asks here. A
 * part address answers its owning element's verdict, and an address
 * with no verdict is not selectable, the same closed default as
 * capableIn.
 *
 * @param {?Map<string, {can?: ?object}>} index
 *   The projection index, or null before the mirror boots.
 * @param {?string} key
 *   The selectable key or part address.
 *
 * @return {boolean}
 */
export const selectableIn = (index, key) => {
  const verdict = verdictIn(index, key);
  return (
    !!verdict && CAPABILITIES.some((capability) => verdict[capability] === true)
  );
};

/**
 * The verdict recorded on a lean node tree by element uuid, or null.
 *
 * For readers holding the delivered `layout` and no index (the stylish
 * satellite window): the node's own `_element.can`. The root and the
 * host's fields are not tree nodes; such a reader answers them from the
 * root's verdict it holds beside the tree.
 *
 * @param {?Array} tree
 *   The lean node tree (`blueprint.layout`).
 * @param {?string} uuid
 *   The element uuid (a selectable key's second segment).
 *
 * @return {?Object<string, boolean>}
 */
export const verdictInTree = (tree, uuid) => {
  if (!uuid) {
    return null;
  }
  const find = (nodes) => {
    for (const node of nodes ?? []) {
      if (node.uuid === uuid) {
        return node._element?.can ?? null;
      }
      const nested = find(node.children);
      if (nested) {
        return nested;
      }
    }
    return null;
  };
  return find(tree);
};

/**
 * Whether a lean node tree's verdict on an element allows a capability.
 *
 * @param {?Array} tree
 *   The lean node tree.
 * @param {?string} uuid
 *   The element uuid.
 * @param {string} capability
 *   One of CAPABILITIES.
 *
 * @return {boolean}
 */
export const capableInTree = (tree, uuid, capability) =>
  verdictInTree(tree, uuid)?.[capability] === true;
