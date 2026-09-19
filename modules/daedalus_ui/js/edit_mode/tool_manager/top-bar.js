/**
 * The Edit Mode top bar's save, discard, and refresh buttons.
 *
 * Part of the app bundle; behaviors only, so initTopBar() has no ordering
 * needs beyond running before the next attachBehaviors pass.
 */
export const initTopBar = () => {
  /**
   * Refresh button.
   */
  Drupal.behaviors.DaedalusUiRefresh = {
    attach: (context, settings) => {
      once(
        'DaedalusUiRefresh',
        '#daedalus-ui-refresh',
        context,
      ).forEach((refreshButton) => {
        refreshButton.onclick = (e) => {
          window.location.reload();
        };
      });
    },
  };

  /**
   * Save button
   */
  Drupal.behaviors.DaedalusUiSave = {
    attach: (context, settings) => {
      once('DaedalusUiSave', '#daedalus-ui-save', context).forEach(
        (saveButton) => {
          saveButton.onclick = async (e) => {
            if (Drupal.Daedalus.Edit) {
              await Drupal.Daedalus.Edit.notUpdating();
            }
            // @todo Temporarily use the Daedalus Edit save controller.
            // @todo This creates a dependency on Daedalus Edit when in reality the
            // @todo tempstore for Daedalus Edit and Daedalus Layout should bubble up to some parent
            // @todo module.
            const info =
              Drupal.Daedalus.ModeManager.getPlugin(
                'edit',
              ).getMainEntityInfo();
            if (!info) {
              return;
            }

            if (Drupal.Daedalus.Edit?.hasInvalidFormItem?.() === true) {
              // @todo warnAboutInvalidFormItem should detect that there is already a message on screen for this so you don't get
              // double messages (the form submit validation and the "hey fix this before you continue")
              Drupal.Daedalus.Edit.warnAboutInvalidFormItem();
              return;
            }

            window.location = Drupal.Daedalus.ModePluginBase.url(
              `/daedalus-edit/tempstore/save/${info.entityType}.${
                info.id
              }?destination=${window.location.pathname}`,
            );
          };
        },
      );
    },
  };

  /**
   * Discard changes button
   */
  Drupal.behaviors.DaedalusUiDiscardChanges = {
    attach: (context, settings) => {
      once(
        'DaedalusUiDiscardChanges',
        '#daedalus-ui-discard-changes',
        context,
      ).forEach((discardChangesButton) => {
        discardChangesButton.onclick = (e) => {
          // @todo Temporarily use the Daedalus Edit delete controller.
          // @todo This creates a dependency on Daedalus Edit when in reality the
          // @todo tempstore for Daedalus Edit and Daedalus Layout should bubble up to some parent
          // @todo module.
          const info =
            Drupal.Daedalus.ModeManager.getPlugin(
              'edit',
            ).getMainEntityInfo();
          if (!info) {
            return;
          }
          Drupal.Daedalus.ModePluginBase.ajax({
            url: `/daedalus-edit/tempstore/delete-confirm/${info.entityType}.${
              info.id
            }?destination=${window.location.pathname}`,
            dialogType: 'modal',
            dialog: {
              title: Drupal.t('Discard changes?'),
              width: 'auto',
              height: 'auto',
              dialogClass: 'discard-changes-dialog',
            },
            error: (error) => {
              console.error(
                'Unable to discard changes: ',
                error.responseText || error,
              );
              Drupal.Daedalus.ModeManager.getPlugin('edit').handleError(
                error,
                'Unable to discard changes.',
              );
            },
          }).execute();
        };
      });
    },
  };
};
