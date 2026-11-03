/**
 * @file
 * The usage meter: what the user has left of each usage window.
 *
 * The ledger's caps are the site's and the user's spend windows; this
 * script is the chat's read of them, through the status route
 * ChatSidebar::build() hands over as drupalSettings.daedalus_ai.usageUrl
 * (set only when a configured window applies to the user: a site window
 * applies to everyone, a user window to those without the bypass
 * permission; the library is attached only then). The payload is the gate's own
 * arithmetic, never money: per window its scope, period, label, the
 * fraction left, whether it is reached, and the next moment the fraction
 * changes (daedalus_ai_ledger's UsageStatusController).
 *
 * Fetched at attach and whenever a chat turn ends, which the context
 * manager's fetch shim announces as `daedalus-ai:turn-ended` on document
 * (a deepchat response whose should_continue is falsy ends ai_chatbot's
 * polling, so that is the moment a turn's legs are all on the ledger).
 * Three surfaces read the result:
 * - the kebab's "Usage left" group (daedalus-ai-chat-menu.js reads
 *   windows() at open time, labels each row by the window's period and
 *   words it through hint(); the scope is not shown, the notice says
 *   whose limit is reached);
 * - the kebab's dot: data-usage="low" when a window is under a tenth,
 *   "out" when one is reached, absent otherwise (styled in
 *   daedalus-ai-usage.css);
 * - the one-line notice under the chat, present only while a window is
 *   reached, the same fact the gate's refusal states in the bubble, so
 *   the user need not send a message to learn it.
 *
 * Moments are worded on the client from the epoch the route answers: a
 * reset under a day away as a duration ("in 42 min"), a reached window's
 * as a clock time ("at 3:40 PM"), and a far one as a short date ("Fri
 * 9:12 AM" inside a week, "Nov 1" beyond), in the page's language.
 */

((Drupal, drupalSettings, once) => {
  const SCOPE_SITE = 'site';
  const LOW = 0.1;
  const DAY = 86400;
  const WEEK = 7 * DAY;

  Drupal.Daedalus.Ai = Drupal.Daedalus.Ai || {};

  /** @type {object[]} The windows of the last fetch, site windows first. */
  let windows = [];

  const locale = () => document.documentElement.lang || undefined;

  /**
   * Seconds from now to a moment.
   */
  const secondsUntil = (epoch) => epoch - Math.floor(Date.now() / 1000);

  /**
   * A duration in words: "3 h 10 min", "42 min", "1 min" (never less).
   */
  const duration = (seconds) => {
    const minutes = Math.max(1, Math.round(seconds / 60));
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    if (hours === 0) {
      return Drupal.t('@minutes min', { '@minutes': rest });
    }
    if (rest === 0) {
      return Drupal.t('@hours h', { '@hours': hours });
    }
    return Drupal.t('@hours h @minutes min', {
      '@hours': hours,
      '@minutes': rest,
    });
  };

  /**
   * A clock time: "3:40 PM".
   */
  const clock = (epoch) =>
    new Intl.DateTimeFormat(locale(), { timeStyle: 'short' }).format(
      new Date(epoch * 1000),
    );

  /**
   * A short date: "Fri 9:12 AM" inside a week, "Nov 1" beyond (with the
   * year when it differs from this one).
   */
  const shortDate = (epoch) => {
    const date = new Date(epoch * 1000);
    if (secondsUntil(epoch) < WEEK) {
      return new Intl.DateTimeFormat(locale(), {
        weekday: 'short',
        hour: 'numeric',
        minute: '2-digit',
      }).format(date);
    }
    const options = { month: 'short', day: 'numeric' };
    if (date.getFullYear() !== new Date().getFullYear()) {
      options.year = 'numeric';
    }
    return new Intl.DateTimeFormat(locale(), options).format(date);
  };

  /**
   * The percent left, floored so 99.6% never reads as full.
   */
  const percent = (window) => Math.floor(window.remaining * 100);

  /**
   * When a reset moment is, in words: "at 3:40 PM" within a day, else the
   * short date. Null when the window never clears by time.
   */
  const resetWords = (epoch) => {
    if (epoch === null || epoch === undefined) {
      return null;
    }
    return secondsUntil(epoch) < DAY
      ? Drupal.t('at @time', { '@time': clock(epoch) })
      : shortDate(epoch);
  };

  /**
   * The usage facade.
   *
   * @namespace Drupal.Daedalus.Ai.usage
   */
  const usage = {
    /**
     * The windows of the last fetch, site windows first, as the route
     * answered them ({scope, period, label, remaining, reached, reset_at}).
     *
     * @return {object[]}
     *   The windows; empty before the first fetch answers.
     */
    windows() {
      return windows;
    },

    /**
     * The kebab row's hint: the percent left and when that changes.
     *
     * @param {object} window
     *   A window.
     *
     * @return {string}
     *   "82% · resets in 3 h 10 min" under a day, "61% · resets Fri 9:12
     *   AM" beyond, "0% · resets at 3:40 PM" when reached, the percent
     *   alone when nothing is due to change.
     */
    hint(window) {
      const left = Drupal.t('@percent%', { '@percent': percent(window) });
      const epoch = window.reset_at;
      if (epoch === null || epoch === undefined) {
        return left;
      }
      if (window.reached) {
        return Drupal.t('@left · resets @when', {
          '@left': left,
          '@when': resetWords(epoch),
        });
      }
      if (secondsUntil(epoch) < DAY) {
        return Drupal.t('@left · resets in @duration', {
          '@left': left,
          '@duration': duration(secondsUntil(epoch)),
        });
      }
      return Drupal.t('@left · resets @date', {
        '@left': left,
        '@date': shortDate(epoch),
      });
    },

    /**
     * The notice's line for a reached window.
     *
     * @param {object} window
     *   A reached window.
     *
     * @return {string}
     *   The line.
     */
    notice(window) {
      const when = resetWords(window.reset_at);
      if (window.scope === SCOPE_SITE) {
        return when === null
          ? Drupal.t("This site's AI usage limit for the @label is reached", {
              '@label': window.label,
            })
          : Drupal.t(
              "This site's AI usage limit for the @label is reached · resets @when",
              { '@label': window.label, '@when': when },
            );
      }
      return when === null
        ? Drupal.t('AI usage limit reached for the last @label', {
            '@label': window.label,
          })
        : Drupal.t('AI usage limit reached for the last @label · resets @when', {
            '@label': window.label,
            '@when': when,
          });
    },

    /**
     * Fetches the status and repaints the dot and the notice.
     *
     * @return {Promise<object[]>}
     *   The windows.
     */
    async refresh() {
      const response = await fetch(drupalSettings.daedalus_ai.usageUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(
          `Daedalus AI: the usage status route answered ${response.status}.`,
        );
      }
      windows = (await response.json()).windows;
      render();
      return windows;
    },
  };

  /**
   * Paints the kebab's dot and the notice from the fetched windows.
   */
  const render = () => {
    const reached = windows.filter((window) => window.reached);
    const low = windows.some(
      (window) => !window.reached && window.remaining < LOW,
    );
    document
      .querySelectorAll('.daedalus-ai-chat-menu-button')
      .forEach((button) => {
        if (reached.length > 0) {
          button.setAttribute('data-usage', 'out');
        } else if (low) {
          button.setAttribute('data-usage', 'low');
        } else {
          button.removeAttribute('data-usage');
        }
      });
    document.querySelectorAll('#daedalus_ai .chat-container').forEach((chat) => {
      let notice = chat.parentElement.querySelector(
        '.daedalus-ai-chat-usage-notice',
      );
      if (reached.length === 0) {
        notice?.remove();
        return;
      }
      if (!notice) {
        notice = document.createElement('div');
        notice.className = 'daedalus-ai-chat-usage-notice';
        notice.setAttribute('role', 'status');
        chat.insertAdjacentElement('afterend', notice);
      }
      // A site window refuses everyone and is checked first, so it is the
      // one line that matters when both are reached.
      notice.textContent = usage.notice(reached[0]);
    });
  };

  Drupal.Daedalus.Ai.usage = usage;

  Drupal.behaviors.daedalusAiUsage = {
    attach(context) {
      once('daedalus-ai-usage', '#daedalus_ai', context).forEach(() => {
        document.addEventListener('daedalus-ai:turn-ended', () => {
          usage.refresh();
        });
        usage.refresh();
      });
    },
  };
})(Drupal, drupalSettings, once);
