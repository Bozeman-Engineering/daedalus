/**
 * @file
 * The container outlines toggle.
 *
 * The preference is one attribute on the html element; CSS matches every
 * container under it. Nothing walks the DOM, so subtree swaps (an AJAX
 * rebuild, a mutation response replacing a container) arrive already
 * outlined — there is no per-element class to re-apply, and therefore no
 * window in which fresh markup is missing it.
 *
 * The preference is client-only and per-browser, so it lives in localStorage
 * rather than in user data (see daedalus_ui's EditorSettings).
 */
((Drupal, once) => {
  const STORAGE_KEY = 'daedalus_layout.container_outlines';

  // Containers are invisible without it, so a first visit gets outlines.
  const enabled = () => localStorage.getItem(STORAGE_KEY) !== 'false';

  const apply = (on) => {
    document.documentElement.toggleAttribute('data-container-outlines', on);
  };

  // The preference governs the canvas whether or not the settings panel has
  // rendered its checkbox yet.
  apply(enabled());

  Drupal.behaviors.DaedalusLayoutToggleContainerOutlines = {
    attach(context) {
      once(
        'DaedalusLayoutToggleContainerOutlines',
        '#daedalus-layout-toggle-container-outlines input',
        context,
      ).forEach((toggle) => {
        toggle.checked = enabled();
        toggle.addEventListener('change', () => {
          localStorage.setItem(STORAGE_KEY, toggle.checked);
          apply(toggle.checked);
        });
      });
    },
  };
})(Drupal, once);
