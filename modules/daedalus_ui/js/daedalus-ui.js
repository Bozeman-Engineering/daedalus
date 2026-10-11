(($, Drupal, once) => {
  // Namespace and utilities only. The managers, facades, and plugins all
  // boot inside the edit-mode app bundle (edit_mode/edit-mode-app.js), the
  // one place with a guaranteed initialization order; the behaviors below
  // read them at interaction time, long after boot.
  Drupal.Daedalus = Drupal.Daedalus || {};

  /**
   * Records the page's mode.
   *
   * The mode cookie is scoped to the edited page, the same path the server
   * plants it at (InitialMode::setResponseCookies, ModeState), so one
   * page's mode never bleeds onto another and a server-planted value is
   * overwritten rather than outranked. Any site-wide value is swept first so
   * it cannot linger under the page's own.
   *
   * @param {string} mode
   *   The mode plugin id, or 'none'.
   */
  const setModeCookie = (mode) => {
    Drupal.Daedalus.deleteCookieValue('navigationMode');
    Drupal.Daedalus.setCookieValue('navigationMode', mode, {
      path: window.location.pathname,
    });
  };

  /**
   * Daedalus UI Edit Mode.
   */
  Drupal.behaviors.DaedalusUiModes = {
    attach: (context, settings) => {
      once(
        'DaedalusUiModeButton',
        '.daedalus-ui-mode-button',
        context,
      ).forEach((modeButton) => {
        // Listen for mode changes.
        modeButton.addEventListener('click', (e) => {
          e.preventDefault();
          if (!Drupal.Daedalus.ModeManager) {
            console.error(
              'The Daedalus edit-mode app has not loaded; the mode cannot be toggled.',
            );
            return;
          }
          const mode = Drupal.Daedalus.ModeManager.getPlugin(
            e.currentTarget.dataset.mode,
          );
          const navigationMode =
            Drupal.Daedalus.getCookieValue('navigationMode');
          if (mode.id === navigationMode) {
            // The already enabled mode was clicked, lets disable it.
            Drupal.behaviors.DaedalusUiModes.DisableMode(mode);
          } else if (navigationMode && navigationMode !== 'none') {
            // Disable the last mode.
            const lastMode =
              Drupal.Daedalus.ModeManager.getPlugin(navigationMode);
            Drupal.behaviors.DaedalusUiModes.DisableMode(lastMode)
              .then((response, status) => {
                Drupal.behaviors.DaedalusUiModes.EnableMode(mode);
              })
              .catch((error) => {
                console.error(
                  `An error occurred while trying to disable ${
                    navigationMode
                  }:`,
                  error,
                );
              });
          } else {
            // Enable the mode.
            Drupal.behaviors.DaedalusUiModes.EnableMode(mode);
          }
        });
      });
    },
    EnableMode: (mode) => {
      // The mode button renders both in core's top bar (the entry) and the
      // mode's own top bar (the exit) — keep every instance's state in sync.
      document
        .querySelectorAll(`[data-mode="${mode.id}"]`)
        .forEach((button) => button.classList.add('active'));

      setModeCookie(mode.id);

      // One html attribute drives all mode-dependent chrome (the core
      // navigation sidebar and top bar hide, the mode's top bar shows) — the
      // same attribute the server stamps from the cookie, so the client
      // toggle and a cookie-rendered load converge on identical state. The
      // hidden core sidebar stops displacing, so recalculate.
      document.documentElement.setAttribute(
        'data-daedalus-ui-mode',
        mode.id,
      );
      Drupal.displace?.(true);

      mode.enable();
      window.setMode?.('edit');
    },
    DisableMode: (mode) => {
      document
        .querySelectorAll(`[data-mode="${mode.id}"]`)
        .forEach((button) => button.classList.remove('active'));

      setModeCookie('none');

      document.documentElement.removeAttribute('data-daedalus-ui-mode');
      Drupal.displace?.(true);

      const disabled = mode.disable();
      window.setMode?.(null);
      return disabled;
    },
  };
})(jQuery, Drupal, once);
