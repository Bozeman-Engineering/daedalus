import { verbsFor, onChange as onVerbsChange } from '../verbs/verbs';
import { verbMenuItems } from '../verbs/verb-menu-items';

/**
 * The Edit Mode top bar's save, discard, and refresh buttons.
 *
 * Part of the app bundle; behaviors only, so initTopBar() has no ordering
 * needs beyond running before the next attachBehaviors pass.
 *
 * Save is a split control (ButtonBuilderTrait::buildSplitButton()): the
 * button commits the tempstore, and the caret beside it opens the DOCUMENT
 * menu, the top bar's face of the verb registry. The menu lists the root
 * entity's verbs flagged `document` (verbs.js register), adapted through
 * the one verbs-to-items wire (verb-menu-items.js) into the shared menu
 * primitive, so a whole-page gesture a module registers as a root verb
 * (daedalus_layout's "Save page as blueprint…") shows up here with its
 * capability gate and its keyboard contract intact, and the bar itself
 * names no gesture. The caret renders hidden and reveals only while the
 * root offers at least one document verb: honest chrome, never a caret
 * that opens nothing.
 */

/**
 * Where Save and Discard land, percent-encoded for the query.
 *
 * The edited page's own path, unless this canvas was reached through a
 * portal's leave (Drupal.Daedalus.Portal.leave), which put the page it
 * left from on the URL as `destination`: then that page is where the
 * editor returns, so a Save on a header or a footer never strands them on
 * the block's root.
 */
const saveDestination = () =>
  encodeURIComponent(
    new URLSearchParams(window.location.search).get('destination') ||
      window.location.pathname,
  );

/**
 * The root entity's selectable key, or null before the mirror boots.
 */
const rootKey = () => {
  const root = Drupal.Daedalus.Blueprint?.getRoot?.();
  return root ? `entity:${root.uuid}` : null;
};

/**
 * The document verbs the root currently offers, menu-ordered.
 */
const documentVerbKeys = () => {
  const key = rootKey();
  return key && verbsFor(key).some((verb) => verb.document) ? [key] : [];
};

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
            // Every write (a spine op, a form commit) rides the op queue;
            // a commit still on the wire must land in tempstore before the
            // page navigates to the save route.
            await Drupal.Daedalus.Blueprint.drained();
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
              `/daedalus-edit/tempstore/save/${info.entityType}/${
                info.id
              }?destination=${saveDestination()}`,
            );
          };
        },
      );
    },
  };

  /**
   * The Save caret: the document menu over the root's `document` verbs.
   *
   * The caret follows the registry and the mirror: a verb registering
   * later, or the root arriving with the mirror's boot, reveals it; a
   * root the gate refuses every document verb on hides it. The items are
   * read at open time, like every other face of the registry.
   */
  Drupal.behaviors.DaedalusUiSaveMenu = {
    attach: (context, settings) => {
      once('DaedalusUiSaveMenu', '#daedalus-ui-save-menu', context).forEach(
        (caret) => {
          const sync = () => {
            caret.hidden = documentVerbKeys().length === 0;
          };
          sync();
          onVerbsChange(sync);
          Drupal.Daedalus.Blueprint.onBlueprintChange(sync);
          caret.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            Drupal.Daedalus.menu.open({
              anchor: caret,
              align: 'end',
              items: verbMenuItems(documentVerbKeys(), (verb) => verb.document),
            });
          });
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
            url: `/daedalus-edit/tempstore/delete-confirm/${info.entityType}/${
              info.id
            }?destination=${saveDestination()}`,
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
