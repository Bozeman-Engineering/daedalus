/**
 * @file
 * The selection reveal rule's pure half: which way an element scrolls into
 * its scrollport. The DOM host (js/scroll-reveal.js) measures the element,
 * its scroll margins and its nearest scrolling ancestor, and applies the
 * decision made here through scrollIntoView.
 */

/**
 * Decides how a selection reveal scrolls an element into view.
 *
 * One rule for every surface that reveals the selection (the canvas from a
 * panel gesture, the page elements row, the code view's row): the whole
 * element in view, centered when it fits, and never moved when it is
 * already fully in view. Every measure is in the same coordinate space
 * (viewport px) and the scroll margins count as part of the element: on
 * the canvas the top margin is the fixed chrome's height and the bottom
 * margin the floating selection indicator's (css/edit-mode.css), so "in
 * view" means visible, not merely inside the viewport.
 *
 * @param {object} box
 *   The measured box.
 * @param {number} box.top
 *   The element's top edge.
 * @param {number} box.height
 *   The element's height.
 * @param {{top: number, height: number}} box.scrollport
 *   The nearest scrollport's top edge and height (the window's for the
 *   canvas, the panel body's for a row).
 * @param {number} [box.marginTop=0]
 *   The element's scroll-margin-top.
 * @param {number} [box.marginBottom=0]
 *   The element's scroll-margin-bottom.
 *
 * @return {?string}
 *   The scrollIntoView block alignment: 'center' when the element with its
 *   margins fits the scrollport, 'start' when it does not (its top edge
 *   lands under the fixed chrome, the margin doing the offset math), null
 *   when the element with its margins is already fully inside and must not
 *   move.
 */
export const revealBlock = ({
  top,
  height,
  scrollport,
  marginTop = 0,
  marginBottom = 0,
}) => {
  const outerTop = top - marginTop;
  const outerBottom = top + height + marginBottom;
  const portBottom = scrollport.top + scrollport.height;
  if (outerTop >= scrollport.top && outerBottom <= portBottom) {
    return null;
  }
  return outerBottom - outerTop <= scrollport.height ? 'center' : 'start';
};
