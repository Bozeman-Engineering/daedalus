/**
 * @file
 * The panel's DOM contract: its ids, its stable hooks and what a row says.
 *
 * The component draws the rows and the insert session reads them, so
 * the two meet here and nowhere else. Every hook is a data attribute
 * (`data-daedalus-layout-add-panel-*`), never an id or a class, because
 * the same face is in the DOM once per set it belongs to (a row is keyed
 * by set and face, faces.js rowKey) and because the look may change
 * while the contract holds. The FJS classes key on these hooks.
 *
 * A row carries three facts: the face it shows (ROW: the face id, a
 * block plugin id or `blueprint:ID`), the set it sits in (IN: the set
 * id, or the search's), and what a drop of it is (KIND: `container`,
 * `blueprint` or `block`), so the session can arm a drag source from
 * the element alone, with no store lookup.
 */

import { CONTAINER_ID, blueprintRecordId, isBlueprintId } from './model/faces.js';

/**
 * The panel's own id (the Sidebar plugin id): its DOM id and cookie
 * identity.
 */
export const PANEL_ID = 'place_block';

/**
 * The mount's id (PlaceBlock::MOUNT_ID).
 */
export const MOUNT_ID = 'daedalus-layout-add-panel';

/**
 * The header slot's id (PlaceBlock::TOOLS_ID), where the collapse pair
 * sits.
 */
export const TOOLS_ID = 'daedalus-layout-add-panel-tools';

/**
 * The hooks.
 */
export const ATTR = Object.freeze({
  mount: 'data-daedalus-layout-add-panel-mount',
  tools: 'data-daedalus-layout-add-panel-tools',
  search: 'data-daedalus-layout-add-panel-search',
  list: 'data-daedalus-layout-add-panel-list',
  // A heading: the set's id; `aria-expanded` is its posture.
  set: 'data-daedalus-layout-add-panel-set',
  // A row: the face's id.
  row: 'data-daedalus-layout-add-panel-row',
  // A row: the set it sits in.
  in: 'data-daedalus-layout-add-panel-in',
  // A row: what a drop of it is.
  kind: 'data-daedalus-layout-add-panel-kind',
  // The star: `aria-pressed` is the effective favorite.
  star: 'data-daedalus-layout-add-panel-star',
  // A row's home: the kind it lives under, named beside the label (a
  // search hit, a favorite from elsewhere in All).
  home: 'data-daedalus-layout-add-panel-home',
  // An empty sentence: `favorites` or `search`.
  empty: 'data-daedalus-layout-add-panel-empty',
  // The search's count line.
  count: 'data-daedalus-layout-add-panel-count',
  // The peek's layer beside the panel: its state, `loading`, `ready` or
  // `refused`. Absent while nothing peeks.
  peek: 'data-daedalus-layout-add-panel-peek',
  // The peek's layer: the face it shows.
  peekFor: 'data-daedalus-layout-add-panel-peek-for',
  // The peek's frame, whose srcdoc is the door's document.
  peekFrame: 'data-daedalus-layout-add-panel-peek-frame',
});

/**
 * The peek layer's id: one per document, appended to body (outside the
 * rail, which clips) while the panel is mounted.
 */
export const PEEK_ID = 'daedalus-layout-add-panel-peek';

/**
 * The three row kinds.
 */
export const ROW_KIND = Object.freeze({
  container: 'container',
  blueprint: 'blueprint',
  block: 'block',
});

export const ROW_SELECTOR = `[${ATTR.row}]`;
export const HEADING_SELECTOR = `[${ATTR.set}]`;
export const STOP_SELECTOR = `${HEADING_SELECTOR}, ${ROW_SELECTOR}`;
export const STAR_SELECTOR = `[${ATTR.star}]`;
export const SEARCH_SELECTOR = `[${ATTR.search}]`;

/**
 * The panel element, or null off an editable page.
 */
export const panelElement = () => document.getElementById(PANEL_ID);

/**
 * The mount, or null.
 */
export const mountElement = () => document.getElementById(MOUNT_ID);

/**
 * A face's row kind.
 */
export const rowKindOf = (faceId) => {
  if (faceId === CONTAINER_ID) {
    return ROW_KIND.container;
  }
  return isBlueprintId(faceId) ? ROW_KIND.blueprint : ROW_KIND.block;
};

/**
 * The drag source a row places through, and its drag data.
 *
 * The Container is a plain section drop (no data); a blueprint rides the
 * section caret through its own source carrying the record's id; every
 * other face is a block. One reading for the keyboard lift, the click-
 * to-place and the drag handle, so every door answers the source the
 * row's drag would arm. A blueprint row placed LINKED (the row menu's
 * "Place linked") is a block drop through the linked source: one node
 * showing the blueprint by reference, landing where a block lands.
 *
 * @param {Element} row
 *   A row element.
 * @param {{linked?: boolean}} [options]
 *   linked: place the blueprint by reference instead of as a copy.
 *
 * @return {{source: string, data: object, placingSection: boolean}}
 */
export const rowSource = (row, { linked = false } = {}) => {
  const id = row.getAttribute(ATTR.row);
  const kind = row.getAttribute(ATTR.kind);
  if (kind === ROW_KIND.container) {
    return { source: 'new_section', data: {}, placingSection: true };
  }
  if (kind === ROW_KIND.blueprint) {
    if (linked) {
      return {
        source: 'new_blueprint_block',
        data: { blueprintId: blueprintRecordId(id), linked: true },
        placingSection: false,
      };
    }
    return {
      source: 'new_blueprint_section',
      data: { blueprintId: blueprintRecordId(id) },
      placingSection: true,
    };
  }
  return { source: 'new_block', data: { pluginId: id }, placingSection: false };
};

/**
 * The rows drawn in the panel, in DOM order, the visible ones only.
 *
 * Folded sets draw no rows, so "drawn" is nearly "visible"; the
 * offsetParent read covers a hidden panel and a row scrolled out of a
 * closed ancestor the same way the old list did.
 */
export const visibleRows = () =>
  Array.from(mountElement()?.querySelectorAll(ROW_SELECTOR) ?? []).filter(
    (row) => row.offsetParent !== null,
  );

/**
 * Every stop of the panel's roving order, in DOM order: the headings and
 * the rows.
 */
export const allStops = () =>
  Array.from(mountElement()?.querySelectorAll(STOP_SELECTOR) ?? []);
