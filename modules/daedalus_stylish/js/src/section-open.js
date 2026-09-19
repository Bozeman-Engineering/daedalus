/**
 * The open state of the inspector's property-group sections.
 *
 * Each group renders as a native <details> whose `open` prop is
 * controlled: Preact pushes it to the DOM whenever it CHANGES between
 * renders. The panel is passive and follows selection, so the arrival
 * state is computed ("sections with values open, empty ones collapsed",
 * the auto signals). Within one selection the user's own toggles are the
 * stronger signal: a group opened by hand must not snap shut when its
 * last value is deleted, and a group closed by hand stays closed when a
 * value lands. So the rule is `mark ?? auto`, with the marks folded INTO
 * the prop so it mirrors the DOM at all times and the controlled push is
 * always correct, including the reset when the selection moves on.
 *
 * Marks live under an anchor, the styling target (`key` or `key::part`):
 * marks recorded under another anchor are stale and read as no marks, so
 * a selection change yields the auto state again with no effect and no
 * extra render. The editing session (variant document) is entered from
 * the same anchor, so the user's opens survive park/resume.
 *
 * Pure: data in, data out. The click-to-mark and prop-push wiring lives
 * in the inspector's Sections component.
 */

/**
 * The arrival state of a section: the three auto signals.
 *
 * Open when the section holds committed values, when one of its visible
 * rows carries a pending line (a pending removal still needs its dismiss
 * surface), or, outside the editing session, when a visible row wears a
 * defaults-tier value (the active skin's, or the element's declaration's
 * own beneath it). `rows` is the section's VISIBLE rows (after the
 * hidden-row filter); the signals are read through the callers'
 * row-keyed lookups.
 */
export const autoOpen = (
  section,
  rows,
  { pendingFor, defaultsValueFor, sheet = null },
) =>
  Boolean(
    section.hasValues ||
    rows.some((row) => pendingFor(row) !== null) ||
    (!sheet && rows.some((row) => defaultsValueFor(row) !== null)),
  );

/**
 * The marks that apply at `anchor`: the state's marks only while the
 * state was recorded under that same anchor, else none.
 */
export const marksFor = (state, anchor) =>
  state !== null && typeof state === 'object' && state.anchor === anchor
    ? (state.marks ?? {})
    : {};

/**
 * The open state of one section: the user's mark for it when there is
 * one, the auto state otherwise.
 */
export const sectionOpen = (auto, marks, id) => marks[id] ?? auto;

/**
 * Records the user's word for one section under `anchor`, dropping any
 * marks recorded under a stale anchor.
 */
export const markSection = (state, anchor, id, open) => ({
  anchor,
  marks: { ...marksFor(state, anchor), [id]: Boolean(open) },
});
