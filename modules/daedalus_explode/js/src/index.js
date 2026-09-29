import { h, render } from 'preact';
import { log } from './log.js';
import * as canvasView from './canvas-view.js';
import { createHost } from './host.js';
import { settings } from './settings.js';
import { ExplodePanel } from './panel.js';

/**
 * The in-page entry point: the Explode sidebar plugin type.
 *
 * Registration rides the DaedalusUiQueue seam: the only load-time act is
 * pushing a callback, so this bundle never races the app bundle or the
 * sidebar stack (Drupal.Daedalus.DefaultSidebar exists by the time the
 * app drains the queue). The panel markup renders server-side
 * (ExplodeSidebar) and persists across mode and tool switches.
 *
 * The panel's open state IS the exploded state: open() stamps the
 * canvas-view attribute on <html> and starts the host (host.js), which
 * prints the exploded sheet; close() stops the host, which clears the
 * attribute once the planes have settled flat. Every opener (the Page
 * elements panel's Show-menu entry, the Panels hotkey, a cookie-rendered
 * open at boot, the mode exit's closeEphemeral sweep) routes through the
 * sidebar manager, so this plugin is the one entry to the attribute and
 * the host.
 *
 * The panel declares no top-bar button. Its opener is a checked item in
 * the Page elements panel's Show menu, registered through daedalus_ui's
 * seam for it (app.tree.registerShowMenuItem): the item's checked state
 * is the panel's DOM open state, read when the menu opens, and activating
 * it toggles the panel through the sidebar manager exactly as a toggle
 * button's click would (sidebar-buttons.js), so open/close policy and
 * the close guard stay the manager's.
 */
const PANEL_ID = 'daedalus_explode';

/**
 * The Show-menu entry's glyph: Phosphor's Stack, in the menu grammar's
 * inline-SVG convention (fill="currentColor"; the menu sizes it).
 */
const STACK_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256">'
  + '<path d="M230.91,172A8,8,0,0,1,228,182.91l-96,56a8,8,0,0,1-8.06,0l-96-56A8,8,0,0,1,36,169.09l92,53.65,92-53.65A8,8,0,0,1,230.91,172ZM220,121.09l-92,53.65L36,121.09A8,8,0,0,0,28,134.91l96,56a8,8,0,0,0,8.06,0l96-56A8,8,0,1,0,220,121.09ZM24,80a8,8,0,0,1,4-6.91l96-56a8,8,0,0,1,8.06,0l96,56a8,8,0,0,1,0,13.82l-96,56a8,8,0,0,1-8.06,0l-96-56A8,8,0,0,1,24,80Zm23.88,0L128,126.74,208.12,80,128,33.26Z"></path>'
  + '</svg>';

(window.DaedalusUiQueue ||= []).push((app) => {
  const { DefaultSidebar, SidebarManager } = Drupal.Daedalus;
  const host = createHost({ app, settings });

  /**
   * The Explode sidebar plugin.
   *
   * The DOM is the open-state truth (daedalus-ui-hidden), so the attribute
   * follows what the panel element says after the base class has moved it,
   * never a parallel flag.
   */
  class ExplodeSidebar extends DefaultSidebar {
    type = PANEL_ID;

    /**
     * Whether the Explode panel is showing.
     *
     * @param {string|null} id
     *   The panel id.
     *
     * @return {boolean}
     *   True while the panel is present and open.
     */
    isShowing(id = PANEL_ID) {
      const panel = this.getElement(id);
      return !!panel && !panel.classList.contains('daedalus-ui-hidden');
    }

    /**
     * Open the panel and enter the exploded view.
     *
     * @param {string|null} id
     *   The panel id to open.
     *
     * @return {Promise}
     *   Resolves when the panel is open and the view is set.
     */
    open(id = null) {
      return super.open(id).then(() => {
        if (this.isShowing(id)) {
          canvasView.enter();
          host.start();
          log('open', 'Panel open, canvas view exploded');
        }
      });
    }

    /**
     * Close the panel and leave the exploded view.
     *
     * The panel hides at once; the canvas returns flat over the lift
     * transition and the host clears the view attribute after it. The
     * return is not awaited: the manager awaits close() before opening
     * the next panel in the slot and on the mode exit's sweep, and
     * neither should wait out an animation.
     *
     * @param {string|null} id
     *   The panel id to close.
     *
     * @return {Promise}
     *   Resolves when the panel is hidden.
     */
    close(id = null) {
      return super.close(id).then(() => {
        log('close', 'Panel closed, returning the canvas');
        host.stop();
      });
    }
  }

  const plugin = new ExplodeSidebar();
  SidebarManager.registerPlugin(plugin);

  // The opener: a checked entry in the Page elements panel's Show menu.
  // checked() is read as the menu opens, so it answers from the panel's
  // DOM open state (the sidebar manager's one truth), never a flag of its
  // own. run() toggles through the manager by that same state, the
  // policy a toggle button's click applies (sidebar-buttons.js): a
  // refused close (an invalid daedalus_edit form) is expected and already
  // shown to the user; anything else is a bug.
  app.tree.registerShowMenuItem({
    id: PANEL_ID,
    label: Drupal.t('Explode'),
    icon: STACK_ICON,
    checked: () => plugin.isShowing(),
    run: () => {
      const toggled = plugin.isShowing()
        ? SidebarManager.closeSidebar(PANEL_ID)
        : SidebarManager.openSidebar(PANEL_ID);
      toggled.catch((error) => {
        if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
          console.error('Cannot toggle the Explode panel:', error);
        }
      });
    },
  });

  // The panel's controls (panel.js) mount into the server-rendered mount
  // point. The panel markup persists across mode and tool switches, so
  // one mount per page lifetime suffices; the behavior covers a mount
  // point that arrives later (an AJAX re-render of the chrome), the
  // dataset flag keeps a re-attach from mounting twice.
  Drupal.behaviors.daedalusExplodePanel = {
    attach: () => {
      const mount = document.getElementById('daedalus-explode-panel');
      if (!mount || mount.dataset.daedalusExplodeMounted) {
        return;
      }
      mount.dataset.daedalusExplodeMounted = 'true';
      render(<ExplodePanel settings={settings} app={app} />, mount);
      log('mount', 'Panel controls mounted');
    },
  };
  Drupal.behaviors.daedalusExplodePanel.attach();

  // A panel the server rendered open (the right_top_sidebar cookie names
  // it) never passes through open(): the attribute must follow the DOM's
  // open state at boot too, or a reload while exploded would show the
  // panel with a flat canvas.
  // The index may still be null here (the mirror boots after the queue
  // drains); the host prints the stage now and the planes on the
  // blueprint change that follows.
  if (plugin.isShowing()) {
    canvasView.enter();
    host.start();
    log('boot', 'Panel rendered open, canvas view exploded');
  }
});
