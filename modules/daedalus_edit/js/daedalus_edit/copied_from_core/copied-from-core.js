/**
 * COPIED FROM ckeditor5.js: Set an id to a data-attribute for registering this element instance.
 *
 * @param {Element} element
 *   An element that should receive unique ID.
 *
 * @return {string}
 *   The id to use for this element.
 */
export const setElementId = (element) => {
  const id = Math.random().toString().slice(2, 9);
  element.setAttribute('data-ckeditor5-id', id);

  return id;
};

/**
 * COPIED FROM ckeditor5.js: Select CKEditor 5 plugin classes to include.
 *
 * Found in the CKEditor 5 global JavaScript object as {package.Class}.
 *
 * @param {Array} plugins
 *  List of package and Class name of plugins
 *
 * @return {Array}
 *   List of JavaScript Classes to add in the extraPlugins property of config.
 */
export function selectPlugins(plugins) {
  return plugins.map((pluginDefinition) => {
    const [build, name] = pluginDefinition.split('.');
    if (CKEditor5[build] && CKEditor5[build][name]) {
      return CKEditor5[build][name];
    }

    // eslint-disable-next-line no-console
    console.warn(`Failed to load ${build} - ${name}`);
    return null;
  });
}

/**
 * COPIED FROM ckeditor5.js: Converts a string representing regexp to a RegExp object.
 *
 * @param {Object} config
 *   An object containing configuration.
 * @param {string} config.pattern
 *   The regexp pattern that is used to create the RegExp object.
 *
 * @return {RegExp}
 *   Regexp object built from the string regexp.
 */
function buildRegexp(config) {
  const { pattern } = config.regexp;

  const main = pattern.match(/\/(.+)\/.*/)[1];
  const options = pattern.match(/\/.+\/(.*)/)[1];

  return new RegExp(main, options);
}

function findFunc(scope, name) {
  if (!scope) {
    return null;
  }
  const parts = name.includes('.') ? name.split('.') : name;

  if (parts.length > 1) {
    return findFunc(scope[parts.shift()], parts);
  }
  return typeof scope[parts[0]] === 'function' ? scope[parts[0]] : null;
}

/**
 * Transform a config key in a callback function or execute the function
 * to dynamically build the configuration entry.
 *
 * @param {object} config
 *  The plugin configuration object.
 *
 * @return {null|function|*}
 *  Resulting configuration value.
 */
function buildFunc(config) {
  const { func } = config;
  // Assuming a global object.
  const fn = findFunc(window, func.name);
  if (typeof fn === 'function') {
    const result = func.invoke ? fn(...func.args) : fn;
    return result;
  }
  return null;
}

/**
 * COPIED FROM ckeditor5.js: Casts configuration items to correct types.
 *
 * @param {Object} config
 *   The config object.
 * @return {Object}
 *   The config object with items transformed to correct type.
 */
export function processConfig(config) {
  /**
   * Processes an array in config recursively.
   *
   * @param {Array} config
   *   An array that should be processed recursively.
   * @return {Array}
   *   An array that has been processed recursively.
   */
  function processArray(config) {
    return config.map((item) => {
      if (typeof item === 'object') {
        return processConfig(item);
      }

      return item;
    });
  }

  return Object.entries(config).reduce((processed, [key, value]) => {
    if (typeof value === 'object') {
      // Check for null values.
      if (!value) {
        return processed;
      }
      if (value.hasOwnProperty('func')) {
        processed[key] = buildFunc(value);
      } else if (value.hasOwnProperty('regexp')) {
        processed[key] = buildRegexp(value);
      } else if (Array.isArray(value)) {
        processed[key] = processArray(value);
      } else {
        processed[key] = processConfig(value);
      }
    } else {
      processed[key] = value;
    }

    return processed;
  }, {});
}
