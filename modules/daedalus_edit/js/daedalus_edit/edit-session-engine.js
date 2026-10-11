import * as entityForm from './entity-form.js';
import * as editableElement from './editable-element.js';
import { bindEditChangeReporting } from './currently-editing.js';
import { BlueprintEditableElement } from './blueprint-element.js';
import { PanelWidgetElement } from './panel-widget-element.js';
import {
  formCacheMount,
  panelItems,
  formFieldKeys,
  isPanelItem,
  presentSubject,
  hasCanvasLeaf,
} from './panel-items.js';
import { targetedKeys } from './field-state.js';
import { formCommitInFlight } from './transports.js';
import { reconcilePanelWidgets } from './panel-widget-visibility.js';

/**
 * daedalus_edit's editing engine for the daedalus_ui open edit.
 *
 * The app owns the open edit — the editor door, the press decision, Tab
 * traversal, Escape — and calls this engine to do the actual editing. The
 * edit session holds exactly ONE engine, so this engine is THE engine and
 * routes internally per item kind, each kind a state object over the one
 * editor base (plugins/editor-plugin-base.js):
 * - Form-edited leaves ([data-daedalus-edit-id], EditableElement): the
 *   real Drupal widget is the value store, the leaf's editor (by widget)
 *   the surface, the form transport the commit.
 * - Blueprint-edited leaves ([data-daedalus-edit-editor],
 *   BlueprintEditableElement): no form is fetched; the leaf's declared
 *   editor edits in place over the mirror and commits a value op.
 * - Panel widgets (a revealed form-item wrapper in the entity-form panel,
 *   PanelWidgetElement, panel-items.js): the widget IS the editor
 *   (plugins/panel-widget-editor.js), and the form transport commits it.
 * The Tab/Escape/click grammar is shared across all kinds for free: the
 * engine's items() hands the session the canvas leaves and the panel's
 * keyed widgets, and the session walks them as one list.
 *
 * The applied seam: an open edit is a DRAFT the editor holds, the canvas
 * its projection. A foreign mutation's response (a style scrub from the
 * Daedalus Stylish inspector, an undo, an AI turn) swaps the edited
 * subtree; the op queue detaches behaviors on the replaced element first,
 * which is where DaedalusEditDraft captures the editor's draft onto the
 * item, and reports the end of the whole application through the session
 * (mutationApplied, with the payload). Here the panel is refreshed from
 * the payload's targets (a write to a field whose widget the panel shows
 * left that widget stale: the subject form is refetched, the client-side
 * successor of server-side form eviction), and then the draft is handed
 * back to the editor's resume() over the fresh markup and the fresh
 * form, or the edit dropped when the swap removed its leaf.
 */

/**
 * The canvas leaves: per-delta field items carrying the 9-part edit
 * identity (form-edited), and composed elements declaring their editor
 * (blueprint-edited). The engine's vocabulary; the session sees only the
 * elements items() lists.
 */
const LEAF_SELECTOR = '[data-daedalus-edit-id], [data-daedalus-edit-editor]';

/**
 * A subject form whose refresh waits for the open panel-widget edit to
 * end: a refetch would pull the widget out from under the typing user for
 * a field that is not the one being edited.
 *
 * @type {?Element}
 */
let deferredRefresh = null;

/**
 * The canvas leaves in document order. A render inside the form-cache
 * mount (a widget's own entity preview) is not the canvas.
 *
 * @return {Element[]}
 */
const canvasLeaves = () => {
  const mount = formCacheMount();
  return Array.from(document.querySelectorAll(LEAF_SELECTOR)).filter(
    (leaf) => !mount || !mount.contains(leaf),
  );
};

/**
 * Commits any current edit, then opens an item's edit. An invalid widget
 * keeps its edit; the new one stays closed.
 */
const openAfterCurrent = (open) => {
  const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  if (current) {
    return current.plugin
      .blur()
      .then(open)
      .catch((error) => {
        if (error !== 'HTML validation failed') {
          throw error;
        }
      });
  }
  open();
  return Promise.resolve();
};

/**
 * Opens the declared editor for a blueprint-edited leaf: no form fetch,
 * straight to the plugin's edit().
 */
const beginBlueprintEdit = (item) => {
  const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  if (current?.getPageElement() === item) {
    // The leaf is already being edited in place.
    return Promise.resolve();
  }
  const leaf = new BlueprintEditableElement(item);
  return openAfterCurrent(() => leaf.plugin.edit(leaf));
};

/**
 * Opens a panel widget's edit: the widget is the editor, already visible.
 */
const beginPanelEdit = (item) => {
  const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  if (current?.getPageElement() === item) {
    return Promise.resolve();
  }
  const widget = new PanelWidgetElement(item);
  return openAfterCurrent(() => widget.plugin.edit(widget));
};

/**
 * Runs the refresh a panel-widget edit deferred, once that edit is over.
 */
const flushDeferredRefresh = () => {
  const form = deferredRefresh;
  deferredRefresh = null;
  if (form?.isConnected) {
    entityForm.refetchForm(form).catch((error) => {
      console.error('Daedalus Edit: the deferred panel refresh failed.', error);
    });
  }
};

/**
 * Refreshes the cached forms from a response's targets.
 *
 * A cached form is refetched when the targets name a field it carries a
 * widget for, hidden or revealed (formFieldKeys): a foreign write leaves
 * that widget stale whatever the panel shows of it now, and the next edit
 * (a leaf's editor reads its widget as the value store, a resume reads
 * the remote from it, the panel reveals it) would otherwise start from
 * the old value. Judged over every wrapper rather than the revealed ones
 * because the response's own swap has already re-judged the panel by the
 * time this runs (a field the write materialized is hidden now). A form
 * commit's own response is exempt: the field it wrote is exactly the one
 * the user is done with. While a panel widget edit is open on some OTHER
 * field of a form, that form's refresh waits for the edit to end.
 *
 * @param {Object} payload
 *   The applied mutation-response payload.
 *
 * @return {Promise<void>}
 */
const refreshFromTargets = async (payload) => {
  const mount = formCacheMount();
  if (!mount) {
    return;
  }
  const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  const editingWrapper = editing?.getFormItemWrapper?.() ?? null;
  const forms = Array.from(
    mount.querySelectorAll(':scope > .daedalus-edit-form'),
  ).filter((form) => formCommitInFlight() !== form.dataset.daedalusEditFormId);
  for (const form of forms) {
    const stale = targetedKeys(payload?.targets, formFieldKeys(form));
    if (stale.length === 0) {
      continue;
    }
    if (
      editing instanceof PanelWidgetElement &&
      editingWrapper &&
      form.contains(editingWrapper) &&
      !stale.includes(editing.sessionKey)
    ) {
      deferredRefresh = form;
      continue;
    }
    // eslint-disable-next-line no-await-in-loop
    await entityForm.refetchForm(form);
  }
};

const engine = {
  /**
   * The engine's items: the canvas leaves in document order, and the
   * entity-form panel's revealed widgets in form order, keyed
   * (panel-items.js). The session merges them into its one ordered list.
   */
  items: () => ({
    canvas: canvasLeaves(),
    panel: panelItems(),
  }),

  /**
   * Opens the editor for an item, routed per item kind (see the module
   * docblock). Commits any current edit first.
   *
   * Resolves once the widget is editing; resolves without editing when the
   * leaf refuses (already editing as contenteditable, or a pending invalid
   * widget).
   */
  beginEdit: (item) => {
    if (item.hasAttribute('contenteditable')) {
      // The leaf is already being text-edited in place.
      // @see textfield.js
      return Promise.resolve();
    }
    if (Drupal.Daedalus.Edit.hasInvalidFormItem()) {
      Drupal.Daedalus.Edit.warnAboutInvalidFormItem();
      return Promise.resolve();
    }
    if (isPanelItem(item)) {
      return beginPanelEdit(item);
    }
    if (item.hasAttribute('data-daedalus-edit-editor')) {
      return beginBlueprintEdit(item);
    }
    const EditableElement = new editableElement.EditableElement(
      item.dataset.daedalusEditId,
    );
    return entityForm.getForm(EditableElement).then(() => {
      Drupal.displace();
      // Set the widget now that the form is loaded.
      EditableElement.setWidget();
      return openAfterCurrent(() =>
        EditableElement.plugin.edit(EditableElement),
      );
    });
  },

  /**
   * Ends the current edit: commit on blur, discard on cancel. Rejects with
   * 'HTML validation failed' when the widget is invalid and the edit must
   * stay open. A panel refresh the edit deferred runs once it is over.
   */
  endEdit: ({ cancel = false } = {}) => {
    const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!current) {
      return Promise.resolve();
    }
    const ended = cancel
      ? current.plugin.cancelEdit(current)
      : current.plugin.blur();
    return ended.then((result) => {
      flushDeferredRefresh();
      return result;
    });
  },

  /**
   * Whether the current edit can end now: the selection write door's
   * synchronous question before it moves the selection off the edit. The
   * one refusal is a form-path edit whose widget is invalid (a wrapper
   * carrying an error class, an input failing checkValidity); the same
   * warning beginEdit and the sidebar show is shown here, since the user
   * must fix that widget before anything else edits or selects. A
   * blueprint-edited leaf has no widget to be invalid, so it always ends.
   */
  canEndEdit: () => {
    if (Drupal.Daedalus.Edit.hasInvalidFormItem()) {
      Drupal.Daedalus.Edit.warnAboutInvalidFormItem();
      return false;
    }
    return true;
  },

  isEditing: () => !!Drupal.Daedalus.Edit.CurrentlyEditingElement,

  /**
   * Whether a press on this element lands on the current edit's own
   * surface. Answered from the active plugin's getFocusedSelectors() —
   * the editor-owned surfaces only (editing element, CK popups,
   * dialogs, media library); chrome that keeps an edit declares
   * data-keeps-edit on its own root, which the session checks itself.
   */
  keepsEdit: (target) => {
    const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!current) {
      return false;
    }
    return current.plugin
      .getFocusedSelectors()
      .some((selector) => target.closest(selector) !== null);
  },

  currentItem: () =>
    Drupal.Daedalus.Edit.CurrentlyEditingElement?.getPageElement() ?? null,

  inputs: () => {
    const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    return current ? current.plugin.inputs(current) : [];
  },

  /**
   * A mutation response has been fully applied (see the module docblock).
   *
   * The panel first: the response's targets decide whether the subject
   * form is refetched, and the open edit's resume must see the fresh form
   * (its widget is the value store the remote is read from). Then the
   * edit: a panel widget whose field the response gave a canvas leaf (a
   * sample fill, its own commit of a value) ends, because the leaf edits
   * now; a leaf the swap removed has nothing to resume onto, so its edit
   * is dropped without committing; otherwise the draft, if
   * DaedalusEditDraft captured one for the current edit, resumes (a swap
   * that never touched the edited subtree leaves the edit alone). The
   * draft is consumed here (one capture, one resume). An ended edit's
   * wrapper was exempt from the swap's own attach, so the panel is judged
   * again afterwards.
   *
   * @param {Object} payload
   *   The applied payload.
   *
   * @return {Promise<void>}
   */
  mutationApplied: async (payload) => {
    await refreshFromTargets(payload).catch((error) => {
      console.error('Daedalus Edit: the panel refresh failed.', error);
    });
    const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!editing) {
      return;
    }
    const wrapper = editing.getFormItemWrapper?.() ?? null;
    if (
      editing instanceof PanelWidgetElement &&
      wrapper &&
      hasCanvasLeaf(wrapper)
    ) {
      editing.draft = undefined;
      await editing.plugin.blur().catch(() => {});
      reconcilePanelWidgets();
      return;
    }
    if (editing.draft === undefined) {
      return;
    }
    const { draft } = editing;
    editing.draft = undefined;
    if (!editing.getPageElement()) {
      await editing.plugin.cancelEdit(editing);
      reconcilePanelWidgets();
      return;
    }
    await editing.plugin.resume(editing, draft);
  },

  presentSubject,
};

/**
 * Captures the open edit's draft as a markup swap detaches its subtree.
 *
 * The op queue detaches behaviors (trigger 'unload') on the element it is
 * about to replace, while the editing surface still stands: the one
 * moment the editor can still read what the user typed. The draft rides
 * the item until mutationApplied() hands it to resume(). Every other
 * detach (a panel widget's #ajax rebuild, a dialog closing, the
 * 'serialize' trigger of a form submit) misses the page element and
 * captures nothing.
 *
 * @see swapMarkup() in daedalus_ui/js/edit_mode/ops/op-queue.js
 */
Drupal.behaviors.DaedalusEditDraft = {
  detach(context, settings, trigger) {
    if (trigger !== 'unload') {
      return;
    }
    const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!editing) {
      return;
    }
    const pageElement = editing.getPageElement();
    // contains() is inclusive: the context may be the page element itself.
    if (
      !pageElement ||
      !(context instanceof Element) ||
      !context.contains(pageElement)
    ) {
      return;
    }
    editing.draft = editing.plugin.captureDraft(editing);
  },
};

/**
 * The panel's Update button is the edit's commit door, Cmd+Enter's
 * visible twin: it commits the open edit and stays, the selection kept
 * (daedalus_ui edit-session.js commit()). It is never the form's
 * own submit: the native press would run the AJAX submit Drupal bound to
 * the button (the whole form, naming no field), so both the mousedown
 * (Drupal's AJAX trigger) and the click (the form submit, and keyboard
 * activation) are taken here in the capture phase. The form transport's
 * own round (transports.js) triggers the button through a custom jQuery
 * event, which no native listener sees.
 */
const bindUpdateButton = (app) => {
  const onPress = (event) => {
    if (
      !(event.target instanceof Element) ||
      !event.target.closest('.daedalus-edit-update-button')
    ) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    if (event.type === 'click') {
      app.EditSession.commit();
    }
  };
  document.addEventListener('mousedown', onPress, true);
  document.addEventListener('click', onPress, true);
};

export const registerEditSessionEngine = (app) => {
  // The engine contract's reactive half: the one setter
  // (currently-editing.js) reports every edit open/close through
  // app.editSession.editChanged, which keeps the app's editingKey — and
  // everything derived from it — truthful without polling.
  bindEditChangeReporting(app);
  bindUpdateButton(app);
  app.editSession.registerEngine(engine);
};
