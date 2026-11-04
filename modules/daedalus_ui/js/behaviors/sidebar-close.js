(($, Drupal, once) => {
  /**
   * Surfaces a refused close.
   *
   * A close the panel blocked on purpose (a validation error it already
   * showed the user) is expected and stays quiet.
   *
   * @param {*} error
   *   The sidebar manager's rejection.
   */
  const surface = (error) => {
    if (error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED) {
      Drupal.throwError(error);
    }
  };

  /**
   * Close control for sidebar panels.
   *
   * Any element carrying [data-sidebar-close] closes the active sidebar when
   * clicked. Lets a panel own its own close affordance (e.g. an X in the AI
   * chat header) instead of relying on its launcher button, which may be hidden
   * while the panel is open.
   */
  Drupal.behaviors.NpSidebarClose = {
    attach(context) {
      once(
        'daedalus-ui-sidebar-close',
        '[data-sidebar-close]',
        context,
      ).forEach((element) => {
        element.addEventListener('click', () => {
          // Close the panel this control lives in.
          const panel = element.closest('[data-sidebar-type]');
          if (!panel) {
            return;
          }
          Drupal.Daedalus.SidebarManager.closeSidebar(panel.id).catch(surface);
        });
      });
    },
  };
})(jQuery, Drupal, once);
