/**
 * @file
 * The shared modal dialog primitive (Drupal.Daedalus.dialog).
 *
 * The chrome's replacement for window.confirm / window.prompt /
 * window.alert: those are blocking, unstylable, browser-suppressible
 * ("prevent this page from creating additional dialogs"), and detached
 * from the chrome's palette. Any module's chrome library declares
 * daedalus_ui/dialog as a dependency and awaits these instead:
 * @code
 *   const ok = await Drupal.Daedalus.dialog.confirm({
 *     title: Drupal.t('Delete "CTA"?'),
 *     message: Drupal.t('Worn on 4 pieces of content across the site.'),
 *     confirmLabel: Drupal.t('Delete'),
 *     danger: true,
 *   });
 *   const label = await Drupal.Daedalus.dialog.prompt({
 *     message: Drupal.t('Name for your copy:'),
 *     defaultValue: current,
 *   });
 * @endcode
 *
 * Built on the native <dialog> element and showModal(): the top layer
 * renders above every chrome z-index without joining the modal-floor
 * scheme (css/modal.css), focus is trapped inside the dialog and restored
 * to the opener by the platform, and Esc cancels natively. Promise-based,
 * so call sites are async where the window.* calls were synchronous:
 * confirm resolves true/false, prompt resolves the entered string or null
 * on cancel (mirroring window.prompt), alert resolves when dismissed.
 *
 * The confirm button precedes the cancel button in the DOM, and the
 * button row reverses visually (dialog.css): implicit form submission
 * (Enter in the prompt's input) triggers the FIRST submit button in tree
 * order, and Enter must confirm, not cancel. Initial focus is explicit
 * via autofocus: the input for prompts, the confirm button otherwise,
 * except danger confirms, which focus Cancel so Enter alone cannot
 * destroy anything.
 *
 * Content renders via textContent only: messages routinely interpolate
 * user-authored labels, and this primitive must never become an HTML
 * injection seam.
 */
((Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Builds one element with a class and optional text.
   */
  const build = (tag, className, text) => {
    const el = document.createElement(tag);
    el.className = className;
    if (text !== undefined) {
      el.textContent = text;
    }
    return el;
  };

  /**
   * Opens one modal dialog and resolves with its outcome.
   *
   * @param {object} options
   *   The dialog description.
   * @param {string} options.message
   *   The question or statement, required.
   * @param {string} [options.title]
   *   An optional heading above the message.
   * @param {string} [options.detail]
   *   An optional muted paragraph under the message (consequences,
   *   permission notes).
   * @param {string} [options.confirmLabel]
   *   The confirm button label; defaults to "OK".
   * @param {string} [options.cancelLabel]
   *   The cancel button label; defaults to "Cancel". Alerts render no
   *   cancel button at all.
   * @param {boolean} [options.danger]
   *   Styles the confirm button in the danger accent and moves initial
   *   focus to Cancel.
   * @param {object} [input]
   *   Prompt plumbing: { defaultValue } adds a text input.
   * @param {boolean} [cancellable]
   *   Whether a cancel button renders (false for alerts).
   *
   * @return {Promise<{confirmed: boolean, value: string|null}>}
   *   Resolves when the dialog closes, however it closes.
   */
  const open = (
    { message, title, detail, confirmLabel, cancelLabel, danger },
    input = null,
    cancellable = true,
  ) =>
    new Promise((resolve) => {
      const dialog = build(
        'dialog',
        `daedalus-ui-dialog${danger ? ' daedalus-ui-dialog--danger' : ''}`,
      );
      const form = build('form', 'daedalus-ui-dialog__form');
      form.method = 'dialog';
      dialog.appendChild(form);

      if (title) {
        form.appendChild(build('h2', 'daedalus-ui-dialog__title', title));
      }
      form.appendChild(build('p', 'daedalus-ui-dialog__message', message));
      if (detail) {
        form.appendChild(build('p', 'daedalus-ui-dialog__detail', detail));
      }

      let field = null;
      if (input) {
        field = build('input', 'daedalus-ui-dialog__input');
        field.type = 'text';
        field.value = input.defaultValue ?? '';
        field.autofocus = true;
        form.appendChild(field);
      }

      // Confirm first in the DOM (implicit submission must confirm), cancel
      // first visually (the row reverses in CSS).
      const buttons = build('div', 'daedalus-ui-dialog__buttons');
      const confirm = build(
        'button',
        'daedalus-ui-dialog__button daedalus-ui-dialog__button--confirm',
        confirmLabel ?? Drupal.t('OK'),
      );
      confirm.value = 'confirm';
      confirm.autofocus = !field && !danger;
      buttons.appendChild(confirm);
      if (cancellable) {
        const cancel = build(
          'button',
          'daedalus-ui-dialog__button',
          cancelLabel ?? Drupal.t('Cancel'),
        );
        cancel.value = 'cancel';
        cancel.autofocus = !field && !!danger;
        buttons.appendChild(cancel);
      }
      form.appendChild(buttons);

      dialog.addEventListener('close', () => {
        const confirmed = dialog.returnValue === 'confirm';
        resolve({ confirmed, value: confirmed && field ? field.value : null });
        dialog.remove();
      });

      document.body.appendChild(dialog);
      dialog.showModal();
      field?.select();
    });

  Drupal.Daedalus.dialog = {
    /**
     * Asks a yes/no question.
     *
     * @param {object} options
     *   message (required), title, detail, confirmLabel, cancelLabel,
     *   danger; see open().
     *
     * @return {Promise<boolean>}
     *   True when confirmed, false when cancelled or dismissed.
     */
    confirm(options) {
      return open(options).then(({ confirmed }) => confirmed);
    },

    /**
     * Asks for one line of text.
     *
     * @param {object} options
     *   message (required), title, detail, confirmLabel, cancelLabel,
     *   danger, and defaultValue for the input's initial content.
     *
     * @return {Promise<string|null>}
     *   The entered string when confirmed (possibly empty, exactly like
     *   window.prompt), null when cancelled or dismissed.
     */
    prompt({ defaultValue, ...options }) {
      return open(options, { defaultValue }).then(({ confirmed, value }) =>
        confirmed ? value : null,
      );
    },

    /**
     * Announces something that only needs acknowledging.
     *
     * @param {object} options
     *   message (required), title, detail, confirmLabel; see open().
     *
     * @return {Promise<void>}
     *   Resolves when dismissed.
     */
    alert(options) {
      return open(options, null, false).then(() => undefined);
    },
  };
})(Drupal);
