/**
 * @file
 * The shared floating-surface primitive (Drupal.Daedalus.popover).
 *
 * The one floating surface every pop-up in the chrome rides: menus, swatch
 * and choice wells, color fields, pickers. The surface owns where a
 * pop-up lands and how it leaves; what shows inside is the rider's. Any
 * chrome library declares daedalus_ui/popover as a dependency and
 * opens it imperatively:
 * @code
 *   const root = Drupal.Daedalus.popover.open({
 *     anchor: kebab,        // or x, y from a pointer
 *     side: 'above',        // 'below' (default) or 'above': the preferred side
 *     align: 'end',         // 'start' (default) or 'end': which edges align
 *     content: list,        // optional: a Node appended before placement
 *     onClose,
 *   });
 *   // ...or fill root later (a portal); the surface re-places on resize.
 * @endcode
 * open() returns the root Element (`.daedalus-ui-popover`), appended to the
 * running document's body and positioned fixed: on the anchor's preferred
 * `side` (below by default; above for a rider that must never cover what
 * sits under its anchor), flipping to the other side only when the
 * preferred one is short and the other fits, start or end edges aligned
 * per `align`, clamped into the viewport; or at the pointer.
 *
 * A plain-DOM host over the pure math in placement.js, following
 * dialog.js and menu.js: an unbundled module script that appends its own
 * root to whichever document runs it, so a separate window (daedalus_stylish's
 * satellite) gets its own copy by declaring the dependency. Content that
 * is a Preact tree portals into the root; Preact cannot cross separately
 * built bundles, but a portal into a plain element can.
 *
 * The host owns every surface concern:
 * - Placement: measured hidden, placed, then revealed (no flicker); a
 *   ResizeObserver re-places whenever the content's size changes, so a
 *   root filled after open() (a portal's first render, a picker's
 *   search results) lands correctly too. An empty root stays invisible
 *   (popover.css), so nothing shows before the content does.
 * - Dismissal: outside pointerdown, outside contextmenu, outside scroll,
 *   window resize, window blur. The anchor counts as inside, so a
 *   trigger button's own pointerdown never dismisses the surface it is
 *   about to toggle. Pointer dismissal does NOT restore focus (the
 *   pointer said where focus goes next).
 * - Escape, at document capture: closes, stops propagation (the
 *   document-level hotkey dispatcher never sees it), and restores focus
 *   to the opener captured at open. close({ restoreFocus: true }) gives a
 *   rider the same leave for its own keyboard exits (a menu's Tab, an
 *   item that ran).
 * - The singleton rule: one surface per window. A second open() replaces
 *   the first, firing its onClose.
 *
 * Surface chrome (border, background, radius, shadow, chrome typeface,
 * z-index) lives on `.daedalus-ui-popover` in popover.css; the content styles its
 * own inside. The root appends to body, outside the canvas and outside
 * #daedalus-root, so the selection grammar's document-level click handler
 * (scoped to [data-main-entity]) leaves its clicks alone.
 *
 * The root declares data-keeps-edit: the edit session's rule is that
 * chrome which keeps a live edit declares it on its own root
 * (edit-session.js), and a press inside a pop-up never ends the edit it
 * opened from (a widget popover over a text edit, a stylish well while
 * an inline edit is live). Every rider inherits this from the surface.
 */
import { clampPosition, anchoredPosition } from './placement.js';

Drupal.Daedalus = Drupal.Daedalus || {};

/**
 * The mounted surface: null while closed.
 *
 * @type {?{root: Element, opener: ?Element, onClose: ?Function,
 *   teardown: Function}}
 */
let mounted = null;

/**
 * Closes the surface. Idempotent; fires onClose exactly once per open.
 *
 * @param {object} options
 *   {restoreFocus?}: whether the opener captured at open gets its focus
 *   back (a keyboard leave), default false (a pointer leave).
 */
const close = ({ restoreFocus = false } = {}) => {
  if (!mounted) {
    return;
  }
  const { opener, onClose, teardown } = mounted;
  mounted = null;
  teardown();
  if (restoreFocus && opener && opener.isConnected) {
    opener.focus();
  }
  onClose?.();
};

const isOpen = () => mounted !== null;

/**
 * Opens the surface, replacing any open one.
 *
 * @param {object} spec
 *   {anchor?, x?, y?, side?, align?, content?, onClose?}: either an
 *   anchor Element or both viewport coordinates; side is 'below'
 *   (default) or 'above', the preferred side of the anchor; align is
 *   'start' (default) or 'end'; content is an optional Node appended
 *   before placement.
 *
 * @return {Element}
 *   The surface root, for the rider to fill.
 */
const open = ({
  anchor = null,
  x = null,
  y = null,
  side = 'below',
  align = 'start',
  content = null,
  onClose = null,
}) => {
  if (!anchor && (typeof x !== 'number' || typeof y !== 'number')) {
    throw new Error('A surface opens at {x, y} or under an anchor.');
  }
  if (side !== 'below' && side !== 'above') {
    throw new Error(`A surface sides 'below' or 'above', not "${side}".`);
  }
  if (align !== 'start' && align !== 'end') {
    throw new Error(`A surface aligns 'start' or 'end', not "${align}".`);
  }
  close();

  // The element focused when the surface opened, captured BEFORE the
  // rider moves focus inside.
  const opener = document.activeElement;
  const root = document.createElement('div');
  root.className = 'daedalus-ui-popover';
  // Chrome that keeps a live edit declares it on its own root; a press
  // inside a pop-up never ends the edit it opened from.
  root.setAttribute('data-keeps-edit', '');
  root.style.visibility = 'hidden';
  if (content) {
    root.appendChild(content);
  }
  document.body.appendChild(root);

  // Measure, place, reveal: one synchronous pass per size, no flicker.
  const place = () => {
    const measured = {
      width: root.offsetWidth,
      height: root.offsetHeight,
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
    };
    const position = anchor
      ? anchoredPosition({
          rect: anchor.getBoundingClientRect(),
          side,
          align,
          ...measured,
        })
      : clampPosition({ x, y, ...measured });
    root.style.left = `${position.left}px`;
    root.style.top = `${position.top}px`;
    root.style.visibility = 'visible';
  };
  place();
  const observer = new ResizeObserver(place);
  observer.observe(root);

  const inside = (target) =>
    root.contains(target) || (anchor?.contains(target) ?? false);
  const dismiss = () => close();
  const dismissIfOutside = (event) => {
    if (!inside(event.target)) {
      dismiss();
    }
  };
  const onKeyDown = (event) => {
    if (event.key !== 'Escape') {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    close({ restoreFocus: true });
  };
  document.addEventListener('keydown', onKeyDown, true);
  document.addEventListener('pointerdown', dismissIfOutside, true);
  document.addEventListener('contextmenu', dismissIfOutside, true);
  document.addEventListener('scroll', dismissIfOutside, true);
  window.addEventListener('resize', dismiss);
  window.addEventListener('blur', dismiss);
  const teardown = () => {
    observer.disconnect();
    document.removeEventListener('keydown', onKeyDown, true);
    document.removeEventListener('pointerdown', dismissIfOutside, true);
    document.removeEventListener('contextmenu', dismissIfOutside, true);
    document.removeEventListener('scroll', dismissIfOutside, true);
    window.removeEventListener('resize', dismiss);
    window.removeEventListener('blur', dismiss);
    root.remove();
  };

  mounted = { root, opener, onClose, teardown };
  return root;
};

Drupal.Daedalus.popover = { open, close, isOpen };
