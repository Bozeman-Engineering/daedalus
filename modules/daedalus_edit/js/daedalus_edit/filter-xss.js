/**
 * @file
 * The text editor filter round trip of a mid-edit format switch.
 *
 * Port of core editor.js filterXssWhenSwitching, the one copy every canvas
 * editor uses: the hosted form's format selector (format-switch.js) and
 * the formatted text block's format picker (handed to it as `filterXss` on
 * the DaedalusEditQueue API).
 *
 * A value moving to another format's editor must not seed that editor with
 * markup the target format would never allow, so the server re-filters it
 * through core's editor/filter_xss/{format} route (access: the target
 * format's 'use' permission). The route answers with the filtered value,
 * or false when the target format needs no filtering (it restricts no
 * HTML), in which case the value passes through untouched. An editor that
 * guarantees safe markup itself (isXssSafe in its settings) skips the
 * round trip entirely, exactly core's behavior.
 */

/**
 * Filters a value for the editor of the format being switched to.
 *
 * @param {string} value
 *   The data to filter.
 * @param {string} format
 *   The target format id; it must have editor settings.
 * @param {?string} originalFormat
 *   The format the value currently has, for the server's rules when
 *   switching between formats.
 *
 * @return {Promise<string>}
 *   The value to seed the target format's editor with. Rejects when the
 *   route refuses or fails; nothing may be seeded then.
 */
export function filterXss(value, format, originalFormat) {
  if (drupalSettings.editor.formats[format].isXssSafe) {
    return Promise.resolve(value);
  }
  const body = new URLSearchParams({ value });
  if (originalFormat) {
    body.set('original_format_id', originalFormat);
  }
  return fetch(Drupal.url(`editor/filter_xss/${format}`), {
    method: 'POST',
    body,
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(
          `editor/filter_xss/${format} failed with status ${response.status}.`,
        );
      }
      return response.json();
    })
    .then((filtered) => (filtered === false ? value : filtered));
}
