/**
 * Screen-reader announcements for app gestures.
 *
 * Core's drupal.announce owns the live region and its debouncing; the app
 * library declares it as a dependency. The guard keeps announcement-free
 * environments (a page without the library, unit contexts) from throwing:
 * an announcement is never load-bearing.
 *
 * @param {string} text
 *   The message.
 * @param {string} priority
 *   'polite' (default) or 'assertive'.
 */
export const announce = (text, priority = 'polite') => {
  Drupal.announce?.(text, priority);
};
