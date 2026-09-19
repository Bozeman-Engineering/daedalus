import { h, render } from 'preact';
import { Provider } from 'react-redux';
import store from './store/store';
import SelectionFrame from './components/selection-frame/selection-frame';
import Portal from './components/portal/portal';
import InsertionCaret from './components/insertion-caret/insertion-caret';
import SelectionIndicator from './components/selection-indicator/selection-indicator';
import bootToolManager from './tool_manager/tool-plugin-manager';
import bootModeManager from '../mode_manager/mode-plugin-manager';
import { initHotkeys } from './hotkeys/hotkeys';
import { initVerbs } from './verbs/verbs';
import { registerRenameVerb } from './verbs/rename-verb';
import { initHistory } from './history/history';
import { initEditSession } from './edit_session/edit-session';
import { initSelectorEvents } from './selection/selector-events';
import { initKeyboardTraversal } from './selection/keyboard-traversal';
import { initSelectionFacade } from './selection/selection-facade';
import { initBlueprintMirror } from './blueprint/blueprint-mirror';
import { initMaterialsStore } from './materials/materials-store';
import { initDrag } from './drag/drag-controller';
import { initSelectorDrag } from './drag/selector-drag';
import { initKeyboardLift } from './drag/keyboard-lift';
import { initPageElementsTree } from './components/page-elements/page-elements-tree';
import { initTopBar } from './tool_manager/top-bar';
import { initEditModePlugin } from './edit-mode-plugin';
import { initSelectorTool } from './tools/selector-tool-plugin';
import { drainRegistrationQueue } from './registration';

if (process.env.DEV_MODE) {
  import(/* webpackMode: "eager" */ 'preact/devtools');
}

/**
 * Boots the edit-mode app in dependency order: managers, then facades, then
 * the app's own mode/tool plugins, and finally the registration queue, so
 * contrib callbacks run against a complete app (see registration.js). This
 * in-file ordering is the only ordering the app relies on — script delivery
 * order is not a guarantee (big_pipe and AJAX-inserted scripts execute
 * whenever their bytes arrive).
 */
const boot = () => {
  // Managers first: everything below may register plugins.
  bootToolManager();
  bootModeManager();
  // Hotkeys next: everything after it may register bindings.
  initHotkeys();
  initVerbs();
  initHistory();
  initEditSession();
  initSelectorEvents();
  initKeyboardTraversal();
  initSelectionFacade();
  initBlueprintMirror();
  initMaterialsStore();
  initDrag();
  initSelectorDrag();
  // After initDrag: the lift facade extends Drupal.Daedalus.Drag.
  initKeyboardLift();
  initPageElementsTree();
  // The app's own verbs and plugins go through the same registries
  // contrib uses.
  registerRenameVerb();
  initTopBar();
  initEditModePlugin();
  initSelectorTool();
  // Contrib registrations, last.
  drainRegistrationQueue();

  const root = document.getElementById('daedalus-root');
  if (root) {
    render(
      <Provider store={store}>
        <SelectionFrame />
        <Portal />
        <InsertionCaret />
        <SelectionIndicator />
      </Provider>,
      root,
    );
  }
};

// Re-executing the bundle would rebuild the store and managers, silently
// discarding every registered plugin — the failure mode a stale
// ajax_page_state once caused by re-shipping already-loaded scripts. Refuse
// loudly instead.
if (window.DaedalusUiAppBooted) {
  console.warn(
    'The Daedalus edit-mode app executed twice; ignoring the repeat execution.',
  );
} else {
  window.DaedalusUiAppBooted = true;
  boot();
}
