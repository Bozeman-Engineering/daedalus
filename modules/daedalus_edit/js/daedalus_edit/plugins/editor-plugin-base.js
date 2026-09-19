import { setCurrentlyEditing } from '../currently-editing.js';

/**
 * Editor plugin base.
 *
 * The gesture surface every daedalus_edit editor shares, independent of HOW a
 * change is committed: edit/focus/blur/cancel, getFocusedSelectors (the
 * editor-owned surfaces the engine's keepsEdit answers from — the armed
 * session's press decision owns "does this click end the edit"), inputs()
 * for the armed session's Tab traversal, and the validation-message
 * rendering helpers.
 *
 * Commit strategies subclass this:
 * - FormEditorBase: the entity-form path — the real Drupal widget is the
 *   editor and blur commits through the form's AJAX update.
 * - BlueprintEditorBase: the blueprint path — the leaf is edited in place
 *   and blur commits one update_element op on the mutation spine.
 *
 * The draft contract (captureDraft / resume): an open edit is a DRAFT and
 * the canvas its projection. A foreign mutation's response (a style scrub
 * from the Daedalus Stylish inspector, an undo, an AI turn) swaps the edited
 * markup; the engine captures the editor's draft when the swap detaches
 * the edited subtree and hands it back through resume() once the response
 * is fully applied, so the editor re-projects it over the fresh markup.
 * The base holds no draft: capture yields null and resume leaves the edit
 * as it stands; each commit strategy decides what its draft is.
 *
 * @see form-editor-base.js
 * @see blueprint-editor-base.js
 * @see editor-registry.js
 */
export class EditorPluginBase {
  constructor() {
    this.originalValue = null;
  }

  /**
   * The registry key for this editor.
   *
   * Blueprint-edited leaves declare it directly in their attribution
   * (data-daedalus-edit-editor="{id}"); form-edited leaves are matched by widget
   * instead (see supportedWidgets on FormEditorBase). Null falls back to the
   * constructor name at registration.
   *
   * @type {string|null}
   */
  id = null;

  /**
   * Selection priority.
   *
   * When several editors support the same widget, the registry offers the
   * leaf to candidates in descending priority (ties keep registration
   * order) and the first whose applies() accepts wins. An editor overriding
   * a stock one for a widget declares a priority above 0.
   *
   * @type {number}
   */
  priority = 0;

  init() {}

  /**
   * Edit.
   *
   * The gesture entry point: makes this editor the active edit.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  edit(EditableElement) {
    // Hotkeys stay live during an edit: the dispatcher's typing context
    // guards text entry, and the armed session's Tab/Escape depend on
    // dispatch running.
    setCurrentlyEditing(EditableElement);
    this.focus(EditableElement);
  }

  /**
   * Focus.
   *
   * Focus on the element after it has been clicked. Ending the edit is not
   * this plugin's concern: the armed session's press decision owns "does
   * this click end the edit" and asks the engine's keepsEdit (built on
   * getFocusedSelectors()) whether a press lands on this editor's own
   * surface.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  focus(EditableElement) {
    this.focusElement(EditableElement);
  }

  /**
   * Focus element.
   *
   * This should be overridden by editors to provide specific instructions on
   * how to give the element focus.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @param event
   */
  focusElement(EditableElement, event) {}

  /**
   * Blur.
   *
   * End the current edit: commit through blurElement(), or discard through
   * cancelElement() when the edit is being canceled.
   *
   * @param e
   *   The event, if any.
   */
  async blur(e = null) {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;

    if (!EditableElement?.isCancelingEdit && !this.isValid(e)) {
      // A sentinel string, not an Error: five catch sites compare it by
      // identity to tell "invalid widget" from a real failure.
      // eslint-disable-next-line prefer-promise-reject-errors
      return Promise.reject('HTML validation failed');
    }

    this.defocus(EditableElement);
    let result;
    if (EditableElement?.isCancelingEdit) {
      result = this.cancelElement(EditableElement);
    } else {
      result = this.blurElement(EditableElement);
    }
    setCurrentlyEditing(null);
    return result;
  }

  /**
   * Defocus.
   *
   * Tear down whatever focus() put up, before the edit's outcome
   * (blurElement/cancelElement) runs.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  defocus(EditableElement) {}

  /**
   * Is valid.
   *
   * Editors gate blur() on this: an invalid edit stays open. The base editor
   * has no validation of its own.
   *
   * @param e
   *   The event if any.
   *
   * @return {boolean}
   *   Whether the current edit is valid and may commit.
   */
  isValid(e = null) {
    return true;
  }

  displayValidationMessage(validationMessageString, input = null) {
    // Display the validation message.
    const validationMessageHandle = this.validationMessageHandle() ?? input;
    const validationId = this.generateUniqueId(validationMessageHandle);
    let validationMessage = document.querySelector(
      `[data-daedalus-edit-validation-id="${validationId}"]`,
    );
    if (!validationMessage) {
      // Add the validation message to the page.
      validationMessageHandle.insertAdjacentHTML(
        'afterend',
        Drupal.theme('daedalusEditValidationMessage', validationMessageString),
      );
      validationMessage = validationMessageHandle.nextElementSibling;
      // Position the message.
      if (
        window.getComputedStyle(validationMessage.parentElement).position ===
        'static'
      ) {
        validationMessage.parentElement.dataset.originalPosition = 'static';
        validationMessage.parentElement.style.position = 'relative';
      }
      validationMessage.style.top = window.getComputedStyle(
        validationMessageHandle,
      ).height;
      // Relate the message to the input.
      validationMessageHandle.dataset.daedalusEditValidationId = validationId;
      validationMessage.dataset.daedalusEditValidationId = validationId;
      validationMessageHandle.insertAdjacentElement(
        'afterend',
        validationMessage,
      );

      this.clearValidationMessageOnUserInput(validationMessageHandle);
    }
  }

  /**
   * Clear validation message on user input.
   *
   * @param validationMessageHandle
   *   The validation message handle.
   *   @see this.validationMessageHandle
   * @param removeClientsideValidationMessage
   *   Optional function to call to remove validation messages.
   *   Defaults to removeClientsideValidationMessage for HTML5 validation.
   */
  clearValidationMessageOnUserInput(
    validationMessageHandle,
    removeClientsideValidationMessage = null,
  ) {
    // Default to client-side removal if not specified.
    if (!removeClientsideValidationMessage) {
      removeClientsideValidationMessage =
        this.removeClientsideValidationMessage.bind(this);
    }

    // Listen for user input.
    validationMessageHandle.addEventListener('input', (e) => {
      removeClientsideValidationMessage(e.target);
    });

    // Only set up the property override if we haven't already.
    if (!validationMessageHandle.dataset.daedalusEditValueOverridden) {
      // Listen for programmatic changes.
      // Get the descriptor from the correct prototype (could be input, select, or textarea).
      const originalDescriptor = Object.getOwnPropertyDescriptor(
        validationMessageHandle.constructor.prototype,
        'value',
      );
      Object.defineProperty(validationMessageHandle, 'value', {
        get() {
          return originalDescriptor.get.call(this);
        },
        set(newValue) {
          originalDescriptor.set.call(this, newValue);
          removeClientsideValidationMessage(this);
        },
        configurable: true,
      });
      validationMessageHandle.dataset.daedalusEditValueOverridden = 'true';
    }
  }

  /**
   * Remove client-side HTML5 validation message.
   *
   * @param inputElement
   *   The invalid input element with the validation message.
   */
  removeClientsideValidationMessage(inputElement) {
    if (inputElement.dataset.daedalusEditValidationId) {
      document
        .querySelector(
          `.daedalus-edit-validation-message[data-daedalus-edit-validation-id="${inputElement.dataset.daedalusEditValidationId}"]`,
        )
        .remove();
      if (inputElement.dataset.originPosition === 'static') {
        inputElement.parentElement.style.position = '';
      }
      delete inputElement.dataset.daedalusEditValidationId;
    }
  }

  /**
   * Generate unique ID.
   *
   * @param element
   *   The element that needs a unique ID.
   *
   * @return {*|string}
   *   A unique ID based on the elements position in the DOM.
   */
  generateUniqueId(element) {
    if (element.id) {
      return element.id;
    }

    const path = [];
    let currentElement = element;

    while (currentElement && currentElement.nodeType === Node.ELEMENT_NODE) {
      let selector = currentElement.tagName.toLowerCase();

      // Add class or nth-child for uniqueness. classList.length, not a
      // truthy className: an svg element's className is an
      // SVGAnimatedString, truthy even when no class is set.
      if (currentElement.classList.length) {
        selector += `.${Array.from(currentElement.classList).join('.')}`;
      } else {
        const siblings = Array.from(currentElement.parentNode?.children || []);
        const index = siblings.indexOf(currentElement) + 1;
        selector += `:nth-child(${index})`;
      }

      path.unshift(selector);
      currentElement = currentElement.parentElement;
    }

    return path.join(' > ');
  }

  /**
   * Validation message handle.
   *
   * Most of the time we want to show the HTML5 validation messages on the form
   * item, but some fields like simple text fields use contenteditable, which
   * isn't supported by HTML5 validation. Let's perform the validation on the
   * form item, but show the message on the contenteditable element.
   *
   * @return {Element|null}
   *   The element to show the validation messages on. Most of the time this
   *   should be null.
   */
  validationMessageHandle() {
    return null;
  }

  /**
   * Blur element.
   *
   * This should be overridden by editors to commit the edit. Since detecting
   * if the value has changed occurs at this point, after editing, this is
   * where an editor diffs against its snapshot and sends the change.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Promise<void>}
   *   Committing may be async (e.g. waiting on a CKEditor before reading
   *   changes).
   */
  blurElement(EditableElement) {
    return Promise.resolve();
  }

  /**
   * Cancel element.
   *
   * Called when an edit is canceled (e.g., Escape key pressed). Discards all
   * changes without committing. This bypasses validation.
   *
   * This is the counterpart to blurElement() - both are called from blur(),
   * but cancelElement() is used when EditableElement.isCancelingEdit is true.
   *
   * @param {EditableElement} EditableElement
   *   The element being canceled.
   *
   * @return {Promise<void>}
   *   Returns a resolved promise to match the async pattern of blurElement().
   */
  cancelElement(EditableElement) {
    return Promise.resolve();
  }

  /**
   * Capture draft.
   *
   * The edit's uncommitted state, read while the edited subtree is still
   * on the page (the engine calls this from the swap's detach, before the
   * markup is replaced). Whatever is returned comes back to resume()
   * verbatim once the response has fully applied. Null means "nothing to
   * carry": the base holds no draft.
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
   * Re-projects a captured draft over the swapped-in markup. The leaf's
   * page element is present again (the engine drops an edit whose leaf
   * the swap removed before reaching here). The base leaves the edit as
   * it stands.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {*} draft
   *   The value captureDraft() returned.
   *
   * @return {Promise<void>}
   */
  resume(EditableElement, draft) {
    return Promise.resolve();
  }

  /**
   * Cancel edit.
   *
   * Give users a way to bail on the current edit.
   */
  cancelEdit(EditableElement, e = null) {
    EditableElement.isCancelingEdit = true;

    return EditableElement.plugin.blur(e).catch((e) => {
      if (e !== 'HTML validation failed') {
        console.error(e);
      }
    });
  }

  /**
   * Get remain focused selectors.
   *
   * The editor-OWNED surfaces a press must not commit the edit from: the
   * editing element itself, CKEditor popups, editor-launched
   * dialogs. The engine's keepsEdit answers the armed session's press
   * decision from this list. Only surfaces this editor owns belong here;
   * page chrome that keeps an edit (the Edit Mode toolbar, the form panel,
   * the Daedalus Stylish inspector) declares data-keeps-edit on its own root
   * instead.
   *
   * @return {string[]}
   *   An array of selectors that will not cause the current edit to end
   *   when clicked.
   */
  getFocusedSelectors() {
    return [];
  }

  /**
   * Inputs.
   *
   * The editor's focusable controls, in traversal order. The armed session
   * extends its native Tab model with these while the edit is open.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Element[]|NodeList}
   *   The editor's input elements.
   */
  inputs(EditableElement) {
    return [];
  }

  /**
   * Applies.
   *
   * When the registry is selecting editors, ensure that this editor applies.
   *
   * @param {EditableElement} EditableElement
   *   The editable element.
   * @return {boolean}
   *   Whether this editor should be used.
   */
  applies(EditableElement) {
    return true;
  }
}
