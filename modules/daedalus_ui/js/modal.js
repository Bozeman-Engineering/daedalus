/**
 * @file
 * The daedalus popup's one opener (Drupal.Daedalus.openModal).
 *
 * The daedalus popup for a server-rendered surface is core's jQuery UI
 * dialog (the openDialog command on #drupal-modal) wearing daedalus_ui's
 * skin (css/dialog-skin.css rides core/drupal.dialog). Its shape lives
 * here once: any module's door is one call, or a link wearing an
 * attribute, never its own Drupal.ajax:
 *
 * @example
 * Drupal.Daedalus.openModal(url, { width: 560 });
 * <a href="/the/route" data-daedalus-modal
 *    data-daedalus-modal-width="560"
 *    data-daedalus-modal-class="my-dialog">Open</a>
 *
 * The request rides as GET (a dialog route answers a reading; nothing is
 * written by opening it) with _wrapper_format=drupal_modal, so the route
 * answers a render array and core wraps it in the dialog. Links are
 * caught at the document, so a surface rendered later (a card the
 * inspector draws, a dialog's own markup) needs no behavior; a modified
 * or secondary click (a new tab) is left to the browser, since the href
 * is a page of its own.
 *
 * Standalone like dialog and menu: no edit-mode dependency, so the chat
 * on a non-editable page and the satellite window host it, each window
 * with its own copy (the listener watches its own document). The
 * instance it executes is returned, so a caller can hang its own error
 * handler on it.
 *
 * The popup also keeps itself on screen as its content grows. Core's
 * dialog.position.js caps the content pane at 95% of the window and
 * centers the dialog, but only when the dialog is created or the window
 * resizes: a dialog centered at a short height whose content then grows
 * (a details element opened, a form rebuilt with its errors) keeps its
 * top and runs off the bottom. A ResizeObserver on every dialog's
 * content pane re-applies the dialog's own maxHeight on the jQuery UI
 * instance, synchronously, which re-runs the widget's _size() and
 * _position() and so re-centers the dialog; it is bound at
 * dialog:aftercreate and dropped at dialog:beforeclose like core's, so
 * every jQuery UI dialog on the page gets it, not only the ones this
 * opener executes. It never fires core's own window-resize handler:
 * core delays that one 20 ms on a timer nothing can cancel, and a dialog
 * destroyed inside those 20 ms left the timer calling 'option' on a dead
 * widget ("cannot call methods on dialog prior to initialization"), a
 * console error the FJS tier fails on.
 *
 * Not for core's media library, whose dialog options are the library's
 * own (Drupal.Daedalus.openMediaLibraryModal), nor for a text-only
 * confirm or prompt (Drupal.Daedalus.dialog, the native <dialog>).
 */

(($, Drupal) => {
  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * The link attribute the delegated listener opens on.
   */
  const SELECTOR = 'a[data-daedalus-modal]';

  /**
   * Opens a server-rendered surface in the daedalus popup.
   *
   * @param {string} url
   *   The dialog route's URL, as the server generated it.
   * @param {object} [options]
   *   The popup's shape.
   * @param {number|string} [options.width=560]
   *   The dialog width (a number of pixels, or 'auto').
   * @param {?string} [options.dialogClass=null]
   *   A class on the .ui-dialog wrapper, for a surface's own styling or
   *   a test's hook.
   * @param {?string} [options.title=null]
   *   A title where the route's own does not serve.
   *
   * @return {Drupal.Ajax}
   *   The instance executed, for a caller's error handler.
   */
  const openModal = (
    url,
    { width = 560, dialogClass = null, title = null } = {},
  ) => {
    const dialog = { width };
    if (dialogClass) {
      dialog.classes = { 'ui-dialog': dialogClass };
    }
    if (title) {
      dialog.title = title;
    }
    const ajax = Drupal.ajax({
      url,
      // Drupal.ajax posts unless told otherwise.
      httpMethod: 'GET',
      dialogType: 'modal',
      dialog,
      progress: { type: 'fullscreen' },
    });
    ajax.execute();
    return ajax;
  };

  /**
   * A link's width attribute: pixels as a number, anything else as text.
   *
   * @param {?string} value
   *   The data-daedalus-modal-width attribute, or null.
   *
   * @return {number|string|undefined}
   *   The width option, or undefined for the default.
   */
  const widthOf = (value) => {
    if (value === null || value === '') {
      return undefined;
    }
    return /^\d+$/.test(value) ? Number(value) : value;
  };

  document.addEventListener('click', (event) => {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey ||
      !(event.target instanceof Element)
    ) {
      return;
    }
    const link = event.target.closest(SELECTOR);
    if (!link) {
      return;
    }
    event.preventDefault();
    openModal(link.href, {
      width: widthOf(link.getAttribute('data-daedalus-modal-width')),
      dialogClass: link.getAttribute('data-daedalus-modal-class'),
    });
  });

  /**
   * The observer watching each open dialog's content pane, by pane.
   *
   * @type {WeakMap<Element, ResizeObserver>}
   */
  const observers = new WeakMap();

  window.addEventListener('dialog:aftercreate', (event) => {
    const { settings } = event;
    if (!(settings.autoResize === true || settings.autoResize === 'true')) {
      return;
    }
    const pane = event.target;
    const observer = new ResizeObserver(() => {
      const $pane = $(pane);
      // The pane may outlive its widget by a frame (a close in flight);
      // 'instance' is undefined once the widget is destroyed.
      if (!$pane.dialog('instance')) {
        return;
      }
      // maxHeight is a size-related option, so re-setting it runs the
      // widget's _size() then _position(): the dialog re-centers at its
      // new height, here and now, with no timer left to fire after a
      // destroy. The value is the instance's current cap, the one core's
      // dialog.position.js computed (95% of the window less the displace
      // offsets) at creation and recomputes on a window resize, never a
      // recomputation here: a cap differing from core's by a pixel would
      // change the pane's own size inside this callback, which Chrome
      // reports as "ResizeObserver loop completed with undelivered
      // notifications", a console error. The same cap changes no size,
      // so the observer reports nothing further and the cycle ends here.
      $pane.dialog('option', 'maxHeight', $pane.dialog('option', 'maxHeight'));
    });
    observer.observe(pane);
    observers.set(pane, observer);
  });

  window.addEventListener('dialog:beforeclose', (event) => {
    const observer = observers.get(event.target);
    if (observer) {
      observer.disconnect();
      observers.delete(event.target);
    }
  });

  Drupal.Daedalus.openModal = openModal;
  Drupal.Daedalus.openModal.SELECTOR = SELECTOR;
})(jQuery, Drupal);
