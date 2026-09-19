/**
 * Mode plugin base.
 *
 * A base class for Daedalus UI mode plugins.
 */
export class ModePluginBase {
  /**
   * Initialize the plugin.
   */
  init() {}

  /**
   * Enable the mode.
   *
   * @return {Promise}
   */
  enable() {
    const topBar = document.querySelector(`#${this.id}-top-bar`);
    if (topBar) {
      topBar.classList.remove('daedalus-ui-hidden');
    }
    return Promise.resolve();
  }

  /**
   * Disable the mode.
   *
   * @param modesDisabled
   *   False if we are just disabling the mode to switch to another mode.
   *   True if we are just exiting the current mode.
   * @return {Promise}
   */
  disable(modesDisabled = false) {
    const topBar = document.querySelector(`#${this.id}-top-bar`);
    if (topBar) {
      topBar.classList.add('daedalus-ui-hidden');
    }
    return Promise.resolve();
  }

  /**
   * Ajax.
   *
   * Wraps Drupal.ajax in order to pass along Daedalus UI state.
   *
   * @param settings
   *   Settings for Drupal.ajax.
   *
   * @return {Drupal.Ajax}
   *   An instance of Drupal.ajax with query parameters appended to the URL.
   */
  static ajax = (settings) => {
    settings.url = this.url(settings.url).toString();
    return Drupal.ajax(settings);
  };

  /**
   * Url.
   *
   * Add Daedalus UI state parameters.
   *
   * navigationMode: The current mode. This is normally used as a cookie, but we
   * also include it here because the current path of the page e.g. /node/1 may
   * have a navigationMode cookie set for it, but the JS path we are calling e.g.
   * /place/block does not.
   * _wrapper_format: drupal_ajax
   *
   * @param path
   *   The URL path segment.
   * @param params
   *   (optional) Query string parameters to include. Query string parameters in
   *   the path string are preserved as well.
   *
   * @return {string}
   *   The URL path.
   */
  static url = (path, params = {}) => {
    const url = new URL(window.location.origin + path);

    url.searchParams.set(
      'navigationMode',
      Drupal.Daedalus.getCookieValue('navigationMode') ?? 'none',
    );
    url.searchParams.set('_wrapper_format', 'drupal_ajax');
    Object.keys(params).forEach((key) => {
      if (params[key] !== undefined && params[key] !== null) {
        url.searchParams.set(key, params[key]);
      }
    });

    // Drupal.url() prepends the base path (and any language path prefix), so the
    // path passed to it must not already contain one. Callers normally pass a
    // base-path-less route path (e.g. /daedalus-layout/layout-options/...), but a
    // server-generated URL (Url::toString()) already includes the base path
    // (e.g. /web/daedalus-layout/edit/...). Strip a leading occurrence of whatever
    // Drupal.url() would prepend so it is never doubled (e.g. /web/web/...).
    const basePath = Drupal.url('');
    const relativePath = url.pathname.startsWith(basePath)
      ? url.pathname.slice(basePath.length)
      : url.pathname.slice(1);
    return Drupal.url(`${relativePath}${url.search}`);
  };

  static dialog = (settings) => {
    const ajaxConfig = {
      url: settings.url,
      event: 'click',
      progress: {
        type: 'fullscreen',
        message: settings.message ?? Drupal.t('Loading...'),
      },
      dialogType: 'dialog',
      dialog: {
        width: settings.width ?? 600,
        height: 'auto',
        target: 'layout-builder-modal',
        autoResize: true,
        modal: true,
      },
    };

    const ajax = Drupal.Daedalus.ModePluginBase.ajax(ajaxConfig);
    ajax.execute();
  };
}

Drupal.Daedalus = Drupal.Daedalus || {};
Drupal.Daedalus.ModePluginBase = ModePluginBase;
