/**
 * The Selector tool: object-first selection, the default tool.
 *
 * Part of the app bundle: initSelectorTool() runs after the tool manager
 * boots, so the manager reads below are ordered by code, not by script
 * delivery.
 */
export const initSelectorTool = () => {
  class SelectorToolPlugin extends Drupal.Daedalus.ToolPluginBase {
    id = 'selector';
  }

  Drupal.Daedalus.ToolManager.registerPlugin(new SelectorToolPlugin());
};
