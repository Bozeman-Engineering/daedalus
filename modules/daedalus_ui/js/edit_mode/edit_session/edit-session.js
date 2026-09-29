import store from '../store/store';
import { setSelected, setEditingKey } from '../store/slices/selection-slice';
import {
  resolve,
  setEditableItems,
  presentationBoundaryItem,
  keyForEditableItem,
  elementForKey,
} from '../selection/resolver';
import { policyFor } from '../selection/depth-policy';
import { sessionItems, neighborEntry } from './session-items';
import { register as registerHotkey } from '../hotkeys/hotkeys';

/**
 * The OPEN EDIT: editing as a state of the ONE selection.
 *
 * Edit Mode has one pointer and one keyboard grammar over the selection
 * (selector-events.js, keyboard-traversal.js); editing is not a tool or a
 * mode but a fact of the selection, the store's editingKey, entered by a
 * gesture that means "edit this" and left by a gesture that ends it. There
 * is no armed state, no store door and no session to leave: with no edit
 * open the selection grammar is the only grammar, and a selection that
 * lands never opens an editor by itself; a landing that should start
 * typing says so on its op-queue request (`edit: true` beside `select`,
 * op-queue.js) and reaches the same door every other gesture does.
 *
 * THE DOOR. openEditor(key[, target]) is the one way in, and every gesture
 * that means "edit this" calls it: Enter on the selection (the `edit.enter`
 * binding below, selection context, never while typing, so an open editor
 * keeps its newline), the Edit verb on the chip, a double-click at a leaf
 * (selector-events.js, chain index 0, passing the pointer's target so the
 * leaf it hit is the one that edits), a page-tree row's Enter (the same
 * binding: the tree is a tree widget, so its rows' Enter reaches the
 * dispatcher), the entity-form panel's toggle presenting the root, the op
 * queue's `edit` intent, and the engine's validation-failure re-open. A
 * key that owns a canvas leaf opens that leaf's editor (editItem); a key
 * with no leaf but a SUBJECT the engine can present (the root entity, a
 * block placement whose fields all live in the panel) is presented in the
 * engine's panel with its first revealed widget opened as the edit; a
 * section or a foreign key opens nothing; with no engine registered the
 * door is a no-op.
 *
 * THE KEYS, one ladder everywhere (hotkeys.js: typing, lift, editing,
 * selection, global):
 * - Tab / Shift+Tab (`editing` and `typing`): the editing walk. Native
 *   within the current item's inputs; only at the edge input does Tab
 *   commit the edit and open the neighbor in session order, exactly what
 *   native Tab would do if every widget were rendered inline. A focused
 *   panel widget is the current item by the same rule whether or not the
 *   engine holds an edit on it. With no edit open and no widget focused
 *   Tab is the browser's. A widget that already consumed the keystroke
 *   (the stroke's `consumed` fact: CKEditor indenting a list, an
 *   autocomplete navigating options) wins.
 * - Escape (`editing` and `typing`): cancels the open edit. The global
 *   Escape ladder (the picker, the target, the clear; selector-events.js)
 *   is the NEXT press: Escape never switches anything and never traverses.
 * - Cmd+Enter (`editing` and `typing`): commits and STAYS, the selection
 *   kept and no edit open. The panel's Update button is its visible twin
 *   (commit()).
 * Because typing outranks editing in the dispatcher, the three register
 * with both contexts so they keep working while focus is inside the
 * active widget.
 *
 * THE ITEMS. The editable items are one ordered, keyed list
 * (session-items.js): the canvas leaves in document order, then the
 * entity-form panel's revealed widgets in form order, each panel widget
 * keyed by the engine (`field:{root}:{f}`, `block:{c}:{f}`,
 * `block:{c}:label`). A revealed widget is a field with no canvas leaf,
 * so the panel is not chrome to the edit but the far end of the same
 * walk: Tab from the last leaf lands on the first widget, a press into a
 * widget edits its field, and the widget's key is the editingKey every
 * selection-anchored surface (the frame, the verb edit-scope gate) reads
 * unchanged.
 *
 * THE PRESS while an edit is open (onClick, capture phase) is the one
 * owner of "does this press end the edit": keep (the edit's own surface
 * per engine.keepsEdit, chrome marked [data-keeps-edit], the selection
 * frame), switch (another editable leaf the selection grammar names, or a
 * revealed panel widget), commit (anything else). The commit case leaves
 * the press to the selection grammar that runs after it, so inside the
 * canvas the press lands where a click always lands (a selection, or the
 * keyless clear) and outside the canvas the selection stays.
 *
 * The app owns the open edit; the editing machinery stays in the module
 * that registers the ENGINE (daedalus_edit: form-swap, field plugins,
 * tempstore sync), registered through the same facade + ready-event idiom
 * as Verbs.
 *
 * The applied seam: an open edit is a DRAFT the engine holds, and the
 * canvas is its projection. A foreign mutation (a style scrub, an undo, an
 * AI turn) swaps the edited markup out from under it; the op queue reports
 * the end of every applied response here (mutationApplied) with the
 * payload, and the edit forwards it to the engine so the engine can
 * re-project the draft over the fresh markup and refresh the panel
 * widgets the write left stale. The signal is "response fully applied"
 * (after the piggybacked commands), never the earlier mirror replace.
 */

/** @type {?object} The registered editing engine. */
let engine = null;

/**
 * Registers the editing engine.
 *
 * @param {object} spec
 *   - items: () => {canvas: Element[], panel: {item: Element, key:
 *     ?string}[]} — the engine's editable items: the canvas leaves in
 *     document order (the engine's vocabulary; keyed here through the
 *     projection gate) and the entity-form panel's revealed widgets in
 *     form order, each keyed by the engine from the subject the panel
 *     shows (null when the engine cannot key one). They merge into the
 *     ordered item list (session-items.js), and the canvas half is
 *     injected into the resolver, so nothing here names another module's
 *     attributes.
 *   - beginEdit: (item: Element) => Promise — load the editor for an item
 *     (a canvas leaf, or a panel widget: focus it) and give it focus.
 *     Ends any current edit itself.
 *   - endEdit: ({cancel}) => Promise — commit (or cancel) the current
 *     edit. Rejects when the widget is invalid and the edit must stay.
 *   - isEditing: () => bool.
 *   - currentItem: () => ?Element — the leaf being edited, or null.
 *   - inputs: () => Element[]|NodeList — the active widget's focusable
 *     inputs, document order.
 *   - keepsEdit: (target: Element) => bool — whether a press on this
 *     element lands on the current edit's OWN surface (the editing
 *     element, editor-launched popups and dialogs), so the press decision
 *     (onClick) leaves the edit open. The engine answers only for its own
 *     surfaces; page chrome that keeps an edit declares data-keeps-edit
 *     on its root instead.
 *   - mutationApplied: (payload: Object) => ?Promise — a mutation
 *     response has been fully applied (markup swapped, mirror replaced,
 *     piggybacked commands played). Called for every applied response,
 *     with the payload: the engine refreshes its panel's revealed widgets
 *     from the payload's targets, re-projects an open edit's draft over
 *     the swapped-in markup, or drops the edit when the swap removed its
 *     leaf. The engine decides what "the draft" is; only WHEN is decided
 *     here.
 *   - presentSubject: (key: string) => Promise<boolean> — show the
 *     subject a selectable key names in the engine's panel (the root
 *     entity, a block placement's content), opening the panel on it when
 *     it is not already showing it. Resolves false when the key names no
 *     subject the engine can show. The door's road for a key that owns no
 *     canvas leaf, so a subject whose editor is the panel gets its editor
 *     exactly as a leaf gets its widget.
 *
 * Beyond the members, the engine owes a REPORT: its one setter for the
 * current edit calls editChanged(item[, key]) on every open and close
 * (through the registration seam's app.editSession.editChanged), which is
 * what keeps the store's editingKey — and everything derived from it —
 * truthful without polling.
 */
const registerEngine = (spec) => {
  const required = [
    'items',
    'beginEdit',
    'endEdit',
    'isEditing',
    'currentItem',
    'inputs',
    'keepsEdit',
    'mutationApplied',
    'presentSubject',
  ];
  if (!spec || required.some((member) => !spec[member])) {
    throw new Error(`Invalid edit session engine: ${JSON.stringify(spec)}`);
  }
  engine = spec;
  setEditableItems(() => engine.items().canvas);
};

/**
 * The engine's report that the live edit changed — the reactive half of
 * the engine contract. The engine's ONE setter calls this on every edit
 * open/close with the item now being edited (null when the edit ended).
 * A canvas leaf's key is derived here through the same gate every editing
 * door passes (keyForEditableItem over the presentation boundary); a
 * panel widget has no selectable wrapper to derive from, so the engine
 * reports its key alongside (the key rule of session-items.js). Either
 * way the store's editingKey follows the edit's REAL lifecycle, including
 * on paths not initiated here (Escape's cancel inside the widget, a
 * markup swap ending an edit, focus leaving a panel widget).
 *
 * @param {?Element} item
 *   The item now being edited, or null.
 * @param {string} [key]
 *   The item's key, when the engine keyed it (a panel widget). Omitted
 *   for a canvas leaf, whose key is derived here.
 */
const editChanged = (item, key) => {
  let next = null;
  if (item) {
    next =
      key !== undefined
        ? key
        : keyForEditableItem(presentationBoundaryItem(item));
  }
  if (next !== store.getState().selection.editingKey) {
    store.dispatch(setEditingKey(next));
  }
};

/**
 * Whether an edit is open: the store's editingKey, the one reactive
 * truth the editChanged report writes.
 */
export const isEditing = () =>
  store.getState().selection.editingKey !== null;

/**
 * The op queue's report that a mutation response has been FULLY applied
 * (the applied seam, see the module docblock). Forwarded to the engine
 * with the payload: the engine owns the draft and its re-projection, and
 * its panel's freshness (a write to a field whose widget the panel shows
 * leaves that widget stale), so nothing is carried here but the moment
 * and the payload.
 *
 * @param {Object} payload
 *   The applied mutation-response payload.
 *
 * @return {?Promise}
 *   The engine's flight, or undefined when no engine is registered.
 */
export const mutationApplied = (payload) => {
  if (engine !== null) {
    return engine.mutationApplied(payload);
  }
  return undefined;
};

/**
 * The items, in session order (session-items.js): the canvas leaves that
 * pass the projection gate at their presentation boundary, then the
 * panel's keyed widgets. keyForEditableItem is the one gate for the
 * canvas half, so Tab lands exactly where the door would and never
 * leaves the universe.
 *
 * @return {{item: Element, key: string, panel: boolean}[]}
 */
const items = () =>
  sessionItems(engine.items(), {
    boundary: presentationBoundaryItem,
    keyFor: keyForEditableItem,
  });

/**
 * The focusable controls inside a panel widget, document order: the
 * widget is an ordinary form region, so its stops are the standard
 * focusables. The canvas counterpart is the engine's inputs() for the
 * edit it holds.
 *
 * @param {Element} item
 *   A panel widget item.
 *
 * @return {Element[]}
 */
const focusablesIn = (item) =>
  Array.from(
    item.querySelectorAll(
      'input:not([type="hidden"]), select, textarea, button, a[href], [contenteditable="true"], [tabindex]:not([tabindex="-1"])',
    ),
  ).filter((element) => !element.disabled && element.getClientRects().length);

/**
 * The panel entry whose widget contains an element, or null.
 */
const panelEntryAt = (element) =>
  items().find((entry) => entry.panel && entry.item.contains(element)) ??
  null;

/**
 * The panel entry holding keyboard focus, or null. A focused widget is
 * the current item for the Tab edge rule whether or not the engine holds
 * an edit on it.
 */
const focusedPanelEntry = () => {
  const active = document.activeElement;
  return active instanceof Element ? panelEntryAt(active) : null;
};

/**
 * Selects an entry's key and opens its editor. Inside a merged
 * presentation root the committed key is the root's own (the merged node
 * absorbs its wrapper chain) while the edit still targets the leaf item;
 * the root's edit-target leaf (the label, wherever it renders) commits the
 * root entity key the same way; a panel widget commits its field key.
 * Entering backwards (Shift+Tab) lands focus on the widget's last input —
 * the position native Tab would arrive at.
 *
 * @param {{item: Element, key: string, panel: boolean}} entry
 *   The entry to edit.
 * @param {number} direction
 *   1 (default) or -1 for entering backwards.
 *
 * @return {Promise}
 */
const editItem = (entry, direction = 1) => {
  const { item, key } = entry;
  if (key && key !== store.getState().selection.selectedKey) {
    store.dispatch(setSelected(key));
  }
  return engine.beginEdit(item).then(() => {
    if (direction === -1) {
      const inputs = entry.panel
        ? focusablesIn(item)
        : Array.from(engine.inputs() ?? []);
      inputs[inputs.length - 1]?.focus();
    }
  });
};

/**
 * The entry `direction` steps from an item in session order, or the first
 * / last entry when there is no reference point.
 */
const neighbor = (from, direction) => neighborEntry(items(), from, direction);

/**
 * The editable canvas leaf a KEY owns: the leaf the door would edit for
 * that selection. A key that is itself a presentation root (a unit media
 * reference, a merged block — whose owned leaves sit under DEEPER
 * selectable wrappers) yields its boundary item. Null when the key owns
 * no canvas leaf (a container, a region, a foreign host's key, the root
 * entity: its editor is the entity-form panel, which the door presents
 * through presentSubject; where a theme prints the label as an H1 leaf
 * that leaf commits the root key, see rootKeyForEditTarget, but sits
 * outside the entity wrapper, so only a pointer gesture reaches it,
 * entryAt; the row itself owns nothing).
 */
const itemForKey = (key) => {
  const wrapper = elementForKey(key);
  if (!wrapper) {
    return null;
  }
  const canvas = engine.items().canvas;
  if (wrapper.hasAttribute('data-presentation')) {
    // A collapsed block IS its own editable item (leaf-is-root markup), so
    // the wrapper itself may be the first leaf in document order.
    const inner = canvas.find(
      (item) => item === wrapper || wrapper.contains(item),
    );
    return inner ? presentationBoundaryItem(inner) : null;
  }
  if (canvas.includes(wrapper)) {
    return wrapper;
  }
  return (
    canvas.find(
      (item) =>
        wrapper.contains(item) && item.closest('[data-selectable]') === wrapper,
    ) ?? null
  );
};

/**
 * The entry an edit on a key would land on, or null: editability as a
 * static per-element fact, never a focus fact. The key's wrapper must own
 * a canvas leaf whose presentation boundary passes the projection gate
 * (keyForEditableItem), the same filter Tab traversal applies, so a
 * container, a region, or a foreign host's leaf yields null. The frame's
 * dash reads the store's editingKey instead (the LIVE edit, never a
 * prediction).
 */
const entryForKey = (key) => {
  if (engine === null || !key) {
    return null;
  }
  const item = itemForKey(key);
  if (item === null) {
    return null;
  }
  const boundary = presentationBoundaryItem(item);
  const committed = keyForEditableItem(boundary);
  return committed !== null
    ? { item: boundary, key: committed, panel: false }
    : null;
};

/**
 * The editable canvas leaf under a pointer target, at its presentation
 * boundary: the innermost of the engine's canvas leaves containing the
 * target, or null when the pointer hit none (a wrapper's padding, a
 * section, chrome).
 */
const itemAt = (target) => {
  const hits = engine
    .items()
    .canvas.filter((item) => item === target || item.contains(target));
  return hits.length > 0
    ? presentationBoundaryItem(hits[hits.length - 1])
    : null;
};

/**
 * The entry a POINTER gesture on a key lands on, or null. The selection
 * grammar decided the key; the pointer decides which of the key's own
 * leaves edits: the one it hit, when that leaf commits the key
 * (keyForEditableItem), never another key's leaf. Where the key road
 * (entryForKey, the leaf a key OWNS by containment) and this one differ
 * is a leaf that commits a key whose wrapper does not contain it: the
 * theme's H1, the root's label leaf promoted to the root row
 * (rootKeyForEditTarget), sits outside the entity wrapper, so the key
 * road finds no leaf for the root and presents the panel form (the root
 * row's editor by design, Enter's road), while the pointer on the H1
 * edits the title in place. A multi-value field's deltas share a key
 * the same way: the pointer edits the delta it hit.
 */
const entryAt = (target, key) => {
  if (engine === null || !key || !(target instanceof Element)) {
    return null;
  }
  const item = itemAt(target);
  return item !== null && keyForEditableItem(item) === key
    ? { item, key, panel: false }
    : null;
};

/**
 * The entry a press or double-click on a key lands on: the leaf under the
 * pointer when it commits the key, else the leaf the key owns.
 */
const entryForPress = (target, key) =>
  entryAt(target, key) ?? entryForKey(key);

/**
 * The one editor door (see the module docblock). A key that owns a canvas
 * leaf opens that leaf's editor; a key that owns none but names a subject
 * the engine can present (the root entity, a block placement) is
 * presented in the engine's panel and its first revealed widget becomes
 * the open edit (a subject whose fields all live in the panel would
 * otherwise present with nothing editing); a section or a foreign key
 * opens nothing. A POINTER gesture (the double-click) passes its target:
 * the leaf under the pointer edits when it commits the key (entryAt: the
 * theme's H1 for the root row, the delta hit of a multi-value field), else
 * the key road decides as for a keyless gesture. Failures are the door's
 * to report: every caller is a gesture, not a transaction.
 *
 * @param {?string} key
 *   A selectable key.
 * @param {?EventTarget} target
 *   The pointer target for a pointer gesture; null (the default) for a
 *   keyless one (Enter, the verb, the op queue's intent, a re-open).
 *
 * @return {?Promise<boolean>}
 *   Null when no engine is registered or the key is empty; else a flight
 *   resolving true when an editor opened (or a subject was presented) and
 *   false when the key has no editor here.
 */
export const openEditor = (key, target = null) => {
  if (engine === null || !key) {
    return null;
  }
  const entry = entryForPress(target, key);
  const opening = entry
    ? editItem(entry).then(() => true)
    : engine.presentSubject(key).then((presented) => {
        if (!presented) {
          return false;
        }
        const first = items().find((candidate) => candidate.panel);
        return first ? editItem(first).then(() => true) : true;
      });
  return opening.catch((error) => {
    console.error('Cannot edit element:', error);
    return false;
  });
};

/**
 * Commits and stays: the one door for every explicit commit gesture
 * (Cmd+Enter, the panel's Update button, leaving Edit Mode). A focused
 * panel widget's control is blurred first so its native change fires
 * (the widget's own commit trigger, whatever the transport); an open
 * canvas edit is committed through the engine, and an invalid widget
 * keeps the edit. The selection stays as it is: there is no session to
 * leave.
 *
 * @return {Promise<void>}
 */
export const commit = () => {
  if (focusedPanelEntry()) {
    document.activeElement.blur();
  }
  if (engine === null) {
    return Promise.resolve();
  }
  return engine.endEdit({ cancel: false }).catch(() => {
    // Invalid widget: the edit stays, the engine surfaces its own
    // feedback.
  });
};

const onTab = (direction) => (stroke) => {
  if (engine === null || stroke.consumed) {
    return false;
  }
  const editing = engine.isEditing();
  const focused = editing ? null : focusedPanelEntry();
  if (!editing && !focused) {
    // No edit open and no widget focused: Tab is the browser's.
    return false;
  }
  // Native Tab within the current item's inputs: only the edge input
  // advances to the neighboring item. The current item is the engine's
  // edit, or the panel widget holding focus.
  const current = editing ? engine.currentItem() : focused.item;
  const inputs = editing
    ? Array.from(engine.inputs() ?? [])
    : focusablesIn(focused.item);
  const index = inputs.indexOf(document.activeElement);
  if (index !== -1 && index !== (direction === 1 ? inputs.length - 1 : 0)) {
    return false;
  }
  const ended = editing ? engine.endEdit({ cancel: false }) : Promise.resolve();
  ended
    .then(() => {
      const next = neighbor(current, direction);
      if (next) {
        return editItem(next, direction);
      }
      return undefined;
    })
    .catch(() => {
      // Invalid widget: the edit stays, the engine surfaces its own
      // feedback.
    });
  return undefined;
};

/**
 * Escape cancels the open edit, and only that: the global ladder
 * (selector-events.js onEscape) is the next press's. Typing with no edit
 * open (the AI chat, a rename input) is not ours.
 */
const onEscape = (stroke) => {
  if (engine === null || stroke.consumed || !engine.isEditing()) {
    return false;
  }
  engine.endEdit({ cancel: true }).catch(() => {});
  return undefined;
};

/**
 * Cmd+Enter: the dialog's OK, the commit door. Commits the open edit (or
 * a focused panel widget) and stays, the selection kept. Not "commit and
 * keep editing": every commit round-trips and swaps the DOM under the
 * editor, CKEditor loses its caret on the rebuild, and an edit that stays
 * open gives no sign the commit happened. A widget that consumed the
 * keystroke wins, as for Tab.
 */
const onCommit = (stroke) => {
  if (
    engine === null ||
    stroke.consumed ||
    (!engine.isEditing() && !focusedPanelEntry())
  ) {
    return false;
  }
  commit();
  return undefined;
};

/**
 * The press decision while an edit is open, the ONE owner of "does this
 * press end the edit":
 *
 * | Target                                              | Result        |
 * |-----------------------------------------------------|---------------|
 * | a revealed panel widget                             | editItem      |
 * | current editor's own surface (engine.keepsEdit) or  | nothing       |
 * | chrome declaring data-keeps-edit (incl. .daedalus-ui-frame)  |               |
 * | another editable leaf the selection grammar names   | editItem      |
 * | anything else                                       | endEdit; the  |
 * |                                                     | selection     |
 * |                                                     | grammar lands |
 * |                                                     | the press     |
 *
 * A panel widget resolves FIRST, and with or without an open edit: the
 * entity-form panel declares data-keeps-edit for its non-widget chrome
 * (the header, the advanced panes, the Update button), but a press into a
 * revealed widget is a press into an editable item, the widget being its
 * own editor, and edits it exactly as the door would — one gesture, the
 * current edit committed by beginEdit. The press itself is left to the
 * widget (no preventDefault): the control it landed on must take focus
 * natively.
 *
 * The engine answers keepsEdit for its own surfaces (the editing element,
 * CK popups, dialogs); chrome that keeps an edit declares data-keeps-edit
 * on its own root (the Edit Mode toolbar, the daedalus_edit form panel, the
 * Daedalus Stylish inspector), and the selection frame's own chrome (.daedalus-ui-frame:
 * the chip, its verb face and widget popovers) always keeps it — the
 * frame anchors the edit's controls.
 *
 * The SWITCH resolves the press at the selection grammar's own depth
 * (depth-policy.js: the boundary, or the leaf under Cmd or the armed
 * picker; the drill context and the deepen rule as for any click) and
 * switches when the key it names has an editable leaf, the one under the
 * pointer first (entryForPress): a merged block or a drilled field edits
 * in one press while another edit is open, the theme's H1 the same, and
 * a nested leaf takes Cmd, the one depth signal. A Shift press builds the
 * set instead and is never a switch.
 *
 * Everything else commits the edit (an invalid widget rejects and keeps
 * it) and is otherwise left alone: the selection grammar's own click
 * handler runs after this one (both capture phase, boot order) and lands
 * the press where a click always lands, so inside the canvas the selection
 * follows the press or the keyless clear empties it, and outside the
 * canvas (the AI chat, the page tree, chrome) the selection stays. There
 * is no session to leave.
 */
const onClick = (event) => {
  if (engine === null) {
    return;
  }
  const target = event.target instanceof Element ? event.target : null;

  const panelEntry = target ? panelEntryAt(target) : null;
  if (panelEntry) {
    if (engine.isEditing() && engine.currentItem() === panelEntry.item) {
      return;
    }
    editItem(panelEntry).catch((error) => {
      console.error('Cannot edit element:', error);
    });
    return;
  }

  if (!engine.isEditing()) {
    return;
  }

  if (
    target &&
    (engine.keepsEdit(target) ||
      target.closest('[data-keeps-edit], .daedalus-ui-frame'))
  ) {
    if (target.closest('[data-selectable]')) {
      // The press landed inside an editable leaf of the current edit's
      // surface: the canvas click must not act natively (a link in the
      // edited rich text must not navigate).
      event.preventDefault();
    }
    return;
  }

  if (target && !event.shiftKey) {
    const state = store.getState().selection;
    const entry = entryForPress(
      target,
      resolve(target, {
        policy: policyFor(event, state.pickerArmed),
        drillKey: state.drillKey,
        selectedKeys: state.selectedKeys,
      }),
    );
    if (entry) {
      if (engine.currentItem() === entry.item) {
        return;
      }
      // Another editable leaf: one gesture — beginEdit commits the current
      // edit itself, then opens the next. The selection grammar's own
      // handler re-resolves the same key after this and finds it
      // committed already.
      editItem(entry).catch((error) => {
        console.error('Cannot edit element:', error);
      });
      return;
    }
  }

  engine.endEdit({ cancel: false }).catch(() => {
    // Invalid widget: the edit stays, the engine surfaces its own
    // feedback.
  });
};

/**
 * Enter opens the selection's editor: a leaf inline, a block or the root
 * in the panel, a section nothing. Selection context only, so an open
 * editor's Enter (typing) stays a newline, and a set has no single
 * editor to open.
 */
const onEnter = () => {
  const { selectedKey, selectedKeys } = store.getState().selection;
  if (engine === null || !selectedKey || selectedKeys.length !== 1) {
    return false;
  }
  openEditor(selectedKey);
  return undefined;
};

/**
 * Boots the open edit. Called once by the app entry, after hotkeys.
 */
export const initEditSession = () => {
  // Capture phase so the press decision runs before page handlers and
  // before the selection grammar's own click handler (attached after this
  // one at boot) see the press.
  document.addEventListener('click', onClick, true);

  // The open edit's grammar also declares the typing context so it keeps
  // working while focus is inside the active widget. Every action checks
  // the engine's state itself, so the typing side is a safe no-op
  // elsewhere. Listed locked in the settings tray: Tab/Escape/Cmd+Enter
  // are the edit's grammar, shown for discoverability but not open to
  // remapping.
  [
    {
      id: 'edit.next',
      key: 'tab',
      label: Drupal.t('Edit the next element'),
      action: onTab(1),
    },
    {
      id: 'edit.previous',
      key: 'shift+tab',
      label: Drupal.t('Edit the previous element'),
      action: onTab(-1),
    },
    {
      id: 'edit.cancel',
      key: 'escape',
      label: Drupal.t('Cancel the edit'),
      action: onEscape,
    },
    {
      id: 'edit.commit',
      key: 'mod+enter',
      label: Drupal.t('Commit the edit'),
      action: onCommit,
    },
  ].forEach((binding) => {
    registerHotkey({
      ...binding,
      when: ['editing', 'typing'],
      section: Drupal.t('Editing'),
    });
  });

  registerHotkey({
    id: 'edit.enter',
    key: 'enter',
    when: 'selection',
    label: Drupal.t('Edit the selected element'),
    section: Drupal.t('Editing'),
    remappable: true,
    action: onEnter,
  });

  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.EditSession = {
    registerEngine,
    // The one editor door, for chrome outside the bundle (the Edit verb,
    // the entity-form panel's toggle, the engine's validation re-open).
    openEditor,
    // The commit door (Cmd+Enter's twin) for chrome that commits
    // explicitly: the entity-form panel's Update button, the mode's exit.
    commit,
    // The engine's setter reports every edit open/close here (through the
    // registration seam's app.editSession.editChanged).
    editChanged,
    // Derived from the store's editingKey: the editChanged report is the
    // one writer, so this is the reactive truth every consumer shares.
    isEditing,
    // The applied seam: the op queue reports every fully applied response
    // here, and the engine resumes its draft while an edit is open.
    mutationApplied,
  };
};
