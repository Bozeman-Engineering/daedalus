(($, Drupal, once) => {
  /**
   * Choose block tabs.
   *
   * @type {{attach: Drupal.behaviors.DaedalusLayoutChooseBlockTabs.attach}}
   */
  Drupal.behaviors.DaedalusLayoutChooseBlockTabs = {
    attach: (context, settings) => {
      once('DaedalusLayoutChooseBlockTabs', '.choose-block-tab', context).forEach(
        (tab) => {
          tab.onclick = (e) => {
            // Make no tab active.
            const noLongerActiveElements = [
              ...document.querySelectorAll('.tabbed-content'),
              ...document.querySelectorAll('.choose-block-tab'),
            ];
            noLongerActiveElements.forEach((tabbedContent) => {
              // The shared segmented-control modifier rides along on tabs; it
              // is absent from .tabbed-content, where removing it is a no-op.
              tabbedContent.classList.remove('active', 'daedalus-ui-segment--active');
            });
            // Activate the selected tab.
            e.target.classList.add('active', 'daedalus-ui-segment--active');
            document
              .getElementById(`${e.target.id}-content`)
              .classList.add('active');
          };
        },
      );
    },
  };

  let layoutBuilderBlocksFiltered = false;

  /**
   * Provides the ability to filter the block listing in "Add block" dialog.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach block filtering behavior to "Add block" dialog.
   */
  Drupal.behaviors.DaedalusLayoutBlockFilter = {
    attach(context) {
      const $categories = $('.js-layout-builder-categories', context);
      const $filterLinks = $categories.find('.js-layout-builder-block-link');

      /**
       * Filters the block list.
       *
       * @param {jQuery.Event} e
       *   The jQuery event for the keyup event that triggered the filter.
       */
      const filterBlockList = (e) => {
        const query = e.target.value.toLowerCase();

        /**
         * Shows or hides the block entry based on the query.
         *
         * @param {number} index
         *   The index in the loop, as provided by `jQuery.each`
         * @param {HTMLElement} link
         *   The link to add the block.
         */
        const toggleBlockEntry = (index, link) => {
          const $link = $(link);
          const textMatch =
            link.textContent.toLowerCase().indexOf(query) !== -1;
          // Checks if a category is currently hidden.
          // Toggles the category on if so.
          const category = link.closest('.js-layout-builder-category');
          const categoryHidden =
            category &&
            !(
              category.offsetWidth ||
              category.offsetHeight ||
              category.getClientRects().length
            );
          if (categoryHidden) {
            $(category).show();
          }
          // Toggle the li tag of the matching link.
          // $link.parent().toggle(textMatch);
          $link.toggle(textMatch);
        };

        // Filter if the length of the query is at least 2 characters.
        if (query.length >= 2) {
          // Attribute to note which categories are closed before opening all.
          $categories
            .find('.js-layout-builder-category:not([open])')
            .attr('remember-closed', '');

          // Open all categories so every block is available to filtering.
          $categories.find('.js-layout-builder-category').attr('open', '');
          // Toggle visibility of links based on query.
          $filterLinks.each(toggleBlockEntry);

          // Only display categories containing visible links.
          $categories
            .find(
              '.js-layout-builder-category:not(:has(.js-layout-builder-block-link:visible))',
            )
            .hide();

          Drupal.announce(
            Drupal.formatPlural(
              $categories.find('.js-layout-builder-block-link:visible').length,
              '1 block is available in the modified list.',
              '@count blocks are available in the modified list.',
            ),
          );
          layoutBuilderBlocksFiltered = true;
        } else if (layoutBuilderBlocksFiltered) {
          layoutBuilderBlocksFiltered = false;
          // Remove "open" attr from categories that were closed pre-filtering.
          $categories
            .find('.js-layout-builder-category[remember-closed]')
            .removeAttr('open')
            .removeAttr('remember-closed');
          // Show all categories since filter is turned off.
          $categories.find('.js-layout-builder-category').show();
          // Show all blocks since filter is turned off.
          $filterLinks.show();
          Drupal.announce(Drupal.t('All available blocks are listed.'));
        }
      };

      $(
        once('block-filter-text', 'input.js-layout-builder-filter', context),
      ).on('input', Drupal.debounce(filterBlockList, 200));
    },
  };
})(jQuery, Drupal, once);
