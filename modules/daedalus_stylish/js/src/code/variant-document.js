import { captureSources, entryRefs, findVariant } from '../variant-scope.js';
import { ROOT_KINDS, compareSegments, parseSlotKey } from '../slot-key.js';
import {
  CLASS_PART,
  parseSelector,
} from '../../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';
import { ELEMENT_LAYER, documentLayerId } from './layers.js';
import { stagesLines } from '../pending.js';

/**
 * The variant-document model (pure; the editing session renders it).
 *
 * A variant is its own document, not a write-target flip over the
 * places (four controls, one loud mode): the editing session renders the
 * variant's OWN key space — the root, its part and '> *' rows, and the
 * slot-path tree of a composition — in wearer context, and every edit is
 * a PENDING variant change (pending.js update sessions), committed only
 * by the Push/Discard bar. This module owns everything computable without
 * a DOM: the document's slot tree (documentTree), the per-fragment
 * current-page carriers behind the "carried by" chrome (carriedBy), the
 * wearing that anchors an element to the document, the baseline a slot
 * prints and diffs against, the session overlay (staged lines rendered in
 * place with pending marks), the wearer-cascade honesty reads (a document
 * line a higher layer beats on the anchoring wearer strikes through with
 * the winner named), and the update-from-element pre-staged diff.
 * Everything is data-in/data-out for node-test backpressure (the
 * line-menu.js pattern).
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * The wearing that anchors an element to a variant document.
 *
 * The editing session always renders in wearer context (the
 * wearer-derived vocabulary rule: guide declarations, parts, and the
 * honesty reads all resolve through a concrete element): an element
 * anchors the document exactly when its entry wears a reference to it —
 * a root wearing, or a fragment a composition distributed. The returned
 * fragment is the element's base slot in the variant's key space: the
 * element's key at relative R maps to the document at `fragment + R`
 * (fragmentStyles' concatenation).
 *
 * @param {?object} entry
 *   The element's ROOT style entry (references live on base entries).
 * @param {string} reference
 *   The document's reference, verbatim as the map stores it.
 *
 * @return {?{variant: string, fragment: string}}
 *   The wearing, or null (this element does not wear the document).
 */
export const wearingOf = (entry, reference) =>
  entryRefs(entry).find((ref) => ref.variant === reference) ?? null;

/**
 * The document's slot tree: the variant's whole key space as render nodes.
 *
 * One node per SLOT — the base key a {variant, fragment} wearing names,
 * the element grain — parents before children in slot-path order
 * (compareSegments: layout tree order), the root ('') always first and
 * always present, even in an empty document (the honest edit surface a
 * fresh or wears-nothing document still offers). Each node lists its
 * document KEYS in render order: the element's own entry, then its
 * selector entries grouped by their first part class in first-seen order
 * (a part's own entry directly before the selectors under it), the
 * part-less selectors (the children, a tag, a type) first. A key lists when the variant stores a non-empty
 * entry at it or the open session stages lines into it (a staged new slot
 * renders with its pending marks; an entry whose only content is a nested
 * "variants" list still lists — references are content), plus the root's
 * own key unconditionally. Slot paths parse against the document's root
 * kind (slot-key.js rootKindForScope over the variant's scope); keys the
 * grammar cannot parse keep stored order after every parsed slot with
 * kind null — rendered, never hidden (the server is the validator, the
 * panel is not).
 *
 * @param {?object} styles
 *   The variant's element-relative styles.
 * @param {?object} session
 *   The open pending session (update and skin sessions stage).
 * @param {?string} rootKind
 *   The document's root kind, one of slot-key.js ROOT_KINDS, or null
 *   (unresolvable scope: only path-less keys parse).
 *
 * @return {Array<{slot: string, depth: number, kind: ?string,
 *   detail: ?string, keys: Array<{key: string, selector: ?string}>}>}
 *   kind is 'root' for the root slot, the last segment's type
 *   ('container' | 'block' | 'field') for a path slot, null for an
 *   unparsed one; detail carries the block type or field name.
 */
export const documentTree = (styles, session = null, rootKind = null) => {
  const safeRoot =
    rootKind !== null && ROOT_KINDS.includes(rootKind) ? rootKind : null;
  const keys = new Set(['']);
  Object.entries(styles ?? {}).forEach(([key, entry]) => {
    if (isEntryObject(entry) && Object.keys(entry).length) {
      keys.add(key);
    }
  });
  (stagesLines(session) ? session.lines : []).forEach((line) => {
    if (typeof line.relative === 'string') {
      keys.add(line.relative);
    }
  });
  const slots = new Map();
  keys.forEach((key) => {
    const pos = key.indexOf('::');
    const base = pos === -1 ? key : key.slice(0, pos);
    if (!slots.has(base)) {
      slots.set(base, []);
    }
    slots.get(base).push(key);
  });
  const stored = [...slots.keys()];
  const parsed = new Map(
    stored.map((slot) => {
      // With no usable root kind only the path-less root parses; path slots
      // fall back to stored order below.
      const pathless = slot === '';
      return [
        slot,
        safeRoot !== null || pathless ? parseSlotKey(slot, safeRoot) : null,
      ];
    }),
  );
  const order = [...stored].sort((a, b) => {
    const parsedA = parsed.get(a);
    const parsedB = parsed.get(b);
    if (!parsedA || !parsedB) {
      // Unparsed slots keep stored order after every parsed one.
      if (parsedA || parsedB) {
        return parsedA ? -1 : 1;
      }
      return stored.indexOf(a) - stored.indexOf(b);
    }
    return (
      compareSegments(parsedA.segments, parsedB.segments) ||
      stored.indexOf(a) - stored.indexOf(b)
    );
  });
  return order.map((slot) => {
    const slotParsed = parsed.get(slot);
    // Suffixes are path-less keys, so they parse without a root kind.
    const suffixes = slots.get(slot).map((key) => ({
      key,
      selector: parseSlotKey(key.slice(slot.length), null)?.selector ?? null,
    }));
    // The group a selector files under: its first class when it is
    // part-first, else the part-less group (rank 1, right after the root).
    const groupOf = (selector) => {
      const first = parseSelector(selector)?.compounds[0];
      return first?.kind === CLASS_PART ? first.name : null;
    };
    const partOrder = [];
    suffixes.forEach(({ selector }) => {
      const group = groupOf(selector);
      if (group !== null && !partOrder.includes(group)) {
        partOrder.push(group);
      }
    });
    const rankOf = ({ selector }) => {
      if (selector === null) {
        return [0, 0];
      }
      const group = groupOf(selector);
      return [
        group === null ? 1 : 2 + partOrder.indexOf(group),
        parseSelector(selector).compounds.length,
      ];
    };
    suffixes.sort((a, b) => {
      const [groupA, depthA] = rankOf(a);
      const [groupB, depthB] = rankOf(b);
      return groupA - groupB || depthA - depthB;
    });
    const last = slotParsed?.segments.length
      ? slotParsed.segments[slotParsed.segments.length - 1]
      : null;
    return {
      slot,
      depth: slotParsed ? slotParsed.segments.length : 0,
      kind: last ? last.type : slotParsed ? 'root' : null,
      detail: last?.name ?? null,
      keys: suffixes,
    };
  });
};

/**
 * The current-page carriers of each document fragment ("carried by").
 *
 * Which keys on THIS page wear each of the document's fragments: the
 * root fragment's carriers are its root wearers, elements and rules
 * alike (a rule `container:X::> pb-text` wearing the document delivers
 * its root fragment at every match), a composition child slot's
 * carriers are the elements its distributed fragment landed on. A
 * reference is the document's record id (the paintCoverage matching
 * rule), so matching is verbatim. Fragments nothing on this page
 * carries are absent: the document still renders them (a variant's
 * reach is site-wide), the chrome just names no local carrier.
 *
 * @param {?object} map
 *   The style map (mirror view).
 * @param {?string} reference
 *   The document's reference (the variant's record id).
 *
 * @return {Map<string, string[]>}
 *   Fragment → carrier keys (elements and rules), both in map order (the
 *   map's canonical order is the document's, styleMapOf: node-less keys
 *   first, then the nodes in document order).
 */
export const carriedBy = (map, reference) => {
  const carriers = new Map();
  Object.entries(map ?? {}).forEach(([key, entry]) => {
    entryRefs(entry).forEach((ref) => {
      if (reference == null || ref.variant !== reference) {
        return;
      }
      if (!carriers.has(ref.fragment)) {
        carriers.set(ref.fragment, []);
      }
      carriers.get(ref.fragment).push(key);
    });
  });
  return carriers;
};

/**
 * One document slot's baseline: the variant's own declarations at a slot.
 *
 * The single-layer document the buffer prints and every diff runs
 * against — within its own sheet nothing shadows, so the lines carry the
 * element-mode shape with the layer facts at rest (layer: ELEMENT_LAYER
 * makes the island engine treat every line as the sheet's own, so
 * deletable: deleting a line IS expressible here, a pending unset).
 * Wearer-context shadowing is a DISPLAY overlay (documentLineWinner),
 * never part of the diff baseline.
 *
 * @param {?object} variantStyles
 *   The variant's element-relative styles.
 * @param {string} slot
 *   The document key ('' the root, '::.class' a part, a slot path plus
 *   suffixes for a composition child).
 * @param {string} condition
 *   The island's condition.
 *
 * @return {Array<{property: string, value: string, layer: string,
 *   variant: null, lost: boolean, winner: null}>}
 */
export const documentBaselineLines = (variantStyles, slot, condition) => {
  const values = variantStyles?.[slot]?.[condition];
  if (!isEntryObject(values)) {
    return [];
  }
  const lines = [];
  Object.entries(values).forEach(([property, value]) => {
    if (value === null || value === undefined) {
      return;
    }
    lines.push({
      property,
      value: String(value).trim(),
      layer: ELEMENT_LAYER,
      variant: null,
      lost: false,
      winner: null,
    });
  });
  return lines;
};

/**
 * The baseline with an update session's staged lines rendered in place.
 *
 * What a document island PRINTS: a staged set replaces its baseline line
 * (or appends, for a new property) and wears the pending mark ({pending:
 * true, pendingId} — the view renders the focus treatment and the
 * dismiss control); a staged unset removes its line from the buffer
 * entirely — the printed sheet IS what Push produces, and re-typing the
 * line is the unset's dismiss gesture. Lines staged for other slots or
 * conditions belong to other islands and pass through untouched there.
 *
 * @param {Array<object>} baseline
 *   documentBaselineLines()'s result.
 * @param {?object} session
 *   The open pending session (update and skin sessions overlay).
 * @param {string} slot
 *   The document key.
 * @param {string} condition
 *   The island's condition.
 *
 * @return {Array<object>}
 *   The printable lines; staged ones carry {pending: true, pendingId}.
 */
export const overlaySessionLines = (baseline, session, slot, condition) => {
  const staged = new Map();
  (stagesLines(session) ? session.lines : [])
    .filter((line) => line.relative === slot && line.condition === condition)
    .forEach((line) => staged.set(line.property, line));
  const lines = [];
  baseline.forEach((line) => {
    const pending = staged.get(line.property);
    if (!pending) {
      lines.push(line);
      return;
    }
    staged.delete(line.property);
    if (pending.value === null) {
      // A pending unset: the line is gone from the sheet Push produces.
      return;
    }
    lines.push({
      ...line,
      value: String(pending.value).trim(),
      pending: true,
      pendingId: pending.id,
    });
  });
  staged.forEach((pending) => {
    if (pending.value !== null) {
      lines.push({
        property: pending.property,
        value: String(pending.value).trim(),
        layer: ELEMENT_LAYER,
        variant: null,
        lost: false,
        winner: null,
        pending: true,
        pendingId: pending.id,
      });
    }
  });
  return lines;
};

/**
 * The wearer cascade's winners, per property.
 *
 * From the wearer island's layered lines (stackLines()): which layer's
 * value the wearer actually shows for each property. The honesty read
 * behind document-line shadowing — a document edit a higher layer beats
 * on this wearer must say so, or the buffer promises a look Push cannot
 * deliver here.
 *
 * @param {Array<{property: string, layer: string, variant: ?string,
 *   lost: boolean}>} wearerLines
 *   stackLines() for the wearer's (key, condition) pair.
 *
 * @return {Map<string, {layer: string, variant: ?string}>}
 *   property → the winning layer in stackLines' winner shape (the layer
 *   id, and the reference on a variant layer). Properties no layer
 *   defines are absent.
 */
export const wearerWinners = (wearerLines) => {
  const winners = new Map();
  (wearerLines ?? []).forEach((line) => {
    if (!line.lost) {
      winners.set(line.property, { layer: line.layer, variant: line.variant });
    }
  });
  return winners;
};

/**
 * The layer beating a document line on this wearer, if any.
 *
 * Null when the document's own value wins (either half of a composition
 * pair: the winning layer is the document's own layer, a variant's or
 * the skin's at its scope), or when
 * nothing else defines the property; then the document line is what the
 * wearer shows (or will show on Push).
 *
 * @param {Map<string, {layer: string, variant: ?string}>} winners
 *   wearerWinners()'s result.
 * @param {string} reference
 *   The document's reference.
 * @param {string} property
 *   The line's property.
 *
 * @return {?{layer: string, variant: ?string}}
 *   The winning layer in stackLines' winner shape, or null.
 */
export const documentLineWinner = (winners, reference, property) =>
  winners.has(property) &&
  winners.get(property).layer !== documentLayerId(reference)
    ? winners.get(property)
    : null;

/**
 * One document slot as a condition-keyed entry, session overlaid — the
 * widget modes' projection of the variant's sheet.
 *
 * The widget-mode counterpart of the islands' print: where an island
 * renders one (slot, condition) line list, the property rows and basic
 * controls read a whole slot's entry (every condition at once, the
 * condition-keyed entry shape) so their values, other-condition chips, and
 * has-more hints all speak the variant's post-Push truth. Composes the
 * same primitives the islands print from (documentBaselineLines +
 * overlaySessionLines), so the two surfaces can never disagree about what
 * a slot says. The pending table is the chrome input: every staged line
 * at the slot — sets AND unsets (an unset's property is absent from the
 * entry, but its row still needs the pending-removal mark and the dismiss
 * gesture) — keyed condition → property → {id, unset}.
 *
 * @param {?object} variantStyles
 *   The variant's element-relative styles.
 * @param {string} slot
 *   The document key (for a wearer-context read: the wearing fragment +
 *   the target's element-relative key).
 * @param {?object} session
 *   The open pending session (update and skin sessions overlay).
 *
 * @return {{entry: object, pending: object}}
 *   entry: condition → property → value (the sheet Push produces);
 *   pending: condition → property → {id: string, unset: boolean}.
 */
export const documentSlotEntry = (variantStyles, slot, session) => {
  const conditions = new Set(
    Object.keys(
      isEntryObject(variantStyles?.[slot]) ? variantStyles[slot] : {},
    ).filter((name) => name !== 'variants'),
  );
  const staged = (stagesLines(session) ? session.lines : []).filter(
    (line) => line.relative === slot,
  );
  staged.forEach((line) => conditions.add(line.condition));
  const entry = {};
  const pending = {};
  conditions.forEach((condition) => {
    const lines = overlaySessionLines(
      documentBaselineLines(variantStyles, slot, condition),
      session,
      slot,
      condition,
    );
    lines.forEach((line) => {
      (entry[condition] ??= {})[line.property] = line.value;
      if (line.pending) {
        (pending[condition] ??= {})[line.property] = {
          id: line.pendingId,
          unset: false,
        };
      }
    });
  });
  staged.forEach((line) => {
    if (line.value === null) {
      (pending[line.condition] ??= {})[line.property] = {
        id: line.id,
        unset: true,
      };
    }
  });
  return { entry, pending };
};

/**
 * The update-from-element diff: the wearer's own values the variant does
 * not already say, as stageable lines.
 *
 * The retired "Update a variant…" doorway re-expressed in the pending
 * engine: every own (condition, property, value) in the wearer's
 * capture space that differs from the variant's stored value at the
 * corresponding slot becomes ONE pending set — reviewable and
 * dismissible in the document's pending session before Push. The walk is
 * captureSources' (the save/push capture space, instance parts excluded):
 * the element's own root and part entries map through the wearing's
 * fragment; for a ROOT wearing the staged subtree's children map through
 * their slot paths (the composition's reach). A FRAGMENT wearing stages
 * its own slice only — deeper slots belong to the composition that
 * distributed it. Unsets never pre-stage: the element cannot express "the
 * variant should stop defining this" by wearing it.
 *
 * @param {?object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The wearer's selectable key.
 * @param {string} reference
 *   The worn reference, verbatim.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {Array} tree
 *   The lean node tree (subtreeSlotTable's input).
 *
 * @return {Array<{relative: string, condition: string, property: string,
 *   value: string}>}
 *   stageLine()-ready lines, [] when nothing differs (the menu gates the
 *   doorway on this).
 */
export const updateFromElementLines = (
  map,
  key,
  reference,
  variants,
  tree = [],
) => {
  const wearing = wearingOf(map?.[key], reference);
  const variant = findVariant(variants, reference);
  if (!wearing || !variant) {
    return [];
  }
  const lines = [];
  const add = (slot, entry) => {
    Object.entries(entry).forEach(([condition, values]) => {
      if (condition === 'variants' || !isEntryObject(values)) {
        return;
      }
      Object.entries(values).forEach(([property, raw]) => {
        if (raw === null || raw === undefined) {
          return;
        }
        const value = String(raw).trim();
        const stored = variant.value?.[slot]?.[condition]?.[property];
        if (
          stored === undefined ||
          stored === null ||
          String(stored).trim() !== value
        ) {
          lines.push({ relative: slot, condition, property, value });
        }
      });
    });
  };
  const { own, direct } = captureSources(map, key, tree);
  own.forEach(({ relative, entry }) => add(wearing.fragment + relative, entry));
  if (wearing.fragment === '') {
    direct.forEach(({ relative, entry }) => add(relative, entry));
  }
  return lines;
};

/**
 * Full map keys → capture-relative keys for a save session's anchor.
 *
 * The save session's pending set IS the live capture; rendering it inline
 * in the islands needs each island's map key translated to the capture
 * payload's relative key, so a line's dismiss mark addresses the same row
 * id the model prunes by (pendingLineId over the capture relative).
 * captureSources() is the one capture walk, so the table's coverage IS
 * the capture's: keys it does not carry (instance parts, non-addressable
 * children, elements outside the subtree) are not captured and render no
 * marks.
 *
 * @param {?object} map
 *   The style map (mirror view).
 * @param {string} anchor
 *   The save session's capture root.
 * @param {Array} tree
 *   The lean node tree.
 *
 * @return {Map<string, string>}
 *   Full map key → capture-relative key.
 */
export const captureRelativeTable = (map, anchor, tree = []) => {
  const table = new Map();
  const { own, direct } = captureSources(map, anchor, tree);
  [...own, ...direct].forEach(({ key, relative }) => table.set(key, relative));
  return table;
};
