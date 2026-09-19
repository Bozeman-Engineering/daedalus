(($, Drupal, once) => {
  /**
   * Close control for sidebar panels.
   *
   * Any element carrying [data-sidebar-close] closes the active sidebar when
   * clicked. Lets a panel own its own close affordance (e.g. an X in the AI
   * chat header) instead of relying on its launcher button, which may be hidden
   * while the panel is open.
   *
   * @type {{attach(*, *): void}}
   */
  Drupal.behaviors.NpSidebarClose = {
    attach(context, settings) {
      once('daedalus-ui-sidebar-close', '[data-sidebar-close]', context).forEach(
        (element) => {
          element.addEventListener('click', () => {
            // Close the panel this control lives in.
            const panel = element.closest('[data-sidebar-type]');
            if (!panel) {
              return;
            }
            Drupal.Daedalus.SidebarManager.closeSidebar(panel.id).catch(
              (error) => {
                // Validation errors are expected and already shown to the user.
                if (
                  error !==
                  Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED
                ) {
                  console.error('Cannot close sidebar:', error);
                }
              },
            );
          });
        },
      );
    },
  };
})(jQuery, Drupal, once);
