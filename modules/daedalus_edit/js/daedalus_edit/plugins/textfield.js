import { DefaultPlugin } from './default.js';

/**
 * Text field editor.
 *
 * In-place plain text: the page element goes contenteditable and an input
 * listener mirrors its text into the form's real input, so the input holds
 * the draft at every keystroke. A foreign markup swap replaces the page
 * element (and, when the mutation changed the owning entity, evicts the
 * form with the input): captureDraft reads the input while it stands, and
 * reseed writes the surviving draft onto the swapped-in page element and
 * into the (possibly refetched) input before the edit re-opens on them.
 */
class TextfieldPlugin extends DefaultPlugin {
  id = 'textfield';

  supportedWidgets = ['string_textfield', 'string_textarea'];

  replacePageElementWithFormItem(EditableElement) {
    const pageElement = EditableElement.getPageElementHandle();
    pageElement.setAttribute('contenteditable', true);
    pageElement.classList.add('daedalus-edit-text-editing');
  }

  focusElement(EditableElement) {
    const input = EditableElement.getFormItemInputs()[0];

    // Bound to the CURRENT page element: a resume re-runs this over the
    // swapped-in one.
    const pageElement = EditableElement.getPageElementHandle();
    pageElement.addEventListener('input', () => {
      input.value = pageElement.textContent;
    });
    pageElement.focus();
  }

  captureDraft(EditableElement) {
    return EditableElement.getFormItemInputs()[0].value;
  }

  reseed(EditableElement, draft) {
    EditableElement.getPageElementHandle().textContent = draft;
    EditableElement.getFormItemInputs()[0].value = draft;
  }

  cancelElement(EditableElement) {
    EditableElement.getPageElementHandle().innerText = this.originalValue;
    this.removeClientsideValidationMessage(this.validationMessageHandle());
    this.replaceFormItemWithPageElement(EditableElement);
    return Promise.resolve();
  }

  replaceFormItemWithPageElement(EditableElement) {
    const pageElement = EditableElement.getPageElementHandle();
    if (pageElement) {
      pageElement.removeAttribute('contenteditable');
      pageElement.classList.remove('daedalus-edit-text-editing');
    }
  }

  getFocusedSelectors() {
    const selectors = super.getFocusedSelectors();
    selectors.push('.daedalus-edit-text-editing');
    return selectors;
  }

  applies(EditableElement) {
    return EditableElement.getHandleType() !== 'wrapper';
  }

  validationMessageHandle() {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    return EditableElement.getPageElementHandle();
  }
}

/**
 * Register the text field editor.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new TextfieldPlugin());
});
