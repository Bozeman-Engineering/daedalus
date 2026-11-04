(($, Drupal, once) => {
  /**
   * Surfaces a refused toggle.
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
   * Sidebar toggle buttons.
   *
   * Any element carrying [data-sidebar-button-for="#panel_id"] toggles that
   * panel open/closed through the sidebar manager, regardless of which side or
   * slot the panel occupies.
   */
  Drupal.behaviors.NpSidebarButtons = {
    attach(context) {
      once(
        'daedalus-ui-sidebar-buttons',
        '[data-sidebar-button-for]',
        context,
      ).forEach((button) => {
        button.addEventListener('click', (e) => {
          const sidebar = document.querySelector(
            e.currentTarget.dataset.sidebarButtonFor,
          );
          if (!sidebar) {
            return;
          }
          const id = sidebar.id;
          const manager = Drupal.Daedalus.SidebarManager;
          const action = sidebar.classList.contains('daedalus-ui-hidden')
            ? manager.openSidebar(id)
            : manager.closeSidebar(id);
          action.catch(surface);
        });
      });
    },
  };
})(jQuery, Drupal, once);
