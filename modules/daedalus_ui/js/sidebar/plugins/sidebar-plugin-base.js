(($, Drupal) => {
  /**
   * Sidebar Plugin Base class
   *
   * Base class for sidebar plugins. Each sidebar type should extend this class
   * and implement the lifecycle methods.
   */
  class SidebarPluginBase {
    /**
     * Rejection reason when close() refuses because the panel isn't ready.
     *
     * Part of the close() contract: a plugin with unresolved state (e.g. an
     * invalid form item) rejects with this so the panel stays open and surfaces
     * its own feedback. It is a cross-module sentinel -- callers reject with it
     * and every catch compares against it -- so it lives here, in the one
     * library all of them depend on, rather than as a copy-pasted literal. The
     * reason is intentionally generic: the blocker need not be a form.
     *
     * @type {string}
     */
    static CLOSE_BLOCKED = 'Sidebar close blocked';

    /**
     * Unique identifier for the sidebar plugin type
     * @type {string}
     */
    type = 'base';

    /**
     * Open the sidebar
     *
     * @param {string|null} id
     *   The specific sidebar instance ID to open
     *
     * @return {Promise}
     *   Resolves when the sidebar is opened.
     */
    open(id = null) {
      return Promise.resolve();
    }

    /**
     * Close the sidebar
     *
     * Can reject if the sidebar cannot be closed (e.g., invalid form data).
     *
     * @param {string|null} id
     *   The specific sidebar instance ID to close
     *
     * @return {Promise}
     *   Resolves when the sidebar is closed, rejects if it cannot be closed.
     */
    close(id = null) {
      return Promise.resolve();
    }
  }

  // Make SidebarPluginBase available globally.
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.SidebarPluginBase = SidebarPluginBase;
})(jQuery, Drupal);
