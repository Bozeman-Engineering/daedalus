import * as editableElement from './editable-element.js';

(($, Drupal, once) => {
  Drupal.behaviors.DaedalusEditFieldAddedToPage = {
    attach: (context, settings) => {
      once(
        'DaedalusEditFieldAddedToPage',
        '.daedalus-edit-field-added-to-page',
        context,
      ).forEach((formItem) => {
        // @todo Review this. If creating the EditableElement here proves unreliable
        // we need to implement something like the getMarkupItem that was removed from
        // utilities.js

        const EditableElement =
          editableElement.EditableElement.createFromFormItem(formItem);
        const pageElement = EditableElement.getPageElementHandle();
        // Use the field wrapper if it's available.
        const element = pageElement.closest('.field') ?? pageElement;
        // Animate adding the field to the page.
        element.classList.add('call-attention-to');
      });
    },
  };
})(jQuery, Drupal, once);
