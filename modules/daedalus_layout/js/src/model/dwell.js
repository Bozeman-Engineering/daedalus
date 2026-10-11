/**
 * @file
 * The peek's dwell: one pending peek at a time, after a moment's rest.
 *
 * Hovering or focusing a row for a moment shows, beside the panel, what
 * dropping it here would give. The moment is this model: a row is armed
 * when the pointer enters it or it takes focus, and the peek fires only
 * when the row has been rested on for DWELL_MS, so a pointer sweeping
 * down the list requests nothing. One peek is pending at a time: arming
 * another row cancels the first, and arming the row already pending is
 * a no-op (the pointer moving between a row's own children must not
 * restart its clock).
 *
 * Four gestures cancel a pending peek and the component wires each to
 * `cancel()`: leaving the row (the pointer out, or focus moving on),
 * the panel losing focus (blur), Escape, and a drag start (a lift must
 * never be followed by a peek of the thing being dragged). Cancelling
 * what is not pending is a harmless no.
 *
 * The timer is injected so the node tier drives it by hand; the
 * defaults are the window's.
 *
 * What fires is the caller's: T30 posts the row's own drop batch to the
 * peek door and frames the answer. This model never requests anything.
 *
 * Pure: no DOM, no Preact.
 */

/**
 * How long a row is rested on before its peek fires, in milliseconds.
 */
export const DWELL_MS = 400;

/**
 * Builds a dwell.
 *
 * @param {object} options
 *   `delay` in milliseconds (DWELL_MS); `setTimer(fn, ms)` and
 *   `clearTimer(handle)` as setTimeout and clearTimeout.
 *
 * @return {{arm: Function, cancel: Function, pending: Function}}
 *   `arm(key, fire)` starts the clock for a row (its key), calling
 *   `fire(key)` when it runs out; `cancel()` drops the pending peek and
 *   answers whether there was one; `pending()` answers the pending key
 *   or null.
 */
export const createDwell = ({
  delay = DWELL_MS,
  setTimer = (fn, ms) => setTimeout(fn, ms),
  clearTimer = (handle) => clearTimeout(handle),
} = {}) => {
  let pending = null;

  const cancel = () => {
    if (pending === null) {
      return false;
    }
    clearTimer(pending.handle);
    pending = null;
    return true;
  };

  const arm = (key, fire) => {
    if (pending !== null && pending.key === key) {
      return;
    }
    cancel();
    const handle = setTimer(() => {
      pending = null;
      fire(key);
    }, delay);
    pending = { key, handle };
  };

  return {
    arm,
    cancel,
    pending: () => (pending === null ? null : pending.key),
  };
};
