/**
 * Tool plugin base.
 *
 * A base class for Daedalus UI Edit mode Tool plugins.
 */
export class ToolPluginBase {
  /**
   * The id of the sidebar this tool owns, or null if it has none.
   *
   * Subclasses set this to route their panel through the SidebarManager on
   * enable/disable. It is the panel's verbatim DOM id (identity rule) and the
   * manager reads the side + type from the element, so there is one open/close
   * path for every sidebar.
   *
   * @type {?string}
   */
  sidebarId = null;

  /**
   * Initialized the plugin.
   */
  init() {}

  /**
   * Enable the tool.
   *
   * @return {Promise}
   */
  enable() {
    if (this.sidebarId) {
      Drupal.Daedalus.SidebarManager.openSidebar(this.sidebarId).catch(
        (error) => {
          // The same-side panel refused to close (e.g. an invalid daedalus_edit
          // form); it stays open and surfaces its own feedback.
          if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
            console.error('Cannot open tool sidebar:', error);
          }
        },
      );
    }
    const topBar = document.querySelector(`#${this.id}-top-bar`);
    if (topBar) {
      topBar.classList.remove('daedalus-ui-hidden');
    }
    window.setCurrentTool(this.id ?? null);
    return Promise.resolve();
  }

  /**
   * Disable the tool.
   *
   * @param editModeDisabled
   *   False if we are just disabling the tool to switch to another tool.
   *   True if we are exiting edit mode.
   * @return {Promise}
   */
  disable(editModeDisabled = false) {
    if (this.sidebarId) {
      // On Edit-Mode exit, an always-on panel (the chat) persists as
      // user-owned; only a tool switch closes it.
      const element = document.getElementById(this.sidebarId);
      const persistent =
        element && element.hasAttribute('data-sidebar-persistent');
      if (!(editModeDisabled && persistent)) {
        Drupal.Daedalus.SidebarManager.closeSidebar(this.sidebarId).catch(
          (error) => {
            if (
              error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED
            ) {
              console.error('Cannot close tool sidebar:', error);
            }
          },
        );
      }
    }
    const topBar = document.querySelector(`#${this.id}-top-bar`);
    if (topBar) {
      topBar.classList.add('daedalus-ui-hidden');
    }
    window.setCurrentTool(null);
    return Promise.resolve();
  }
}

Drupal.Daedalus = Drupal.Daedalus || {};
Drupal.Daedalus.ToolPluginBase = ToolPluginBase;
