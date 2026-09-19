import * as toolPluginBase from './tool-plugin-base.js';

/**
 * Tool plugin manager.
 *
 * Tool plugins are used to handle enabling and disabling tools when their
 * Daedalus UI icon is clicked.
 */
class ToolPluginManager {
  constructor() {
    this.plugins = {};
    this.waiters = {};
  }

  /**
   * Register plugin.
   *
   * @param plugin
   *  The tool plugin.
   */
  registerPlugin(plugin) {
    if (plugin instanceof toolPluginBase.ToolPluginBase) {
      this.plugins[plugin.constructor.name] = plugin;
      plugin.init();
      (this.waiters[plugin.id] ?? []).forEach((waiter) =>
        waiter.resolve(plugin),
      );
      delete this.waiters[plugin.id];
    } else {
      console.error('Failed to register tool plugin: ', plugin);
    }
  }

  /**
   * Has plugin.
   *
   * @param tool
   *   The tool plugin id.
   * @return {boolean}
   *   Whether a plugin with that id is registered.
   */
  hasPlugin = (tool) => {
    return Object.values(this.plugins).some((plugin) => plugin.id === tool);
  };

  /**
   * Resolves with a tool plugin once it is registered.
   *
   * Tool scripts are delivered dynamically (big_pipe placeholders, AJAX
   * add_js), so a tool can legitimately register after code that needs it is
   * already running. Waiting on registration instead of reading the registry
   * at one instant is what makes tool activation delivery-order-proof.
   *
   * @param tool
   *   The tool plugin id.
   * @param timeoutMs
   *   How long to wait before rejecting: a tool that never registers (stale
   *   cookie or settings naming an uninstalled tool) must not leave the
   *   caller hanging forever.
   * @return {Promise<ToolPluginBase>}
   */
  whenRegistered = (tool, timeoutMs = 4000) => {
    return new Promise((resolve, reject) => {
      if (this.hasPlugin(tool)) {
        resolve(this.getPlugin(tool));
        return;
      }
      const waiter = { resolve };
      (this.waiters[tool] ??= []).push(waiter);
      setTimeout(() => {
        const pending = this.waiters[tool] ?? [];
        const index = pending.indexOf(waiter);
        if (index !== -1) {
          pending.splice(index, 1);
          reject(
            new Error(
              `The "${tool}" tool did not register within ${timeoutMs}ms.`,
            ),
          );
        }
      }, timeoutMs);
    });
  };

  /**
   * Get plugin.
   *
   * @param tool
   *   The tool plugin id.
   * @return {ToolPluginBase}
   *   The tool plugin.
   */
  getPlugin = (tool) => {
    let foundPlugin = null;
    Object.values(this.plugins).forEach((plugin) => {
      if (plugin.id === tool) {
        foundPlugin = plugin;
      }
    });
    if (foundPlugin) {
      return foundPlugin;
    }
    throw new Error(`Failed find plugin: ${tool}`);
  };
}

export default function () {
  Drupal.Daedalus = Drupal.Daedalus || {};
  Drupal.Daedalus.ToolManager = new ToolPluginManager();
}
