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
 *   The point, the surface's size and the viewport it must fit.
 * @param {number} spec.x
 *   The preferred left edge, in viewport pixels.
 * @param {number} spec.y
 *   The preferred top edge, in viewport pixels.
 * @param {number} spec.width
 *   The measured width of the surface.
 * @param {number} spec.height
 *   The measured height of the surface.
 * @param {number} spec.viewportWidth
 *   The viewport's width.
 * @param {number} spec.viewportHeight
 *   The viewport's height.
 * @param {number} [spec.margin]
 *   The gutter the surface never crosses at any viewport edge; 4 by
 *   default.
 *
 * @return {{left: number, top: number}}
 *   The surface's clamped viewport top-left.
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
 * Places the surface against an anchor's rect: on the preferred side
 * ('below' by default), flipped to the other side only when the
 * preferred one is short and the other fits; clamped either way.
 *
 * `side` is the vertical intent: 'below' hangs the surface under the
 * anchor (a menu under a chip, the default), 'above' hangs it over the
 * anchor (a widget popover over a selection chip, which must never
 * cover the editing surface beneath the chip). The flip is symmetric:
 * a surface that fits neither side stays on the preferred one and the
 * clamp pulls it into the viewport.
 *
 * `align` is the horizontal intent, an explicit part of the open() call
 * and never inferred from layout: 'start' aligns the surface's left edge
 * to the anchor's left edge (a menu under a chip), 'end' aligns the
 * right edges (a kebab at the end of a panel header, whose surface must
 * hang inward over the panel, not outward over the canvas). The chrome
 * reads left-to-right, so start is the left edge.
 *
 * @param {object} spec
 *   The anchor, the surface's size, the viewport and the intents.
 * @param {{left: number, right: number, top: number, bottom: number}} spec.rect
 *   The anchor's viewport rect.
 * @param {number} spec.width
 *   The measured width of the surface.
 * @param {number} spec.height
 *   The measured height of the surface.
 * @param {number} spec.viewportWidth
 *   The viewport's width.
 * @param {number} spec.viewportHeight
 *   The viewport's height.
 * @param {string} [spec.side]
 *   The vertical intent, 'below' (the default) or 'above'.
 * @param {string} [spec.align]
 *   The horizontal intent, 'start' (the default) or 'end'.
 * @param {number} [spec.gap]
 *   The breathing room between the anchor and the surface; 2 by default.
 * @param {number} [spec.margin]
 *   The gutter the surface never crosses at any viewport edge; 4 by
 *   default.
 *
 * @return {{left: number, top: number}}
 *   The surface's viewport top-left, on the side it landed and clamped.
 */
export const anchoredPosition = ({
  rect,
  width,
  height,
  viewportWidth,
  viewportHeight,
  side = 'below',
  align = 'start',
  gap = 2,
  margin = 4,
}) => {
  if (side !== 'below' && side !== 'above') {
    throw new Error(`A surface sides 'below' or 'above', not "${side}".`);
  }
  if (align !== 'start' && align !== 'end') {
    throw new Error(`A surface aligns 'start' or 'end', not "${align}".`);
  }
  const fits = {
    below: rect.bottom + gap + height + margin <= viewportHeight,
    above: rect.top - gap - height >= margin,
  };
  const other = side === 'below' ? 'above' : 'below';
  const landing = !fits[side] && fits[other] ? other : side;
  const y = landing === 'above' ? rect.top - gap - height : rect.bottom + gap;
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

/**
 * Places a menu's flyout (a submenu's list) beside its row: to the right
 * of the row by default, flipped to the left only when the right would
 * leave the viewport and the left fits, else clamped like any surface;
 * its top aligned so the flyout's first row sits level with the submenu
 * row (`inset` is the list padding the flyout wears above its first
 * row), pulled up when it would run off the bottom and never past the
 * top margin. Horizontal only ever flips, never clamps into a position
 * covering the row, unless neither side fits (an oversized flyout pins
 * to the left margin).
 *
 * @param {object} spec
 *   The row, the flyout's size, the viewport and the spacing.
 * @param {{left: number, right: number, top: number, bottom: number}} spec.rect
 *   The submenu row's viewport rect.
 * @param {number} spec.width
 *   The measured width of the flyout.
 * @param {number} spec.height
 *   The measured height of the flyout.
 * @param {number} spec.viewportWidth
 *   The viewport's width.
 * @param {number} spec.viewportHeight
 *   The viewport's height.
 * @param {number} [spec.gap]
 *   The breathing room between the row and the flyout; 0 by default.
 * @param {number} [spec.margin]
 *   The gutter the flyout never crosses at any viewport edge; 4 by
 *   default.
 * @param {number} [spec.inset]
 *   The list padding the flyout wears above its first row; 4 by default.
 *
 * @return {{left: number, top: number, side: string}}
 *   The flyout's viewport top-left and which side of the row it took
 *   ('right' or 'left').
 */
export const flyoutPosition = ({
  rect,
  width,
  height,
  viewportWidth,
  viewportHeight,
  gap = 0,
  margin = 4,
  inset = 4,
}) => {
  const fits = {
    right: rect.right + gap + width + margin <= viewportWidth,
    left: rect.left - gap - width >= margin,
  };
  const side = !fits.right && fits.left ? 'left' : 'right';
  const x = side === 'left' ? rect.left - gap - width : rect.right + gap;
  const position = clampPosition({
    x,
    y: rect.top - inset,
    width,
    height,
    viewportWidth,
    viewportHeight,
    margin,
  });
  return { ...position, side };
};
