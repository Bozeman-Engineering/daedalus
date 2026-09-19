import store from '../store/store';
import {
  setHover,
  setSelected,
  setEditingKey,
  clearSelection,
} from '../store/slices/selection-slice';
import {
  resolveEditable,
  setEditableItemSelector,
  presentationBoundaryItem,
  keyForEditableItem,
} from '../selection/resolver';
import { register as registerHotkey } from '../hotkeys/hotkeys';

/**
 * The armed edit session — "I'm here to edit content" as a page-wide state.
 *
 * The session is armed while the registered engine's tool is the current
 * tool; there is no separate flag to drift out of sync. Two entrances, one
 * session: activating the Edit tool arms it, and the Edit verb on a field
 * or a merged block switches to the tool and immediately edits the
 * selection (on the root entity the same verb opens the entity-form panel
 * instead and never arms the session: the root has no canvas leaf of its
 * own). While armed, pointer
 * resolution uses the EDITABLE policy — a single click lands on the exact
 * editable leaf under the cursor, every other level of the stack is
 * transparent — and Tab / Shift+Tab walk the page's editable leaves in
 * document order. Escape and Cmd+Enter are the dialog pair: Escape cancels
 * the current edit first and exits the session (back to the Selector) on
 * the next press; Cmd+Enter is OK, committing the open edit and leaving
 * the session in one press, the selection kept.
 *
 * Under the engine's tool "selected" means "editing" (arming with a
 * selection opens its editor), so a commit that stayed armed would leave
 * the forbidden state "selected but not editing". Every gesture that
 * commits and does not open another edit therefore LEAVES the session
 * through one door, leaveSession(): Cmd+Enter, Escape's second press, and
 * a press into chrome that does not keep the edit (the AI chat, the page
 * tree). The tool switch runs the engine tool's own disable path, which
 * commits the open edit and refuses on an invalid widget, so leaving IS
 * committing.
 *
 * The app owns the session; the editing machinery stays in the module that
 * registers the ENGINE (daedalus_edit: form-swap, field plugins, tempstore
 * sync). The engine is registered through the same facade + ready-event
 * idiom as Verbs, and the registration carries its toolId so nothing here
 * hardcodes which tool arms the session.
 *
 * Keyboard model: Tab is "native, extended". Within the active widget's
 * inputs the browser moves focus as it would in any form; only at the edge
 * input does Tab commit the edit and materialize the next field's widget —
 * exactly what native Tab would do if every widget were rendered inline.
 * A widget that already consumed the keystroke (event.defaultPrevented:
 * CKEditor indenting a list, an autocomplete navigating options) wins.
 * Because typing outranks the armed-session context in the dispatcher,
 * Tab, Escape and Cmd+Enter register twice: once for the armed context,
 * once as unlisted typing-context twins that fire mid-edit.
 *
 * The applied seam: an open edit is a DRAFT the engine holds, and the
 * canvas is its projection. A foreign mutation (a style scrub, an undo, an
 * AI turn) swaps the edited markup out from under it; the op queue reports
 * the end of every applied response here (mutationApplied), and the
 * session forwards it to the engine while an edit is open so the engine
 * can re-project the draft over the fresh markup. The signal is "response
 * fully applied" (after the piggybacked commands, which may evict the very
 * form an edit rides), never the earlier mirror replace.
 */

/** @type {?object} The registered editing engine. */
let engine = null;

/**
 * The editItem flight in progress (its promise), or null. The outside
 * press reads it to tell "this press opened another edit" (a page-tree
 * row's gesture, whose form fetch may still be pending) from "this press
 * only committed", which decides whether the session leaves.
 *
 * @type {?Promise}
 */
let openingEdit = null;

/**
 * Registers the editing engine.
 *
 * @param {object} spec
 *   - toolId: the tool whose activation arms the session.
 *   - itemSelector: CSS selector matching the engine's editable leaf
 *     elements — the engine's vocabulary, injected into the resolver so
 *     nothing here names another module's attributes.
 *   - beginEdit: (item: Element) => Promise — load the editor for an
 *     editable leaf (an itemSelector element) and give it focus.
 *     Ends any current edit itself.
 *   - endEdit: ({cancel}) => Promise — commit (or cancel) the current
 *     edit. Rejects when the widget is invalid and the edit must stay.
 *   - isEditing: () => bool.
 *   - currentItem: () => ?Element — the leaf being edited, or null.
 *   - inputs: () => Element[]|NodeList — the active widget's focusable
 *     inputs, document order.
 *   - keepsEdit: (target: Element) => bool — whether a press on this
 *     element lands on the current edit's OWN surface (the editing
 *     element, editor-launched popups and dialogs), so the
 *     press decision (onClick) leaves the edit open. The engine answers
 *     only for its own surfaces; page chrome that keeps an edit declares
 *     data-keeps-edit on its root instead.
 *   - mutationApplied: () => ?Promise — a mutation response has been
 *     fully applied (markup swapped, mirror replaced, piggybacked commands
 *     played). Called only while an edit is open: the engine re-projects
 *     the edit's draft over the swapped-in markup, or drops the edit when
 *     the swap removed its leaf. The engine decides what "the draft" is;
 *     the session only tells it WHEN.
 *
 * Beyond the members, the engine owes the session a REPORT: its one
 * setter for the current edit calls editChanged(item) on every open and
 * close (through the registration seam's app.editSession.editChanged),
 * which is what keeps the store's editingKey — and everything derived
 * from it — truthful without the session polling.
 */
const registerEngine = (spec) => {
  const required = [
    'toolId',
    'itemSelector',
    'beginEdit',
    'endEdit',
    'isEditing',
    'currentItem',
    'inputs',
    'keepsEdit',
    'mutationApplied',
  ];
  if (!spec || required.some((member) => !spec[member])) {
    throw new Error(`Invalid edit session engine: ${JSON.stringify(spec)}`);
  }
  engine = spec;
  setEditableItemSelector(spec.itemSelector);
};

/**
 * The engine's report that the live edit changed — the reactive half of
 * the engine contract. The engine's ONE setter calls this on every edit
 * open/close with the leaf now being edited (null when the edit ended);
 * the store's editingKey is derived here through the same key gate every
 * editing door passes (keyForEditableItem over the presentation
 * boundary), so selection-anchored chrome re-renders from the edit's
 * REAL lifecycle, including on paths the session did not initiate
 * (Escape's cancel inside the widget, a markup swap ending an edit).
 */
const editChanged = (item) => {
  const key = item ? keyForEditableItem(presentationBoundaryItem(item)) : null;
  if (key !== store.getState().selection.editingKey) {
    store.dispatch(setEditingKey(key));
  }
};

/**
 * Whether the session is armed: the engine's tool is current.
 */
export const isArmed = () =>
  engine !== null && store.getState().tool.currentTool === engine.toolId;

/**
 * Leaves the session for the Selector, the selection kept: the one door
 * every commit-and-leave gesture walks through (Cmd+Enter, Escape's
 * second press, the outside press). The tool switch runs the engine
 * tool's own disable path, which commits an open edit and refuses (the
 * tool stays) on an invalid widget, so nothing here commits separately.
 */
const leaveSession = () => {
  Drupal.Daedalus.ModeManager.getPlugin('edit').changeTool('selector');
};

/**
 * The op queue's report that a mutation response has been FULLY applied
 * (the applied seam, see the module docblock). Forwarded to the engine
 * only while an edit is open; the engine owns the draft and its
 * re-projection, so the session carries nothing but the moment.
 *
 * @return {?Promise}
 *   The engine's resume promise, or undefined when nothing was open.
 */
export const mutationApplied = () => {
  if (engine !== null && engine.isEditing()) {
    return engine.mutationApplied();
  }
  return undefined;
};

/**
 * The page's editable leaves, document order. A presentation root's
 * subtree (a unit or merged element) contributes exactly ONE stop
 * (its boundary item), and a leaf whose committed key is outside the root
 * context's projection (no selectable wrapper, or a theme-region host's
 * attributed leaf that renders on this page but lives in another root's
 * blueprint) is no stop at all: keyForEditableItem is the one gate, so
 * Tab lands exactly where a click would and never leaves the universe.
 */
const editableItems = () =>
  Array.from(document.querySelectorAll(engine.itemSelector)).filter(
    (item) =>
      presentationBoundaryItem(item) === item &&
      keyForEditableItem(item) !== null,
  );

/**
 * Selects an editable leaf's key and opens its editor. Inside a merged
 * presentation root the committed key is the root's own (the merged node
 * absorbs its wrapper chain) while the edit still targets the leaf item;
 * the root's edit-target leaf (the label, wherever it renders) commits the
 * root entity key the same way. Entering backwards (Shift+Tab) lands focus
 * on the widget's last input — the position native Tab would arrive at.
 */
const editItem = (item, direction = 1) => {
  const key = keyForEditableItem(item);
  if (key && key !== store.getState().selection.selectedKey) {
    store.dispatch(setSelected(key));
  }
  const opening = engine.beginEdit(item).then(() => {
    if (direction === -1) {
      const inputs = Array.from(engine.inputs() ?? []);
      inputs[inputs.length - 1]?.focus();
    }
  });
  openingEdit = opening;
  const settled = () => {
    if (openingEdit === opening) {
      openingEdit = null;
    }
  };
  // Both arms: the caller owns the rejection, this only tracks the flight.
  opening.then(settled, settled);
  return opening;
};

/**
 * The leaf `direction` steps from `from` in document order, or the first /
 * last leaf when there is no reference point.
 */
const neighborItem = (from, direction) => {
  const items = editableItems();
  if (!items.length) {
    return null;
  }
  const index = from ? items.indexOf(from) : -1;
  if (index === -1) {
    return direction === 1 ? items[0] : items[items.length - 1];
  }
  return items[index + direction] ?? null;
};

/**
 * The editable leaf a KEY owns: the leaf a click or the Edit verb would
 * edit for that selection. A key that is itself a presentation root (a
 * unit media reference, a merged block — whose owned leaves sit under
 * DEEPER selectable wrappers) yields its boundary item. Null when the key
 * owns no editable leaf (a container, a region, a foreign host's key, the
 * root entity: its editor is the entity-form panel, and where a theme
 * prints the label as an H1 leaf that leaf commits the root key on its
 * own click, see rootKeyForEditTarget; the row itself owns nothing).
 */
const itemForKey = (key) => {
  const wrapper = key
    ? document.querySelector(`[data-selectable="${key}"]`)
    : null;
  if (!wrapper) {
    return null;
  }
  if (wrapper.hasAttribute('data-presentation')) {
    // A collapsed block IS its own editable item (leaf-is-root markup);
    // querySelector alone cannot match self.
    const inner = wrapper.matches(engine.itemSelector)
      ? wrapper
      : wrapper.querySelector(engine.itemSelector);
    return inner ? presentationBoundaryItem(inner) : null;
  }
  if (wrapper.matches(engine.itemSelector)) {
    return wrapper;
  }
  return (
    Array.from(wrapper.querySelectorAll(engine.itemSelector)).find(
      (item) => item.closest('[data-selectable]') === wrapper,
    ) ?? null
  );
};

/**
 * The leaf traversal continues from while not editing: the committed
 * selection's own editable leaf, if any.
 */
const selectionItem = () => itemForKey(store.getState().selection.selectedKey);

/**
 * The editable leaf an armed edit on a key would land on, or null:
 * editability as a static per-element fact, never a focus fact. The
 * key's wrapper must own an engine item whose presentation boundary
 * passes the projection gate (keyForEditableItem), the same filter Tab
 * traversal applies, so a container, a region, or a foreign host's
 * leaf yields null and stays a plain selection to the armed session.
 * editItemForKey is the gesture over this fact; the frame's dash reads
 * the store's editingKey instead (the LIVE edit, never a prediction).
 */
const editableItemForKey = (key) => {
  if (engine === null || !key) {
    return null;
  }
  const item = itemForKey(key);
  if (item === null) {
    return null;
  }
  const boundary = presentationBoundaryItem(item);
  return keyForEditableItem(boundary) !== null ? boundary : null;
};

/**
 * Opens the editor for a key's editable leaf: the armed gesture's
 * programmatic twin, so panel surfaces (a tree row's click, its
 * Enter/Space activation) commit the same gesture a canvas click does.
 * Returns the editItem promise, or null when the key owns no editable
 * leaf (the caller falls back to plain selection).
 */
export const editItemForKey = (key) => {
  const item = editableItemForKey(key);
  return item ? editItem(item) : null;
};

const onTab = (direction) => (event) => {
  if (!isArmed() || event.defaultPrevented) {
    return false;
  }
  if (engine.isEditing()) {
    // Native Tab within the active widget: only the edge input advances to
    // the neighboring field.
    const inputs = Array.from(engine.inputs() ?? []);
    const index = inputs.indexOf(document.activeElement);
    if (index !== -1 && index !== (direction === 1 ? inputs.length - 1 : 0)) {
      return false;
    }
    const current = engine.currentItem();
    engine
      .endEdit({ cancel: false })
      .then(() => {
        const next = neighborItem(current, direction);
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
  }
  const next = neighborItem(selectionItem(), direction);
  if (!next) {
    return false;
  }
  editItem(next, direction);
  return undefined;
};

const onEscape = (event) => {
  if (!isArmed() || event.defaultPrevented) {
    return false;
  }
  if (engine.isEditing()) {
    engine.endEdit({ cancel: true }).catch(() => {});
    return undefined;
  }
  // Second press: exit to plain selection.
  leaveSession();
  return undefined;
};

/**
 * Cmd+Enter: the dialog's OK. Commits the open edit and leaves the session
 * for the Selector with the selection kept; armed without an edit it
 * leaves the same way (Escape's second branch, one press). Not "commit
 * and keep editing": every commit round-trips and swaps the DOM under the
 * editor, CKEditor loses its caret on the rebuild, and an edit that stays
 * open gives no sign the commit happened. A widget that consumed the
 * keystroke wins, as for Tab.
 */
const onCommit = (event) => {
  if (!isArmed() || event.defaultPrevented) {
    return false;
  }
  leaveSession();
  return undefined;
};

/**
 * The armed pointer grammar. Hover and click resolve with the EDITABLE
 * policy; a click opens the leaf's editor directly — selection and editing
 * are one gesture here, unlike the Selector's select-then-verb.
 */
const onMouseMove = (event) => {
  if (!isArmed()) {
    return;
  }
  if (
    event.target instanceof Element &&
    event.target.closest('[data-selection-hover-scope]')
  ) {
    return;
  }
  const key = resolveEditable(event.target)?.key ?? null;
  if (key !== store.getState().selection.hoverKey) {
    store.dispatch(setHover(key));
  }
};

/**
 * The press decision. While armed with an edit open, this handler is the
 * ONE owner of "does this press end the edit" (the old per-editor
 * mousedown watcher is gone):
 *
 * | Target                                              | Result        |
 * |-----------------------------------------------------|---------------|
 * | current editor's own surface (engine.keepsEdit) or  | nothing       |
 * | chrome declaring data-keeps-edit (incl. .daedalus-ui-frame)  |               |
 * | another editable leaf                               | editItem      |
 * | canvas with nothing editable                        | endEdit,      |
 * |                                                     | clear select. |
 * | anything else outside the canvas (chat, tree, page) | endEdit, then |
 * |                                                     | leaveSession; |
 * |                                                     | select. stays |
 *
 * The engine answers keepsEdit for its own surfaces (the editing element,
 * CK popups, dialogs); chrome that keeps an edit declares data-keeps-edit
 * on its own root (the Edit Mode toolbar, the daedalus_edit form panel, the
 * Daedalus Stylish inspector), and the selection frame's own chrome (.daedalus-ui-frame:
 * the chip, its verb face and widget popovers) always keeps it — the
 * frame anchors the edit's controls. Everything else commits the edit (an
 * invalid widget rejects and keeps it, and the session with it). The AI
 * chat deliberately declares nothing, so pressing into it commits the
 * edit; because the selection stays and the session forbids "selected
 * but not editing", the outside press then leaves for the Selector.
 */
const onClick = (event) => {
  if (!isArmed()) {
    return;
  }
  const target = event.target instanceof Element ? event.target : null;
  const resolved = resolveEditable(event.target);

  if (
    engine.isEditing() &&
    target &&
    (engine.keepsEdit(target) || target.closest('[data-keeps-edit], .daedalus-ui-frame'))
  ) {
    if (resolved) {
      // The press landed on (or inside) an editable leaf of the current
      // edit's surface: the canvas click must not act natively (a link in
      // the edited rich text must not navigate).
      event.preventDefault();
      event.stopPropagation();
    }
    return;
  }

  if (resolved) {
    event.preventDefault();
    event.stopPropagation();
    if (engine.isEditing() && engine.currentItem() === resolved.item) {
      return;
    }
    // Another editable leaf: one gesture — beginEdit commits the current
    // edit itself, then opens the next.
    editItem(resolved.item).catch((error) => {
      console.error('Cannot edit element:', error);
    });
    return;
  }

  // Nothing editable under the press: an open edit commits (an invalid
  // widget rejects and keeps it). Inside the canvas the selection clears
  // and the session stays armed; outside it the selection stays, so the
  // session leaves for the Selector once the commit went through.
  if (target && target.closest('[data-main-entity]')) {
    if (engine.isEditing()) {
      engine.endEdit({ cancel: false }).catch(() => {});
    }
    store.dispatch(clearSelection());
    return;
  }
  if (!engine.isEditing()) {
    return;
  }
  engine
    .endEdit({ cancel: false })
    .then(() => {
      // Chrome that opens edits (a page-tree row) runs its own gesture in
      // the bubble phase, AFTER this capture-phase commit, through the
      // session's editItem door; leaving before it ran would close the
      // edit it opens. Decide once the click has fully dispatched: a
      // press that opened (or is opening) another edit stays armed, any
      // other outside press leaves.
      window.setTimeout(() => {
        if (isArmed() && !engine.isEditing() && openingEdit === null) {
          leaveSession();
        }
      }, 0);
    })
    .catch(() => {
      // Invalid widget: the edit and the session stay, the engine
      // surfaces its own feedback.
    });
};

/**
 * Boots the session. Called once by the app entry, after hotkeys.
 */
export const initEditSession = () => {
  document.addEventListener('mousemove', onMouseMove, { passive: true });
  // Capture phase so an armed click wins before page handlers see it.
  document.addEventListener('click', onClick, true);

  // The armed-session bindings also declare the typing context so they
  // keep working while focus is inside the active widget. All actions
  // check isArmed() themselves, so the typing side is a safe no-op
  // elsewhere.
  [
    {
      id: 'edit_session.next',
      key: 'tab',
      label: Drupal.t('Edit the next element'),
      action: onTab(1),
    },
    {
      id: 'edit_session.previous',
      key: 'shift+tab',
      label: Drupal.t('Edit the previous element'),
      action: onTab(-1),
    },
    {
      id: 'edit_session.escape',
      key: 'escape',
      label: Drupal.t('Cancel the edit'),
      action: onEscape,
    },
    {
      id: 'edit_session.commit',
      key: 'mod+enter',
      label: Drupal.t('Commit the edit'),
      action: onCommit,
    },
  ].forEach((binding) => {
    // Listed locked in the settings tray: Tab/Escape/Cmd+Enter are the
    // session's grammar, shown for discoverability but not open to
    // remapping.
    registerHotkey({
      ...binding,
      when: ['armed-session', 'typing'],
      section: Drupal.t('Editing'),
    });
  });

  window.listenToStateChange(
    (state) => state.tool.currentTool,
    () => {
      if (isArmed()) {
        // Entering the session with a field selected opens its editor
        // immediately — this IS the Edit verb's behavior on a field or a
        // merged block; there the verb itself just switches tools. A
        // selection that owns no editable leaf (section, block, the root
        // entity) arms quietly.
        const item = selectionItem();
        if (item) {
          editItem(item).catch((error) => {
            console.error('Cannot edit element:', error);
          });
        }
      } else if (engine && store.getState().selection.hoverKey) {
        // Leaving the armed tool drops the transient hover, matching the
        // Selector's behavior.
        store.dispatch(setHover(null));
      }
    },
  );

  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.EditSession = {
    registerEngine,
    isArmed,
    editItemForKey,
    // The engine's setter reports every edit open/close here (through the
    // registration seam's app.editSession.editChanged).
    editChanged,
    // Derived from the store's editingKey: the editChanged report is the
    // one writer, so this is the reactive truth every consumer shares.
    isEditing: () => store.getState().selection.editingKey !== null,
    // The applied seam: the op queue reports every fully applied response
    // here, and the engine resumes its draft while an edit is open.
    mutationApplied,
  };
};
