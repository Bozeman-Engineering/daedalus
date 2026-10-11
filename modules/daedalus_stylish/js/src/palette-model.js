/**
 * The Palette face's pure model.
 *
 * The library model's twin at value scope: the catalog's second section is
 * a flat, filterable list of the swatches the materials store carries (the
 * roster's swatch records, delivery shape) with a lifecycle card per
 * swatch, over the skin section (the roster's tokens, the curated half of
 * the palette, edited where they are listed: the active skin's tokens are
 * materials, and the palette is their card). This module owns everything
 * the view can compute without a DOM: the kind filter vocabulary, the row
 * roster with its shipped marker, the two cards' gesture rosters as data
 * (cardGestures' posture: presence means permitted AND applicable) over
 * the record plus the card's per-gesture management read (usages, shipped
 * state, the token's layer and lock), the condition-honest editor split
 * for condition-keyed values, the token name grammar, and the skin token
 * grouping, for node-test backpressure. The view (palette.js) only renders
 * and dispatches.
 */

import { isPlainObject, schemeCondition } from './style-guide.js';
import { tokenOptions } from './materials.js';
import { isShipped, locked } from './variant-scope.js';

/**
 * The kind filter's vocabulary: every kind the palette speaks, sorted.
 *
 * @param {?Array<object>} swatches
 *   The swatch records (roster order).
 *
 * @return {{kinds: string[]}}
 */
export const paletteFilters = (swatches) => ({
  kinds: [
    ...new Set(
      (swatches ?? [])
        .map((swatch) => swatch.group)
        .filter((kind) => typeof kind === 'string' && kind !== ''),
    ),
  ].sort(),
});

/**
 * The palette list's row roster: filtered, flagged, in list order.
 *
 * The libraryRows twin: the search needle matches label or machine name,
 * case-insensitive; the kind filter is exact (null = all). `shipped`
 * marks the record's origin (the lock, `updatable`, is read at the
 * card). Usage counts are
 * management facts the card fetches per open (a list count drifts
 * mid-session and never rides the roster), so rows carry none.
 *
 * @param {?Array<object>} swatches
 *   The swatch records (roster order).
 * @param {{search?: string, kind?: ?string}} filters
 *
 * @return {Array<{reference: string, label: string, kind: string,
 *   shipped: boolean}>}
 */
export const paletteRows = (swatches, { search = '', kind = null } = {}) => {
  const needle = search.trim().toLowerCase();
  return (swatches ?? [])
    .filter((swatch) => {
      if (kind !== null && swatch.group !== kind) {
        return false;
      }
      return (
        needle === '' ||
        `${swatch.label ?? ''}\n${swatch.id ?? ''}`
          .toLowerCase()
          .includes(needle)
      );
    })
    .map((swatch) => ({
      reference: swatch.id,
      label: swatch.label !== '' ? swatch.label : swatch.id,
      kind: swatch.group ?? '',
      shipped: isShipped(swatch),
    }));
};

/**
 * The lifecycle card's gesture roster, as data.
 *
 * The cardGestures twin at value scope: presence means the gesture is
 * permitted AND applicable, so nothing on the card is a dead control. Two
 * record facts split the roster (variant-scope.js isShipped, locked): the
 * lock (`updatable` false: a shipped swatch on a consuming site, where
 * the importer owns its label and value and rename, edit, restore, and
 * delete all refuse server-side) withdraws the editing gestures; the
 * shipped origin offers "Revert to defaults" when the state is
 * actionable. Under the developer gate a shipped swatch is updatable and
 * carries both. There is no copy
 * gesture: a swatch is one value — minting a fresh one IS the copy.
 * Delete carries the two permission grades: a referenced swatch needs the
 * in-use grade (its delete runs the rewrite-then-delete batch), an
 * unreferenced one either grade.
 *
 * @param {object} swatch
 *   The swatch record (delivery shape).
 * @param {object} config
 *   The gesture gates (drupalSettings.daedalusStylish.swatches):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}.
 * @param {?{usages: number, shipped_state: ?string}} management
 *   The card's per-open management read (the usage route: the live
 *   reference count and the shipped state against the current disk
 *   copy), or null while it is in flight — then the count reads as zero
 *   and revert waits for the state.
 *
 * @return {{rename: boolean, editValue: boolean, revisions: boolean,
 *   revert: boolean, delete: boolean}}
 */
export const swatchCardGestures = (swatch, config = {}, management = null) => {
  const shipped = isShipped(swatch);
  const editable = !locked(swatch);
  const used = (management?.usages ?? 0) > 0;
  return {
    rename: editable && Boolean(config.canUpdate),
    editValue: editable && Boolean(config.canUpdate),
    revisions: editable && Boolean(config.canUpdate),
    revert:
      shipped &&
      management?.shipped_state != null &&
      management.shipped_state !== 'unmodified' &&
      Boolean(config.canUpdate),
    delete:
      editable &&
      (used
        ? Boolean(config.canDeleteInUse)
        : Boolean(config.canDelete) || Boolean(config.canDeleteInUse)),
  };
};

/**
 * A swatch value split for the string editors (condition honesty).
 *
 * Kind editors edit ONE string. A plain string value edits whole; a
 * condition-keyed value edits its 'default' entry, with the other
 * condition names surfaced so the card can say honestly what the editor
 * is not touching (mergedValue reassembles them verbatim on commit —
 * never a silent flattening).
 *
 * @param {string|object} value
 *   The stored swatch value.
 *
 * @return {{draft: string, conditions: string[]}}
 */
export const editableValue = (value) => {
  if (isPlainObject(value)) {
    return {
      draft: typeof value.default === 'string' ? value.default : '',
      conditions: Object.keys(value).filter(
        (condition) => condition !== 'default',
      ),
    };
  }
  return { draft: typeof value === 'string' ? value : '', conditions: [] };
};

/**
 * The editor's committed draft merged back into the stored value shape.
 *
 * editableValue's inverse: a string value becomes the draft; a
 * condition-keyed value keeps every other condition entry byte-identical
 * and replaces only 'default' — what the editor edited is exactly what
 * changes.
 *
 * @param {string|object} value
 *   The stored swatch value the edit started from.
 * @param {string} draft
 *   The editor's committed string.
 *
 * @return {string|object}
 */
export const mergedValue = (value, draft) =>
  isPlainObject(value) ? { ...value, default: draft } : draft;

/**
 * A swatch value split for per-scheme (light/dark) authoring.
 *
 * editableValue()'s scheme-aware sibling: the pair control edits TWO
 * strings — the default entry and the entry under the site's scheme
 * condition (schemeCondition(); the guide alone decides which name rides
 * the scheme axis, same axis rule as the split faces, so compound keys
 * like 'narrow+dark' never surface here). `darkDraft` is null when the
 * value carries no authorable scheme entry (a plain string, an empty
 * entry, or a guide without a scheme axis — `scheme` null disables pair
 * authoring entirely). `conditions` lists only the entries the pair
 * control is NOT touching, so the card's honesty line stays exact.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string|object} value
 *   The stored swatch value.
 *
 * @return {{draft: string, darkDraft: ?string, scheme: ?string,
 *   conditions: string[]}}
 */
export const editablePair = (guide, value) => {
  const scheme = schemeCondition(guide);
  const { draft, conditions } = editableValue(value);
  const entry =
    scheme !== null && isPlainObject(value) ? value[scheme] : undefined;
  return {
    draft,
    darkDraft: typeof entry === 'string' && entry !== '' ? entry : null,
    scheme,
    conditions: conditions.filter((condition) => condition !== scheme),
  };
};

/**
 * The pair control's committed drafts merged back into the stored shape.
 *
 * editablePair()'s inverse and mergedValue()'s scheme-aware sibling:
 * 'default' becomes the draft, the scheme entry becomes darkDraft, and
 * every OTHER condition entry stays byte-identical (never a silent
 * flattening). A null darkDraft REMOVES the scheme entry — and when only
 * 'default' remains the value collapses to a plain string, so a
 * de-paired swatch paints a whole face again (single-valued stops stay
 * whole, the arc-1 rule). With no scheme axis (scheme null) this is
 * exactly mergedValue().
 *
 * @param {string|object} value
 *   The stored swatch value the edit started from.
 * @param {{draft: string, darkDraft: ?string, scheme: ?string}} pair
 *   The pair control's committed drafts (editablePair()'s shape).
 *
 * @return {string|object}
 */
export const mergedPair = (value, { draft, darkDraft, scheme }) => {
  const merged = mergedValue(value, draft);
  if (scheme === null || scheme === undefined) {
    return merged;
  }
  if (darkDraft === null) {
    if (!isPlainObject(merged) || merged[scheme] === undefined) {
      return merged;
    }
    const { [scheme]: removed, ...rest } = merged;
    const names = Object.keys(rest);
    return names.length === 1 && names[0] === 'default' ? rest.default : rest;
  }
  return isPlainObject(merged)
    ? { ...merged, [scheme]: darkDraft }
    : { default: merged, [scheme]: darkDraft };
};

/**
 * A token's layer as the roster record tells it: the package's or the site's.
 *
 * The provider stamps origin per token (shipped when the skin's package
 * layer holds the name, user for a site-minted token and for every token
 * of the site's own skin), so a row knows which layer it lists before
 * any card opens. A site override OVER a package token keeps the shipped
 * origin: the row says "package" and the card's management read (the
 * usage route's `layer`: package, site or both) refines it.
 *
 * @param {object} record
 *   The token record (delivery shape).
 *
 * @return {string}
 *   'package' or 'site'.
 */
export const tokenLayer = (record) => (isShipped(record) ? 'package' : 'site');

/**
 * The skin tokens grouped per scale: the palette's curated half.
 *
 * Roster order (declared scale order, then authoring order = display
 * order), each entry carrying its shape so the view paints color faces
 * only where a color face is honest, its description and offered flag
 * (the card's editable facts), its layer (the row's package/site marker)
 * and the record itself (the card's subject: the gestures, the live
 * preview and the update op all take the record).
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 *
 * @return {Array<[string, Array<{scale: string, name: string, ref: string,
 *   value: string, valueDark: ?string, shape: ?string, description: string,
 *   offered: boolean, layer: string, record: object}>]>}
 *   [scaleLabel, entries] pairs, [] when no skin tokens are minted.
 */
export const skinGroups = (guide, materials) => {
  const groups = new Map();
  tokenOptions(guide, materials).forEach((token) => {
    if (!groups.has(token.scaleLabel)) {
      groups.set(token.scaleLabel, []);
    }
    groups.get(token.scaleLabel).push({
      scale: token.scale,
      name: token.name,
      ref: token.ref,
      value: token.value,
      valueDark: token.valueDark,
      shape: token.shape,
      description:
        typeof token.record.description === 'string'
          ? token.record.description
          : '',
      offered: token.record.offered !== false,
      layer: tokenLayer(token.record),
      record: token.record,
    });
  });
  return [...groups.entries()];
};

/**
 * The token name grammar: the client twin of StyleMapValidator::TOKEN_NAME.
 *
 * Lowercase letters, digits and inner hyphens ("4", "2xl", "accent-hover");
 * the name becomes the tail of the custom property. PHP is authoritative
 * (the op refuses INVALID_TOKEN_NAME); the mint card reads this to
 * withhold Save before the round trip.
 */
export const TOKEN_NAME = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

/**
 * Why a token name cannot be minted on a scale right now, or null.
 *
 * @param {string} name
 *   The typed name, trimmed by the caller.
 * @param {string} scale
 *   The scale the mint targets.
 * @param {object} materials
 *   The materials roster (the duplicate check: a token id is
 *   "{scale}/{name}", so the same name on another scale is fine).
 *
 * @return {?string}
 *   'missing' (empty), 'invalid' (off the grammar), 'taken' (the active
 *   skin already mints it), or null when the name can go.
 */
export const tokenNameError = (name, scale, materials) => {
  if (name === '') {
    return 'missing';
  }
  if (!TOKEN_NAME.test(name)) {
    return 'invalid';
  }
  return materials.has('token', `${scale}/${name}`) ? 'taken' : null;
};

/**
 * The token card's gesture roster, as data.
 *
 * swatchCardGestures' twin for the material that lives on the skin. A
 * token record is always `updatable` (a consuming site's write lands in
 * the skin's site layer over the package's record), so the update grants
 * alone open the value, description and offered editors: `update tokens`
 * IS the override grade, and withholding it is how a locked tenant reads
 * its skin. What the package layer's lock refuses is the token's
 * identity: rename and delete are the site's own on a site-minted token
 * (the record's origin) and on every token where the lock is lifted (the
 * management read's `locked`: false on the site's own skin and under the
 * developer gate), never on a package token a consuming site only
 * overrides. Revert (the route that drops the site's record and, under
 * the gate, rewrites the package's from disk: "use the shipped value",
 * the unset gesture) follows the shipped state exactly as a swatch's
 * does. Delete carries the two permission grades: a referenced token
 * needs the in-use grade (its delete runs the rewrite-then-delete batch),
 * an unreferenced one either grade.
 *
 * @param {object} token
 *   The token record (delivery shape).
 * @param {object} config
 *   The gesture gates (drupalSettings.daedalusStylish.tokens):
 *   {canCreate, canUpdate, canDelete, canDeleteInUse}.
 * @param {?{usages: number, layer: string, locked: boolean,
 *   shipped_state: ?string}} management
 *   The card's per-open management read (the usage route: the live
 *   reference count, the layer(s) holding the token, whether the package
 *   layer is locked on this site, the shipped state against the disk
 *   copy), or null while it is in flight or not readable: then the count
 *   reads as zero, the lock as holding, and revert waits for the state.
 *
 * @return {{editValue: boolean, editDescription: boolean, offer: boolean,
 *   rename: boolean, revert: boolean, delete: boolean}}
 */
export const tokenCardGestures = (token, config = {}, management = null) => {
  const update = Boolean(config.canUpdate);
  const used = (management?.usages ?? 0) > 0;
  const own = tokenLayer(token) === 'site' || management?.locked === false;
  return {
    editValue: update,
    editDescription: update,
    offer: update,
    rename: update && own,
    revert: update && management?.shipped_state === 'modified',
    delete:
      own &&
      (used
        ? Boolean(config.canDeleteInUse)
        : Boolean(config.canDelete) || Boolean(config.canDeleteInUse)),
  };
};
