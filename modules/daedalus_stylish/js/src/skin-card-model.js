/**
 * The skin card's pure model.
 *
 * The palette model's sibling for the skin itself: over the Palette's
 * skin section sits one card for the look the site wears, and this
 * module owns everything that card can compute without a DOM from its
 * one management read (the skins route: the active design system, the
 * skin it wears, the site's choice, the unit's default, and every member
 * skin with the facts the gestures key on, and every skin on the site as
 * what a copy may copy) and the account's gates. The member roster with
 * each skin's standing (worn, the default, shipped, choosable or why
 * not), the copy's source roster grouped by design system, the gesture
 * roster as data (presence means permitted AND applicable, so nothing on
 * the card is a dead control), what a restore restores, and the copy's
 * derived label. The view (palette.js SkinCard) only renders and
 * dispatches.
 */

/**
 * Whether the account may read the skin card at all.
 *
 * The skins route admits every grade that touches the skin (the token
 * gestures, the defaults gesture, the administer grade): whoever edits
 * the skin's tokens or defaults sees which skin they edit. With none of
 * them the card is not mounted and no read is made.
 *
 * @param {object} tokens
 *   The token gesture gates (drupalSettings.daedalusStylish.tokens).
 * @param {object} skin
 *   The skin gesture gates (drupalSettings.daedalusStylish.skin).
 *
 * @return {boolean}
 */
export const skinCardReadable = (tokens = {}, skin = {}) =>
  Boolean(
    tokens?.canCreate ||
      tokens?.canUpdate ||
      tokens?.canDelete ||
      tokens?.canDeleteInUse ||
      skin?.canEditDefaults ||
      skin?.canAdminister,
  );

/**
 * The refusal codes the read speaks, as the reasons the card phrases.
 *
 * A member the system may not wear carries the choose judge's refusal
 * (SkinManager::refusal()); the view phrases each reason in its own
 * words and never shows a code.
 */
const REASONS = {
  STYLE_SKIN_UNPUBLISHED: 'unpublished',
  STYLE_SKIN_NONCONFORMING: 'nonconforming',
  STYLE_SKIN_NOT_MEMBER: 'not-member',
  STYLE_SKIN_NOT_FOUND: 'not-found',
};

/**
 * The read's entry for the skin the system wears, or null.
 *
 * @param {?object} read
 *   The skins route's answer, or null while in flight or unreadable.
 *
 * @return {?object}
 *   The worn member's entry (delivery shape), null when nothing is worn
 *   or the worn name is not a member (a default_skin naming a skin that
 *   is not there yet).
 */
export const wornSkin = (read) =>
  read?.worn == null
    ? null
    : (read.skins ?? []).find((skin) => skin.machine_name === read.worn) ??
      null;

/**
 * The member roster: every skin the active system carries, in read order.
 *
 * Read order is by label (the manager's memberSkins()). Each row says
 * where the skin stands: `worn` (the one the site wears), `isDefault`
 * (the unit's default_skin, the package's answer when the site has
 * chosen nothing), `shipped` (a provider ships it, so the site's edits
 * are a layer over it), `choosable` (the system may wear it and does not
 * already), and `reason` with `lacks` for a member the system may not
 * wear right now (the refusal's reason word and, for a nonconforming
 * skin, the contract names it lacks per scale).
 *
 * @param {?object} read
 *   The skins route's answer.
 *
 * @return {Array<{reference: string, label: string, worn: boolean,
 *   isDefault: boolean, shipped: boolean, provider: ?string,
 *   published: boolean, choosable: boolean, reason: ?string,
 *   lacks: ?object}>}
 */
export const skinCardRows = (read) =>
  (read?.skins ?? []).map((skin) => {
    const worn = skin.machine_name === read.worn;
    const refusal = skin.refusal ?? null;
    return {
      reference: skin.machine_name,
      label: skin.label !== '' ? skin.label : skin.machine_name,
      worn,
      isDefault: skin.machine_name === read.default,
      shipped: skin.provider != null,
      provider: skin.provider ?? null,
      published: Boolean(skin.published),
      choosable: refusal === null && !worn,
      reason: refusal === null ? null : (REASONS[refusal.code] ?? 'refused'),
      lacks: refusal?.context?.lacks ?? null,
    };
  });

/**
 * The copy's source roster: every skin on the site, grouped by system.
 *
 * A copy lands in the ACTIVE design system whatever its source's
 * membership (SkinManager::copyAsMySkin()), so the source may be any
 * skin on the site: the read's `sources` (every skin by label). Grouped
 * by design system in the order the read first names each, with the
 * global skins (no membership) as one group of their own (`system`
 * null; the view names it). The worn skin's group is hoisted first and
 * the worn skin to the head of it, so the roster's first entry is the
 * worn skin whenever one is worn: copying what you wear stays the
 * one-step default, and with nothing worn the first entry is still a
 * sensible preselection.
 *
 * @param {?object} read
 *   The skins route's answer.
 *
 * @return {Array<{system: ?string, label: ?string,
 *   skins: Array<{reference: string, label: string, provider: ?string,
 *   worn: boolean}>}>}
 *   The groups; empty with no read or no skins on the site.
 */
export const copySources = (read) => {
  const groups = new Map();
  for (const source of read?.sources ?? []) {
    const system = source.design_system ?? null;
    if (!groups.has(system)) {
      groups.set(system, {
        system,
        label: system === null ? null : (source.design_system_label ?? system),
        skins: [],
      });
    }
    groups.get(system).skins.push({
      reference: source.machine_name,
      label: source.label !== '' ? source.label : source.machine_name,
      provider: source.provider ?? null,
      worn: read.worn != null && source.machine_name === read.worn,
    });
  }
  const hoistWorn = (list, isWorn) => {
    const index = list.findIndex(isWorn);
    return index > 0 ? [list[index], ...list.filter((_, i) => i !== index)] : list;
  };
  return hoistWorn(
    [...groups.values()].map((group) => ({
      ...group,
      skins: hoistWorn(group.skins, (skin) => skin.worn),
    })),
    (group) => group.skins.some((skin) => skin.worn),
  );
};

/**
 * The card's gesture roster, as data.
 *
 * The cardGestures twin for the skin: presence means permitted AND
 * applicable. Every lifecycle gesture is the administer grade's (each
 * restyles the whole site, the same act as activating a design system),
 * so without it the card reads and offers nothing. Choose needs a member
 * the system may wear other than the worn one; copy needs an active
 * design system to land in and a skin on the site to copy (never a
 * worn skin: it is the door through which a system with no skin gets
 * its first); revisions need a worn skin to restore; revert needs a
 * worn skin with a package to reveal (a provider) and a site layer to
 * drop.
 *
 * @param {?object} read
 *   The skins route's answer.
 * @param {object} gates
 *   The skin gesture gates (drupalSettings.daedalusStylish.skin):
 *   {canEditDefaults, canAdminister}.
 *
 * @return {{choose: boolean, copy: boolean, revisions: boolean,
 *   revert: boolean}}
 */
export const skinCardGestures = (read, gates = {}) => {
  const admin = Boolean(gates?.canAdminister);
  const worn = wornSkin(read);
  return {
    choose: admin && skinCardRows(read).some((row) => row.choosable),
    copy:
      admin && read?.design_system != null && copySources(read).length > 0,
    revisions: admin && worn !== null,
    revert:
      admin &&
      worn !== null &&
      worn.provider != null &&
      Boolean(worn.site_layer),
  };
};

/**
 * What a restore of this skin brings back: 'site' or 'whole'.
 *
 * On a consuming site the package layer is the disk's, so a restore
 * lands the old revision's SITE layer over the package layer as synced
 * (SkinManager::restoreRevision()); on the site's own skin and under the
 * developer gate the revision comes back whole. The read's `locked` (the
 * skins route per member, the revisions route on its skin) is the fact.
 *
 * @param {boolean} locked
 *   Whether the package layer is locked on this site.
 *
 * @return {'site'|'whole'}
 */
export const restoreScope = (locked) => (locked ? 'site' : 'whole');

/**
 * The copy's derived label: the server's default, offered as the draft.
 *
 * @param {{label: string}} source
 *   The picked source (a copySources() entry).
 *
 * @return {string}
 */
export const copyLabel = (source) => `${source.label} (copy)`;
