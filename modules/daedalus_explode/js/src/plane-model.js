/**
 * The plane model, pure: the projection index plus the view settings and
 * the selection become the planes the stylesheet printer lifts.
 *
 * One reader, one map: every plane is an entry of daedalus_ui's
 * projection index (app.tree.index(), projection-index.js), so depth,
 * label, kind and ancestry can never disagree with the tree, the
 * breadcrumb or keyboard traversal. Nothing here touches the DOM or the
 * store; the host (T6) reads the index and the settings and hands them
 * in, and the node suite drives this file with a hand-built index.
 *
 * A plane is a REACHABLE entry (daedalus_ui's reach, app.tree.reachable,
 * reach.js: the entry exists and the Page elements panel currently
 * depicts its row) that is not a generated box. Explode lifts exactly
 * what the panel shows and has no depth switch of its own: an element
 * row is always depicted, an instance row (a rich-text carrier) always,
 * a declared shell part only while the Markup view is on. The host reads
 * reach at every print and hands the predicate in, so the node suite
 * drives this file with a hand-built index and a reach over it.
 *
 * Depth is the count of PLANE ancestors on the entry's rowParent chain:
 * the root is 0, a section 1, a block in it 2, a field in the block 3.
 * The rowParent chain (not `parent`) is the walk because a field claimed
 * into a shell part sits inside that shell's row, so with the shell
 * reachable it is a plane at owner depth + 1 and the field lifts above
 * it; with the shell unreachable (Markup off) the part rows are
 * transparent (they count for nothing) and the field's depth is its
 * element ancestry alone. A Markup flip therefore never moves an element
 * plane, it only adds the part planes between.
 *
 * The depth cap collapses every deeper plane onto the cap (its depth
 * becomes the cap; it stays lifted, so its content still sits on the
 * capped slab, but it loses its label: a stack of labels on one plane
 * reads as noise). Isolate keeps only the selection's lineage lifted, the
 * selected planes, their ancestors and their descendants; every other
 * plane is unlifted (flat, unlabeled). An isolate with nothing selected
 * in the index is inert: there is no lineage to keep, and hiding the
 * whole page would read as a broken view.
 *
 * Depth is where a plane sits; lift is what its element is told to do.
 * The printer transforms the real DOM under `transform-style:
 * preserve-3d`, where a child's translateZ composes with its transformed
 * ancestors' (a block translated by one gap inside a section translated
 * by one gap sits two gaps up), so the per-element translation is the
 * plane's depth MINUS its nearest plane ancestor's: one gap for an
 * ordinary child, zero for a plane collapsed onto the cap it shares with
 * its parent, zero for an unlifted plane (flat on whatever it sits in).
 * The nearest plane ancestor of a lifted plane is always lifted itself
 * (a lineage is closed upward), so lifts add up to depths exactly.
 *
 * A generated box (`&::before`, `&::after`: a part-tier row the index
 * carries under its owner, generated-rows.js) is never a plane, even
 * while the Markup view draws its row. A box has no element of its own,
 * so nothing could be measured, anchored or lifted for it; it is painted
 * on its host and lifts with the host's plane. The grammar
 * (relative-selector.js) names the box: a part row whose selector
 * carries a terminal pseudo-element.
 */
import { parseSelector } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * A plane: one entry of the index projected for the printer.
 *
 * @typedef {object} Plane
 * @property {string} address
 *   The index address: an element key or a `key::selector` part address.
 * @property {number} depth
 *   The effective lift depth (the plane-ancestor count, capped).
 * @property {string} label
 *   The index's label for the entry (what the element says; the host
 *   words a content label as its type through app.Blueprint.typeForKey).
 * @property {string} kind
 *   The entry's kind ('entity', 'container', 'block', 'field', 'part').
 * @property {'element'|'part'} tier
 *   The entry's tier.
 * @property {?string} owner
 *   A part plane's owning element key; null for an element plane.
 * @property {?string} selector
 *   A part plane's canonical relative selector under its owner (what the
 *   part behavior's selectorFor takes); null for an element plane.
 * @property {boolean} lifted
 *   Whether the plane lifts along Z (false outside an isolated lineage).
 * @property {number} lift
 *   The Z step the element itself takes, in gap units: its depth minus
 *   its nearest plane ancestor's (the translateZ composes with the
 *   ancestors' under preserve-3d); 0 when unlifted or at the root.
 * @property {boolean} labeled
 *   Whether the plane carries its label (labels on, lifted, not collapsed
 *   under the cap).
 */

/**
 * The depth cap as the model reads it: an integer of at least 1, else no
 * cap ("All").
 *
 * @param {*} value
 *   The settings' depthCap.
 *
 * @return {?number}
 */
const capOf = (value) =>
  Number.isInteger(value) && value >= 1 ? value : null;

/**
 * The selector a part address carries under its owner: the index's
 * `owner::selector` grammar (selectorAddress), so it is what follows.
 *
 * @param {string} address
 *   A part-tier index address.
 * @param {object} entry
 *   Its index entry (owner set).
 *
 * @return {string}
 */
const selectorOf = (address, entry) => address.slice(entry.owner.length + 2);

/**
 * Whether a part-tier entry is a generated box: its selector ends in a
 * pseudo-element by the grammar's reading.
 *
 * @param {string} address
 *   The index address.
 * @param {object} entry
 *   Its index entry.
 *
 * @return {boolean}
 */
const isBox = (address, entry) =>
  entry.tier === 'part' &&
  (parseSelector(selectorOf(address, entry))?.pseudo ?? null) !== null;

/**
 * Whether an index entry is a plane: reachable (the panel depicts its
 * row) and not a generated box.
 *
 * @param {string} address
 *   The index address.
 * @param {object} entry
 *   Its index entry.
 * @param {function(string): boolean} reach
 *   The reach predicate.
 *
 * @return {boolean}
 */
const isPlane = (address, entry, reach) =>
  reach(address) && !isBox(address, entry);

/**
 * The structural depths of every plane address, in the index's order
 * (pre-order over the tree, so a parent precedes its rows).
 *
 * @param {Map<string, object>} index
 *   The projection index.
 * @param {?string} root
 *   The projection root's key, where the ancestry walk ends.
 * @param {function(string): boolean} reach
 *   The reach predicate.
 *
 * @return {Map<string, number>}
 *   Address → plane-ancestor count, plane addresses only.
 */
const structuralDepths = (index, root, reach) => {
  const depths = new Map();
  // Every address's depth, planes and transparent rows alike: a
  // transparent row's depth is its nearest plane ancestor's, so the rows
  // under it read through it.
  const memo = new Map();
  const depthOf = (address) => {
    if (memo.has(address)) {
      return memo.get(address);
    }
    const entry = index.get(address);
    const above = entry?.rowParent ?? null;
    let depth = 0;
    if (address !== root && above !== null && index.has(above)) {
      depth =
        depthOf(above) + (isPlane(above, index.get(above), reach) ? 1 : 0);
    }
    memo.set(address, depth);
    return depth;
  };
  for (const [address, entry] of index) {
    if (isPlane(address, entry, reach)) {
      depths.set(address, depthOf(address));
    }
  }
  return depths;
};

/**
 * The nearest plane ancestor of an address on its rowParent chain, or
 * null (the root, or a plane with no plane above it).
 *
 * @param {Map<string, object>} index
 *   The projection index.
 * @param {Map<string, number>} depths
 *   The plane addresses (structuralDepths' keys).
 * @param {string} address
 *   The address whose ancestry to climb.
 *
 * @return {?string}
 */
const planeAbove = (index, depths, address) => {
  let at = index.get(address)?.rowParent ?? null;
  while (at !== null && index.has(at)) {
    if (depths.has(at)) {
      return at;
    }
    at = index.get(at).rowParent ?? null;
  }
  return null;
};

/**
 * The lineage an isolate keeps lifted: the selected plane addresses,
 * their plane ancestors and their plane descendants. Empty when no
 * selected key is a plane (the isolate is then inert).
 *
 * @param {Map<string, object>} index
 *   The projection index.
 * @param {Map<string, number>} depths
 *   The plane addresses (structuralDepths' keys).
 * @param {string[]} selection
 *   The selected keys.
 *
 * @return {Set<string>}
 */
const lineageOf = (index, depths, selection) => {
  const lineage = new Set();
  const climb = (address) => {
    let at = index.get(address)?.rowParent ?? null;
    while (at !== null && index.has(at)) {
      if (depths.has(at)) {
        lineage.add(at);
      }
      at = index.get(at).rowParent ?? null;
    }
  };
  const descend = (address) => {
    for (const row of index.get(address).rows) {
      if (depths.has(row)) {
        lineage.add(row);
      }
      descend(row);
    }
  };
  for (const key of selection) {
    if (!depths.has(key)) {
      continue;
    }
    lineage.add(key);
    climb(key);
    descend(key);
  }
  return lineage;
};

/**
 * The planes for an index under reach, the settings and the selection.
 *
 * @param {?Map<string, object>} index
 *   The projection index (app.tree.index()), or null before the mirror
 *   boots (no planes).
 * @param {?string} root
 *   The projection root's key (app.tree.root()), where the ancestry walk
 *   ends.
 * @param {function(string): boolean} reach
 *   The reach predicate (app.tree.reachable): address → whether the
 *   panel currently depicts its row. Decides which entries are planes.
 * @param {{depthCap?: ?number, isolate?: boolean, labels?: boolean}} settings
 *   The view settings the model reads: depthCap (an integer of at least
 *   1, or null for all), isolate and labels. The toggles read as given
 *   (absent is off); their defaults are the settings store's.
 * @param {string[]} selection
 *   The ordered multi-select set (app.Selection.getSelectedSet()); a key
 *   the index does not place is no plane and isolates nothing.
 *
 * @return {Plane[]}
 *   The planes in the index's order.
 */
export const planes = (index, root, reach, settings, selection = []) => {
  if (!index) {
    return [];
  }
  const cap = capOf(settings.depthCap);
  const labels = settings.labels === true;
  const depths = structuralDepths(index, root, reach);
  const lineage =
    settings.isolate === true ? lineageOf(index, depths, selection) : null;
  const isolated = lineage !== null && lineage.size > 0;
  const result = [];
  // Effective (capped) depth per plane, filled in the index's pre-order
  // so a plane's ancestor is always there before it.
  const effective = new Map();
  for (const [address, depth] of depths) {
    const entry = index.get(address);
    const collapsed = cap !== null && depth > cap;
    const lifted = !isolated || lineage.has(address);
    const owner = entry.tier === 'part' ? entry.owner : null;
    const capped = collapsed ? cap : depth;
    effective.set(address, capped);
    const above = planeAbove(index, depths, address);
    const lift = lifted
      ? capped - (above === null ? 0 : effective.get(above))
      : 0;
    result.push({
      address,
      depth: capped,
      label: entry.label,
      kind: entry.kind,
      tier: entry.tier,
      owner,
      selector: owner === null ? null : selectorOf(address, entry),
      lifted,
      lift,
      labeled: labels && lifted && !collapsed,
    });
  }
  return result;
};

/**
 * The deepest structural depth among the planes, ignoring the cap: the
 * top of the depth-cap control's range. Zero for an empty index. Reach
 * is the only input that changes the answer (a Markup flip adds or
 * removes the part planes).
 *
 * @param {?Map<string, object>} index
 *   The projection index, or null.
 * @param {?string} root
 *   The projection root's key.
 * @param {function(string): boolean} reach
 *   The reach predicate.
 *
 * @return {number}
 */
export const maxDepth = (index, root, reach) => {
  if (!index) {
    return 0;
  }
  let deepest = 0;
  for (const depth of structuralDepths(index, root, reach).values()) {
    deepest = Math.max(deepest, depth);
  }
  return deepest;
};
