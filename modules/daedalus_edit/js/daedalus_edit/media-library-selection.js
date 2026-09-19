/**
 * AJAX command handler for media library selections.
 *
 * The server half is the DaedalusEditMediaLibraryOpener: a blueprint editor
 * opens the media_library.ui URL from its editor settings as an AJAX modal,
 * and the user's selection comes back as this one command (the modal's
 * close command rides the same response). The selection routes to the
 * CURRENT edit — the modal is only ever reachable from an active blueprint
 * edit gesture, so the current edit is the selection's addressee.
 *
 * The editor contract: a blueprint editor that opens the media library
 * implements mediaSelected(EditableElement, selectedIds). Editors commit
 * the selection immediately as its own history entry — no client-side
 * preview exists for a media reference; the mutation response's markup
 * swap delivers the new render.
 *
 * @see \Drupal\daedalus_edit\Ajax\MediaLibrarySelection
 * @see \Drupal\daedalus_edit\DaedalusEditMediaLibraryOpener
 */
Drupal.AjaxCommands.prototype.daedalusEditMediaLibrarySelection = function (
  ajax,
  response,
) {
  const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  if (typeof current?.plugin?.mediaSelected !== 'function') {
    // A selection with no edit able to receive it is an integration bug
    // (the edit ended underneath the open modal, or the editor forgot to
    // implement its half); silence here would be an invisible lost commit.
    console.warn(
      'Daedalus Edit received a media library selection but no current edit implements mediaSelected(); the selection was dropped.',
    );
    return;
  }
  current.plugin.mediaSelected(current, response.selectedIds.map(Number));
};
