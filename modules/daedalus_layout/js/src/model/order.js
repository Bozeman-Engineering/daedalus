/**
 * @file
 * Label order: the one order every set of the Add panel shows.
 *
 * Natural and case-insensitive, as core already sorts the block
 * categories' labels (strnatcasecmp): "Band 2" before "Band 10",
 * "image" beside "Image". Inside Favorites, inside each group, inside
 * each kind under All, and in a search's answer. Nothing is ordered by
 * hand and nothing carries a weight: at a hundred blueprints a
 * predictable order beats a curated one nobody maintains, and the
 * roster's order (provider, then entity id) means nothing to an editor.
 *
 * The one exception is a leading row: the Container leads Favorites. A
 * caller names the id that leads; every other row follows in label
 * order.
 *
 * The collator is the runtime's default locale, so a site's labels sort
 * by the editor's own rules; the ASCII cases every test pins hold in
 * every locale.
 *
 * Pure: no DOM, no Preact.
 */

const collator = new Intl.Collator(undefined, {
  numeric: true,
  sensitivity: 'base',
});

/**
 * Compares two labels, natural and case-insensitive.
 *
 * @param {*} a
 *   A label; anything is read as its string.
 * @param {*} b
 *   Another.
 *
 * @return {number}
 *   Negative, zero or positive, as a sort comparator answers.
 */
export const compareLabels = (a, b) =>
  collator.compare(String(a ?? ''), String(b ?? ''));

/**
 * The faces in label order, a named one leading.
 *
 * Stable: faces whose labels compare equal keep the order they came in.
 * Never mutates the list it is given.
 *
 * @param {Array<object>} faces
 *   Faces (anything with a `label` and an `id`).
 * @param {?string} leading
 *   The id that leads the list whatever its label, or null for none.
 *
 * @return {Array<object>}
 */
export const byLabel = (faces, leading = null) =>
  [...faces].sort(
    (a, b) =>
      (leading === null
        ? 0
        : Number(b.id === leading) - Number(a.id === leading)) ||
      compareLabels(a.label, b.label),
  );
