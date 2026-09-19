((Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Reads a cookie's value.
   *
   * @param {string} name
   *   The cookie name.
   *
   * @return {string|null}
   *   The cookie value, or null when no cookie of that name is set. An empty
   *   value reads as null too: the panel cookies use "set but empty" as a
   *   deliberate mark, and the server tells it from "absent"; the client
   *   only ever needs the non-empty case.
   */
  Drupal.Daedalus.getCookieValue = (name) => {
    const match = document.cookie.match(`(^|;)\\s*${name}\\s*=\\s*([^;]+)`);
    return match ? match.pop() : null;
  };

  /**
   * Writes a cookie.
   *
   * The one place a cookie string is built. Every writer in daedalus_ui
   * goes through here so the path convention lives once: site-wide ("/")
   * unless the caller says otherwise, and the caller that does (the mode
   * cookie, scoped to the edited page) says so in both directions, write and
   * delete, so a value can never be written at a path it is not deleted at.
   *
   * @param {string} name
   *   The cookie name.
   * @param {string} value
   *   The cookie value. The empty string is a legitimate value ("set but
   *   empty"), distinct from deleting the cookie.
   * @param {object} options
   *   Cookie attributes.
   * @param {string} options.path
   *   The cookie path. Defaults to "/".
   * @param {number} options.maxAge
   *   Lifetime in seconds. Omitted, the cookie lives for the session.
   */
  Drupal.Daedalus.setCookieValue = (
    name,
    value,
    { path = '/', maxAge } = {},
  ) => {
    let cookie = `${name}=${value}; path=${path}`;
    if (maxAge !== undefined) {
      cookie += `; max-age=${maxAge}`;
    }
    document.cookie = cookie;
  };

  /**
   * Deletes a cookie.
   *
   * Routed through setCookieValue() so the deletion carries exactly the path
   * a write does; a cookie is only removed by a write at its own path.
   *
   * @param {string} name
   *   The cookie name.
   * @param {object} options
   *   Cookie attributes.
   * @param {string} options.path
   *   The cookie path. Defaults to "/", the same default as a write.
   */
  Drupal.Daedalus.deleteCookieValue = (name, { path } = {}) => {
    Drupal.Daedalus.setCookieValue(name, '', { path, maxAge: 0 });
  };
})(Drupal);
