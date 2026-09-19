import { verbsForSet, runVerbOn } from './verbs';

/**
 * Adapts the verb registry into menu items, the ONE verbs→items wire
 * (a menu is a third face of the registry, beside the floating bar
 * and the hotkeys, never a parallel action list). The page-elements tree
 * adapter is its one consumer: the menu is panel-only, and a canvas
 * adapter would reuse it as-is.
 *
 * verbsForSet already filters to verbs applying to the whole set, so no
 * item here is ever disabled. Label and run mirror the floating bar
 * exactly: the menuLabel refinement names a single selection's target,
 * a real set keeps the static label and runs the verb's multi path
 * (runMany's atomic batch when it has one).
 *
 * @param {string[]} keys
 *   The selection set's selectable keys, captured at open time.
 * @param {?function(object): boolean} filter
 *   Optional refinement over the set's verbs (the chip's chevron passes
 *   `(verb) => !verb.tab` so face verbs never repeat in the menu).
 *
 * @return {object[]}
 *   Items in the menu grammar (js/menu/menu-state.js).
 */
export const verbMenuItems = (keys, filter = null) =>
  verbsForSet(keys)
    .filter((verb) => !filter || filter(verb))
    .map((verb) => ({
      label:
        verb.menuLabel && keys.length === 1
          ? verb.menuLabel(keys[0])
          : verb.label,
      icon: verb.icon,
      run: () => runVerbOn(verb, keys),
    }));
