/**
 * Blueprint Editable Element.
 *
 * The state object for a blueprint-edited leaf — the counterpart of
 * EditableElement (the form-edited leaf's state). A blueprint leaf carries
 * only identity in its attribution, never values:
 * - data-daedalus-edit-editor: the editor's registry key.
 * - data-daedalus-edit-editor-settings: JSON settings stamped by the template's
 *   preprocess (e.g. a tag field's allowed_values).
 * - The component uuid comes from the enclosing block wrapper's selectable
 *   key (data-selectable="block:{uuid}") — the one addressing scheme.
 *
 * Values are read from the blueprint mirror by the editor, never parsed
 * from the DOM.
 *
 * @see editable-element.js
 * @see plugins/blueprint-editor-base.js
 */
export class BlueprintEditableElement {
  /**
   * Whether the edit is being canceled (Escape key pressed).
   *
   * When true, the snapshot is restored and nothing is committed.
   *
   * @type {boolean}
   */
  isCancelingEdit = false;

  /**
   * The mirror snapshot of the edited properties, set by the editor's
   * edit(). Per-edit state lives here because editor plugin instances are
   * registry singletons shared across leaves.
   *
   * @type {?Object<string, *>}
   */
  snapshot = null;

  /**
   * The draft captured as a markup swap detached the editing surface,
   * pending the engine's resume once the response has fully applied.
   * Undefined when no swap touched the edit (the engine's "nothing to
   * resume" signal); the engine sets and consumes it.
   *
   * @type {*}
   *
   * @see edit-session-engine.js (DaedalusEditDraft, mutationApplied)
   */
  draft = undefined;

  constructor(element) {
    this.editorId = element.dataset.daedalusEditEditor;

    /**
     * The editor settings stamped into the attribution by preprocess.
     *
     * @type {Object}
     */
    this.settings = JSON.parse(element.dataset.daedalusEditEditorSettings ?? '{}');

    const wrapper = element.closest('[data-selectable^="block:"]');
    if (!wrapper) {
      // Attribution without an addressable component is a template bug: the
      // stamp must live inside the block placement's selectable wrapper.
      throw new Error(
        `Blueprint-edited leaf "${this.editorId}" has no enclosing block:{uuid} selectable wrapper.`,
      );
    }

    /**
     * The block placement's selectable key (block:{uuid}).
     *
     * @type {string}
     */
    this.selectableKey = wrapper.getAttribute('data-selectable');

    /**
     * The component uuid `update_element` ops address inside the root
     * entity.
     *
     * @type {string}
     */
    this.componentUuid = this.selectableKey.split(':')[1];

    /**
     * The inline editing editor plugin for this leaf.
     *
     * @type {BlueprintEditorBase}
     */
    this.plugin = Drupal.Daedalus.Edit.Editors.get(this.editorId);
    if (!this.plugin) {
      throw new Error(
        `No editor "${this.editorId}" is registered for a blueprint-edited leaf.`,
      );
    }
  }

  /**
   * Get page element.
   *
   * Re-resolved through the attribution rather than held as a reference,
   * because the mutation response's markup swap replaces the subtree — the
   * template re-stamps the attribution on every render, so the lookup
   * survives swaps.
   *
   * @return {?Element}
   *   The leaf's composed element on the page.
   */
  getPageElement() {
    const wrapper = document.querySelector(
      `[data-selectable="${this.selectableKey}"]`,
    );
    if (!wrapper) {
      return null;
    }
    const selector = `[data-daedalus-edit-editor="${this.editorId}"]`;
    return wrapper.matches(selector)
      ? wrapper
      : wrapper.querySelector(selector);
  }
}
