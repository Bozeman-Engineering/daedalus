/**
 * The top bar's Save, client half: one POST and what its answer means.
 *
 * The save route (daedalus_edit's Tempstore::save) commits the staged
 * changes and answers JSON: `{destination}` on a commit, `{messages}`
 * (plain-text sentences) when the commit door refuses: the page was saved
 * elsewhere after the changes were started, the whole entity does not
 * validate, storage failed. A commit navigates to the destination; a
 * refusal shows each message and navigates nowhere, so the editor stays in
 * Edit Mode with the unsaved changes still staged.
 *
 * Pure: the poster (Drupal.Daedalus.post), the navigation and the message
 * sink are handed in, so the node spec drives every answer without a
 * browser (tests/js/save-commit.test.mjs).
 */

/**
 * Posts the save and acts on the answer.
 *
 * @param {string} url
 *   The save route's URL.
 * @param {object} doors
 *   What the answer is acted on through.
 * @param {function(string): Promise<Response>} doors.post
 *   Sends the POST with the session token.
 * @param {function(string): void} doors.navigate
 *   Takes the browser to a destination.
 * @param {function(string): void} doors.report
 *   Shows one error message to the editor.
 *
 * @return {Promise<boolean>}
 *   True when the changes were committed and the navigation asked for,
 *   false when the server refused and the messages were shown.
 */
export const commitSave = async (url, { post, navigate, report }) => {
  const response = await post(url);
  const type = response.headers.get('Content-Type') ?? '';
  if (!type.includes('application/json')) {
    // Not the route's own answer (an access refusal, an error page): the
    // editor is told, and the failure stays loud for whoever debugs it.
    const message = Drupal.t(
      'The changes could not be saved (the server answered @status).',
      { '@status': response.status },
    );
    report(message);
    throw new Error(message);
  }
  const answer = await response.json();
  if (response.ok) {
    navigate(answer.destination);
    return true;
  }
  answer.messages.forEach((message) => report(message));
  return false;
};
