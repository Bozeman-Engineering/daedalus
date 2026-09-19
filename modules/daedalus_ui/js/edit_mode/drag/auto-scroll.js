/**
 * Edge auto-scroll for placement drags.
 *
 * A drag cannot reach targets outside the viewport unless something scrolls
 * the page while the cursor is held at an edge. Current Chrome only half
 * covers this natively: its drag autoscroll belt is anchored to the
 * document's layout box in root-frame coordinates, so scrolling DOWN at the
 * bottom edge works from any scroll position but scrolling UP at the top
 * edge stops being reachable once the page is scrolled past the belt
 * (measured against Chrome 151 through the CDP drag pipeline, which drives
 * the same Blink DragController path as a real drag). Firefox has no window
 * autoscroll for HTML5 drags at all. Every drag library ships its own for
 * this reason.
 *
 * The controller owns the gesture, so it owns the scroll: dragover feeds
 * the cursor position in, a rAF loop scrolls the window while the cursor
 * sits inside an edge band, and the drag's end stops it. Where the browser
 * DOES natively scroll (Chrome, downward) the loop measures that movement
 * and only tops up to the target velocity, so the two never compound.
 *
 * Window scroller only: the canvas is the page, and the old daedalus_layout shim
 * this replaces (dnd-page-scroll.js) never scrolled inner containers
 * either. Keyboard lifts move by target, not by pixel, and never come
 * through here.
 */

/**
 * How far from a viewport edge (px) the scroll band starts.
 */
export const EDGE_BAND = 48;

/**
 * Scroll speed (px/s) when the cursor is at the very edge; the band ramps
 * up to it linearly. Chrome's native downward autoscroll measured about
 * 1300 px/s, so ours matching it keeps the two directions feeling alike.
 */
export const MAX_VELOCITY = 1400;

/**
 * The scroll velocity (signed px/s) a cursor position asks for.
 *
 * Zero outside the edge bands; inside a band it ramps linearly from 0 at
 * the band's inner boundary to MAX_VELOCITY at the viewport edge.
 * Negative is up.
 */
export const desiredVelocity = (clientY, viewportHeight) => {
  const topDepth = EDGE_BAND - clientY;
  if (topDepth > 0) {
    return -Math.min(topDepth / EDGE_BAND, 1) * MAX_VELOCITY;
  }
  const bottomDepth = clientY - (viewportHeight - EDGE_BAND);
  if (bottomDepth > 0) {
    return Math.min(bottomDepth / EDGE_BAND, 1) * MAX_VELOCITY;
  }
  return 0;
};

/**
 * The scroll step to apply after subtracting what the browser's native
 * autoscroll already moved in the same direction since the last frame.
 * Movement in the opposite direction (a user wheel, an anchor jump) is not
 * ours to fight and is ignored rather than compensated.
 */
export const topUp = (desired, nativeDelta) => {
  const native =
    Math.sign(nativeDelta) === Math.sign(desired) ? Math.abs(nativeDelta) : 0;
  return Math.sign(desired) * Math.max(Math.abs(desired) - native, 0);
};

/**
 * A live drag refeeds the cursor via dragover at least every ~350ms (the
 * HTML spec's drag event interval); a feed older than this means the
 * gesture ended without the controller seeing its end (a dragend swallowed
 * by a teardown mid-drag), and the loop must not scroll on stale intent.
 */
const STALE_FEED_MS = 750;

let cursorY = null;
let rafId = null;
let lastScrollTop = null;
let lastTime = null;
let lastFeedTime = null;

const stopLoop = () => {
  if (rafId !== null) {
    cancelAnimationFrame(rafId);
    rafId = null;
  }
  lastScrollTop = null;
  lastTime = null;
};

const tick = (now) => {
  rafId = null;
  const velocity = desiredVelocity(cursorY, window.innerHeight);
  if (velocity === 0 || now - lastFeedTime > STALE_FEED_MS) {
    stopLoop();
    return;
  }
  const scroller = document.scrollingElement;
  // First frame establishes the baselines and applies nothing: dt and the
  // native-scroll delta both need a previous frame to be meaningful.
  if (lastTime !== null) {
    const dt = Math.min((now - lastTime) / 1000, 0.05);
    const nativeDelta = scroller.scrollTop - lastScrollTop;
    scroller.scrollTop += topUp(velocity * dt, nativeDelta);
  }
  lastTime = now;
  lastScrollTop = scroller.scrollTop;
  rafId = requestAnimationFrame(tick);
};

/**
 * Feeds the drag cursor in; called from the controller's dragover.
 */
export const updateAutoScroll = (clientY) => {
  cursorY = clientY;
  lastFeedTime = performance.now();
  if (rafId === null && desiredVelocity(cursorY, window.innerHeight) !== 0) {
    rafId = requestAnimationFrame(tick);
  }
};

/**
 * Stops scrolling; called when the drag gesture ends.
 */
export const stopAutoScroll = () => {
  cursorY = null;
  stopLoop();
};
