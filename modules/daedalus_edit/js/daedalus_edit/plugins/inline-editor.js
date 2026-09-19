import * as ckeditor5EditorInline from '../../../assets/vendor/ckeditor5/editor-inline.js'; // Do not remove!
import * as core from '../copied_from_core/copied-from-core.js';
import * as editableElement from '../editable-element.js';
import { FormEditorBase } from './form-editor-base.js';
import * as entityForm from '../entity-form.js';
import { Identity } from '../ckeditor/identity.js';

/**
 * InlineEditor field editor.
 *
 * Canvas-native rich text: the inline CKEditor attaches to the rendered
 * page element itself, so editing formatted text feels like editing plain
 * text (textfield.js) — on the canvas, styled by the page's own CSS.
 *
 * The wrinkle that shapes everything here: the canvas shows the FILTERED
 * output while the editor must edit the RAW value. At edit start the page
 * element's filtered markup is saved and swapped for the raw value from
 * the form's textarea; on blur the editor data goes back into the textarea
 * and the tempstore update re-renders the filtered output. When nothing
 * changed (or the edit is canceled) no re-render happens, so the saved
 * filtered markup is restored by hand.
 *
 * The form panel still holds the real textarea (hidden — it is what the
 * form submit serializes) and the text format selector, which is revealed
 * as the edit's ancillary UI. Text formats with no CKEditor configured
 * fall back to editing the revealed textarea in the panel.
 *
 * The open edit is a DRAFT (FormEditorBase's three-way landing). A foreign
 * mutation's response swaps the page element out from under the canvas
 * CKEditor and, when the mutation changed the owning entity, evicts the
 * form with the textarea. captureDraft reads the live editor's data into
 * the textarea and returns it as the draft, tearing down the orphan editor
 * (it rides the element being replaced). On a surviving resume, reseed
 * writes the draft into the (possibly refetched) textarea, so the reveal
 * seeds a fresh canvas editor from it as on a fresh entry; the filtered
 * markup is re-saved from the swapped-in page element on the way. The
 * recorded original data stays the edit's opening value, so blur diffs the
 * draft against it and commits.
 */
class InlineEditor extends FormEditorBase {
  id = 'inline_editor';

  /**
   * Supported widgets.
   *
   * @type {[string]}
   */
  supportedWidgets = ['text_textarea', 'text_textarea_with_summary'];

  /**
   * Original data.
   *
   * The raw value at edit start so we can determine if it's been changed.
   *
   * @type {Map<any, any>}
   */
  originalData = new Map();

  /**
   * Original markup.
   *
   * The page element's FILTERED innerHTML at edit start. Restored when the
   * edit ends without a data change (no tempstore re-render happens then)
   * and on cancel.
   *
   * @type {Map<any, any>}
   */
  originalMarkup = new Map();

  /**
   * Get the active text format for a form item.
   *
   * Read live from the format selector (or the hidden single-format input)
   * rather than the form item's data-daedalus-edit-format attribute, which goes
   * stale when the user switches formats mid-session.
   */
  getFormat(formItem) {
    const textarea = formItem.querySelector('textarea');
    const formatElement = document.querySelector(
      `[data-inline-editor-for="${textarea.id}"]`,
    );
    return formatElement?.value;
  }

  replacePageElementWithFormItem(EditableElement) {
    const formItem = EditableElement.getFormItem();
    const textarea = formItem.querySelector('textarea');
    const format = this.getFormat(formItem);
    const editorConfig = drupalSettings.editor?.formats?.[format];

    if (!editorConfig) {
      // No CKEditor for this format: the base panel reveal with the
      // textarea unhidden — editing happens in the panel.
      super.replacePageElementWithFormItem(EditableElement);
      textarea.classList.remove('daedalus-edit-hidden');
      return;
    }

    // Canvas-native tier. Reveal the form item in the panel for its
    // ancillary UI (the format selector) — but without the base gesture's
    // panel-editing outline: the page element is not a dimmed selection
    // here, it IS the editor.
    EditableElement.getFormItemWrapper().classList.remove('daedalus-edit-hidden');
    EditableElement.getFormItemHandle().classList.remove('daedalus-edit-hidden');

    const pageElement = EditableElement.getPageElementHandle();
    if (pageElement.dataset.ckeditor5Id) {
      // An editor already rides this element (e.g. a validation-error
      // re-open before teardown finished).
      return;
    }

    // Swap filtered output for the raw value and put the editor on the
    // real element.
    this.originalMarkup.set(
      EditableElement.info.elementId,
      pageElement.innerHTML,
    );
    pageElement.innerHTML = textarea.value;
    pageElement.classList.add('daedalus-edit-inline-editing');
    Drupal.behaviors.DaedalusEditInlineEditors.attachEditor(
      pageElement,
      editorConfig,
    );
  }

  replaceFormItemWithPageElement(EditableElement) {
    super.replaceFormItemWithPageElement(EditableElement);
    // Re-hide the textarea in case the no-editor tier revealed it.
    EditableElement.getFormItem()
      .querySelector('textarea')
      .classList.add('daedalus-edit-hidden');
  }

  rememberOriginal(EditableElement) {
    // Remember what the original data was so that we can update the
    // tempstore if it has changed. The textarea always holds the raw value
    // at edit start: the editor is seeded from it.
    const textarea = EditableElement.getFormItem().querySelector('textarea');
    this.originalData.set(EditableElement.info.elementId, textarea.value);
  }

  focusElement(EditableElement) {
    // A canvas editor focuses itself when its async create resolves
    // (see attachEditor). Only the panel textarea needs focusing here.
    if (!EditableElement.getPageElementHandle().dataset.ckeditor5Id) {
      EditableElement.getFormItem().querySelector('textarea').focus();
    }
  }

  /**
   * Capture draft.
   *
   * Called from the swap's detach while the page element and the textarea
   * still stand. The live editor's data is the draft (the textarea's on
   * the no-editor tier, or while the editor's async create has not
   * resolved: no user input to carry). The editor rides the element being
   * replaced, so it is torn down here by its stamped id; a create still in
   * flight finds the editing mark gone on its element and destroys its
   * own orphan (attachEditor's guard). The saved filtered markup dies with
   * the old element; the reveal re-saves it from the swapped-in one.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {string}
   *   The raw draft value.
   */
  captureDraft(EditableElement) {
    const elementId = EditableElement.info.elementId;
    const textarea = EditableElement.getFormItem().querySelector('textarea');
    const pageElement = EditableElement.getPageElementHandle();
    const ckeditor5Id = pageElement.dataset.ckeditor5Id;
    const editor = ckeditor5Id
      ? Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id)
      : null;
    if (editor) {
      textarea.value = editor.getData();
      this.defocusCkeditor(editor);
    }
    pageElement.classList.remove('daedalus-edit-inline-editing');
    if (ckeditor5Id) {
      Drupal.behaviors.DaedalusEditInlineEditors.detachEditor(
        pageElement,
        ckeditor5Id,
      );
    }
    this.originalMarkup.delete(elementId);
    return textarea.value;
  }

  reseed(EditableElement, draft) {
    EditableElement.getFormItem().querySelector('textarea').value = draft;
  }

  concede(EditableElement) {
    super.concede(EditableElement);
    const elementId = EditableElement.info.elementId;
    this.originalData.delete(elementId);
    this.originalMarkup.delete(elementId);
  }

  blurElement(EditableElement) {
    const elementId = EditableElement.info.elementId;
    const textarea = EditableElement.getFormItem().querySelector('textarea');
    const pageElement = EditableElement.getPageElementHandle();
    const originalData = this.originalData.get(elementId);
    const ckeditor5Id = pageElement.dataset.ckeditor5Id;
    const editor = ckeditor5Id
      ? Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id)
      : null;

    const finish = (data) => {
      textarea.value = data;
      this.replaceFormItemWithPageElement(EditableElement);
      if (data !== originalData) {
        entityForm.updateTempstore(EditableElement, data.length === 0);
      } else if (this.originalMarkup.has(elementId)) {
        // No re-render is coming; put the filtered output back ourselves.
        pageElement.innerHTML = this.originalMarkup.get(elementId);
      }
      this.originalMarkup.delete(elementId);
    };

    if (editor) {
      const data = editor.getData();
      this.defocusCkeditor(editor);
      return editor.destroy().then(() => {
        Drupal.Daedalus.Edit.Ckeditor5Instances.delete(ckeditor5Id);
        pageElement.removeAttribute('data-ckeditor5-id');
        pageElement.classList.remove('daedalus-edit-inline-editing');
        finish(data);
      });
    }

    // The no-editor tier (panel textarea), or the editor never finished
    // building (attachEditor's create guard destroys it on resolve).
    pageElement.classList.remove('daedalus-edit-inline-editing');
    pageElement.removeAttribute('data-ckeditor5-id');
    finish(textarea.value);
    return Promise.resolve();
  }

  /**
   * Defocus CKEditor before destroying it.
   *
   * Delegates to the shared canvas-editor machinery; both commit paths
   * (this form-swap editor and the blueprint formatted-text editor) tear
   * down canvas CKEditors and need the same workaround.
   *
   * @see Drupal.behaviors.DaedalusEditInlineEditors.defocusEditor
   */
  defocusCkeditor(editor) {
    Drupal.behaviors.DaedalusEditInlineEditors.defocusEditor(editor);
  }

  cancelElement(EditableElement) {
    this.removeClientsideValidationMessage(this.validationMessageHandle());

    const elementId = EditableElement.info.elementId;
    const textarea = EditableElement.getFormItem().querySelector('textarea');
    const pageElement = EditableElement.getPageElementHandle();
    const originalData = this.originalData.get(elementId);
    const ckeditor5Id = pageElement.dataset.ckeditor5Id;
    const editor = ckeditor5Id
      ? Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id)
      : null;

    const finish = () => {
      pageElement.removeAttribute('data-ckeditor5-id');
      pageElement.classList.remove('daedalus-edit-inline-editing');
      textarea.value = originalData;
      if (this.originalMarkup.has(elementId)) {
        pageElement.innerHTML = this.originalMarkup.get(elementId);
        this.originalMarkup.delete(elementId);
      }
      this.replaceFormItemWithPageElement(EditableElement);
    };

    if (editor) {
      this.defocusCkeditor(editor);
      return editor.destroy().then(() => {
        Drupal.Daedalus.Edit.Ckeditor5Instances.delete(ckeditor5Id);
        finish();
      });
    }
    finish();
    return Promise.resolve();
  }

  getFocusedSelectors() {
    const selectors = super.getFocusedSelectors();
    // The page element being edited must keep focus when clicked — it is
    // the editor.
    selectors.push('.daedalus-edit-inline-editing');
    return selectors;
  }

  /**
   * Get data.
   *
   * Gets the user input from either the canvas CKEditor or the panel
   * textarea.
   *
   * @param formItem
   *   The form item to retrieve data from.
   *
   * @return {*}
   *   The user input, or null while a canvas editor is still building.
   */
  getInputValue(formItem) {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    const ckeditor5Id =
      EditableElement?.getPageElementHandle().dataset.ckeditor5Id;
    if (ckeditor5Id) {
      const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id);
      // The editor's async create hasn't resolved yet — no user input can
      // exist. Callers treat null as "unchanged".
      return editor ? editor.getData() : null;
    }
    return formItem.querySelector('textarea').value;
  }

  isValid(e = null) {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    const formItem = EditableElement.getFormItem();

    // Sync the textarea so HTML5/server validation and form serialization
    // see the editor's current data.
    const data = this.getInputValue(formItem);
    const textarea = formItem.querySelector('textarea');
    if (data !== null && textarea.value !== data) {
      textarea.value = data;
    }

    return super.isValid(e);
  }

  validationMessageHandle() {
    const EditableElement = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    const pageElement = EditableElement.getPageElementHandle();
    if (pageElement.dataset.ckeditor5Id) {
      return pageElement;
    }
    return EditableElement.getFormItem().querySelector('textarea');
  }

  clearValidationMessageOnUserInput(validationMessageHandle) {
    const removeClientsideValidationMessage =
      this.removeClientsideValidationMessage;

    const ckeditor5Id = validationMessageHandle.dataset?.ckeditor5Id;
    if (ckeditor5Id) {
      const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id);
      editor.model.document.on('change:data', () => {
        removeClientsideValidationMessage(validationMessageHandle);
      });
    } else {
      // Listen for user input.
      validationMessageHandle.addEventListener('input', (e) => {
        removeClientsideValidationMessage(e.target);
      });
    }
  }
}

/**
 * Register the InlineEditor field editor.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new InlineEditor());

  /**
   * The Inline CKEditor instances.
   */
  Drupal.Daedalus.Edit.Ckeditor5Instances = new Map();

  /**
   * Inline Editors.
   *
   * Editors are created lazily at edit time, directly on the canvas element
   * (see InlineEditor.replacePageElementWithFormItem) — nothing attaches at
   * form load. This behavior only wires up the text format selectors so a
   * mid-edit format switch rebuilds the canvas editor with the new format's
   * toolbar and plugins.
   */
  Drupal.behaviors.DaedalusEditInlineEditors = {
    attach: (context, settings) => {
      once('DaedalusEditInlineEdit', '[data-inline-editor-for]', context).forEach(
        (formatSelector) => {
          // Listen for Editor format changes.
          formatSelector.addEventListener('change', (e) => {
            // Ignore the filter module changes on page load.
            if (!e.isTrusted) {
              return;
            }
            const textarea = document.querySelector(
              `#${e.currentTarget.dataset.inlineEditorFor}`,
            );
            const formItem = textarea.closest('.daedalus-edit-form-item');
            const EditableElement =
              editableElement.EditableElement.createFromFormItem(formItem);
            const plugin = EditableElement.plugin;
            const pageElement = EditableElement.getPageElementHandle();
            const ckeditor5Id = pageElement.dataset.ckeditor5Id;
            const editorConfig =
              drupalSettings.editor?.formats?.[e.currentTarget.value];

            const reattach = () => {
              if (editorConfig) {
                // Move the edit (back) onto the canvas with the new format.
                textarea.classList.add('daedalus-edit-hidden');
                if (
                  !plugin.originalMarkup.has(EditableElement.info.elementId)
                ) {
                  // The edit started on the no-editor tier, so the filtered
                  // markup was never saved — the canvas is untouched until now.
                  plugin.originalMarkup.set(
                    EditableElement.info.elementId,
                    pageElement.innerHTML,
                  );
                }
                pageElement.innerHTML = textarea.value;
                pageElement.classList.add('daedalus-edit-inline-editing');
                Drupal.behaviors.DaedalusEditInlineEditors.attachEditor(
                  pageElement,
                  editorConfig,
                );
              } else {
                // The new format has no editor: continue the edit in the panel
                // textarea. The canvas keeps showing the raw value until blur
                // commits and re-renders it.
                pageElement.classList.remove('daedalus-edit-inline-editing');
                textarea.classList.remove('daedalus-edit-hidden');
                textarea.focus();
              }
            };

            if (ckeditor5Id) {
              const editor =
                Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id);
              if (editor) {
                // Preserve the user's input across the format switch.
                textarea.value = editor.getData();
              }
              Drupal.behaviors.DaedalusEditInlineEditors.detachEditor(
                pageElement,
                ckeditor5Id,
              ).then(reattach);
            } else {
              reattach();
            }
          });
        },
      );
    },

    /**
     * Attach Editor.
     *
     * Attaches the CKEditor Inline Editor to the canvas page element.
     *
     * @param element
     *   The page element being edited (already seeded with the raw value).
     * @param format
     *   The format definition from drupalSettings.editor.formats.
     *
     * @return {Promise}
     *   Resolves when the editor is created and focused.
     */
    attachEditor: (element, format) => {
      // Process the field formatter config.
      const { editorInline } = CKEditor5;
      const { config: pluginConfig, language } = format.editorSettings;
      let { toolbar, plugins } = format.editorSettings;

      // The source editing plugin is not supported on a canvas editor:
      // flipping to the source textarea would swap the page element's
      // markup out mid-edit. Strip the plugin (its GHS companion and the
      // format's source-allowed tags stay intact) and its toolbar button,
      // then drop any separator left doubled ("| |"), leading, or trailing
      // by the removal.
      plugins = plugins.filter(
        (item) => item !== 'sourceEditing.SourceEditing',
      );
      if (Array.isArray(toolbar?.items)) {
        const items = toolbar.items.filter((item) => item !== 'sourceEditing');
        toolbar = {
          ...toolbar,
          items: items.filter(
            (item, index) =>
              item !== '|' ||
              (index !== 0 &&
                index !== items.length - 1 &&
                items[index - 1] !== '|'),
          ),
        };
      }

      // Every canvas editor carries the eager-identity plugin: the live
      // mirror of the server's IdentityMinter (sid attribute, converters,
      // postfixer). Both the form-swap inline_editor and the blueprint
      // formatted_text editor attach through here, so this one injection
      // covers them all.
      const extraPlugins = [...core.selectPlugins(plugins), Identity];
      const config = {
        extraPlugins,
        toolbar,
        language,
        ...core.processConfig(pluginConfig),
        ui: {
          viewportOffset: {
            top: drupalSettings?.DaedalusEdit?.InlineEditor?.viewportOffset ?? 65,
          },
        },
      };

      // Add an Inline Editor.
      const id = core.setElementId(element);
      if (!element.id) {
        // Some CKEditor plugins assume the editable element has an id (the
        // contrib word-count family derives its counter container's id from
        // it and TypeErrors on an empty one). A canvas element has no
        // natural id, so the instance id doubles as one.
        element.id = `daedalus-edit-inline-editor-${id}`;
      }
      const { InlineEditor } = editorInline;

      return InlineEditor.create(element, config)
        .then((editor) => {
          if (!element.classList.contains('daedalus-edit-inline-editing')) {
            // The edit ended before the async create resolved (a fast
            // click-away). blurElement already restored the element; tear
            // the orphan editor down.
            return editor.destroy().then(() => {
              element.removeAttribute('data-ckeditor5-id');
            });
          }
          Drupal.Daedalus.Edit.Ckeditor5Instances.set(id, editor);

          // The edit gesture is what created this editor, so it always wants
          // focus. Normally you'd call editor.focus() here, but it's not
          // playing nice with Safari. Let's manually fire the event.
          editor.ui.focusTracker.isFocused = true;
          editor.editing.view.document.fire('focus');
        })
        .catch((err) => {
          console.error(err.stack);
        });
    },

    /**
     * Defocus a CKEditor before destroying it.
     *
     * Works around a CKEditor 5 bug where the inline toolbar stays visible
     * after its editable goes away, rendering as orphaned three-dots in
     * .ck-body-wrapper. This affects Safari and Firefox.
     *
     * Root cause: CKEditor's link plugin _removeFormView() unconditionally
     * calls saveButtonView.focus() during balloon cleanup, which re-triggers
     * the FocusTracker and sets isFocused back to true. The toolbar panel
     * is bound to isFocused, so it stays visible.
     *
     * We temporarily disable _focus() on the FocusTracker so balloon cleanup
     * cannot re-assert focus, then restore it after the event cycle.
     *
     * @see https://github.com/ckeditor/ckeditor5/issues/18978
     * @todo Remove when the upstream fix lands in Drupal core's CKEditor 5.
     *
     * @param editor
     *   The CKEditor instance about to be destroyed.
     */
    defocusEditor: (editor) => {
      const origFocus = editor.ui.focusTracker._focus;
      editor.ui.focusTracker._focus = () => {};
      editor.ui.focusTracker.isFocused = false;
      editor.ui.focusTracker.focusedElement = null;
      setTimeout(() => {
        editor.ui.focusTracker._focus = origFocus;
      }, 0);
    },

    /**
     * Detach Editor.
     *
     * @param element
     *   The page element whose editor needs removing.
     * @param ckeditor5Id
     *   The CKEditor ID.
     *
     * @return {Promise}
     *   A CKEditor destroy promise.
     */
    detachEditor: (element, ckeditor5Id) => {
      const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.get(ckeditor5Id);
      if (!editor) {
        return Promise.resolve();
      }
      return editor.destroy().then(() => {
        Drupal.Daedalus.Edit.Ckeditor5Instances.delete(ckeditor5Id);
        element.removeAttribute('data-ckeditor5-id');
      });
    },
  };
});
