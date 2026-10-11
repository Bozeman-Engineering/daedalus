import { ROOT_KINDS, compareSegments, parseSlotKey } from './slot-key.js';
import { skinDocument } from './code/layers.js';

/**
 * The pending/push engine's model (pure; the push bar renders and commits
 * it, and the variant-document editors write through it).
 *
 * Variant edits are pending-until-Push (the editing session's cadence;
 * the commit-cadence boundary IS the mode boundary): variants have
 * no tempstore net, so the durability net is the explicit
 * Push, which mints one revision of the variant content entity and
 * restyles every wearer site-wide (workspaces stage it). This module owns
 * the session shapes and everything computable without a DOM:
 *
 * - An UPDATE session is the per-document pending edit store: one open
 *   session per edited variant document, holding the staged lines (a
 *   set or a null
 *   unset per relative key x condition x property) that Push merges over
 *   the variant's current styles into a master-push payload.
 * - A SAVE session is save-as-variant re-expressed as pending lines: the
 *   staged capture (variant-scope.js saveStyles()) IS the pending set.
 *   The session stores no copy of it — the capture derives fresh from the
 *   live map on every read, so edits made while the session is open stay
 *   captured — plus the dismissals (the draft document's excluded rows)
 *   that prune rows out of what Push commits.
 * - A SKIN session is the update session's site-scope twin: the pending
 *   edit store of the active skin's document at one declaring scope
 *   (`base:h2`, `block_type:text`, `kind:container`), the same staged
 *   lines over the same relative keys ('' the scope's element, '::' plus
 *   a selector under it), staged and dismissed through the same
 *   functions (stagesLines), and pushed as update_default ops, one per
 *   (key, condition) the lines touch (push-bar.js). Its reference is the
 *   skin document's (code/layers.js skinDocument), so every reader that
 *   keys on `session.reference` (the synced editing field, the stack's
 *   overlay, the leave-prompt) treats the two alike.
 *
 * Everything here is data-in/data-out for node-test backpressure; the
 * session object itself (label, axis, staged lines, dismissals) is view
 * state its holder owns (the Inspector), never source or server state.
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * A pending value row's stable identity within one session.
 */
export const pendingLineId = (relative, condition, property) =>
  `line|${relative}|${condition}|${property}`;

/**
 * A pending reference row's stable identity within one session.
 */
export const pendingRefId = (relative, variant) => `ref|${relative}|${variant}`;

/**
 * The render-ready rows of an element-relative styles payload.
 *
 * One group per payload entry, ordered as the tree reads: the capture
 * root ('') first, its parts next, then children shallow-first in slot
 * order with each child's parts after its own entry. Within a group,
 * reference rows precede value rows and the default condition's values
 * precede conditioned ones (delivery reading). Empty entries drop.
 *
 * @param {object} styles
 *   The element-relative payload (saveStyles()/pushStyles() shape).
 * @param {?string} rootKind
 *   The capture root's element kind (parseKey().kind), for slot-path
 *   parsing. An unknown kind degrades to payload order for path keys.
 *
 * @return {Array<{relative: string, kind: ?string, detail: ?string,
 *   part: ?string, refs: Array<{variant: string, id: string}>,
 *   lines: Array<{condition: string, property: string, value: string,
 *   id: string}>}>}
 */
export const payloadRows = (styles, rootKind = null) => {
  const safeRoot =
    rootKind !== null && ROOT_KINDS.includes(rootKind) ? rootKind : null;
  const keys = Object.keys(styles ?? {}).filter((key) =>
    isEntryObject(styles[key]),
  );
  const parsed = new Map(
    keys.map((key) => {
      // With no usable root kind only path-less keys ('' and '::.class')
      // parse; path keys fall back to payload order below.
      const pathless = key === '' || key.startsWith('::');
      return [
        key,
        safeRoot !== null || pathless ? parseSlotKey(key, safeRoot) : null,
      ];
    }),
  );
  const order = [...keys].sort((a, b) => {
    const parsedA = parsed.get(a);
    const parsedB = parsed.get(b);
    if (!parsedA || !parsedB) {
      // Unparsed keys keep payload order after every parsed one.
      if (parsedA || parsedB) {
        return parsedA ? -1 : 1;
      }
      return keys.indexOf(a) - keys.indexOf(b);
    }
    const bySegments = compareSegments(parsedA.segments, parsedB.segments);
    if (bySegments !== 0) {
      return bySegments;
    }
    // Same base: the element's own entry before its selectors, selectors
    // in payload order.
    if ((parsedA.selector === null) !== (parsedB.selector === null)) {
      return parsedA.selector === null ? -1 : 1;
    }
    return keys.indexOf(a) - keys.indexOf(b);
  });
  return order
    .map((key) => {
      const entry = styles[key];
      const slot = parsed.get(key);
      const partPos = key.indexOf('::');
      const refs = (Array.isArray(entry.variants) ? entry.variants : [])
        .filter((ref) => typeof ref?.variant === 'string' && ref.variant !== '')
        .map((ref) => ({
          variant: ref.variant,
          id: pendingRefId(key, ref.variant),
        }));
      const conditions = Object.keys(entry).filter(
        (name) => name !== 'variants' && isEntryObject(entry[name]),
      );
      conditions.sort(
        (a, b) => (a === 'default' ? -1 : 0) - (b === 'default' ? -1 : 0),
      );
      const lines = [];
      conditions.forEach((condition) => {
        Object.entries(entry[condition]).forEach(([property, value]) => {
          lines.push({
            condition,
            property,
            value,
            id: pendingLineId(key, condition, property),
          });
        });
      });
      const last = slot?.segments.length
        ? slot.segments[slot.segments.length - 1]
        : null;
      return {
        relative: key,
        kind: last ? last.type : slot ? 'root' : null,
        detail: last?.name ?? null,
        part: slot ? slot.selector : partPos === -1 ? null : key.slice(partPos + 2),
        refs,
        lines,
      };
    })
    .filter((group) => group.refs.length || group.lines.length);
};

/**
 * A payload pruned to the rows NOT dismissed: what a save session commits.
 *
 * Dismissed value rows drop their property; dismissed reference rows drop
 * their reference. Conditions, reference lists, and whole entries that
 * end up empty drop with them, so the committed payload never carries
 * hollow shells the validator would have to tolerate.
 *
 * @param {object} styles
 *   The capture payload.
 * @param {Set<string>|Array<string>} dismissed
 *   The dismissed row ids (pendingLineId()/pendingRefId()).
 *
 * @return {object}
 *   A fresh pruned payload; the input is never mutated.
 */
export const prunedPayload = (styles, dismissed) => {
  const skip = dismissed instanceof Set ? dismissed : new Set(dismissed ?? []);
  const pruned = {};
  Object.entries(styles ?? {}).forEach(([key, entry]) => {
    if (!isEntryObject(entry)) {
      return;
    }
    const kept = {};
    const refs = (Array.isArray(entry.variants) ? entry.variants : []).filter(
      (ref) =>
        !(
          typeof ref?.variant === 'string' &&
          skip.has(pendingRefId(key, ref.variant))
        ),
    );
    if (refs.length) {
      kept.variants = refs;
    }
    Object.entries(entry).forEach(([condition, values]) => {
      if (condition === 'variants' || !isEntryObject(values)) {
        return;
      }
      const included = {};
      Object.entries(values).forEach(([property, value]) => {
        if (!skip.has(pendingLineId(key, condition, property))) {
          included[property] = value;
        }
      });
      if (Object.keys(included).length) {
        kept[condition] = included;
      }
    });
    if (Object.keys(kept).length) {
      pruned[key] = kept;
    }
  });
  return pruned;
};

/**
 * A payload's rule count: pending (not dismissed) rows over total rows.
 *
 * Value rows and reference rows both count — each is one dismissible fact
 * the commit takes or leaves.
 *
 * @return {{total: number, included: number}}
 */
export const payloadSummary = (styles, dismissed) => {
  const skip = dismissed instanceof Set ? dismissed : new Set(dismissed ?? []);
  let total = 0;
  let included = 0;
  payloadRows(styles).forEach((group) => {
    [...group.refs, ...group.lines].forEach((row) => {
      total += 1;
      if (!skip.has(row.id)) {
        included += 1;
      }
    });
  });
  return { total, included };
};

/**
 * A fresh save-as-variant session anchored at a capture root.
 *
 * The capture itself is never stored: readers derive it live
 * (saveStyles(map, anchor, layout, library, axis)) so the pending set
 * always says what Push would commit right now.
 *
 * @param {string} anchor
 *   The capture root's selectable key.
 * @param {string} axis
 *   The axis the new variant competes on — the fold input; commit passes
 *   it to the server only on a multi-axis scope (the payload's own
 *   default mirror).
 *
 * `label` and `description` are the draft's identity: the name and the
 * roster's "when to reach for this look" sentence (the same description
 * the AI lane writes and reads); the push bar sends both on
 * save_material. `offered` is the AI opt-out (ai-offer.js): born true,
 * the default every material has; the push bar sends the key only when
 * the draft is off.
 *
 * `linked` is the mint-time ownership consent: a variant minted inside a
 * blueprint placement is born as that blueprint's own look (the anchor's
 * placement provenance rides the save), and the session starts linked.
 * The header's consent chip says so and its unlink flips the flag, so
 * the commit sends no owner. The provenance itself is a layout fact
 * readers derive live (session-frame-model.js mintConsent), never stored
 * here; the session holds only the user's pick.
 */
export const saveSession = (anchor, axis = 'style') => ({
  kind: 'save',
  anchor,
  label: '',
  description: '',
  offered: true,
  axis,
  dismissed: [],
  linked: true,
});

/**
 * A fresh per-document update session against a worn variant.
 *
 * @param {string} reference
 *   The worn reference, VERBATIM as the map stores it (the
 *   verbatim-reference rule).
 * @param {string} anchor
 *   The wearer the document was entered from (the preview context): the
 *   styling target's wear key, an element or a rule wearing the
 *   document; the pushed payload itself is variant-relative and
 *   anchor-free.
 */
export const updateSession = (reference, anchor) => ({
  kind: 'update',
  reference,
  anchor,
  lines: [],
});

/**
 * A fresh skin session against the active skin's document at a scope.
 *
 * The update session's site-scope twin: `reference` is the skin
 * document's (code/layers.js skinDocument, the scope's skin layer id),
 * so the synced editing field, the stack overlay and the one-session-
 * per-document reads key on it exactly as on a variant's; `scope` is the
 * declaring scope the lines patch (update_default's `scope`); `anchor`
 * the element the session was entered from, whose stack previews the
 * document (an element of the scope: the h2 whose Size row offered the
 * doorway, the section whose kind block delivers). The lines' relative
 * keys are the scope's defaults keys ('' and '::' plus a selector).
 *
 * @param {string} scope
 *   The declaring scope ("base:h2", "block_type:text", "kind:container").
 * @param {string} anchor
 *   The anchoring wear key.
 */
export const skinSession = (scope, anchor) => ({
  kind: 'skin',
  reference: skinDocument(scope),
  scope,
  anchor,
  lines: [],
});

/**
 * Whether a session stages lines: an update session or a skin session
 * (a save session's pending set is its live capture).
 *
 * The one read every staging function and every reader of
 * `session.lines` gates on, so the skin session rides the update
 * session's machinery whole and a third kind never forks it.
 */
export const stagesLines = (session) =>
  session?.kind === 'update' || session?.kind === 'skin';

/**
 * Stages one pending edit into an update session (upsert by row id).
 *
 * A string value is a pending set, null a pending unset (removal from the
 * variant). Save sessions take no staged lines — their pending set IS the
 * derived capture — so they pass through untouched.
 *
 * @return {object}
 *   A fresh session; the input is never mutated.
 */
export const stageLine = (
  session,
  { relative, condition, property, value },
) => {
  if (!stagesLines(session)) {
    return session;
  }
  const id = pendingLineId(relative, condition, property);
  return {
    ...session,
    lines: [
      ...session.lines.filter((line) => line.id !== id),
      { id, relative, condition, property, value },
    ],
  };
};

/**
 * Replaces one island's staged lines wholesale (the settle-boundary write).
 *
 * The document islands re-derive their whole (slot, condition) diff against
 * the variant baseline at every settle boundary, so staging is
 * REPLACEMENT, not accumulation: lines the buffer no longer says
 * (re-typed back to baseline — the pending set's dismiss-by-editing
 * gesture) drop, and everything staged for other islands survives
 * untouched. `lines` carry {property, value} ({value: null} = a pending
 * unset); ids mint here, the one id-minting path. A no-change replacement
 * returns the SAME session object so subscribers can skip re-rendering.
 *
 * @return {object}
 *   A fresh session (or the input when nothing changed); save sessions
 *   pass through untouched.
 */
export const stageIslandLines = (session, relative, condition, lines) => {
  if (!stagesLines(session)) {
    return session;
  }
  const staged = (Array.isArray(lines) ? lines : []).map(
    ({ property, value }) => ({
      id: pendingLineId(relative, condition, property),
      relative,
      condition,
      property,
      value,
    }),
  );
  const existing = session.lines.filter(
    (line) => line.relative === relative && line.condition === condition,
  );
  const unchanged =
    existing.length === staged.length &&
    staged.every((line) =>
      existing.some(
        (candidate) =>
          candidate.id === line.id && candidate.value === line.value,
      ),
    );
  if (unchanged) {
    return session;
  }
  return {
    ...session,
    lines: [
      ...session.lines.filter(
        (line) => !(line.relative === relative && line.condition === condition),
      ),
      ...staged,
    ],
  };
};

/**
 * Stages one widget-settle value against the variant's stored baseline.
 *
 * The widget modes' per-value counterpart of stageIslandLines (the code
 * islands re-derive whole (slot, condition) diffs; a widget settles ONE
 * property): a value differing from what the variant stores at the slot
 * stages as a pending set, an empty/null value where the variant stores
 * one stages as a pending unset, and a value settling back ON the stored
 * baseline DISMISSES the staged line instead — reverting a control
 * unstages it, exactly like re-typing a code line back to baseline. Save
 * sessions pass through untouched (their pending set is the capture).
 *
 * @param {object} session
 *   The open update session.
 * @param {?object} documentStyles
 *   The edited document's stored styles (a variant's element-relative
 *   styles, a skin scope's defaults): the diff baseline.
 * @param {string} slot
 *   The written slot in the variant's key space (wearing fragment + the
 *   target's element-relative key).
 * @param {string} condition
 *   The editing compound the value settled at.
 * @param {string} property
 * @param {?string} value
 *   The settled value; null or '' means unset.
 *
 * @return {object}
 *   A fresh session; the input is never mutated.
 */
export const stageValue = (
  session,
  documentStyles,
  slot,
  condition,
  property,
  value,
) => {
  if (!stagesLines(session)) {
    return session;
  }
  const stored = documentStyles?.[slot]?.[condition]?.[property];
  const baseline =
    stored === undefined || stored === null ? null : String(stored).trim();
  const next =
    value === undefined || value === null || String(value).trim() === ''
      ? null
      : String(value).trim();
  if (next === baseline) {
    return dismissLine(session, pendingLineId(slot, condition, property));
  }
  return stageLine(session, {
    relative: slot,
    condition,
    property,
    value: next,
  });
};

/**
 * Dismisses one pending row by id: an update session drops the staged
 * line, a save session excludes the captured row from the commit.
 *
 * @return {object}
 *   A fresh session; the input is never mutated.
 */
export const dismissLine = (session, id) => {
  if (stagesLines(session)) {
    return {
      ...session,
      lines: session.lines.filter((line) => line.id !== id),
    };
  }
  return session.dismissed.includes(id)
    ? session
    : { ...session, dismissed: [...session.dismissed, id] };
};

/**
 * Restores one dismissed row of a save session (the un-exclude gesture).
 *
 * Update sessions pass through: a dismissed staged line is GONE (the
 * baseline resurfaced); re-editing the line is its restore.
 *
 * @return {object}
 *   A fresh session; the input is never mutated.
 */
export const restoreLine = (session, id) =>
  session.kind === 'save' && session.dismissed.includes(id)
    ? {
        ...session,
        dismissed: session.dismissed.filter((dismissed) => dismissed !== id),
      }
    : session;

/**
 * The session's pending row count — the bar's loud number and the
 * "anything to prompt about?" gate.
 *
 * @param {object} session
 * @param {?object} capture
 *   A save session's live capture (saveStyles() payload); unused for
 *   update sessions.
 *
 * @return {number}
 */
export const sessionCount = (session, capture = null) =>
  stagesLines(session)
    ? session.lines.length
    : payloadSummary(capture ?? {}, session.dismissed).included;

/**
 * What Push commits.
 *
 * A save session commits its capture pruned to the rows not dismissed
 * (the save_material op's `styles`). An update session commits the staged
 * lines merged over the variant's current styles, the master-push payload
 * (update_material with `styles` and no source key): sets land at
 * [relative][condition][property], null unsets remove (emptied conditions
 * and entries dropping with them, never hollow shells), and everything
 * untouched — other properties, other conditions, nested "variants"
 * lists — rides verbatim, so wearers only see the pushed changes. A skin
 * session merges the same way over the scope's stored defaults: the
 * document the scope wears after Push (the push bar spells the lines
 * themselves as update_default patches; this merge is what the panel
 * reads as the post-Push truth).
 *
 * @param {object} session
 * @param {object} context
 *   - capture: a save session's live capture payload.
 *   - documentStyles: an update session's target variant styles
 *     (variant.styles) or a skin session's scope defaults
 *     (guide.skinDefaults[scope]), never mutated.
 *
 * @return {object}
 */
export const sessionStyles = (
  session,
  { capture = null, documentStyles = null } = {},
) => {
  if (session.kind === 'save') {
    return prunedPayload(capture ?? {}, session.dismissed);
  }
  const styles = { ...(documentStyles ?? {}) };
  session.lines.forEach(({ relative, condition, property, value }) => {
    const entry = isEntryObject(styles[relative])
      ? { ...styles[relative] }
      : {};
    const values = isEntryObject(entry[condition])
      ? { ...entry[condition] }
      : {};
    if (value === null) {
      delete values[property];
    } else {
      values[property] = value;
    }
    if (Object.keys(values).length) {
      entry[condition] = values;
    } else {
      delete entry[condition];
    }
    if (Object.keys(entry).length) {
      styles[relative] = entry;
    } else {
      delete styles[relative];
    }
  });
  return styles;
};

/**
 * What Push commits for a skin session: one update_default per (key,
 * condition) the staged lines touch.
 *
 * update_default is a PATCH against one scope at one key and condition
 * (DaedalusStylishOperationHandler: `properties` values set, null values
 * unset), so the staged lines group by their relative key and condition,
 * in the order the first line of each group was staged, and every group
 * is one op in one batch: the batch lands atomically, so the pushed
 * document is exactly sessionStyles' merge or nothing.
 *
 * @param {object} session
 *   The skin session.
 *
 * @return {Array<{op: string, scope: string, key: string, condition:
 *   string, properties: object}>}
 */
export const skinPushOperations = (session) => {
  if (session?.kind !== 'skin') {
    return [];
  }
  const groups = new Map();
  session.lines.forEach(({ relative, condition, property, value }) => {
    const id = `${relative}|${condition}`;
    if (!groups.has(id)) {
      groups.set(id, {
        op: 'update_default',
        scope: session.scope,
        key: relative,
        condition,
        properties: {},
      });
    }
    groups.get(id).properties[property] = value;
  });
  return [...groups.values()];
};
