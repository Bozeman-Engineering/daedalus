/**
 * @file
 * Registers the view_mode context provider for Daedalus AI.
 *
 * Reads the current Layout Builder view mode from Daedalus and makes
 * it available to the AI agent on every chat request.
 */

(($, Drupal) => {
  /**
   * Registers the view_mode context provider.
   */
  Drupal.behaviors.daedalusAiLayoutContext = {
    attach(context) {
      if (context !== document) {
        return;
      }
      Drupal.Daedalus.AiContext.register('view_mode', () => {
        const editPlugin =
          Drupal.Daedalus?.ModeManager?.getPlugin('edit');
        if (!editPlugin) {
          return null;
        }
        // No main entity wrapper on this page (a route with no canvas):
        // no view mode to report, as top-bar.js guards the same read.
        const info = editPlugin.getMainEntityInfo();
        return info ? info.viewMode : null;
      });
    },
  };
})(jQuery, Drupal);
