/**
 * @file
 * The collapse controls' two glyphs, shared by every outline.
 *
 * Inline SVG strings, the picker glyph's precedent
 * (page-elements/picker-toggle.js): the server icon pack
 * (daedalus_ui.icons.yml) renders through Twig, which Preact cannot call,
 * and neither webpack config has an SVG rule, so the one home JS can
 * import is a string in a module. Both glyphs are stroke-drawn in
 * currentColor at the picker's weight (24 box, 1.8 stroke, round caps
 * and joins) so the three read as one set where they sit together.
 *
 * Two chevrons on the vertical axis, the way code editors draw collapse
 * all and expand all: pointing toward each other for collapse (the rows squeeze
 * together), away from each other for expand (the rows spread apart).
 */

/**
 * Collapse all: chevrons together, the top one pointing down, the bottom
 * one pointing up, meeting around the middle.
 */
export const collapseAllIcon =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l4 4 4-4"/><path d="M8 19l4-4 4 4"/></svg>';

/**
 * Expand all: chevrons apart, the top one pointing up, the bottom one
 * pointing down, leaving the middle open.
 */
export const expandAllIcon =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9l4-4 4 4"/><path d="M8 15l4 4 4-4"/></svg>';
