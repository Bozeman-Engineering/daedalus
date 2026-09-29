(($, Drupal) => {
  const SidebarPluginBase = Drupal.Daedalus.SidebarPluginBase;

  /**
   * Sidebar Manager
   *
   * The single owner of sidebar open/close. At most one panel is open PER
   * (SIDE, SLOT): sides (left/right) and slots within a side (top/bottom) are
   * independent, so a left-rail tree (top) and palette (bottom) coexist while
   * panels sharing the same side AND slot are mutually exclusive. Every
   * opener (toggle buttons, the chat FAB, hotkeys, daedalus_edit forms)
   * routes through here; there is no other open/close path.
   *
   * There is no in-memory record of "what's open". The DOM is the single source
   * of truth: the open panel in a (side, slot) is the one without the
   * "daedalus-ui-hidden" class, and the server renders that same state from
   * the per-(side, slot) "{side}_{slot}_sidebar" cookie (written alongside the
   * DOM by DefaultSidebar). State cannot drift because nothing stores it twice.
   */
  class SidebarManager {
    /**
     * Registered sidebar plugins, keyed by type.
     * @type {Map<string, SidebarPluginBase>}
     */
    plugins = new Map();

    /**
     * Register a sidebar plugin.
     *
     * @param {SidebarPluginBase} plugin
     *   The sidebar plugin to register.
     */
    registerPlugin(plugin) {
      if (!(plugin instanceof SidebarPluginBase)) {
        throw new Error('Plugin must extend SidebarPluginBase');
      }
      this.plugins.set(plugin.type, plugin);
    }

    /**
     * Get a sidebar plugin by type.
     *
     * @param {string} type
     *   The sidebar plugin type.
     *
     * @return {SidebarPluginBase}
     *   The sidebar plugin.
     *
     * @throws {Error}
     *   If the plugin is not found.
     */
    getPlugin(type) {
      if (!this.plugins.has(type)) {
        throw new Error(`Sidebar plugin "${type}" not found`);
      }
      return this.plugins.get(type);
    }

    /**
     * Resolve which side a panel sits on from its DOM element.
     *
     * The plugin id is the verbatim DOM id (identity rule) and the side is
     * carried as the "{side}-sidebar" class, so the element is the single
     * source of truth for a panel's side.
     *
     * @param {string} id
     *   The panel id (== its DOM id).
     *
     * @return {?string}
     *   'left' or 'right', or null when the panel is not present on this page.
     */
    sideOf(id) {
      const element = document.getElementById(id);
      if (!element) {
        return null;
      }
      return element.classList.contains('left-sidebar') ? 'left' : 'right';
    }

    /**
     * Resolve which slot a panel occupies from its DOM element.
     *
     * The slot rides the data-sidebar-slot attribute stamped by the server
     * (daedalus_edit's runtime-appended forms declare 'bottom'). A panel without
     * one defaults to 'top'.
     *
     * @param {Element} element
     *   The panel element.
     *
     * @return {string}
     *   The slot name ('top' unless declared otherwise).
     */
    slotOf(element) {
      return element.dataset.sidebarSlot || 'top';
    }

    /**
     * The panel currently open in a (side, slot), derived from the DOM.
     *
     * The open panel is the one element in that side and slot not carrying
     * "daedalus-ui-hidden". This is the single source of truth -- there is
     * no parallel in-memory record to fall out of sync with.
     *
     * @param {string} side
     *   'left' or 'right'.
     * @param {string} slot
     *   The slot within the side; defaults to 'top'.
     *
     * @return {?{element: Element, plugin: SidebarPluginBase, id: string}}
     *   The open panel descriptor, or null when that (side, slot) is empty.
     */
    getOpenPanel(side, slot = 'top') {
      const element = [
        ...document.querySelectorAll(
          `.${side}-sidebar:not(.daedalus-ui-hidden)`,
        ),
      ].find((candidate) => this.slotOf(candidate) === slot);
      if (!element) {
        return null;
      }
      return {
        element,
        plugin: this.getPlugin(element.dataset.sidebarType),
        id: element.id,
      };
    }

    /**
     * Every open panel, across both sides and all slots.
     *
     * @return {{element: Element, plugin: SidebarPluginBase, id: string}[]}
     *   Open panel descriptors, possibly empty.
     */
    getOpenPanels() {
      return [
        ...document.querySelectorAll(
          '.daedalus-ui-sidebar:not(.daedalus-ui-hidden)',
        ),
      ].map((element) => ({
        element,
        plugin: this.getPlugin(element.dataset.sidebarType),
        id: element.id,
      }));
    }

    /**
     * Open a sidebar, closing whatever currently holds its (side, slot).
     *
     * The plugin type is read from the element's data-sidebar-type, so callers
     * pass only the id. A no-op when the panel is not present (e.g. a sidebar
     * plugin that did not render on this page) or already open. Panels in
     * the side's other slots are untouched -- slots stack, they don't compete.
     *
     * @param {string} id
     *   The panel id to open.
     *
     * @return {Promise}
     *   Resolves when open; rejects if the same-(side, slot) panel refused to
     *   close (e.g. an invalid daedalus_edit form), in which case nothing is
     *   opened.
     */
    async openSidebar(id) {
      const element = document.getElementById(id);
      if (!element) {
        return;
      }
      // Already the open panel in this (side, slot) (the DOM says so).
      if (!element.classList.contains('daedalus-ui-hidden')) {
        return;
      }
      const side = element.classList.contains('left-sidebar')
        ? 'left'
        : 'right';

      // Free the (side, slot) first. A rejected close (invalid form) aborts
      // the open.
      const current = this.getOpenPanel(side, this.slotOf(element));
      if (current && current.id !== id) {
        await current.plugin.close(current.id);
      }

      await this.getPlugin(element.dataset.sidebarType).open(id);
      Drupal.displace();
    }

    /**
     * Close a specific panel by id.
     *
     * Used by a panel's own close control and by a toggle button switching off.
     * Only acts when that panel is actually open (visible in the DOM).
     *
     * @param {string} id
     *   The panel id to close.
     *
     * @return {Promise}
     *   Resolves when closed; rejects if the panel refused to close.
     */
    async closeSidebar(id) {
      const element = document.getElementById(id);
      if (!element || element.classList.contains('daedalus-ui-hidden')) {
        return;
      }
      await this.getPlugin(element.dataset.sidebarType).close(id);
      Drupal.displace();
    }

    /**
     * Close every panel open on a side, across all its slots.
     *
     * @param {string} side
     *   'left' or 'right'.
     *
     * @return {Promise}
     *   Resolves when closed; rejects if a panel refused to close.
     */
    async closeSide(side) {
      // Panels close one at a time so each close hook sees the previous
      // panel gone.
      for (const panel of this.getOpenPanels()) {
        if (panel.element.classList.contains(`${side}-sidebar`)) {
          // eslint-disable-next-line no-await-in-loop
          await panel.plugin.close(panel.id);
        }
      }
      Drupal.displace();
    }

    /**
     * Close every open panel on both sides.
     *
     * @return {Promise}
     *   Resolves when both sides are closed.
     */
    async closeAll() {
      await this.closeSide('left');
      await this.closeSide('right');
    }

    /**
     * Temporarily widen one panel beyond the rail width.
     *
     * For content that needs more room than the user's rail width gives it
     * (e.g. daedalus_edit widening the form panel to fit a wide widget). The
     * request is a floor, not a replacement: the panel gets the larger of
     * the rail width and the requested width, clamped to half the viewport,
     * so a user who already dragged the rail wider is never shrunk.
     * Presentation only -- open/close state and cookies are untouched. Call
     * releasePanelWidth() when the need passes.
     *
     * @param {Element} element
     *   The panel element.
     * @param {number} px
     *   The needed width in pixels.
     */
    setPanelWidth(element, px) {
      element.style.inlineSize = `min(max(var(--daedalus-ui-sidebar-width, 17rem), ${Math.round(px)}px), 50vw)`;
      Drupal.displace();
    }

    /**
     * Undo setPanelWidth(): the panel follows the rail width again.
     *
     * @param {Element} element
     *   The panel element.
     */
    releasePanelWidth(element) {
      element.style.removeProperty('inline-size');
      Drupal.displace();
    }

    /**
     * Close every open panel, except always-on (persistent) ones.
     *
     * Used when leaving Edit Mode: the mode-bound panels (settings, palettes,
     * edit forms) close, while a panel marked data-sidebar-persistent -- the
     * always-on chat -- stays in place and becomes user-owned again.
     *
     * @return {Promise}
     *   Resolves when the ephemeral panels are closed.
     */
    async closeEphemeral() {
      for (const panel of this.getOpenPanels()) {
        if (panel.element.hasAttribute('data-sidebar-persistent')) {
          continue;
        }
        // eslint-disable-next-line no-await-in-loop
        await panel.plugin.close(panel.id);
      }
      // Leaving the mode reverts every default-open panel's (side, slot) to
      // "never touched", so the panel is open again on the next entry however
      // that entry happens (the client toggle opens unconditionally; the
      // page-load entry paths render from this absent cookie). Swept over ALL
      // default-open panels, not just the open ones: a deliberately closed
      // panel is exactly the one whose empty cookie would otherwise outlive
      // the mode session it was closed in.
      document
        .querySelectorAll('[data-sidebar-default-open]')
        .forEach((element) => {
          const side = element.classList.contains('left-sidebar')
            ? 'left'
            : 'right';
          const slot = this.slotOf(element);
          Drupal.Daedalus.deleteCookieValue(`${side}_${slot}_sidebar`);
        });
      Drupal.displace();
    }
  }

  // Initialize global instance.
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.SidebarManager = new SidebarManager();
})(jQuery, Drupal);
