/**
 * @file
 * The notification spine (Drupal.Daedalus.messenger): one message
 * display any window hosts.
 *
 * A gesture's outcome (a write succeeded, failed, landed with warnings) is
 * reported here and nowhere else:
 * @code
 *   Drupal.Daedalus.messenger.message('Saving failed.', 'error', { target: key });
 * @endcode
 *
 * The messenger shows a transient toast in the document's
 * [data-drupal-messages] wrapper (core's status_messages wrapper when the
 * page rendered one, its fallback, or one the messenger creates), keeps a
 * persistent history in one localStorage key that every same-origin window
 * shares, and owns the toast's look (css/messenger.css), so a window that
 * never rendered status_messages (daedalus_stylish's satellite) still shows styled
 * toasts. A message identical to one already showing is restarted and
 * pulsed rather than added again or dropped: a retried failure is seen.
 *
 * Standalone on purpose: no edit mode, no mode manager. The edit-mode
 * plugin's message() is a facade over this; the notifications sidebar is a
 * consumer of history(). A native ES module (it imports its pure model
 * relative to its own URL), so it runs deferred: classic scripts read
 * Drupal.Daedalus.messenger at call time, never at parse time.
 */
import {
  planMessage,
  historyEntry,
  pushHistory,
  parseHistory,
} from './messenger-model.js';

Drupal.Daedalus = Drupal.Daedalus || {};

/**
 * The localStorage key the history lives in. The messenger is its only
 * writer; consumers read it through history() and learn of writes from the
 * window's `storage` event on this key.
 */
const STORAGE_KEY = 'daedalus_ui_notifications';

/**
 * The class the messenger stamps on the wrapper it manages; the toast CSS
 * is scoped to it, so a page whose wrapper stays theme-rendered keeps the
 * theme's messages styling until the first message() call claims it.
 */
const CLAIM_CLASS = 'daedalus-ui-messenger';

/**
 * The exit animation's length (css/messenger.css), after which the toast
 * element leaves the DOM.
 */
const EXIT_MS = 200;

/**
 * The pulse animation's length (css/messenger.css).
 */
const PULSE_MS = 400;

/**
 * The pending timer per showing toast id (its leave, or its removal once
 * the exit animation has run), so a replace can cancel it.
 */
const timers = new Map();

/**
 * Claims the document's messages wrapper and returns its inner list.
 *
 * Core's defaultWrapper() finds [data-drupal-messages], converts the
 * hidden [data-drupal-messages-fallback] placeholder a page without server
 * messages renders, or creates an attributed wrapper on the body, and hands
 * back the .messages__wrapper list inside it.
 *
 * The claimed wrapper is body-level chrome, like the canvas drag handles:
 * the theme renders it inside a page region, which in any daedalus_ui
 * mode sits inside #daedalus-ui-stage, an isolated paint layer
 * (canvas-vector.css). A fixed wrapper left in there can never paint over
 * body-level chrome, whatever its z-index: a modal's overlay blurs the
 * toast the moment a consumer reports while a dialog is up. So the
 * messenger moves the wrapper it owns to the body on first use.
 *
 * @return {HTMLElement}
 *   The inner list toasts are appended to.
 */
const claim = () => {
  // A wrapper the server rendered with whitespace only has no element
  // child; core would then hand back null as the inner list. See
  // https://www.drupal.org/project/drupal/issues/3407067.
  const rendered = document.querySelector('[data-drupal-messages]');
  if (
    rendered &&
    rendered.innerHTML !== '' &&
    rendered.firstElementChild === null
  ) {
    rendered.innerHTML = '';
  }
  const list = Drupal.Message.defaultWrapper();
  const wrapper = list.parentElement;
  wrapper.classList.add(CLAIM_CLASS);
  if (wrapper.parentElement !== document.body) {
    document.body.append(wrapper);
  }
  return list;
};

/**
 * Selects a showing toast by its exact id.
 *
 * Core's Message.select() matches by prefix, which conflates ids whose
 * text is a prefix of another's; the messenger matches exactly.
 *
 * @param {HTMLElement} list
 *   The inner list.
 * @param {string} id
 *   The toast id.
 *
 * @return {HTMLElement|null}
 *   The toast element.
 */
const select = (list, id) =>
  list.querySelector(`[data-drupal-message-id="${CSS.escape(id)}"]`);

/**
 * Removes a toast from the DOM and forgets its timer.
 *
 * @param {HTMLElement} element
 *   The toast element.
 */
const remove = (element) => {
  timers.delete(element.getAttribute('data-drupal-message-id'));
  element.remove();
};

/**
 * Plays the exit animation, then removes the toast.
 *
 * @param {HTMLElement} element
 *   The toast element.
 */
const leave = (element) => {
  element.classList.add('is-exiting');
  timers.set(
    element.getAttribute('data-drupal-message-id'),
    setTimeout(() => remove(element), EXIT_MS),
  );
};

/**
 * Restarts a showing toast: cancels its pending leave or removal, brings
 * it back from a running exit, and pulses it so the repeat is seen.
 *
 * @param {HTMLElement} element
 *   The toast element.
 */
const restart = (element) => {
  clearTimeout(timers.get(element.getAttribute('data-drupal-message-id')));
  element.classList.remove('is-exiting');
  // The pulse rides a pseudo-element the class creates, so removing and
  // re-adding the class replays it without touching the toast's own
  // entrance animation; the reflow between the two makes the removal take.
  element.classList.remove('is-repeated');
  // eslint-disable-next-line no-void
  void element.offsetWidth;
  element.classList.add('is-repeated');
  setTimeout(() => element.classList.remove('is-repeated'), PULSE_MS);
};

/**
 * Persists a history and tells this window's consumers.
 *
 * The browser fires `storage` in every same-origin window except the one
 * that wrote; the messenger fires the same event locally so a consumer
 * (the notifications sidebar) has one listener for every writer, its own
 * window included.
 *
 * @param {Array<object>} history
 *   The history to store.
 */
const write = (history) => {
  const oldValue = localStorage.getItem(STORAGE_KEY);
  const newValue = JSON.stringify(history);
  localStorage.setItem(STORAGE_KEY, newValue);
  window.dispatchEvent(
    new StorageEvent('storage', {
      key: STORAGE_KEY,
      oldValue,
      newValue,
      storageArea: localStorage,
      url: window.location.href,
    }),
  );
};

/**
 * The persisted history, oldest first.
 *
 * @return {Array<{id: string, message: string, type: string, timestamp: number, target?: string}>}
 *   A fresh array; mutating it changes nothing.
 */
const history = () => parseHistory(localStorage.getItem(STORAGE_KEY));

/**
 * Shows a message and records it in the history.
 *
 * @param {string} text
 *   The message text (HTML is rendered, as core's Drupal.Message does).
 * @param {string} type
 *   'status', 'warning' or 'error' (core's toast types).
 * @param {object} options
 *   - duration: milliseconds the toast shows; -1 keeps it until cleared.
 *     Defaults per type (status 6s, warning 8s, error 10s).
 *   - scroll: whether to bring the toast into view in the list (default
 *     true).
 *   - target: an element id or selectable key the outcome concerns; the
 *     history's entry links back to it.
 *
 * @return {string}
 *   The toast id: the same text and type is the same id.
 */
const message = (
  text,
  type = 'error',
  { duration, scroll = true, target = null } = {},
) => {
  const list = claim();
  const showing = [...list.querySelectorAll('[data-drupal-message-id]')].map(
    (element) => element.getAttribute('data-drupal-message-id'),
  );
  const plan = planMessage({ text, type, duration, scroll, target }, showing);

  let element;
  if (plan.replace) {
    element = select(list, plan.id);
    restart(element);
    Drupal.Message.announce(text, { type });
  } else {
    new Drupal.Message(list).add(text, { type, id: plan.id });
    element = select(list, plan.id);
    // Any interactive content in the message (a use-ajax link, say) gets
    // its behaviors.
    Drupal.attachBehaviors(element);
  }

  write(pushHistory(history(), historyEntry(plan, Date.now())));

  if (plan.scroll) {
    element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  if (plan.duration > 0) {
    timers.set(
      plan.id,
      setTimeout(() => leave(element), plan.duration),
    );
  }
  return plan.id;
};

/**
 * Removes every showing toast at once, animations skipped.
 */
const clear = () => {
  timers.forEach((timer) => clearTimeout(timer));
  timers.clear();
  document
    .querySelectorAll('[data-drupal-messages] [data-drupal-message-id]')
    .forEach((element) => element.remove());
};

/**
 * Empties the persisted history.
 */
const clearHistory = () => write([]);

Drupal.Daedalus.messenger = {
  message,
  clear,
  history,
  clearHistory,
  historyKey: STORAGE_KEY,
};
