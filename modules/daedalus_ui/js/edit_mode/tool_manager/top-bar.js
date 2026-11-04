import { verbsFor } from '../verbs/verbs';
import { verbMenuItems } from '../verbs/verb-menu-items';
import {
  onChange as onHistoryChange,
  getState as historyState,
} from '../history/history';
import { topBarMenuItems } from './top-bar-menu-items';

/**
 * The Edit Mode top bar's client half: Save and the Daedalus menu.
 *
 * Part of the app bundle; behaviors only, so initTopBar() has no ordering
 * needs beyond running before the next attachBehaviors pass.
 *
 * Save (#daedalus-ui-save, Edit::buildTopBar()) is the one unsaved-changes
 * indicator: data-daedalus-ui-saved and the label span say whether this
 * user's history timeline holds something to undo. The server stamps the
 * state on render (history_manager->metadata($host)['can_undo']); the
 * client follows the same fact through the history mirror
 * (history/history.js, fed by every mutation response's `history`
 * payload), so an undo back to the start reads Saved again and Save
 * agrees with the History panel by construction. A blueprint change the
 * mirror has not heard about yet (a response without history metadata,
 * the first change before any response) still flips to unsaved: a
 * change is a change. Nothing polls.
 *
 * The menu (#daedalus-ui-menu, the Daedalus trigger) is the bar's one
 * menu, riding the shared primitive (Drupal.Daedalus.menu) over the pure
 * item composer (top-bar-menu-items.js). Everything is read at open time:
 * the panels from drupalSettings.daedalusUi.topBar and their open state
 * from the sidebar manager, the hotkey hints from
 * drupalSettings.daedalusUi.hotKeys, the document verbs from the registry
 * over the root (verb-menu-items.js, the one verbs-to-items wire, filtered
 * to the `document` flag), the density from the html stamp. Every row runs
 * through a door that already exists: the sidebar manager, the preview
 * window opener, the density writer, the modal opener, the mode
 * behaviors. The bar itself names no gesture.
 */

/**
 * Where Save and Discard land, percent-encoded for the query.
 *
 * The edited page's own path, unless this canvas was reached through a
 * portal's leave (Drupal.Daedalus.Portal.leave), which put the page it
 * left from on the URL as `destination`: then that page is where the
 * editor returns, so a Save on a header or a footer never strands them on
 * the block's root.
 */
const saveDestination = () =>
  encodeURIComponent(
    new URLSearchParams(window.location.search).get('destination') ||
      window.location.pathname,
  );

/**
 * The root entity's selectable key, or null before the mirror boots.
 */
const rootKey = () => {
  const root = Drupal.Daedalus.Blueprint?.getRoot?.();
  return root ? `entity:${root.uuid}` : null;
};

/**
 * The document verbs the root currently offers, menu-ordered.
 */
const documentVerbKeys = () => {
  const key = rootKey();
  return key && verbsFor(key).some((verb) => verb.document) ? [key] : [];
};

/**
 * The edit plugin's main entity, or null off editable pages.
 *
 * @todo Save and Discard use the Daedalus Edit tempstore controllers,
 * a dependency on Daedalus Edit when the tempstore for Daedalus Edit
 * and Daedalus Layout should bubble up to some parent module.
 */
const mainEntityInfo = () =>
  Drupal.Daedalus.ModeManager.getPlugin('edit').getMainEntityInfo();

/**
 * Commits the staged changes: navigates to the save route once every
 * write on the wire has landed.
 */
const save = async () => {
  // Every write (a spine op, a form commit) rides the op queue; a commit
  // still on the wire must land in tempstore before the page navigates
  // to the save route.
  await Drupal.Daedalus.Blueprint.drained();
  const info = mainEntityInfo();
  if (!info) {
    return;
  }
  if (Drupal.Daedalus.Edit?.hasInvalidFormItem?.() === true) {
    // @todo warnAboutInvalidFormItem should detect that there is already
    // a message on screen for this so you don't get double messages (the
    // form submit validation and the "hey fix this before you continue").
    Drupal.Daedalus.Edit.warnAboutInvalidFormItem();
    return;
  }
  window.location = Drupal.Daedalus.ModePluginBase.url(
    `/daedalus-edit/tempstore/save/${info.entityType}/${
      info.id
    }?destination=${saveDestination()}`,
  );
};

/**
 * Opens the discard confirm form, the one confirm, in the daedalus popup
 * through the one opener; the mode rides the query as on every edit-mode
 * route.
 */
const discard = () => {
  const info = mainEntityInfo();
  if (!info) {
    return;
  }
  const ajax = Drupal.Daedalus.openModal(
    Drupal.Daedalus.ModePluginBase.url(
      `/daedalus-edit/tempstore/delete-confirm/${info.entityType}/${
        info.id
      }?destination=${saveDestination()}`,
    ),
    {
      title: Drupal.t('Discard changes?'),
      width: 'auto',
      dialogClass: 'discard-changes-dialog',
    },
  );
  // Drupal.Ajax calls the instance's error() when the response completes,
  // so the handler lands on the executed instance.
  ajax.error = (error) => {
    Drupal.Daedalus.ModeManager.getPlugin('edit').handleError(
      error,
      'Unable to discard changes.',
    );
  };
};

/**
 * Opens or closes a panel through the sidebar manager, the road the
 * hotkeys take for a panel without a toggle button.
 *
 * @param {string} id
 *   The panel id.
 * @param {boolean} open
 *   True to open the panel, false to close it.
 */
const togglePanel = (id, open) => {
  const manager = Drupal.Daedalus.SidebarManager;
  (open ? manager.openSidebar(id) : manager.closeSidebar(id)).catch((error) => {
    // A refused close (an invalid daedalus_edit form) is expected and
    // already shown to the user; anything else is a bug.
    if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
      Drupal.throwError(error);
    }
  });
};

/**
 * Opens the Settings panel on its Hotkeys section: the one keyboard
 * shortcuts reference, the remap list itself.
 */
const keyboardShortcuts = async () => {
  await Drupal.Daedalus.SidebarManager.openSidebar('edit_mode_settings');
  const details = document.getElementById('daedalus-ui-hotkeys');
  if (!details) {
    throw new Error(
      'The Settings panel has no #daedalus-ui-hotkeys details ' +
        '(HotkeySettings).',
    );
  }
  details.open = true;
  Drupal.Daedalus.scrollReveal(details, { behavior: 'smooth' });
};

/**
 * Leaves Edit Mode the way Done does.
 */
const exit = () => {
  Drupal.behaviors.DaedalusUiModes.DisableMode(
    Drupal.Daedalus.ModeManager.getPlugin('edit'),
  );
};

const actions = {
  togglePanel,
  openPreview: () => Drupal.Daedalus.openPreviewWindow(),
  refresh: () => window.location.reload(),
  discard,
  setDensity: (value) => Drupal.Daedalus.setChromeDensity(value),
  keyboardShortcuts,
  openSettings: (url) => window.location.assign(url),
  exit,
};

/**
 * The facts the menu is built from, read at open time.
 */
const facts = () => {
  const topBar = drupalSettings.daedalusUi?.topBar;
  if (!topBar) {
    throw new Error(
      'drupalSettings.daedalusUi.topBar is missing; Edit::buildTopBar() ' +
        'attaches it with the bar.',
    );
  }
  return {
    panels: topBar.panels,
    openPanelIds: Drupal.Daedalus.SidebarManager.getOpenPanels().map(
      (panel) => panel.id,
    ),
    hotKeys: drupalSettings.daedalusUi.hotKeys ?? {},
    previewAvailable: Boolean(drupalSettings.daedalusUi.responsivePreview?.url),
    documentItems: verbMenuItems(documentVerbKeys(), (verb) => verb.document),
    settingsUrl: topBar.settingsUrl,
    density:
      document.documentElement.getAttribute(
        'data-daedalus-ui-chrome-density',
      ) ?? 'full',
  };
};

export const initTopBar = () => {
  /**
   * Save: the commit, and the unsaved indicator.
   */
  Drupal.behaviors.DaedalusUiSave = {
    attach: (context) => {
      once('DaedalusUiSave', '#daedalus-ui-save', context).forEach(
        (saveButton) => {
          const label = saveButton.querySelector('.daedalus-ui-save__label');
          const setSaved = (saved) => {
            saveButton.setAttribute(
              'data-daedalus-ui-saved',
              saved ? 'true' : 'false',
            );
            label.textContent = saved
              ? Drupal.t('Saved')
              : Drupal.t('Save changes');
          };
          saveButton.addEventListener('click', save);

          // The history mirror is the server's own rule: something to
          // undo means unsaved, nothing means saved (an undo to the start
          // reads Saved again).
          onHistoryChange((state) => {
            if (state.loaded) {
              setSaved(!state.canUndo);
            }
          });
          // A mirror replacement is a change, unless the history mirror
          // has already said otherwise (the response that replaced the
          // mirror carried its timeline first). The mirror's boot (null
          // to the first blueprint) is not a change.
          let booted = Drupal.Daedalus.Blueprint.getBlueprint() !== null;
          Drupal.Daedalus.Blueprint.onBlueprintChange(() => {
            if (!booted) {
              booted = true;
              return;
            }
            const history = historyState();
            setSaved(history.loaded ? !history.canUndo : false);
          });
        },
      );
    },
  };

  /**
   * The Daedalus menu trigger.
   */
  Drupal.behaviors.DaedalusUiMenu = {
    attach: (context) => {
      once('DaedalusUiMenu', '#daedalus-ui-menu', context).forEach((button) => {
        // Whether OUR menu is the open one (aria-expanded); the
        // primitive's onClose clears it however the menu closes.
        let expanded = false;
        const setExpanded = (open) => {
          expanded = open;
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        // The trigger toggles: the anchor counts as inside the menu's
        // dismissal boundary, so a click on it while open closes rather
        // than reopens.
        button.addEventListener('click', (event) => {
          event.preventDefault();
          if (expanded) {
            Drupal.Daedalus.menu.close();
            return;
          }
          setExpanded(
            Drupal.Daedalus.menu.open({
              anchor: button,
              align: 'start',
              items: topBarMenuItems(facts(), actions),
              onClose: () => setExpanded(false),
            }),
          );
        });
      });
    },
  };
};
