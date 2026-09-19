import { SIDES, propertySides } from './widgets/lib/box-model.js';
import {
  paddingBandRects,
  marginBandRects,
  borderBandRects,
  gapStripRects,
} from './band-geometry.js';
import { paddingPx, borderPx, marginPx, gapPx } from './computed-read.js';

/**
 * The canvas-highlight painter registry: property → painter.
 *
 * The canvas highlight spec is property-keyed — consumers say WHAT they
 * are editing ({key, sub, properties}), never how to paint it. This
 * module owns the HOW: which painter tints a property's invisible
 * geometry, and the pure per-frame arithmetic each painter runs over
 * one measured match. The renderer measures; painters compute; nothing
 * here touches the DOM, so the whole registry is node-tested
 * (tests/js/canvas-painters.test.mjs).
 *
 * A painter exists for properties whose effect is INVISIBLE GEOMETRY —
 * editing them moves empty space (padding, margin, gap) — and for the
 * border ring, whose three side families the box-model ring edits per
 * side: a border paints itself, but a currentColor hairline on a
 * same-hued ground shows nothing, and the ring's hovered cell must
 * still name its side on the canvas. Properties that show their own
 * effect everywhere (color, typography, background, shadow, radius)
 * have no painter, and highlightRects silently paints nothing for them:
 * a consumer hands over every property it edits without checking
 * eligibility first.
 *
 * The measurement context a renderer supplies per match:
 * - rect: the match's border-box rect (the tracker's per-frame
 *   getBoundingClientRect).
 * - computed: the match's computed declaration (getComputedStyle, or
 *   any {getPropertyValue} fake under node) — the SAME read that feeds
 *   the diagram's placeholders, so panel and canvas can never disagree.
 * - childRects: LAZY flow-child rects, () => flowChildRects(element).
 *   Only the gap painter calls it, so padding/margin highlights never
 *   walk children.
 */

/**
 * Gap properties address axes, not sides: the shorthand covers both,
 * each longhand its own.
 */
const GAP_AXES = {
  gap: ['row', 'column'],
  'row-gap': ['row'],
  'column-gap': ['column'],
};

/**
 * The registry. Per painter (keyed by its band kind, which is also the
 * painted box's CSS modifier — .daedalus-stylish-band--<kind>):
 * - order: the canonical part order, so merged multi-property specs
 *   paint deterministically.
 * - parts(property): the parts of this painter the property addresses,
 *   or null when the property is not this painter's.
 * - rects(context, parts): the band rects for one measured match.
 */
/**
 * The border ring's three side families: one painter, because a side's
 * width, style and color are one band on the canvas.
 */
const BORDER_FAMILIES = ['border-width', 'border-style', 'border-color'];

/**
 * The box painters read the one side grammar (box-model.js
 * propertySides): the shorthand paints all four bands, a physical
 * longhand its own, a logical longhand the pair it moves (padding-inline
 * the inline bands, the width story's spelling), so the highlight and
 * the wear bar's shadow read agree on which sides a spelling addresses.
 * The border painter answers for any of its three families
 * (border-top-width and border-top-color both tint the top band).
 */
const PAINTERS = {
  padding: {
    order: SIDES,
    parts: (property) => propertySides('padding', property),
    rects: ({ rect, computed }, sides) => {
      const bands = paddingBandRects(
        rect,
        borderPx(computed),
        paddingPx(computed),
      );
      return sides.map((side) => bands[side]);
    },
  },
  margin: {
    order: SIDES,
    parts: (property) => propertySides('margin', property),
    rects: ({ rect, computed }, sides) => {
      const bands = marginBandRects(rect, marginPx(computed));
      return sides.map((side) => bands[side]);
    },
  },
  border: {
    order: SIDES,
    parts: (property) => {
      for (const family of BORDER_FAMILIES) {
        const sides = propertySides(family, property);
        if (sides) {
          return sides;
        }
      }
      return null;
    },
    rects: ({ rect, computed }, sides) => {
      const bands = borderBandRects(rect, borderPx(computed));
      return sides.map((side) => bands[side]);
    },
  },
  gap: {
    order: ['row', 'column'],
    parts: (property) => (GAP_AXES[property] ? [...GAP_AXES[property]] : null),
    rects: ({ computed, childRects }, axes) => {
      const gaps = gapPx(computed);
      return gapStripRects(childRects(), {
        row: axes.includes('row') ? gaps.row : 0,
        column: axes.includes('column') ? gaps.column : 0,
      });
    },
  },
};

/**
 * Resolves the painter a property belongs to.
 *
 * @param {string} property
 *
 * @return {?{kind: string, parts: string[]}}
 *   The painter's band kind and the parts this property addresses, or
 *   null for painterless properties (their edits are visible on their
 *   own).
 */
export const painterFor = (property) => {
  for (const [kind, painter] of Object.entries(PAINTERS)) {
    const parts = painter.parts(property);
    if (parts) {
      return { kind, parts };
    }
  }
  return null;
};

/**
 * The band rects one measured match paints for a highlight spec's
 * properties.
 *
 * Properties are grouped per painter first (union of parts, canonical
 * order), so 'padding' + 'padding-top' costs one padding pass and four
 * deduped bands, and each painter digests the computed declaration
 * once per frame. Painterless properties contribute nothing, silently.
 * Zero-size rects (an unpadded side, a negative margin) are dropped
 * here — the renderer paints exactly what it receives.
 *
 * @param {?string[]} properties
 *   The highlight spec's properties.
 * @param {{rect: Object, computed: Object,
 *   childRects: function(): Array}} context
 *   The measurement context (see the module docblock).
 *
 * @return {Array<{kind: string, left: number, top: number,
 *   width: number, height: number}>}
 */
export const highlightRects = (properties, context) => {
  const selected = new Map();
  (properties ?? []).forEach((property) => {
    const match = painterFor(property);
    if (!match) {
      return;
    }
    const parts = selected.get(match.kind) ?? new Set();
    match.parts.forEach((part) => parts.add(part));
    selected.set(match.kind, parts);
  });
  const rects = [];
  selected.forEach((parts, kind) => {
    const painter = PAINTERS[kind];
    const ordered = painter.order.filter((part) => parts.has(part));
    painter.rects(context, ordered).forEach((rect) => {
      if (rect && rect.width > 0 && rect.height > 0) {
        rects.push({ kind, ...rect });
      }
    });
  });
  return rects;
};
