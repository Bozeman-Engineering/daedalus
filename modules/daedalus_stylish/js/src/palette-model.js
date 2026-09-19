/**
 * The Palette face's pure model.
 *
 * The library model's twin at value scope: the catalog's second section is
 * a flat, filterable list of the swatches the materials store carries (the
 * roster's swatch records, delivery shape) with a lifecycle card per
 * swatch, over a read-only skin section (the roster's tokens, the curated
 * half of the palette). This module owns everything the view can compute
 * without a DOM — the kind filter vocabulary, the row roster with its
 * shipped marker, the card's gesture roster as data (cardGestures'
 * posture: presence means permitted AND applicable) over the record plus
 * the card's per-gesture management read (usages, shipped state), the
 * condition-honest editor split for condition-keyed values, and the skin
 * token grouping — for node-test backpressure. The view (palette.js) only
 * renders and dispatches.
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
 * The skin tokens grouped per scale: the palette's read-only curated half.
 *
 * Roster order (declared scale order, then authoring order = display
 * order), each entry carrying its shape so the view paints color faces
 * only where a color face is honest. Read-only BY DESIGN: token values
 * live on the active skin, and the palette never grows a second skin
 * editor — the "edit the skin" line is the honesty chrome.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object} materials
 *   The materials roster.
 *
 * @return {Array<[string, Array<{name: string, ref: string,
 *   value: string, valueDark: ?string, shape: ?string}>]>}
 *   [scaleLabel, entries] pairs, [] when no skin tokens are minted.
 */
export const skinGroups = (guide, materials) => {
  const groups = new Map();
  tokenOptions(guide, materials).forEach((token) => {
    if (!groups.has(token.scaleLabel)) {
      groups.set(token.scaleLabel, []);
    }
    groups.get(token.scaleLabel).push({
      name: token.name,
      ref: token.ref,
      value: token.value,
      valueDark: token.valueDark,
      shape: token.shape,
    });
  });
  return [...groups.entries()];
};
