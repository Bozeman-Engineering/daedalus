import * as entityForm from '../entity-form.js';
import { FormEditorBase } from './form-editor-base.js';

/**
 * Media field editor.
 */
class MediaPlugin extends FormEditorBase {
  id = 'media';

  supportedWidgets = ['media_library_widget'];

  rememberOriginal(EditableElement) {
    this.originalValue = this.getMediaWidgetValue(EditableElement);
  }

  focusElement(EditableElement) {
    window.setFileDrag(false);
  }

  blurElement(EditableElement) {
    const newValue = this.getMediaWidgetValue(EditableElement);
    const isEmpty = this.isMediaValueEmpty(newValue);

    this.replaceFormItemWithPageElement(EditableElement);
    if (this.originalValue !== newValue) {
      entityForm.updateTempstore(EditableElement, isEmpty);
    }
    window.setFileDrag(true);
    return Promise.resolve();
  }

  getFocusedSelectors() {
    const selectors = super.getFocusedSelectors();
    selectors.push(
      '.field--widget-media-library-widget',
      '#drupal-modal',
      '.ui-dialog-buttonpane',
    );
    return selectors;
  }

  validationMessageHandle() {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    const formItem = EditableElement.getFormItem();
    return (
      formItem.querySelector('.fieldset-wrapper') ??
      formItem.querySelector('.fieldset__wrapper')
    );
  }

  isValid(e = null) {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!EditableElement.getFormItem().hasAttribute('required')) {
      return true;
    }
    const newValue = this.getMediaWidgetValue(EditableElement);
    const isValid = !this.isMediaValueEmpty(newValue);
    if (!isValid) {
      this.displayValidationMessage(Drupal.t('A media item is required.'));
    }
    return isValid;
  }

  getMediaWidgetValue(EditableElement) {
    return EditableElement.getFormItem()?.dataset?.daedalusEditMediaValue ?? null;
  }

  /**
   * Check if media value is empty.
   *
   * @param {string|null} mediaValue
   *   JSON-encoded array of media target IDs.
   *
   * @return {boolean}
   *   True if the media value is empty (null, invalid JSON, or empty array).
   */
  isMediaValueEmpty(mediaValue) {
    if (mediaValue === null) {
      return true;
    }
    try {
      const targetIds = JSON.parse(mediaValue);
      return !Array.isArray(targetIds) || targetIds.length === 0;
    } catch (e) {
      // Invalid JSON, treat as empty
      return true;
    }
  }

  clearValidationMessageOnUserInput(validationMessageHandle) {
    // Do nothing. The validation message is removed when the media library
    // is used.
  }
}

/**
 * Register the media field editor.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new MediaPlugin());
});
