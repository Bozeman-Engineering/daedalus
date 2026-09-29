/**
 * The media library AJAX plumbing: open the modal, receive the selection.
 *
 * The server half is DaedalusStylishMediaLibraryOpener: the sidebar embeds the
 * ready-made media_library.ui URL (state hash included, map host in the
 * opener parameters) in drupalSettings.daedalusStylish.mediaLibrary, and a picker
 * widget calls openMediaLibrary(onSelect) to open it through daedalus_ui's
 * modal helper (Drupal.Daedalus.openMediaLibraryModal, the one place
 * the modal is shaped). The user's selection returns as ONE
 * daedalusStylishMediaLibrarySelection command (the modal's close command rides the
 * same response) carrying the semantic token url('media:<uuid>'), the uuid,
 * and the server-resolved preview URL, routed here to the picker that is
 * waiting for it. The client never constructs library state and never
 * derives URLs — both are server-made.
 *
 * Leader-window only: the modal is core's AJAX dialog, whose stack rides
 * the daedalus_stylish/inspector library (daedalus_ui/media_library). The
 * satellite window has no dialog stack — a satellite picker must route its
 * open intent to the leader over the broadcast wire, the same way every
 * commit already routes (the leader source is the only writer).
 *
 * @see \Drupal\daedalus_stylish\DaedalusStylishMediaLibraryOpener
 * @see \Drupal\daedalus_stylish\Ajax\MediaLibrarySelection
 */

/**
 * The picker awaiting a selection: at most one, the modal being modal.
 */
let activePicker = null;

/**
 * The server-provided library URL, or null (media_library not installed,
 * unsaved host, no image- or vector-source media types). Read per call, not
 * at load:
 * drupalSettings settles after bundle evaluation. The typeof guard keeps the
 * module importable under node (the leader-source test battery).
 */
const libraryUrl = () =>
  (typeof drupalSettings !== 'undefined'
    ? drupalSettings.daedalusStylish?.mediaLibrary?.url
    : null) ?? null;

/**
 * Whether this window can open the media library at all.
 *
 * The honest-chrome gate for picker buttons: without a server URL the media
 * row degrades to free text. The leader source answers from here; the
 * satellite hears the leader's answer over the broadcast state (its own
 * settings never carry the URL, and it could not open the modal anyway).
 */
export const mediaLibraryAvailable = () => libraryUrl() !== null;

/**
 * The value a pick payload carries for the picked property until the
 * selection fills it with the token.
 *
 * A pick's changes arrive at the source already routed (the widget
 * pipeline's route() over the picked property and its companions, or the
 * advanced row's one change), before any media is chosen, so the picked
 * property needs a stand-in the routing treats as a set member and the
 * source can find again once the token exists. A plain string, never a
 * function: a satellite's pick crosses the broadcast wire as an intent,
 * and the leader fills the same payload it would have built itself.
 */
export const MEDIA_PICK_PLACEHOLDER = 'media:pick';

/**
 * Opens the media library modal, routing the selection to onSelect.
 *
 * @param {function({uuid: string, token: string, url: ?string}): void} onSelect
 *   Receives the selection: the lowercased media uuid (the client table's
 *   key), the canonical style token to commit, and the resolved preview URL
 *   (null when the media has no image file — degrade the preview to "none",
 *   like the server's emit gate).
 *
 * @return {boolean}
 *   Whether the library could open (false without a server-provided URL —
 *   media_library not installed, unsaved host, no image- or vector-source
 *   types).
 */
export const openMediaLibrary = (onSelect) => {
  const url = libraryUrl();
  if (!url) {
    return false;
  }
  activePicker = onSelect;
  Drupal.Daedalus.openMediaLibraryModal(url);
  return true;
};

// The command handler registers at bundle load (guarded: the satellite
// bundle shares widget modules but loads without the AJAX stack). A
// selection with no picker waiting for it is an integration bug (the picker
// cleared underneath the open modal); silence would be an invisible lost
// pick.
if (typeof Drupal !== 'undefined' && Drupal.AjaxCommands) {
  Drupal.AjaxCommands.prototype.daedalusStylishMediaLibrarySelection = function (
    ajax,
    response,
  ) {
    const picker = activePicker;
    activePicker = null;
    if (typeof picker !== 'function') {
      console.warn(
        'Daedalus Stylish received a media library selection but no picker was waiting; the selection was dropped.',
      );
      return;
    }
    picker({ uuid: response.uuid, token: response.token, url: response.url });
  };
}
