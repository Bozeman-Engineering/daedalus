/**
 * @file
 * The one way a menu entry previews a selector pair on the canvas.
 *
 * A menu entry that would seat a rule (the address chip's entries,
 * subject-line.js; a provenance row menu's "Edit at", use-row-menu.js)
 * hands its pair here from the menu primitive's `preview(active)`
 * (daedalus_ui menu-state.js: the active item under the pointer or the
 * keyboard focus, told when it becomes active and when it stops). Active
 * drives the spine's hover-selector facet through the source, the facet
 * code mode's row headers and the page tree's part rows already write,
 * so canvas-selector-paint.js paints the pair's reach dashed before any
 * pick and the pop-out rides its hover-selector intent. Leaving clears
 * only while the hovered pair is still this one: the standing enter /
 * leave contract every writer of the facet keeps, so no surface
 * clobbers another's hover. A source without the facet (a row handed
 * none) makes the preview inert.
 */

/**
 * @param {?object} source
 *   The state source (sources/leader-source.js or satellite-source.js).
 * @param {{key: string, selector: string}} pair
 *   The owner key and relative selector the entry would seat.
 * @param {boolean} active
 *   Whether the entry just became the active item (true) or stopped
 *   being it (false).
 */
export const previewPair = (source, pair, active) => {
  if (!source?.hoverSelector) {
    return;
  }
  if (active) {
    source.hoverSelector(pair.key, pair.selector);
    return;
  }
  const current = source.getHoveredSelector?.() ?? null;
  if (current?.key === pair.key && current?.selector === pair.selector) {
    source.hoverSelector(null);
  }
};
