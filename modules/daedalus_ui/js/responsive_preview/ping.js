/**
 * @file
 * The leader half of the responsive preview's live updates.
 *
 * Rides the edit-mode bundle: the op queue calls pingMutated() after every
 * applied mutation response, and the popup shell (preview.js) reloads its
 * iframe on the ping. Nothing but the ping is broadcast: edits stage in
 * tempstore keyed to the user session, so the popup re-renders the staged
 * state straight from the server; the wire only needs to say "something
 * landed".
 */
import { openChannel } from '../broadcast/channel.js';

let channel = null;

/**
 * Broadcasts a 'mutated' ping to any paired responsive preview window.
 */
export const pingMutated = () => {
  // The responsivePreview settings ride along only when the canvas vector
  // cluster rendered (an edit session with a main entity); without a scope
  // there is no channel a popup could be listening on.
  const scope = drupalSettings.daedalusUi?.responsivePreview?.scope;
  if (!scope) {
    return;
  }
  // The channel stays open for the page's lifetime. BroadcastChannel never
  // echoes to the posting context, and the leader registers no handlers: it
  // only speaks.
  channel ??= openChannel('preview', scope, {});
  channel.post('mutated');
};
