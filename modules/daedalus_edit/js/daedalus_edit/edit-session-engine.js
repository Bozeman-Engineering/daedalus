import * as entityForm from './entity-form.js';
import * as editableElement from './editable-element.js';
import { bindEditChangeReporting } from './currently-editing.js';
import { BlueprintEditableElement } from './blueprint-element.js';

/**
 * daedalus_edit's editing engine for the daedalus_ui armed edit session.
 *
 * The app owns the session — arming, pointer resolution, Tab traversal,
 * Escape — and calls this engine to do the actual editing. The armed
 * session holds exactly ONE engine, so this engine is THE engine and
 * routes internally per leaf kind:
 * - Form-edited leaves ([data-daedalus-edit-id]): the module's existing
 *   form-swap machinery — the real Drupal widget is still the editor,
 *   tempstore still receives the changes, field plugins still own the
 *   gestures.
 * - Blueprint-edited leaves ([data-daedalus-edit-editor]): no form is fetched;
 *   the leaf's declared editor edits in place and commits through the
 *   blueprint mutation spine.
 * The Tab/Escape/click grammar is shared across both kinds for free.
 *
 * The draft handoff: an open edit is a DRAFT the editor holds, the canvas
 * its projection. A foreign mutation's response (a style scrub from the
 * Daedalus Stylish inspector, an undo, an AI turn) swaps the edited subtree; the
 * op queue detaches behaviors on the replaced element first, which is
 * where DaedalusEditDraft captures the editor's draft onto the leaf state
 * object, and reports the end of the whole application through the
 * session (mutationApplied), where this engine hands the draft back to
 * the editor's resume() over the fresh markup, or drops the edit when the
 * swap removed its leaf. The editor owns what a draft is and how it is
 * re-projected (the three-way rule on BlueprintEditorBase); the engine
 * only carries it across the swap.
 */

/**
 * Opens the declared editor for a blueprint-edited leaf: no form fetch,
 * straight to the plugin's edit(). Commits any current edit first (an
 * invalid form widget keeps its edit; the new one stays closed).
 */
const beginBlueprintEdit = (item) => {
  const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  if (current?.getPageElement() === item) {
    // The leaf is already being edited in place.
    return Promise.resolve();
  }
  const leaf = new BlueprintEditableElement(item);
  if (current) {
    return current.plugin
      .blur()
      .then(() => leaf.plugin.edit(leaf))
      .catch((error) => {
        if (error !== 'HTML validation failed') {
          throw error;
        }
      });
  }
  leaf.plugin.edit(leaf);
  return Promise.resolve();
};

const engine = {
  toolId: 'edit',

  // The editable leaves: per-delta field items carrying the 9-part edit
  // identity (form-edited), and composed elements declaring their editor
  // (blueprint-edited). The session injects this into the app's resolver,
  // so the attribute names stay this module's vocabulary.
  itemSelector: '[data-daedalus-edit-id], [data-daedalus-edit-editor]',

  /**
   * Opens the editor for an editable leaf, routed per leaf kind (see the
   * module docblock). Commits any current edit first.
   *
   * Resolves once the widget is editing; resolves without editing when the
   * leaf refuses (already editing as contenteditable, a pending invalid
   * widget, or an in-flight update).
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
    if (item.hasAttribute('data-daedalus-edit-editor')) {
      return beginBlueprintEdit(item);
    }
    const EditableElement = new editableElement.EditableElement(
      item.dataset.daedalusEditId,
    );
    // Prevent edits on a form item that is currently being updated.
    if (
      EditableElement.getPageElementHandle().classList.contains(
        'disabled-updating',
      )
    ) {
      return Promise.resolve();
    }
    return entityForm.getForm(EditableElement).then(() => {
      Drupal.displace();
      // Set the widget now that the form is loaded.
      EditableElement.setWidget();
      const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
      if (current) {
        return current.plugin
          .blur()
          .then(() => EditableElement.plugin.edit(EditableElement))
          .catch((error) => {
            // An invalid widget keeps its edit; the new one stays closed.
            if (error !== 'HTML validation failed') {
              throw error;
            }
          });
      }
      EditableElement.plugin.edit(EditableElement);
      return undefined;
    });
  },

  /**
   * Ends the current edit: commit on blur, discard on cancel. Rejects with
   * 'HTML validation failed' when the widget is invalid and the edit must
   * stay open.
   */
  endEdit: ({ cancel = false } = {}) => {
    const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!current) {
      return Promise.resolve();
    }
    if (cancel) {
      return current.plugin.cancelEdit(current);
    }
    return current.plugin.blur();
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
   * Nothing happens unless DaedalusEditDraft captured a draft for the current
   * edit: a swap that never touched the edited subtree leaves the edit
   * alone. The draft is consumed here (one capture, one resume). A leaf
   * the swap removed has nothing to resume onto: the edit is dropped
   * without committing.
   *
   * @return {?Promise<void>}
   *   The editor's resume (or cancel) flight, or undefined.
   */
  mutationApplied: () => {
    const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!editing || editing.draft === undefined) {
      return undefined;
    }
    const { draft } = editing;
    editing.draft = undefined;
    if (!editing.getPageElement()) {
      return editing.plugin.cancelEdit(editing);
    }
    return editing.plugin.resume(editing, draft);
  },
};

/**
 * Captures the open edit's draft as a markup swap detaches its subtree.
 *
 * The op queue detaches behaviors (trigger 'unload') on the element it is
 * about to replace, while the editing surface still stands: the one
 * moment the editor can still read what the user typed. The draft rides
 * the leaf state object until mutationApplied() hands it to resume().
 * Every other detach (a panel widget's #ajax rebuild, a dialog closing,
 * the 'serialize' trigger of a form submit) misses the page element and
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

export const registerEditSessionEngine = (app) => {
  // The engine contract's reactive half: the one setter
  // (currently-editing.js) reports every edit open/close through
  // app.editSession.editChanged, which keeps the app's editingKey — and
  // everything derived from it — truthful without polling.
  bindEditChangeReporting(app);
  app.editSession.registerEngine(engine);
};
