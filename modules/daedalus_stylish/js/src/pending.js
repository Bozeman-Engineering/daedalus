import { ROOT_KINDS, compareSegments, parseSlotKey } from './slot-key.js';
import { skinDocument } from './code/layers.js';
import { continuationSuffix, planRuleMove } from './rule-move.js';
import { canonicalSelector } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

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
 * Both line-staging kinds also hold MOVES: a rule's selector edit in the
 * document (`> d-text` becoming `> d-text:has(a)`) staged as its own
 * pending fact, never as lines, because a variant entry may carry a
 * nested `variants` list the lines cannot express and because one move
 * is one dismissible row with an honest identity. A move names the
 * document rule key it leaves (`{slot}::{selector}`, the move_style op's
 * `key`) and the relative selector it lands at (`to`); applying it is the
 * rule-move transformation (rule-move.js planRuleMove: the rule and every
 * key continuing it, whole entries), run over the stored document when a
 * reader needs the post-move truth (sessionStyles, skinPushOperations,
 * the document tree) and over the staged lines when the move is staged
 * (the lines at the moved keys follow at once, so the islands keep
 * printing them under the row's new key). A reader of a slot's baseline
 * (the islands, the widget rows, stageValue) reads movedStyles(), the
 * stored document as the moves leave it.
 *
 * Everything here is data-in/data-out for node-test backpressure; the
 * session object itself (label, axis, staged lines, dismissals) is view
 * state its holder owns (the Inspector), never source or server state.
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * A pending value row's stable identity within one session.
 *
 * @param {string} relative
 *   The row's document key.
 * @param {string} condition
 *   The row's condition compound.
 * @param {string} property
 *   The CSS property name.
 *
 * @return {string}
 *   The row id.
 */
export const pendingLineId = (relative, condition, property) =>
  `line|${relative}|${condition}|${property}`;

/**
 * A pending reference row's stable identity within one session.
 *
 * @param {string} relative
 *   The row's document key.
 * @param {string} variant
 *   The referenced variant.
 *
 * @return {string}
 *   The row id.
 */
export const pendingRefId = (relative, variant) => `ref|${relative}|${variant}`;

/**
 * A pending move row's stable identity within one session.
 *
 * @param {string} from
 *   The document rule key the move leaves (`{slot}::{selector}`).
 * @param {string} to
 *   The canonical relative selector it lands at.
 */
export const pendingMoveId = (from, to) => `move|${from}|${to}`;

/**
 * A document key split at its rule boundary, or null for a slot's own
 * entry ('' the root, a slot path): the slot is the rule's owner in the
 * document's key space, the selector what follows the first `::` (a
 * slot path never carries one; a selector may, as a pseudo).
 *
 * @param {string} key
 *   The document key.
 *
 * @return {?{slot: string, selector: string}}
 *   The owning slot and the relative selector, or null for a slot's own
 *   entry.
 */
const splitDocumentKey = (key) => {
  const pos = key.indexOf('::');
  return pos === -1
    ? null
    : { slot: key.slice(0, pos), selector: key.slice(pos + 2) };
};

/**
 * The document key of a relative selector under a slot.
 *
 * @param {string} slot
 *   The owning slot.
 * @param {string} selector
 *   The relative selector.
 *
 * @return {string}
 *   The `{slot}::{selector}` key.
 */
const documentKey = (slot, selector) => `${slot}::${selector}`;

/**
 * One staged move planned over a set of document keys.
 *
 * The plan is planRuleMove()'s over the keys sharing the move's slot
 * (their relative selectors): the rule to `to`, every continuation to
 * `to` continued by its suffix. Null when the keys hold nothing the move
 * touches although its landing is taken: the document the move already
 * left (a reader handed post-move styles), or a lines-only draft row
 * whose landing another row holds in the stored document while the
 * lines are what is being renamed. A refusal with something to move is
 * a real collision or a continuation the landing cannot carry, and
 * throws: the chrome's gate (collisions refuse) stands before staging,
 * so reaching it here means the document changed under the session.
 *
 * @param {string[]} keys
 *   The document keys the move is planned over.
 * @param {{from: string, to: string}} move
 *   The staged move.
 *
 * @return {?{prefix: string, moves: Object<string, string>}}
 *   The slot's key prefix and the plan's source-to-target map, or null
 *   when the keys hold nothing the move touches.
 */
const planMoveOver = (keys, move) => {
  const { slot, selector } = splitDocumentKey(move.from);
  const prefix = documentKey(slot, '');
  const relatives = keys
    .filter((key) => key.startsWith(prefix))
    .map((key) => key.slice(prefix.length));
  const plan = planRuleMove(relatives, selector, move.to);
  if (plan.refusal === null) {
    return { prefix, moves: plan.moves };
  }
  const touched = relatives.some(
    (relative) =>
      relative === selector || continuationSuffix(selector, relative) !== null,
  );
  if (!touched) {
    return null;
  }
  throw new Error(
    `The staged move of "${move.from}" to "${move.to}" is refused: ${plan.refusal.reason} at "${plan.refusal.key}".`,
  );
};

/**
 * Where one planned step lands a document key (itself when untouched).
 *
 * @param {?{prefix: string, moves: Object<string, string>}} step
 *   A planned step (planMoveOver), or null for a move carrying nothing.
 * @param {string} key
 *   The document key to follow.
 *
 * @return {string}
 *   The key the step lands it at.
 */
const landingOf = (step, key) => {
  if (step === null || !key.startsWith(step.prefix)) {
    return key;
  }
  const relative = key.slice(step.prefix.length);
  return step.moves[relative] === undefined
    ? key
    : step.prefix + step.moves[relative];
};

/**
 * Where the session's planned steps, in order, land a document key.
 *
 * @param {Array<?object>} steps
 *   The planned steps in staged order (applyMoves).
 * @param {string} key
 *   The document key to follow.
 *
 * @return {string}
 *   The key after every step.
 */
const followed = (steps, key) =>
  steps.reduce((current, step) => landingOf(step, current), key);

/**
 * The staged lines with one step's renames applied (ids re-minted; a
 * line the step leaves alone is the same object).
 *
 * @param {Array<object>} lines
 *   The staged lines.
 * @param {?object} step
 *   The planned step (planMoveOver), or null.
 *
 * @return {Array<object>}
 *   The lines with the renames applied.
 */
const renamedLines = (lines, step) =>
  lines.map((line) => {
    const relative = landingOf(step, line.relative);
    return relative === line.relative
      ? line
      : {
          ...line,
          relative,
          id: pendingLineId(relative, line.condition, line.property),
        };
  });

/**
 * The session's moves applied to a stored document, in staged order.
 *
 * Every entry a step names moves WHOLE (its conditions, their
 * properties, a nested `variants` list) to its landing and keeps its
 * position in the map, as the move_style op rekeys the host map.
 *
 * @param {object} session
 *   The line-staging session whose moves apply.
 * @param {?object} documentStyles
 *   The stored document, never mutated.
 *
 * @return {{styles: object, steps: Array<?object>}}
 *   The rekeyed document (a fresh object; the input is never mutated)
 *   and the planned steps, one per move (null for a move with nothing
 *   stored to carry), for following keys through.
 */
const applyMoves = (session, documentStyles) => {
  let styles = { ...(documentStyles ?? {}) };
  const steps = [];
  session.moves.forEach((move) => {
    const step = planMoveOver(Object.keys(styles), move);
    steps.push(step);
    if (step !== null) {
      const rekeyed = {};
      Object.entries(styles).forEach(([key, entry]) => {
        rekeyed[landingOf(step, key)] = entry;
      });
      styles = rekeyed;
    }
  });
  return { styles, steps };
};

/**
 * A stored document as the session's moves leave it: the baseline every
 * per-slot reader of an edited document prints and diffs against (the
 * islands' sheet at a row's key, the widget rows, stageValue), so a row
 * moved to a new key still shows the entry that moved with it. No lines
 * are merged (sessionStyles is the push payload). Idempotent over styles
 * already read through the moves, and the identity for a session that
 * stages nothing.
 *
 * @param {?object} session
 *   The open pending session.
 * @param {?object} documentStyles
 *   A variant's element-relative styles or a skin scope's defaults,
 *   never mutated.
 *
 * @return {object}
 *   The document as the moves leave it (a fresh object).
 */
export const movedStyles = (session, documentStyles) =>
  stagesLines(session)
    ? applyMoves(session, documentStyles).styles
    : { ...(documentStyles ?? {}) };

/**
 * The render-ready rows of an element-relative styles payload.
 *
 * One group per payload entry, ordered as the tree reads: the capture
 * root ('') first, its parts next, then children shallow-first in slot
 * order with each child's parts after its own entry. Within a group,
 * reference rows precede value rows and the default condition's values
 * precede conditioned ones (delivery reading). Empty entries drop. A
 * session's staged moves render after the entry groups, one row each
 * ("from to to": the group's `part` is the selector the rule leaves,
 * `move.to` the selector it lands at), dismissible by `move.id`.
 *
 * @param {object} styles
 *   The element-relative payload (saveStyles()/pushStyles() shape).
 * @param {?string} rootKind
 *   The capture root's element kind (parseKey().kind), for slot-path
 *   parsing. An unknown kind degrades to payload order for path keys.
 * @param {Array<{id: string, from: string, to: string}>} moves
 *   A line-staging session's moves (session.moves), if any.
 *
 * @return {Array<{relative: string, kind: ?string, detail: ?string,
 *   part: ?string, refs: Array<{variant: string, id: string}>,
 *   lines: Array<{condition: string, property: string, value: string,
 *   id: string}>, move: ?{id: string, to: string}}>}
 *   One group per document key in tree order, its reference rows, its
 *   value rows and the staged move leaving it.
 */
export const payloadRows = (styles, rootKind = null, moves = []) => {
  const safeRoot =
    rootKind !== null && ROOT_KINDS.includes(rootKind) ? rootKind : null;
  const keys = Object.keys(styles ?? {}).filter((key) =>
    isEntryObject(styles[key]),
  );
  // With no usable root kind only path-less keys ('' and '::.class')
  // parse; path keys fall back to payload order below.
  const parseKey = (key) => {
    const pathless = key === '' || key.startsWith('::');
    return safeRoot !== null || pathless ? parseSlotKey(key, safeRoot) : null;
  };
  const slotFacts = (slot) => {
    const last = slot?.segments.length
      ? slot.segments[slot.segments.length - 1]
      : null;
    return {
      kind: last ? last.type : slot ? 'root' : null,
      detail: last?.name ?? null,
    };
  };
  const parsed = new Map(keys.map((key) => [key, parseKey(key)]));
  const moveRows = (Array.isArray(moves) ? moves : []).map((move) => {
    const { slot, selector } = splitDocumentKey(move.from);
    return {
      relative: move.from,
      ...slotFacts(parseKey(slot)),
      part: selector,
      refs: [],
      lines: [],
      move: { id: move.id, to: move.to },
    };
  });
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
      return {
        relative: key,
        ...slotFacts(slot),
        part: slot
          ? slot.selector
          : partPos === -1
            ? null
            : key.slice(partPos + 2),
        refs,
        lines,
        move: null,
      };
    })
    .filter((group) => group.refs.length || group.lines.length)
    .concat(moveRows);
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
 * @param {object} styles
 *   The capture payload (saveStyles()).
 * @param {Set<string>|string[]} dismissed
 *   The dismissed row ids.
 *
 * @return {{total: number, included: number}}
 *   Every row, and the rows not dismissed.
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
  moves: [],
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
  moves: [],
});

/**
 * Whether a session stages lines (and moves): an update session or a
 * skin session (a save session's pending set is its live capture).
 *
 * The one read every staging function and every reader of
 * `session.lines` or `session.moves` gates on, so the skin session rides
 * the update session's machinery whole and a third kind never forks it.
 *
 * @param {?object} session
 *   The pending session, or null.
 *
 * @return {boolean}
 *   True for an update or skin session.
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
 * @param {object} session
 *   The open pending session.
 * @param {object} edit
 *   The pending edit.
 * @param {string} edit.relative
 *   The row's document key.
 * @param {string} edit.condition
 *   The condition compound.
 * @param {string} edit.property
 *   The CSS property name.
 * @param {?string} edit.value
 *   The pending value, or null for a pending unset.
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
 * @param {object} session
 *   The open pending session.
 * @param {string} relative
 *   The island's document key.
 * @param {string} condition
 *   The island's condition compound.
 * @param {Array<{property: string, value: ?string}>} lines
 *   The island's whole diff against the baseline.
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
 *   styles, a skin scope's defaults): the diff baseline, read through
 *   the session's moves (movedStyles), so a slot a move landed at diffs
 *   against the entry that moved there.
 * @param {string} slot
 *   The written slot in the variant's key space (wearing fragment + the
 *   target's element-relative key).
 * @param {string} condition
 *   The editing compound the value settled at.
 * @param {string} property
 *   The CSS property name.
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
  const stored = movedStyles(session, documentStyles)[slot]?.[condition]?.[
    property
  ];
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
 * Stages a rule's selector change into a line-staging session.
 *
 * The move is its own pending fact: `from` is the document rule key the
 * rule leaves (`{slot}::{selector}`, the row's key), `to` the relative
 * selector it lands at in any spelling the grammar reads (stored
 * canonical; the slot never changes, the owner rule). Staging it:
 *
 * - renames the staged lines the move carries at once (the rule's key
 *   and every staged key continuing it, planRuleMove over the session's
 *   line keys), so the islands keep printing them under the row's new
 *   key and a line staged after the move lands beside them;
 * - collapses a chain: moving a row the session already moved (its
 *   staged landing is this `from`) replaces that move with one from the
 *   original key, and a move back onto the original key cancels it
 *   (the lines return, no row remains);
 * - upserts by origin key, so one rule is one row.
 *
 * Save sessions pass through untouched. `from` outside the document's
 * rule keys and `to` outside the grammar are caller bugs (the chrome's
 * gate parses before it commits) and throw.
 *
 * @param {object} session
 *   The open pending session.
 * @param {string} from
 *   The document rule key the rule leaves.
 * @param {string} to
 *   The relative selector it lands at.
 *
 * @return {object}
 *   A fresh session; the input is never mutated.
 */
export const stageMove = (session, from, to) => {
  if (!stagesLines(session)) {
    return session;
  }
  const split = splitDocumentKey(from);
  const selector = split === null ? null : canonicalSelector(split.selector);
  if (selector === null) {
    throw new TypeError(`"${from}" is not a document rule key.`);
  }
  const target = canonicalSelector(to);
  if (target === null) {
    throw new TypeError(`"${to}" is not a relative selector.`);
  }
  const source = documentKey(split.slot, selector);
  const chained = session.moves.find(
    (move) => documentKey(splitDocumentKey(move.from).slot, move.to) === source,
  );
  const origin = chained ? chained.from : source;
  const moves = session.moves.filter((move) => move.from !== origin);
  const lines = renamedLines(
    session.lines,
    planMoveOver(
      session.lines.map((line) => line.relative),
      { from: source, to: target },
    ),
  );
  if (splitDocumentKey(origin).selector === target) {
    return { ...session, moves, lines };
  }
  return {
    ...session,
    moves: [
      ...moves,
      { id: pendingMoveId(origin, target), from: origin, to: target },
    ],
    lines,
  };
};

/**
 * Dismisses one pending row by id: an update session drops the staged
 * line, or drops the staged move and returns the lines it carried to the
 * keys they left (the reverse rename over the session's line keys); a
 * save session excludes the captured row from the commit.
 *
 * @param {object} session
 *   The open pending session.
 * @param {string} id
 *   The row id (a line, move or captured row).
 *
 * @return {object}
 *   A fresh session; the input is never mutated.
 */
export const dismissLine = (session, id) => {
  if (stagesLines(session)) {
    const move = session.moves.find((staged) => staged.id === id);
    if (move) {
      const { slot, selector } = splitDocumentKey(move.from);
      return {
        ...session,
        moves: session.moves.filter((staged) => staged.id !== id),
        lines: renamedLines(
          session.lines,
          planMoveOver(
            session.lines.map((line) => line.relative),
            { from: documentKey(slot, move.to), to: selector },
          ),
        ),
      };
    }
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
 * @param {object} session
 *   The open pending session.
 * @param {string} id
 *   The dismissed row id.
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
 * "anything to prompt about?" gate. A staged move is one row.
 *
 * @param {object} session
 *   The open pending session.
 * @param {?object} capture
 *   A save session's live capture (saveStyles() payload); unused for
 *   update sessions.
 *
 * @return {number}
 *   The pending rows.
 */
export const sessionCount = (session, capture = null) =>
  stagesLines(session)
    ? session.lines.length + session.moves.length
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
 * lists — rides verbatim, so wearers only see the pushed changes. The
 * staged moves apply first (movedStyles: the moved entries whole, their
 * nested references with them, at the new keys and gone from the old),
 * and a line staged at a key a move carries lands where the move put it
 * (its rule key or a stored continuation; staging already renamed the
 * lines, so this is the stale caller's net). A skin session merges the
 * same way over the scope's stored defaults: the document the scope
 * wears after Push (the push bar spells the lines and moves themselves
 * as update_default patches; this merge is what the panel reads as the
 * post-Push truth).
 *
 * @param {object} session
 *   The open pending session.
 * @param {object} context
 *   What the session's kind commits over.
 * @param {?object} [context.capture]
 *   A save session's live capture payload.
 * @param {?object} [context.documentStyles]
 *   An update session's target variant styles (variant.styles) or a
 *   skin session's scope defaults (guide.skinDefaults[scope]), never
 *   mutated.
 *
 * @return {object}
 *   The styles the push commits (a fresh object).
 */
export const sessionStyles = (
  session,
  { capture = null, documentStyles = null } = {},
) => {
  if (session.kind === 'save') {
    return prunedPayload(capture ?? {}, session.dismissed);
  }
  const { styles, steps } = applyMoves(session, documentStyles);
  session.lines.forEach(({ relative: staged, condition, property, value }) => {
    const relative = followed(steps, staged);
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
 * condition) the staged moves and lines touch.
 *
 * update_default is a PATCH against one scope at one key and condition
 * (DaedalusStylishOperationHandler: `properties` values set, null values
 * unset), so the facts group by key and condition, in the order each
 * group is first touched, and every group is one op in one batch: the
 * batch lands atomically, so the pushed document is exactly
 * sessionStyles' merge or nothing. A move spells as the defaults can
 * (they carry no references): for every stored entry it carries, every
 * property nulled at the old (key, condition) and set at the new, in
 * stored order; the lines follow, a line at a landing merging into its
 * group (a staged unset there wins over the moved value, as the merge
 * says).
 *
 * @param {object} session
 *   The skin session.
 * @param {?object} documentStyles
 *   The scope's stored defaults (guide.skinDefaults[scope]), never
 *   mutated: what the moves carry.
 *
 * @return {Array<{op: string, scope: string, key: string, condition:
 *   string, properties: object}>}
 *   One update_default patch per (key, condition) group; [] for any other
 *   session kind.
 */
export const skinPushOperations = (session, documentStyles = null) => {
  if (session?.kind !== 'skin') {
    return [];
  }
  const groups = new Map();
  const patch = (key, condition, property, value) => {
    const id = `${key}|${condition}`;
    if (!groups.has(id)) {
      groups.set(id, {
        op: 'update_default',
        scope: session.scope,
        key,
        condition,
        properties: {},
      });
    }
    groups.get(id).properties[property] = value;
  };
  const stored = documentStyles ?? {};
  const { steps } = applyMoves(session, stored);
  Object.entries(stored).forEach(([key, entry]) => {
    const landing = followed(steps, key);
    if (landing === key || !isEntryObject(entry)) {
      return;
    }
    const conditions = Object.entries(entry).filter(
      ([condition, values]) =>
        condition !== 'variants' && isEntryObject(values),
    );
    conditions.forEach(([condition, values]) => {
      Object.keys(values).forEach((property) =>
        patch(key, condition, property, null),
      );
    });
    conditions.forEach(([condition, values]) => {
      Object.entries(values).forEach(([property, value]) =>
        patch(landing, condition, property, value),
      );
    });
  });
  session.lines.forEach(({ relative, condition, property, value }) => {
    patch(followed(steps, relative), condition, property, value);
  });
  return [...groups.values()];
};
