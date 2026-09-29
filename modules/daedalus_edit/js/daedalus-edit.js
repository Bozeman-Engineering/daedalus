import './daedalus_edit/media-library-selection.js'; // Registers an AJAX command.
import './daedalus_edit/panel-widget-visibility.js'; // Registers the field-state behavior.
import './daedalus_edit/configure-dialog.js'; // Registers the Configure dialog behavior.
import * as entityForm from './daedalus_edit/entity-form.js';
import initEditorRegistry from './daedalus_edit/editor-registry.js';
import { setCurrentlyEditing } from './daedalus_edit/currently-editing.js';
// The stock editors. Importing them pushes their registration callbacks
// onto window.DaedalusEditQueue, which initEditorRegistry() drains.
import './daedalus_edit/plugins/panel-widget-editor.js';
import './daedalus_edit/plugins/textfield.js';
import './daedalus_edit/plugins/inline-editor.js';

(($, Drupal) => {
  Drupal.Daedalus.Edit = {};
  Drupal.Daedalus.Edit.EditMode = 'enabled';

  /**
   * Currently Editing Element.
   *
   * Remember the focused form item. Instead of calling replaceFormItemWithPageElement
   * onblur, lets track the focused form item so that clicking things in the
   * sidebar doesn't lose focus on the currently edited form item.
   *
   * The setter is the ONE writer (currently-editing.js): it maintains the
   * global AND reports the change through the registration seam so the
   * app's editingKey stays reactive. Exposed on Drupal.Daedalus.Edit for
   * editors delivered outside this module's ES-module graph (the
   * formatted-text submodule's plain script). Reads stay direct.
   */
  Drupal.Daedalus.Edit.SetCurrentlyEditingElement = setCurrentlyEditing;
  Drupal.Daedalus.Edit.SetCurrentlyEditingElement(null);

  /**
   * Fetches the top-level entity's form into the panel's form cache.
   *
   * Exposed for the entity-form sidebar plugin (daedalus-edit-sidebar.js, a
   * plain script outside this ES-module graph): the panel's dumb door (the
   * toggle icon, the boot restore) and the subject revert load the
   * top-level form without an EditableElement in hand.
   */
  Drupal.Daedalus.Edit.FetchMainEntityForm = entityForm.fetchMainEntityForm;

  /**
   * Fetches the form for a cached-form identity ("{type}::{id}").
   *
   * The sidebar plugin's refetch (a stale or discarded cached form is
   * replaced with a fresh one) fetches through here.
   */
  Drupal.Daedalus.Edit.FetchForm = entityForm.fetchFormFor;

  /**
   * Has invalid form item.
   *
   * A field is currently being edited and needs correction before we can edit
   * another field.
   *
   * @return {boolean}
   *   Whether there is an invalid form item that needs to be resolved.
   */
  Drupal.Daedalus.Edit.hasInvalidFormItem = () => {
    const isValid = Drupal.Daedalus.Edit.CurrentlyEditingElement?.plugin.isValid();
    return typeof isValid === 'boolean' ? !isValid : false;
  };

  /**
   * Warn about invalid form item.
   *
   * Shows a warning message and scrolls to the currently editing element
   * when it has invalid form data. This prevents users from editing another
   * field, closing the sidebar, or exiting edit mode until they either fix
   * the validation error or press Escape to discard changes.
   *
   * If a server-side validation error was previously shown for this element,
   * the warning will include the original error message to remind the user
   * what needs to be fixed.
   *
   * The warning appears in two scenarios:
   * - Attempting to edit a different field
   * - Attempting to close the daedalus edit sidebar
   *
   * @see Drupal.Daedalus.Edit.hasInvalidFormItem()
   */
  Drupal.Daedalus.Edit.warnAboutInvalidFormItem = () => {
    const element = Drupal.Daedalus.Edit.CurrentlyEditingElement;
    const elementId = element.info.elementId;

    // Find the most recent error or warning for this element in the
    // messenger's history.
    const relevantNotifications = Drupal.Daedalus.messenger
      .history()
      .filter(
        (n) =>
          n.target === elementId &&
          (n.type === 'error' || n.type === 'warning'),
      )
      .reverse();

    let message = Drupal.t(
      'Fix the invalid form item or press Esc to discard changes.',
    );
    if (relevantNotifications.length > 0) {
      const originalError = relevantNotifications[0].message;
      // Combine original error with instruction.
      message = `${message}
      ${originalError}`;
    }

    const editMode = Drupal.Daedalus.ModeManager.getPlugin('edit');
    editMode.message(Drupal.t(message), 'warning', {
      duration: 3000,
      scroll: false,
    });
    // A panel widget has no page element handle: its widget is the one
    // in the panel holding the invalid input, already in view.
    const target = element.getPageElementHandle();
    if (target) {
      editMode.scrollToElement(target);
    }
  };

  // Boot the editor registry and register the editors.
  initEditorRegistry();

  /**
   * Disables interactive elements while in edit mode.
   */
  Drupal.Daedalus.Edit.DoNothing = (e) => {
    e.preventDefault();
    e.stopPropagation();
  };

  /**
   * Re-check the panel width after AJAX rebuilds inside the active edit.
   *
   * Widgets change size mid-edit ("Add another item", media selection):
   * #ajax replaces the wrapper subtree and Drupal re-attaches behaviors with
   * the new markup as context, which is the natural re-measure hook. The
   * measurement itself is grow-only per edit and released on blur.
   *
   * @see EditorPluginBase.widenPanelIfNeeded()
   */
  Drupal.behaviors.DaedalusEditPanelAutoWiden = {
    attach(context) {
      const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
      // Page-load attaches (context = document) can't have an active edit;
      // only element contexts from AJAX rebuilds matter here. Blueprint
      // edits have no form panel to measure (mutation swaps attach behaviors
      // too), so only form-backed edits, which carry getForm(), proceed.
      if (
        !editing ||
        !(context instanceof Element) ||
        typeof editing.getForm !== 'function'
      ) {
        return;
      }
      const panel = editing.getForm();
      if (panel && (panel === context || panel.contains(context))) {
        editing.plugin.widenPanelIfNeeded(editing);
      }
    },
  };

  /**
   * An HTML5 validation message.
   *
   * @param {string} [message]
   *   (optional) The message shown on the UI.
   * @return {string}
   *   The HTML markup for the validation message.
   */
  Drupal.theme.daedalusEditValidationMessage = (message) => {
    return `<div class="daedalus-edit-validation-message">${message}</div>`;
  };
})(jQuery, Drupal);
