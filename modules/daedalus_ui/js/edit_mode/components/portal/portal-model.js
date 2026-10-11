/**
 * The portal chip's pure model: which element is the portal for a pointer
 * target, what the chip reads off it, whether a double-click leaves the
 * page, and the URL a leave navigates to. No DOM lookups of its own and
 * no Drupal globals; the Preact host (portal.js) hands it the pointer
 * target and paints the answer, so the reasoning is node-testable
 * (tests/js/portal-model.test.mjs).
 *
 * A PORTAL is a placed document that is inert on this page and edited at
 * its own root: a linked blueprint (the site header, the footer), any
 * node of the site frame around the page, a template's node on a locked
 * page, other than a field block presenting the page's own field (that
 * field is the page's to edit). The server projects the spine's answers
 * onto the placement root (LayoutBuilderBlockAttributes::stampShowing):
 * the editing URL, the label and the kind word of the portal host (the
 * blueprint a join shows, from the spine's showing; the holder of any
 * other foreign node, from its FOREIGN_DOCUMENT verdict on content), the
 * kind being what the shown document is to the editor ("Blueprint", "Site
 * frame", "Template"), read into the chip's label as "Header · blueprint"
 * (portalChipLabel). A slot door showing its default blueprint carries one
 * more stamp when this page may claim it (SlotBlock::CLAIM_ATTRIBUTE): the
 * default's machine name, beside the slot id every door wears, so the chip
 * offers the claim (detach the default into this slot as the page's own
 * copy) through the doorway registry (portal-actions.js), with or without
 * an editing URL (an editor may claim a header they may not edit). The
 * registry takes the spine's showing, so the chip reads the stamps back
 * into that shape (portalShowing). Those attributes are the whole contract
 * between the stamps and this chip. The portal's SUBTREE sits outside the projection: nothing inside
 * it resolves as a selectable, so the chip is not a selection and never
 * competes with the selection frame's hover. Two exceptions share one rule
 * (portalUnder): a slot door is a portal whose subtree holds the page's
 * OWN fill, and a portal may sit inside presented markup (a block of the
 * page that holds the placement), so a key resolved INSIDE the portal is
 * the page's own and sets the portal aside, while a selectable ABOVE it
 * resolves as usual (single click selects that block) and the gate below
 * decides the double-click.
 */

export const PORTAL_URL_ATTRIBUTE = 'data-daedalus-ui-portal-url';
export const PORTAL_LABEL_ATTRIBUTE = 'data-daedalus-ui-portal-label';
export const PORTAL_TYPE_ATTRIBUTE = 'data-daedalus-ui-portal-type';
// The slot door's two stamps: the slot it declares (every door, the drag
// layer's target) and, when this page may claim it, the default it shows.
export const PORTAL_SLOT_ATTRIBUTE = 'data-daedalus-slot';
export const PORTAL_CLAIM_ATTRIBUTE = 'data-daedalus-slot-default';
export const PORTAL_SELECTOR = `[${PORTAL_URL_ATTRIBUTE}], [${PORTAL_CLAIM_ATTRIBUTE}]`;

/**
 * The portal a pointer target sits in.
 *
 * The chip is chrome painted OUTSIDE the portal (in #daedalus-root, a
 * tab on the portal's stroke), so a pointer travelling from the portal
 * to the chip's Edit link leaves the portal's subtree on the way. Pointing
 * at the chip therefore keeps the portal the chip belongs to (`current`)
 * instead of resolving to nothing and hiding the very control being
 * reached for.
 *
 * @param {?EventTarget} target
 *   The pointer event target.
 * @param {object} [options]
 *   - chrome: the chip's own root element, or null before it mounts.
 *   - current: the portal currently shown, or null.
 *
 * @returns {?Element}
 *   The portal's placement root (the element carrying the URL stamp), or
 *   null when the pointer is over neither a portal nor the chip.
 */
export const portalForTarget = (target, { chrome = null, current = null } = {}) => {
  if (!target || typeof target.closest !== 'function') {
    return null;
  }
  if (chrome && current && chrome.contains(target)) {
    return current;
  }
  return target.closest(PORTAL_SELECTOR);
};

/**
 * What the chip reads off a portal: its editing URL, label, kind, claim.
 *
 * @param {?Element} portal
 *   A portal's placement root, or null.
 *
 * @returns {?{url: ?string, label: string, type: ?string, claim: ?{slot: string, blueprint: string}}}
 *   The editing URL (null on a claimable door the actor may not edit at
 *   its root), the label, the kind word of the document the portal opens
 *   (stamped beside the URL; null without one), and the claim a slot door
 *   offers (the slot id and the default blueprint it shows) or null. null
 *   when there is no portal or the element carries neither a URL nor a
 *   claim (a stamp without a destination and without a gesture is nothing
 *   to draw a chip for).
 */
export const portalIdentity = (portal) => {
  if (!portal) {
    return null;
  }
  const url = portal.getAttribute(PORTAL_URL_ATTRIBUTE) || null;
  const blueprint = portal.getAttribute(PORTAL_CLAIM_ATTRIBUTE) || null;
  const slot = portal.getAttribute(PORTAL_SLOT_ATTRIBUTE) || null;
  const claim = blueprint && slot ? { slot, blueprint } : null;
  if (!url && !claim) {
    return null;
  }
  return {
    url,
    label: portal.getAttribute(PORTAL_LABEL_ATTRIBUTE) ?? '',
    type: portal.getAttribute(PORTAL_TYPE_ATTRIBUTE) || null,
    claim,
  };
};

/**
 * The showing the chip hands the doorway registry, built from the stamps.
 *
 * The registry (portal-actions.js) takes the spine's showing, the one
 * answer for what a join shows (`shows`, `document`, `blueprint`,
 * `portal`, `claimable`; daedalus_ui.api.php), so the chip and the tree's
 * shown-document row call one `applies(showing)` and one `run(showing,
 * uuid)`. The row reads the annotation's copy; the chip has the stamps,
 * which are a projection of the same answer onto the placement root
 * (LayoutBuilderBlockAttributes::stampShowing), so the chip reads them
 * back into the showing's shape: the claim stamp rides exactly when the
 * door shows its DEFAULT and this canvas may claim it (`shows`,
 * `blueprint`, `claimable`), the label names the shown document, and the
 * url with its kind word is the portal. What the stamps do not say stays
 * null: a url-only portal may be a join showing a default the actor may
 * open or the holder of any other foreign node (the frame's chrome block,
 * a template's node), and the stamps do not tell them apart, so `shows`
 * is null there; the shown document's entity identity is not stamped. The
 * full-stack mirror (the chip reading the model) retires this reading.
 *
 * @param {?{url: ?string, label: string, type: ?string, claim: ?{slot: string, blueprint: string}}} identity
 *   What the chip read off the portal (portalIdentity), or null.
 *
 * @returns {?{shows: ?string, document: {label: string}, blueprint: ?string, portal: ?{label: string, kind: ?string, url: string}, claimable: boolean}}
 *   The showing as the stamps tell it; null without an identity.
 */
export const portalShowing = (identity) => {
  if (!identity) {
    return null;
  }
  const { url, label, type, claim } = identity;
  return {
    shows: claim ? 'default' : null,
    document: { label },
    blueprint: claim?.blueprint ?? null,
    portal: url ? { label, kind: type, url } : null,
    claimable: !!claim,
  };
};

/**
 * The kind word as the chip says it: the server's word, lowercased.
 *
 * @param {?string} type
 *   The stamped kind word ("Blueprint", "Site frame"), or null.
 *
 * @returns {?string}
 *   "blueprint", "site frame"; null without a kind.
 */
export const portalKindWord = (type) => (type ? type.toLocaleLowerCase() : null);

/**
 * The chip's label: the portal's name and, lowercased, what it is.
 *
 * "Header · blueprint", "Stark · site frame": the name the editor knows
 * the document by, then its kind as the server worded it, so the chip
 * says at rest that this is a blueprint shown here by reference and
 * edited elsewhere. A portal with no kind (a claim-only door the actor
 * may not open) reads as its name alone.
 *
 * @param {{label: string, type: ?string}} identity
 *   What the chip read off the portal (portalIdentity).
 *
 * @returns {string}
 *   The label.
 */
export const portalChipLabel = ({ label, type }) => {
  const kind = portalKindWord(type);
  return kind ? `${label} · ${kind}` : label;
};

/**
 * Where a double-click goes.
 *
 * Double-click is the canvas's "drill into this group" gesture; across the
 * entity boundary it means "go edit this at its own root". The Selector's
 * drill keeps precedence INSIDE the portal: the gesture navigates when
 * the selection resolver yielded nothing for the target (portal markup
 * has no projection row, so that is the frame case) and when the
 * key it yielded resolved ABOVE the portal, on an element that contains
 * it (a portal placed inside the page's own markup: the pointer's closest
 * presented ancestor is the page's block holding it, the page's own
 * selection, not something in the portal to drill into). Only a key
 * whose element lies inside the portal's subtree keeps the drill, so the
 * portal wins its subtree and the page keeps everything above it.
 *
 * @param {object} args
 *   - portal: the portal under the pointer (portalForTarget), or null.
 *   - resolvedKey: what the selection resolver returned for the same
 *     target (null when nothing selectable is there).
 *   - resolvedElement: the element carrying that key (the resolver's
 *     elementForKey), or null. A key passed without its element cannot
 *     be placed against the portal and keeps the drill.
 *
 * @returns {?string}
 *   The URL to navigate to, or null to leave the gesture alone.
 */
export const portalNavigation = ({
  portal = null,
  resolvedKey = null,
  resolvedElement = null,
}) => {
  const url = portalIdentity(portal)?.url ?? null;
  if (!url) {
    return null;
  }
  if (resolvedKey === null || resolvedKey === undefined) {
    return url;
  }
  if (!resolvedElement) {
    return null;
  }
  return portalUnder({ portal, resolvedKey, resolvedElement }) ? url : null;
};

/**
 * The portal the pointer is really over, the page's own content set aside.
 *
 * A slot door (the template's or the frame's slot block) is a portal
 * whose subtree holds the document beneath it: the page's own fill, every
 * element of it a selectable of this page. Pointing at the fill is
 * pointing at the page, not at the door, so the hover reads the same rule
 * the double-click does: a key resolved INSIDE the portal (on an element
 * the portal contains, other than the portal itself) sets the portal
 * aside; a key resolved on the portal itself or above it, or no key at
 * all, keeps it. A key whose element cannot be placed (none given) keeps
 * the portal too: the hover errs toward showing the door.
 *
 * @param {object} args
 *   - portal: the portal under the pointer (portalForTarget), or null.
 *   - resolvedKey: what the selection resolver returned for the same
 *     target (null when nothing selectable is there).
 *   - resolvedElement: the element carrying that key, or null.
 *
 * @returns {?Element}
 *   The portal, or null when the pointer is over the page's own content
 *   inside it.
 */
export const portalUnder = ({
  portal = null,
  resolvedKey = null,
  resolvedElement = null,
}) => {
  if (!portal) {
    return null;
  }
  if (resolvedKey === null || resolvedKey === undefined || !resolvedElement) {
    return portal;
  }
  const inside =
    resolvedElement !== portal && portal.contains(resolvedElement);
  return inside ? null : portal;
};

/**
 * The URL a leave actually navigates to: the editing URL carrying where
 * it left from.
 *
 * Leaving a page for a portal's root is a round trip: Save and Discard on
 * that root send the browser to `destination=` the canvas URL carries
 * (top-bar.js), so the leave is the one place that knows the way back and
 * the only one that can say it. The path rides `destination`, Drupal's
 * own redirect parameter, percent-encoded so a path with a query of its
 * own survives as one value.
 *
 * @param {string} url
 *   The portal's editing URL (the stamp).
 * @param {?string} fromPath
 *   The current page's path (window.location.pathname), or nothing when
 *   there is no page to return to.
 *
 * @returns {string}
 *   The url with `destination=<fromPath>` appended (`?` or `&` as the url
 *   already carries a query); a falsy fromPath returns the url unchanged.
 */
export const portalLeaveUrl = (url, fromPath) => {
  if (!fromPath) {
    return url;
  }
  const separator = url.includes('?') ? '&' : '?';
  return `${url}${separator}destination=${encodeURIComponent(fromPath)}`;
};
