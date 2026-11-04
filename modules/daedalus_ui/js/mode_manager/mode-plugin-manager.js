import * as modePluginBase from './mode-plugin-base.js';
import * as defaultModePlugin from './default-mode-plugin.js';

/**
 * Mode plugin manager.
 */
class ModePluginManager {
  constructor() {
    this.plugins = {};
  }

  /**
   * Register plugin.
   *
   * @param {ModePluginBase} plugin
   *   The mode plugin.
   */
  registerPlugin(plugin) {
    if (!(plugin instanceof modePluginBase.ModePluginBase)) {
      throw new TypeError('A mode plugin must extend ModePluginBase.');
    }
    this.plugins[plugin.constructor.name] = plugin;
    plugin.init();
  }

  /**
   * Get plugin.
   *
   * @param {string} mode
   *   The mode plugin id.
   * @return {ModePluginBase}
   *   The registered plugin, or a default plugin wearing the id.
   */
  getPlugin = (mode) => {
    let foundPlugin = null;
    Object.values(this.plugins).forEach((plugin) => {
      if (plugin.id === mode) {
        foundPlugin = plugin;
      }
    });
    if (foundPlugin) {
      return foundPlugin;
    }
    const defaultMode = new defaultModePlugin.DefaultModePlugin();
    defaultMode.id = mode;
    return defaultMode;
  };
}

export default function bootModeManager() {
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.ModeManager = new ModePluginManager();
}
