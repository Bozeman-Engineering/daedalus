/**
 * The top bar menu's item list, pure: what the Daedalus trigger hands the
 * menu primitive (js/menu/menu-state.js grammar), composed from the facts
 * the bar reads at open time and the actions the behavior supplies.
 *
 * The order is the bar's one menu, top to bottom, a row absent when its
 * fact says it does not apply:
 * 1. Panels ▸, one checked row per panel of the mode (the roster
 *    drupalSettings.daedalusUi.topBar.panels delivers, toggle button or
 *    not: a panel that takes no bar slot is still a panel), hint the
 *    user's binding for `sidebar.<id>` spelled the way the Settings
 *    panel's Hotkeys list spells it, checked while open at open time.
 * 2. Open preview window, while a preview is attached to the page.
 * 3. The root's `document` verbs (the group exists only while non-empty).
 * 4. Refresh the editor.
 * 5. Discard changes… (danger; the existing confirm modal is the one
 *    confirm, so no in-menu two-step).
 * 6. Editing chrome ▸: Everything / Minimal / Clean as checked rows.
 * 7. Keyboard shortcuts.
 * 8. Daedalus settings, only when the server delivered the door (the user
 *    may walk through it).
 * 9. Exit Edit Mode.
 * Separators stand between the groups; the primitive would trim a leading
 * or trailing one, but the list this returns is the list the menu gets,
 * so none is relied on.
 *
 * Every `checked` is read HERE, at composition time: the menu is
 * transient (built on every open) and the facts live where their owners
 * keep them (the panels' DOM open state, the html density stamp), so
 * nothing stores them twice. The document items pass through untouched:
 * they are already items (verb-menu-items.js, the one verbs-to-items
 * wire). Drupal.t labels the rows, so a test stubs globalThis.Drupal.
 */

/**
 * The density rows, in the ladder's order, worded as the Settings select
 * words them (literal strings, so Drupal's translation extraction sees
 * them).
 */
const DENSITIES = [
  ['full', () => Drupal.t('Everything')],
  ['minimal', () => Drupal.t('Minimal')],
  ['clean', () => Drupal.t('Clean')],
];

/**
 * A binding string as the Hotkeys list shows it (hotkey-settings.js's
 * displayText, minus the unbound dash: an unbound panel carries no hint).
 *
 * @param {?string} key
 *   The binding string ('v', 'mod+e'), empty or absent when unbound.
 *
 * @return {?string}
 *   The hint, or null for no hint.
 */
const hintFor = (key) => (key ? key.toUpperCase() : null);

/**
 * Composes the menu's items.
 *
 * @param {object} facts
 *   What the bar read at open time.
 * @param {{id: string, label: string, side: string}[]} facts.panels
 *   Every panel of the mode, in the bar's order.
 * @param {string[]} facts.openPanelIds
 *   The ids of the panels open right now.
 * @param {Object<string, {key?: string}>} facts.hotKeys
 *   The binding map (drupalSettings.daedalusUi.hotKeys), keyed by binding
 *   id; the panel rows read `sidebar.<id>`.
 * @param {boolean} facts.previewAvailable
 *   Whether a preview window can open on this page.
 * @param {object[]} facts.documentItems
 *   The root's document verbs as menu items, possibly empty.
 * @param {?string} facts.settingsUrl
 *   The Daedalus settings door, or null when the user may not reach it.
 * @param {string} facts.density
 *   The current editing chrome density (full | minimal | clean).
 * @param {object} actions
 *   What the rows do, supplied by the behavior.
 * @param {function(string, boolean): void} actions.togglePanel
 *   Called with the panel id and whether the row is opening (true) or
 *   closing (false) it.
 * @param {function(): void} actions.openPreview
 * @param {function(): void} actions.refresh
 * @param {function(): void} actions.discard
 * @param {function(string): void} actions.setDensity
 *   Called with the density value picked.
 * @param {function(): void} actions.keyboardShortcuts
 * @param {function(string): void} actions.openSettings
 *   Called with the settings URL.
 * @param {function(): void} actions.exit
 *
 * @return {object[]}
 *   Items in the menu grammar.
 */
export const topBarMenuItems = (
  {
    panels,
    openPanelIds,
    hotKeys,
    previewAvailable,
    documentItems,
    settingsUrl,
    density,
  },
  actions,
) => {
  const open = new Set(openPanelIds);
  const items = [];

  if (panels.length > 0) {
    items.push({
      label: Drupal.t('Panels'),
      items: panels.map((panel) => {
        const checked = open.has(panel.id);
        return {
          label: panel.label,
          hint: hintFor(hotKeys?.[`sidebar.${panel.id}`]?.key),
          checked,
          run: () => actions.togglePanel(panel.id, !checked),
        };
      }),
    });
  }
  if (previewAvailable) {
    items.push({
      label: Drupal.t('Open preview window'),
      run: actions.openPreview,
    });
  }
  if (documentItems.length > 0) {
    if (items.length > 0) {
      items.push({ separator: true });
    }
    items.push(...documentItems);
  }
  if (items.length > 0) {
    items.push({ separator: true });
  }
  items.push(
    { label: Drupal.t('Refresh the editor'), run: actions.refresh },
    {
      label: Drupal.t('Discard changes…'),
      danger: true,
      run: actions.discard,
    },
    { separator: true },
    {
      label: Drupal.t('Editing chrome'),
      items: DENSITIES.map(([value, label]) => ({
        label: label(),
        checked: density === value,
        run: () => actions.setDensity(value),
      })),
    },
    {
      label: Drupal.t('Keyboard shortcuts'),
      run: actions.keyboardShortcuts,
    },
  );
  if (settingsUrl) {
    items.push({
      label: Drupal.t('Daedalus settings'),
      run: () => actions.openSettings(settingsUrl),
    });
  }
  items.push(
    { separator: true },
    { label: Drupal.t('Exit Edit Mode'), run: actions.exit },
  );
  return items;
};
