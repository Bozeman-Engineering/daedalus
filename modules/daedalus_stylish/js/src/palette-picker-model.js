/**
 * The palette grid's pure half: the shared grid's grouping and its
 * listbox navigation. No JSX here so node tests import it directly
 * (palette-picker-model.test.mjs); the rendering half (PaletteGrid, the
 * popover posture) lives in palette-picker.js. The grid consumes the
 * paint-carrying stop shape every value-space derivation produces
 * (widget-values.js propertyStops / gradientStops); it derives no offer
 * of its own.
 */

/**
 * The listbox's roving-tabindex home: the stop that carries tabindex 0
 * before arrow keys have moved focus. The lit stop when one is (focus
 * lands on the current value, the native select's posture), the first
 * stop otherwise, null for an empty offer.
 *
 * @param {Array<string>} ids
 *   The stop ids in offer order.
 * @param {?string} selected
 *   The lit stop's id, or null.
 *
 * @return {?string}
 */
export const listboxHome = (ids, selected = null) =>
  selected !== null && ids.includes(selected) ? selected : (ids[0] ?? null);

/**
 * The listbox's navigation map: the stop a key lands on from the current
 * one. Arrows step the flat offer order in both axes (the chips wrap
 * visually, so vertical and horizontal arrows both mean next/previous;
 * groups are visual sections, not navigation boundaries), Home/End jump,
 * and the ends clamp rather than wrap (the native select's posture the
 * grid replaced). Null for keys the listbox does not own, so the caller
 * lets them through unhandled.
 *
 * @param {Array<string>} ids
 *   The stop ids in offer order.
 * @param {?string} currentId
 *   The stop currently carrying focus (listboxHome before any movement).
 * @param {string} key
 *   The KeyboardEvent key.
 *
 * @return {?string}
 */
export const listboxTarget = (ids, currentId, key) => {
  if (ids.length === 0) {
    return null;
  }
  const index = currentId !== null ? ids.indexOf(currentId) : -1;
  switch (key) {
    case 'ArrowRight':
    case 'ArrowDown':
      return index === -1 ? ids[0] : ids[Math.min(index + 1, ids.length - 1)];
    case 'ArrowLeft':
    case 'ArrowUp':
      return index === -1 ? ids[0] : ids[Math.max(index - 1, 0)];
    case 'Home':
      return ids[0];
    case 'End':
      return ids[ids.length - 1];
    default:
      return null;
  }
};

/**
 * The grid's sections: stops grouped by their palette-side `group` label,
 * first-seen order (the stop tables are already in offer order: scales in
 * declaration order, then swatch kinds, then gradient kinds).
 *
 * Stops without a group (an `allowed:` list's literals) collect under the
 * null label; a grid with ONLY the null group renders headerless: a
 * curated list is its own one section.
 *
 * @param {Array} stops
 *   The concatenated stop table (color stops, then gradient stops).
 *
 * @return {Array<{label: ?string, stops: Array}>}
 */
export const groupedStops = (stops) => {
  const groups = [];
  const index = new Map();
  stops.forEach((stop) => {
    const label = stop.group ?? null;
    if (!index.has(label)) {
      const group = { label, stops: [] };
      index.set(label, group);
      groups.push(group);
    }
    index.get(label).stops.push(stop);
  });
  return groups;
};
