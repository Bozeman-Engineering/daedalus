/**
 * The tree's own glyphs: the icon zone of a row the annotator does not
 * mint an icon for.
 *
 * An element's icon is its third minted word (`_element.icon`, a URL
 * delivered on the mirror beside `type` and `name`: BlueprintElementAnnotator,
 * through the daedalus_ui.element_icon_provider collector), and the tree
 * reads it as data like the other two. Pseudo rows are daedalus_ui's own
 * anatomy and never reach the annotator: a shell part (a declared wrapper
 * the Markup view draws), a generated box (`&::before`, `&::after`) and an
 * instance carrier (a paragraph, a heading, a list item minted in rich
 * text) each pick their glyph HERE, client-side, from the one fact that
 * says what they are: the tag they rendered as. Model rows the annotator
 * names but does not icon (a container or an inline block on a site with
 * no provider answering) and rows it does not name at all (a field row, a
 * region, an inline entity, a value row) fall back to their kind's glyph.
 * A small map, not a seam: nothing outside daedalus_ui has a say in what
 * daedalus_ui's own anatomy looks like.
 *
 * Every glyph is a data URL of a 16-unit stroke drawing, so the icon zone
 * paints it the way it paints a minted URL: as a mask in the row's
 * current color (panel-controls.css .daedalus-ui-outline__icon). The
 * stroke color is irrelevant to a mask; only the alpha draws.
 */

const glyph = (body) =>
  `data:image/svg+xml,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" stroke="#000" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`,
  )}`;

const PARAGRAPH = glyph('<path d="M3 5h10M3 8h10M3 11h6"/>');
const HEADING = glyph('<path d="M3.5 3v10M3.5 8h6.5M10 3v10"/>');
const LIST = glyph(
  '<circle cx="3.5" cy="4" r="0.9" fill="#000" stroke="none"/><circle cx="3.5" cy="8" r="0.9" fill="#000" stroke="none"/><circle cx="3.5" cy="12" r="0.9" fill="#000" stroke="none"/><path d="M6.5 4h7M6.5 8h7M6.5 12h7"/>',
);
const NUMBERED_LIST = glyph(
  '<path d="M6.5 4h7M6.5 8h7M6.5 12h7"/><path d="M2.5 3.2h1.2v2.2M2.5 7.1h1.7l-1.7 1.9h1.7M2.5 10.8h1.5v2.4h-1.5M4 12h-1"/>',
);
const LIST_ITEM = glyph(
  '<circle cx="4" cy="8" r="1.2" fill="#000" stroke="none"/><path d="M7 8h6"/>',
);
const QUOTE = glyph(
  '<path d="M4 5.5h3v3H5.5A1.5 1.5 0 0 1 4 7zM9.5 5.5h3v3H11a1.5 1.5 0 0 1-1.5-1.5z"/><path d="M7 8.5v1.5a2 2 0 0 1-2 2M12.5 8.5v1.5a2 2 0 0 1-2 2"/>',
);
const LINK = glyph(
  '<path d="M6.5 9.5l3-3M5 8l-1.5 1.5a2.12 2.12 0 0 0 3 3L8 11M11 8l1.5-1.5a2.12 2.12 0 0 0-3-3L8 5"/>',
);
const IMAGE = glyph(
  '<rect x="2.5" y="3" width="11" height="10" rx="1"/><circle cx="6" cy="6.5" r="1"/><path d="M3 12l3.5-3.5 2.5 2.5 2-2 2.5 2.5"/>',
);
const TABLE = glyph(
  '<rect x="2.5" y="3" width="11" height="10" rx="1"/><path d="M2.5 7h11M8 3v10"/>',
);
const CODE = glyph('<path d="M5.5 5l-3 3 3 3M10.5 5l3 3-3 3"/>');
const RULE = glyph('<path d="M3 8h10"/>');
const ANATOMY = glyph(
  '<rect x="2.5" y="2.5" width="11" height="11" rx="1" stroke-dasharray="2 1.5"/>',
);
const BOX = glyph(
  '<rect x="2.5" y="2.5" width="11" height="11" rx="1" stroke-dasharray="2 1.5"/><path d="M8 5.5v5M5.5 8h5"/>',
);
const PAGE = glyph('<path d="M4 1.5h5.5L13 5v9.5H4z"/><path d="M9.5 1.5V5H13"/>');
const CONTAINER = glyph('<rect x="2" y="3" width="12" height="10" rx="1"/>');
const BLOCK = glyph('<rect x="2.5" y="2.5" width="11" height="11" rx="1.5"/>');
const FIELD = glyph('<path d="M5.5 3H3v10h2.5M10.5 3H13v10h-2.5"/>');
const REGION = glyph(
  '<rect x="2" y="2.5" width="12" height="11" rx="1"/><path d="M8 2.5v11"/>',
);

/**
 * The tag-to-glyph map for pseudo rows. A tag outside it (a div, a span,
 * a section, a custom element) is anatomy.
 */
const TAG_GLYPHS = {
  p: PARAGRAPH,
  h1: HEADING,
  h2: HEADING,
  h3: HEADING,
  h4: HEADING,
  h5: HEADING,
  h6: HEADING,
  ul: LIST,
  dl: LIST,
  ol: NUMBERED_LIST,
  li: LIST_ITEM,
  dt: LIST_ITEM,
  dd: LIST_ITEM,
  blockquote: QUOTE,
  q: QUOTE,
  a: LINK,
  img: IMAGE,
  svg: IMAGE,
  picture: IMAGE,
  figure: IMAGE,
  video: IMAGE,
  table: TABLE,
  pre: CODE,
  code: CODE,
  hr: RULE,
};

/**
 * The kind-to-glyph map for model rows with no minted icon.
 */
const KIND_GLYPHS = {
  entity: PAGE,
  container: CONTAINER,
  block: BLOCK,
  field: FIELD,
  region: REGION,
  value: PARAGRAPH,
};

/**
 * The glyph for a pseudo row that rendered as a tag: a shell part's
 * resolved (or declared) tag, an instance carrier's.
 *
 * @param {?string} tag
 *   The lowercase tag name, or null when the model does not speak.
 *
 * @return {string}
 *   A data URL.
 */
export const tagGlyph = (tag) => TAG_GLYPHS[tag] ?? ANATOMY;

/**
 * The glyph for a generated box (`&::before`, `&::after`): a box the
 * cascade drew, no tag of its own.
 *
 * @return {string}
 *   A data URL.
 */
export const boxGlyph = () => BOX;

/**
 * The glyph for a shown-document row (shown-rows.js): a blueprint a door
 * shows by reference, the link glyph, since the document is linked in,
 * not owned. The row is the tree's own anatomy (the shown document is no
 * element of this host, so the annotator mints nothing for it), so it
 * picks its glyph here like a pseudo row.
 *
 * @return {string}
 *   A data URL.
 */
export const shownGlyph = () => LINK;

/**
 * The glyph for a model row by its kind, the fallback when the annotator
 * minted no icon (`_element.icon` null, or a row it does not name).
 *
 * @param {string} kind
 *   The row's kind (projection.js: entity, container, block, field,
 *   region, value).
 *
 * @return {string}
 *   A data URL.
 */
export const kindGlyph = (kind) => KIND_GLYPHS[kind] ?? BLOCK;
