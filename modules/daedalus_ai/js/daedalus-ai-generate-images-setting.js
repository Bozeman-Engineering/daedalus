/**
 * @file
 * Persists the per-user "Generate images" toggle from the Edit Mode settings
 * panel via the daedalus_ui unified editor-preference helper.
 */

((Drupal, once) => {
  Drupal.behaviors.daedalusAiGenerateImages = {
    attach(context) {
      once(
        'daedalus-ai-generate-images',
        '#daedalus-ai-generate-images',
        context,
      ).forEach((checkbox) => {
        checkbox.addEventListener('change', (event) => {
          const value = event.target.checked ? '1' : '0';
          Drupal.Daedalus.saveSetting(
            'daedalus_ai',
            'generate_images',
            value,
          );
        });
      });
    },
  };
})(Drupal, once);
