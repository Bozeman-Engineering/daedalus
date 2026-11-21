/**
 * @file
 * The one client token source: Drupal.Daedalus.csrfToken and
 * Drupal.Daedalus.post.
 *
 * Every state-changing Daedalus route takes the session's CSRF token in
 * the X-CSRF-Token header. The token is fetched here, once per document,
 * and every script that writes to a route sends through post(), so the
 * header convention lives in one place. A plain script on the Drupal
 * global rather than a bundled module on purpose: the bundles
 * (daedalus_ui's edit-mode app, daedalus_layout's add panel,
 * daedalus_stylish's inspector and satellite) and the plain scripts
 * (daedalus_ai's chat) share one token, where a module imported into
 * each bundle would fetch its own. A library that writes to a route
 * declares daedalus_ui/csrf as a dependency.
 */

((Drupal) => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  let tokenPromise = null;

  /**
   * The session's CSRF token, fetched once.
   *
   * A failed fetch is not remembered: the caller's request rejects with
   * it and the next caller asks again.
   *
   * @return {Promise<string>}
   *   The token.
   */
  Drupal.Daedalus.csrfToken = () => {
    if (tokenPromise === null) {
      tokenPromise = fetch(Drupal.url('session/token'))
        .then((response) => {
          if (!response.ok) {
            throw new Error(
              Drupal.t('The session token request failed (@status).', {
                '@status': response.status,
              }),
            );
          }
          return response.text();
        })
        .catch((error) => {
          tokenPromise = null;
          throw error;
        });
    }
    return tokenPromise;
  };

  /**
   * Sends a write to a URL with the session's CSRF token in the header.
   *
   * The answer is the caller's to read: the routes answer in several
   * shapes (JSON envelopes, a document, a bare status), so the Response
   * is handed back untouched.
   *
   * @param {string} url
   *   The URL, as Drupal.url() answers it.
   * @param {?(object|FormData)} body
   *   The body: a FormData goes as multipart (the browser names the
   *   content type with its boundary), anything else non-null as JSON,
   *   null as no body.
   * @param {object} options
   *   The request's options.
   * @param {string} options.method
   *   The HTTP method of a write that is not a POST (PATCH, DELETE).
   * @param {boolean} options.keepalive
   *   Whether the request outlives the document (a write sent just before
   *   a navigation).
   *
   * @return {Promise<Response>}
   *   The fetch response.
   */
  Drupal.Daedalus.post = async (
    url,
    body = null,
    { method = 'POST', keepalive = false } = {},
  ) => {
    const token = await Drupal.Daedalus.csrfToken();
    const headers = { 'X-CSRF-Token': token };
    const init = { method, headers };
    if (keepalive) {
      init.keepalive = true;
    }
    if (body instanceof FormData) {
      init.body = body;
    } else if (body !== null) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(url, init);
  };
})(Drupal);
