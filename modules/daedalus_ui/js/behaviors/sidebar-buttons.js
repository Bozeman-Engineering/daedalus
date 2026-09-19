(($, Drupal, once) => {
  /**
   * Sidebar toggle buttons.
   *
   * Any element carrying [data-sidebar-button-for="#panel_id"] toggles that
   * panel open/closed through the sidebar manager, regardless of which side or
   * slot the panel occupies.
   *
   * @type {{attach(*, *): void}}
   */
  Drupal.behaviors.NpSidebarButtons = {
    attach(context, settings) {
      once('daedalus-ui-sidebar-buttons', '[data-sidebar-button-for]', context).forEach(
        (button) => {
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
            action.catch((error) => {
              // Sidebar couldn't be toggled (e.g. validation error preventing
              // close). Validation errors are expected and already shown to user.
              if (
                error !== Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED
              ) {
                console.error('Cannot toggle sidebar:', error);
              }
            });
          });
        },
      );
    },
  };
})(jQuery, Drupal, once);
