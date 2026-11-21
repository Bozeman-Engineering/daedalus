/**
 * @file
 * The hosted form's mid-edit text format switch.
 *
 * The form road's canvas editor (plugins/inline-editor.js) edits the page
 * element itself, seeded from the field's textarea. When the format
 * selector moves to a format with an editor, the textarea's value is about
 * to become live markup on the page, so it first passes the filter round
 * trip for that format (filter-xss.js); only the answer is written to the
 * textarea and the page element. A switch to a format with no editor
 * writes nothing to the page: the edit continues in the panel textarea.
 *
 * Pure of the DOM's globals: the handler in inline-editor.js hands in the
 * elements and the editor machinery, so the order of the steps is what
 * tests/js/format-switch-filter.test.mjs drives.
 */

/**
 * Moves an open form-road edit onto another text format.
 *
 * The filter runs before any teardown, so a refused or failed request
 * rejects with the previous format's editor still standing.
 *
 * @param {object} options
 *   The switch.
 * @param {HTMLTextAreaElement} options.textarea
 *   The field's textarea: the value store the mount seeds from.
 * @param {Element} options.pageElement
 *   The page element the canvas editor rides.
 * @param {string} options.format
 *   The format switched to.
 * @param {?string} options.previousFormat
 *   The format switched from.
 * @param {?object} options.editorConfig
 *   The target format's editor settings, or a falsy value when it has no
 *   editor.
 * @param {function(): ?string} options.liveData
 *   The live canvas editor's data, or null when none stands.
 * @param {function(string, string, ?string): Promise<string>} options.filter
 *   The filter round trip (filterXss).
 * @param {function(): Promise} options.detach
 *   Destroys the live canvas editor, if any.
 * @param {function(object): *} options.attach
 *   Attaches a canvas editor with the given settings to the page element.
 * @param {function(): void} options.saveMarkup
 *   Saves the page element's filtered markup when the edit has not yet.
 * @param {function(): boolean} options.active
 *   Whether the edit that started the switch is still the open one.
 *
 * @return {Promise<void>}
 *   Resolves when the switch has landed (or was dropped because the edit
 *   ended meanwhile).
 */
export function switchFormat({
  textarea,
  pageElement,
  format,
  previousFormat,
  editorConfig,
  liveData,
  filter,
  detach,
  attach,
  saveMarkup,
  active,
}) {
  // Preserve the user's input across the switch.
  const data = liveData();
  if (data !== null) {
    textarea.value = data;
  }

  if (!editorConfig) {
    // The new format has no editor: continue the edit in the panel
    // textarea. The canvas keeps what it shows until blur commits and
    // re-renders it.
    return detach().then(() => {
      pageElement.classList.remove('daedalus-edit-inline-editing');
      textarea.classList.remove('daedalus-edit-hidden');
      textarea.focus();
    });
  }

  return filter(textarea.value, format, previousFormat).then((filtered) => {
    if (!active()) {
      // The edit ended while the request was in flight; blur or cancel
      // already settled the surface.
      return undefined;
    }
    return detach().then(() => {
      // Move the edit (back) onto the canvas with the new format.
      textarea.classList.add('daedalus-edit-hidden');
      // When the edit started on the no-editor tier the filtered markup
      // was never saved: the canvas is untouched until now.
      saveMarkup();
      textarea.value = filtered;
      pageElement.innerHTML = filtered;
      pageElement.classList.add('daedalus-edit-inline-editing');
      return attach(editorConfig);
    });
  });
}
