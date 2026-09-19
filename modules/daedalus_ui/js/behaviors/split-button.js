(($, Drupal, once) => {
  Drupal.behaviors.NpSplitButton = {
    attach(context) {
      once('NpSplitButton', '.daedalus-ui-split-button', context).forEach(
        (splitButton) => {
          const caret = splitButton.querySelector('.daedalus-ui-split-button__caret');
          const dropdown = splitButton.querySelector(
            '.daedalus-ui-split-button__dropdown',
          );

          caret.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close other open split-button dropdowns first.
            document
              .querySelectorAll('.daedalus-ui-split-button__dropdown--open')
              .forEach((openDropdown) => {
                if (openDropdown !== dropdown) {
                  openDropdown.classList.remove(
                    'daedalus-ui-split-button__dropdown--open',
                  );
                }
              });
            dropdown.classList.toggle('daedalus-ui-split-button__dropdown--open');
          });

          // Prevent clicks inside dropdown from closing it via document listener.
          dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
          });
        },
      );

      // Document click closes all open split-button dropdowns.
      once('NpSplitButtonDocument', 'body', context).forEach(() => {
        document.addEventListener('click', () => {
          document
            .querySelectorAll('.daedalus-ui-split-button__dropdown--open')
            .forEach((openDropdown) => {
              openDropdown.classList.remove('daedalus-ui-split-button__dropdown--open');
            });
        });
      });
    },
  };
})(jQuery, Drupal, once);
