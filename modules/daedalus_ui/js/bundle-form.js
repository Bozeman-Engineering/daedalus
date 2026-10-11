/**
 * @file
 * The Page builder vertical tab's summary line on a bundle form.
 *
 * States the bundle's page builder setup without opening the tab: "Off",
 * or "On" followed by one short sentence per checked control that carries
 * a data-daedalus-ui-summary attribute (the server renders the attribute,
 * translated, on the controls whose state is worth a word; see
 * BundleEditFormAlter::SUMMARY_ATTRIBUTE). The leading switch decides
 * between the two, so a bundle with the page builder off reads "Off" alone
 * whatever the hidden controls say.
 */

(function ($, Drupal) {
  Drupal.behaviors.daedalusUiBundleForm = {
    attach(context) {
      $(context)
        .find('[data-drupal-selector="edit-page-builder"]')
        .drupalSetSummary((details) => {
          const leading = details.querySelector(
            'input[name="daedalus_ui_enabled"]',
          );
          if (!leading || !leading.checked) {
            return Drupal.t('Off');
          }
          const parts = [Drupal.t('On')];
          details
            .querySelectorAll('input[data-daedalus-ui-summary]')
            .forEach((input) => {
              if (input.checked) {
                parts.push(Drupal.checkPlain(input.dataset.daedalusUiSummary));
              }
            });
          return `${parts.join('. ')}.`;
        });
    },
  };
})(jQuery, Drupal);
