import { configureStore } from '@reduxjs/toolkit';
import toolReducer from './slices/tool-slice';
import hotKeyReducer from './slices/hot-key-slice';
import fileDragReducer from './slices/file-drag-slice';
import modeReducer from './slices/mode-slice';
import selectionReducer from './slices/selection-slice';
import blueprintReducer from './slices/blueprint-slice';
import dragReducer from './slices/drag-slice';
import viewReducer from './slices/view-slice';
import materialsReducer from './slices/materials-slice';

const store = configureStore({
  reducer: {
    tool: toolReducer,
    hotKey: hotKeyReducer,
    fileDrag: fileDragReducer,
    mode: modeReducer,
    selection: selectionReducer,
    blueprint: blueprintReducer,
    drag: dragReducer,
    view: viewReducer,
    materials: materialsReducer,
  },
  devTools: process.env.DEV_MODE, // Enable DevTools only in dev mode
});

// Expose store and debugging utility globally in dev mode.
// DEV_MODE is set in webpack.config.js based on the build commands like
// build:dev defined in package.json.
if (process.env.DEV_MODE) {
  window.reduxStore = store;
}

export default store;

/**
 * Listen to state change.
 *
 * Usage:
 *
 * window.listenToStateChange(
 *   state => state.drag.active,
 *   active => console.log('drag active ', active)
 * );
 *
 * @param selector
 *   The redux state selector.
 * @param callback
 *   The callback for when the state changes.
 */
export const listenToStateChange = (selector, callback) => {
  let previousValue = selector(store.getState());

  store.subscribe(() => {
    const currentState = store.getState();
    const currentValue = selector(currentState);

    if (currentValue !== previousValue) {
      callback(currentValue);
      previousValue = currentValue;
    }
  });
};
// The window bridge remains for the app's own externally-loaded scripts;
// contrib subscribes through the registration seam's curated events
// (app.onToolChange etc.) so the store shape stays private.
window.listenToStateChange = listenToStateChange;

/**
 * Listen to multiple states.
 *
 * Usage:
 *
 * listenToMultipleStates(
 *   [
 *     state => state.tool.currentTool,
 *     state => state.drag.active
 *   ],
 *   (currentTool, active) => {
 *     console.log('currentTool ', currentTool, 'drag active ', active);
 *   }
 * );
 *
 * @param selectors
 *   An array of redux state selectors.
 * @param callback
 *   The callback for when the state changes.
 */
window.listenToMultipleStates = (selectors, callback) => {
  const values = selectors.map((selector) => selector(store.getState()));

  // Set up a listener for each selector.
  selectors.forEach((selector, index) => {
    window.listenToStateChange(selector, (newValue) => {
      values[index] = newValue;
      callback(...values);
    });
  });
};
