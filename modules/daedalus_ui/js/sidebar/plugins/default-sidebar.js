(($, Drupal) => {
  const SidebarPluginBase = Drupal.Daedalus.SidebarPluginBase;

  /**
   * Default Sidebar Plugin
   *
   * Base class for simple sidebars that may have multiple instances.
   * Provides common functionality for opening, closing, and managing
   * sidebar elements with cookie support and button state management.
   */
  class DefaultSidebar extends SidebarPluginBase {
    type = 'default';

    /**
     * Get a specific sidebar element by ID.
     *
     * The DOM is the source of truth for sidebar presence and state, so this
     * resolves the element by id directly rather than from a separate registry.
     *
     * @param {string|null} id
     *   The unique sidebar ID (its DOM id).
     * @return {Element|null}
     */
    getElement(id = null) {
      return id ? document.getElementById(id) : null;
    }

    /**
     * Show a sidebar element
     *
     * Helper method that handles common DOM operations for showing a sidebar.
     * Does NOT handle button state - that's for simple default sidebars only.
     *
     * @param {Element} element
     *   The sidebar element to show.
     */
    showSidebarElement(element) {
      if (!element) {
        return;
      }

      element.classList.remove('daedalus-ui-hidden');
      const side = element.classList.contains('left-sidebar')
        ? 'left'
        : 'right';
      const slot = Drupal.Daedalus.SidebarManager.slotOf(element);
      element.setAttribute(`data-offset-${side}`, '');

      // Record this panel as the one open in its (side, slot), for server-side
      // rendering. One cookie per (side, slot), valued with the open panel's id.
      Drupal.Daedalus.setCookieValue(
        `${side}_${slot}_sidebar`,
        element.id,
      );

      Drupal.displace();
    }

    /**
     * Hide a sidebar element
     *
     * Helper method that handles common DOM operations for hiding a sidebar.
     * Does NOT handle button state - that's for simple default sidebars only.
     *
     * @param {Element} element
     *   The sidebar element to hide.
     */
    hideSidebarElement(element) {
      if (!element) {
        return;
      }

      element.classList.add('daedalus-ui-hidden');
      const side = element.classList.contains('left-sidebar')
        ? 'left'
        : 'right';
      const slot = Drupal.Daedalus.SidebarManager.slotOf(element);
      element.removeAttribute(`data-offset-${side}`);

      // Empty this (side, slot)'s open-panel cookie: nothing is open here now.
      // Set empty, not deleted: the server tells "closed here" from "never
      // touched" (a default-open panel renders open only on the latter).
      Drupal.Daedalus.setCookieValue(`${side}_${slot}_sidebar`, '');

      Drupal.displace();
    }

    /**
     * Open the sidebar
     *
     * Manages cookies, button states, and DOM for server-side rendering.
     *
     * @param {string|null} id
     *   The specific sidebar instance ID to open
     *
     * @return {Promise}
     *   Resolves when the sidebar is opened.
     */
    open(id = null) {
      return new Promise((resolve) => {
        const sidebar = this.getElement(id);
        if (sidebar) {
          // Common show operations
          this.showSidebarElement(sidebar);

          // Reflect the open state on the panel's toggle buttons. The button
          // owns the only link between the two (data-sidebar-button-for),
          // so we resolve it from there -- the same elements the click
          // listener binds and the server stamps "active" on. A panel can
          // have more than one button (an always-on panel has a floating
          // launcher plus a top bar toggle); mirror them all.
          document
            .querySelectorAll(`[data-sidebar-button-for="#${sidebar.id}"]`)
            .forEach((button) => button.classList.add('active'));
        }
        resolve();
      });
    }

    /**
     * Close the sidebar
     *
     * Manages cookies, button states, and DOM for server-side rendering.
     *
     * @param {string|null} id
     *   The specific sidebar instance ID to close
     *
     * @return {Promise}
     *   Resolves when the sidebar is closed.
     */
    close(id = null) {
      return new Promise((resolve) => {
        const sidebar = this.getElement(id);
        if (sidebar) {
          // Common hide operations
          this.hideSidebarElement(sidebar);

          // Mirror the closed state on the panel's toggle buttons, resolved
          // via the button-owned link (see open()).
          document
            .querySelectorAll(`[data-sidebar-button-for="#${sidebar.id}"]`)
            .forEach((button) => button.classList.remove('active'));
        }
        resolve();
      });
    }
  }

  // Make DefaultSidebar available globally
  Drupal.Daedalus.DefaultSidebar = DefaultSidebar;

  // Register the default sidebar plugin
  const sidebarManager = Drupal.Daedalus.SidebarManager;
  sidebarManager.registerPlugin(new DefaultSidebar());
})(jQuery, Drupal);
