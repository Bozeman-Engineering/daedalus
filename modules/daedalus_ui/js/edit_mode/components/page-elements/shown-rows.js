/**
 * The shown-document row: what a door shows by reference, under the door.
 *
 * A slot door (`daedalus_slot:{id}`) with a default blueprint renders that
 * blueprint's document inside itself, by reference: the nodes are the
 * blueprint host's and never enter this canvas's lean tree, so nothing in
 * the mirror is an element to project under the door. The spine still
 * says what the door shows: the annotator stamps `_element.showing` on
 * every join (VerdictStamper::showing(), one answer per door per canvas
 * per actor: `shows`, `document`, `blueprint`, `portal`, `claimable`).
 * This module is the tree's adapter over that one answer, the Page
 * elements panel following the render under every door the way
 * slot-fills.js nests a locked page's fills: one child row per door
 * showing a DEFAULT, named by the shown blueprint's label, typed
 * "Blueprint, by reference", wearing the link glyph (row-glyphs.js: the
 * document is linked in, not owned), composed AFTER the fill rows by both
 * projections (projection.js componentNode, compose.js composeComponent).
 *
 * The row is a row of its own kind, neither a model row nor a pseudo row:
 * `shown: true`, no key (the shown document is not an element of this
 * host: not a selection, not a styling address, not in the projection
 * index, not in reach; the index walks it as transparent since it has no
 * address and nothing under it), `parentKey` the door's key (the outline
 * ids it `shown:{parentKey}`, tree-outline.js) and the door's `showing`
 * riding along for the row's doorways. A door showing `fill` already has
 * its fill rows, `route` is the canvas itself and `nothing` has nothing to
 * name, so none of those grows a row.
 *
 * Pure over the lean node: the mirror carries the showing, this reads it
 * and derives nothing. The doorways (shownDoorways) are read at menu-open
 * time in the browser: Edit reaches the one leave through the Drupal
 * facade, the rest are the doorway registry's (portal-actions.js), the
 * same actions the portal chip draws on the canvas.
 */

import { shownGlyph } from './row-glyphs.js';
import { portalActionItems } from '../portal/portal-actions.js';

/**
 * The showing kind that grows a row: the door's default, shown by
 * reference (Showing::DEFAULT).
 */
const SHOWS_DEFAULT = 'default';

/**
 * The row's type word (Decisions: the shown-document row typed
 * "Blueprint, by reference"). The one word the tree mints for this row;
 * its name is the spine's (the shown document's label).
 */
export const SHOWN_TYPE = 'Blueprint, by reference';

/**
 * A door's showing when it shows a default, else null.
 */
const shownDefault = (component) => {
  const showing = component?._element?.showing;
  return showing?.shows === SHOWS_DEFAULT && showing.document ? showing : null;
};

/**
 * The shown-document rows under a block node: one for a door (or a linked
 * placement, the other join) showing a default blueprint, none otherwise.
 *
 * @param {Object} component
 *   A lean block node, its `_element` annotation riding it.
 *
 * @return {Array<{shown: true, label: string, elementType: string, icon: string, showing: Object, parentKey: string, children: Array}>}
 *   Zero or one row.
 */
export const shownRows = (component) => {
  const showing = shownDefault(component);
  if (!showing) {
    return [];
  }
  return [
    {
      shown: true,
      label: showing.document.label,
      elementType: SHOWN_TYPE,
      icon: shownGlyph(),
      showing,
      parentKey: `block:${component.uuid}`,
      children: [],
    },
  ];
};

/**
 * The door's component uuid behind a shown row: its parentKey is the
 * door's selectable key, `block:{uuid}`.
 *
 * @param {{parentKey: string}} row
 *   A shown row.
 *
 * @return {string}
 */
export const shownDoorUuid = (row) => row.parentKey.slice('block:'.length);

/**
 * The row's doorways, in the menu grammar (js/menu/menu-state.js): where
 * the shown document is edited, then what this canvas may do to it. Edit
 * leaves this page for the shown blueprint's canvas through the one leave
 * every portal gesture shares (Drupal.Daedalus.Portal.leave,
 * selection-facade.js: waits for writes on the wire, carries this page as
 * the way back), offered only when the showing carries a portal with a url
 * (the actor may update the blueprint). After it come the registered
 * doorways that apply to the showing (portal-actions.js: the claim,
 * "Detach into this slot"), bound to the door's uuid, the same run the
 * portal chip calls on the canvas.
 *
 * @param {Object} showing
 *   The row's `showing`.
 * @param {?string} uuid
 *   The door's component uuid (shownDoorUuid).
 *
 * @return {Array<{label: string, icon?: ?string, run: function}>}
 */
export const shownDoorways = (showing, uuid = null) => {
  const url = showing?.portal?.url;
  const edit =
    typeof url === 'string' && url !== ''
      ? [
          {
            label: Drupal.t('Edit'),
            run: () => Drupal.Daedalus.Portal.leave(url),
          },
        ]
      : [];
  return [...edit, ...portalActionItems(showing, uuid)];
};
