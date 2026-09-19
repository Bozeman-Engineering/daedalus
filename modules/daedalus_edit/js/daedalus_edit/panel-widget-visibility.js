/**
 * @file
 * Panel widget visibility: a field's form-item wrapper in the entity-form
 * panel is hidden iff the field has a leaf on the canvas.
 *
 * The server ships every field wrapper hidden (FormDefaultFieldAttributes,
 * FormMediaFieldAttributes) and decides nothing else: whether a field
 * renders on the canvas is a theme and layout fact (the product theme
 * places no page title block; an empty or unplaced Layout Builder field
 * renders nothing) that only the client can see, and the view display's
 * hidden list the server used to consult was a proxy that failed exactly
 * there. This behavior is the one place the decision is made, for every
 * field alike:
 *
 * - Leaf present (a [data-daedalus-edit-field-value-wrapper] outside the form
 *   cache whose id starts with the wrapper's entity::id::field:: prefix;
 *   the leaf's id ends in the field's main property where the wrapper's
 *   always says 'value'): the wrapper is hidden and carries no
 *   auto-submit. The leaf's editor owns the widget; the form-swap
 *   machinery reveals it for the edit's duration.
 * - Leaf absent: the wrapper (and any form-item handle a previous edit's
 *   teardown left hidden inside it) is revealed in-flow and carries
 *   data-daedalus-edit-auto-submit, so DaedalusEditEntityFormAutoSave stages its
 *   changes to tempstore on change, under any tool.
 *
 * Exemption: the wrapper of the CURRENT edit
 * (Drupal.Daedalus.Edit.CurrentlyEditingElement) is never touched. The swap
 * machinery (FormEditorBase.replacePageElementWithFormItem and
 * hideAncillary, the inline editor's textarea swap) owns its visibility
 * while the edit is open, and a behavior pass mid-edit (a widget's own
 * AJAX, a foreign mutation's swap) must not hide the widget under the
 * user. Only a form-edited leaf (EditableElement) has a wrapper to
 * exempt: a blueprint-edited leaf (BlueprintEditableElement, the text,
 * image and formatted-text blocks) edits the blueprint mirror in place
 * with no entity form behind it, so while one is open nothing is exempt.
 *
 * Runs on every behavior attach regardless of context and re-evaluates
 * every wrapper in the form-cache mount against the live document: a form
 * arrival (AppendCommand), a form rebuild (ReplaceCommand) and a canvas
 * swap (updateElement re-attaches on the whole document) all change the
 * answer, and the answer is one querySelector per wrapper.
 */

((Drupal, drupalSettings) => {
  const MOUNT_ID = 'daedalus-edit-form-cache';
  const AUTO_SUBMIT = 'data-daedalus-edit-auto-submit';
  const HIDDEN = 'daedalus-edit-hidden';

  /**
   * Whether the wrapper's field has a leaf on the canvas.
   *
   * @param {Element} wrapper
   *   A form-item wrapper carrying data-daedalus-edit-form-item-wrapper-id.
   * @param {Element} mount
   *   The form-cache mount; a render inside a cached form (a widget's own
   *   entity preview) is not the canvas.
   *
   * @return {boolean}
   *   True when at least one canvas leaf carries the field's identity.
   */
  const hasCanvasLeaf = (wrapper, mount) => {
    const prefix = wrapper.dataset.daedalusEditFormItemWrapperId
      .split('::')
      .slice(0, 3)
      .join('::');
    return [
      ...document.querySelectorAll(
        `[data-daedalus-edit-field-value-wrapper^="${prefix}::"]`,
      ),
    ].some((leaf) => !mount.contains(leaf));
  };

  /**
   * Reconciles every cached wrapper's visibility with the canvas.
   */
  const reconcile = () => {
    const mount = document.getElementById(MOUNT_ID);
    if (!mount) {
      return;
    }
    // The current edit's wrapper, when the edit has one (see the file
    // comment: a blueprint-edited leaf has no form-item wrapper).
    const editing = Drupal.Daedalus.Edit?.CurrentlyEditingElement;
    const exempt =
      typeof editing?.getFormItemWrapper === 'function'
        ? (editing.getFormItemWrapper() ?? null)
        : null;
    const revealedIn = new Set();
    mount
      .querySelectorAll(
        '.daedalus-edit-form-item-wrapper[data-daedalus-edit-form-item-wrapper-id]',
      )
      .forEach((wrapper) => {
        if (wrapper === exempt) {
          return;
        }
        if (hasCanvasLeaf(wrapper, mount)) {
          wrapper.classList.add(HIDDEN);
          wrapper.removeAttribute(AUTO_SUBMIT);
          return;
        }
        wrapper.classList.remove(HIDDEN);
        wrapper
          .querySelectorAll(`.daedalus-edit-form-item.${HIDDEN}`)
          .forEach((handle) => handle.classList.remove(HIDDEN));
        if (!wrapper.hasAttribute(AUTO_SUBMIT)) {
          wrapper.setAttribute(AUTO_SUBMIT, '');
          revealedIn.add(wrapper.closest('form'));
        }
      });
    // Bind the auto-save on what this pass revealed, whatever the behavior
    // order of this attach: once() keeps a wrapper from binding twice, and
    // the listener reads the attribute at event time, so a withdrawn
    // auto-submit goes inert without unbinding.
    revealedIn.forEach((form) => {
      Drupal.behaviors.DaedalusEditEntityFormAutoSave.attach(form, drupalSettings);
    });
  };

  Drupal.behaviors.daedalusEditPanelWidgetVisibility = {
    attach: reconcile,
  };
})(Drupal, drupalSettings);
