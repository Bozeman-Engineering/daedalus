import { useEffect, useState } from 'preact/hooks';

/**
 * The optimistic click-gesture state shared by all segmented widgets.
 *
 * Gesture semantics:
 * - A click previews immediately (optimistic paint) and commits in the
 *   same breath — one update_style op per gesture, multi-property writes
 *   (a spacing step's padding + gap) atomic in that one op. The source
 *   releases the staged preview when the op settles, so onRelease has no
 *   caller here: buttons have no cancel path.
 * - pick(properties, companions?) is the compound gesture: companions
 *   ([{key, properties, condition?}]) are changes on OTHER keys (the
 *   position widget's parent display: grid upgrade) that ride onCommit's
 *   batch — one op, one undo label, all or nothing. They preview in the
 *   same breath under their own key, because the optimistic paint must
 *   cover the WHOLE gesture: the child's align-self is visually inert
 *   until the parent is a grid, so the parent upgrade paints mid-gesture,
 *   not after the round trip. The source releases every key's staged
 *   preview when the one batch settles.
 * - A rejected commit drops the optimistic draft (the preview already
 *   reverted, companions included, and the page reported the failure)
 *   and marks the widget invalid until the next authoritative value or
 *   gesture.
 * - pick's third argument names the gesture in the undo history when the
 *   widget's story is richer than the pipeline's "Styled <control>" (the
 *   position widget's compound upgrade names the parent it re-arranged);
 *   omitted, the pipeline labels the op.
 *
 * `values` is the committed model values with the in-flight draft layered
 * over them, so the clicked stop lights before the op round-trip settles.
 * A new authoritative model (the settled commit, an AI edit, a selection
 * move) supersedes the draft. stage(properties) is the paint half alone,
 * for a gesture whose commit comes later (the basic wells' color lane:
 * the picker's input events stage so the well repaints live, its change
 * event picks).
 */
export const useGesture = (model, onPreview, onCommit) => {
  const committed = JSON.stringify([model.key, model.values]);
  const [draft, setDraft] = useState(null);
  const [invalid, setInvalid] = useState(false);
  useEffect(() => {
    setDraft(null);
    setInvalid(false);
  }, [committed]);

  const values = draft ? { ...model.values, ...draft } : model.values;

  // The optimistic paint alone: the draft layers over the values (so the
  // widget's own faces repaint from it) and the canvas previews, with no
  // commit. A pick's first half, and the whole of a live gesture that
  // settles later (the color lane's picker: input events stage, the
  // change event picks).
  const stage = (properties) => {
    setDraft((current) => ({ ...(current ?? {}), ...properties }));
    Object.entries(properties).forEach(([property, value]) =>
      onPreview(property, value),
    );
  };

  const pick = (properties, companions = null, label = null) => {
    stage(properties);
    setInvalid(false);
    // Companion changes paint under their own key; the draft stays the
    // widget's own — model.values never carries another key's properties.
    (companions ?? []).forEach(({ key, properties: companionProperties }) => {
      Object.entries(companionProperties).forEach(([property, value]) =>
        onPreview(property, value, key),
      );
    });
    onCommit(properties, companions ?? undefined, label ?? undefined).then(
      () => setInvalid(false),
      (error) => {
        setDraft(null);
        if (!error?.abandoned) {
          setInvalid(true);
        }
      },
    );
  };

  return { values, invalid, stage, pick };
};

/**
 * The text-field gesture for a field embedded in a basic widget (the
 * pencil reveal's DimensionField): preview per input, one commit on
 * settle, the same policy the advanced rows' PropertyWidget applies,
 * spoken through the widget contract's onPreview/onRelease/onCommit
 * handlers instead of the row pipeline's.
 *
 * `base` is the authoritative text underneath the field: the committed
 * value with any sibling gesture's optimistic draft layered over it
 * (useGesture's `values`), so a chip click repaints an open reveal in the
 * same breath as the chips. The field's own draft echoes previews back
 * into the controlled field; a new base supersedes it (a settled commit,
 * an AI edit, a selection move — or a failed CHIP commit reverting). An
 * unchanged settle releases the staged preview instead of committing; an
 * emptied field commits null (unset). A failed commit keeps the typed
 * value for correction and marks the field invalid until the next
 * authoritative value or gesture.
 */
export const useFieldGesture = (
  base,
  property,
  onPreview,
  onRelease,
  onCommit,
) => {
  const committed = base ?? '';
  const [draft, setDraft] = useState(null);
  const [invalid, setInvalid] = useState(false);
  useEffect(() => {
    setDraft(null);
    setInvalid(false);
  }, [committed]);

  const shown = draft ?? committed;
  const asStyleValue = (text) => (text.trim() === '' ? null : text.trim());

  const preview = (text) => {
    setDraft(text);
    onPreview(property, asStyleValue(text));
  };

  const settle = (text) => {
    if (draft === null && text === committed) {
      return;
    }
    const next = asStyleValue(text);
    if (next === asStyleValue(committed)) {
      // Nothing to persist — release the staged preview and fall back to
      // the committed value.
      onRelease({ [property]: next });
      setDraft(null);
      setInvalid(false);
      return;
    }
    onCommit({ [property]: next }).then(
      () => setInvalid(false),
      (error) => {
        if (error?.abandoned) {
          // Cancelled, not failed (a declined detach, a superseded op):
          // fall back to the committed value instead of marking invalid.
          setDraft(null);
        } else {
          setInvalid(true);
        }
      },
    );
  };

  return { shown, invalid, preview, settle };
};
