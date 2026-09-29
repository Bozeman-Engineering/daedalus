/**
 * The selection frame's pure model: where a member's chip goes and what
 * the chip holds. No DOM, no Drupal globals; the Preact host
 * (selection-frame.js) gathers the facts each frame and paints the
 * answer, so the reasoning here is node-testable on its own
 * (tests/js/selection-frame.test.mjs).
 */

/**
 * Where a member's chip sits.
 *
 * Decided in VIEWPORT space (the rect is a getBoundingClientRect) and
 * painted by the host in document space. The chip is a TAB on the
 * stroke: the stroke is an outline painted OUTWARD from the rect
 * (selection-frame.css), so the stroke's outer edges sit `stroke`
 * outside the rect, and the chip sits flush on them. Its outer left
 * edge aligns with the stroke's outer left edge and its bottom edge
 * touches the stroke's top edge, no gap; when there is no room above
 * (the top rail counts as no room), it flips BELOW the stroke's bottom
 * edge, never inside, because inside covers the very content the
 * selection is about. Horizontally the left edge is sacred: it always
 * sits on the stroke, even when a full-bleed element puts that a
 * hair under a rail, because a chip that aligns on some elements and
 * drifts on others reads as broken. The one exception is functional,
 * not cosmetic: a chip wider than a narrow element near the RIGHT
 * rail is pulled back into the canvas so its verbs stay reachable.
 *
 * @param {object} args
 *   - rect: the anchor's viewport rect {top, left, bottom, right}.
 *   - chip: the chip's measured {width, height}.
 *   - viewport: {width, height}.
 *   - offsets: Drupal.displace-style {top, right, bottom, left} of the
 *     fixed rails (defaults to none).
 *   - stroke: the stroke's outline width (default 2, matching
 *     .daedalus-ui-frame__stroke--primary in selection-frame.css); the chip
 *     offsets by it so it sits on the outline, not the rect.
 *   - gap: breathing room between the right rail and a pulled-back chip
 *     (default 4).
 *
 * @return {{left: number, top: number, below: boolean}}
 */
export const chipPlacement = ({
  rect,
  chip,
  viewport,
  offsets = {},
  stroke = 2,
  gap = 4,
}) => {
  const rail = { top: 0, right: 0, bottom: 0, left: 0, ...offsets };
  const above = rect.top - stroke - chip.height;
  const below = above < rail.top;
  const top = below ? rect.bottom + stroke : above;
  let left = rect.left - stroke;
  left = Math.min(left, viewport.width - rail.right - chip.width - gap);
  return { left, top, below };
};

/**
 * Whether the frame wears the editing dress — the dashed stroke: a LIVE
 * edit lands on the primary. One reading of the store's reactive
 * editingKey (the engine's editChanged report is its only writer),
 * never a prediction from editability — so canceling an edit (Escape)
 * restores the solid stroke the moment the engine reports the edit
 * ended, the editable primary still selected. The chip face never
 * dresses for the edit: the
 * registry's applies gate swaps its verbs instead (chipFace).
 *
 * @param {object} args
 *   - editingKey: the key the live edit lands on (null: no open edit).
 *   - primary: the primary member's key (null: no selection).
 *
 * @return {boolean}
 */
export const frameEditing = ({ editingKey = null, primary = null }) =>
  primary !== null && editingKey === primary;

/**
 * What one member's chip holds: its set-order number, and (on the
 * primary) the verb face.
 *
 * The number is the member's position in the ordered set, shown only for
 * a real set (two or more): the same order the indicator chips and the
 * AI chat's chips render, so any surface can name a member to any other.
 *
 * Verbs follow the chrome-density ladder, each tier strictly less
 * chrome than the last: at FULL density every applicable verb sits on
 * the face in menu order as icon plus text label, chevron unrendered;
 * MINIMAL is the same face with the labels dropped (icons only);
 * CLEAN is the partition by each verb's own `tab` flag (verbs.js):
 * flagged verbs are the face, everything else waits behind the
 * chevron's context menu, and nothing appears in both. The number and
 * name render at every tier: identity must stay readable or a
 * multi-select set turns unreadable again. Only the primary's chip
 * carries verbs, and the face never hides for an open edit: WHICH
 * verbs it holds is the registry's applies gate (verbs.js appliesTo —
 * selection verbs while no live edit lands on the key, edit-scoped
 * verbs while the store's editingKey IS the key), so mid-edit the face
 * shows the edit's own verbs and the frame's only editing dress is the
 * dashed stroke (frameEditing). Neither the edit state nor a prediction ever
 * hides what you can do.
 *
 * @param {object} args
 *   - key: the member this chip is for.
 *   - keys: the ordered selection set.
 *   - primary: the primary member's key.
 *   - verbs: the set's applicable verbs (verbsForSet), menu-ordered;
 *     the registry has already scoped them to the live edit state.
 *   - density: the chrome-density tier (full | minimal | clean;
 *     absent reads as full, like the html attribute).
 *
 * @return {{number: ?number, primary: boolean, labels: boolean, faceVerbs: object[], overflow: object[]}}
 */
export const chipFace = ({
  key,
  keys = [],
  primary = null,
  verbs = [],
  density = 'full',
}) => {
  const isPrimary = key === primary;
  const partitioned = density === 'clean';
  return {
    number: keys.length > 1 ? keys.indexOf(key) + 1 : null,
    primary: isPrimary,
    labels: density !== 'minimal' && density !== 'clean',
    faceVerbs: isPrimary
      ? verbs.filter((verb) => !partitioned || verb.tab)
      : [],
    overflow: isPrimary && partitioned ? verbs.filter((verb) => !verb.tab) : [],
  };
};
