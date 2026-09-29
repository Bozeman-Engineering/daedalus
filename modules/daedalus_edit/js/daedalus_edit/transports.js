/**
 * @file
 * The two commit transports, and the form transport's round trip.
 *
 * Every edit is one field of one subject committed through the op spine,
 * answered by the one mutation response, and the transport is chosen by
 * the item's KIND, never by the editor:
 * - The VALUE transport (a blueprint-edited leaf, [data-daedalus-edit-editor]):
 *   the editor's changed property bag rides one `update_element` op on
 *   Drupal.Daedalus.Blueprint.mutate, addressed to the leaf's component.
 * - The FORM transport (a form-edited leaf, [data-daedalus-edit-id], and a
 *   panel widget): the subject's cached entity form is submitted by its
 *   own AJAX with one extra input, the field to commit
 *   (FormCommit::FIELD_INPUT), so the form's full Drupal semantics run
 *   (form cache, uploads, multi-step widget state, site validate and
 *   submit handlers); the server hands exactly that field's items to the
 *   spine (daedalus_edit FormCommit) and answers the standard mutation
 *   payload as one daedalusBlueprintApplyResponse command, applied
 *   through the app's one pipeline. Nothing raw crosses the wire, and no
 *   widget's value is ever read from its DOM: values leave a widget only
 *   through the form's own submit.
 *
 * Both ride the op queue, so a form commit and the spine ops around it
 * stay FIFO: the value transport as an op, the form transport as a job
 * (Blueprint.enqueueJob) whose run is the AJAX round below. The Configure
 * dialog (configure-dialog.js) runs the same round over core's Layout
 * Builder forms.
 */

/**
 * The POST parameter naming the field a form submit commits.
 *
 * The client half of \Drupal\daedalus_edit\FormCommit::FIELD_INPUT: a
 * plain request parameter beside the form's own fields, never a form
 * element.
 */
export const FIELD_INPUT = 'daedalus_edit_commit_field';

/**
 * The jQuery event the commit's own Drupal.Ajax instance is bound to.
 *
 * A custom event so the instance never answers a pointer: the Update
 * button's native press is the edit session's commit-and-leave door
 * (edit-session-engine.js), and the button's server-bound instance (a
 * mousedown) is left alone.
 */
const COMMIT_EVENT = 'daedalusEditCommit';

/**
 * The form commit in flight, or null: its subject form's identity.
 *
 * A form commit's own response names the field it wrote, and that field's
 * widget is exactly the one the user is done with: the panel must not
 * refetch the subject form for it (the engine reads this before its
 * refresh-from-targets decision). The op queue runs one job at a time, so
 * a single slot is enough.
 *
 * @type {?string}
 */
let inFlight = null;

/**
 * The form-cache identity ("{type}::{id}") of the form commit in flight.
 *
 * @return {?string}
 */
export const formCommitInFlight = () => inFlight;

/**
 * The Drupal.Ajax instance the server bound to a form's submit button.
 *
 * @param {?Element} button
 *   The button.
 *
 * @return {?Object}
 *   The instance, or null when the button carries none.
 */
export const boundAjaxOf = (button) =>
  (button &&
    Drupal.ajax.instances.find(
      (instance) => instance && instance.element === button,
    )) ||
  null;

/**
 * Submits a form's button by AJAX and reads the mutation payload back.
 *
 * The round is the form's own: a Drupal.Ajax instance over the same
 * button and form the server bound (its URL, with the mode and the AJAX
 * form flags on the query; a submit bag carrying the triggering element),
 * so serialization detaches behaviors for the CKEditors to sync their
 * textareas, the form's every input rides the POST, and the response's
 * commands play through Drupal.Ajax.prototype.success. The instance is
 * this round's alone, bound to a custom event and released afterwards.
 *
 * @param {Element} button
 *   The submit button, inside its form.
 * @param {{url: string, submit: Object}} round
 *   The URL and the submit bag (the server-bound instance's, plus what the
 *   caller adds).
 *
 * @return {Promise<Object>}
 *   Resolves with the mutation-response payload the answer carried
 *   (success or failure alike, judged by the caller); rejects with an
 *   Error whose `validation` flag is set when the server answered the
 *   rebuilt form instead (form validation refused the submit), or with
 *   the transport error.
 */
export const submitBound = (button, { url, submit }) =>
  new Promise((resolve, reject) => {
    let ajax = null;
    const release = () => {
      jQuery(button).off(COMMIT_EVENT);
      if (ajax) {
        Drupal.ajax.instances[ajax.instanceIndex] = null;
      }
    };
    ajax = Drupal.ajax({
      url,
      element: button,
      event: COMMIT_EVENT,
      keypress: false,
      progress: { type: 'none' },
      submit,
      success: (response, status) =>
        Promise.resolve(
          Drupal.Ajax.prototype.success.call(ajax, response, status),
        ).then(
          () => {
            release();
            const payload = ajax.daedalusBlueprintPayload;
            if (payload) {
              resolve(payload);
              return;
            }
            // No payload came back: the server answered the rebuilt form
            // with its validation errors (FormCommit::rejected) and wrote
            // nothing.
            const error = new Error(
              Drupal.t('The form did not validate; nothing was committed.'),
            );
            error.validation = true;
            reject(error);
          },
          (error) => {
            release();
            reject(error);
          },
        ),
      error: (xmlhttprequest, uri, customMessage) => {
        release();
        reject(
          new Error(
            customMessage ??
              `Daedalus Edit: the form commit request to ${uri} failed.`,
          ),
        );
      },
    });
    jQuery(button).trigger(COMMIT_EVENT);
  });

/**
 * Submits a cached form's Update button by AJAX, naming the field to commit.
 *
 * @param {Element} form
 *   The cached form wrapper (.daedalus-edit-form) holding the form.
 * @param {string} fieldName
 *   The field (or the placement label pseudo-field, 'label') to commit.
 *
 * @return {Promise<Object>}
 *   The round's promise (see submitBound).
 */
export const submitFormCommit = (form, fieldName) => {
  const button = form.querySelector('.daedalus-edit-update-button');
  const bound = boundAjaxOf(button);
  if (!bound) {
    return Promise.reject(
      new Error(
        'Daedalus Edit: the cached form has no AJAX-bound Update button to commit through.',
      ),
    );
  }
  return submitBound(button, {
    url: bound.url,
    submit: {
      ...bound.submit,
      [FIELD_INPUT]: fieldName,
    },
  });
};

/**
 * The value transport: one `update_element` op with the changed bag.
 */
export const valueTransport = {
  /**
   * @param {BlueprintEditableElement} item
   *   The blueprint-edited leaf.
   * @param {Object<string, *>} properties
   *   The changed properties.
   * @param {{label: string, rollback: Function}} options
   *   The history label and the optimistic rollback.
   *
   * @return {Promise<Object>}
   *   The op-queue promise.
   */
  commit: (item, properties, { label, rollback }) => {
    const context = Drupal.Daedalus.Blueprint.rootContext();
    if (!context) {
      throw new Error('No root entity mutation context on this page.');
    }
    return Drupal.Daedalus.Blueprint.mutate({
      ...context,
      operations: [
        {
          op: 'update_element',
          element: item.componentUuid,
          properties,
        },
      ],
      label,
      optimistic: {
        // The DOM already shows the edit; there is nothing to apply.
        apply: () => {},
        rollback,
      },
    });
  },
};

/**
 * The form transport: the subject form's own submit, naming one field.
 */
export const formTransport = {
  /**
   * @param {EditableElement|PanelWidgetElement} item
   *   The form-edited leaf or the panel widget.
   * @param {Object<string, *>} properties
   *   Unused: the form carries the values, the server extracts them.
   * @param {{rollback: Function}} options
   *   The optimistic rollback.
   *
   * @return {Promise<Object>}
   *   The op-queue promise.
   */
  commit: (item, properties, { rollback }) => {
    const form = item.getForm();
    if (!form) {
      throw new Error(
        `Daedalus Edit: no cached form to commit "${item.info.fieldName}" through.`,
      );
    }
    const formId = form.dataset.daedalusEditFormId;
    return Drupal.Daedalus.Blueprint.enqueueJob({
      run: () => {
        inFlight = formId;
        return submitFormCommit(form, item.info.fieldName).finally(() => {
          inFlight = null;
        });
      },
      optimistic: {
        apply: () => {},
        rollback,
      },
    });
  },
};
