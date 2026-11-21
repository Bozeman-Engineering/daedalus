/**
 * The cue a restore gives when it moved another editor's work.
 *
 * The timeline is the page's: an undo takes back the last change to the
 * page, whoever made it. Taking back one's own change is what the editor
 * asked for and stays silent; taking back, or putting back, someone
 * else's is said once, naming whose it was and what it was.
 *
 * The restore answer names the changes the move undid or redid
 * (`restored_changes`, each with its label, the `uid` that made it and
 * the `author`'s display name, null for an account that no longer exists)
 * and which way it went (`restored_direction`). A step moves one change;
 * a jump from the History panel may move several.
 *
 * Pure of the page but for Drupal.t, drupalSettings and the messenger, so
 * the sentences are tested in node (tests/js/history-cue.test.mjs).
 */

/**
 * The sentence for the other editors' changes a restore moved.
 *
 * Plain text for the messenger, which escapes once: every placeholder is
 * the pass-through `!name`.
 *
 * @param {?object} payload
 *   The applied restore answer.
 * @param {number|string} currentUid
 *   The current user's id.
 *
 * @return {?string}
 *   The sentence, or null when every change moved was the current user's.
 */
export const restoreCue = (payload, currentUid) => {
  const others = (payload?.restored_changes ?? []).filter(
    (change) =>
      change.uid !== null &&
      change.uid !== undefined &&
      Number(change.uid) !== Number(currentUid),
  );
  if (others.length === 0) {
    return null;
  }
  const redo = payload.restored_direction === 'redo';

  if (others.length === 1) {
    const [{ author, label }] = others;
    if (author) {
      const args = { '!author': author, '!label': label };
      return redo
        ? Drupal.t("Redid !author's change: !label", args)
        : Drupal.t("Undid !author's change: !label", args);
    }
    const args = { '!label': label };
    return redo
      ? Drupal.t("Redid another editor's change: !label", args)
      : Drupal.t("Undid another editor's change: !label", args);
  }

  const [{ author, uid }] = others;
  if (author && others.every((change) => change.uid === uid)) {
    const args = { '!count': others.length, '!author': author };
    return redo
      ? Drupal.t("Redid !count of !author's changes.", args)
      : Drupal.t("Undid !count of !author's changes.", args);
  }
  const args = { '!count': others.length };
  return redo
    ? Drupal.t('Redid !count changes by other editors.', args)
    : Drupal.t('Undid !count changes by other editors.', args);
};

/**
 * Reports an applied restore that moved another editor's change.
 *
 * @param {?object} payload
 *   The applied restore answer.
 */
export const announceRestore = (payload) => {
  const text = restoreCue(payload, drupalSettings.user.uid);
  if (text !== null) {
    Drupal.Daedalus.messenger.message(text, 'status');
  }
};
