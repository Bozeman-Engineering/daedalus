/**
 * Element drop targets — the second target kind beside gaps.
 *
 * A gap target inserts BETWEEN flow items; an element target lands ON a
 * compatible element already on the page (drop a file onto an image to
 * replace its media). Same gesture, different landing surface: a source
 * declares its element face in its spec (`elements`, see the controller's
 * docblock) and the drag layer owns hit-testing and chrome — no per-target
 * overlay elements, no geometry-tracking loops in sources.
 *
 * Hit-testing rides the drag event's real target, like gap container
 * resolution: the browser already hit-tests through nested layouts, so a
 * candidate is simply the closest declared element enclosing the cursor's
 * target. The element claims its INTERIOR; within an edge band
 * (min(28px, 25% of the extent) per axis — the container edge-zone rule
 * transposed) resolution falls through to gaps, so the flow around a large
 * compatible element stays pointer-reachable while its body reads
 * unambiguously as "land on me".
 *
 * The resolved target is the drag layer's polymorphic drop shape with
 * kind: 'element' — the controller and the caret discriminate on kind, and
 * gapId gives the same stable identity gaps carry (hysteresis, transition
 * detection).
 */

// The per-axis band inside an element's rect that yields to gap
// resolution — min(EDGE_BAND_PX, extent * EDGE_BAND_RATIO), the container
// edge-zone sizing.
const EDGE_BAND_PX = 28;
const EDGE_BAND_RATIO = 0.25;

// Stable per-element identity for gapId: elements have no natural string
// key (a media element may carry no selectable), so identity is minted
// once per element and held weakly.
let sequence = 0;
const identities = new WeakMap();
const identityOf = (element) => {
  if (!identities.has(element)) {
    identities.set(element, ++sequence);
  }
  return `element|${identities.get(element)}`;
};

/**
 * The declared landing element enclosing an event target, or null.
 *
 * Pure candidacy — selector match plus the source's accepts filter — with
 * no geometry: the interior/edge split is elementInterior's, so the
 * controller can test stickiness (does the CURRENT element still match?)
 * separately from claiming.
 *
 * @param {EventTarget} target
 *   The drag event's target (what the cursor is over).
 * @param {Object} spec
 *   The active source spec (reads spec.elements).
 * @param {Object} data
 *   The drag data, passed to the accepts filter.
 *
 * @return {?Element}
 */
export const matchElementTarget = (target, spec, data) => {
  const face = spec.elements;
  if (!face?.selector || !(target instanceof Element)) {
    return null;
  }
  const element = target.closest(face.selector);
  if (!element || (face.accepts && !face.accepts(element, data))) {
    return null;
  }
  return element;
};

/**
 * Whether a point is in an element's interior — inside its rect, clear of
 * the edge bands that yield to gap resolution.
 *
 * @param {Element} element
 * @param {number} x
 * @param {number} y
 *   Viewport cursor coordinates.
 * @param {number} grow
 *   Extra pixels the interior extends INTO the band (never past the rect):
 *   the controller passes its hysteresis distance so the current element
 *   target keeps its claim until the cursor is decisively in the band —
 *   the element face of the gap dead zone.
 *
 * @return {boolean}
 */
export const elementInterior = (element, x, y, grow = 0) => {
  const rect = element.getBoundingClientRect();
  const bandX = Math.max(
    Math.min(EDGE_BAND_PX, rect.width * EDGE_BAND_RATIO) - grow,
    0,
  );
  const bandY = Math.max(
    Math.min(EDGE_BAND_PX, rect.height * EDGE_BAND_RATIO) - grow,
    0,
  );
  return (
    x >= rect.left + bandX &&
    x <= rect.right - bandX &&
    y >= rect.top + bandY &&
    y <= rect.bottom - bandY
  );
};

/**
 * Resolves a cursor position to an element drop target, or null.
 *
 * Null falls through to gap resolution: no declared element under the
 * cursor, the accepts filter rejected it, or the cursor sits in the edge
 * band (the flow around the element).
 *
 * @param {EventTarget} target
 *   The drag event's target.
 * @param {number} x
 * @param {number} y
 *   Viewport cursor coordinates.
 * @param {Object} spec
 *   The active source spec.
 * @param {Object} data
 *   The drag data.
 *
 * @return {?Object}
 *   {kind: 'element', element, label, gapId} — the drag layer's element
 *   target shape.
 */
export const elementTargetAt = (target, x, y, spec, data) => {
  const element = matchElementTarget(target, spec, data);
  if (!element || !elementInterior(element, x, y)) {
    return null;
  }
  const { label } = spec.elements;
  return {
    kind: 'element',
    element,
    label: (typeof label === 'function' ? label(element, data) : label) ?? null,
    gapId: identityOf(element),
  };
};
