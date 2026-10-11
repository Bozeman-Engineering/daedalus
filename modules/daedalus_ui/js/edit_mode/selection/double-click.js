/**
 * The ONE definition of a double-click in Edit Mode: the even OS click
 * count on a `click` event.
 *
 * Why not `dblclick`: browsers fire `dblclick` only on the click whose OS
 * click count is exactly 2. macOS keeps counting fast stationary clicks
 * past that (3, 4, 5...) until the pointer moves past the slop or the
 * double-click interval lapses, so a second double-click at one spot
 * arrives as clicks 3 and 4 and no `dblclick` ever fires; drilling
 * section, container, leaf at one spot stalls after the first pair.
 * Every `click` carries the count as `event.detail`, and every even count
 * closes a pair, so the pair is read off the click itself: counts 2, 4,
 * 6... are each a double-click, however fast and however stationary. The
 * count honours the user's own double-click speed and slop (the OS
 * setting) and is the same on every platform; no timer, no distance
 * check, no state.
 *
 * Why even and not "2 or more": a triple-click (count 3) is a single click
 * at the new depth, not a second drill, or every fast pair would drill
 * twice. A count of 0 (a synthetic or keyboard-generated click) or a
 * missing `detail` is a single click too.
 */

/**
 * Whether the click closes a double-click pair.
 *
 * @param {{detail?: number}} event
 *   The click event, or any object carrying `detail` as the OS click count.
 *
 * @return {boolean}
 *   True when `detail` is an even integer of 2 or more.
 */
export const isDoubleClick = (event) => {
  const count = event?.detail;
  return Number.isInteger(count) && count >= 2 && count % 2 === 0;
};
