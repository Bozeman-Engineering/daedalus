/**
 * The canvas-view axis, written by this module and read by everyone else.
 *
 * daedalus_ui owns the axis: `data-daedalus-ui-canvas-view` on <html>
 * (daedalus_ui.api.php, canvas_view), the precedent of
 * data-daedalus-ui-mode and data-daedalus-ui-chrome-density. This module
 * is a writer of the one value `exploded`; every owner of rect-measured
 * chrome (the selection frame, the insertion caret, the indicator, the
 * canvas-vector handles and ruler, daedalus_stylish's bands and paint)
 * hides under the attribute in its OWN stylesheet. Explode never names
 * another module's classes.
 *
 * Every one of those hide rules is display-based, so the chrome
 * reappears the instant the attribute drops: the host (T6) must let the
 * lift transition back before calling leave(), or the frames would paint
 * against still-projected rects.
 */

export const ATTRIBUTE = 'data-daedalus-ui-canvas-view';

export const VIEW = 'exploded';

/**
 * Whether the document is in the exploded view right now.
 *
 * @return {boolean}
 */
export const isExploded = () =>
  document.documentElement.getAttribute(ATTRIBUTE) === VIEW;

/**
 * Enter the exploded view: stamp the attribute on <html>.
 */
export const enter = () => {
  document.documentElement.setAttribute(ATTRIBUTE, VIEW);
};

/**
 * Leave the exploded view: clear the attribute from <html>.
 *
 * Only this module's value is cleared; another writer's view is left
 * alone (the axis carries one value at a time, but a foreign one is not
 * ours to drop).
 */
export const leave = () => {
  if (isExploded()) {
    document.documentElement.removeAttribute(ATTRIBUTE);
  }
};
