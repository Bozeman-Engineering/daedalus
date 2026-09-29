import { setCurrentlyEditing } from '../currently-editing.js';

/**
 * The one editor base.
 *
 * Every daedalus_edit editor extends this, whatever it edits (a composed
 * block's parts, a canvas leaf of an entity field, a widget revealed in
 * the entity-form panel) and whatever commits it: the gesture surface
 * (edit / focus / blur / cancel, the validation helpers, inputs() for the
 * edit session's Tab traversal, getFocusedSelectors() for the session's
 * press decision), the value contract (readProperties / writeProperties
 * over the editing surface), the snapshot-and-diff commit, and the draft
 * rule, all live here ONCE. What differs per item is supplied by the
 * ITEM, not the editor:
 * - its VALUE STORE, what committedValues() reads: the blueprint mirror
 *   for a blueprint-edited leaf (BlueprintEditableElement), the widget
 *   itself for a form-edited leaf or a panel widget (EditableElement,
 *   PanelWidgetElement; the real Drupal widget holds the value, the
 *   editing surface reads and writes it);
 * - its COMMIT TRANSPORT (transports.js): the value transport (one
 *   `update_element` op with the changed bag) for a blueprint leaf, the
 *   form transport (the subject form's own AJAX submit naming the one
 *   field, answered by the same mutation payload) for a form leaf or a
 *   panel widget.
 *
 * The gesture's value contract:
 * - edit() snapshots the item's committed values for the properties the
 *   editor reads (the store's word, cloned) onto the item, mounts the
 *   editing surface and focuses it.
 * - blur diffs readProperties() against the snapshot and commits only the
 *   changed properties through the item's transport; an empty diff sends
 *   nothing, so one history entry exists per gesture that changed
 *   something. Escape restores the snapshot through writeProperties() and
 *   sends nothing; a rejected commit rolls back the same way.
 * - The open edit is a DRAFT (captureDraft / resume). A foreign
 *   mutation's response (a style scrub from the Daedalus Stylish
 *   inspector, an undo, an AI turn) swaps the edited markup out from under
 *   the surface; the engine captures the draft as the swap detaches the
 *   subtree and, once the response has fully applied (the subject form
 *   refetched when the response touched the field, so a widget-backed
 *   store reads the remote), hands it to resume(), which re-projects by
 *   the three-way rule per property: base = the snapshot at edit open,
 *   draft = the captured value, remote = the store now. remote != base
 *   means the remote changed the very thing being edited and wins;
 *   otherwise a changed draft is written back and the user keeps typing.
 *   The snapshot becomes the remote, so Escape after a resume restores
 *   server truth. No flag decides; the data does.
 *
 * Failures surface through the one mutation-failure display
 * (Blueprint.reportFailure); a form commit the server's form validation
 * refused re-opens the edit so the widget's errors are in front of the
 * user.
 *
 * @see ../transports.js
 * @see ../editable-element.js
 * @see ../blueprint-element.js
 * @see ../panel-widget-element.js
 * @see ../editor-registry.js
 */

/**
 * Value equality for editing values (scalars, link objects, arrays, field
 * value bags).
 *
 * @param {*} a
 *   One value.
 * @param {*} b
 *   The other value.
 *
 * @return {boolean}
 *   TRUE when the values are structurally equal.
 */
export const equals = (a, b) => {
  if (a === b) {
    return true;
  }
  if (
    typeof a !== 'object' ||
    typeof b !== 'object' ||
    a === null ||
    b === null
  ) {
    return false;
  }
  const aKeys = Object.keys(a);
  const bKeys = Object.keys(b);
  return (
    aKeys.length === bKeys.length &&
    aKeys.every((key) => equals(a[key], b[key]))
  );
};

/**
 * The class marking the element an open edit's surface rides.
 *
 * The session's press decision keeps the edit for a press inside it
 * (getFocusedSelectors); editors add their own surface classes beside it
 * where CSS keys on them (the canvas CKEditor's neutralizer). It rides
 * the item's page element HANDLE, so a panel widget (no handle) carries
 * no mark: the form panel keeps a press through data-keeps-edit instead.
 */
export const EDITING_CLASS = 'daedalus-edit-editing';

/**
 * The class dimming a page element while its widget edits in the panel.
 *
 * The honest-panel gesture: the leaf stays visible as the selection being
 * edited (the selection frame draws the stroke; the CSS lowers opacity).
 * Only ever added to a page element handle by revealFormItem, and only
 * for a reveal the edit itself made; a panel widget has no handle and a
 * wrapper the panel already shows is nobody's reveal, so nothing inside
 * the form panel is ever dimmed.
 */
export const PANEL_EDITING_CLASS = 'daedalus-edit-panel-editing';

/**
 * The panel-hidden class the server ships every field wrapper with.
 */
const HIDDEN_CLASS = 'daedalus-edit-hidden';

export class EditorPluginBase {
  /**
   * The registry key for this editor.
   *
   * Blueprint-edited leaves declare it directly in their attribution
   * (data-daedalus-edit-editor="{id}"); form-edited leaves are matched by widget
   * instead (supportedWidgets). Null falls back to the constructor name at
   * registration.
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

  /**
   * Supported widgets.
   *
   * Form-edited leaves are matched to their editor by the field widget: the
   * registry indexes editors by these IDs and selects by priority. An
   * editor with none is addressed by id (a blueprint leaf's attribution)
   * or is the registry's fallback.
   *
   * @see EditorRegistry.getPlugin()
   *
   * @type {string[]}
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

  init() {}

  /**
   * Edit.
   *
   * The fresh entry: snapshots the item's committed values for the
   * properties this editor reads, makes the item the active edit (the one
   * setter reports it to the session), mounts the editing surface, and
   * focuses it. Hotkeys stay live during an edit: the dispatcher's typing
   * context guards text entry, and the open edit's Tab/Escape depend on
   * dispatch running.
   *
   * @param {Object} item
   *   The item being edited (an EditableElement, a
   *   BlueprintEditableElement or a PanelWidgetElement).
   */
  edit(item) {
    item.snapshot = this.snapshot(item);
    // Reported BEFORE the surface changes: the setter reads the page
    // element to report the edit's key, so it must still stand as
    // rendered.
    setCurrentlyEditing(item);
    this.mount(item);
    item.getPageElementHandle()?.classList.add(EDITING_CLASS);
    this.focus(item);
  }

  /**
   * Mount.
   *
   * Puts up the editing surface over the item (contenteditable on the
   * leaf, a canvas CKEditor, the widget revealed in the panel). Overridden
   * by editors whose surface is not simply the rendered element. Runs on
   * the fresh entry and again on a resume over swapped-in markup, after
   * any surviving draft was written back.
   *
   * @param {Object} item
   *   The item being edited.
   */
  mount(item) {}

  /**
   * Focus.
   *
   * Ending the edit is not this plugin's concern: the open edit's press
   * decision owns "does this click end the edit" and asks the
   * engine's keepsEdit (built on getFocusedSelectors()) whether a press
   * lands on this editor's own surface.
   *
   * @param {Object} item
   *   The item being edited.
   */
  focus(item) {
    this.focusElement(item);
  }

  /**
   * Focus element.
   *
   * Overridden by editors to give their surface focus.
   *
   * @param {Object} item
   *   The item being edited.
   */
  focusElement(item) {}

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
    const item = Drupal.Daedalus.Edit.CurrentlyEditingElement;

    if (!item?.isCancelingEdit && !this.isValid(e)) {
      // A sentinel string, not an Error: the catch sites compare it by
      // identity to tell "invalid widget" from a real failure.
      // eslint-disable-next-line prefer-promise-reject-errors
      return Promise.reject('HTML validation failed');
    }

    item?.getPageElementHandle()?.classList.remove(EDITING_CLASS);
    this.defocus(item);
    let result;
    if (item?.isCancelingEdit) {
      result = this.cancelElement(item);
    } else {
      result = this.blurElement(item);
    }
    setCurrentlyEditing(null);
    return result;
  }

  /**
   * Defocus.
   *
   * Tears down whatever mount() put up, before the edit's outcome
   * (blurElement/cancelElement) runs.
   *
   * @param {Object} item
   *   The item being edited.
   */
  defocus(item) {}

  /**
   * Is valid.
   *
   * Editors gate blur() on this: an invalid edit stays open. Two facts
   * decide, both generic over any widget: a server-side validation marker
   * inside the item's form-item wrapper (a rebuilt form carrying errors)
   * and HTML5 validity over the editor's inputs.
   *
   * @param e
   *   The event if any. Its default is prevented because the mousedown
   *   event doesn't work well with validity checking.
   *
   * @return {boolean}
   *   Whether the current edit is valid and may commit.
   */
  isValid(e = null) {
    const item = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    const wrapper = item?.getFormItemWrapper?.() ?? null;
    if (
      wrapper &&
      (wrapper.matches('.error, .form-item--error') ||
        wrapper.querySelector(
          '.error, .form-item--error, [aria-invalid="true"], .form-item--error-message, .error-message',
        ))
    ) {
      return false;
    }

    const inputs = Array.from(this.inputs(item) ?? []).filter(
      (input) => typeof input.checkValidity === 'function',
    );
    if (inputs.length === 0) {
      return true;
    }
    if (e) {
      e.preventDefault();
    }
    let allValid = true;
    inputs.forEach((input) => {
      if (!input.checkValidity()) {
        this.displayValidationMessage(input.validationMessage, input);
        allValid = false;
      }
    });
    return allValid;
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
   * Remove server-side validation error indicators.
   *
   * A rebuilt form carries its errors as classes and messages inside the
   * form-item wrapper; the user's next input clears them so the edit may
   * commit again.
   *
   * @param {Object} item
   *   The item being edited.
   */
  removeServersideValidationMessage(item) {
    const wrapper = item?.getFormItemWrapper?.() ?? null;
    if (!wrapper) {
      return;
    }
    wrapper.classList.remove('error', 'form-item--error');
    wrapper
      .querySelectorAll('.error, .form-item--error, [aria-invalid="true"]')
      .forEach((el) => {
        el.classList.remove('error', 'form-item--error');
        el.removeAttribute('aria-invalid');
      });
    wrapper
      .querySelectorAll('.form-item--error-message, .error-message')
      .forEach((el) => {
        el.remove();
      });
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
   * Read properties.
   *
   * The editing surface's current values as a flat property bag, keyed by
   * the names the spine sorts to storage (a block's fields and label, an
   * entity's field). Editors MUST implement this: it defines both which
   * properties the editor edits (the snapshot picks these names) and what
   * their current values are on the surface. An editor with no client-side
   * value (a panel widget, whose value leaves only through the form's
   * submit) reads nothing.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {Object<string, *>}
   *   The current property values, keyed by property name.
   */
  readProperties(item) {
    return {};
  }

  /**
   * Write properties.
   *
   * Writes a property bag back onto the editing surface: the restore half
   * of the contract (Escape, a rejected commit's rollback) and the draft's
   * re-projection on a resume. Editors MUST implement this for the
   * properties they read.
   *
   * @param {Object} item
   *   The item being edited.
   * @param {Object<string, *>} properties
   *   The property values to write.
   */
  writeProperties(item, properties) {}

  /**
   * Snapshot.
   *
   * The edited properties' committed values, read from the item's value
   * store (the blueprint mirror, or the widget itself, see the class
   * docblock) and cloned so later replacements can't reach into it.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {Object<string, *>}
   *   The snapshot property bag.
   */
  snapshot(item) {
    const surface = () => this.readProperties(item);
    return structuredClone(item.committedValues(Object.keys(surface()), surface));
  }

  /**
   * Changed properties.
   *
   * Diffs the editing surface's current values against the edit's
   * snapshot.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {Object<string, *>}
   *   The properties whose values changed during this edit gesture.
   */
  changedProperties(item) {
    const snapshot = item.snapshot ?? {};
    const changed = {};
    Object.entries(this.readProperties(item)).forEach(([name, value]) => {
      if (!equals(value, snapshot[name])) {
        changed[name] = value;
      }
    });
    return changed;
  }

  /**
   * Blur element.
   *
   * Commits the edit gesture: an empty diff sends nothing; a change
   * commits through the item's transport. The gesture is over the moment
   * the commit is queued (the surface already shows the change), so blur
   * resolves immediately instead of holding Tab traversal hostage to the
   * round trip: the FIFO queue keeps ordering and a failure reports
   * through the one failure display, not through this promise.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {Promise<void>}
   */
  blurElement(item) {
    const changed = this.changedProperties(item);
    if (Object.keys(changed).length > 0) {
      this.commit(item, changed);
    }
    return Promise.resolve();
  }

  /**
   * Cancel element.
   *
   * Escape's half of the contract: restore the snapshot onto the editing
   * surface and send nothing.
   *
   * @param {Object} item
   *   The item being canceled.
   *
   * @return {Promise<void>}
   */
  cancelElement(item) {
    this.writeProperties(item, item.snapshot ?? {});
    return Promise.resolve();
  }

  /**
   * Capture draft.
   *
   * The editing surface's current values, read while the surface still
   * stands (the swap's detach): the same bag blur would diff. Whatever is
   * returned comes back to resume() verbatim once the response has fully
   * applied.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {*}
   *   The draft property bag.
   */
  captureDraft(item) {
    return this.readProperties(item);
  }

  /**
   * Reset gesture.
   *
   * Per-gesture state an editor keeps beside the snapshot (a link
   * control's preview) belongs to the surface the swap just replaced;
   * resume() calls this before re-snapshotting so nothing stale shadows
   * the remote. The base keeps no such state.
   *
   * @param {Object} item
   *   The item being edited.
   */
  resetGesture(item) {}

  /**
   * Resume.
   *
   * The three-way re-projection (see the class docblock): the snapshot is
   * re-taken from the item's store (the remote), the draft's changed
   * properties survive where the remote left the base alone and are
   * written back, and the surface is mounted and focused again on the
   * swapped-in element. The fresh entry (edit()) is not re-run: the edit
   * never closed.
   *
   * @param {Object} item
   *   The item being edited.
   * @param {Object<string, *>} draft
   *   The bag captureDraft() returned.
   *
   * @return {Promise<void>}
   */
  resume(item, draft) {
    const base = item.snapshot ?? {};
    this.resetGesture(item);
    const remote = this.remoteValues(item);
    item.snapshot = remote;
    const survivors = this.survivingProperties(draft, base, remote);
    if (Object.keys(survivors).length > 0) {
      this.writeProperties(item, survivors);
    }
    this.mount(item);
    item.getPageElementHandle()?.classList.add(EDITING_CLASS);
    this.focus(item);
    return Promise.resolve();
  }

  /**
   * Remote values.
   *
   * The store's values after a foreign response, the remote of the
   * three-way rule: a fresh snapshot by default (the mirror, or the
   * possibly refetched widget). An editor whose store is dirty during the
   * edit (the text field mirrors every keystroke into its input) answers
   * the base instead when the store was not refetched, because the store
   * then holds the draft, not a remote.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {Object<string, *>}
   *   The remote property bag.
   */
  remoteValues(item) {
    return this.snapshot(item);
  }

  /**
   * Surviving properties.
   *
   * The three-way rule, per property: a draft value survives when it
   * differs from the base (the user changed it) and the remote left the
   * base alone (the response did not change it). The remote is compared
   * against `mirrorBase`, the snapshot by default; an editor whose
   * snapshot re-baselines away from the store's own bytes (the formatted
   * text editor keeps CKEditor's normalization there) passes the store's
   * value at edit open instead, so a response that merely echoed the
   * stored markup does not read as a remote change.
   *
   * @param {?Object<string, *>} draft
   *   The captured draft bag.
   * @param {Object<string, *>} base
   *   The snapshot the draft is compared against.
   * @param {Object<string, *>} remote
   *   The store's values after the response.
   * @param {Object<string, *>} mirrorBase
   *   The values the remote is compared against; the base unless given.
   *
   * @return {Object<string, *>}
   *   The draft properties to re-project.
   */
  survivingProperties(draft, base, remote, mirrorBase = base) {
    const survivors = {};
    Object.entries(draft ?? {}).forEach(([name, value]) => {
      if (
        !equals(value, base[name]) &&
        equals(mirrorBase[name], remote[name])
      ) {
        survivors[name] = value;
      }
    });
    return survivors;
  }

  /**
   * Commit.
   *
   * Hands the gesture's changed properties to the item's transport (one
   * `update_element` op for a blueprint leaf, the subject form's submit
   * for a form leaf or a panel widget). Rollback restores the snapshot;
   * failures go to the one mutation-failure display keyed by the item's
   * selectable element, and a form the server's validation refused
   * re-opens the edit over the widget carrying the errors.
   *
   * @param {Object} item
   *   The item being edited.
   * @param {Object<string, *>} properties
   *   The changed properties to send.
   *
   * @return {Promise<Object>}
   *   The op-queue promise. Rejections are already reported; the return
   *   value exists for callers that want to sequence on the round trip.
   */
  commit(item, properties) {
    const snapshot = item.snapshot ?? {};
    const request = item.transport.commit(item, properties, {
      label: this.commitLabel(item, properties),
      rollback: () => this.writeProperties(item, snapshot),
    });
    request.catch((error) => this.commitFailed(item, error));
    return request;
  }

  /**
   * Commit failed.
   *
   * @param {Object} item
   *   The item whose commit failed.
   * @param {Error} error
   *   The rejection.
   */
  commitFailed(item, error) {
    if (error?.validation) {
      // The rebuilt form carries the widget's errors; put the edit back
      // in front of the user: the edit was open, validation failed, so it
      // re-opens through the one editor door.
      const key = item.sessionKey ?? item.selectableKey ?? null;
      if (key) {
        Drupal.Daedalus.EditSession.openEditor(key);
      }
      return;
    }
    Drupal.Daedalus.Blueprint.reportFailure(
      error,
      item.selectableKey ?? item.getPageElement() ?? undefined,
    );
  }

  /**
   * Commit label.
   *
   * The gesture's history-entry label on the value transport (the form
   * transport's server names its own entry by the field). The default
   * names the component by its minted name (`_element.name`, the same
   * word the tree row and the chips read); editors with a better word for
   * the gesture override this.
   *
   * @param {Object} item
   *   The item being edited.
   * @param {Object<string, *>} properties
   *   The changed properties being sent.
   *
   * @return {string}
   *   The history label.
   */
  commitLabel(item, properties) {
    const component = this.mirrorComponent(item);
    return Drupal.t('Edited @label', {
      '@label': component?._element?.name ?? Drupal.t('block'),
    });
  }

  /**
   * Mirror component.
   *
   * The item's component node in the blueprint mirror, for items that
   * address one (a blueprint-edited leaf).
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {?Object}
   *   The component, or null.
   */
  mirrorComponent(item) {
    if (!item.componentUuid) {
      return null;
    }
    return Drupal.Daedalus.Blueprint.componentForUuid(
      Drupal.Daedalus.Blueprint.getBlueprint(),
      item.componentUuid,
    );
  }

  /**
   * Cancel edit.
   *
   * Give users a way to bail on the current edit.
   */
  cancelEdit(item, e = null) {
    item.isCancelingEdit = true;

    return item.plugin.blur(e).catch((error) => {
      if (error !== 'HTML validation failed') {
        console.error(error);
      }
    });
  }

  /**
   * Reveals a form item's widget in-flow in the entity-form panel.
   *
   * The honest-panel gesture for a form-edited leaf whose editor is the
   * widget itself: the wrapper is unhidden, the widget scrolled into view,
   * and the page element stays visible, dimmed as the selection being
   * edited (PANEL_EDITING_CLASS). Clicking the dimmed page element
   * commits and re-opens the edit through the open edit's press
   * decision.
   *
   * The reveal is judged per EDIT, not per call: `item.revealed` is set
   * when this call unhid the wrapper and stays set until hideFormItem
   * (a resume re-mounts over swapped-in markup and finds the wrapper
   * already visible, but the swapped-in page element still needs the
   * dim). A wrapper the panel already showed before the edit (a revealed
   * panel widget, the panel-items.js kind) is nobody's reveal: nothing is
   * dimmed and nothing is re-hidden after, and such an item has no page
   * element handle anyway.
   *
   * @param {Object} item
   *   A form-edited item (getFormItemWrapper / getFormItemHandle).
   * @param {boolean} dim
   *   Whether the page element is dimmed as the selection being edited
   *   (false for an editor whose page element IS the editing surface).
   *
   * @return {boolean}
   *   Whether the reveal is this edit's (so the teardown re-hides it).
   */
  revealFormItem(item, dim = true) {
    const wrapper = item.getFormItemWrapper?.();
    if (!wrapper) {
      return false;
    }
    const wasHidden = wrapper.classList.contains(HIDDEN_CLASS);
    wrapper.classList.remove(HIDDEN_CLASS);
    const handle = item.getFormItemHandle?.() ?? wrapper;
    handle.classList.remove(HIDDEN_CLASS);
    item.revealed = item.revealed === true || wasHidden;
    if (dim && item.revealed) {
      item.getPageElementHandle()?.classList.add(PANEL_EDITING_CLASS);
    }
    // Widen before scrolling so the scroll target has its final geometry.
    this.widenPanelIfNeeded(item);
    handle.scrollIntoView({ block: 'nearest' });
    return item.revealed;
  }

  /**
   * Re-hides a form item's widget after the edit, the counterpart of
   * revealFormItem, releasing the page element's dim, the reveal flag
   * and the panel width.
   *
   * @param {Object} item
   *   A form-edited item.
   */
  hideFormItem(item) {
    const wrapper = item.getFormItemWrapper?.();
    if (!wrapper) {
      return;
    }
    wrapper.classList.add(HIDDEN_CLASS);
    const handle = item.getFormItemHandle?.() ?? wrapper;
    handle.classList.add(HIDDEN_CLASS);
    item.revealed = false;
    item.getPageElementHandle()?.classList.remove(PANEL_EDITING_CLASS);
    this.releasePanelWidth(item);
  }

  /**
   * Widen the form panel when the revealed widget doesn't fit.
   *
   * Wide widgets (multi-value tables, media grids, min-width inputs) can't
   * be known statically, so the need is measured: the panel clips
   * horizontal overflow, which makes scrollWidth > clientWidth on the form
   * item a direct signal, and the deficit is exactly the extra width to
   * request. Widgets that wrap without overflowing measure as fitting;
   * the user's rail resize handle is the tool for taste.
   *
   * Grow-only for the duration of the edit (an AJAX rebuild that shrinks
   * the widget doesn't snap the panel back mid-edit); the panel reverts to
   * the rail width when the edit releases it (releasePanelWidth). Re-run
   * on behaviors re-attach inside the panel, since "Add another item" and
   * friends change the widget's size mid-edit.
   *
   * @see Drupal.behaviors.DaedalusEditPanelAutoWiden
   *
   * @param {Object} item
   *   A form-edited item.
   */
  widenPanelIfNeeded(item) {
    // The width request targets the PANEL, not the cached form wrapper
    // inside it: the panel is the element whose inline-size the rail
    // honors.
    const panel = item.getForm?.()?.closest('.daedalus-ui-sidebar');
    const formItem = item.getFormItemHandle?.() ?? item.getFormItemWrapper?.();
    if (!panel || !formItem) {
      return;
    }
    const deficit = formItem.scrollWidth - formItem.clientWidth;
    if (deficit <= 0) {
      return;
    }
    const needed = panel.offsetWidth + deficit;
    if (needed > (item.widenedPanelTo ?? 0)) {
      item.widenedPanelTo = needed;
      Drupal.Daedalus.SidebarManager.setPanelWidth(panel, needed);
    }
  }

  /**
   * Lets the panel follow the rail width again once the edit is over.
   *
   * @param {Object} item
   *   A form-edited item.
   */
  releasePanelWidth(item) {
    if (!item.widenedPanelTo) {
      return;
    }
    item.widenedPanelTo = null;
    const panel = item.getForm?.()?.closest('.daedalus-ui-sidebar');
    if (panel) {
      Drupal.Daedalus.SidebarManager.releasePanelWidth(panel);
    }
  }

  /**
   * Get remain focused selectors.
   *
   * The editor-OWNED surfaces a press must not commit the edit from: the
   * element the edit rides (EDITING_CLASS), CKEditor's floating chrome
   * (its toolbar and balloons render into .ck-body-wrapper on <body>),
   * autocompletes, and editor-launched dialogs (image upload, media
   * library: jQuery UI dialogs). The engine's keepsEdit answers the open
   * edit's press decision from this list. Only surfaces this editor
   * owns belong here; page chrome that keeps an edit (the Edit Mode
   * toolbar, the form panel, the Daedalus Stylish inspector) declares
   * data-keeps-edit on its own root instead.
   *
   * @return {string[]}
   *   An array of selectors that will not cause the current edit to end
   *   when clicked.
   */
  getFocusedSelectors() {
    return [
      `.${EDITING_CLASS}`,
      '.ck-body-wrapper',
      '.ui-autocomplete',
      '.ui-widget',
      '#drupal-modal',
      '.ui-dialog-buttonpane',
    ];
  }

  /**
   * Inputs.
   *
   * The editor's focusable controls, in traversal order. The open edit
   * extends its native Tab model with these.
   *
   * @param {Object} item
   *   The item being edited.
   *
   * @return {Element[]|NodeList}
   *   The editor's input elements.
   */
  inputs(item) {
    return [];
  }

  /**
   * Applies.
   *
   * When the registry is selecting editors, ensure that this editor applies.
   *
   * @param {Object} item
   *   The item.
   * @return {boolean}
   *   Whether this editor should be used.
   */
  applies(item) {
    return true;
  }
}
