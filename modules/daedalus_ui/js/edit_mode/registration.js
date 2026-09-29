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
 *     app.verbs.register({ id: 'my_module.thing', kinds: ['block'], ... });
 *     app.editSession.registerEngine(myEngine);
 *     app.onSelectionChange((key) => { ... });
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
import { setViewVisibility, setMarkedKeys } from './store/slices/view-slice';
import { generateUuid } from './utilities/uuid';
import { ModePluginBase } from '../mode_manager/mode-plugin-base';
import {
  registerTreeViewProvider,
  registerPartBehavior,
  getPartBehavior,
  registerShowMenuItem,
} from './components/page-elements/view-registry';
import {
  projectionIndex,
  projectionRoot,
} from './components/page-elements/projection-index';
import { reachable } from './components/page-elements/reach';
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
  ModePluginBase,

  // Client-side UUIDs for ops that name their elements up front (the batch
  // grammar honors them), so optimistic UI and tests can address an element
  // before the server confirms it.
  generateUuid,

  // Registration surfaces.
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
    // item now being edited, null when the edit ended; key = the item's
    // key when the engine keyed it, a panel widget); the app derives the
    // store's editingKey from it. See edit-session.js editChanged.
    editChanged: (item, key) =>
      Drupal.Daedalus.EditSession.editChanged(item, key),
  },
  tree: {
    // A page-elements tree view provider (see view-registry.js for the
    // contract): while its Show-menu toggle is on, the provider's view of
    // the model replaces the tree's projection. Model rows are unchanged
    // on the selection spine; pseudo rows enter it only as a styling
    // target (a pseudo row click selects its owner's key and targets the
    // row's part selector under it).
    registerViewProvider: registerTreeViewProvider,
    // The part behavior the built-in markup view's shell rows delegate to
    // (see view-registry.js): {resolve, selectorFor, label}. What a
    // declared markup class OFFERS (daedalus_stylish: the part resolution seam) as
    // opposed to the anatomy itself, which daedalus_ui composes, and
    // the interactive state — selection, hover, row glow — which lives on
    // the spine as the styling target and the hover facet.
    registerPartBehavior,
    // The registered part behavior, read-only: {resolve, selectorFor,
    // matches?, generated?, label?} as the registry wraps it (resolve
    // already gated on the projection), or null while none is
    // registered. The seam's
    // readers on the spine (selector-events, the markup view, the
    // breadcrumb) reach it in-bundle; a module that prints through the
    // grammar (daedalus_explode's part planes) reads it here, at print
    // time rather than at registration, since the behavior's own
    // registration is another queue callback that may run later.
    partBehavior: getPartBehavior,
    // A Show-menu item (see view-registry.js for the contract): {id,
    // label, icon?, checked, run}, rendered in the Page elements panel's
    // Show kebab after the view toggles, behind a separator. For a
    // module whose entry belongs in that menu without being a view of
    // the tree (daedalus_explode's Explode toggles a canvas projection
    // from here). `checked()` is read as the menu opens, so the owner
    // answers from its own state (a panel's DOM open state) and nothing
    // stores it twice; re-registering an id replaces the item in place.
    registerShowMenuItem,
    // Programmatic Show-menu visibility for a view, by its displayToggle
    // id ('markup' for the built-in markup view): a module-owned mode
    // switch (daedalus_stylish's basic/advanced inspector) drives a view on and
    // off. Same view-slice state the Show-menu checkbox writes, so the
    // menu reflects it and stays user-controllable between switches.
    setViewVisibility: (id, visible) =>
      store.dispatch(setViewVisibility({ id, visible: visible === true })),
    // Row marks: outline a set of rows (by selectable key) in the shared
    // focus treatment (--daedalus-focus) — the tree half of a module's
    // coverage gesture (daedalus_stylish's Outline Variant marks a variant's
    // wearers on the canvas and here as one gesture). One mark set at a
    // time, last write wins; null or [] clears. Keys the tree does not
    // currently render are inert.
    setRowMarks: (keys) => store.dispatch(setMarkedKeys(keys ?? [])),
    // THE projection index, read-only (see projection-index.js): address →
    // {label, kind, bundle, parent, rowParent, children, rows, tier,
    // owner?, tag?} over BOTH tiers, element keys and `key::selector` part
    // addresses. The same map labelForKey, typeForKey, traversal, the
    // breadcrumb and drag read, memoized on the mirror reference (rebuilt
    // only when a mutation response replaces the blueprint), so a module
    // reading depth, labels, kinds or ancestry from it (daedalus_explode's
    // plane model) never disagrees with the tree. Null before the mirror
    // boots; read it at interaction time, never at registration, and
    // never mutate the entries: they are the tree's own.
    index: projectionIndex,
    // The projection root's key (the page-entity row), or null before the
    // mirror boots: where an ancestry walk over index() ends.
    root: projectionRoot,
    // Reach (see reach.js): whether one index address is currently
    // offered to land on, which is that it exists in index() AND the Page
    // elements panel depicts its row under the current view visibility
    // (a shell part or a generated box only while the Markup view is on;
    // an element or an instance row always). Every entry road reads it:
    // the pointer's depth stop, the keyboard, the breadcrumb, and a
    // module that lifts what the panel shows (daedalus_explode's plane
    // model) reads it here, per address, at print time. False for
    // everything before the mirror boots.
    reachable,
    // Fires with the view visibility map (view id → boolean) whenever a
    // Show-menu view toggle flips, by the checkbox or setViewVisibility:
    // what reachable() is keyed to, so a reader that prints by reach
    // (daedalus_explode's host) re-prints on the flip and not on the next
    // unrelated change. Returns an unsubscribe.
    onViewVisibilityChange: (callback) =>
      listenToStateChange((state) => state.view.views, callback),
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
  onSelectionChange: (callback) =>
    Drupal.Daedalus.Selection.onChange(callback),
  // The target-aware counterpart: fires when the selection OR its styling
  // target changes, so styling surfaces track the address without widening
  // onSelectionChange (which stays key-only for element-scoped consumers).
  onTargetChange: (callback) =>
    Drupal.Daedalus.Selection.onTargetChange(callback),
  onHoverChange: (callback) =>
    Drupal.Daedalus.Selection.onHoverChange(callback),
  // The transient selector-hover facet ({key, selector} | null),
  // value-compared — what a canvas selector-paint adapter renders the
  // dashed hover box from.
  onHoverSelectorChange: (callback) =>
    Drupal.Daedalus.Selection.onHoverSelectorChange(callback),
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
  get ModeManager() {
    return Drupal.Daedalus.ModeManager;
  },
  get SidebarManager() {
    return Drupal.Daedalus.SidebarManager;
  },
});

/**
 * Drains queued registration callbacks and goes live.
 *
 * Called once, at the end of app boot: everything a callback may touch
 * (managers, facades, the store) exists by then. A failing callback is
 * contained so one broken module cannot take down every registration
 * behind it in the queue.
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
  };
  window.DaedalusUiQueue = { push: run };
  queued.forEach(run);
};
