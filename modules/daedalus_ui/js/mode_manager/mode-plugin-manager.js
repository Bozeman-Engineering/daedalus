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
   * @param plugin
   *  The mode plugin.
   */
  registerPlugin(plugin) {
    if (plugin instanceof modePluginBase.ModePluginBase) {
      this.plugins[plugin.constructor.name] = plugin;
      plugin.init();
    } else {
      console.error('Failed to register mode plugin: ', plugin);
    }
  }

  /**
   * Get plugin.
   *
   * @param mode
   *   The mode plugin id.
   * @return {modePluginBase|null}
   *   The mode plugin.
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

export default function () {
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.ModeManager = new ModePluginManager();
}
