/**
 * The Edit mode plugin and the Edit Mode boot behavior.
 *
 * Part of the app bundle: initEditModePlugin() runs after the mode
 * manager boots, so the manager reads below are ordered by code, not by
 * script delivery.
 */
import { warnMissingEntityUi } from './utilities/warn';

export const initEditModePlugin = () => {
  const modeManager = Drupal.Daedalus.ModeManager;

  Drupal.behaviors.DaedalusUiEditMode = {
    attach: (context, settings) => {
      // Not once-gated: this behavior can miss the initial document attach
      // (script delivery order varies with page weight and entry path), and
      // once('body') would then never match again — later attaches only see
      // subtree contexts — leaving Edit Mode's client state (hotkeys, mode
      // flags) silently uninitialized. Idempotent re-assertion on every
      // attach is load-order-proof; the edit_mode class is the "done" flag.
      // The mode slice is what the selection grammar and its chrome gate
      // on (Edit Mode on or off).
      if (
        Drupal.Daedalus.getCookieValue('navigationMode') === 'edit' &&
        !document.documentElement.classList.contains('edit_mode')
      ) {
        document.documentElement.classList.add('edit_mode');
        window.setMode('edit');
        window.setHotKey(true);
        window.setFileDrag(true);
      }
    },
  };

  /**
   * Daedalus UI Edit Mode plugin
   */
  class EditModePlugin extends Drupal.Daedalus.ModePluginBase {
    id = 'edit';

    enable = () => {
      this.ReloadPageElements()
        .then(async (response, status) => {
          document.querySelector('html').classList.add('edit_mode');
          // Clean slate: clear any panel left open from outside Edit Mode
          // (the chat), then open the mode's default-open panels.
          await Drupal.Daedalus.SidebarManager.closeAll();
          this.openDefaultSidebars();
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

    disable = () => {
      document.querySelector('html').classList.remove('edit_mode');
      window.setHotKey(false);
      window.setFileDrag(false);
      // Close the mode-bound panels but leave the always-on chat, which becomes
      // user-owned again outside Edit Mode.
      Drupal.Daedalus.SidebarManager.closeEphemeral();
      // Leaving the mode must not leave an open editor behind: the open
      // edit commits through its one commit door (an invalid widget keeps
      // it and surfaces its own feedback).
      Drupal.Daedalus.EditSession.commit();
      return this.ReloadPageElements();
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

    /**
     * Reveals a canvas element a message or an editor points at (the
     * notifications sidebar, daedalus_edit's invalid-input reveal) by the
     * one selection reveal rule (Drupal.Daedalus.scrollReveal): the whole
     * element in view, centered when it fits, never moved when it already
     * is. The fixed chrome's offset is the canvas's scroll-margin-top
     * (css/edit-mode.css), no math here.
     *
     * @param element
     *   The canvas element.
     */
    scrollToElement = (element) => {
      Drupal.Daedalus.scrollReveal(element, { behavior: 'smooth' });
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
