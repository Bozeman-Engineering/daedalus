/**
 * The registration seam between contrib modules and the edit-mode app.
 *
 * Script delivery order is not a guarantee this code can use: library
 * dependencies order parser-inserted script tags, but anything delivered
 * through AJAX add_js (big_pipe placeholders, LoadEditablePage, dialogs) is a
 * dynamically inserted script that executes whenever its bytes arrive. A
 * contrib script that reads an app global at its own top level is therefore
 * racing the app bundle, and only warm browser caches make it look ordered.
 *
 * The seam removes the race instead of managing it. A contrib edit-mode
 * script's only load-time act is pushing a registration callback onto a
 * plain array that needs no app code to exist:
 *
 *   (window.DaedalusUiQueue ||= []).push((app) => {
 *     class MyTool extends app.ToolPluginBase {
 *       id = 'my_tool';
 *     }
 *     app.tools.register(new MyTool());
 *     app.verbs.register({ id: 'my_module.thing', kinds: ['block'], ... });
 *     app.onToolChange((currentTool) => { ... });
 *   });
 *
 * The app drains the queue once, at the end of its boot, and replaces the
 * array with a live object whose push() runs callbacks immediately — so a
 * callback pushed before, with, or after the app executes behaves the same.
 * Callbacks receive the app API and should register things; runtime work
 * belongs in the registered plugins and handlers, where the rest of the API
 * (Drupal.Daedalus.*) is safe to read because the app booted long ago.
 */

import store, { listenToStateChange } from './store/store';
import { setPartVisibility, setMarkedKeys } from './store/slices/view-slice';
import { generateUuid } from './utilities/uuid';
import { ToolPluginBase } from './tool_manager/tool-plugin-base';
import { ModePluginBase } from '../mode_manager/mode-plugin-base';
import {
  registerTreeViewProvider,
  registerPartBehavior,
} from './components/page-elements/view-registry';
import {
  createGeometryTracker,
  documentPosition,
} from './utilities/geometry-tracker';
import { createSelectableTracker } from './selection/selectable-tracker';

/**
 * The API handed to registration callbacks.
 *
 * Registration surfaces and curated events are safe the moment a callback
 * runs. The runtime facades are lazy getters because some of them (the
 * SidebarManager) live outside the bundle and may not have executed yet when
 * registration runs — they are read at call time, which is interaction time.
 */
const buildApp = () => ({
  // Base classes for plugin registration.
  ToolPluginBase,
  ModePluginBase,

  // Client-side UUIDs for ops that name their elements up front (the batch
  // grammar honors them), so optimistic UI and tests can address an element
  // before the server confirms it.
  generateUuid,

  // Registration surfaces.
  tools: {
    register: (plugin) =>
      Drupal.Daedalus.ToolManager.registerPlugin(plugin),
  },
  modes: {
    register: (plugin) =>
      Drupal.Daedalus.ModeManager.registerPlugin(plugin),
  },
  verbs: {
    register: (verb) => Drupal.Daedalus.Verbs.register(verb),
  },
  editSession: {
    registerEngine: (engine) =>
      Drupal.Daedalus.EditSession.registerEngine(engine),
    // The engine's one setter reports every edit open/close (item = the
    // leaf now being edited, null when the edit ended); the app derives
    // the store's editingKey from it. See edit-session.js editChanged.
    editChanged: (item) => Drupal.Daedalus.EditSession.editChanged(item),
  },
  tree: {
    // A page-elements tree view provider (see view-registry.js for the
    // contract): while its Show-menu toggle is on, the provider's view of
    // the model replaces the tree's projection. Model rows are unchanged
    // on the selection spine; pseudo rows enter it only as a part facet (a
    // pseudo row click commits its owner's key with the row id as
    // selectedPart).
    registerViewProvider: registerTreeViewProvider,
    // The part behavior the built-in markup view's shell rows delegate to
    // (see view-registry.js): {resolve, selectorFor}. What a
    // declared markup class OFFERS (daedalus_stylish: the part resolution seam) as
    // opposed to the anatomy itself, which daedalus_ui composes, and
    // the interactive state — selection, hover, row glow — which lives on
    // the spine as the part facets.
    registerPartBehavior,
    // Programmatic Show-menu visibility for a view, by its displayToggle
    // id ('markup' for the built-in markup view): a module-owned mode
    // switch (daedalus_stylish's basic/advanced inspector) drives a view on and
    // off. Same view-slice state the Show-menu checkbox writes, so the
    // menu reflects it and stays user-controllable between switches.
    setPartVisibility: (id, visible) =>
      store.dispatch(setPartVisibility({ id, visible: visible === true })),
    // Row marks: outline a set of rows (by selectable key) in the shared
    // focus treatment (--daedalus-focus) — the tree half of a module's
    // coverage gesture (daedalus_stylish's Outline Variant marks a variant's
    // wearers on the canvas and here as one gesture). One mark set at a
    // time, last write wins; null or [] clears. Keys the tree does not
    // currently render are inert.
    setRowMarks: (keys) => store.dispatch(setMarkedKeys(keys ?? [])),
  },
  // Per-frame canvas geometry tracking, the outline's own mechanism shared
  // (utilities/geometry-tracker.js): a frame loop plus a stale-element
  // cache measuring live elements every animation frame, so floating chrome
  // in #daedalus-root follows resize, animation, and markup swaps with
  // one mechanism. createSelectableTracker resolves selectable keys
  // (chrome that follows the selection); createTracker takes a custom
  // resolver for anything else, e.g. multi-match part selectors.
  // Element-following chrome paints position: absolute at documentPosition
  // coordinates — not fixed — so scrolling moves chrome and content
  // together on the compositor instead of trailing by a frame.
  geometry: {
    createTracker: createGeometryTracker,
    createSelectableTracker,
    documentPosition,
  },
  drag: {
    registerSource: (type, spec) =>
      Drupal.Daedalus.Drag.registerSource(type, spec),
    // A registered source's spec: a placement door of the source's own
    // (the Add panel's click-to-place) answers the spec's acceptsTarget,
    // the same legality the pointer drag and the keyboard lift consult.
    getSource: (type) => Drupal.Daedalus.Drag.getSource(type),
    makeDraggable: (element, type, getData) =>
      Drupal.Daedalus.Drag.makeDraggable(element, type, getData),
    removeDraggable: (element) =>
      Drupal.Daedalus.Drag.removeDraggable(element),
    // External arming, for drags that originate outside the page (OS file
    // drags): a detector recognizes the gesture and arms a registered
    // source; the drop handler delivers the files as data.files.
    beginExternalDrag: (type, data) =>
      Drupal.Daedalus.Drag.beginExternalDrag(type, data),
    endExternalDrag: () => Drupal.Daedalus.Drag.endExternalDrag(),
    // The keyboard lift: arms a registered source and walks the
    // insertion caret through its valid gaps by arrow key; the palette's
    // Enter-on-an-item rides this ("lifted with a new block").
    lift: (type, data, options) =>
      Drupal.Daedalus.Drag.lift(type, data, options),
    isLifted: () => Drupal.Daedalus.Drag.isLifted(),
    cancelLift: () => Drupal.Daedalus.Drag.cancelLift(),
    // The standard operation builders (newBlock, moveBlock, newSection,
    // moveSection) a source's operations() can point at.
    get builders() {
      return Drupal.Daedalus.Drag.builders;
    },
    // The one depth read for a target's location (gaps.js): whether it
    // lands in the root flow, a page band's position.
    isRootFlowLocation: (location) =>
      Drupal.Daedalus.Drag.isRootFlowLocation(location),
  },

  // Curated events. The store itself is app-private: subscribing to state
  // shapes here instead of window.listenToStateChange keeps the slice layout
  // free to change without breaking contrib.
  onToolChange: (callback) =>
    listenToStateChange((state) => state.tool.currentTool, callback),
  onSelectionChange: (callback) =>
    Drupal.Daedalus.Selection.onChange(callback),
  // The part-aware counterpart: fires on any committed (key, part) tuple
  // change, so part surfaces track sub-selection without widening
  // onSelectionChange (which stays key-only for element-scoped consumers).
  onPartChange: (callback) =>
    Drupal.Daedalus.Selection.onPartChange(callback),
  onHoverChange: (callback) =>
    Drupal.Daedalus.Selection.onHoverChange(callback),
  // The transient part-hover facet ({key, part} | null), value-compared —
  // what a canvas part-paint adapter renders the dashed hover box from.
  onHoverPartChange: (callback) =>
    Drupal.Daedalus.Selection.onHoverPartChange(callback),
  onBlueprintChange: (callback) =>
    Drupal.Daedalus.Blueprint.onBlueprintChange(callback),
  onHistoryChange: (callback) =>
    Drupal.Daedalus.History.onChange(callback),

  // Runtime facades, resolved at read time.
  get Hotkeys() {
    return Drupal.Daedalus.Hotkeys;
  },
  get Selection() {
    return Drupal.Daedalus.Selection;
  },
  get Blueprint() {
    return Drupal.Daedalus.Blueprint;
  },
  get Materials() {
    return Drupal.Daedalus.Materials;
  },
  get History() {
    return Drupal.Daedalus.History;
  },
  get EditSession() {
    return Drupal.Daedalus.EditSession;
  },
  get ToolManager() {
    return Drupal.Daedalus.ToolManager;
  },
  get ModeManager() {
    return Drupal.Daedalus.ModeManager;
  },
  get SidebarManager() {
    return Drupal.Daedalus.SidebarManager;
  },
});

/**
 * Warns for server-rendered tools whose JS plugin never registered.
 *
 * The toolbar buttons are rendered from the PHP tool plugins, so every
 * data-tool id on them should have a matching JS plugin by the time delivery
 * settles. A tool whose script never executed — or crashed reading an app
 * global at top level instead of using the queue — used to fail silently:
 * the button rendered, its chrome never appeared, and clicking it threw.
 *
 * Scheduled behind a settle delay because tool scripts arrive dynamically
 * (big_pipe placeholders, AJAX add_js) and legitimately execute after the
 * toolbar markup lands. Every schedule call resets the timer, and both the
 * live queue push and the Edit Mode behavior attach reschedule, so the check
 * runs once things stop arriving.
 */
const DIAGNOSTICS_SETTLE_MS = 4000;
let diagnosticsTimer = null;
const warnedTools = new Set();

export const scheduleRegistrationDiagnostics = () => {
  clearTimeout(diagnosticsTimer);
  diagnosticsTimer = setTimeout(() => {
    const buttons = document.querySelectorAll('#edit-mode-toolbar [data-tool]');
    if (!buttons.length) {
      return;
    }
    const registered = new Set(
      Object.values(Drupal.Daedalus.ToolManager.plugins).map(
        (plugin) => plugin.id,
      ),
    );
    buttons.forEach((button) => {
      const toolId = button.dataset.tool;
      if (!registered.has(toolId) && !warnedTools.has(toolId)) {
        warnedTools.add(toolId);
        console.warn(
          `Daedalus: the "${toolId}" tool rendered a toolbar button but its JS plugin never registered — its edit-mode script did not run or failed. Tool scripts register via (window.DaedalusUiQueue ||= []).push((app) => { ... }).`,
        );
      }
    });
  }, DIAGNOSTICS_SETTLE_MS);
};

/**
 * Drains queued registration callbacks and goes live.
 *
 * Called once, at the end of app boot: everything a callback may touch
 * (managers, facades, the store) exists by then. A failing callback is
 * contained so one broken tool cannot take down every registration behind
 * it in the queue.
 */
export const drainRegistrationQueue = () => {
  const queued = Array.isArray(window.DaedalusUiQueue)
    ? window.DaedalusUiQueue
    : [];
  const app = buildApp();
  const run = (callback) => {
    try {
      callback(app);
    } catch (error) {
      console.error('A Daedalus registration callback failed:', error);
    }
    // A late-arriving script is a signal delivery hasn't settled: push the
    // not-yet-registered check out again.
    scheduleRegistrationDiagnostics();
  };
  window.DaedalusUiQueue = { push: run };
  queued.forEach(run);
  scheduleRegistrationDiagnostics();
};
