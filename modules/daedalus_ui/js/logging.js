/**
 * @file
 * Per-module debug logging for the Daedalus client.
 *
 * A module grabs a tagged logger in one line and logs through it:
 * @code
 *   const logger = Drupal.Daedalus.logger('daedalus_stylish');
 *   logger.log('apply', 'Applying variant', payload);
 * @endcode
 *
 * Nothing prints until that module is switched on from the browser console:
 * @code
 *   Drupal.Daedalus.logging.enable('daedalus_stylish');  // One module.
 *   Drupal.Daedalus.logging.enable();           // Everything ('*').
 *   Drupal.Daedalus.logging.disable('daedalus_stylish'); // That module again.
 *   Drupal.Daedalus.logging.disable();          // Everything off.
 *   Drupal.Daedalus.logging.status();
 * @endcode
 *
 * The enabled list persists in one localStorage key so it survives reloads:
 * client-only developer toggles never belong in EditorSettings, which is for
 * server-readable editor preferences.
 *
 * Console only: no levels, no transport, no buffering. If a surface ever
 * wants more (a log tail in the notifications sidebar, say), grow this spine
 * rather than teaching a module its own logging again. Deliberately
 * dependency free beyond the Drupal global, so libraries that load outside
 * edit mode can depend on it.
 */
((Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  const STORAGE_KEY = 'daedalusUiLogging';

  /**
   * The wildcard module name: everything logs.
   */
  const ALL = '*';

  /**
   * Reads the persisted module list.
   *
   * @return {Array<string>}
   *   The enabled module names, empty when nothing was stored.
   */
  const seed = () => {
    let stored;
    try {
      stored = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch (e) {
      // Storage unavailable (private mode quirks) or holding something that
      // is not JSON (a hand edit): logging starts off, which is the state a
      // page without the toggle is in anyway. Rethrowing here would take
      // every consumer down over a developer convenience.
      return [];
    }
    return Array.isArray(stored)
      ? stored.filter((name) => typeof name === 'string')
      : [];
  };

  /**
   * The runtime source of truth; localStorage seeds it and mirrors it.
   */
  const enabledModules = new Set(seed());

  /**
   * One logger instance per module, so repeat calls hand back the same one.
   */
  const loggers = new Map();

  const persist = () => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify([...enabledModules]));
    } catch (e) {
      // Storage unavailable: the toggle still holds for this page, it just
      // won't survive a reload.
    }
  };

  const isEnabled = (module) =>
    enabledModules.has(ALL) || enabledModules.has(module);

  /**
   * What is logging, and what could.
   *
   * @return {{enabled: Array<string>, modules: Array<string>}}
   *   The enabled names (possibly just '*') and every module that has asked
   *   for a logger on this page, which is the menu of what enable() accepts.
   */
  const status = () => ({
    enabled: [...enabledModules],
    modules: [...loggers.keys()],
  });

  /**
   * Gets the logger for a module.
   *
   * @param {string} module
   *   The consuming module's machine name; it prefixes every line.
   *
   * @return {{log: Function, enabled: boolean}}
   *   A logger whose log(tag, message, data) prints `[module:tag] message`
   *   while the module is enabled, and whose enabled flag reports live
   *   state, so a hot path can skip building expensive data.
   */
  Drupal.Daedalus.logger = (module) => {
    if (!loggers.has(module)) {
      loggers.set(module, {
        log: (tag, message, data) => {
          if (!isEnabled(module)) {
            return;
          }
          if (data !== undefined) {
            console.log(`[${module}:${tag}] ${message}`, data);
          } else {
            console.log(`[${module}:${tag}] ${message}`);
          }
        },
        get enabled() {
          return isEnabled(module);
        },
      });
    }
    return loggers.get(module);
  };

  /**
   * The console API. Every call returns status(), so the console shows the
   * resulting state without a second call.
   *
   * Note that disable(module) only removes that name: while '*' is on
   * everything logs, so turn the wildcard off with a bare disable() first.
   */
  Drupal.Daedalus.logging = {
    enable: (module = ALL) => {
      enabledModules.add(module);
      persist();
      return status();
    },
    disable: (module = ALL) => {
      if (module === ALL) {
        enabledModules.clear();
      } else {
        enabledModules.delete(module);
      }
      persist();
      return status();
    },
    status,
  };
})(Drupal);
