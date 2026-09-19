import { elementForKey } from '../selection/resolver';

/**
 * The one mutation-failure display.
 *
 * Every mutation path reports failures here so they all fail the same way:
 * a red flash on the element the failure reverted (for structure ops the
 * rollback snap-back IS most of the display; the flash says why the block
 * jumped), plus a notification carrying the server's structured error
 * (message and hint) as both transient toast and persistent sidebar entry.
 * The element's selectable key rides the notification so the sidebar entry
 * can scroll back to the element later.
 *
 * Tail rejections are loud exactly once: when a failure rolls back ops
 * queued behind it, the failing op's own report says how many follow-on
 * changes were undone, and the abandoned ops' rejections stay quiet.
 *
 * @param {Error} error
 *   The op-queue rejection; carries .payload (the response contract) on
 *   server failures, .abandonedCount when queued ops rolled back with it,
 *   and .abandoned on the quiet tail rejections themselves.
 * @param {?(Element|string)} target
 *   The reverted element, or its selectable key. Optional — a failed
 *   placement has nothing on the page to flash.
 */
export const reportMutationFailure = (error, target = null) => {
  if (error.abandoned) {
    return;
  }

  const element = typeof target === 'string' ? elementForKey(target) : target;
  const key =
    typeof target === 'string'
      ? target
      : (target?.getAttribute?.('data-selectable') ?? null);

  if (element?.isConnected) {
    element.classList.add('daedalus-ui-mutation-failed');
    setTimeout(() => element.classList.remove('daedalus-ui-mutation-failed'), 1600);
  }

  const details = error.payload?.errors?.[0];
  const parts = [details?.message ?? error.message];
  if (details?.hint) {
    parts.push(details.hint);
  }
  if (error.abandonedCount) {
    parts.push(
      Drupal.formatPlural(
        error.abandonedCount,
        '1 follow-on change was undone.',
        '@count follow-on changes were undone.',
      ),
    );
  }

  Drupal.Daedalus.ModeManager.getPlugin('edit').message(
    parts.join(' '),
    'error',
    {},
    key,
  );
};
