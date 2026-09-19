import * as entityForm from '../entity-form.js';
import { FormEditorBase } from './form-editor-base.js';

/**
 * Default field editor.
 */
export class DefaultPlugin extends FormEditorBase {
  id = 'default';

  rememberOriginal(EditableElement) {
    this.getInputValue(EditableElement).then((values) => {
      // Remember the original values so we can tell if there's changes before
      // updating when the form item looses focus.
      this.originalValue = values;
    });
  }

  focusElement(EditableElement) {
    this.getFormItemInputs(EditableElement)[0].focus();
  }

  blurElement(EditableElement) {
    return this.getInputValue(EditableElement).then((values) => {
      this.replaceFormItemWithPageElement(EditableElement);
      // The values have changed.
      if (JSON.stringify(this.originalValue) !== JSON.stringify(values)) {
        const isEmpty =
          values.length === 0 ||
          // Select lists.
          (values.length === 1 && values[0] === '_none');
        entityForm.updateTempstore(EditableElement, isEmpty);
      }
      this.originalValue = null;
    });
  }

  getFormItemInputs(EditableElement) {
    return EditableElement.getFormItemInputs();
  }

  /**
   * Get input value(s).
   *
   * @param {EditableElement} EditableElement
   *   The editable element.
   *
   * @return {Promise<{}|*[]>}
   *   A promise because we may need to wait on a CKEditor to be attached before
   *   getting input changes.
   */
  async getInputValue(EditableElement) {
    const inputs = this.getFormItemInputs(EditableElement);
    if (inputs[0].type === 'checkbox' || inputs[0].type === 'radio') {
      const checkboxes = [];
      for (let i = 0; i < inputs.length; i++) {
        const value = inputs[i].checked ? inputs[i].value : null;
        if (value) {
          checkboxes.push(value);
        }
      }
      return checkboxes;
    }

    // If there's a ckeditor here it won't be initialized on the first pass,
    // then when the values are compared to see if there are changes, there will
    // always be changes on the first go. Let's wait for the ckeditor to load.
    const waitForCkeditor = (ckeditorId) => {
      return new Promise((resolve, reject) => {
        const ckeditor = Drupal.CKEditor5Instances.get(ckeditorId);
        if (ckeditor) {
          resolve(ckeditor);
        } else {
          const count = 0;
          const limit = 10;
          const interval = setInterval(() => {
            const instance = Drupal.CKEditor5Instances.get(ckeditorId);
            if (instance) {
              clearInterval(interval);
              resolve(instance);
            }
            if (count >= limit) {
              clearInterval(interval);
              reject(new Error(`Invalid value for ${ckeditorId}`));
            }
          }, 10);
        }
      });
    };

    const values = (
      await Promise.all(
        Array.from(inputs)
          .filter((input) => input.type !== 'submit' && input.type !== 'button')
          .map(async (input) => {
            if (input.hasAttribute('data-ckeditor5-id')) {
              const ckeditor = await waitForCkeditor(input.dataset.ckeditor5Id);
              return ckeditor.getData();
            }
            return input.value;
          }),
      )
    ).filter((value) => value);
    return values;
  }
}

/**
 * Register the default field editor.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new DefaultPlugin());
});
