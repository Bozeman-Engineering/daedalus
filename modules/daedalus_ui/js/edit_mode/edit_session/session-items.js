/**
 * The edit session's item model: the ordered, keyed list of editable
 * items the open edit walks (Tab), resolves (a press), and edits.
 *
 * The engine hands the session two raw lists (its items() member): the
 * canvas leaves in document order, and the panel's revealed widgets in
 * form order, each panel widget already keyed by the engine (the subject
 * whose form the panel shows is the engine's fact; the session never
 * parses a widget). The session merges them here into ONE ordered list of
 * entries {item, key, panel}, canvas first, then panel, so Tab from the
 * last canvas leaf lands on the first revealed widget and Shift+Tab walks
 * back across the seam.
 *
 * The key rule, one per kind:
 * - A canvas leaf is keyed by the session's own gate (keyFor, the
 *   projection gate in selection/resolver.js keyForEditableItem) over its
 *   presentation boundary. A leaf that is not its own boundary (inside a
 *   unit or merged root, which contributes exactly one stop) or whose
 *   committed key is outside the editing universe is no stop. Two leaves
 *   may share a key (a multi-value field's deltas under one wrapper): each
 *   is its own stop, as it is its own click.
 * - A panel widget is keyed by the engine, verbatim: the widget is revealed
 *   exactly because its field has no canvas leaf, so the projection gate
 *   (which answers "is this canvas leaf in the universe") has nothing to
 *   say about it. A widget the engine could not key is no stop, and a
 *   widget whose key a canvas leaf already owns yields to the leaf: one
 *   key, one editor.
 *
 * Pure: no DOM, no store. The session injects boundary and keyFor; the
 * tests drive it with plain objects.
 */

/**
 * Builds the session's item list from the engine's raw lists.
 *
 * @param {{canvas?: Element[], panel?: {item: Element, key: ?string}[]}} raw
 *   The engine's items(): canvas leaves in document order, panel widgets
 *   in form order with their keys.
 * @param {{boundary: Function, keyFor: Function}} rules
 *   - boundary: (item) => Element, the item's presentation boundary.
 *   - keyFor: (item) => ?string, the committed key for a boundary item, or
 *     null when it is outside the editing universe.
 *
 * @return {{item: Element, key: string, panel: boolean}[]}
 *   The ordered, keyed entries.
 */
export const sessionItems = ({ canvas = [], panel = [] } = {}, { boundary, keyFor }) => {
  const entries = [];
  const canvasKeys = new Set();
  canvas.forEach((item) => {
    if (boundary(item) !== item) {
      return;
    }
    const key = keyFor(item);
    if (key === null || key === undefined) {
      return;
    }
    canvasKeys.add(key);
    entries.push({ item, key, panel: false });
  });
  panel.forEach(({ item, key }) => {
    if (!key || canvasKeys.has(key)) {
      return;
    }
    entries.push({ item, key, panel: true });
  });
  return entries;
};

/**
 * The entry for an item, or null when the item is no stop.
 */
export const entryFor = (entries, item) =>
  entries.find((entry) => entry.item === item) ?? null;

/**
 * The entry `direction` steps from `from` in session order, or the first /
 * last entry when there is no reference point (no item, or an item that is
 * no stop). Null past either end, or when there are no entries.
 *
 * @param {{item: Element, key: string, panel: boolean}[]} entries
 *   The session's item list.
 * @param {?Element} from
 *   The item to step from.
 * @param {number} direction
 *   1 or -1.
 *
 * @return {?{item: Element, key: string, panel: boolean}}
 */
export const neighborEntry = (entries, from, direction) => {
  if (!entries.length) {
    return null;
  }
  const index = from ? entries.findIndex((entry) => entry.item === from) : -1;
  if (index === -1) {
    return direction === 1 ? entries[0] : entries[entries.length - 1];
  }
  return entries[index + direction] ?? null;
};
