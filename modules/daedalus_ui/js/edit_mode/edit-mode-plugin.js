/**
 * The Edit mode plugin and the Edit Mode boot behavior.
 *
 * Part of the app bundle: initEditModePlugin() runs after the mode/tool
 * managers boot, so the manager reads below are ordered by code, not by
 * script delivery.
 */
import { scheduleRegistrationDiagnostics } from './registration';
import { warnMissingEntityUi } from './utilities/warn';

export const initEditModePlugin = () => {
  const modeManager = Drupal.Daedalus.ModeManager;
  const toolManager = Drupal.Daedalus.ToolManager;

  Drupal.behaviors.DaedalusUiEditMode = {
    attach: (context, settings) => {
      // Not once-gated: this behavior can miss the initial document attach
      // (script delivery order varies with page weight and entry path), and
      // once('body') would then never match again — later attaches only see
      // subtree contexts — leaving Edit Mode's client state (hotkeys, mode
      // flags) silently uninitialized. Idempotent re-assertion on every
      // attach is load-order-proof; the edit_mode class is the "done" flag.
      // The infallible state flags come first so a tool-activation failure
      // cannot take Edit Mode's state down with it.
      if (
        Drupal.Daedalus.getCookieValue('navigationMode') === 'edit' &&
        !document.documentElement.classList.contains('edit_mode')
      ) {
        document.documentElement.classList.add('edit_mode');
        window.setMode('edit');
        window.setHotKey(true);
        window.setFileDrag(true);
        modeManager.getPlugin('edit').activateTool();
      }

      once(
        'DaedalusUiEditMode',
        '#edit-mode-toolbar .daedalus-ui-button',
        context,
      ).forEach((toolButton) => {
        // Listen for tool activation clicks.
        toolButton.addEventListener('click', (event) => {
          const nextTool = event.currentTarget.dataset.tool;
          modeManager.getPlugin('edit').changeTool(nextTool);
        });
        // The toolbar (re)arrived — its buttons' tool scripts may still be in
        // flight, so restart the not-yet-registered settle check.
        scheduleRegistrationDiagnostics();
      });
    },
  };

  /**
   * Daedalus UI Edit Mode plugin
   */
  class EditModePlugin extends Drupal.Daedalus.ModePluginBase {
    id = 'edit';

    activationTicket = 0;

    enable = () => {
      this.ReloadPageElements()
        .then(async (response, status) => {
          document.querySelector('html').classList.add('edit_mode');
          // Clean slate: clear any panel left open from outside Edit Mode (the
          // chat) so the chat reflects the active tool. The active tool's enable
          // reopens it only if that tool owns it (the AI tool).
          await Drupal.Daedalus.SidebarManager.closeAll();
          this.openDefaultSidebars();
          this.activateTool();
          window.setHotKey(true);
          window.setFileDrag(true);
        })
        .catch((error) => {
          console.error(
            'An error occurred while trying to load the editing UI:',
            error,
          );
        });
    };

    /**
     * Open default-open panels on Edit Mode entry.
     *
     * The JS half of the Sidebar attribute's default_open: entering Edit Mode
     * client-side flips visibility without a page_top re-render, so the panels
     * are revealed here — unconditionally. A deliberate close (toggle click)
     * sticks only for the rest of that mode session: in-mode page loads render
     * the closed state from the empty cookie server-side, but every fresh
     * entry runs this and starts the panel open again (the exit sweep in
     * closeEphemeral() expires the cookie for the page-load entry paths).
     */
    openDefaultSidebars = () => {
      document
        .querySelectorAll('[data-sidebar-default-open]')
        .forEach((panel) => {
          Drupal.Daedalus.SidebarManager.openSidebar(panel.id);
        });
    };

    disable = (editModeDisabled = false) => {
      document.querySelector('html').classList.remove('edit_mode');
      window.setHotKey(false);
      window.setFileDrag(false);
      this.removeMouse();
      // Close the mode-bound panels but leave the always-on chat, which becomes
      // user-owned again outside Edit Mode.
      Drupal.Daedalus.SidebarManager.closeEphemeral();
      const activeTool =
        Drupal.Daedalus.getCookieValue('activeTool') ?? 'selector';
      if (toolManager.hasPlugin(activeTool)) {
        toolManager.getPlugin(activeTool).disable(true);
      }
      return this.ReloadPageElements();
    };

    removeMouse = () => {
      document
        .querySelectorAll('.daedalus-ui-button.active')
        .forEach((button) => {
          document.querySelector('html').classList.remove(button.dataset.tool);
          button.classList.remove('active');
        });
    };

    changeMouse = (pluginId) => {
      this.removeMouse();
      if (!pluginId) {
        return;
      }
      document
        .querySelector(`[data-tool="${pluginId}"]`)
        ?.classList.add('active');
      document.querySelector('html').classList.add(pluginId);
      sessionStorage.setItem('mouseState', pluginId);
    };

    activateTool = (toolPluginId = null) => {
      if (!toolPluginId) {
        const defaultTool =
          drupalSettings.daedalusUi.defaultTool ?? 'selector';
        toolPluginId =
          Drupal.Daedalus.getCookieValue('activeTool') || defaultTool;
      }

      // Tool scripts arrive dynamically (big_pipe placeholders, AJAX add_js),
      // so the tool being activated may not have registered yet when the edit
      // chrome lands — activation waits for it instead of racing it. The
      // ticket discards a superseded activation: if the user switches tools
      // while an earlier activation is still waiting, the earlier one must
      // not enable its tool over the newer one when its script finally runs.
      const ticket = ++this.activationTicket;
      toolManager
        .whenRegistered(toolPluginId)
        .then((tool) => {
          if (ticket !== this.activationTicket) {
            return;
          }
          this.changeMouse(toolPluginId);
          tool.enable().then(() => {
            Drupal.Daedalus.setCookieValue('activeTool', toolPluginId);

            const ToolChangeEvent = new CustomEvent(
              'Daedalus.EditModeToolChangeEvent',
              {
                detail: {
                  active: toolPluginId,
                },
                bubbles: true,
                cancelable: true,
              },
            );
            document.dispatchEvent(ToolChangeEvent);
          });
        })
        .catch((error) => {
          // The tool never registered — a stale cookie or settings naming an
          // uninstalled tool. Fall back to the configured default, then to the
          // always-present selector, so Edit Mode still has a working tool; the
          // registration diagnostics warn about the missing script separately.
          if (ticket !== this.activationTicket || toolPluginId === 'selector') {
            return;
          }
          const defaultTool =
            drupalSettings.daedalusUi.defaultTool ?? 'selector';
          const fallback =
            toolPluginId === defaultTool ? 'selector' : defaultTool;
          console.warn(
            `Daedalus: could not activate the "${toolPluginId}" tool (${error.message}); falling back to "${fallback}".`,
          );
          this.activateTool(fallback);
        });
    };

    changeTool = (nextTool = null) => {
      const activeTool =
        Drupal.Daedalus.getCookieValue('activeTool') ?? 'selector';

      // A tool that never registered has no state to disable (its cookie is
      // stale — activateTool only writes the cookie after a successful
      // enable), so skip straight to activating the next tool.
      const disabled = toolManager.hasPlugin(activeTool)
        ? toolManager.getPlugin(activeTool).disable()
        : Promise.resolve();
      disabled
        .then(() => {
          modeManager.getPlugin('edit').activateTool(nextTool);
        })
        .catch((error) => {
          if (error) {
            this.message(error.message, 'warning', {
              duration: 3000,
              scroll: false,
            });
          }
        });
    };

    /**
     * Reload page elements.
     *
     * When the edit cookie changes, AJAX reload the page so that the page
     * elements will have the UI attributes applied to them.
     */
    ReloadPageElements = () => {
      return new Promise((resolve, reject) => {
        const info = this.getMainEntityInfo();
        if (!info) {
          this.message('No main entity found.');
          return;
        }

        const ajax = Drupal.Daedalus.ModePluginBase.ajax({
          url: `/daedalus-ui/load-editable-page/${info.entityType}/${
            info.id
          }/${info.viewMode}`,
          type: 'POST',
          dataType: 'text',
          progress: {
            type: 'fullscreen',
            message: Drupal.t('Loading Layout Builder...'),
          },
          error: (error) => {
            console.error(error.responseText);
            this.handleError(error, 'Unable to load the editing UI.');
          },
          success: (response, status) => {
            Promise.resolve(
              Drupal.Ajax.prototype.success.call(ajax, response, status),
            ).then(() => {
              resolve();
            });
          },
        });
        ajax.execute();
      });
    };

    getMainEntityWrapper = () => {
      return document.querySelector(
        '.daedalus-ui-entity-wrapper[data-main-entity]',
      );
    };

    getMainEntityInfo = () => {
      const wrapper = this.getMainEntityWrapper();
      if (!wrapper) {
        warnMissingEntityUi(
          'getMainEntityInfo',
          '.daedalus-ui-entity-wrapper[data-main-entity]',
        );
        return false;
      }
      const entityWrapperId = wrapper.dataset.daedalusUiEntityWrapper;
      const [entityType, id, bundle] = entityWrapperId.split('::');
      const viewMode = wrapper.dataset.daedalusUiViewMode;
      const uuid = wrapper.dataset.entityUuid;
      return { entityType, id, bundle, viewMode, uuid, wrapper };
    };

    /**
     * Get section storage information.
     *
     * @param element
     *   Probably a dropzone, field, or block element.
     *
     * @return {{region: string, storageId: string, blockUuid: string, storageType: string, sectionDelta: string}}
     *   An array of layout builder storage details for editing items on the
     *   page.
     */
    getSectionStorageInfo = (element) => {
      if (!element.closest('.layout-builder')) {
        return null;
      }
      const storageType = drupalSettings['Daedalus Layout']?.sectionStorageType;
      const storageId = drupalSettings['Daedalus Layout']?.sectionStorage;
      if (!storageType) {
        return null;
      }
      const sectionDelta = element.closest(
        '[data-layout-builder-section-delta]',
      )?.dataset.layoutBuilderSectionDelta;
      const region = element.closest('[region]')?.getAttribute('region');
      const block = element.closest('[data-layout-builder-block-uuid]');
      const blockUuid = block?.dataset.layoutBuilderBlockUuid;

      return {
        region,
        storageId,
        blockUuid,
        storageType,
        sectionDelta,
      };
    };

    handleError = (error, message = 'Unknown operation') => {
      document.querySelectorAll('.ajax-progress').forEach((progress) => {
        progress.remove();
      });
      this.message(message, 'error', 15000);
    };

    /**
     * Show a message to the user.
     *
     * The edit-mode facade over the notification spine
     * (Drupal.Daedalus.messenger, daedalus_ui/messenger): the
     * toast, its history and the repeat handling live there.
     *
     * @param message
     *   The message text to display.
     * @param type
     *   The message type: 'error', 'warning' or 'status'.
     * @param options
     *   Optional configuration object:
     *   - duration: How long to show the message in milliseconds or -1 for
     *     forever.
     *   - scroll: Whether to bring the toast into view (default: true)
     * @param elementId
     *   Optional element id or selectable key the message concerns; the
     *   notifications sidebar scrolls back to it.
     *
     * @return {string}
     *   The toast id.
     */
    message = (message, type = 'error', options = {}, elementId = null) => {
      const { duration, scroll = true } = options;
      return Drupal.Daedalus.messenger.message(message, type, {
        duration,
        scroll,
        target: elementId,
      });
    };

    scrollToElement = (element) => {
      // Check if element is visible in viewport.
      const rect = element.getBoundingClientRect();
      const viewportHeight = window.innerHeight;
      const viewportTop = 0;
      const isVisible = rect.top < viewportHeight && rect.bottom > viewportTop;

      if (!isVisible) {
        element.style.scrollMarginTop =
          'var(--daedalus-ui-scroll-offset-top, var(--drupal-displace-offset-top, 0))';
        element.scrollIntoView({
          behavior: 'smooth',
        });
      }
    };

    /**
     * Highlight.
     *
     * Adds a 3s fading background to an element to call attention to it.
     *
     * @param element
     */
    highlight = (element) => {
      element?.classList.add('call-attention-to');
      setTimeout(() => {
        element?.classList.remove('call-attention-to');
      }, 3000);
    };
  }

  modeManager.registerPlugin(new EditModePlugin());
};
