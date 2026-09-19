import { CHROME_SELECTOR } from './layout-context.js';
import { parseKey } from './style-guide.js';
import { PART_SELECTOR } from './parts.js';

/**
 * THE shared canvas computed-read seam.
 *
 * One module answers "what does this style target measure as, live, on the
 * canvas": which elements a key + part selector actually hits (multi-match
 * parts hit several), and what computed values those elements carry. The
 * spacing bands read it per frame; the box-model diagram reads the same
 * values for its computed placeholders (first match); future choice-row
 * placeholders (width, type size, radius) are the same read. One source of
 * truth, so the number in the panel and the band on the canvas can never
 * disagree.
 *
 * Leader-only by construction, like layout-context.js: computed style needs
 * the canvas document, which the satellite window does not have. The px
 * digest helpers at the bottom are pure over a CSSStyleDeclaration-shaped
 * object (getPropertyValue), so they stay node-testable.
 */

/**
 * The @scope donut boundary for container-rooted styles: a container's
 * styles never reach into nested container or block markup (printer.js
 * CONTAINER_SCOPE_LIMIT). Match resolution must honor the same boundary or
 * the bands would tint elements the styles cannot touch.
 */
const SCOPE_BOUNDARY =
  '[data-selectable^="container:"], [data-selectable^="block:"]';

/**
 * Resolves the canvas scope-root element for a selectable key.
 *
 * Keys are stamped on the canvas verbatim as `data-selectable`, so the key
 * IS the scope root: the element every compiled rule for the key is scoped
 * to, and the one place an inline write (a live preview custom property)
 * reaches every target of the key by inheritance.
 *
 * @param {?string} key
 *   The style target's selectable key (the style-map entry's key).
 *
 * @return {?Element}
 *   The live root, or null when the key is not a string, is empty, or is
 *   not in the canvas.
 */
export const resolveRoot = (key) => {
  if (typeof key !== 'string' || key === '') {
    return null;
  }
  // Keys carry a validated charset, but the attribute value is quoted text
  // either way: escape the two characters that could break out.
  const escaped = key.replace(/[\\"]/g, '\\$&');
  return document.querySelector(`[data-selectable="${escaped}"]`);
};

/**
 * Resolves the canvas elements a style target actually hits.
 *
 * Without a part selector the target is the scope-root element itself
 * (zero or one match). With one, every match inside the scope root — a
 * declared part class can match several elements, and every consumer of
 * this read must handle all of them (tint every match; placeholder from
 * the first). Container-owned parts honor the printers' donut: matches
 * inside a nested container or block root are out of scope, exactly as
 * their CSS is. A part can BE the scope root itself (a collapsed nested
 * container hoists the part class onto its root), so the root is a match
 * when it wears the part class.
 *
 * @param {?string} key
 *   The style target's selectable key (the style-map entry's key). Keys
 *   are stamped on the canvas verbatim, so the key IS the scope root.
 * @param {?string} sub
 *   A declared part selector ('.quote'), or null for the whole element.
 *
 * @return {Element[]}
 *   The live matches, outermost first (document order). Empty when the
 *   root is not in the canvas or the part selector is not a legal part
 *   shape.
 */
export const resolveTargets = (key, sub = null) => {
  const root = resolveRoot(key);
  if (!root) {
    return [];
  }
  if (!sub) {
    return [root];
  }
  if (!PART_SELECTOR.test(sub)) {
    return [];
  }
  const limited = parseKey(key)?.kind === 'container';
  const matches = [];
  // A collapsed container wears its part class on the scope root itself
  // (the printers' :scope selector half); include it ahead of descendants.
  if (root.matches(sub)) {
    matches.push(root);
  }
  root.querySelectorAll(sub).forEach((match) => {
    if (limited) {
      // Inside the donut: the nearest container/block root above the match
      // must be the scope root itself, not a nested one.
      const boundary = match.closest(SCOPE_BOUNDARY);
      if (boundary && boundary !== root && root.contains(boundary)) {
        return;
      }
    }
    matches.push(match);
  });
  return matches;
};

/**
 * Reads computed values for a set of properties from a live element.
 *
 * The generic placeholder read: property → the browser's computed value
 * string (px-resolved for lengths).
 *
 * @param {Element} element
 * @param {string[]} properties
 *
 * @return {Object<string, string>}
 */
export const readComputed = (element, properties) => {
  const computed = getComputedStyle(element);
  const values = {};
  properties.forEach((property) => {
    values[property] = computed.getPropertyValue(property);
  });
  return values;
};

/**
 * The rects of the child boxes an element lays out — the strips between
 * them are where its gap lives.
 *
 * Skips editor chrome (layout-context's CHROME_SELECTOR: the same boxes
 * childCount skips, so the strips agree with the gap gate), out-of-flow
 * children (absolute/fixed take no gap), and zero-size boxes.
 *
 * @param {Element} element
 *
 * @return {DOMRect[]}
 */
export const flowChildRects = (element) => {
  const rects = [];
  for (const child of element.children) {
    if (child.matches(CHROME_SELECTOR)) {
      continue;
    }
    const computed = getComputedStyle(child);
    if (
      computed.position === 'absolute' ||
      computed.position === 'fixed' ||
      computed.display === 'none'
    ) {
      continue;
    }
    const rect = child.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) {
      continue;
    }
    rects.push(rect);
  }
  return rects;
};

/**
 * Parses a computed length to px, treating non-lengths ('normal', '') as 0.
 */
const px = (value) => {
  const number = parseFloat(value);
  return Number.isFinite(number) ? number : 0;
};

/**
 * Digests a computed declaration into per-side padding px.
 *
 * @param {{getPropertyValue: function(string): string}} computed
 *
 * @return {{top: number, right: number, bottom: number, left: number}}
 */
export const paddingPx = (computed) => ({
  top: px(computed.getPropertyValue('padding-top')),
  right: px(computed.getPropertyValue('padding-right')),
  bottom: px(computed.getPropertyValue('padding-bottom')),
  left: px(computed.getPropertyValue('padding-left')),
});

/**
 * Digests a computed declaration into per-side border widths px — the
 * getBoundingClientRect box is the BORDER box, so padding bands start
 * inside the borders.
 *
 * @param {{getPropertyValue: function(string): string}} computed
 *
 * @return {{top: number, right: number, bottom: number, left: number}}
 */
export const borderPx = (computed) => ({
  top: px(computed.getPropertyValue('border-top-width')),
  right: px(computed.getPropertyValue('border-right-width')),
  bottom: px(computed.getPropertyValue('border-bottom-width')),
  left: px(computed.getPropertyValue('border-left-width')),
});

/**
 * Digests a computed declaration into per-side margin values for the
 * margin bands. Unlike the padding/border digests, sides keep their
 * sign (negative margins are real values) and a non-length digests to
 * null instead of 0: post-layout engines resolve `auto` to a used px
 * value, but wherever 'auto' (or an empty read) does surface, null lets
 * the band math render nothing rather than paint a guessed band —
 * negative and auto margins claim no space the value owns.
 *
 * @param {{getPropertyValue: function(string): string}} computed
 *
 * @return {{top: ?number, right: ?number, bottom: ?number,
 *   left: ?number}}
 */
export const marginPx = (computed) => {
  const signed = (value) => {
    const number = parseFloat(value);
    return Number.isFinite(number) ? number : null;
  };
  return {
    top: signed(computed.getPropertyValue('margin-top')),
    right: signed(computed.getPropertyValue('margin-right')),
    bottom: signed(computed.getPropertyValue('margin-bottom')),
    left: signed(computed.getPropertyValue('margin-left')),
  };
};

/**
 * Digests a computed declaration into row/column gap px ('normal' → 0).
 *
 * @param {{getPropertyValue: function(string): string}} computed
 *
 * @return {{row: number, column: number}}
 */
export const gapPx = (computed) => ({
  row: px(computed.getPropertyValue('row-gap')),
  column: px(computed.getPropertyValue('column-gap')),
});
