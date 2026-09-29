import { formTransport } from './transports.js';
import { panelItemKey, widgetControls } from './panel-items.js';

/**
 * Panel Widget Element.
 *
 * The state object for a widget revealed in the entity-form panel: a field
 * of the panel's subject with no canvas leaf, whose editor is the widget
 * itself. The third item kind beside EditableElement (a form-edited leaf)
 * and BlueprintEditableElement (a blueprint-edited leaf). Like the form
 * leaf its VALUE STORE is the widget and its COMMIT TRANSPORT the form
 * transport (transports.js); unlike the leaf it has no page element: the
 * form-item wrapper stands in as the session's selection (getPageElement,
 * re-resolved by its identity on every access because a refetched subject
 * form replaces it), and the page-element HANDLE answers null, so nothing
 * an editor marks on a page element (the editing mark, the panel-editing
 * dim) ever lands inside the panel.
 *
 * Its session key is the engine's (panel-items.js panelItemKey), reported
 * with the edit so the session's editingKey names the field exactly as a
 * canvas leaf's would.
 *
 * @see plugins/panel-widget-editor.js
 * @see plugins/editor-plugin-base.js
 */
export class PanelWidgetElement {
  /**
   * Whether the edit is being canceled (Escape key pressed).
   *
   * @type {boolean}
   */
  isCancelingEdit = false;

  /**
   * The editor's snapshot at edit open (the panel widget editor reads no
   * client-side value, so it is empty).
   *
   * @type {?Object<string, *>}
   */
  snapshot = null;

  /**
   * The draft captured as a markup swap detached the editing surface
   * (never, for a panel widget: the panel is not swapped), pending the
   * engine's resume. The engine sets and consumes it.
   *
   * @type {*}
   */
  draft = undefined;

  /**
   * The commit transport: the subject form's own submit.
   *
   * @type {Object}
   */
  transport = formTransport;

  constructor(wrapper) {
    /**
     * The wrapper's form identity: "{type}::{id}::{field}::{property}".
     *
     * @type {string}
     */
    this.wrapperId = wrapper.dataset.daedalusEditFormItemWrapperId;
    const [entityTypeId, entityId, fieldName, mainProperty] =
      this.wrapperId.split('::');

    /**
     * The details about this item, in the shape the leaf's info takes for
     * the members shared with it (the invalid-widget warning reads
     * elementId and fieldName).
     *
     * @type {{elementId: string, entityTypeId: string, entityId: string, fieldName: string, mainProperty: string}}
     */
    this.info = {
      elementId: this.wrapperId,
      entityTypeId,
      entityId,
      fieldName,
      mainProperty,
    };

    /**
     * The session key for the widget's field (`field:{root}:{f}`,
     * `block:{c}:{f}`, `block:{c}:label`), or null when the panel cannot
     * key it.
     *
     * @type {?string}
     */
    this.sessionKey = panelItemKey(wrapper);

    /**
     * The one editor for a panel widget.
     *
     * @type {EditorPluginBase}
     */
    this.plugin = Drupal.Daedalus.Edit.Editors.get('panel_widget');
  }

  /**
   * The committed values of the edited properties: the widget's own.
   *
   * @param {string[]} names
   *   The property names the editor reads.
   * @param {Function} surface
   *   The editor's readProperties() over this item.
   *
   * @return {Object<string, *>}
   */
  committedValues(names, surface) {
    return surface();
  }

  /**
   * The selectable key for the mutation-failure display: the field's.
   *
   * @return {?string}
   */
  get selectableKey() {
    return this.sessionKey;
  }

  /**
   * The widget's form-item wrapper, re-resolved by identity.
   *
   * @return {?Element}
   */
  getFormItemWrapper() {
    return document.querySelector(
      `[data-daedalus-edit-form-item-wrapper-id="${this.wrapperId}"]`,
    );
  }

  /**
   * The wrapper is the handle a form-edited leaf would swap: the widget
   * has no separate form item to reveal.
   *
   * @return {?Element}
   */
  getFormItemHandle() {
    return this.getFormItemWrapper();
  }

  /**
   * The "page element" the session and the engine address: the wrapper.
   *
   * It is the element the edit is reported on (the session's editingKey
   * derives from it) and the one the engine compares a session item
   * against; it is not a page element to mark, which is the handle's
   * question.
   *
   * @return {?Element}
   */
  getPageElement() {
    return this.getFormItemWrapper();
  }

  /**
   * The page element handle: none.
   *
   * A panel widget has no rendered element on the page, so there is
   * nothing for an editor to outline, dim or scroll to; the editors read
   * the handle with optional chaining and the wrapper stays as the panel
   * shows it.
   *
   * @return {null}
   */
  getPageElementHandle() {
    return null;
  }

  /**
   * The cached form the wrapper belongs to.
   *
   * @return {?Element}
   */
  getForm() {
    return this.getFormItemWrapper()?.closest('.daedalus-edit-form') ?? null;
  }

  /**
   * The widget's focusable controls, document order.
   *
   * @return {Element[]}
   */
  getFormItemInputs() {
    const wrapper = this.getFormItemWrapper();
    return wrapper ? widgetControls(wrapper) : [];
  }
}
