/**
 * Pure geometry for the canvas spacing bands.
 *
 * Turns measured rects and digested computed values into the rectangles
 * the band chrome paints: per-side padding bands inside an element's
 * border box, and the gap strips between the child boxes a container lays
 * out. No DOM, no imports — node-tested in tests/js/band-geometry.test.mjs.
 *
 * Rects everywhere are plain {left, top, width, height} (DOMRect
 * compatible); sides/gaps are the computed-read.js digests.
 */

/**
 * The four padding bands of an element, from its border-box rect.
 *
 * Corners belong to the top and bottom bands (full content width); the
 * left and right bands fill the middle, so the four never overlap and a
 * multi-side tint (Alt+Shift = all four) paints a clean ring.
 *
 * @param {{left: number, top: number, width: number, height: number}} rect
 *   The element's border-box rect (getBoundingClientRect).
 * @param {{top: number, right: number, bottom: number, left: number}} border
 *   Computed border widths px (borderPx) — padding starts inside them.
 * @param {{top: number, right: number, bottom: number, left: number}} padding
 *   Computed padding px (paddingPx).
 *
 * @return {Object<string, {left: number, top: number, width: number,
 *   height: number}>}
 *   Band rect per side. A zero-padding side yields a zero-thickness rect —
 *   callers skip empties rather than every consumer re-deriving "is it
 *   set".
 */
export const paddingBandRects = (rect, border, padding) => {
  const inner = {
    left: rect.left + border.left,
    top: rect.top + border.top,
    width: Math.max(0, rect.width - border.left - border.right),
    height: Math.max(0, rect.height - border.top - border.bottom),
  };
  const middleTop = inner.top + padding.top;
  const middleHeight = Math.max(0, inner.height - padding.top - padding.bottom);
  return {
    top: {
      left: inner.left,
      top: inner.top,
      width: inner.width,
      height: padding.top,
    },
    bottom: {
      left: inner.left,
      top: inner.top + inner.height - padding.bottom,
      width: inner.width,
      height: padding.bottom,
    },
    left: {
      left: inner.left,
      top: middleTop,
      width: padding.left,
      height: middleHeight,
    },
    right: {
      left: inner.left + inner.width - padding.right,
      top: middleTop,
      width: padding.right,
      height: middleHeight,
    },
  };
};

/**
 * The four margin bands of an element, outside its border-box rect.
 *
 * Same corner convention as paddingBandRects: corners belong to the top
 * and bottom bands (full margin-box width), left and right fill the
 * border-box height, so the four never overlap and a multi-side tint
 * paints a clean ring.
 *
 * A side digested as null (auto or a non-length read) or negative
 * renders as a zero-thickness rect: a negative margin pulls the box
 * instead of occupying space, and an auto margin's space is not the
 * authored value's to claim — the band paints nothing rather than lie.
 * An absent side also claims no corners: the top/bottom bands only span
 * the margins that actually render.
 *
 * @param {{left: number, top: number, width: number, height: number}} rect
 *   The element's border-box rect (getBoundingClientRect).
 * @param {{top: ?number, right: ?number, bottom: ?number, left: ?number}} margin
 *   Computed margin px (marginPx digest: signed, null for non-lengths).
 *
 * @return {Object<string, {left: number, top: number, width: number,
 *   height: number}>}
 *   Band rect per side. Zero-thickness rects for absent sides — callers
 *   skip empties, exactly as with paddingBandRects.
 */
export const marginBandRects = (rect, margin) => {
  const band = (value) => (typeof value === 'number' && value > 0 ? value : 0);
  const top = band(margin.top);
  const right = band(margin.right);
  const bottom = band(margin.bottom);
  const left = band(margin.left);
  const outerLeft = rect.left - left;
  const outerWidth = rect.width + left + right;
  return {
    top: {
      left: outerLeft,
      top: rect.top - top,
      width: outerWidth,
      height: top,
    },
    bottom: {
      left: outerLeft,
      top: rect.top + rect.height,
      width: outerWidth,
      height: bottom,
    },
    left: { left: outerLeft, top: rect.top, width: left, height: rect.height },
    right: {
      left: rect.left + rect.width,
      top: rect.top,
      width: right,
      height: rect.height,
    },
  };
};

/**
 * The four border bands of an element: the ring between its border-box
 * rect and its padding box.
 *
 * Same corner convention as paddingBandRects: corners belong to the top
 * and bottom bands (full border-box width), left and right fill the
 * height between them, so the four never overlap and a multi-side tint
 * paints a clean ring. Border is VISIBLE geometry (it paints itself),
 * but the highlight still names the side a ring cell edits: a
 * currentColor hairline on a same-hued ground, or a side whose width is
 * set while its style is none, shows nothing of its own.
 *
 * @param {{left: number, top: number, width: number, height: number}} rect
 *   The element's border-box rect (getBoundingClientRect).
 * @param {{top: number, right: number, bottom: number, left: number}} border
 *   Computed border widths px (borderPx).
 *
 * @return {Object<string, {left: number, top: number, width: number,
 *   height: number}>}
 *   Band rect per side. Zero-thickness rects for borderless sides —
 *   callers skip empties, exactly as with paddingBandRects.
 */
export const borderBandRects = (rect, border) => {
  const middleTop = rect.top + border.top;
  const middleHeight = Math.max(0, rect.height - border.top - border.bottom);
  return {
    top: {
      left: rect.left,
      top: rect.top,
      width: rect.width,
      height: border.top,
    },
    bottom: {
      left: rect.left,
      top: rect.top + rect.height - border.bottom,
      width: rect.width,
      height: border.bottom,
    },
    left: {
      left: rect.left,
      top: middleTop,
      width: border.left,
      height: middleHeight,
    },
    right: {
      left: rect.left + rect.width - border.right,
      top: middleTop,
      width: border.right,
      height: middleHeight,
    },
  };
};

/**
 * Clusters child rects into visual rows: rects whose vertical extents
 * overlap share a row (a flex row, a grid row, one wrapped line).
 *
 * @param {Array<{left: number, top: number, width: number, height: number}>} rects
 *
 * @return {Array<{top: number, bottom: number, left: number, right: number,
 *   members: Array}>}
 *   Rows top-to-bottom, each with its members and union extents.
 */
export const rowClusters = (rects) => {
  const sorted = [...rects].sort((a, b) => a.top - b.top);
  const rows = [];
  sorted.forEach((rect) => {
    const row = rows[rows.length - 1];
    // Overlap test with a half-pixel tolerance for subpixel layout.
    if (row && rect.top < row.bottom - 0.5) {
      row.members.push(rect);
      row.top = Math.min(row.top, rect.top);
      row.bottom = Math.max(row.bottom, rect.top + rect.height);
      row.left = Math.min(row.left, rect.left);
      row.right = Math.max(row.right, rect.left + rect.width);
    } else {
      rows.push({
        top: rect.top,
        bottom: rect.top + rect.height,
        left: rect.left,
        right: rect.left + rect.width,
        members: [rect],
      });
    }
  });
  return rows;
};

/**
 * The gap strips between a container's child boxes.
 *
 * Row strips run horizontally between consecutive rows; column strips run
 * vertically between adjacent members within a row. Each strip is as thick
 * as the computed gap, centered in the actual space between the boxes —
 * justify-content distribution can widen the space beyond the gap, and the
 * tint claims only what gap owns. A zero computed gap paints nothing on
 * that axis: space between children is not necessarily gap, and the bands
 * never lie.
 *
 * @param {Array<{left: number, top: number, width: number, height: number}>} childRects
 *   The container's flow-child rects (flowChildRects).
 * @param {{row: number, column: number}} gaps
 *   Computed gap px (gapPx).
 *
 * @return {Array<{left: number, top: number, width: number, height: number}>}
 */
export const gapStripRects = (childRects, gaps) => {
  const strips = [];
  const rows = rowClusters(childRects);

  if (gaps.row > 0) {
    for (let i = 1; i < rows.length; i++) {
      const above = rows[i - 1];
      const below = rows[i];
      const space = below.top - above.bottom;
      if (space <= 0.5) {
        continue;
      }
      const thickness = Math.min(space, gaps.row);
      strips.push({
        left: Math.min(above.left, below.left),
        top: above.bottom + (space - thickness) / 2,
        width:
          Math.max(above.right, below.right) - Math.min(above.left, below.left),
        height: thickness,
      });
    }
  }

  if (gaps.column > 0) {
    rows.forEach((row) => {
      const members = [...row.members].sort((a, b) => a.left - b.left);
      for (let i = 1; i < members.length; i++) {
        const before = members[i - 1];
        const after = members[i];
        const space = after.left - (before.left + before.width);
        if (space <= 0.5) {
          continue;
        }
        const thickness = Math.min(space, gaps.column);
        strips.push({
          left: before.left + before.width + (space - thickness) / 2,
          top: row.top,
          width: thickness,
          height: row.bottom - row.top,
        });
      }
    });
  }

  return strips;
};
