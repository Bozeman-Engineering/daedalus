import { entryRefs } from './variant-scope.js';

/**
 * The inspector's place state (four controls, one loud mode).
 *
 * The inspector answers four questions with four controls, each shown
 * only where its question exists: the PLACE (styles or materials, the
 * Materials toggle on the mode row, pinned under the subject line and
 * the pose group), the styling MODE (basic / advanced / code, the
 * segments on the same row, a preference over the styling place;
 * ui-mode.js), the code SCOPE (code mode only, the showing row's
 * sentence at the top of the code body; page-view.js) and the POSE
 * (the chip row above the mode row,
 * the styling place only). Exactly one of
 * the row's four buttons is lit: a mode click always lands in the
 * styling place, Materials always in the catalog. This module owns the
 * place half: the vocabulary and the rules decidable without a DOM.
 * Styles is the host-map place (writes are instant ops with the
 * tempstore net) and renders per mode; Materials is the
 * mode-independent catalog.
 * Variant editing is NOT a place: it is a SESSION the user visibly enters
 * through the doorways (wearing-row body, overridden-by badge, the
 * library card's Edit styles, the save icon) and leaves through Push or
 * Discard, the purple-framed mode whose boundary is the commit-cadence
 * boundary (a variant document is pending-until-Push; the host map is
 * instant). `editing`, the edited document's reference or null, is the
 * mode's source-owned half, satellite parity included, alongside
 * `place`; the pending session itself (staged lines, draft capture)
 * stays panel state (pending.js), so a draft never phantoms into the
 * other window as a document.
 *
 * This module owns everything decidable without a DOM: the vocabulary,
 * the selection-move rule (on a selection or target move an editing
 * session survives only when the new styling target's wear key, an
 * element or a rule, wears the edited document; otherwise it exits,
 * prompting when pending work would be left behind), and the
 * session-anchoring reads (an update session's pending lines are
 * variant-relative, so the session survives a wearer-to-wearer move and
 * only its preview anchor travels). Everything is data-in/data-out for
 * node-test backpressure (the line-menu.js pattern).
 */

/**
 * The place vocabulary: the styling place and the materials catalog.
 *
 * The retired values, the deleted pill row's 'element' / 'library', the
 * 'variants' document surface (now the editing session, not a place)
 * and the code-only 'children' / 'page' surfaces, fall outside the
 * vocabulary, so stale stored or broadcast values normalize home to
 * styles like any unknown.
 */
export const PLACES = ['styles', 'materials'];

/**
 * A place normalized to the vocabulary.
 *
 * A value outside it lands on styles, the home place every mode opens
 * with.
 *
 * @param {*} place
 *   The stored place value.
 *
 * @return {string}
 */
export const normalizePlace = (place) =>
  PLACES.includes(place) ? place : 'styles';

/**
 * Whether a wear key's entry wears the edited document.
 *
 * A reference IS the variant's record id (one identity, one string), so
 * wearing is a verbatim match over the entry's references.
 *
 * @param {?object} entry
 *   The wear key's style entry: an element's root entry or a rule's
 *   (references live on element and rule entries, never on parts).
 * @param {?string} reference
 *   The edited document's reference.
 *
 * @return {boolean}
 */
export const wearsDocument = (entry, reference) =>
  reference != null &&
  entryRefs(entry).some((ref) => ref.variant === reference);

/**
 * The selection-move rule.
 *
 * The session is an OVERLAY: the places underneath follow their own
 * rules (the styling place follows the selection by nature, the catalog
 * is selection-free), so `place` passes through untouched in every
 * branch: exiting the mode never moves the place beneath it. With no editing
 * session the move passes through whole; a draft (creating posture)
 * keeps `editing` null while its session holds the panel, so moves pass
 * through here too — the draft's own subtree rule (its holder's
 * business) decides when it leaves. An editing session survives the move
 * (of the selection, or of the styling target on it) when the new wear
 * key wears the edited document — cheap because pending lines are
 * variant-relative, so only the preview re-anchors (reanchorSession).
 * Otherwise the session exits with `editing` cleared; `prompt` says the
 * leave-prompt must fire first (pending work would be left behind —
 * nothing committable discards silently).
 *
 * @param {{place: string, editing: ?string}} state
 *   The current place and the edited document's reference (null = no
 *   editing session mounted).
 * @param {object} context
 * @param {?object} context.entry
 *   The NEW wear key's style entry (the selected element's, or the
 *   rule the target names).
 * @param {number} context.pending
 *   The open session's pending row count (pending.js sessionCount()).
 *
 * @return {{place: string, editing: ?string, prompt: boolean}}
 */
export const selectionMove = (
  { place, editing = null },
  { entry = null, pending = 0 } = {},
) => {
  if (editing === null || wearsDocument(entry, editing)) {
    return { place, editing, prompt: false };
  }
  return { place, editing: null, prompt: pending > 0 };
};

/**
 * Re-anchors an update session on a wearer-to-wearer move.
 *
 * The session's staged lines are variant-relative — nothing in them names
 * the old wearer — so anchoring is ONE field: the wearer whose context
 * previews the document, the styling target's wear key (an element, or
 * a rule wearing the document: a selection move and a target move
 * re-anchor alike). Only update sessions re-anchor (a save session's
 * anchor is its capture root: the capture IS that element's look), and a
 * no-op move returns the same object so subscribers can skip re-rendering.
 *
 * @param {?object} session
 *   The open pending session (pending.js shapes).
 * @param {string} key
 *   The new wearer's key: an element's selectable key or a rule key.
 *
 * @return {?object}
 */
export const reanchorSession = (session, key) =>
  session?.kind === 'update' &&
  typeof key === 'string' &&
  key !== '' &&
  session.anchor !== key
    ? { ...session, anchor: key }
    : session;

/**
 * Whether the open session belongs to the edited document.
 *
 * The one-session-per-document read: an update session holds its
 * reference, the variant's record id, the same string every doorway
 * speaks. Save sessions never match: a draft document's session is panel
 * state with no edited document behind it.
 *
 * @param {?object} session
 *   The open pending session.
 * @param {?string} reference
 *   The edited document's reference.
 *
 * @return {boolean}
 */
export const sessionFocuses = (session, reference) =>
  session?.kind === 'update' &&
  reference != null &&
  session.reference === reference;
