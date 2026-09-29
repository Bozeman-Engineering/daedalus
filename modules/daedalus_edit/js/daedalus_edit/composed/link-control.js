/**
 * @file
 * The link control shared by composed-element (blueprint) editors.
 *
 * A collapsed block whose bundle carries a link field renders an anchor
 * when linked (each block type's declaration owns where: the image block's
 * anchor IS the root, the text block nests it inside the root); the link is
 * edited through the shared Link widget verb (`daedalus_edit.link`) on the
 * selection chip's face — a popover holding one input with an entity
 * suggestion dropdown, opened by its toggle or Cmd+K (a typing-context
 * hotkey through the verb registry). This module owns the link's value
 * model, the widget, the suggestions, and the ONE verb registration; the
 * editor supplies the leaf callbacks (how to preview a uri on ITS markup,
 * where focus returns) and opts in by exposing its control as `link` on
 * the plugin instance — that property is the verb's applies gate.
 *
 * The value model, shared by every consumer:
 * - The input shows the link the way a person TYPES it: the snapshot's
 *   read-only `_displayable` enrichment (entity: uris as "Label (id)",
 *   internal: uris as the bare path — LinkFieldHandler serializes it on
 *   every item), and edits in the same user-entered grammar. Typed strings
 *   are sent RAW; the spine normalizes them to storage uris ("/about" →
 *   "internal:/about"), so a mid-preview href showing the raw string is
 *   cosmetic — the commit's markup swap prints the resolved url.
 * - Typing also feeds the suggestion dropdown: a debounced fan-out to
 *   core's entity autocomplete endpoint, one request per target type the
 *   field is configured to suggest (the drupalSettings contract
 *   `daedalusEdit.linkAutocomplete`, keyed by field config id — see
 *   daedalus_edit's LinkAutocomplete), results grouped under the type's label.
 *   Picking a suggestion writes the structural storage uri
 *   `entity:TYPE/ID` directly — no user-entered grammar involved.
 * - linkPreview is per-edit state on the leaf: the previewed value
 *   ({uri, title?, options?} or null), set once the user touches the
 *   input. While undefined, the link reads as the snapshot value, so an
 *   untouched link never diffs.
 * - A preview keeps the snapshot's title/options (clearing a link's uri
 *   and retyping it within one gesture must not silently drop them; the
 *   server clears options not included in an update). The spread also
 *   carries the snapshot's stale `_displayable` — deliberately tolerated,
 *   the spine strips it on write.
 *
 * Handed to registration callbacks as `linkControl` on the DaedalusEditQueue
 * API.
 *
 * @see ./verb-widgets.js
 * @see ./element-preview.js (the resolved-tree morph previews ride)
 * @see daedalus_edit/src/LinkAutocomplete.php (the endpoint contract)
 */

import {
  buildWidgetToggle,
  buildPopover,
  togglePopover,
  releaseWidget,
} from './verb-widgets.js';

/**
 * Phosphor's link glyph, the site's icon language (daedalus_ui
 * assets); the toggle sizes and colors it via the chip's icon class.
 *
 * @type {string}
 */
const LINK_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256" aria-hidden="true"><path d="M165.66,90.34a8,8,0,0,1,0,11.32l-64,64a8,8,0,0,1-11.32-11.32l64-64A8,8,0,0,1,165.66,90.34ZM215.6,40.4a56,56,0,0,0-79.2,0L106.34,70.45a8,8,0,0,0,11.32,11.32l30.06-30a40,40,0,0,1,56.57,56.56l-30.07,30.06a8,8,0,0,0,11.31,11.32L215.6,119.6a56,56,0,0,0,0-79.2ZM138.34,174.22l-30.06,30.06a40,40,0,1,1-56.56-56.57l30.05-30.05a8,8,0,0,0-11.32-11.32L40.4,136.4a56,56,0,0,0,79.2,79.2l30.06-30.07a8,8,0,0,0-11.32-11.31Z"></path></svg>';

/**
 * How long typing pauses before the suggestion fan-out fires.
 *
 * @type {number}
 */
const SUGGEST_DEBOUNCE_MS = 300;

/**
 * Unique-id counter for suggestion listboxes (aria wiring).
 *
 * @type {number}
 */
let listboxCount = 0;

/**
 * Extracts the entity id from a core autocomplete match value.
 *
 * The endpoint's wire format carries the id only inside the value string
 * ("Label (42)", quote-wrapped when the label holds commas/quotes); this is
 * the JS twin of core's
 * EntityAutocomplete::extractEntityIdFromAutocompleteInput(), applied ONCE
 * at the response seam so everything past it holds the id structurally.
 *
 * @param {string} value
 *   The match's value string.
 *
 * @return {?string}
 *   The entity id, or null when the value does not carry one.
 */
const extractEntityId = (value) => value.match(/.+\s\(([^)]+)\)/)?.[1] ?? null;

/**
 * A link value's displayable string, for the widget input.
 *
 * Serialized link items always carry `_displayable` (the spine emits it
 * unconditionally); null means unlinked.
 *
 * @param {?Object} link
 *   The link value.
 *
 * @return {string}
 *   The string the input shows.
 */
const displayable = (link) => link?._displayable ?? link?.uri ?? '';

/**
 * The autocomplete endpoints configured for one leaf's link field.
 *
 * The drupalSettings payload is keyed by field config id
 * (block_content.BUNDLE.FIELD_NAME); the bundle is the leaf's mirror
 * component's block_type. A field configured to suggest nothing is absent
 * from the payload.
 *
 * @param {EditableElement} EditableElement
 *   The element being edited.
 * @param {string} fieldName
 *   The link field's property name.
 *
 * @return {Array<{type: string, label: string, url: string}>}
 *   The endpoint descriptors, possibly empty.
 */
const endpointsFor = (EditableElement, fieldName) => {
  const { Blueprint } = Drupal.Daedalus;
  const component = Blueprint.componentForUuid(
    Blueprint.getBlueprint(),
    EditableElement.componentUuid,
  );
  if (!component?.block_type) {
    return [];
  }
  const id = `block_content.${component.block_type}.${fieldName}`;
  return drupalSettings.daedalusEdit?.linkAutocomplete?.[id] ?? [];
};

/**
 * The current edit, when its editor exposes a link control.
 *
 * The Link verb's gate: an editor opts into the shared verb by holding
 * its created control as `link` on the plugin instance.
 *
 * @return {?object}
 *   The BlueprintEditableElement, or null.
 */
const currentLinkEdit = () => {
  const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
  return current?.plugin?.link ? current : null;
};

/**
 * Creates a link control bound to one editor's leaf callbacks.
 *
 * @param {Object} options
 *   The editor's bindings:
 *   - {string} fieldName: the link field's property name in the mirror
 *     (e.g. 'field_text_url'); snapshot reads and the autocomplete
 *     endpoint lookup key off it.
 *   - {Function} applyLink: (EditableElement, uri) => void — previews the
 *     uri on the leaf's markup (wrap/unwrap the root, set href).
 *   - {Function} returnFocus: (EditableElement) => ?Element — the element
 *     focus returns to when the popover closes with focus in its input
 *     (Enter, a scroll, a press that dismissed it).
 *
 * @return {Object}
 *   The control: buildWidget, open, preview, value, write, refresh,
 *   reset.
 */
export function create({ fieldName, applyLink, returnFocus }) {
  const control = {
    /**
     * Builds the Link widget: the toggle, and the popover holding the
     * input and its suggestion dropdown.
     *
     * The verb's widget callback body: called by the frame's widget slot
     * while this editor's edit is open. The input edits the user-entered
     * link grammar and doubles as the suggestion combobox. Typing
     * previews the raw string on the leaf and (debounced) fans out one
     * request per configured endpoint; responses render as a grouped
     * listbox under the input. Arrows walk the options; Enter picks the
     * active option (writing entity:TYPE/ID), else confirms and closes;
     * a pick's mousedown keeps focus in the input. Escape is the
     * surface's: one press closes the popover, dropdown and all (the
     * list lives inside it), at document capture, so the session's
     * cancel never sees it. Tab is left alone: it bubbles to the
     * session, which commits and advances — "type the link, Tab, move
     * on".
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     * @param {Element} container
     *   The frame's widget slot container.
     * @param {Object} context
     *   - {boolean} showLabel: render the entry's text label (the
     *     frame's density ladder).
     *
     * @return {Function}
     *   The widget cleanup.
     */
    buildWidget(EditableElement, container, { showLabel = false } = {}) {
      buildWidgetToggle(EditableElement, container, {
        name: 'link',
        label: Drupal.t('Link'),
        icon: LINK_ICON,
        showLabel,
      });
      const popover = buildPopover(EditableElement, 'link', { returnFocus });
      popover.classList.add('daedalus-edit-link-popover');
      const endpoints = endpointsFor(EditableElement, fieldName);

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'daedalus-edit-link-input';
      input.placeholder = Drupal.t('Link: type a URL, a path, or a title');
      input.setAttribute('aria-label', Drupal.t('Link'));
      // Opening the popover moves focus in and selects (typing is its
      // whole point).
      input.setAttribute('data-daedalus-edit-popover-autofocus', '');
      input.value = displayable(control.value(EditableElement));

      const list = document.createElement('div');
      list.className = 'daedalus-edit-link-suggestions';
      list.id = `daedalus-edit-link-suggestions-${listboxCount++}`;
      list.setAttribute('role', 'listbox');
      list.hidden = true;
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-controls', list.id);

      // The dropdown's state, per popover (popovers are per-gesture).
      let debounce = null;
      let requestToken = 0;
      let options = [];
      let activeIndex = -1;

      const closeSuggestions = () => {
        requestToken++;
        clearTimeout(debounce);
        list.hidden = true;
        list.textContent = '';
        options = [];
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
        input.setAttribute('aria-expanded', 'false');
      };

      const setActive = (index) => {
        activeIndex = index;
        options.forEach((option, i) => {
          option.element.setAttribute('aria-selected', String(i === index));
        });
        const active = options[activeIndex];
        if (active) {
          input.setAttribute('aria-activedescendant', active.element.id);
          active.element.scrollIntoView({ block: 'nearest' });
        } else {
          input.removeAttribute('aria-activedescendant');
        }
      };

      // A pick writes the structural storage uri: the id came structured
      // off the response seam, no string grammar between here and the
      // spine. The input shows the match's "Label (id)" value — the same
      // displayable shape the committed snapshot will serialize back.
      const pick = (suggestion) => {
        control.preview(
          EditableElement,
          `entity:${suggestion.type}/${suggestion.id}`,
        );
        input.value = suggestion.value;
        closeSuggestions();
      };

      const render = (groups) => {
        closeSuggestions();
        // Group headings earn their row only when the field fans out to
        // more than one type.
        const showHeadings = groups.length > 1;
        groups.forEach((group) => {
          const suggestions = group.matches
            .map((match) => ({
              type: group.type,
              id: extractEntityId(match.value),
              ...match,
            }))
            .filter((suggestion) => suggestion.id !== null);
          if (!suggestions.length) {
            return;
          }
          if (showHeadings) {
            const heading = document.createElement('div');
            heading.className = 'daedalus-edit-link-group';
            heading.textContent = group.label;
            list.append(heading);
          }
          suggestions.forEach((suggestion) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'daedalus-edit-link-suggestion';
            option.id = `${list.id}-${options.length}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            // A pointer surface, not a Tab stop (arrows walk the list).
            option.tabIndex = -1;
            // The endpoint labels are server-escaped; render them the way
            // core's autocomplete does.
            option.innerHTML = suggestion.label;
            option.addEventListener('mousedown', (event) => {
              // Keep focus (and the caret) in the input through the pick.
              event.preventDefault();
              pick(suggestion);
            });
            list.append(option);
            options.push({ element: option, suggestion });
          });
        });
        list.hidden = options.length === 0;
        input.setAttribute('aria-expanded', String(!list.hidden));
      };

      // The fan-out: N parallel requests, one per configured type, against
      // core's system.entity_autocomplete endpoint (the minted urls). A
      // superseded response (the user kept typing) is dropped by token.
      const suggest = async (query) => {
        const token = ++requestToken;
        const groups = await Promise.all(
          endpoints.map(async (endpoint) => {
            const url = `${endpoint.url}${endpoint.url.includes('?') ? '&' : '?'}q=${encodeURIComponent(query)}`;
            const response = await fetch(url);
            if (!response.ok) {
              throw new Error(
                `Link autocomplete request failed (${response.status}): ${url}`,
              );
            }
            return {
              type: endpoint.type,
              label: endpoint.label,
              matches: await response.json(),
            };
          }),
        );
        if (token === requestToken) {
          render(groups);
        }
      };

      input.addEventListener('input', () => {
        const typed = input.value.trim();
        control.preview(EditableElement, typed);
        clearTimeout(debounce);
        if (!typed || endpoints.length === 0) {
          closeSuggestions();
          return;
        }
        debounce = setTimeout(() => suggest(typed), SUGGEST_DEBOUNCE_MS);
      });

      input.addEventListener('keydown', (event) => {
        if (
          (event.key === 'ArrowDown' || event.key === 'ArrowUp') &&
          options.length
        ) {
          event.preventDefault();
          event.stopPropagation();
          const step = event.key === 'ArrowDown' ? 1 : -1;
          setActive((activeIndex + step + options.length) % options.length);
          return;
        }
        if (event.key === 'Enter' && activeIndex >= 0) {
          event.preventDefault();
          event.stopPropagation();
          pick(options[activeIndex].suggestion);
          return;
        }
        if (event.key === 'Enter') {
          // Confirm: the typed value is already previewed; close, and the
          // surface's close hands focus back to the editing surface.
          event.preventDefault();
          event.stopPropagation();
          closeSuggestions();
          togglePopover(EditableElement, 'link', false);
        }
      });

      popover.append(input, list);
      return () => releaseWidget(EditableElement, 'link');
    },

    /**
     * Opens the link popover and focuses its input — the verb's run
     * (Cmd+K, or a context-menu pick).
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     */
    open(EditableElement) {
      togglePopover(EditableElement, 'link', true);
    },

    /**
     * Previews a typed or picked uri: applies it to the markup, records it.
     *
     * Apply BEFORE record, deliberately: the editors' applyLink resolves
     * the change against the CURRENT value (value() below), so recording
     * first would make the preview diff against itself and morph nothing.
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     * @param {string} uri
     *   The raw user-entered string or a structural entity:TYPE/ID pick,
     *   or '' to unlink. Sent as typed; the spine normalizes.
     */
    preview(EditableElement, uri) {
      const base = EditableElement.snapshot?.[fieldName] ?? {};
      applyLink(EditableElement, uri);
      EditableElement.linkPreview = uri ? { ...base, uri } : null;
    },

    /**
     * The link's current value, for the editor's readProperties().
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     *
     * @return {?Object}
     *   The previewed value once the input was touched, else the snapshot
     *   value (the DOM's href can never be read back).
     */
    value(EditableElement) {
      return EditableElement.linkPreview !== undefined
        ? EditableElement.linkPreview
        : (EditableElement.snapshot?.[fieldName] ?? null);
    },

    /**
     * The widget's live input, if the widget is mounted.
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     *
     * @return {?Element}
     *   The input.
     */
    input(EditableElement) {
      return (
        EditableElement.verbWidgets
          ?.get('link')
          ?.popover?.querySelector('.daedalus-edit-link-input') ?? null
      );
    },

    /**
     * Writes a link value back, for the editor's writeProperties().
     *
     * The restore half: Escape and optimistic rollback push the snapshot
     * value through here — markup, preview state, and the widget's input
     * (its displayable form) all follow.
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     * @param {?Object} value
     *   The link value ({uri, _displayable, ...} or null), spine-serialized.
     */
    write(EditableElement, value) {
      const link = value ?? null;
      // Apply before record, same as preview(): the markup change diffs
      // against the pre-write value.
      applyLink(EditableElement, link?.uri ?? '');
      EditableElement.linkPreview = link;
      const input = control.input(EditableElement);
      if (input) {
        input.value = displayable(link);
      }
    },

    /**
     * Re-syncs the widget's input to the current value.
     *
     * A draft resuming over swapped markup (EditorPluginBase.resume)
     * keeps the mounted widget while the snapshot is re-taken; the input
     * must follow the fresh snapshot. A fresh edit builds the widget after
     * this runs, so a missing input is a no-op.
     *
     * @param {EditableElement} EditableElement
     *   The element being edited.
     */
    refresh(EditableElement) {
      const input = control.input(EditableElement);
      if (input) {
        input.value = displayable(control.value(EditableElement));
      }
    },

    /**
     * Clears the per-gesture preview state.
     *
     * The editors' resetGesture() and their fresh entry both call this: a
     * draft resuming over swapped markup reuses the SAME leaf state
     * object, and a stale linkPreview would shadow the re-taken snapshot
     * for an untouched link.
     *
     * @param {EditableElement} EditableElement
     *   The element whose state to reset.
     */
    reset(EditableElement) {
      delete EditableElement.linkPreview;
    },
  };
  return control;
}

// The ONE Link verb, shared by every editor that exposes a link control:
// edit-scoped (applies only while the live edit lands on the key), on the
// chip's face at every density, its Cmd+K a typing-context hotkey through
// the registry so it fires while keystrokes land in the open editor. The
// queue runs the callback immediately when the daedalus_ui app is
// already live, or at its boot when it isn't yet.
(window.DaedalusUiQueue ||= []).push((app) => {
  app.verbs.register({
    id: 'daedalus_edit.link',
    label: Drupal.t('Link'),
    icon: LINK_ICON,
    kinds: ['block'],
    editScoped: true,
    tab: true,
    weight: 10,
    hotkeys: 'mod+k',
    typingHotkeys: true,
    applies: () => currentLinkEdit() !== null,
    run: () => {
      const current = currentLinkEdit();
      if (current) {
        current.plugin.link.open(current);
      }
    },
    widget: (key, container, { labels } = {}) => {
      const current = currentLinkEdit();
      if (!current) {
        return undefined;
      }
      return current.plugin.link.buildWidget(current, container, {
        showLabel: labels,
      });
    },
  });
});
