/**
 * The gesture endpoints' shared HTTP transport, and the one reader of a
 * gesture failure on either lane.
 *
 * The lifecycle gestures that stay routes (rename, revisions/restore,
 * revert, copy, delete, release/transfer, the usage reads) speak one
 * protocol: JSON in, JSON out, the CSRF header on writes, and failures
 * rejecting with an Error whose `payload` is the server's response
 * contract (`{errors: [{path, code, message, hint}]}`, the shared
 * envelope, GestureApiControllerBase), exactly the shape the op queue's
 * rejections carry (`error.payload.errors`), so `failureText()` phrases a
 * failed route and a failed op alike. Each API module (variants-api.js and
 * the palette's twin) is a thin naming layer over these calls.
 *
 * These calls deliberately do NOT ride the leader's op queue: the gesture
 * entities are site-wide content, not per-user tempstore state, so
 * either host (the in-page panel or the satellite window) talks to the
 * endpoints directly over its own authenticated session. The material
 * WRITES (mint, value, push, save-as) are page-language ops instead
 * (material-gestures.js), the same ops the AI submits.
 */

/**
 * The text a failed gesture shows: the server's message and hint.
 *
 * Reads the op queue's rejection shape (an Error carrying `.payload`, the
 * response contract) and falls back to the Error's own message (a
 * transport failure, a missing page context).
 *
 * @param {?Error} error
 *   The rejection.
 *
 * @return {string}
 */
export const failureText = (error) => {
  const first = error?.payload?.errors?.[0];
  return [first?.message ?? error?.message, first?.hint]
    .filter(Boolean)
    .join(' ');
};

/**
 * Runs one route gesture and reports its failure once, through the
 * host's messenger.
 *
 * The route lane's one reporter per window: every card and area that
 * runs a lifecycle route (rename, restore, copy, revert, release,
 * transfer, delete) hands the work here, so a failure toasts exactly
 * once in the window whose hand made it and nothing else phrases it.
 * The op-queue lane (commit, batch, apply, detach, and the material
 * writes over them) is NOT this transport: its source reports
 * (reportFailure on the leader, the satellite's settle), so a caller
 * wrapping an op never notifies again. The rejection propagates so the
 * caller's own flow (busy state, staying open) settles on it.
 *
 * @param {object} source
 *   The state source; its `notify` is the messenger call.
 * @param {Function} work
 *   Returns the gesture's promise.
 *
 * @return {Promise<*>}
 *   The work's own settlement; rejects with the same Error after the
 *   report.
 */
export const gesture = (source, work) =>
  work().catch((error) => {
    source.notify?.('error', failureText(error));
    throw error;
  });

let tokenPromise = null;
const csrfToken = () => {
  tokenPromise ??= fetch(Drupal.url('session/token')).then((response) =>
    response.text(),
  );
  return tokenPromise;
};

/**
 * Resolves a fetch response as JSON, rejecting on the error envelope.
 */
export const asJson = async (response) => {
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(
      body?.errors?.[0]?.message ??
        Drupal.t('The request failed (@status).', {
          '@status': response.status,
        }),
    );
    error.payload = body;
    throw error;
  }
  return body;
};

/**
 * A read: no CSRF token (GET routes are permission-gated, not token-gated).
 */
export const get = (path) => fetch(Drupal.url(path)).then(asJson);

/**
 * A text read (a route answering text/css): the body as a string,
 * rejecting on the shared JSON error envelope exactly as asJson does.
 */
export const getText = (path) =>
  fetch(Drupal.url(path)).then((response) =>
    response.ok ? response.text() : asJson(response),
  );

/**
 * A write with the CSRF header; payload null sends no body.
 */
export const send = async (method, path, payload = null) => {
  const token = await csrfToken();
  return fetch(Drupal.url(path), {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': token,
    },
    ...(payload === null ? {} : { body: JSON.stringify(payload) }),
  }).then(asJson);
};

export const post = (path, payload) => send('POST', path, payload);

/**
 * Drives a core Batch API job from JS (a delete gesture's 202 contract).
 *
 * Core's own progress page speaks this exact protocol (misc/batch.js):
 * POST the batch URL with op=do — each request processes a slice and
 * answers {status, percentage, message, label} as JSON — until the
 * percentage reads 100, then GET op=finished so the batch record cleans
 * up. No CSRF header: the batch route checks ownership through the
 * session that staged it.
 *
 * @param {string} batchUrl
 *   The op=start URL the staging response carried.
 * @param {?function} onProgress
 *   Called with each slice's {percentage, message, label}.
 *
 * @return {Promise<null>}
 */
export const driveBatch = (batchUrl, onProgress = null) => {
  const url = (op) => batchUrl.replace(/([?&])op=[^&]*/, `$1op=${op}`);
  const step = () =>
    fetch(url('do'), { method: 'POST' })
      .then((response) => response.json())
      .then((data) => {
        if (!data || data.status !== true) {
          throw new Error(Drupal.t('The batch failed.'));
        }
        onProgress?.(data);
        if (parseInt(data.percentage, 10) >= 100) {
          return fetch(url('finished')).then(() => null);
        }
        return step();
      });
  return step();
};
