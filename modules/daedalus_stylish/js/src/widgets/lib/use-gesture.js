import { useEffect, useState } from 'preact/hooks';

/**
 * The optimistic click-gesture state shared by all segmented widgets.
 *
 * Gesture semantics:
 * - A click previews immediately (optimistic paint) and commits in the
 *   same breath — one update_style op per gesture, multi-property writes
 *   (a stroke part's completion to a whole stroke, the motion trigger's
 *   pair, an own shorthand's collapse) atomic in that one op. The source
 *   releases the staged preview when the op settles, so onRelease has no
 *   caller here: buttons have no cancel path.
 * - A pick writes only where its rows read: every property in it rides
 *   the widget's own keys (the pipeline routes the pick's set values as
 *   a group to one box and each removal to the box it lives on, several
 *   boxes as one batch; basic-controls.js route), never another
 *   element's. The pipeline labels the op ("Styled <control>").
 * - A rejected commit drops the optimistic draft (the preview already
 *   reverted and the page reported the failure) and marks the widget
 *   invalid until the next authoritative value or gesture.
 *
 * `values` is the widget's shown values (`base`: each owned property's
 * winner at the pose, controls.js litValues over the model's reads, or
 * a row's own value) with the in-flight draft layered over them, so the
 * clicked stop lights before the op round-trip settles. A new
 * authoritative base (the settled commit, an AI edit, a selection move)
 * supersedes the draft. stage(properties) is the paint half alone, for
 * a gesture whose commit comes later (the basic wells' color lane: the
 * picker's input events stage so the well repaints live, its change
 * event picks).
 *
 * @param {string} key
 *   The gesture's target (the bound key; a row's property when the row
 *   is its own gesture), the draft's identity beside the base.
 * @param {Object<string, ?string>} base
 *   The shown values the draft layers over.
 */
export const useGesture = (key, base, onPreview, onCommit) => {
  const committed = JSON.stringify([key, base]);
  const [draft, setDraft] = useState(null);
  const [invalid, setInvalid] = useState(false);
  useEffect(() => {
    setDraft(null);
    setInvalid(false);
  }, [committed]);

  const values = draft ? { ...base, ...draft } : base;

  // The optimistic paint alone: the draft layers over the values (so the
  // widget's own faces repaint from it) and the canvas previews, with no
  // commit. A pick's first half, and the whole of a live gesture that
  // settles later (the color lane's picker: input events stage, the
  // change event picks).
  const stage = (properties) => {
    setDraft((current) => ({ ...(current ?? {}), ...properties }));
    onPreview(properties);
  };

  const pick = (properties) => {
    stage(properties);
    setInvalid(false);
    onCommit(properties).then(
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
 * padding row's shorthand DimensionField, the stroke row's width field,
 * the box-model diagram's side editor): preview per input, one commit
 * on settle, the same policy the advanced rows' PropertyWidget applies,
 * spoken through the widget contract's OBJECT handlers
 * (onPreview(properties), onRelease(properties), onCommit(properties))
 * instead of the row pipeline's pair.
 *
 * `base` is the authoritative text underneath the field: the shown
 * value (the winner's at the pose) with any sibling gesture's optimistic
 * draft layered over it (useGesture's `values`), so a sibling's pick (a
 * stroke's style or colour) repaints the field in the same breath. The field's
 * own draft echoes previews back into the controlled field; a new base
 * supersedes it (a settled commit, an AI edit, a selection move — or a
 * failed CHIP commit reverting). `own` is what the editable layer says
 * at the EXACT pose (null when it says nothing there): a settle that
 * lands on it has nothing to persist and releases the staged preview
 * instead of committing, and an emptied field commits null (unset)
 * only while it says something, because a null write over a value a
 * lower layer delivers would remove nothing. Callers whose base IS the
 * own value (a row over the own entry alone) leave `own` at its default.
 * A failed commit keeps the typed value for correction and marks the
 * field invalid until the next authoritative value or gesture.
 */
export const useFieldGesture = (
  base,
  property,
  onPreview,
  onRelease,
  onCommit,
  own = base,
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
    onPreview({ [property]: asStyleValue(text) });
  };

  const settle = (text) => {
    if (draft === null && text === committed) {
      return;
    }
    const next = asStyleValue(text);
    if (next === asStyleValue(own ?? '')) {
      // Nothing to persist (the editable layer already says this at the
      // pose, or says nothing and the field was emptied) — release the
      // staged preview and fall back to the shown value.
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
