/**
 * @file
 * Shared file upload utility for the desktop-file-drag feature.
 *
 * Posts a single dragged file to one of the daedalus_ui upload routes and
 * resolves with the parsed Drupal AJAX command response. This replaces the
 * (now removed) DropzoneJs transport: it owns the browser -> saved-file leg of
 * the pipeline directly.
 *
 * A promise-wrapped XMLHttpRequest is used deliberately instead of fetch():
 * fetch cannot report request-body (upload) progress, and a progress bar is a
 * first-class requirement for this feature (media uploads are often large
 * videos/images). The progress UI itself is a separate slice; this utility only
 * exposes the `onProgress` hook so that UI can be wired in later.
 */

/**
 * Default upload timeout in milliseconds.
 *
 * Mirrors ReplaceMediaFieldAttributes::DEFAULT_UPLOAD_TIMEOUT_MS (5 minutes) on
 * the server. Replace-media call sites should prefer the per-field
 * `data-timeout` attribute when present; new-media has no per-field source so it
 * falls back to this default.
 *
 * @type {number}
 */
export const DEFAULT_UPLOAD_TIMEOUT_MS = 300000;

/**
 * Uploads a single file via a promise-wrapped XMLHttpRequest.
 *
 * @param {string} url
 *   The route to POST the file to.
 * @param {File} file
 *   The file (e.g. from a native drop's dataTransfer.files) to upload. It is
 *   appended to the request under the `file` form field.
 * @param {object} [options]
 *   Optional settings.
 * @param {number} [options.timeout]
 *   Request timeout in milliseconds. Defaults to DEFAULT_UPLOAD_TIMEOUT_MS.
 *   A non-numeric or falsy value falls back to the default. Pass 0 to disable
 *   the timeout entirely (XHR's native behavior).
 * @param {function(ProgressEvent): void} [options.onProgress]
 *   Optional callback wired to xhr.upload.onprogress. Receives the native
 *   ProgressEvent (use `event.lengthComputable`, `event.loaded`,
 *   `event.total`). The progress UI is a later slice; this is just the hook.
 *
 * @return {Promise<object>}
 *   Resolves with the parsed JSON response (the Drupal AJAX command array).
 *   Rejects with an Error on HTTP error status, network error, timeout, or an
 *   unparseable response body.
 */
export const uploadFile = (url, file, options = {}) => {
  const timeout =
    typeof options.timeout === 'number' && !Number.isNaN(options.timeout)
      ? options.timeout
      : DEFAULT_UPLOAD_TIMEOUT_MS;

  return new Promise((resolve, reject) => {
    const formData = new FormData();
    formData.append('file', file);

    // Ensure Drupal knows about the page state so the AJAX response can attach
    // the right libraries/settings. Mirrors the params pattern the DropzoneJs
    // call sites used.
    if (drupalSettings && drupalSettings.ajaxPageState) {
      Object.entries(drupalSettings.ajaxPageState).forEach(([key, value]) => {
        formData.append(`ajax_page_state[${key}]`, value);
      });
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.timeout = timeout;

    if (typeof options.onProgress === 'function') {
      xhr.upload.onprogress = options.onProgress;
    }

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        let response;
        try {
          response = JSON.parse(xhr.responseText);
        } catch (error) {
          reject(
            new Error(`Unable to parse upload response: ${error.message}`),
          );
          return;
        }
        resolve(response);
      } else {
        reject(
          new Error(
            `Server responded with ${xhr.status} ${xhr.statusText}`.trim(),
          ),
        );
      }
    };

    xhr.onerror = () => {
      reject(new Error('A network error occurred during the upload.'));
    };

    xhr.ontimeout = () => {
      reject(new Error('The upload timed out.'));
    };

    xhr.send(formData);
  });
};
