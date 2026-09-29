/**
 * The Show menu's item list, pure: what the kebab hands the menu
 * primitive, composed from the two things the menu shows.
 *
 * The tree's view toggles come first (one checked item per view provider,
 * checked from the view slice, running the visibility flip the kebab
 * supplies), then the registered Show-menu items (view-registry.js
 * registerShowMenuItem: another module's entry that belongs in this menu,
 * e.g. daedalus_explode's Explode), behind one separator. Either side
 * empty means no separator: the menu primitive would trim a leading or
 * trailing one anyway, but the list this returns is the list the menu
 * gets, so the trim is not relied on.
 *
 * A registered item's `checked` is a function, read HERE, at composition
 * time: the menu is transient (built on every open) and the item's state
 * lives wherever its owner keeps it (the Explode panel's DOM open state),
 * so nothing stores it twice. `run` passes through untouched; `icon` is
 * inline SVG markup in the menu grammar and passes through as well.
 *
 * No imports: the registry composes through it, and tests drive it bare.
 */

/**
 * Returns `items` with `item` in place of the entry sharing its id, or
 * appended when none does. Registration order is the menu order, so a
 * replacement keeps the earlier entry's position.
 *
 * @param {Array<object>} items
 *   The registered items, each with a string id.
 * @param {object} item
 *   The item to register.
 *
 * @return {Array<object>}
 *   A new list; the input is not mutated.
 */
export const withShowMenuItem = (items, item) => {
  const index = items.findIndex((candidate) => candidate.id === item.id);
  if (index === -1) {
    return [...items, item];
  }
  return items.map((candidate, at) => (at === index ? item : candidate));
};

/**
 * Composes the menu's item list.
 *
 * @param {Array<{id: string, label: string}>} toggles
 *   The view providers' Show-menu toggles (view-registry.js viewToggles).
 * @param {Object<string, boolean>} viewVisibility
 *   The view slice's `views` map: toggle state by toggle id.
 * @param {function({id: string, visible: boolean})} setVisibility
 *   What a toggle's activation does: flip its axis.
 * @param {Array<{id: string, label: string, icon?: string,
 *   checked: function(): boolean, run: function(): void}>} items
 *   The registered Show-menu items (view-registry.js showMenuItems).
 *
 * @return {Array<object>}
 *   Items in the menu primitive's grammar (js/menu/menu-state.js).
 */
export const showMenuEntries = (toggles, viewVisibility, setVisibility, items) => {
  const toggleEntries = toggles.map((toggle) => ({
    label: toggle.label,
    checked: viewVisibility[toggle.id] === true,
    run: () =>
      setVisibility({
        id: toggle.id,
        visible: viewVisibility[toggle.id] !== true,
      }),
  }));
  const itemEntries = items.map((item) => ({
    label: item.label,
    ...(item.icon ? { icon: item.icon } : {}),
    checked: item.checked() === true,
    run: item.run,
  }));
  if (toggleEntries.length === 0 || itemEntries.length === 0) {
    return [...toggleEntries, ...itemEntries];
  }
  return [...toggleEntries, { separator: true }, ...itemEntries];
};
