import { DefaultPlugin } from './default.js';

/**
 * Entity reference label field editor.
 */
class EntityReferenceAutocompletePlugin extends DefaultPlugin {
  id = 'entity_reference_autocomplete';

  supportedWidgets = [
    'entity_reference_autocomplete_tags',
    'entity_reference_autocomplete',
  ];

  getFormItemInputs(EditableElement) {
    return EditableElement.getFormItemWrapper().querySelectorAll(
      'input:not([type="submit"])',
    );
  }
}

/**
 * Register the entity reference label field editor.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new EntityReferenceAutocompletePlugin());
});
