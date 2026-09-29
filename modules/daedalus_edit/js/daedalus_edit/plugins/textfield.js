import { EditorPluginBase } from './editor-plugin-base.js';

/**
 * Text field editor.
 *
 * In-place plain text over a form-edited leaf: the page element goes
 * contenteditable and an input listener mirrors its text into the form's
 * real input, so the input (the item's value store: the widget) holds the
 * draft at every keystroke and the form transport commits it on blur. A
 * foreign markup swap replaces the page element: the draft (the input's
 * value, captureDraft through readProperties) is written back onto the
 * swapped-in element and into the possibly refetched input before the
 * edit re-mounts on them. Because the store is dirty during the edit, the
 * remote of the three-way rule is the base unless the widget was
 * refetched (a response whose targets named the field): only a fresh
 * input holds a remote (remoteValues).
 */
class TextfieldPlugin extends EditorPluginBase {
  id = 'textfield';

  supportedWidgets = ['string_textfield', 'string_textarea'];

  /**
   * The widget's one input.
   */
  input(item) {
    return item.getFormItemInputs()[0];
  }

  readProperties(item) {
    return { [item.info.fieldName]: this.input(item)?.value ?? '' };
  }

  writeProperties(item, properties) {
    if (!(item.info.fieldName in properties)) {
      return;
    }
    const value = properties[item.info.fieldName] ?? '';
    const pageElement = item.getPageElementHandle();
    if (pageElement) {
      pageElement.textContent = value;
    }
    const input = this.input(item);
    if (input) {
      input.value = value;
    }
  }

  mount(item) {
    const pageElement = item.getPageElementHandle();
    pageElement.setAttribute('contenteditable', true);
    pageElement.classList.add('daedalus-edit-text-editing');
    // The input this edit mirrors into; a resume tells a refetched widget
    // (a new element) from the same one by it.
    item.mirroredInput = this.input(item);
  }

  /**
   * The base unless the input was refetched: the same input holds the
   * draft the keystroke mirror wrote, never a remote.
   */
  remoteValues(item) {
    if (this.input(item) === item.mirroredInput) {
      return structuredClone(item.snapshot ?? {});
    }
    return super.remoteValues(item);
  }

  focusElement(item) {
    const input = this.input(item);

    // Bound to the CURRENT page element: a resume re-runs this over the
    // swapped-in one.
    const pageElement = item.getPageElementHandle();
    pageElement.addEventListener('input', () => {
      input.value = pageElement.textContent;
    });
    pageElement.focus();
  }

  defocus(item) {
    const pageElement = item.getPageElementHandle();
    if (pageElement) {
      pageElement.removeAttribute('contenteditable');
      pageElement.classList.remove('daedalus-edit-text-editing');
    }
  }

  cancelElement(item) {
    this.removeClientsideValidationMessage(this.validationMessageHandle());
    return super.cancelElement(item);
  }

  getFocusedSelectors() {
    return [...super.getFocusedSelectors(), '.daedalus-edit-text-editing'];
  }

  inputs(item) {
    return item.getFormItemInputs();
  }

  applies(item) {
    return item.getHandleType() !== 'wrapper';
  }

  validationMessageHandle() {
    return Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
  }
}

/**
 * Register the text field editor.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new TextfieldPlugin());
});
