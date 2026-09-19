/**
 * The motion-triggers runtime's pure model: what js/motion-triggers.js can
 * decide without a DOM.
 *
 * The runtime polyfills the Animation Triggers draft (`timeline-trigger`
 * + `animation-trigger`) where the browser lacks it: the printer holds a
 * triggered animation paused until the runtime stamps the element, and the
 * runtime reads the effective pair off computed style through the marker
 * custom properties the printer prints beside each property. This module
 * owns the grammar (the two trigger values as the draft spells them), the
 * translation of a view() activation range into IntersectionObserver
 * options, and the action map over the Web Animations API. The runtime
 * only observes and dispatches.
 *
 * This file is imported by a plain ES module outside the webpack bundles
 * (the live-site runtime), so it imports nothing.
 */

/**
 * The range names the draft's activation range accepts, each mapped to the
 * element-top position (from the scrollport's start edge) at which the
 * range point is reached, given the element height and the scrollport
 * height. The polyfill approximates a view() timeline as one dimension
 * (the block axis, the default) on the window scrollport.
 *
 * A point is expressed as "the element's start edge is at least this far
 * up": entry runs from the start edge touching the scrollport's end edge
 * (0%) to the end edge touching it (100%); contain from fully entered to
 * about to leave (the element sliding across the free space, or, taller
 * than the scrollport, covering it); exit from the start edge at the
 * scrollport's start (0%) to fully gone (100%); cover from about to enter
 * to fully gone. The crossing variants share the plain edges' geometry.
 */
const RANGE_TOP = {
  entry: (p, h, vh) => vh - p * h,
  'entry-crossing': (p, h, vh) => vh - p * h,
  contain: (p, h, vh) => (h <= vh ? vh - h - p * (vh - h) : (vh - h) * (1 - p)),
  cover: (p, h, vh) => vh - p * (vh + h),
  exit: (p, h, vh) => -p * h,
  'exit-crossing': (p, h, vh) => -p * h,
};

/**
 * The actions the draft names, first = on activation, second = on
 * deactivation.
 */
const ACTIONS = new Set([
  'play',
  'play-once',
  'play-forwards',
  'play-backwards',
  'pause',
  'reset',
  'replay',
  'none',
]);

const DASHED_IDENT = /^--[A-Za-z0-9_-]+$/;
const PERCENT = /^(\d+(?:\.\d+)?|\.\d+)%$/;

const tokens = (value) =>
  String(value ?? '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);

/**
 * The range that is the whole view timeline: `cover 0%` to `cover 100%`.
 * A bare percentage in an activation range is a point on it, and no range
 * at all (`normal`) is all of it.
 */
const WHOLE_TIMELINE = 'cover';

const percentOf = (token) => {
  const match = PERCENT.exec(token ?? '');
  return match ? Number(match[1]) : null;
};

/**
 * Reads one range point at `parts[at]`: a range name with an optional
 * percentage (absent = `namedDefault`), or a bare percentage on the whole
 * timeline. Returns `{point, next}` or null when the token is neither.
 */
const readPoint = (parts, at, namedDefault) => {
  const token = parts[at];
  if (token === undefined) {
    return null;
  }
  if (Object.hasOwn(RANGE_TOP, token)) {
    const percent = percentOf(parts[at + 1]);
    return percent === null
      ? { point: { edge: token, percent: namedDefault }, next: at + 1 }
      : { point: { edge: token, percent }, next: at + 2 };
  }
  const percent = percentOf(token);
  return percent === null
    ? null
    : { point: { edge: WHOLE_TIMELINE, percent }, next: at + 1 };
};

/**
 * The activation range's tokens (after the source, before any `/`) as
 * `{edge, percent, end: {edge, percent}}` by animation-range's shorthand
 * rules; null when malformed.
 */
const parseActivationRange = (parts) => {
  if (parts.length === 0) {
    return {
      edge: WHOLE_TIMELINE,
      percent: 0,
      end: { edge: WHOLE_TIMELINE, percent: 100 },
    };
  }
  const start = readPoint(parts, 0, 0);
  if (!start) {
    return null;
  }
  if (start.next === parts.length) {
    return { ...start.point, end: { edge: start.point.edge, percent: 100 } };
  }
  const end = readPoint(parts, start.next, 100);
  if (!end || end.next !== parts.length) {
    return null;
  }
  return { ...start.point, end: end.point };
};

/**
 * Parses a `timeline-trigger` value into what the polyfill can bind: the
 * trigger name, the timeline source and the activation range as start and
 * end points (`range: {edge, percent, end: {edge, percent}}`). Null when
 * there is nothing to polyfill: `none`, empty, a source other than
 * `view(...)` (a scroll() timeline has no element geometry to observe), or
 * a malformed value.
 *
 * The draft's grammar is `none | [ <name> <source> <activation-range>
 * [ / <active-range> ]? ]#`. Only the first list item counts, and the
 * active range after `/` is accepted and ignored (the polyfill has no
 * notion of one). The activation range follows animation-range's
 * shorthand rules, which the model records rather than reinterprets: a
 * range name with no percentage reads as its 0% at the start and its 100%
 * at the end; a start with no end takes its own name's 100% as the end, so
 * a bare `entry 20%` is `entry 20% entry 100%` (active only while the
 * element crosses its entry band, which is why the curated stops spell
 * `exit 100%` explicitly); a bare percentage is a point on the whole
 * timeline (`cover`); no range at all (`normal`) is the whole timeline.
 * Checked against Chrome 152's computed `timeline-trigger-activation-range`.
 * Whether the polyfill can honour the end is observerOptions' business.
 */
export const parseTimelineTrigger = (value) => {
  const first = String(value ?? '').split(',')[0];
  const parts = tokens(first.split('/')[0]);
  if (parts.length < 2 || !DASHED_IDENT.test(parts[0])) {
    return null;
  }
  const source = parts[1];
  if (!/^view\(.*\)$/.test(source)) {
    return null;
  }
  const range = parseActivationRange(parts.slice(2));
  if (!range) {
    return null;
  }
  return { name: parts[0], source, range };
};

/**
 * Parses an `animation-trigger` value into the trigger name it binds to
 * and its enter/exit actions. Null for `none`, empty, or a malformed value
 * (no dashed ident, an unknown action). One action means "on activation
 * only" (exit `none`); a third action and beyond are ignored. Only the
 * first list item counts.
 */
export const parseAnimationTrigger = (value) => {
  const parts = tokens(String(value ?? '').split(',')[0]);
  if (parts.length < 2 || !DASHED_IDENT.test(parts[0])) {
    return null;
  }
  const enter = parts[1];
  const exit = parts[2] ?? 'none';
  if (!ACTIONS.has(enter) || !ACTIONS.has(exit)) {
    return null;
  }
  return { name: parts[0], enter, exit };
};

/**
 * The IntersectionObserver options that fire when the element's start
 * edge reaches the activation range's start point and hold until the
 * element has fully left.
 *
 * The root is the window scrollport shrunk from its end edge by the
 * distance between that edge and the range's start point, so
 * "intersecting" holds while the element has scrolled at least that far
 * in and has not yet fully left. That is exactly the range `<start> exit
 * 100%` (the curated stops' spelling; `cover 100%` is the same point) and
 * the only shape this geometry can express: a shrunk root has one movable
 * edge. The polyfill-era approximation, named here once: `range.end` is
 * not read, and every other end (`entry 20% entry 100%`, the bare start's
 * native meaning; any end before full exit) is honoured as `exit 100%`,
 * so such an element activates and holds its activation until full exit
 * rather than deactivating at its end point. Never a refusal: an unbound
 * trigger would hold the animation paused forever, worse than playing
 * long. The clamp: a start point above the scrollport's start edge (a
 * tall element's contain, any exit point, cover past the element's own
 * height) cannot shrink the root past one pixel, so it fires when the
 * element's start edge reaches the top instead; the polyfill has no
 * geometry beyond the scrollport. Threshold stays 0 (the edge crossing is
 * the whole signal).
 */
export const observerOptions = (range, elementHeight, viewportHeight) => {
  const top = RANGE_TOP[range.edge](
    range.percent / 100,
    Math.max(0, elementHeight),
    Math.max(1, viewportHeight),
  );
  const clamped = Math.min(Math.max(1, viewportHeight), Math.max(1, top));
  const margin = Math.max(0, Math.round(Math.max(1, viewportHeight) - clamped));
  return {
    threshold: 0,
    rootMargin: margin === 0 ? '0px' : `0px 0px -${margin}px 0px`,
  };
};

/**
 * The Web Animations step an action performs on one Animation, or null
 * for `none`. The runtime flips the CSS hold (the stamp) around these:
 * a CSS-paused animation ignores play() from script, so the stamp lands
 * first on activation and lifts again on `reset`, and each step here is
 * spelled to hold under either regime (CSS or API governing the play
 * state).
 *
 * The forward play steps leave a CSS-governed animation alone: once the
 * stamp lifts the hold the animation runs by CSS, and the first API call
 * on a CSSAnimation takes its play state away from CSS for good, after
 * which Chromium (136 verified) no longer cancels it when `animation-name`
 * stops naming it, so a `reduced-motion` unset could not stop a played
 * entrance. The API steps in only where CSS cannot deliver the action: an
 * API-paused animation (`reset` left it so) or a reversed rate. Backward
 * play, `replay` and `reset` are API-only by nature (a rate, a seek).
 *
 * `play-once` is `play` here; the runtime stops observing after its first
 * activation, and every re-activation passes through `reset` first, so the
 * paused branch is the one that ever replays a finished entrance.
 */
export const actionFor = (kind) => {
  switch (kind) {
    case 'play':
    case 'play-once':
    case 'play-forwards':
      return (animation) => {
        if (animation.playbackRate === 1 && animation.playState !== 'paused') {
          return;
        }
        animation.playbackRate = 1;
        animation.play();
      };
    case 'play-backwards':
      return (animation) => {
        animation.playbackRate = -1;
        animation.play();
      };
    case 'replay':
      return (animation) => {
        animation.currentTime = 0;
        animation.playbackRate = 1;
        animation.play();
      };
    case 'reset':
      return (animation) => {
        animation.currentTime = 0;
        animation.playbackRate = 1;
        animation.pause();
      };
    case 'pause':
      return (animation) => {
        animation.pause();
      };
    default:
      return null;
  }
};

/**
 * Whether the two effective marker values bind: both parse and name the
 * same trigger. Returns the binding (`{timeline, actions}`) or null.
 */
export const bindingFor = (timelineValue, animationValue) => {
  const timeline = parseTimelineTrigger(timelineValue);
  const actions = parseAnimationTrigger(animationValue);
  if (!timeline || !actions || timeline.name !== actions.name) {
    return null;
  }
  return { timeline, actions };
};
