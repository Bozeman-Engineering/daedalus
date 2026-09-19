import { axisLabel, findVariant, orderedEntryRefs } from './variant-scope.js';
import { stencilLabel } from './library-model.js';
import { sessionCount } from './pending.js';

/**
 * The editing session frame's pure model (four controls, one loud mode).
 *
 * The SessionFrame is the mode's chrome: the purple frame around the
 * panel body, its header (back/park arrow, posture label, document chip
 * + switcher dropdown, blast line), and the parked session's resume
 * chip. The frame's BODY is the existing variant-document machinery and
 * its commit surface is the Push/Discard bar — neither is modeled here.
 * This module owns what the chrome can decide without a DOM: which
 * posture the frame is in, the park/resume transitions over the mode's
 * split state (`editing` is source-owned synced state, the pending
 * session and the parked flag are panel state — pending.js's session
 * shapes), the header's document identity, the mint-time ownership
 * consent, the switcher's roster, and the resume chip's facts.
 * Everything is data-in/data-out for node-test
 * backpressure (the line-menu.js pattern); the view renders, translates
 * (Drupal.t and the "New variant" fallback for an unnamed draft are its
 * business), and dispatches.
 */

/**
 * The frame's posture for one render.
 *
 * 'editing' mounts the frame over the variant document (update session),
 * 'creating' over the draft document (save session), 'parked' renders
 * the resume chip instead of the frame, and null renders plain places —
 * no session, or an update session whose `editing` echo is in flight
 * (source round-trip): nothing mounts rather than an anchorless frame.
 *
 * @param {?object} session
 *   The open pending session (pending.js shapes), or null.
 * @param {?string} editing
 *   The edited document's reference (the synced half), or null.
 * @param {boolean} parked
 *   The panel's parked flag.
 *
 * @return {?string}
 */
export const framePosture = (session, editing, parked = false) => {
  if (!session) {
    return null;
  }
  if (parked) {
    return 'parked';
  }
  if (session.kind === 'save') {
    return 'creating';
  }
  return editing !== null ? 'editing' : null;
};

/**
 * Parking the session (the header's back arrow).
 *
 * Parking clears the synced `editing` field — the mode dismounts in
 * every window, the pills return — while the session itself stays panel
 * state, held behind the parked flag. Nothing is asked: park is the
 * quiet exit (the tweak-an-element-then-resume workflow); the prompt
 * belongs to the REAL leave events (selectionMove, subtree exit,
 * displacement).
 *
 * @return {{editing: null, parked: true}}
 */
export const parkTransition = () => ({ editing: null, parked: true });

/**
 * Resuming a parked session (the chip's Resume).
 *
 * The mode remounts exactly where it parked: an update session restores
 * `editing` from its own reference (the session IS the document's
 * pending store — the reference never left it), a draft restores with
 * `editing` still null (a draft has no document reference until Push
 * mints one; its frame mounts off the session itself).
 *
 * @param {?object} session
 *   The parked session.
 *
 * @return {{editing: ?string, parked: false}}
 */
export const resumeTransition = (session) => ({
  editing: session?.kind === 'update' ? session.reference : null,
  parked: false,
});

/**
 * The session header's document identity.
 *
 * One read for the posture label and the document chip: an update
 * session resolves its variant through the store's records (label and
 * axis degrade honestly to the verbatim reference and the built-in 'style'
 * axis when the records cannot resolve it — a session must still render
 * its exits); a draft speaks its own panel-held name and axis pick. The
 * axis label follows the one axis-label rule (variant-scope.js
 * axisLabel) so the blast line's "@axis variant" agrees with the wear
 * bar and the library chips.
 *
 * @param {?object} session
 *   The open pending session.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?object} guide
 *   The style guide (axis labels).
 *
 * @return {?{posture: string, label: string, axis: string,
 *   axisLabel: string}}
 */
export const sessionHeader = (session, variants, guide = null) => {
  if (!session) {
    return null;
  }
  if (session.kind === 'save') {
    return {
      posture: 'creating',
      label: session.label.trim(),
      axis: session.axis,
      axisLabel: axisLabel(guide, session.axis),
    };
  }
  const variant = findVariant(variants, session.reference);
  const axis = variant?.group ?? 'style';
  return {
    posture: 'editing',
    label: variant?.label ?? session.reference,
    axis,
    axisLabel: axisLabel(guide, axis),
  };
};

/**
 * The creating posture's mint-time ownership consent, as facts.
 *
 * A variant minted inside a stencil placement is born as that stencil's
 * own switchable look (silently, before this chip existed: the #4
 * surprise). This is the ONE read behind both consumers of that fact:
 * the session header renders it as the "belongs to <stencil>" chip
 * (unlink = the opt-out gesture, `linked: false` on the session), and
 * the push bar sends `stencil` iff this answers non-null, so what the
 * chip says IS what Save commits, by construction. Null means no chip
 * and no owner: not a draft, the user unlinked, or the anchor has no
 * placement provenance (the null-provenance direction stays chipless on
 * purpose: there is nothing to consent to, and adopt-later is the
 * Materials surface's Assign gesture). The label resolves through the
 * store's stencil record (library-model.js stencilLabel), the id when the
 * roster holds no such stencil; both windows read the same roster.
 *
 * @param {?object} session
 *   The open pending session (pending.js shapes).
 * @param {?string} provenance
 *   The anchor's placement provenance (variant-scope.js
 *   stencilProvenance(session.anchor, layout), derived live by the
 *   caller; a layout fact, never session state).
 * @param {object} materials
 *   The materials roster.
 *
 * @return {?{stencil: string, label: string}}
 */
export const mintConsent = (session, provenance, materials) => {
  if (
    session?.kind !== 'save' ||
    session.linked === false ||
    typeof provenance !== 'string' ||
    provenance === ''
  ) {
    return null;
  }
  return { stencil: provenance, label: stencilLabel(provenance, materials) };
};

/**
 * The document switcher's roster: the selection's worn documents.
 *
 * The header chip's dropdown lists every DOCUMENT the selection wears —
 * the designer working two axes hops without exit-and-rejump (switching
 * still resolves the open session through the ordinary prompt first;
 * the displacement queue's business, not this read's). One row per
 * resolved variant in delivery order (orderedEntryRefs): a root and a
 * fragment wearing of the same variant are ONE document, so they
 * collapse to the first-delivered reference; references the records
 * cannot resolve are no documents at all and drop. The edited document
 * stays in the roster marked `current`; the view decides whether it
 * lists or only checks it.
 *
 * @param {?object} entry
 *   The selection's ROOT style entry (references live on base entries).
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?string} editing
 *   The edited document's reference, or null.
 * @param {?string[]} axes
 *   The scope's axis vocabulary, for delivery order.
 *
 * @return {Array<{reference: string, fragment: string, label: string,
 *   axis: string, current: boolean}>}
 */
export const switcherRoster = (
  entry,
  variants,
  editing = null,
  axes = null,
) => {
  const seen = new Set();
  return orderedEntryRefs(entry, variants, axes)
    .map((ref) => ({ ref, variant: findVariant(variants, ref.variant) }))
    .filter(({ variant }) => {
      if (!variant || seen.has(variant.id)) {
        return false;
      }
      seen.add(variant.id);
      return true;
    })
    .map(({ ref, variant }) => ({
      reference: ref.variant,
      fragment: ref.fragment,
      label: variant.label ?? variant.id,
      axis: variant.group ?? 'style',
      current: editing !== null && editing === ref.variant,
    }));
};

/**
 * The parked session's resume chip, as facts.
 *
 * Rendered on BOTH places ("✎ CTA · 3 staged changes · Resume /
 * Discard"): the label resolves like the header (an unnamed draft
 * returns '' and the view supplies its translated fallback), the count
 * is the session's loud number (pending.js sessionCount — a save
 * session's caller passes the live capture, derived fresh so the chip
 * says what Push would commit right now).
 *
 * @param {?object} session
 *   The parked session.
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?object} capture
 *   A save session's live capture payload (saveStyles()).
 *
 * @return {?{kind: string, label: string, count: number}}
 */
export const resumeChip = (session, variants, capture = null) => {
  if (!session) {
    return null;
  }
  return {
    kind: session.kind,
    label:
      session.kind === 'update'
        ? (findVariant(variants, session.reference)?.label ?? session.reference)
        : session.label.trim(),
    count: sessionCount(session, capture),
  };
};
