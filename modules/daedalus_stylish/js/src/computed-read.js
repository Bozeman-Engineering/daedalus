import { CHROME_SELECTOR } from './layout-context.js';
import { parseKey } from './style-guide.js';
import { hostTargets, donutLimit } from './printer.js';
import { parseSelector } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

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
 * A generated box (a selector ending in `::before` / `::after`) has no
 * node: the DOM can only give its HOSTS, the elements the rule's host
 * selectors hit (the owner's own root for the anchor `&::before`, every
 * `.quote` for `.quote::after`), and the box itself rides the computed
 * read as getComputedStyle's pseudo argument. So resolveTargets answers
 * the hosts, pseudoOf names the box, and readComputed takes it; a
 * consumer that measures RECTS (highlight bands, coverage rings) has
 * nothing to measure for a box and withholds rather than paint the host
 * as if it were the box.
 *
 * Computed values are px-only, and the panel speaks the value space's
 * unit, so the read also measures the bases a length converts through
 * (lengthBases: the root's font size for rem, the element's for em).
 * The conversion itself is dimension-value.js's; this module only
 * measures.
 *
 * Leader-only by construction, like layout-context.js: computed style needs
 * the canvas document, which the satellite window does not have. The px
 * digest helpers at the bottom and pseudoOf are pure (over a
 * CSSStyleDeclaration-shaped object, over the selector grammar), so they
 * stay node-testable and satellite-safe.
 */

/**
 * The terminal pseudo-element a style target's selector addresses.
 *
 * @param {?string} selector
 *   A canonical relative selector, or null for the whole element.
 *
 * @return {?string}
 *   'before' / 'after' for a generated box, null for an element target
 *   (a null selector and a selector outside the grammar included: the
 *   grammar's verdict is resolveTargets' empty answer, not this one's).
 */
export const pseudoOf = (selector) =>
  typeof selector === 'string' ? (parseSelector(selector)?.pseudo ?? null) : null;

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
 * Without a selector the target is the scope-root element itself (zero
 * or one match). With one, every match of the printed targets inside the
 * scope root (printer.js scopeTargets: the same selectors the delivered
 * CSS carries, so what is read is exactly what the rules hit): a part
 * class can match several elements, `> pb-text` every text block child,
 * and every consumer of this read must handle all of them (tint every
 * match; placeholder from the first). Container-owned entries honor the
 * printers' donut exactly as their CSS does (donutLimit): a part-first
 * selector's matches inside a nested container or block root are out of
 * scope, shifted by the levels the part reaches by `>`; a tag or type
 * selector reaches the whole subtree. A part can BE the scope root
 * itself (a collapsed nested container hoists the part class onto its
 * root), so the root is a match when it wears the part class (the
 * printers' `:scope.cls` half). A generated box resolves to its HOSTS
 * (the printers' hostTargets: the root itself for `&::before`, every
 * `.quote` for `.quote::after`), since the box has no node; the reader
 * carries pseudoOf(selector) into readComputed, and rect chrome
 * withholds.
 *
 * @param {?string} key
 *   The style target's selectable key (the style-map entry's key). Keys
 *   are stamped on the canvas verbatim, so the key IS the scope root.
 * @param {?string} selector
 *   The canonical relative selector ('.quote', '> *', '> pb-text',
 *   '&::before'), or null for the whole element.
 *
 * @return {Element[]}
 *   The live matches (a box's hosts), the root first when it matches,
 *   then document order. Empty when the root is not in the canvas or
 *   the selector is not in the grammar.
 */
export const resolveTargets = (key, selector = null) => {
  const root = resolveRoot(key);
  if (!root) {
    return [];
  }
  if (!selector) {
    return [root];
  }
  let targets;
  try {
    targets = hostTargets(selector);
  } catch {
    return [];
  }
  const limit =
    parseKey(key)?.kind === 'container' ? donutLimit(selector) : null;
  // The donut's depth: how many child levels below a nested root the
  // limit sits (0 for the standard donut).
  const levels = limit === null ? -1 : (limit.match(/ > \*/g) ?? []).length;
  const inDonut = (match) => {
    if (levels < 0) {
      return true;
    }
    // The nearest container/block root above the match's `levels`-th
    // ancestor must be the scope root itself, not a nested one.
    let from = match;
    for (let i = 0; i < levels && from; i += 1) {
      from = from.parentElement;
    }
    const boundary = from?.closest(SCOPE_BOUNDARY) ?? null;
    return !(boundary && boundary !== root && root.contains(boundary));
  };
  const matches = [];
  const add = (element) => {
    if (!matches.includes(element) && inDonut(element)) {
      matches.push(element);
    }
  };
  targets.forEach((target) => {
    // The hoisted dual (':scope.cls', no combinator after the root)
    // matches the root itself; every other target queries the subtree
    // relative to the root.
    if (/^:scope[^\s]*$/.test(target)) {
      if (root.matches(target)) {
        add(root);
      }
      return;
    }
    const query = target.startsWith(':scope') || target.startsWith(':where(:scope)')
      ? target
      : `:scope ${target}`;
    root.querySelectorAll(query).forEach(add);
  });
  return matches;
};

/**
 * Reads computed values for a set of properties from a live element, or
 * from one of its generated boxes.
 *
 * The generic placeholder read: property → the browser's computed value
 * string (px-resolved for lengths). A box reads through its host with
 * getComputedStyle's pseudo argument, the one read the platform offers
 * for it.
 *
 * @param {Element} element
 *   The element, or a box's host (a resolveTargets match).
 * @param {string[]} properties
 * @param {?string} pseudo
 *   'before' / 'after' to read the element's generated box (pseudoOf the
 *   target's selector), null for the element itself.
 *
 * @return {Object<string, string>}
 */
export const readComputed = (element, properties, pseudo = null) => {
  const computed = getComputedStyle(element, pseudo === null ? null : `::${pseudo}`);
  const values = {};
  properties.forEach((property) => {
    values[property] = computed.getPropertyValue(property);
  });
  return values;
};

/**
 * The px a font-size computes to, or null when the read is no length
 * (an empty read on a detached node).
 */
const fontSizePx = (computed) => {
  const number = parseFloat(computed.getPropertyValue('font-size'));
  return Number.isFinite(number) && number > 0 ? number : null;
};

/**
 * The measured bases a length converts through on this canvas: the
 * document root's font size (what one rem is) and the element's own
 * (what one em is), both in px. A generated box measures its own
 * font size through its host with the pseudo argument, like
 * readComputed. Rides readCanvas beside `values`, so a computed
 * placeholder read in px can honestly convert into the unit the
 * gesture works in (dimension-value.js convertLength) at THIS page's
 * root, never an assumed 16.
 *
 * @param {Element} element
 *   The element, or a box's host (a resolveTargets match).
 * @param {?string} pseudo
 *   'before' / 'after' for the element's generated box, null for the
 *   element itself.
 *
 * @return {{rootPx: ?number, fontPx: ?number}}
 */
export const lengthBases = (element, pseudo = null) => ({
  rootPx: fontSizePx(getComputedStyle(document.documentElement)),
  fontPx: fontSizePx(
    getComputedStyle(element, pseudo === null ? null : `::${pseudo}`),
  ),
});

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
