/**
 * @file
 * The peek's request: a row's drop, dry run, answered as a bare document.
 *
 * The Add panel's peek shows, beside the panel, what dropping a row at
 * the end of the page would give: the row's own drop batch (the one the
 * insert session composes for click-to-place, model/drop.js) posted to
 * daedalus_blueprint_api's peek door, which applies it to the staged
 * working copy in memory, persists nothing and answers a document holding
 * the host's own style tag and the placed element alone, in the skin and
 * with none of the page around it (daedalus_ui's bare chromeless kind).
 * The document goes into a frame as srcdoc (components/peek-frame.js).
 *
 * navigationMode rides as a query parameter on every daedalus_blueprint_api
 * request: the cookie is path-scoped to the edited page. The peek renders
 * the placed element in the mode's posture, so without it the door would
 * render as a visitor and drop every block the editing canvas shows with
 * a body (an empty build, a block forbidden to visitors).
 *
 * The POST carries the session's CSRF token as the mutation door does; a
 * refusal (a batch the spine refuses is 422 in the mutation contract's
 * error shape; the request-shape 400s likewise) rejects with the
 * gestures' refusal Error, whose message is shown in the frame's place.
 * A fired request always lands: aborting the fetch would not stop the
 * server's render, so the answer is let arrive and the panel keeps it by
 * row for the page load (model/peek-cache.js), dropping what it holds
 * on this session's own writes. This module asks and answers; what is
 * held, and for how long, is the panel's.
 */

import { csrfToken, refusalError } from './blueprint-gestures.js';

/**
 * The peek door's URL for a canvas, carrying the mode.
 *
 * @param {{entityType: string, entityId: string}} context
 *   The canvas's root entity (Blueprint.rootContext()).
 * @param {string} mode
 *   The effective navigation mode (the cookie's value, or 'none').
 *
 * @return {string}
 */
export const peekUrl = (context, mode) =>
  Drupal.url(
    `daedalus-blueprint/${context.entityType}/${context.entityId}/peek?navigationMode=${mode}`,
  );

/**
 * Posts a peek and answers its document.
 *
 * @param {{entityType: string, entityId: string, viewMode: string}} context
 *   The canvas's root entity and the view mode it displays (the peek
 *   renders the same).
 * @param {{operations: object[], show: string}} request
 *   The drop's batch and the key of what it places.
 *
 * @return {Promise<string>}
 *   The document, for a frame's srcdoc.
 */
export const requestPeek = async (context, request) => {
  const token = await csrfToken();
  const mode = Drupal.Daedalus.getCookieValue('navigationMode') ?? 'none';
  const response = await fetch(peekUrl(context, mode), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': token,
    },
    body: JSON.stringify({
      operations: request.operations,
      show: request.show,
      view_mode: context.viewMode,
    }),
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw refusalError(body, response.status);
  }
  return response.text();
};
