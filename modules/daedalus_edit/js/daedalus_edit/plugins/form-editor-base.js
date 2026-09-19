import { EditorPluginBase } from './editor-plugin-base.js';
import { setCurrentlyEditing } from '../currently-editing.js';
import * as entityForm from '../entity-form.js';
import { equals, mirrorValue } from '../mirror-value.js';

/**
 * Form editor base.
 *
 * The entity-form commit path: the leaf's editor is the field's real Drupal
 * widget, revealed in the form panel (or swapped over the page element), and
 * blur commits by submitting the form's AJAX update to tempstore. This is
 * the base class for editors of form-edited leaves ([data-daedalus-edit-id]).
 *
 * The open edit is a DRAFT (the draft contract on EditorPluginBase). A
 * foreign mutation's response (a style scrub from the Daedalus Stylish inspector, an
 * undo, an AI turn) swaps the edited markup and, when the mutation changed
 * the owning entity's values, evicts its cached form so the next submit
 * cannot clobber the change with a stale whole-entity write (the form
 * submit writes the form's WHOLE cached entity to tempstore). The engine
 * captures the draft as the swap detaches the leaf and hands it to resume()
 * once the response has fully applied, eviction and all. resume() puts the
 * form back (waiting on the eviction's own refetch when one is in flight,
 * fetching otherwise) and decides by the three-way rule with the mirror as
 * the judge: base = the mirror value at edit open (the snapshot), remote =
 * the mirror value now. remote != base means the remote changed the very
 * field being edited, and it wins by ENDING the edit (concede): the fresh
 * form and the swapped-in markup already hold the remote value, and the
 * panel widget was the draft store, so there is nothing to project.
 * Otherwise the edit re-opens on the swapped-in page element and the
 * editor re-seeds its draft (reseed) where the surface can carry one: the
 * textfield's in-place text and the inline editor's canvas CKEditor. A
 * panel widget (default, media) keeps its typed value while its form
 * survives; when a style-only mutation evicts the form (base == remote),
 * the refetched widget is fresh and the draft was only in the old widget,
 * so it is lost: the accepted loss of the form path, since the rule cannot
 * re-seed a widget it cannot read.
 *
 * @see ../mirror-value.js
 * @see blueprint-editor-base.js (the blueprint commit path's three-way rule)
 */
export class FormEditorBase extends EditorPluginBase {
  /**
   * Supported widgets.
   *
   * Form-edited leaves are matched to their editor by the field widget: the
   * registry indexes editors by these IDs and selects by priority.
   *
   * @see EditorRegistry.getPlugin()
   *
   * @type {[]}
   *   An array of widget ID's this editor supports.
   */
  supportedWidgets = [];

  /**
   * Formatter properties.
   *
   * Which field PROPERTY a rendered formatter shows, keyed by formatter id,
   * for formatters that render something other than the field's main
   * property. Property resolution is how a click on rendered output finds
   * its one form item (the property segment of the form-side lookup ids);
   * the fallback for unmapped formatters is the field's main property.
   *
   * @see EditorRegistry.resolveProperty()
   *
   * @type {Object<string, string>}
   */
  formatterProperties = {};

  /**
   * Edit.
   *
   * The fresh entry: snapshots the field's mirror value (the base a resume
   * compares against), reveals the widget, records the widget's original
   * value, focuses.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  edit(EditableElement) {
    EditableElement.snapshot = structuredClone(mirrorValue(EditableElement));
    // Hotkeys stay live during an edit: the dispatcher's typing context
    // guards text entry, and the armed session's Tab/Escape depend on
    // dispatch running. Set BEFORE the form-item swap: the setter reads
    // the page element to report the edit's key, so it must still be in
    // the DOM.
    setCurrentlyEditing(EditableElement);
    this.replacePageElementWithFormItem(EditableElement);
    this.rememberOriginal(EditableElement);
    this.focus(EditableElement);
    this.revealAncillary(EditableElement);
  }

  /**
   * Remember original.
   *
   * Records the widget's value at edit open: what blur diffs against to
   * decide whether to submit, and what cancel restores. Only the fresh
   * entry records it; a resume keeps the recorded original, because the
   * draft that survived is measured against the edit's OPENING value, not
   * against itself (re-recording after a re-seed would make blur see no
   * change and commit nothing).
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  rememberOriginal(EditableElement) {}

  /**
   * Focus.
   *
   * Focus on the field after it has been clicked.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  focus(EditableElement) {
    // Track what is currently focused so that we can check if one of its
    // ancillary elements was clicked and remain focused, e.g. changing a
    // text format in the sidebar.
    EditableElement.getFormItemWrapper().classList.add('daedalus-edit-focused');
    super.focus(EditableElement);
  }

  /**
   * Defocus.
   *
   * Blur the form item: re-hide the ancillary UI and release the focused
   * marker class.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  defocus(EditableElement) {
    this.hideAncillary(EditableElement);
    document.querySelectorAll('.daedalus-edit-focused').forEach((element) => {
      element.classList.remove('daedalus-edit-focused');
    });
  }

  /**
   * Is valid.
   *
   * @param e
   *   The event if any. We prevent default because the mousedown event doesn't
   *   work well with validity checking.
   *
   * @return {boolean}
   *   Whether the currently editing element passes HTML5 validation.
   */
  isValid(e = null) {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    // Check for server validation errors.
    const formItemWrapper = EditableElement.getFormItemWrapper();
    if (
      formItemWrapper.querySelector(
        '.error, .form-item--error, [aria-invalid="true"], .form-item--error-message, .error-message',
      ) ||
      formItemWrapper.classList.contains('error') ||
      formItemWrapper.classList.contains('form-item--error')
    ) {
      return false;
    }

    // Run the HTML5 validation.
    const inputs = EditableElement.getFormItemInputs();
    if (inputs && inputs.length > 0) {
      if (e) {
        e.preventDefault();
      }
      let allValid = true;
      for (const input of inputs) {
        const valid = input.checkValidity();
        if (!valid) {
          this.displayValidationMessage(input.validationMessage, input);
        }
        if (allValid) {
          allValid = valid;
        }
      }
      if (!allValid) {
        return false;
      }
    }
    return true;
  }

  /**
   * Remove server-side validation error indicators.
   *
   * @param inputElement
   *   The input element that's being corrected.
   */
  removeServersideValidationMessage(inputElement) {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!EditableElement) return;

    const wrapper = EditableElement.getFormItemWrapper();

    // Remove error classes from wrapper.
    wrapper.classList.remove('error', 'form-item--error');

    // Remove error classes and aria-invalid from child elements.
    wrapper
      .querySelectorAll('.error, .form-item--error, [aria-invalid="true"]')
      .forEach((el) => {
        el.classList.remove('error', 'form-item--error');
        el.removeAttribute('aria-invalid');
      });

    // Remove server error message elements.
    wrapper
      .querySelectorAll('.form-item--error-message, .error-message')
      .forEach((el) => {
        el.remove();
      });
  }

  /**
   * Cancel element.
   *
   * Reverts the form item back to the original page element without saving.
   * This bypasses validation and does not update the tempstore.
   *
   * @param {EditableElement} EditableElement
   *   The element being canceled.
   *
   * @return {Promise<void>}
   *   Returns a resolved promise to match the async pattern of blurElement().
   */
  cancelElement(EditableElement) {
    const validationMessageHandle = this.validationMessageHandle();
    if (validationMessageHandle) {
      this.clearValidationMessageOnUserInput(validationMessageHandle);
    }
    this.replaceFormItemWithPageElement(EditableElement);
    return Promise.resolve();
  }

  /**
   * Reveal ancillary.
   *
   * Reveal things in the sidebar that pertain to this field e.g. formatter
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  revealAncillary(EditableElement) {}

  /**
   * Hide ancillary.
   *
   * Reveal things in the sidebar that pertain to this field e.g. formatter
   *
   * @param {EditableElement} EditableElement
   *   The current form item.
   */
  hideAncillary(EditableElement) {
    EditableElement.getFormItemWrapper().classList.add('daedalus-edit-hidden');
  }

  /**
   * Replace page element with form item.
   *
   * The honest-panel gesture: reveals the real widget in-flow in the form
   * panel (right rail, bottom slot). The wrapper is unhidden, the widget
   * scrolled into view, and the page element stays visible with a selection
   * outline (.daedalus-edit-panel-editing). Clicking the outlined page element
   * commits and re-opens the edit through the armed session's click
   * resolution — click-to-refocus needs no extra wiring.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  replacePageElementWithFormItem(EditableElement) {
    const pageElement = EditableElement.getPageElementHandle();
    const formItemWrapper = EditableElement.getFormItemWrapper();
    const formItem = EditableElement.getFormItemHandle();

    // The page element stays visible as the selection being edited.
    pageElement.classList.add('daedalus-edit-panel-editing');

    // Reveal the widget in-flow in the panel. Hidden siblings are
    // position: absolute (see .daedalus-edit-hidden), so the widget renders
    // where it falls in form order without reflowing them. The form item
    // may carry .daedalus-edit-hidden from a previous edit's teardown.
    formItemWrapper.classList.remove('daedalus-edit-hidden');
    formItem.classList.remove('daedalus-edit-hidden');

    // Widen before scrolling so the scroll target has its final geometry.
    this.widenPanelIfNeeded(EditableElement);
    formItem.scrollIntoView({ block: 'nearest' });
  }

  /**
   * Widen the form panel when the revealed widget doesn't fit.
   *
   * Wide widgets (multi-value tables, media grids, min-width inputs) can't
   * be known statically, so the need is measured: the panel clips
   * horizontal overflow, which makes scrollWidth > clientWidth on the form
   * item a direct signal, and the deficit is exactly the extra width to
   * request. Widgets that wrap without overflowing measure as fitting —
   * the user's rail resize handle is the tool for taste.
   *
   * Grow-only for the duration of the edit (an AJAX rebuild that shrinks
   * the widget doesn't snap the panel back mid-edit); the panel reverts to
   * the rail width on blur (replaceFormItemWithPageElement). Re-run on
   * behaviors re-attach inside the panel, since "Add another item" and
   * friends change the widget's size mid-edit.
   *
   * @see Drupal.behaviors.DaedalusEditPanelAutoWiden
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  widenPanelIfNeeded(EditableElement) {
    // The width request targets the PANEL, not the cached form wrapper
    // inside it: the panel is the element whose inline-size the rail
    // honors.
    const panel = EditableElement.getForm()?.closest(
      '.daedalus-ui-sidebar',
    );
    const formItem = EditableElement.getFormItemHandle();
    if (!panel || !formItem) {
      return;
    }
    const deficit = formItem.scrollWidth - formItem.clientWidth;
    if (deficit <= 0) {
      return;
    }
    const needed = panel.offsetWidth + deficit;
    if (needed > (EditableElement.widenedPanelTo ?? 0)) {
      EditableElement.widenedPanelTo = needed;
      Drupal.Daedalus.SidebarManager.setPanelWidth(panel, needed);
    }
  }

  /**
   * Replace form item with page element.
   *
   * The counterpart of the panel reveal: hides the form item in the panel
   * and releases the page element. blur() has already re-hidden the wrapper
   * (hideAncillary); re-hiding the form item handle too keeps the invariant
   * that a blurred handle carries .daedalus-edit-hidden.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  replaceFormItemWithPageElement(EditableElement) {
    const pageElement = EditableElement.getPageElementHandle();
    const formItem = EditableElement.getFormItemHandle();

    formItem.classList.add('daedalus-edit-hidden');
    pageElement.classList.remove('daedalus-edit-panel-editing');

    // The panel follows the rail width again once the wide widget is hidden.
    if (EditableElement.widenedPanelTo) {
      EditableElement.widenedPanelTo = null;
      const panel = EditableElement.getForm()?.closest(
        '.daedalus-ui-sidebar',
      );
      if (panel) {
        Drupal.Daedalus.SidebarManager.releasePanelWidth(panel);
      }
    }
  }

  /**
   * Cancel edit.
   *
   * Give users a way to bail on the current edit. If they have removed the
   * form item value we won't know what it is anymore, so the cached form is
   * evicted through the sidebar plugin's subject-death entry point: an open
   * panel reverts to a fresh top-level form, and the next edit reloads
   * fresh values either way.
   */
  cancelEdit(EditableElement, e = null) {
    EditableElement.isCancelingEdit = true;

    return EditableElement.plugin
      .blur(e)
      .then(async (response, status) => {
        const form = EditableElement.getForm();
        if (form) {
          await Drupal.Daedalus.SidebarManager.getPlugin(
            'daedalus_edit_form',
          ).removeForm(form);
        }
      })
      .catch((e) => {
        if (e !== 'HTML validation failed') {
          console.error(e);
        }
      });
  }

  /**
   * Capture draft.
   *
   * The panel widget is the draft store on the form path: what the user
   * typed sits in the real form input, which the swap does not touch. The
   * base carries nothing across; editors whose draft lives on the canvas
   * (the textfield's in-place text, the inline editor's CKEditor) override
   * this to read it while the surface still stands.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {*}
   *   The draft, or null.
   */
  captureDraft(EditableElement) {
    return null;
  }

  /**
   * Resume.
   *
   * The form path's three-way landing (see the class docblock). The form
   * comes first: an eviction's revert may already be refetching the
   * top-level form (wait on it rather than fetch it twice), and getForm()
   * reuses the cached form or fetches a fresh one, so by the time the rule
   * runs the widget exists again. The remote is judged from the mirror,
   * which the response replaced before the engine called here.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {*} draft
   *   The value captureDraft() returned.
   *
   * @return {Promise<void>}
   */
  async resume(EditableElement, draft) {
    await Drupal.Daedalus.SidebarManager.getPlugin(
      'daedalus_edit_form',
    ).pendingFetch();
    await entityForm.getForm(EditableElement);
    EditableElement.setWidget();
    const base = EditableElement.snapshot;
    const remote = mirrorValue(EditableElement);
    if (base === null || !equals(base, remote)) {
      this.concede(EditableElement);
      return;
    }
    this.reseed(EditableElement, draft);
    this.replacePageElementWithFormItem(EditableElement);
    this.focus(EditableElement);
    this.revealAncillary(EditableElement);
  }

  /**
   * Reseed.
   *
   * Writes a surviving draft back where the edit reads it from, before the
   * widget is revealed over the swapped-in page element: the refetched
   * form's input holds the remote (which equals the base), and the new
   * page element renders it. The base has no canvas draft to write.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {*} draft
   *   The value captureDraft() returned.
   */
  reseed(EditableElement, draft) {}

  /**
   * Concede.
   *
   * Ends the edit as the remote's: nothing is committed and nothing is
   * restored, because the form (refetched or untouched) and the swapped-in
   * markup already hold the remote value. Unlike cancel, the cached form
   * stays: its values are known. Editors that keep per-edit state beside
   * the widget release it here.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  concede(EditableElement) {
    this.defocus(EditableElement);
    this.replaceFormItemWithPageElement(EditableElement);
    setCurrentlyEditing(null);
  }

  /**
   * Get remain focused selectors.
   *
   * The form path's editor-owned surfaces: the focused form item and the
   * widget's floating chrome (CKEditor popups, autocompletes, dialogs).
   * The form PANEL and the rest of the page chrome are not this editor's
   * to keep — they declare data-keeps-edit on their own roots.
   *
   * @return {string[]}
   *   An array of selectors that will not cause the current form item to lose
   *   focus if clicked.
   */
  getFocusedSelectors() {
    return [
      ...super.getFocusedSelectors(),
      '.daedalus-edit-focused',
      '.ck-body-wrapper',
      // Ignore autocompletes.
      '.ui-autocomplete',
      '.ui-widget',
      // Ignore Drupal modals.
      '#drupal-modal',
      '.ui-dialog-buttonpane',
    ];
  }

  /**
   * Inputs.
   *
   * The form path's editor controls are the revealed widget's inputs.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {NodeList}
   *   The form item's input elements.
   */
  inputs(EditableElement) {
    return EditableElement.getFormItemInputs();
  }
}
