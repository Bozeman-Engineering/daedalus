/**
 * The floating surface's placement math: where a measured surface lands
 * in a viewport, for both of the primitive's placements (at a pointer, or
 * against an anchor's rect). Pure (no DOM, no Drupal globals) so every
 * case is node-testable; the plain-DOM host in popover.js measures the
 * surface hidden and calls in here before revealing it.
 *
 * Coordinates are viewport (fixed) pixels. `margin` is the gutter the
 * surface never crosses at any viewport edge; `gap` is the breathing
 * room between an anchor and its surface.
 */

/**
 * Clamps a top-left so the surface fits the viewport: prefer opening
 * right-and-down from the point, pull back at the right/bottom edges,
 * never past the top-left margin (an oversized surface pins there).
 *
 * @param {object} spec
 *   {x, y, width, height, viewportWidth, viewportHeight, margin?}.
 *
 * @return {{left: number, top: number}}
 */
export const clampPosition = ({
  x,
  y,
  width,
  height,
  viewportWidth,
  viewportHeight,
  margin = 4,
}) => ({
  left: Math.max(margin, Math.min(x, viewportWidth - width - margin)),
  top: Math.max(margin, Math.min(y, viewportHeight - height - margin)),
});

/**
 * Places the surface against an anchor's rect: hanging below it, above
 * it when the viewport is short below but not above; clamped either way.
 *
 * `align` is the horizontal intent, an explicit part of the open() call
 * and never inferred from layout: 'start' aligns the surface's left edge
 * to the anchor's left edge (a menu under a chip), 'end' aligns the
 * right edges (a kebab at the end of a panel header, whose surface must
 * hang inward over the panel, not outward over the canvas). The chrome
 * reads left-to-right, so start is the left edge.
 *
 * @param {object} spec
 *   {rect: {left, right, top, bottom}, width, height, viewportWidth,
 *   viewportHeight, align?, gap?, margin?}.
 *
 * @return {{left: number, top: number}}
 */
export const anchoredPosition = ({
  rect,
  width,
  height,
  viewportWidth,
  viewportHeight,
  align = 'start',
  gap = 2,
  margin = 4,
}) => {
  if (align !== 'start' && align !== 'end') {
    throw new Error(`A surface aligns 'start' or 'end', not "${align}".`);
  }
  const fitsBelow = rect.bottom + gap + height + margin <= viewportHeight;
  const fitsAbove = rect.top - gap - height >= margin;
  const y =
    !fitsBelow && fitsAbove ? rect.top - gap - height : rect.bottom + gap;
  const x = align === 'end' ? rect.right - width : rect.left;
  return clampPosition({
    x,
    y,
    width,
    height,
    viewportWidth,
    viewportHeight,
    margin,
  });
};
