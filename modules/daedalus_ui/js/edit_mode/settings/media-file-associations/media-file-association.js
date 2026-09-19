(($, Drupal, once) => {
  /**
   * Daedalus UI open file association settings.
   *
   * When a user saves a Media Type to Block Type association, open the settings
   * sidebar to show where the setting has been saved.
   */
  jQuery.fn.DaedalusUiOpenFileAssociationSettings = () => {
    setTimeout(() => {
      document
        .querySelector('.file-association-details')
        .setAttribute('open', '');
      const newSetting = document.querySelector(
        '.file-association-details .setting-list li:last-of-type',
      );
      const editMode = Drupal.Daedalus.ModeManager.getPlugin('edit');
      editMode.highlight(newSetting);
    }, 100);
  };

  /**
   * Remove file association.
   *
   * @type {{attach(*, *): void}}
   */
  Drupal.behaviors.RemoveMediaFileAssociation = {
    attach(context, settings) {
      once('media-file-association', '.remove-association', context).forEach(
        (button) => {
          button.addEventListener('click', (e) => {
            const fileExtension = e.target.dataset.fileExtension;
            const path = `/daedalus-ui/remove-media-file-association/${fileExtension.toLowerCase()}`;
            const url = Drupal.Daedalus.ModePluginBase.url(path);

            const ajaxConfig = {
              url,
              event: 'click',
              progress: {
                type: 'fullscreen',
                message: Drupal.t('Removing media file association...'),
              },
              success: (response, status) => {
                if (status === 'success') {
                  const settingsList = button.closest('.setting-list');
                  button.closest('li').remove();
                  document.querySelector('.ajax-progress').remove();
                  if (settingsList.children.length === 0) {
                    document
                      .querySelector('.setting-description')
                      .classList.remove('daedalus-ui-hidden');
                  }
                  Drupal.Ajax.prototype.success.call(ajax, response, status);
                }
              },
              error: (error) => {
                console.error(
                  'Unable to remove media file association: ',
                  error.responseText || error,
                );
                Drupal.Daedalus.ModeManager.getPlugin('edit').handleError(
                  error,
                  'Unable to remove media file association.',
                );
              },
            };

            let ajax = Drupal.Daedalus.ModePluginBase.ajax(ajaxConfig);
            ajax.execute();
          });
        },
      );
    },
  };
})(jQuery, Drupal, once);
