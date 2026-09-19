import { islandLines, islandOps } from './island.js';
import { ELEMENT_LAYER } from './layers.js';
import {
  effectiveEntry,
  orderedEntryRefs,
  findVariant,
} from '../variant-scope.js';
import { fragmentStyles } from '../slot-key.js';
import { parseKey } from '../style-guide.js';

/**
 * The single-element islands view model (pure; the view renders it).
 *
 * In the islands architecture ALL structure is chrome: which islands exist
 * for an element — its default declaration list, a WHEN row per condition
 * holding values, a part row per styled sub-element —
 * is a projection of the style map and the worn variants, never
 * something typing creates. This module computes that roster, the
 * removal-gesture honesty reads, and the settle cadence filter (which of
 * an island's diffed ops have actually SETTLED and may commit). Everything
 * is data-in/data-out so the same logic serves both hosts and gets
 * node-test backpressure.
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Orders condition names the way rows render: 'default' first (always
 * present), declared conditions in the guide's vocabulary order, unknown
 * names last in their given order (inert data still shows honestly).
 * Deduplicates, so callers can merge sources (an entry's holdings plus
 * materialized drafts) without bookkeeping.
 *
 * @param {string[]} names
 *   Condition names in any order, 'default' optional, duplicates fine.
 * @param {object} guide
 *   The style guide payload (for the conditions vocabulary order).
 *
 * @return {string[]}
 *   Condition names, 'default' first.
 */
export const orderConditions = (names, guide) => {
  const declared = Object.keys(guide?.conditions ?? {});
  const rank = (condition) => {
    const index = declared.indexOf(condition);
    return index === -1 ? declared.length : index;
  };
  const unique = [...new Set(names)].filter(
    (condition) => condition !== 'default',
  );
  unique.sort((a, b) => rank(a) - rank(b));
  return ['default', ...unique];
};

/**
 * The condition islands an entry needs: 'default' always, then every named
 * condition holding at least one value, in the guide's declaration order
 * (unknown names last, stored order — inert data still shows honestly).
 *
 * @param {?object} entry
 *   The RESOLVED entry (effectiveEntry().entry): variant-supplied
 *   conditions get their WHEN rows exactly like own ones.
 * @param {object} guide
 *   The style guide payload (for the conditions vocabulary order).
 *
 * @return {string[]}
 *   Condition names, 'default' first.
 */
export const islandConditions = (entry, guide) => {
  if (!isEntryObject(entry)) {
    return ['default'];
  }
  const holding = Object.entries(entry)
    .filter(
      ([condition, values]) =>
        condition !== 'default' &&
        condition !== 'variants' &&
        isEntryObject(values) &&
        Object.values(values).some(
          (value) => value !== null && value !== undefined,
        ),
    )
    .map(([condition]) => condition);
  return orderConditions(holding, guide);
};

/**
 * The island roster for one selected element.
 *
 * Enumerates what the chrome renders: the root's condition islands
 * (default always), and a part row per styled sub-element — own '::.class'
 * map entries AND parts the worn references supply (each reference read
 * through ITS OWN fragment, the delivery read), so a variant-styled part
 * gets its row with zero own overrides. TAG-TIER keys ('{key}::p', the
 * element-scoped middle tier of the three-tier text model) ride the same
 * list: a tag row is a sub row like a part row, rendered alongside them
 * (worn references never supply one — tag tiers are host-map-only).
 * Parts keep first-seen order: own map order, then reference delivery
 * order. Undeclared or malformed part selectors that cannot parse as
 * keys are skipped (nothing could commit to them).
 *
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The selected element's selectable key (never a '::' part key).
 * @param {object} guide
 *   The style guide payload.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?Array<string>} axes
 *   The root scope's axis vocabulary (delivery order).
 *
 * @return {{conditions: string[],
 *   parts: Array<{sub: string, key: string, conditions: string[]}>}}
 */
export const elementIslands = (map, key, guide, variants, axes = null) => {
  const conditions = islandConditions(
    effectiveEntry(map, key, variants, axes).entry,
    guide,
  );

  const subs = [];
  const push = (sub) => {
    if (!subs.includes(sub) && parseKey(`${key}::${sub}`)) {
      subs.push(sub);
    }
  };
  Object.keys(map ?? {}).forEach((mapKey) => {
    if (mapKey.startsWith(`${key}::`)) {
      push(mapKey.slice(key.length + 2));
    }
  });
  orderedEntryRefs(map?.[key], variants, axes).forEach((ref) => {
    Object.keys(
      fragmentStyles(findVariant(variants, ref.variant)?.value, ref.fragment),
    ).forEach((relative) => {
      if (relative.startsWith('::')) {
        push(relative.slice(2));
      }
    });
  });
  const parts = subs.map((sub) => {
    const partKey = `${key}::${sub}`;
    return {
      sub,
      key: partKey,
      conditions: islandConditions(
        effectiveEntry(map, partKey, variants, axes).entry,
        guide,
      ),
    };
  });

  return { conditions, parts };
};

/**
 * Prunes unoccupied islands from a roster (the tree scopes' read for
 * UNSELECTED rows).
 *
 * An island survives only when its layered document prints at least one
 * line, own overrides or variant-supplied values alike (islandLines, lost
 * lines included), and a part row whose islands all prune drops whole.
 * The single-element body and the selected tree row keep the full roster:
 * empty islands are the type-here surface there. On every other tree row
 * they are placeholder noise, and the way in is selecting the element.
 * In practice only 'default' islands prune (islandConditions already
 * admits named conditions on held values alone), but the read is uniform.
 *
 * @param {{conditions: string[], parts: Array<object>}} model
 *   elementIslands()'s roster.
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The roster's element key.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?Array<string>} axes
 *   The root scope's axis vocabulary (delivery order).
 *
 * @return {{conditions: string[], parts: Array<object>}}
 *   The pruned roster, same shape.
 */
export const occupiedRoster = (model, map, key, variants, axes = null) => {
  const occupied = (islandKey) => (condition) =>
    islandLines(map, islandKey, condition, variants, axes).length > 0;
  const parts = model.parts
    .map((part) => ({
      ...part,
      conditions: part.conditions.filter(occupied(part.key)),
    }))
    .filter((part) => part.conditions.length > 0);
  return {
    ...model,
    conditions: model.conditions.filter(occupied(key)),
    parts,
  };
};

/**
 * Merges materialized drafts into the roster.
 *
 * A materialization gesture (`.` -> part, `&`/`@` -> condition) creates a
 * chrome row + island BEFORE any declaration commits, so the row exists
 * only in view state until its first settled declaration writes it into
 * the map. This merge is where those drafts join the map-derived roster:
 * conditions slot into vocabulary order, draft parts append after the
 * map's parts (first-seen order holds), and anything the map already
 * surfaces deduplicates away — a draft that became real is absorbed, not
 * doubled. Draft part selectors that cannot parse as keys are dropped
 * (the gestures gate shape before drafting, this is the backstop).
 *
 * Merging never resurrects a pruned 'default' island (occupiedRoster):
 * a drafted named condition joins without dragging the empty default row
 * back in. Drafted NEW part rows keep their default island: that empty
 * island is exactly the draft's type-here surface.
 *
 * @param {{conditions: string[], parts: Array<object>}} model
 *   elementIslands()'s roster.
 * @param {?{conditions: ?object, parts: ?string[]}} drafts
 *   Materialized-but-uncommitted rows: conditions maps island keys (the
 *   element key or a part key) to drafted condition names; parts lists
 *   drafted part selectors ('.quote').
 * @param {string} key
 *   The selected element's key (the roster's root).
 * @param {object} guide
 *   The style guide payload.
 *
 * @return {{conditions: string[], parts: Array<object>}}
 *   The roster with drafts merged, same shape as elementIslands().
 */
export const draftRoster = (model, drafts, key, guide) => {
  const draftConditions = drafts?.conditions ?? {};
  const merge = (had, drafted) => {
    const merged = orderConditions([...had, ...drafted], guide);
    return had.includes('default')
      ? merged
      : merged.filter((name) => name !== 'default');
  };
  const conditions = merge(model.conditions, draftConditions[key] ?? []);
  const parts = model.parts.map((part) => ({
    ...part,
    conditions: merge(part.conditions, draftConditions[part.key] ?? []),
  }));
  (drafts?.parts ?? []).forEach((sub) => {
    if (parts.some((part) => part.sub === sub)) {
      return;
    }
    const partKey = `${key}::${sub}`;
    if (!parseKey(partKey)) {
      return;
    }
    parts.push({
      sub,
      key: partKey,
      conditions: orderConditions(draftConditions[partKey] ?? [], guide),
    });
  });
  return { ...model, conditions, parts };
};

/**
 * Merges the posed compound's GHOST island into the roster.
 *
 * The code-view twin of pose+edit minting a condition block in the widget
 * modes: when the canvas stands at a non-default compound the element
 * holds no island for, the roster gains an empty chrome-minted island at
 * the ROOT for that compound — ready to type into, wearing the POSED
 * marker its condition already earns. Typing commits through the island's
 * ordinary settle path, which writes the condition block into the map and
 * makes the row roster-real. The ghost is DERIVED, never stored view
 * state: empty again (or the pose moving away) means the next roster
 * print simply has no reason to include it, so the row disappears —
 * the same honesty rule as empty islands vanishing on deselect.
 *
 * Root only: parts materialize their conditions through the `&` gesture;
 * a pose minting a ghost per part row would be noise, not an invitation.
 *
 * @param {{conditions: string[], parts: Array<object>}} model
 *   The roster (elementIslands(), drafts merged).
 * @param {?string} posed
 *   The compound the canvas is posed at ('default' or null poses nothing).
 * @param {object} guide
 *   The style guide payload (for condition ordering).
 *
 * @return {{conditions: string[], parts: Array<object>}}
 *   The roster with the ghost merged (unchanged when the pose is resting
 *   or the compound already has a row).
 */
export const posedRoster = (model, posed, guide) => {
  if (!posed || posed === 'default' || model.conditions.includes(posed)) {
    return model;
  }
  return {
    ...model,
    conditions: orderConditions([...model.conditions, posed], guide),
  };
};

/**
 * The part-materialization completion set for a typed `.` token.
 *
 * Part selectors are REFERENCES into declared anatomy — typing cannot
 * create markup — so the completion offers the type's declared parts. On
 * a site whose world is not closed, an arbitrary well-formed class the
 * anatomy does not declare earns ONE extra entry, the explicit
 * confirm-materialize ("Create this part"): picking it is the confirmation,
 * nothing materializes from typing alone. Strict sites get no such entry —
 * their part vocabulary IS the declared anatomy.
 *
 * The offer is an ELEMENT projection (it projects the page, not the style
 * map), so it gates on DOM presence: a declared part whose presence fact
 * says ABSENT (the unlinked text block's anchor, svg media's img) is not
 * offered, styled or not — the same absolute rule the element-bound
 * controls follow in offered(). Rows already materialized from existing
 * declarations are the STORE projection and persist regardless; only the
 * offer to mint a new row gates here. Unknown presence (no facts, or a
 * selector the map never mentions — a satellite pre-broadcast) degrades
 * to offering; only a positive absence fact hides.
 *
 * @param {string} typed
 *   The token as typed, leading '.' included ('.qu').
 * @param {Array<{id: string, label: string}>} declared
 *   The declared anatomy (partsForKey()).
 * @param {object} options
 *   - closed: whether the guide's world is closed (propertiesClosed()).
 *   - presence: selector → bool DOM-presence facts for the owning
 *     element's declared parts (the layout context's self.parts), or null
 *     when unknown.
 *
 * @return {{options: Array<{sub: string, label: string}>, create: ?string}}
 *   Declared completions (the view lets the editor filter by the typed
 *   text) and the confirm-materialize selector, or null when the typed
 *   token is malformed, already declared, or the world is closed.
 */
export const partGestureOptions = (
  typed,
  declared,
  { closed = false, presence = null } = {},
) => {
  const options = (Array.isArray(declared) ? declared : [])
    .filter((part) => presence?.[part.id] !== false)
    .map((part) => ({ sub: part.id, label: part.label }));
  const wellFormed =
    typeof typed === 'string' && /^\.[A-Za-z0-9_-]+$/.test(typed);
  // Declared-but-absent never falls through to the create entry: absence
  // hides the part, it does not make its class an undeclared one.
  const isDeclared = (Array.isArray(declared) ? declared : []).some(
    (part) => part.id === typed,
  );
  const create = !closed && wellFormed && !isDeclared ? typed : null;
  return { options, create };
};

/**
 * The tag-row materialization offer: base's tag vocabulary, gated on
 * content presence.
 *
 * The tag tier's twin of partGestureOptions(), for a bare tag name typed
 * at a declaration position on an element island: picking an option
 * materializes a '{key}::{tag}' row alongside the part rows. The offer is
 * the site's base tag vocabulary (the one declaration of a prose tag's
 * styleability, labelled through the identity lane where an instance
 * namespace names the tag) and NOTHING else — there is no create entry on
 * any site, open or strict, because the tag tier is only grammar for
 * declared base tags (the server rejects an undeclared tag in every
 * enforcement mode).
 *
 * Like the part offer this is an ELEMENT projection, so it gates on
 * content presence: the key's markup annotation's `tags` fact (the
 * distinct tag names the element's prose contains, minted carriers and
 * never-minting tags alike) is the fact source, and a declared tag the
 * prose does not contain is not offered (a paragraph-only block does not
 * offer List; a block whose paragraphs link offers Link although no
 * carrier ever wears `a`). Rows already materialized from existing
 * declarations are the store projection and persist regardless. Unknown
 * presence (no annotation in reach — the satellite window pre-broadcast,
 * a block with no prose) degrades to offering; only positive absence
 * hides. Carriers (`instances`) stay the identity and label source and
 * never gate this offer.
 *
 * @param {Object<string, string>} tags
 *   The base tag vocabulary ({tag: label}).
 * @param {?Array<string>} present
 *   The element's `_element.markup.tags` fact, or null when no annotation
 *   is reachable.
 *
 * @return {Array<{tag: string, label: string}>}
 *   The offered tags, vocabulary order.
 */
export const tagGestureOptions = (tags, present = null) => {
  const options = Object.entries(tags ?? {}).map(([tag, label]) => ({
    tag,
    label,
  }));
  if (!Array.isArray(present)) {
    return options;
  }
  return options.filter((option) => present.includes(option.tag));
};

/**
 * The unset ops that remove one island's OWN styles (the removal gesture).
 *
 * Removing a WHEN row (or a part row, per condition) unsets every own
 * value under the (key, condition) pair in ONE multi-property op — one
 * gesture, one history entry. Variant-supplied values are untouched by
 * construction: only the own layer holds anything to unset, which is
 * exactly the variant-restore honesty — after removal the variant's
 * values resurface (restoredLines() reports them), and the row honestly
 * stays until the variant is detached.
 *
 * @return {Array<object>}
 *   Zero or one update_style op ([] when the own layer holds nothing —
 *   removing a draft row is pure view state).
 */
export const conditionRemovalOps = (map, key, condition) => {
  const own = map?.[key]?.[condition];
  if (!isEntryObject(own)) {
    return [];
  }
  const properties = {};
  Object.entries(own).forEach(([property, value]) => {
    if (value !== null && value !== undefined) {
      properties[property] = null;
    }
  });
  if (!Object.keys(properties).length) {
    return [];
  }
  return [
    {
      op: 'update_style',
      key,
      ...(condition !== 'default' ? { condition } : {}),
      properties,
    },
  ];
};

/**
 * The unset ops that remove a whole part row: every own condition layer.
 * Same honesty as conditionRemovalOps(): variant-supplied part values
 * survive (only detach removes a variant's delivery), so a
 * variant-styled part's row remains, showing its variant lines.
 *
 * @return {Array<object>}
 *   update_style ops, [] when the part has no own entry (a draft row).
 */
export const partRemovalOps = (map, key) => {
  const entry = map?.[key];
  if (!isEntryObject(entry)) {
    return [];
  }
  const ops = [];
  Object.keys(entry).forEach((condition) => {
    if (condition === 'variants') {
      return;
    }
    ops.push(...conditionRemovalOps(map, key, condition));
  });
  return ops;
};

/**
 * The lines that will SURVIVE removing an island's own styles: what worn
 * variants deliver at the (key, condition) pair — every variant-layer line
 * of the layered document, shadowed ones included (they resurface once
 * their override unsets). The view uses this for variant-restore honesty:
 * a non-empty result means the removal gesture cannot empty the row, only
 * strip the element's own layer.
 *
 * @return {Array<object>}
 *   The variant-layer subset of islandLines(), same line shapes.
 */
export const restoredLines = (map, key, condition, variants, axes = null) =>
  islandLines(map, key, condition, variants, axes).filter(
    (line) => line.layer !== ELEMENT_LAYER,
  );

/**
 * Should the buffer re-print to restore deleted variant-supplied lines?
 *
 * Deleting a variant's line honestly no-ops — the variant keeps
 * delivering — but a no-op commits nothing, so no authoritative refresh
 * ever brings the line back while the island stays mounted. The view
 * re-prints the baseline at settle boundaries instead, gated here: some
 * variant-supplied line must be genuinely gone (its exact property+value
 * text absent from the buffer; a held property is a half-retyped line,
 * not a deletion), and the buffer must hold no other work — no held
 * tokens, no uncommitted diffs (islandOps covers changed values and
 * own-line deletions alike). Formatting-only differences never trigger a
 * re-print on their own. Own lines claim their buffer copies first so an
 * equal-valued variant duplicate cannot mask an own deletion.
 *
 * @param {string} key
 *   The island's full style-map key.
 * @param {string} condition
 *   The island's condition.
 * @param {{declarations: Array<object>, held: Set<string>}} analysis
 *   analyzeIsland()'s result for the buffer.
 * @param {Array<{property: string, value: string, layer: string}>} lines
 *   The island's baseline lines (islandLines()).
 *
 * @return {boolean}
 *   TRUE when re-printing the baseline restores variant lines and loses
 *   nothing.
 */
export const shouldRestoreTinted = (key, condition, analysis, lines) => {
  if (analysis.held.size || islandOps(key, condition, analysis, lines).length) {
    return false;
  }
  const counts = new Map();
  analysis.declarations.forEach((declaration) => {
    const token = `${declaration.property}\0${declaration.value}`;
    counts.set(token, (counts.get(token) ?? 0) + 1);
  });
  let missing = false;
  const claim = (line) => {
    const token = `${line.property}\0${line.value}`;
    const count = counts.get(token) ?? 0;
    if (count > 0) {
      counts.set(token, count - 1);
    } else if (line.layer !== ELEMENT_LAYER) {
      missing = true;
    }
  };
  lines.filter((line) => line.layer === ELEMENT_LAYER).forEach(claim);
  lines.filter((line) => line.layer !== ELEMENT_LAYER).forEach(claim);
  return missing;
};

/**
 * The cadence filter: which of an island's diffed ops have SETTLED.
 *
 * Per-declaration commit without blur: a set commits only when its
 * declaration is terminated (';' after the value — the settle boundary)
 * and, unless `includeActive`, not on the line the cursor is on (typing
 * "1rem" toward "1rem 2rem" must not commit each intermediate value; the
 * declaration settles when the cursor leaves it, a ';' lands, or the
 * island blurs). Deletion = unset on the boundary: unset ops pass
 * unconditionally — islandOps' held-set already protects half-retyped
 * lines, and a whole-line deletion IS the settle gesture. Invalid
 * declarations were never in the ops (they hold only themselves).
 *
 * @param {string} key
 *   The island's full style-map key.
 * @param {string} condition
 *   The island's condition.
 * @param {string} text
 *   The island's buffer text.
 * @param {{declarations: Array<object>, held: Set<string>}} analysis
 *   analyzeIsland()'s result for the buffer.
 * @param {Array<{property: string, value: string, variant: ?string}>} lines
 *   The island's baseline lines (islandLines()).
 * @param {object} options
 *   - cursor: the cursor offset in the buffer, or null (no active line).
 *   - includeActive: commit the cursor's declaration too (';' just typed,
 *     or the island blurred).
 *
 * @return {Array<object>}
 *   The settled subset of islandOps(), same op shapes.
 */
export const settleOps = (
  key,
  condition,
  text,
  analysis,
  lines,
  { cursor = null, includeActive = false } = {},
) => {
  const lineIndexOf = (offset) => {
    let line = 0;
    const end = Math.min(offset, text.length);
    for (let i = 0; i < end; i += 1) {
      if (text[i] === '\n') {
        line += 1;
      }
    }
    return line;
  };
  const terminatorOf = (declaration) => {
    let i = declaration.to;
    while (i < text.length && /\s/.test(text[i])) {
      i += 1;
    }
    return text[i] === ';' ? i : null;
  };
  const cursorLine = cursor === null ? null : lineIndexOf(cursor);
  const lastValidDeclaration = (property) => {
    for (let i = analysis.declarations.length - 1; i >= 0; i -= 1) {
      const declaration = analysis.declarations[i];
      if (declaration.property === property && declaration.valid) {
        return declaration;
      }
    }
    return null;
  };

  return islandOps(key, condition, analysis, lines).filter((op) => {
    const [property, value] = Object.entries(op.properties)[0];
    if (value === null) {
      return true;
    }
    const declaration = lastValidDeclaration(property);
    if (!declaration) {
      return false;
    }
    const terminator = terminatorOf(declaration);
    if (terminator === null) {
      return false;
    }
    if (
      !includeActive &&
      cursorLine !== null &&
      lineIndexOf(declaration.from) <= cursorLine &&
      cursorLine <= lineIndexOf(terminator)
    ) {
      return false;
    }
    return true;
  });
};
