/**
 * The portal chip's pure model: which element is the portal for a pointer
 * target, what the chip reads off it, and whether a double-click leaves
 * the page. No DOM lookups of its own and no Drupal globals; the Preact
 * host (portal.js) hands it the pointer target and paints the answer, so
 * the reasoning is node-testable (tests/js/portal-model.test.mjs).
 *
 * A PORTAL is a placed entity that is inert on this page and edited at
 * its own root: a page-builder block_content in a theme region (the site
 * header, the footer). The server stamps its placement root with the
 * editing URL and the entity's label (DaedalusUiHooks::entityView);
 * those two attributes are the whole contract between the stamp and this
 * chip. The portal sits OUTSIDE the projection on purpose: nothing
 * inside it resolves as a selectable, so the chip is not a selection and
 * never competes with the selection frame's hover.
 */

export const PORTAL_URL_ATTRIBUTE = 'data-daedalus-ui-portal-url';
export const PORTAL_LABEL_ATTRIBUTE = 'data-daedalus-ui-portal-label';
export const PORTAL_SELECTOR = `[${PORTAL_URL_ATTRIBUTE}]`;

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
 * What the chip reads off a portal: its editing URL and its label.
 *
 * @param {?Element} portal
 *   A portal's placement root, or null.
 *
 * @returns {?{url: string, label: string}}
 *   null when there is no portal or the element carries no URL (a stamp
 *   without a destination is nothing to draw a chip for).
 */
export const portalIdentity = (portal) => {
  const url = portal?.getAttribute(PORTAL_URL_ATTRIBUTE) ?? null;
  if (!url) {
    return null;
  }
  return { url, label: portal.getAttribute(PORTAL_LABEL_ATTRIBUTE) ?? '' };
};

/**
 * Where a double-click goes.
 *
 * Double-click is the canvas's "drill into this group" gesture; across the
 * entity boundary it means "go edit this at its own root". The Selector's
 * drill keeps precedence: the gesture navigates only when the selection
 * resolver yielded NOTHING for the target (inside a portal that is always
 * the case today, because portal markup has no projection row; the gate
 * is what keeps it true if a portal ever wraps presented markup).
 *
 * @param {object} args
 *   - portal: the portal under the pointer (portalForTarget), or null.
 *   - resolvedKey: what the selection resolver returned for the same
 *     target (null when nothing selectable is there).
 *
 * @returns {?string}
 *   The URL to navigate to, or null to leave the gesture alone.
 */
export const portalNavigation = ({ portal = null, resolvedKey = null }) => {
  if (resolvedKey !== null && resolvedKey !== undefined) {
    return null;
  }
  return portalIdentity(portal)?.url ?? null;
};
