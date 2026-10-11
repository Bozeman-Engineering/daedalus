/**
 * The Materials variants face's pure model.
 *
 * The variants area's second mode: a flat, filterable list of the store's
 * variant records with a lifecycle card per variant. This module owns
 * everything the view can compute without a DOM — the filter vocabularies,
 * the row roster with its honesty flags (the shipped marker), the card's
 * gesture roster as data (the wearRowMenu pattern: presence means the
 * gesture will do something), the ownership face, and the this-page
 * wearer lookup behind the card's "Edit styles" doorway — for node-test
 * backpressure. The view (library.js) only renders and dispatches.
 *
 * Variants are materials: the list is materials.ofKind('variant') (record
 * shape: id, label, description, weight, group = the axis, scope,
 * owned_by, origin), blueprints are materials.ofKind('blueprint'), and the
 * management facts a card needs past its record (the live wearer count,
 * the shipped state against the current disk copy, the shipping module)
 * are one per-open read of the usage route, never a list fact.
 */

import { entryRefs, isOrphaned, isShipped, locked } from './variant-scope.js';
import { styleTarget } from './widgets/lib/use-highlight.js';

/**
 * The filter vocabularies: every axis and scope the variants speak.
 *
 * Sorted for stable dropdowns; a record without a group competes on the
 * built-in 'style' axis (the picker's own default).
 *
 * @param {?Array<object>} variants
 *   The store's variant records.
 *
 * @return {{axes: string[], scopes: string[]}}
 */
export const libraryFilters = (variants) => ({
  axes: [
    ...new Set((variants ?? []).map((variant) => variant.group ?? 'style')),
  ].sort(),
  scopes: [
    ...new Set(
      (variants ?? [])
        .map((variant) => variant.scope)
        .filter((scope) => typeof scope === 'string' && scope !== ''),
    ),
  ].sort(),
});

/**
 * The list's row roster: filtered, flagged, in roster order.
 *
 * The search needle matches label, id or description, case-insensitive;
 * the axis and scope filters are exact (null = all). `shipped` marks the
 * record's origin (the shipped marker; whether the card renders read-only
 * is the lock, `updatable`, read at the card); `offered` the AI opt-out
 * (absent on the record reads as offered, the default; the row shows the
 * description only while it has that audience). Counts are not list
 * facts: the card's open is the gesture that reads the usage route.
 *
 * @param {?Array<object>} variants
 *   The store's variant records (roster order: weight, then label).
 * @param {{search?: string, axis?: ?string, scope?: ?string}} filters
 *
 * @return {Array<{reference: string, label: string, axis: string,
 *   scope: string, description: string, weight: number, shipped: boolean,
 *   offered: boolean}>}
 */
export const libraryRows = (
  variants,
  { search = '', axis = null, scope = null } = {},
) => {
  const needle = search.trim().toLowerCase();
  return (variants ?? [])
    .filter((variant) => {
      if (axis !== null && (variant.group ?? 'style') !== axis) {
        return false;
      }
      if (scope !== null && variant.scope !== scope) {
        return false;
      }
      return (
        needle === '' ||
        `${variant.label ?? ''}\n${variant.id ?? ''}\n${variant.description ?? ''}`
          .toLowerCase()
          .includes(needle)
      );
    })
    .map((variant) => ({
      reference: variant.id,
      label: variant.label ?? variant.id,
      axis: variant.group ?? 'style',
      scope: variant.scope ?? '',
      description:
        typeof variant.description === 'string' ? variant.description : '',
      weight: typeof variant.weight === 'number' ? variant.weight : 0,
      shipped: isShipped(variant),
      offered: variant.offered !== false,
    }));
};

/**
 * A blueprint's display label, from the store's blueprint record.
 *
 * The one read for naming a blueprint in the variant area's chrome
 * (ownership badge and line, mint-time consent chip): the blueprint record
 * carries the label; a blueprint the roster no longer holds (an orphaned
 * owner) reads as its id, the degraded posture.
 *
 * @param {string} blueprint
 *   The blueprint's id.
 * @param {object} materials
 *   The materials roster.
 *
 * @return {string}
 */
export const blueprintLabel = (blueprint, materials) =>
  materials.get('blueprint', blueprint)?.label ?? blueprint;

/**
 * A variant's blueprint-ownership face, or null when unowned.
 *
 * Ownership is CURATION: an owned variant is one of its blueprint's
 * switchable looks, offered only on that blueprint's placements
 * (variant-scope.js gates the offer; this is the card's honesty read).
 * The record carries the owner's id (`owned_by`); the label comes from the
 * owner's own blueprint record. An orphaned owner (the roster holds no such
 * blueprint: deleted or no longer discovered) has no name at all: `owner`
 * and `label` read null, and the variant offers nowhere until released.
 *
 * @param {?object} variant
 *   The variant record.
 * @param {object} materials
 *   The materials roster.
 *
 * @return {?{owner: ?string, label: ?string, orphaned: boolean}}
 */
export const variantOwnership = (variant, materials) => {
  if (variant?.owned_by == null) {
    return null;
  }
  if (isOrphaned(materials, variant)) {
    return { owner: null, label: null, orphaned: true };
  }
  return {
    owner: variant.owned_by,
    label: blueprintLabel(variant.owned_by, materials),
    orphaned: false,
  };
};

/**
 * The blueprints a variant could transfer to, sorted by label.
 *
 * Transfer targets are content blueprints only (`updatable`: file-shipped
 * blueprints' ownership belongs to the disk lane and the route refuses
 * them), minus the current owner (a transfer to itself is no gesture).
 * For an UNOWNED variant every content blueprint is a target: the transfer
 * route with no prior owner is adopt-later, the card's "Assign to
 * blueprint" gesture.
 *
 * @param {?object} variant
 *   The variant record.
 * @param {object} materials
 *   The materials roster.
 *
 * @return {Array<{id: string, label: string}>}
 */
export const blueprintTargets = (variant, materials) =>
  materials
    .ofKind('blueprint')
    .filter(
      (blueprint) =>
        blueprint.updatable === true && blueprint.id !== variant?.owned_by,
    )
    .map((blueprint) => ({ id: blueprint.id, label: blueprint.label ?? blueprint.id }))
    .sort((a, b) => a.label.localeCompare(b.label));

/**
 * The lifecycle card's gesture roster, as data.
 *
 * The view maps flags to buttons and handlers (the wearRowMenu contract):
 * presence means the gesture is permitted AND applicable, so nothing on
 * the card is a dead control. Two record facts split the roster
 * (variant-scope.js isShipped, locked): the lock (`updatable` false: a
 * shipped variant on a consuming site, where the importer owns its label
 * and styles and details, restore, and delete all refuse server-side)
 * withdraws the editing gestures; the shipped origin offers "Copy as my
 * variant" and, when the state is actionable, "Revert to defaults". Under
 * the developer gate a shipped variant is updatable and carries both
 * rosters. Delete carries the two permission grades: a worn variant
 * needs the in-use grade (its delete runs the detach-then-delete batch),
 * an unworn one either grade.
 *
 * The ownership gestures gate on the BLUEPRINT side's grant (the library's
 * ownership permission, delivered as the variant gates' `canOwn`: ownership lives on
 * the blueprint vocabulary, not the variant permissions) and never offer on
 * locked variants (the disk lane owns their `blueprint:` key; the routes
 * refuse). Release needs an owner to release from (orphans included:
 * releasing an orphan IS the cleanup gesture); transfer needs somewhere
 * to go (blueprintTargets) and doubles as adopt-later on unowned variants.
 *
 * @param {object} variant
 *   The variant record.
 * @param {object} config
 *   The gesture gates (drupalSettings.daedalusStylish.variants):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse, canOwn}.
 * @param {?{usages: number, shipped_state: ?string}} management
 *   The card's per-open management read (the usage route: the live wearer
 *   count and the shipped state against the current disk copy), or null
 *   while it is in flight — then the count reads as zero and revert waits
 *   for the state.
 * @param {object} materials
 *   The materials roster (the blueprints the ownership gestures need).
 *
 * @return {{details: boolean, revisions: boolean, editStyles: boolean,
 *   duplicate: boolean, copy: boolean, revert: boolean, release: boolean,
 *   transfer: boolean, delete: boolean}}
 */
export const cardGestures = (
  variant,
  config = {},
  management = null,
  materials = null,
) => {
  const shipped = isShipped(variant);
  const editable = !locked(variant);
  const worn = (management?.usages ?? 0) > 0;
  const canOwn = Boolean(config.canOwn) && materials !== null;
  return {
    details: editable && Boolean(config.canUpdate),
    revisions: editable && Boolean(config.canUpdate),
    editStyles: editable && Boolean(config.canUpdate),
    duplicate: editable && Boolean(config.canCreate),
    copy: shipped && Boolean(config.canCreate),
    revert:
      shipped &&
      management?.shipped_state != null &&
      management.shipped_state !== 'unmodified' &&
      Boolean(config.canUpdate),
    release: editable && canOwn && variant?.owned_by != null,
    transfer:
      editable && canOwn && blueprintTargets(variant, materials).length > 0,
    delete:
      editable &&
      (worn
        ? Boolean(config.canDeleteInUse)
        : Boolean(config.canDelete) || Boolean(config.canDeleteInUse)),
  };
};

/**
 * The card's "Apply to selection" doorway state.
 *
 * The browse-first workflow's one write gesture: find a variant in the
 * catalog, put it on the styling target you are holding. Applying is
 * the same instant apply_variant op the styling place's variant picker
 * speaks (element edit, tempstore net), so unlike the lifecycle gestures
 * it takes no variant grant — shipped variants apply fine, only pushing
 * into them is locked. What gates it is the TARGET (the key the panel
 * writes at: the selected element, or the rule the target chip names,
 * whose every match then wears it): 'ready' needs a target, not already
 * wearing the variant (fragment wearings included — the wearing row is
 * then the gesture surface; Re-apply lives on its menu), and the variant
 * in the target's offer (the same scope/ownership/tag-filtered set the
 * wear bar's picker lists, paired at the target's landing). The view
 * renders every state — the doorway's presence tells the browsing user it
 * exists; the disabled titles say honestly why it is shut. Off the offer,
 * ownership names its own reasons: 'unfit-owned' when the variant belongs
 * to a blueprint and the target is not inside that blueprint's placements
 * (the caller resolves the target's provenance, variant-scope.js
 * blueprintProvenance), 'unfit-orphaned' when the owner is gone (offers
 * nowhere until released), so the title can say "belongs to blueprint X"
 * instead of a vague scope shrug. An owned variant refused by scope INSIDE
 * its own placements reads plain 'unfit': ownership was not the reason.
 *
 * @param {?object} variant
 *   The variant record.
 * @param {?object} styleMap
 *   The page's style map (mirror view).
 * @param {?string} target
 *   The styling target's key (an element's, or a rule's), null with no
 *   selection.
 * @param {?Array<object>} offer
 *   The target's applicable variants (variant-scope.js
 *   applicableVariants); empty when nothing is selected or the target
 *   is unstylable.
 * @param {?string} provenance
 *   The target's blueprint provenance (blueprintProvenance), or null.
 * @param {object} materials
 *   The materials roster (the orphan rule).
 *
 * @return {'ready'|'no-selection'|'worn'|'unfit'|'unfit-owned'|
 *   'unfit-orphaned'}
 */
export const applyDoorway = (
  variant,
  styleMap,
  target,
  offer,
  provenance = null,
  materials = null,
) => {
  if (!target) {
    return 'no-selection';
  }
  if (
    entryRefs(styleMap?.[target]).some((ref) => ref.variant === variant?.id)
  ) {
    return 'worn';
  }
  if ((offer ?? []).some((candidate) => candidate.id === variant?.id)) {
    return 'ready';
  }
  if (variant?.owned_by != null && materials !== null) {
    if (isOrphaned(materials, variant)) {
      return 'unfit-orphaned';
    }
    if (variant.owned_by !== provenance) {
      return 'unfit-owned';
    }
  }
  return 'unfit';
};

/**
 * A wearer of the variant on THIS page, for the card's "Edit styles".
 *
 * Edit styles enters the editing session on the variant; the document
 * renders in wearer context, so it needs a wearing element as its
 * anchor. The lookup matches the record id (the one reference identity)
 * and answers the worn reference, the one the session's `editing` must
 * hold. The styling target wins when it wears the variant (no jump: the
 * session anchors where the bar already wears); otherwise an element's
 * root wearing (the full-sheet context with a read subject), then a
 * rule's (`container:X::> pb-text`: the document rendered at the
 * rule's own stack, previewing on every match), then a fragment
 * wearing, map order breaking ties (the map's canonical order is the
 * document's, styleMapOf, so the first wearer on the page wins, never
 * the first written). Null when nothing on this page
 * wears it — the view degrades the doorway honestly (a wearer on
 * another page, or the unworn degenerate case, cannot anchor here).
 *
 * @param {?object} styleMap
 *   The page's style map (mirror view).
 * @param {?object} variant
 *   The variant record.
 * @param {?string} preferred
 *   The styling target's wear key (an element or a rule), preferred as
 *   the anchor.
 *
 * @return {?{key: string, reference: string, fragment: string}}
 */
export const pageWearer = (styleMap, variant, preferred = null) => {
  if (!variant) {
    return null;
  }
  const wornRef = (key) => {
    const ref = entryRefs(styleMap?.[key]).find(
      (candidate) => candidate.variant === variant.id,
    );
    return ref ? { key, reference: ref.variant, fragment: ref.fragment } : null;
  };
  if (preferred) {
    const own = wornRef(preferred);
    if (own) {
      return own;
    }
  }
  const wearers = Object.keys(styleMap ?? {}).map(wornRef).filter(Boolean);
  const roots = wearers.filter((wearer) => wearer.fragment === '');
  return (
    roots.find((wearer) => styleTarget(wearer.key).selector === null) ??
    roots[0] ??
    wearers[0] ??
    null
  );
};
