/**
 * The slot pairing: a locked page's fills under the doors that declare them.
 *
 * A locked page (its bundle's template not overridable) owns no document of
 * its own; its own sections are its slot fills, stored as ROOT sections of
 * its layout field tagged with the slot they fill, and the lean tree prints
 * them as top-level containers after the template's, each carrying
 * `slot: <id>`. The door itself is the template's slot block: a block node
 * carrying `slot: <id>` (the slot it DECLARES), at any depth of the
 * template's tree. That shape is the spine's (the ownership policy, the
 * seed and narrow laws, the AI's read and write all rest on the fill being
 * the page's own root section) and nothing here changes it.
 *
 * The canvas nests regardless: the slot block renders its filler's section
 * inside the door's markup. The panel's two projections (projection.js,
 * compose.js) make the same picture from the mirror through this pairing:
 * a root container tagged `slot: X` mounts under the door declaring
 * `slot: X`, as the door's section-row child, and leaves the root list. A
 * door nothing fills composes as it did, a leaf. A fill whose slot no door
 * declares (the template stopped placing it) stays at the root, as the
 * lean tree prints it.
 *
 * Pure over the lean tree: the mirror carries both tags, the pairing reads
 * them and derives nothing else.
 */

/**
 * A block node declaring a slot: the template's slot block, the door.
 */
export const isDoor = (node) =>
  node?.kind === 'block' && typeof node.slot === 'string';

/**
 * A root container tagged with the slot it fills.
 */
export const isFill = (node) =>
  node?.kind === 'container' && typeof node.slot === 'string';

const declaredSlots = (nodes, slots) => {
  for (const node of nodes) {
    if (isDoor(node)) {
      slots.add(node.slot);
    }
    declaredSlots(node.children ?? [], slots);
  }
  return slots;
};

/**
 * Pairs the lean tree's root fills with their doors.
 *
 * @param {Array} layout
 *   The lean tree's root list (`blueprint.layout`).
 *
 * @return {{roots: Array, fillsOf: function(Object): Array}}
 *   `roots`: the root list without the paired fills, in order; `fillsOf`:
 *   a door node's fills, in root order (empty for any node that is no
 *   door or whose slot nothing fills).
 */
export const pairSlotFills = (layout) => {
  const list = layout ?? [];
  // Doors are declared by the template's nodes, never inside a fill (a
  // document never fills its own slot), so the fills are not searched.
  const slots = declaredSlots(list.filter((node) => !isFill(node)), new Set());
  const fills = new Map();
  const roots = [];
  for (const node of list) {
    if (isFill(node) && slots.has(node.slot)) {
      if (!fills.has(node.slot)) {
        fills.set(node.slot, []);
      }
      fills.get(node.slot).push(node);
    } else {
      roots.push(node);
    }
  }
  return {
    roots,
    fillsOf: (node) => (isDoor(node) ? (fills.get(node.slot) ?? []) : []),
  };
};
