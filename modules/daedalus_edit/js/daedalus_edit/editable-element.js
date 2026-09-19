/**
 * Editable Element.
 *
 * This class is a state object that represents the relationship of the currently
 * edited page element to the form element.
 */
export class EditableElement {
  /**
   * Info.
   *
   * All the details about this editable element.
   *
   * @type {{elementId, langcode: *, fieldName: *, mainProperty: *, delta: *, entityTypeId: *, entityId: *, widget: *, viewMode: *, fieldType: *, widget: *}}
   */
  info = null;

  /**
   * The inline editing editor plugin for this EditableElement.
   * @see editor-registry.js
   *
   * @type {EditorPluginBase}
   */
  plugin = null;

  /**
   * Whether the edit is being canceled (Escape key pressed).
   *
   * When true, validation is bypassed and changes are discarded.
   *
   * @type {bool}
   */
  isCancelingEdit = false;

  /**
   * The mirror value of the leaf's field at edit open (mirror-value.js),
   * cloned; the base of the three-way rule a resume applies. Null when
   * the leaf has no mirror value. Per-edit state lives here because editor
   * plugin instances are registry singletons shared across leaves.
   *
   * @type {*}
   *
   * @see plugins/form-editor-base.js
   */
  snapshot = null;

  /**
   * The draft captured as a markup swap detached the editing surface,
   * pending the engine's resume once the response has fully applied.
   * Undefined when no swap touched the edit (the engine's "nothing to
   * resume" signal); null is a captured empty draft. The engine sets and
   * consumes it.
   *
   * @type {*}
   *
   * @see edit-session-engine.js (DaedalusEditDraft, mutationApplied)
   */
  draft = undefined;

  constructor(id) {
    this.info = this.parseMarkupId(id);
  }

  /**
   * Create from form item.
   *
   * @param element
   *   An element within a form item wrapper.
   *
   * @return {EditableElement}
   *   A new EditableElement instance from a form item or any element from
   *   within a form item wrapper.
   */
  static createFromFormItem(element) {
    // Find the page element
    let pageElement;
    const formItem = element.closest('.daedalus-edit-form-item');
    if (formItem) {
      pageElement = document.querySelector(
        `[data-daedalus-edit-page-element-id="${
          formItem.dataset.daedalusEditFormItemId
        }"]`,
      );
    } else {
      const formItemWrapperId = element.closest(
        '[data-daedalus-edit-form-item-wrapper-id]',
      ).dataset.daedalusEditFormItemWrapperId;
      // Let's assume a delta of 0 and get the page element.
      const pageElementId = formItemWrapperId.split('::');
      pageElementId.splice(3, 0, '0');
      pageElement = document.querySelector(
        `[data-daedalus-edit-page-element-id="${pageElementId.join('::')}"]`,
      );
    }

    const editableElement = new EditableElement(pageElement.dataset.daedalusEditId);
    editableElement.setWidget();
    return editableElement;
  }

  /**
   * Set widget.
   *
   * Once the form is loaded the widget needs to be set on the EditableElement.
   */
  setWidget() {
    this.info.widget = this.getFormItemWrapper().dataset.daedalusEditFormItemWidget;
    this.plugin = Drupal.Daedalus.Edit.Editors.getPlugin(this);
  }

  /**
   * Get form.
   *
   * @return {Element}
   * The form for the EditableElement.
   */
  getForm() {
    const id = [this.info.entityTypeId, this.info.entityId].join('::');
    return document.querySelector(`[data-daedalus-edit-form-id="${id}"]`);
  }

  /**
   * Get handle type.
   *
   * @return {string}
   *   Returns a string of either form_item or wrapper.
   *
   *   Users can config whether a field swaps the form item or the entire form
   *   wrapper into the page for editing.
   */
  getHandleType() {
    return this.getFormItemWrapper()?.dataset.daedalusEditHandle ?? 'form_item';
  }

  /**
   * Handle is wrapper.
   *
   * @see getFormItemHandle
   *
   * @return {boolean}
   *   Returns true if the handle is wrapper. false if the handle is form_item.
   */
  handleIsWrapper() {
    const wrapper = this.getFormItemWrapper();
    const elementToUseAsHandle = wrapper.dataset.daedalusEditHandle ?? 'form_item';
    // @todo This doesn't account for getFormItemHandle() gracefully falling back to the wrapper.
    return elementToUseAsHandle === 'wrapper';
  }

  /**
   * Get page element.
   *
   * @return {Element}
   *   The rendered markup on the page for the EditableElement.
   */
  getPageElement() {
    return document.querySelector(
      `[data-daedalus-edit-id="${this.info.elementId}"]`,
    );
  }

  getPageElementWrapper() {
    const id = [
      this.info.entityTypeId,
      this.info.entityId,
      this.info.fieldName,
      this.info.mainProperty,
    ].join('::');
    return document.querySelector(
      `[data-daedalus-edit-field-value-wrapper="${id}"]`,
    );
  }

  getPageElementHandle() {
    if (this.getHandleType() === 'wrapper') {
      return this.getPageElementWrapper();
    }
    return document.querySelector(
      `[data-daedalus-edit-id="${this.info.elementId}"]`,
    );
  }

  /**
   * Get Form Item ID.
   *
   * @return {string}
   *   The form item ID.
   */
  getFormItemId() {
    return [
      this.info.entityTypeId,
      this.info.entityId,
      this.info.fieldName,
      this.info.delta,
      Drupal.Daedalus.Edit.Editors.resolveProperty(this.info),
    ].join('::');
  }

  /**
   * Get form item.
   *
   * @return {Element}
   * The form item for the EditableElement.
   */
  getFormItem() {
    return document.querySelector(
      `[data-daedalus-edit-form-item-id="${this.getFormItemId()}"]`,
    );
  }

  /**
   * Get form item wrapper.
   *
   * @return {Element}
   *   The form item wrapper for the EditableElement.
   */
  getFormItemWrapper() {
    const id = [
      this.info.entityTypeId,
      this.info.entityId,
      this.info.fieldName,
      Drupal.Daedalus.Edit.Editors.resolveProperty(this.info),
    ].join('::');
    return document.querySelector(
      `[data-daedalus-edit-form-item-wrapper-id="${id}"]`,
    );
  }

  /**
   * Get form item handle.
   *
   * @return {Element}
   *   The handle for the form element that will be swapped in for inline editing.
   *   @see getHandleType.
   */
  getFormItemHandle() {
    if (this.getHandleType() === 'wrapper') {
      return this.getFormItemWrapper();
    }

    try {
      return document
        .querySelector(
          `[data-daedalus-edit-form-item-id="${this.getFormItemId()}"]`,
        )
        .closest('.daedalus-edit-form-item');
    } catch (e) {
      console.warn(
        `The ${this.info.fieldName} field is configured to use the form item as a handle, but no form item markup was found. Try configuring ${this.info.fieldName} to use the wrapper as a handle instead.`,
      );
      const wrapper = this.getFormItemWrapper();
      if (wrapper) {
        console.info(
          `Falling back to the wrapper handle for ${this.info.fieldName}.`,
        );
        return wrapper;
      }
      throw e;
    }
  }

  /**
   * Get form input.
   *
   * @return {Element}
   *   The input element for the EditableElement.
   */
  getFormItemInputs() {
    return this.getFormItemHandle().querySelectorAll('input, textarea, select');
  }

  /**
   * Parse markup ID.
   *
   * @param id
   *   The Entity Plus ID on the rendered field value.
   *
   * @return {{elementId, langcode: *, fieldName: *, mainProperty: *, delta: *, entityTypeId: *, entityId: *, widget: *, viewMode: *, fieldType: *}}
   *   The elementInfo object.
   */
  parseMarkupId(id) {
    const parts = id.split('::');
    const elementInfo = {
      entityTypeId: parts[0],
      bundle: parts[1],
      entityId: parts[2],
      fieldName: parts[3],
      delta: parts[4],
      langcode: parts[5],
      fieldType: parts[6],
      fieldFormatter: parts[7],
      mainProperty: parts[8],
      elementId: id,
    };
    elementInfo.viewMode = document.querySelector(
      `[data-daedalus-ui-entity-wrapper="${elementInfo.entityTypeId}::${elementInfo.entityId}::${elementInfo.bundle}"]`,
    ).dataset.daedalusUiViewMode;

    return elementInfo;
  }
}
