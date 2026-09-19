/**
 * THE per-frame element-geometry seam, extracted from the outline.
 *
 * Floating edit-mode chrome (selection outlines, spacing bands, any overlay
 * in #daedalus-root) must follow a live canvas element whose geometry the
 * page can change at any time. One mechanism covers every cause: geometry is
 * re-read from the element each animation frame while tracking is active, so
 * resize, animation, and markup swaps all fall out of the same read.
 * Elements are re-resolved by name when the cached node leaves the document
 * (a subtree swap) or fails the consumer's validity check, so tracking
 * survives re-renders without bookkeeping.
 *
 * Measurements are viewport rects, but element-following chrome PAINTS in
 * document coordinates (documentPosition below, position: absolute at the
 * document origin) — never position: fixed. The compositor scrolls the
 * page before the main thread can re-measure, so per-frame-corrected fixed
 * chrome always trails a scroll by a frame. In document coordinates
 * rect + scroll is constant during a pure scroll: the one-frame-old paint
 * is still exactly right, and the chrome rides the content's own
 * compositor layer with zero lag.
 *
 * The tracker owns mechanism only: the frame loop, the stale-element cache,
 * and the measurement. Consumers own meaning: how a key resolves to an
 * element and what a measurement paints. The measured element rides along in
 * the result so a consumer can take further per-frame reads from it
 * (getComputedStyle for padding bands) without a second lookup.
 *
 * Pure by design: no imports, and the frame scheduler is injectable, so the
 * seam is node-testable. Selection-flavored resolution lives in
 * selection/selectable-tracker.js.
 */

/**
 * Converts a viewport-space rect or point to document coordinates.
 *
 * The paint half of the tracker's contract (see the header): chrome that
 * follows a canvas element positions absolute at the document origin and
 * paints at these coordinates, so a scroll moves chrome and content
 * together on the compositor. Viewport-dependent DECISIONS (edge clamps)
 * keep using the raw viewport rect; only the final painted coordinates
 * convert.
 *
 * @param {{left: number, top: number}} rect
 *   A viewport-space rect or point.
 *
 * @return {{left: number, top: number}}
 *   The same point in document coordinates.
 */
export const documentPosition = (rect) => ({
  left: rect.left + window.scrollX,
  top: rect.top + window.scrollY,
});

/**
 * Creates a geometry tracker.
 *
 * @param {Object} options
 * @param {function(string): ?Element} options.resolve
 *   Resolves a key to its live element, called whenever the cache misses.
 * @param {function(Element, string): boolean} [options.validate]
 *   Extra staleness check beyond isConnected: return false to force
 *   re-resolution (e.g. the cached node no longer carries the key).
 * @param {function(function): *} [options.schedule]
 *   Frame scheduler, defaults to requestAnimationFrame. Injectable for
 *   tests.
 * @param {function(*): void} [options.cancel]
 *   Cancels a scheduled frame, defaults to cancelAnimationFrame.
 *
 * @return {{
 *   measure: function(string, string): ?{element: Element, rect: DOMRect},
 *   prune: function(Iterable<string>): void,
 *   start: function(function): void,
 *   stop: function(): void,
 * }}
 */
export const createGeometryTracker = ({
  resolve,
  validate,
  schedule = (callback) => requestAnimationFrame(callback),
  cancel = (id) => cancelAnimationFrame(id),
} = {}) => {
  if (typeof resolve !== 'function') {
    throw new TypeError(
      'createGeometryTracker requires a resolve(key) function.',
    );
  }

  // Tracked elements by consumer-chosen name. The name, not the key, is the
  // cache identity: one key may be tracked under several names (a hover box
  // and a selection box on the same element) and a multi-match consumer may
  // track several elements for one key under indexed names.
  const elements = new Map();
  let frame = null;

  /**
   * Measures the named tracking target.
   *
   * Re-resolves the element when the cache is empty, the node left the
   * document, or the consumer's validate rejects it.
   *
   * @param {string} name
   *   The cache identity for this target.
   * @param {string} key
   *   The consumer key handed to resolve/validate.
   *
   * @return {?{element: Element, rect: DOMRect}}
   *   The live element and its current viewport rect, or null when nothing
   *   resolves or the rect is zero-size (display: none, empty node): a
   *   consumer can paint nothing useful from either, so both mean "hide".
   */
  const measure = (name, key) => {
    let element = elements.get(name);
    if (
      !element ||
      !element.isConnected ||
      (validate && !validate(element, key))
    ) {
      element = resolve(key) ?? null;
      if (element) {
        elements.set(name, element);
      } else {
        elements.delete(name);
      }
    }
    if (!element) {
      return null;
    }
    const rect = element.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) {
      return null;
    }
    return { element, rect };
  };

  /**
   * Drops cached elements for names outside the live set.
   *
   * @param {Iterable<string>} liveNames
   *   The names still being tracked.
   */
  const prune = (liveNames) => {
    const live = new Set(liveNames);
    elements.forEach((element, name) => {
      if (!live.has(name)) {
        elements.delete(name);
      }
    });
  };

  const stop = () => {
    if (frame !== null) {
      cancel(frame);
      frame = null;
    }
  };

  /**
   * Starts the frame loop; restart-safe (an active loop is stopped first).
   *
   * @param {function(): void} onFrame
   *   Called once per frame; reads measurements via measure() and paints.
   */
  const start = (onFrame) => {
    stop();
    const loop = () => {
      onFrame();
      frame = schedule(loop);
    };
    loop();
  };

  return { measure, prune, start, stop };
};
