import { EditorPluginBase } from './editor-plugin-base.js';
import { PanelWidgetElement } from '../panel-widget-element.js';
import { formCacheMount, WIDGET_CONTROL_SELECTOR } from '../panel-items.js';
import { refetchForm } from '../entity-form.js';

/**
 * The panel widget editor: the one editor for "no canvas editor".
 *
 * A field whose widget has no canvas-native editor edits through the
 * real Drupal widget in the entity-form panel, whether the field renders
 * a leaf on the canvas (an autocomplete reference, a media library
 * reference: the widget is revealed for the edit's duration and the leaf
 * dimmed as the selection being edited) or not (a revealed panel widget,
 * the panel-items.js kind: already visible, the edit is focus). Either
 * way the widget IS the editor and this plugin knows nothing about it,
 * ever: no widget-specific commit detection, no value read from its DOM.
 * Values leave a widget only through the form's own submit (the form
 * transport, transports.js), which is total over every widget that
 * exists or will exist.
 *
 * The commit triggers are the generic three and the explicit floor:
 * - the native `change` event of any control inside a revealed wrapper
 *   (an input, a select, a textarea, an autocomplete's chosen value);
 * - any Drupal AJAX response that touched a revealed wrapper (a behavior
 *   attach whose context lands inside it: a media library selection's
 *   widget update, a remove button, "Add another item"), a generic fact
 *   of the AJAX framework, not a per-widget signal; a round that changed
 *   nothing extracts an equal value and the server skips the write;
 * - the explicit floor: the open edit ending (Tab out, Cmd+Enter, the
 *   Update button, a press elsewhere, leaving the session) commits when
 *   the widget reported input since its last commit (native `input`, or
 *   core's `formUpdated` for editors that fire no DOM input, a CKEditor in
 *   the panel). The worst case for an unseen widget is "press Update or
 *   tab away".
 *
 * Escape on a widget that reported input refetches the subject form: the
 * widget's uncommitted state is not the client's to restore (a typed
 * autocomplete, a CKEditor in the panel), so a discard means a fresh form.
 * A widget with nothing to discard (its AJAX rounds already committed) is
 * left as it is. A foreign response's resume re-focuses the (possibly
 * refetched) widget; the panel holds no canvas draft.
 *
 * @see ../panel-widget-element.js
 * @see ../panel-widget-visibility.js
 */

const WRAPPER_SELECTOR = '[data-daedalus-edit-form-item-wrapper-id]';
const HIDDEN_CLASS = 'daedalus-edit-hidden';
const FORM_SELECTOR = '.daedalus-edit-form';

/**
 * The revealed wrappers with input since their last commit.
 *
 * @type {WeakSet<Element>}
 */
const dirty = new WeakSet();


/**
 * The revealed wrapper an element sits in, or null: a wrapper in the
 * form-cache mount that the panel shows (not hidden behind a canvas leaf).
 *
 * @param {?Element} element
 *   The element.
 *
 * @return {?Element}
 */
const revealedWrapperOf = (element) => {
  const mount = formCacheMount();
  if (!(element instanceof Element) || !mount?.contains(element)) {
    return null;
  }
  const wrapper = element.closest(WRAPPER_SELECTOR);
  return wrapper && !wrapper.classList.contains(HIDDEN_CLASS) ? wrapper : null;
};

export class PanelWidgetEditor extends EditorPluginBase {
  id = 'panel_widget';

  /**
   * The widget's value is the form's to extract: nothing is read here.
   */
  readProperties(item) {
    return {};
  }

  writeProperties(item, properties) {}

  /**
   * Mount: reveal the widget when a canvas leaf hides it.
   *
   * A revealed panel widget is already visible (nothing happens: the
   * base's reveal dims only what the edit itself unhid, and a panel
   * widget has no page element to dim); a leaf's widget is unhidden
   * in-flow for the edit's duration, the leaf dimmed. The base keeps the
   * reveal sticky across a resume (`item.revealed`): the reveal that
   * opened the edit is what the teardown undoes, whatever a re-mount over
   * swapped-in markup finds.
   */
  mount(item) {
    this.revealFormItem(item, true);
  }

  focusElement(item) {
    const wrapper = item.getFormItemWrapper();
    if (wrapper && !wrapper.contains(document.activeElement)) {
      wrapper.querySelector(WIDGET_CONTROL_SELECTOR)?.focus();
    }
  }

  /**
   * Defocus: re-hide a leaf's widget; a panel widget stays as it is.
   */
  defocus(item) {
    if (item.revealed) {
      this.hideFormItem(item);
      return;
    }
    this.releasePanelWidth(item);
  }

  /**
   * The explicit floor: commit if the widget reported input since its
   * last commit. A `change` that already fired committed and cleared it.
   */
  blurElement(item) {
    const wrapper = item.getFormItemWrapper();
    if (wrapper && dirty.has(wrapper)) {
      this.commitWrapper(wrapper, item);
    }
    return Promise.resolve();
  }

  /**
   * Cancel: a fresh form when the widget reported input, because its
   * uncommitted state is not the client's to restore; nothing otherwise.
   */
  cancelElement(item) {
    const wrapper = item.getFormItemWrapper();
    if (!wrapper || !dirty.has(wrapper)) {
      return Promise.resolve();
    }
    dirty.delete(wrapper);
    const form = item.getForm();
    return form ? refetchForm(form) : Promise.resolve();
  }

  /**
   * Resume: the widget (refetched or not) is the surface again.
   */
  resume(item, draft) {
    this.mount(item);
    this.focus(item);
    return Promise.resolve();
  }

  inputs(item) {
    return item.getFormItemInputs();
  }

  /**
   * Commits a revealed wrapper's field through the form transport.
   *
   * The current edit's own item is reused when it is this wrapper (so the
   * rollback and the failure display address it); any other revealed
   * wrapper (a change under the Selector, an AJAX round on a widget no
   * edit holds) gets a fresh item.
   *
   * @param {Element} wrapper
   *   The revealed form-item wrapper.
   * @param {?PanelWidgetElement} item
   *   The current edit's item, when it is this wrapper's.
   *
   * @return {Promise<Object>}
   *   The commit's op-queue promise.
   */
  commitWrapper(wrapper, item = null) {
    dirty.delete(wrapper);
    return this.commit(item ?? new PanelWidgetElement(wrapper), {});
  }

  /**
   * Whether a wrapper's commit triggers are this editor's to act on.
   *
   * A wrapper the current edit reveals for its ANCILLARY chrome (the
   * canvas CKEditor's text format selector rides the body's wrapper) is
   * that editor's: its own blur commits the whole field, and a commit from
   * here mid-edit would submit the textarea before the canvas editor
   * synced it.
   *
   * @param {Element} wrapper
   *   A revealed wrapper.
   *
   * @return {?Object}
   *   The current edit's item when it is this wrapper's under this editor,
   *   null when no edit holds the wrapper, false when another editor does.
   */
  ownerOf(wrapper) {
    const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    if (!editing || editing.getFormItemWrapper?.() !== wrapper) {
      return null;
    }
    return editing.plugin === this ? editing : false;
  }

  /**
   * A control inside a revealed wrapper changed: commit.
   */
  onChange(event) {
    const wrapper = revealedWrapperOf(event.target);
    if (!wrapper) {
      return;
    }
    const owner = this.ownerOf(wrapper);
    if (owner === false) {
      return;
    }
    this.commitWrapper(wrapper, owner);
  }

  /**
   * A control inside a revealed wrapper reported input: the explicit
   * floor commits it when the edit ends.
   */
  onInput(event) {
    const wrapper = revealedWrapperOf(event.target);
    if (wrapper) {
      dirty.add(wrapper);
    }
  }

  /**
   * A Drupal AJAX response touched a revealed wrapper: commit.
   *
   * The context is the inserted or replaced subtree; a whole form arriving
   * (the cache append, a rebuilt form after validation) is the form
   * wrapper itself, above every field wrapper, and commits nothing.
   */
  onAjaxTouched(context) {
    if (
      !(context instanceof Element) ||
      context.matches(FORM_SELECTOR) ||
      context.querySelector(FORM_SELECTOR)
    ) {
      return;
    }
    const wrapper = revealedWrapperOf(context);
    if (!wrapper) {
      return;
    }
    const owner = this.ownerOf(wrapper);
    if (owner === false) {
      return;
    }
    this.commitWrapper(wrapper, owner);
  }

  init() {
    document.addEventListener('change', (event) => this.onChange(event));
    document.addEventListener('input', (event) => this.onInput(event));
    // Core's generic form-change signal, fired by editors that raise no DOM
    // input (a CKEditor in the panel). A jQuery event: it is triggered
    // through jQuery and never reaches native listeners.
    jQuery(document).on('formUpdated', (event) => this.onInput(event));
    const editor = this;
    Drupal.behaviors.daedalusEditPanelWidgetAjax = {
      attach(context) {
        editor.onAjaxTouched(context);
      },
    };
  }
}

/**
 * Register the panel widget editor: the registry's fallback for every
 * widget no other editor claims.
 */
(window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
  daedalusEdit.editors.register(new PanelWidgetEditor());
});
