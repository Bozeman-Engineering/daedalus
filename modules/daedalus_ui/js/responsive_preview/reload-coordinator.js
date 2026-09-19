/**
 * @file
 * Coordinates preview iframe reloads triggered by mutated pings.
 *
 * A pure state machine, DOM-free so node can test it: the shell supplies
 * capture/reload/restore over its iframe. The rules:
 * - A ping while idle captures the scroll position and starts a reload.
 * - Pings that arrive while a reload is in flight coalesce into at most one
 *   follow-up reload. The server renders the complete staged state on every
 *   request, so one reload after the last ping is always enough; replaying
 *   every ping would just flicker.
 * - The load that completes a reload restores the captured scroll position.
 *   Loads the coordinator did not trigger (the first load, the reader
 *   navigating inside the frame) restore nothing.
 *
 * @param {Object} frame
 *   The shell's iframe operations.
 * @param {Function} frame.capture
 *   Returns the current scroll position ({x, y}), or null when there is
 *   nothing to capture.
 * @param {Function} frame.reload
 *   Starts a reload of the frame.
 * @param {Function} frame.restore
 *   Restores a captured scroll position ({x, y}).
 *
 * @return {{mutated: Function, loaded: Function}}
 *   mutated() handles a mutated ping; loaded() handles the iframe's load
 *   event.
 */
export const createReloadCoordinator = ({ capture, reload, restore }) => {
  let pendingScroll = null;
  let reloading = false;
  let queued = false;

  const start = () => {
    pendingScroll = capture();
    reloading = true;
    reload();
  };

  return {
    mutated: () => {
      if (reloading) {
        queued = true;
        return;
      }
      start();
    },
    loaded: () => {
      if (!reloading) {
        return;
      }
      if (pendingScroll) {
        restore(pendingScroll);
        pendingScroll = null;
      }
      if (queued) {
        queued = false;
        start();
      } else {
        reloading = false;
      }
    },
  };
};
