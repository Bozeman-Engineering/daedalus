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
    attach(context) {
      once('media-file-association', '.remove-association', context).forEach(
        (button) => {
          button.addEventListener('click', (e) => {
            const fileExtension = e.target.dataset.fileExtension;
            const editMode = Drupal.Daedalus.ModeManager.getPlugin('edit');
            Drupal.Daedalus.post(
              Drupal.url(
                `daedalus-ui/remove-media-file-association/${encodeURIComponent(fileExtension.toLowerCase())}`,
              ),
            )
              .then((response) => {
                if (!response.ok) {
                  throw new Error(
                    Drupal.t(
                      'Unable to remove media file association (HTTP @status).',
                      { '@status': response.status },
                    ),
                  );
                }
                return response.json();
              })
              .then(({ message }) => {
                const settingsList = button.closest('.setting-list');
                button.closest('li').remove();
                if (settingsList.children.length === 0) {
                  document
                    .querySelector('.setting-description')
                    .classList.remove('daedalus-ui-hidden');
                }
                editMode.message(message, 'status');
              })
              .catch((error) => editMode.message(error.message, 'error'));
          });
        },
      );
    },
  };
})(jQuery, Drupal, once);
