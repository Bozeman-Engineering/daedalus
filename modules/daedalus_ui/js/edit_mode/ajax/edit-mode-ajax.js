/**
 * @file
 * Ensures all Drupal AJAX requests include the navigationMode parameter when in Edit Mode.
 */

/**
 * Global AJAX preprocessor to add navigationMode to all AJAX requests.
 *
 * ## The Problem
 *
 * The navigationMode cookie is path-scoped to the current page (e.g., `path=/node/123`).
 * This means when AJAX requests load content from different paths (like modals, dialogs,
 * or forms from different routes), the cookie is NOT sent by the browser because the
 * request path doesn't match the cookie's path restriction.
 *
 * ## The Solution
 *
 * This global AJAX preprocessor wraps Drupal.Ajax.prototype.beforeSend to automatically
 * add the navigationMode as a query parameter to ALL AJAX requests when in Edit Mode.
 *
 * The backend ModeState::getMode() checks the query parameter BEFORE the cookie:
 *   return $request->query->get('navigationMode') ?? $request->cookies->get('navigationMode') ?? 'none';
 *
 * So even when the cookie isn't sent, the query parameter ensures the backend knows
 * we're in Edit Mode.
 */
Drupal.Ajax.prototype.beforeSend = (function (originalBeforeSend) {
  return function (xmlhttprequest, options) {
    const mode = Drupal.Daedalus.getCookieValue('navigationMode');
    if (mode && mode !== 'none') {
      // Add navigationMode to URL if not already present.
      // This prevents duplicate parameters if the URL was already processed
      // by ModePluginBase.url() or similar.
      if (!options.url.includes('navigationMode=')) {
        const separator = options.url.includes('?') ? '&' : '?';
        options.url += `${separator}navigationMode=${encodeURIComponent(mode)}`;
      }
    }

    // Call the original beforeSend to preserve existing AJAX behavior.
    if (originalBeforeSend) {
      return originalBeforeSend.call(this, xmlhttprequest, options);
    }
  };
})(Drupal.Ajax.prototype.beforeSend);
