/**
 * @file
 * The messenger's pure model: what a message call decides before any DOM.
 *
 * Deliberately free of the DOM, the Drupal global and storage so the
 * contract is testable in node (tests/js/messenger-model.test.mjs): the
 * identity of a message (its id), how long each type shows, the persisted
 * history's shape and cap, and the replace decision (a message whose id is
 * already showing is restarted and pulsed, never added twice and never
 * silently dropped). js/messenger.js renders these decisions.
 */

/**
 * The history cap: the newest entries kept in storage.
 */
export const HISTORY_CAP = 100;

/**
 * How long a toast of each type shows, in milliseconds.
 */
const DURATIONS = {
  status: 6000,
  warning: 8000,
  error: 10000,
};

/**
 * The duration for a type the table does not name.
 */
const DEFAULT_DURATION = 8000;

/**
 * A stable 32-bit hash of a string (the Java String.hashCode recipe).
 *
 * Module-local on purpose: the old implementation patched
 * String.prototype, which every script on the page could see.
 *
 * @param {string} text
 *   The text to hash.
 *
 * @return {number}
 *   A signed 32-bit integer; 0 for the empty string.
 */
export const hash = (text) => {
  let value = 0;
  for (let i = 0; i < text.length; i++) {
    // The djb2-style hash keeps the value a 32-bit integer by shifting.
    // eslint-disable-next-line no-bitwise
    value = ((value << 5) - value + text.charCodeAt(i)) | 0;
  }
  return value;
};

/**
 * The identity of a message: the same type and text is the same message.
 *
 * @param {string} type
 *   The message type.
 * @param {string} text
 *   The message text.
 *
 * @return {string}
 *   The id the toast element and the history entry carry.
 */
export const messageId = (type, text) => String(hash(`${type}:${text}`));

/**
 * The default showing time for a message type.
 *
 * @param {string} type
 *   The message type.
 *
 * @return {number}
 *   Milliseconds.
 */
export const durationFor = (type) => DURATIONS[type] ?? DEFAULT_DURATION;

/**
 * Plans a message: resolves its id, duration and options against what is
 * already showing.
 *
 * @param {object} request
 *   The call: {text, type, duration, scroll, target}. `duration` -1 (or 0)
 *   means the toast never leaves on its own; `target` is the element id or
 *   selectable key the outcome concerns, for the history's scroll-back.
 * @param {Array<string>} showing
 *   The ids of the toasts currently on screen.
 *
 * @return {{id: string, text: string, type: string, duration: number, scroll: boolean, target: (string|null), replace: boolean}}
 *   The plan. `replace` is true when the same message is already showing:
 *   the messenger restarts that toast and pulses it instead of adding
 *   another, so a retried failure is seen again rather than deduped away.
 */
export const planMessage = (
  { text, type = 'error', duration, scroll = true, target = null },
  showing = [],
) => {
  const id = messageId(type, text);
  return {
    id,
    text,
    type,
    duration: duration ?? durationFor(type),
    scroll,
    target,
    replace: showing.includes(id),
  };
};

/**
 * The history entry a plan persists.
 *
 * @param {{id: string, text: string, type: string, target: (string|null)}} plan
 *   The plan.
 * @param {number} timestamp
 *   Milliseconds since the epoch.
 *
 * @return {{id: string, message: string, type: string, timestamp: number, target?: string}}
 *   The entry; `target` is present only when the message has one.
 */
export const historyEntry = ({ id, text, type, target }, timestamp) => ({
  id,
  message: text,
  type,
  timestamp,
  ...(target ? { target } : {}),
});

/**
 * Appends an entry to the history, keeping the newest HISTORY_CAP.
 *
 * @param {Array<object>} history
 *   The stored history, oldest first.
 * @param {object} entry
 *   The entry to append.
 *
 * @return {Array<object>}
 *   A new array; the input is not mutated.
 */
export const pushHistory = (history, entry) =>
  [...history, entry].slice(-HISTORY_CAP);

/**
 * Reads a stored history string.
 *
 * @param {string|null} raw
 *   The stored value, or null when nothing was stored.
 *
 * @return {Array<object>}
 *   The history; empty when nothing was stored or the value is not a JSON
 *   array (a hand edit of the key): the history starts fresh rather than
 *   the messenger refusing to show the toast, which is its real job.
 */
export const parseHistory = (raw) => {
  if (raw === null || raw === undefined) {
    return [];
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    return [];
  }
  return Array.isArray(parsed) ? parsed : [];
};
