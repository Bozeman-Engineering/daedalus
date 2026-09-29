/**
 * @file
 * The selection reveal rule (Drupal.Daedalus.scrollReveal): the one way a
 * surface scrolls the selection into view.
 *
 * Every surface that reveals the selection calls this instead of
 * scrollIntoView with an alignment of its own: the canvas from a panel
 * gesture (a tree row's click, a breadcrumb crumb, arrow traversal, the
 * lift walk, F6, a code-view row's click, a notification's reveal), the
 * page elements row when it becomes the selection, and the code view's
 * row. The rule (js/scroll-reveal-model.js revealBlock): the whole element
 * in view, centered in its nearest scrollport when it fits with its scroll
 * margins, its top edge aligned under the fixed chrome when it does not,
 * and never moved when it is already fully in view.
 * @code
 *   Drupal.Daedalus.scrollReveal(element, { behavior: 'smooth' });
 * @endcode
 * `behavior` is scrollIntoView's; omitted, the browser's default applies.
 * List rovings (a picker's option, a menu's row) are not selection reveals
 * and keep their own `nearest` scroll.
 *
 * The scrollport is the element's nearest ancestor that scrolls vertically
 * (a panel body for a row), else the window (the canvas). The scroll
 * margins are the element's computed ones: css/edit-mode.css gives every
 * canvas element a top margin clearing the fixed top bar and a bottom
 * margin clearing the floating selection indicator, so the same margin that
 * scrollIntoView honors also decides "fully in view" here, and no caller
 * carries offset math.
 *
 * Not the chrome reveal engine: Drupal.Daedalus.reveal (js/reveal.js) is
 * the pointer-proximity engine that shows concealed chrome. Standalone on
 * purpose: any chrome library (a separate window's included; daedalus_stylish's
 * satellite runs the code view's row reveal in its own document) declares
 * daedalus_ui/scroll_reveal as a dependency and reads the facade at call
 * time, never a relative import. A native ES module (it imports its pure
 * model relative to its own URL), so it runs deferred.
 */
import { revealBlock } from './scroll-reveal-model.js';

Drupal.Daedalus = Drupal.Daedalus || {};

/**
 * The overflow-y values under which an ancestor is a scrolling box.
 */
const SCROLLING = new Set(['auto', 'scroll', 'overlay']);

/**
 * The element's nearest ancestor that can scroll vertically, or null when
 * the window is its scrollport. The root and body are the window's: their
 * overflow propagates to the viewport.
 *
 * @param {Element} element
 *   The element to be revealed.
 *
 * @return {?Element}
 *   The scrolling ancestor, or null for the window.
 */
const scrollerOf = (element) => {
  const doc = element.ownerDocument;
  const view = doc.defaultView;
  for (
    let node = element.parentElement;
    node && node !== doc.documentElement && node !== doc.body;
    node = node.parentElement
  ) {
    if (
      SCROLLING.has(view.getComputedStyle(node).overflowY) &&
      node.scrollHeight > node.clientHeight
    ) {
      return node;
    }
  }
  return null;
};

/**
 * The scrollport's top edge and height in viewport px: the scroller's
 * padding box, or the window's.
 *
 * @param {Element} element
 *   The element to be revealed.
 * @param {?Element} scroller
 *   Its scrolling ancestor, or null for the window.
 *
 * @return {{top: number, height: number}}
 *   The scrollport.
 */
const scrollportOf = (element, scroller) => {
  if (!scroller) {
    return { top: 0, height: element.ownerDocument.documentElement.clientHeight };
  }
  const rect = scroller.getBoundingClientRect();
  return { top: rect.top + scroller.clientTop, height: scroller.clientHeight };
};

/**
 * Reveals an element by the selection reveal rule.
 *
 * @param {?Element} element
 *   The element to reveal; null (a key the canvas is not rendering, a row
 *   the tree has not mounted) reveals nothing.
 * @param {object} [options]
 *   Options object.
 * @param {string} [options.behavior]
 *   scrollIntoView's behavior ('smooth', 'instant'); the browser's default
 *   when omitted.
 *
 * @return {?string}
 *   The alignment applied ('center' or 'start'), or null when nothing
 *   moved.
 */
Drupal.Daedalus.scrollReveal = (element, { behavior } = {}) => {
  if (!element) {
    return null;
  }
  const rect = element.getBoundingClientRect();
  const style = element.ownerDocument.defaultView.getComputedStyle(element);
  const block = revealBlock({
    top: rect.top,
    height: rect.height,
    scrollport: scrollportOf(element, scrollerOf(element)),
    marginTop: parseFloat(style.scrollMarginTop) || 0,
    marginBottom: parseFloat(style.scrollMarginBottom) || 0,
  });
  if (block) {
    element.scrollIntoView(behavior ? { block, behavior } : { block });
  }
  return block;
};
