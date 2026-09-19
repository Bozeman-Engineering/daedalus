/**
 * The widget wrapper's class list: base, per-widget modifier, invalid flag.
 *
 * @param {string} name
 *   The widget's modifier name (e.g. 'alignment', 'spacing').
 * @param {boolean} invalid
 *   Whether the last commit was rejected (useGesture's invalid state).
 *
 * @return {string}
 */
export const widgetClasses = (name, invalid) =>
  [
    'daedalus-stylish-inspector__widget',
    `daedalus-stylish-inspector__widget--${name}`,
    invalid ? 'daedalus-stylish-inspector__widget--invalid' : '',
  ]
    .filter(Boolean)
    .join(' ');
