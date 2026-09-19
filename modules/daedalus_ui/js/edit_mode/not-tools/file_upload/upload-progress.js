/**
 * @file
 * Shared upload progress UI for the desktop-file-drag feature.
 *
 * Both upload call sites (new-media and replace-media) share one progress
 * element: a fixed overlay with a percentage label and a fill bar wired to the
 * uploader's `onProgress` hook (xhr.upload.onprogress).
 *
 * CRITICAL nuance: the upload reaching 100% only means the request body has
 * been SENT to the server. After that there is still server-side media creation
 * and the AJAX layout rebuild before the UI is replaced. So the bar must NOT
 * sit at "100%" looking stuck — when bytes finish uploading it flips to a
 * "Processing…" terminal state until the AJAX success/error path removes it.
 *
 * The reason the uploader uses XHR over fetch in the first place is precisely to
 * get request-body progress; this is where that pays off.
 */

/**
 * Creates an upload progress UI element and attaches it to the document.
 *
 * @return {{onProgress: function(ProgressEvent): void, processing: function(): void, remove: function(): void}}
 *   A controller object:
 *   - `onProgress(event)` — pass straight to uploadFile's `options.onProgress`.
 *     Updates the bar/percentage from the native ProgressEvent. When bytes
 *     finish (loaded >= total) it automatically flips to the processing state.
 *   - `processing()` — force the "Processing…" terminal state (server work in
 *     progress, upload bytes already sent). Idempotent.
 *   - `remove()` — detach the element. Safe to call multiple times.
 */
export const createUploadProgress = () => {
  const overlay = document.createElement('div');
  overlay.className = 'daedalus-ui-upload-progress';

  const label = document.createElement('div');
  label.className = 'daedalus-ui-upload-progress__label';
  label.textContent = Drupal.t('Uploading…');

  const track = document.createElement('div');
  track.className = 'daedalus-ui-upload-progress__track';

  const fill = document.createElement('div');
  fill.className = 'daedalus-ui-upload-progress__fill';
  fill.style.width = '0%';

  track.appendChild(fill);
  overlay.appendChild(label);
  overlay.appendChild(track);
  document.body.appendChild(overlay);

  let processingState = false;
  let removed = false;

  const processing = () => {
    if (processingState || removed) {
      return;
    }
    processingState = true;
    overlay.classList.add('daedalus-ui-upload-progress--processing');
    fill.style.width = '100%';
    label.textContent = Drupal.t('Processing…');
  };

  const onProgress = (event) => {
    if (removed || processingState) {
      return;
    }
    if (!event || !event.lengthComputable || !event.total) {
      return;
    }
    const percent = Math.min(
      100,
      Math.round((event.loaded / event.total) * 100),
    );
    fill.style.width = `${percent}%`;
    label.textContent = Drupal.t('Uploading… @percent%', {
      '@percent': percent,
    });

    // Bytes are fully sent — flip to the processing state so the bar does not
    // sit at 100% looking stuck while the server does its work.
    if (event.loaded >= event.total) {
      processing();
    }
  };

  const remove = () => {
    if (removed) {
      return;
    }
    removed = true;
    overlay.remove();
  };

  return { onProgress, processing, remove };
};
