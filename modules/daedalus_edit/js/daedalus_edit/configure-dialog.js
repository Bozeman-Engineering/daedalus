/**
 * @file
 * The Configure dialog on the form transport (the client half).
 *
 * daedalus_layout's Configure verb opens core's Layout Builder forms (the
 * placement's UpdateBlockForm, the section's ConfigureSectionForm) in the
 * edit-mode dialog; the server half (daedalus_edit's
 * ConfigureDialogFormAlterBase) puts their submit on the spine and stamps
 * the form with DIALOG_ATTRIBUTE. Here that form's Update button stops
 * being a Drupal.Ajax trigger of its own and becomes a job on the op queue
 * (Blueprint.enqueueJob), FIFO with the ops around it, whose round is the
 * form's own AJAX submit (transports.js submitBound): the answer, the
 * mutation payload as one daedalusBlueprintApplyResponse command, applies
 * through the app's one pipeline, and the close command the server adds on
 * success closes the dialog.
 *
 * A submit the form refused (validation) answers the rebuilt form with its
 * messages in the dialog and no payload: the job rejects quietly, the
 * dialog stays. A payload the spine refused (success false) rejects like
 * an op's 422 and reports through the one failure display, anchored on
 * the subject's key the form carries.
 */

import { boundAjaxOf, submitBound } from './transports.js';

/**
 * The form attribute the server half stamps (ConfigureDialogFormAlterBase).
 */
const DIALOG_ATTRIBUTE = 'data-daedalus-edit-configure-dialog';

/**
 * The attribute the server half stamps on the dialog's Update button
 * (ConfigureDialogFormAlterBase::SUBMIT_ATTRIBUTE). Core's Layout Builder
 * forms render the button bare (no actions container, so no .form-actions
 * wrapper and no dialog button pane); the stamp names it.
 */
const SUBMIT_ATTRIBUTE = 'data-daedalus-edit-configure-dialog-submit';

/**
 * Takes a dialog form's Update button over from its server-bound trigger.
 *
 * Drupal binds the button's #ajax as a mousedown with a click prevention;
 * those handlers go, the instance is released, and the same mousedown now
 * enqueues the round as a job. The click stays prevented so the form never
 * submits natively.
 *
 * @param {HTMLFormElement} form
 *   The dialog form.
 */
const takeOver = (form) => {
  const button = form.querySelector(`[${SUBMIT_ATTRIBUTE}]`);
  const bound = boundAjaxOf(button);
  if (!bound) {
    return;
  }
  const { url, submit, event } = bound;
  const key = form.getAttribute(DIALOG_ATTRIBUTE) || null;
  jQuery(button).off(`${event} keypress click`);
  Drupal.ajax.instances[bound.instanceIndex] = null;
  jQuery(button).on('click', false);
  jQuery(button).on(event, (pressed) => {
    pressed.preventDefault();
    Drupal.Daedalus.Blueprint.enqueueJob({
      run: () => submitBound(button, { url, submit }),
    }).catch((error) => {
      if (error.validation || error.abandoned) {
        return;
      }
      Drupal.Daedalus.Blueprint.reportFailure(error, key);
    });
  });
};

Drupal.behaviors.daedalusEditConfigureDialog = {
  attach(context) {
    once('daedalusEditConfigureDialog', `form[${DIALOG_ATTRIBUTE}]`, context).forEach(
      takeOver,
    );
  },
};
