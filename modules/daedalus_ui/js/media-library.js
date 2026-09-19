((Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Opens core's media library as a Drupal AJAX modal.
   *
   * The one place the modal is shaped. A MediaLibraryOpener on the server
   * builds the media_library.ui URL (state hash included) and hands it to the
   * client in settings; the client never constructs library state, it opens
   * the URL here. The user's selection returns as the opener's own AJAX
   * command, and the modal's close command rides the same response, so a
   * caller has nothing to wait for.
   *
   * The dialog options are core's media library dialog shape
   * (MediaLibraryUiBuilder::dialogOptions()), so the library's own styling
   * and behaviors (selection count, insert button placement) apply
   * unchanged, and the modal wears the media-library-widget-modal class the
   * edit-mode layout floor (css/media-library.css) and the dialog skin key
   * off.
   *
   * @param {string} url
   *   The server-built media_library.ui URL.
   */
  Drupal.Daedalus.openMediaLibraryModal = (url) => {
    Drupal.ajax({
      url,
      dialogType: 'modal',
      dialog: {
        classes: {
          'ui-dialog': 'media-library-widget-modal',
        },
        title: Drupal.t('Add or select media'),
        height: '75%',
        width: '75%',
      },
      progress: { type: 'fullscreen' },
    }).execute();
  };
})(Drupal);
