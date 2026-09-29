/**
 * Runs a canvas subtree swap without moving the window.
 *
 * The contract: a canvas subtree swap never moves the window. Every canvas
 * replacer (the op-queue's markup swap, the composed preview's root
 * transition) runs its DOM removal inside this helper, on every engine.
 *
 * The reason: WebKit clamps the window scroll synchronously inside a DOM
 * removal that leaves the in-flow document shorter than the viewport, before
 * the replacement's renderers exist (they are built at the next rendering
 * update). An "insert new, remove old" swap of the page's main content
 * therefore lands the window wherever the remaining chrome allows, on the
 * line of the removal itself. Chromium and Gecko defer the clamp to layout
 * time, when the replacement is already there, so on those engines the
 * restore below writes the position the window already has.
 *
 * The clamp and the restore happen inside one task, so nothing paints in
 * between and no layout is forced; reading a layout property before the
 * removal would cost a layout of the document with both roots present.
 *
 * @param {Function} fn
 *   The swap. Its return value is passed through. If it throws, the
 *   exception propagates and no restore is attempted.
 * @param {Window} win
 *   The window whose scroll offsets are held. Injectable for the spec.
 *
 * @return {*}
 *   Whatever fn returned.
 */
export const preserveScroll = (fn, win = window) => {
  const left = win.scrollX;
  const top = win.scrollY;
  const result = fn();
  win.scrollTo({ left, top, behavior: 'instant' });
  return result;
};
