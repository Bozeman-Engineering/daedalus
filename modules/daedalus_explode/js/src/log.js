/**
 * Daedalus Explode's client observability seam: the logger handle.
 *
 * The logger itself lives at the daedalus_ui spine
 * (`Drupal.Daedalus.logger`, library `daedalus_ui/logging`); this module
 * is the one place daedalus_explode grabs it, so every bundle module logs
 * through the same instance and the same module name. Nothing prints
 * until the console switches daedalus_explode on:
 *
 *   Drupal.Daedalus.logging.enable('daedalus_explode');
 *
 * The logger is grabbed at module load, per the spine's contract: the
 * instance is memoized there, `log` is closure-bound (destructuring is
 * safe) and `enabled` is read live inside log(), so a handle taken before
 * the console enables daedalus_explode starts printing the moment it does.
 * The globalThis guard covers the host that has no daedalus_ui logging
 * library: node (the tests/js suites import the pure modules directly),
 * which degrades to silence.
 */
const logger = globalThis.Drupal?.Daedalus?.logger?.('daedalus_explode') ?? {
  log: () => {},
  enabled: false,
};

/**
 * Logs one line: `[daedalus_explode:tag] message` plus optional data.
 */
export const { log } = logger;
