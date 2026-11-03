import { h, Fragment } from 'preact';
import { useRef, useState } from 'preact/hooks';
import { gesture } from './gesture-transport.js';
import { uploadFontFile } from './skin-api.js';
import { duplicateFace, faceDraft, faceLabel } from './skin-model.js';

/**
 * The specimen line every face and card shows: a pangram in the family,
 * at the face's own weight and style, so the tab shows what a stack
 * naming the family renders as (the delivered @font-face, the compiled
 * file's or the session's delivery, is what the browser draws with).
 */
export const SPECIMEN = 'The quick brown fox jumps over the lazy dog 0123456789';

/**
 * The faces list: one row per face, its label and its specimen. The
 * font card's closed value slot.
 */
export const FaceList = ({ faces, spelling }) => (
  <ul className="daedalus-stylish-faces">
    {faces.map((face) => (
      <li
        key={`${face.weight}/${face.style}`}
        className="daedalus-stylish-faces__face"
      >
        <span className="daedalus-ui-chip daedalus-stylish-faces__face-label">
          {faceLabel(face)}
        </span>
        <span
          className="daedalus-stylish-faces__specimen"
          style={{
            fontFamily: spelling ?? undefined,
            fontWeight: face.weight,
            fontStyle: face.style,
          }}
        >
          {SPECIMEN}
        </span>
      </li>
    ))}
  </ul>
);

/**
 * The faces editor: files in, face drafts out.
 *
 * Files first: each woff2 or woff picked uploads through the bytes door
 * (skin-api.js uploadFontFile, the daedalus_ui file lane narrowed to the
 * font extensions) and lands as a face draft carrying the uuid the
 * upload answered, with the weight and style the file's name suggests
 * (skin-model.js guessFace) open to correction; a stored face arrives as
 * a draft already (storedFaceDraft) and keeps its file. A draft repeating
 * another's weight and style is flagged (the validator's
 * DUPLICATE_FONT_FACE rule; the caller refuses the save while
 * `duplicateFace` finds one). Pure drafts-in/drafts-out: the caller (the
 * font card's slot or the mint card) owns the drafts and the save.
 *
 * @param {object} props
 * @param {Array<object>} props.drafts
 *   The face drafts (skin-model.js faceDraft / storedFaceDraft shape).
 * @param {Function} props.onDrafts
 *   An updater over the caller's drafts: (current) => next.
 * @param {Function} props.onUploading
 *   Hears the number of uploads in flight, so the caller withholds its
 *   save while bytes are still landing.
 */
export const FacesEditor = ({ drafts, onDrafts, onUploading, source, disabled = false }) => {
  const nextKey = useRef(1 + drafts.reduce((max, draft) => Math.max(max, draft.key), 0));
  const [uploading, setUploading] = useState(0);
  const duplicate = duplicateFace(drafts);

  const count = (delta) =>
    setUploading((current) => {
      const next = current + delta;
      onUploading?.(next);
      return next;
    });

  const pick = (event) => {
    const files = Array.from(event.target.files ?? []);
    // The input resets so the same file can be picked again after a
    // removal.
    event.target.value = '';
    files.forEach((file) => {
      count(1);
      gesture(source, () => uploadFontFile(file))
        .then((upload) => {
          const key = nextKey.current++;
          onDrafts((current) => [...current, faceDraft(upload, key)]);
        })
        .catch(() => null)
        .finally(() => count(-1));
    });
  };

  const change = (key, patch) =>
    onDrafts((current) =>
      current.map((draft) => (draft.key === key ? { ...draft, ...patch } : draft)),
    );
  const remove = (key) =>
    onDrafts((current) => current.filter((draft) => draft.key !== key));

  return (
    <Fragment>
      <label className="daedalus-stylish-faces-editor__files">
        <span>{Drupal.t('Font files (woff2, woff)')}</span>
        <input
          type="file"
          className="daedalus-stylish-faces-editor__input"
          accept=".woff2,.woff,font/woff2,font/woff"
          multiple
          aria-label={Drupal.t('Font files')}
          disabled={disabled}
          onChange={pick}
        />
      </label>
      {uploading > 0 && (
        <div className="daedalus-stylish-library-card__progress">
          {Drupal.formatPlural(uploading, 'Uploading 1 file…', 'Uploading @count files…')}
        </div>
      )}
      {drafts.length > 0 && (
        <ul className="daedalus-stylish-faces-editor__drafts">
          {drafts.map((draft, index) => (
            <li
              key={draft.key}
              className={`daedalus-stylish-faces-editor__draft${index === duplicate ? ' daedalus-stylish-faces-editor__draft--duplicate' : ''}`}
            >
              <span
                className="daedalus-stylish-faces-editor__file"
                title={draft.name}
              >
                {draft.name}
              </span>
              <input
                type="text"
                className="daedalus-ui-input daedalus-stylish-faces-editor__weight"
                aria-label={Drupal.t('Face weight')}
                title={Drupal.t('The weight: 100 to 900, a keyword, or a range like "100 900" for a variable font.')}
                value={draft.weight}
                disabled={disabled}
                onInput={(event) => change(draft.key, { weight: event.target.value })}
              />
              <select
                className="daedalus-ui-input daedalus-stylish-faces-editor__style"
                aria-label={Drupal.t('Face style')}
                value={draft.style}
                disabled={disabled}
                onChange={(event) => change(draft.key, { style: event.target.value })}
              >
                <option value="normal">{Drupal.t('normal')}</option>
                <option value="italic">{Drupal.t('italic')}</option>
              </select>
              <button
                type="button"
                className="daedalus-ui-chip daedalus-stylish-faces-editor__remove"
                title={Drupal.t('Drop this face.')}
                disabled={disabled}
                onClick={() => remove(draft.key)}
              >
                {Drupal.t('remove')}
              </button>
            </li>
          ))}
        </ul>
      )}
    </Fragment>
  );
};

/**
 * Why the faces cannot save right now, or null when they can.
 *
 * @param {Array<object>} drafts
 * @param {number} uploading
 *
 * @return {?string}
 */
export const facesBlocked = (drafts, uploading) => {
  if (uploading > 0) {
    return Drupal.t('Wait for the upload to land.');
  }
  if (drafts.length === 0) {
    return Drupal.t('Add at least one font file.');
  }
  if (duplicateFace(drafts) !== -1) {
    return Drupal.t('Two faces share a weight and style; give each face its own.');
  }
  return null;
};
