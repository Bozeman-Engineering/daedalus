/**
 * @file
 * The text block's composed-element editor.
 *
 * The `text` block type renders COLLAPSED (no block sandwich): the composed
 * root is the block, stamped with data-daedalus-edit-editor="text_composite" by
 * the generative composed render (TextComposedContent). The root is ALWAYS
 * the tag element (the field_text_tag value IS the root's tagName) and
 * linked text nests the anchor inside it (tag > a). data-daedalus-edit-text
 * marks the contenteditable typing surface, riding the DECLARED element
 * the text mounts in (no editing-only elements): the root itself when
 * unlinked, the anchor when linked. Elements are re-resolved by attribute
 * on every access, never held, because previews and mutation markup swaps
 * replace them.
 *
 * Clicking the leaf makes the typing surface contenteditable, and the
 * edit's controls appear on the selection chip's verb face as EDIT-SCOPED
 * WIDGET VERBS in the daedalus_ui registry: </> (the Tag verb) opens
 * the tag picker popover (the tag field's allowed values; Cmd+Opt+T opens
 * it through the registry, Cmd+Opt+0/2..6 set a tag directly), and the
 * shared Link verb (daedalus_edit.link, Cmd+K) opens the link control's
 * input. Previews resolve through the element model: the previewed
 * property bag rides the resolver's client twin (Blueprint.resolveElement
 * over the delivered declaration) and the shared morph reconciles the DOM
 * to the answer (a tag change retags the root in place, a link change
 * mounts/unmounts the declared anchor with the typing surface following
 * the text), so the preview is the same tree the commit's markup swap
 * will deliver. Blur commits every changed field as one `update_element`
 * op through the blueprint mutation spine (EditorPluginBase). Escape
 * restores the mirror snapshot, previewed structure included.
 *
 * The widget-verb popover grammar, link control, and resolved-tree morph
 * are daedalus_edit's shared composed-editor modules, consumed through the
 * DaedalusEditQueue API; the tag picker and the property-bag resolution are
 * this editor's own.
 *
 * @see daedalus_edit/js/daedalus_edit/plugins/editor-plugin-base.js
 * @see daedalus_edit/js/daedalus_edit/composed/verb-widgets.js
 * @see daedalus_edit/js/daedalus_edit/composed/link-control.js
 * @see daedalus_edit/js/daedalus_edit/composed/element-preview.js
 * @see ../daedalus_edit_text_block.page_builder.block_type.text.yml
 */
(window.DaedalusEditQueue ||= []).push(
  ({ EditorPluginBase, editors, verbWidgets, linkControl, elementPreview }) => {
    // Phosphor icon, the site's icon language (daedalus_ui/assets); the
    // chip's icon class sizes and colors it.
    const CODE_ICON =
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256" aria-hidden="true"><path d="M69.12,94.15,28.5,128l40.62,33.85a8,8,0,1,1-10.24,12.29l-48-40a8,8,0,0,1,0-12.29l48-40a8,8,0,0,1,10.24,12.3Zm176,27.7-48-40a8,8,0,1,0-10.24,12.3L227.5,128l-40.62,33.85a8,8,0,1,0,10.24,12.29l48-40a8,8,0,0,0,0-12.29ZM162.73,32.48a8,8,0,0,0-10.25,4.79l-64,176a8,8,0,0,0,4.79,10.26A8.14,8.14,0,0,0,96,224a8,8,0,0,0,7.52-5.27l64-176A8,8,0,0,0,162.73,32.48Z"></path></svg>';

    // The typing-surface markers: they follow the element the text mounts in
    // (the root unlinked, the anchor linked) when a preview moves the mount,
    // declared to the morph as the text token's mount attributes.
    const SURFACE_ATTRIBUTES = ['data-daedalus-edit-text', 'contenteditable'];

    /**
     * Text composite editor.
     *
     * Per-edit state on the leaf (BlueprintEditableElement instances are
     * per-gesture; plugin instances are registry singletons):
     * - verbWidgets: the widget-verb registry while the edit's widgets are
     *   mounted (see composed/verb-widgets.js).
     * - linkPreview: the link control's previewed value (see
     *   composed/link-control.js).
     */
    class TextCompositeEditor extends EditorPluginBase {
      id = 'text_composite';

      /**
       * The link control, bound to this editor's leaf grammar: a typed uri
       * previews as a property-bag change resolved through the twin.
       */
      link = linkControl.create({
        fieldName: 'field_text_url',
        applyLink: (EditableElement, uri) =>
          this.previewProperties(EditableElement, {
            field_text_url: uri ? { uri } : null,
          }),
        returnFocus: (EditableElement) => this.textElement(EditableElement),
      });

      /**
       * The typing surface: the [data-daedalus-edit-text] element.
       *
       * The root ITSELF when the text is not a link, the anchor inside the
       * root when it is (editing attributes ride the declared elements; no
       * editing-only span exists). Re-resolved through the leaf on every
       * call (never held), because tag/link previews and mutation markup
       * swaps replace the elements.
       *
       * @param {EditableElement} EditableElement
       *   The BlueprintEditableElement being edited.
       *
       * @return {?Element}
       *   The text element, or null when the leaf is off the page.
       */
      textElement(EditableElement) {
        const root = EditableElement.getPageElement();
        if (!root) {
          return null;
        }
        return root.matches('[data-daedalus-edit-text]')
          ? root
          : root.querySelector('[data-daedalus-edit-text]');
      }

      /**
       * Edit.
       *
       * The fresh entry: the per-gesture link state resets before the base
       * snapshots, so a stale linkPreview from an earlier gesture on the
       * same leaf cannot shadow the snapshot for an untouched link. A
       * foreign mutation's swap mid-gesture does not come back through here:
       * the engine hands the open edit's draft to EditorPluginBase.resume,
       * which resets the same state through resetGesture() before it
       * re-snapshots.
       *
       * @param {EditableElement} EditableElement
       *   The BlueprintEditableElement being edited.
       */
      edit(EditableElement) {
        this.link.reset(EditableElement);
        super.edit(EditableElement);
      }

      /**
       * Reset gesture.
       *
       * The link preview is the one per-gesture state beside the snapshot;
       * a surviving link draft comes back through writeProperties() (which
       * re-records it), an untouched one must not shadow the remote.
       *
       * @param {EditableElement} EditableElement
       *   The BlueprintEditableElement being edited.
       */
      resetGesture(EditableElement) {
        this.link.reset(EditableElement);
      }

      focusElement(EditableElement) {
        const text = this.textElement(EditableElement);
        text.setAttribute('contenteditable', 'true');
        text.focus();
        // The chip's widgets stay mounted while a draft resumes over swapped
        // markup with a re-taken snapshot; re-sync them to it (a fresh edit's
        // widgets build after this and read the fresh state themselves).
        this.link.refresh(EditableElement);
        this.syncTagPicker(EditableElement);
      }

      defocus(EditableElement) {
        this.textElement(EditableElement)?.removeAttribute('contenteditable');
        super.defocus(EditableElement);
      }

      snapshot(EditableElement) {
        const snapshot = super.snapshot(EditableElement);
        // Empty fields serialize to null in the mirror while the editing
        // surface has a concrete empty rendering: '' for the text, the
        // template's fallback tag for the tag. Normalize so a gesture that
        // touches nothing diffs empty and sends nothing.
        snapshot.field_text_text ??= '';
        snapshot.field_text_tag ??=
          EditableElement.getPageElement()?.tagName.toLowerCase() ?? 'p';
        snapshot.field_text_url ??= null;
        return snapshot;
      }

      readProperties(EditableElement) {
        const text = this.textElement(EditableElement);
        // The tag is the ROOT's tagName: the root is always the tag element,
        // linked (the anchor is the surface) or not (the root IS the surface).
        return {
          field_text_text: text?.textContent ?? '',
          field_text_tag:
            EditableElement.getPageElement()?.tagName.toLowerCase() ??
            EditableElement.snapshot?.field_text_tag ??
            null,
          field_text_url: this.link.value(EditableElement),
        };
      }

      writeProperties(EditableElement, properties) {
        // The link first: its write restores the anchor structure AND the
        // control's preview state, so the tag restore below resolves against
        // the restored link.
        if ('field_text_url' in properties) {
          this.link.write(EditableElement, properties.field_text_url ?? null);
        }
        if ('field_text_tag' in properties) {
          this.previewProperties(EditableElement, {
            field_text_tag: properties.field_text_tag ?? 'p',
          });
          this.syncTagPicker(EditableElement);
        }
        if ('field_text_text' in properties) {
          const text = this.textElement(EditableElement);
          if (text) {
            text.textContent = properties.field_text_text ?? '';
          }
        }
      }

      inputs(EditableElement) {
        // The text alone: Tab flows leaf to leaf (the writing gesture), never
        // through the chip's widgets. They are hotkey/click surfaces with
        // their own internal keyboard handling; from the link input, Tab's
        // session fallthrough (not an edge input) commits and advances.
        return [this.textElement(EditableElement)].filter(Boolean);
      }

      /**
       * Builds the Tag widget: the </> toggle and the tag picker popover.
       *
       * The Tag verb's widget callback body: called by the frame's widget
       * slot while this editor's edit is open (composed/verb-widgets.js
       * owns the toggle/popover grammar).
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {Element} container
       *   The frame's widget slot container.
       * @param {boolean} showLabel
       *   Whether the entry renders its text label (the frame's density
       *   ladder).
       *
       * @return {Function}
       *   The widget cleanup.
       */
      buildTagsWidget(EditableElement, container, showLabel) {
        verbWidgets.buildWidgetToggle(EditableElement, container, {
          name: 'tags',
          label: Drupal.t('Tag'),
          icon: CODE_ICON,
          showLabel,
        });
        const popover = verbWidgets.buildPopover(EditableElement, 'tags', {
          // The surface closing with focus on a tag button (a scroll, a
          // press outside) hands focus back to the text.
          returnFocus: (element) => this.textElement(element),
        });
        // The picker is the kit's segmented control on the surface's card
        // (daedalus_ui/panel_controls): one track, one segment per tag,
        // the pressed tag raised by daedalus-ui-segment--active.
        const tags = document.createElement('div');
        tags.className = 'daedalus-edit-tag-picker daedalus-ui-segments';
        tags.setAttribute('role', 'group');
        tags.setAttribute('aria-label', Drupal.t('Tag'));
        popover.append(tags);
        Object.entries(
          EditableElement.settings.tag_allowed_values ?? {},
        ).forEach(([tag, label]) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className =
            'daedalus-edit-tag-picker__tag daedalus-ui-segment';
          button.dataset.tag = tag;
          button.textContent = label;
          // Reached by roving focus (arrows/Tab within the popover), never by
          // the page's native tab order.
          button.tabIndex = -1;
          button.addEventListener('mousedown', (event) => {
            event.preventDefault();
            this.previewTag(EditableElement, tag);
          });
          button.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              event.stopPropagation();
              this.previewTag(EditableElement, tag);
            }
          });
          tags.append(button);
        });
        // Roving focus within the open picker: arrows and Tab cycle the tag
        // buttons (trapped — leaving the picker is Escape's job, and Escape
        // is the surface's: it closes the picker at document capture and
        // returns focus to the text, the opener). stopPropagation keeps
        // these presses from the document-level hotkey dispatcher, so the
        // session's Tab grammar only sees keys from the text itself.
        tags.addEventListener('keydown', (event) => {
          const buttons = Array.from(
            tags.querySelectorAll('.daedalus-edit-tag-picker__tag'),
          );
          const index = buttons.indexOf(document.activeElement);
          let target = null;
          if (
            event.key === 'ArrowRight' ||
            event.key === 'ArrowDown' ||
            (event.key === 'Tab' && !event.shiftKey)
          ) {
            target = buttons[(index + 1) % buttons.length];
          } else if (
            event.key === 'ArrowLeft' ||
            event.key === 'ArrowUp' ||
            (event.key === 'Tab' && event.shiftKey)
          ) {
            target = buttons[(index - 1 + buttons.length) % buttons.length];
          } else {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          target?.focus();
        });

        this.syncTagPicker(EditableElement);
        return () => verbWidgets.releaseWidget(EditableElement, 'tags');
      }

      /**
       * Reflects the current tag in the widget's picker.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       */
      syncTagPicker(EditableElement) {
        const current = EditableElement.getPageElement()?.tagName.toLowerCase();
        EditableElement.verbWidgets
          ?.get('tags')
          ?.popover?.querySelectorAll('.daedalus-edit-tag-picker__tag')
          .forEach((button) => {
            const pressed = button.dataset.tag === current;
            button.setAttribute('aria-pressed', String(pressed));
            button.classList.toggle('daedalus-ui-segment--active', pressed);
          });
      }

      /**
       * The data facade of a property bag, for the resolver twin.
       *
       * A key exists iff the field is non-empty; the value is the string the
       * markup prints for it. The link previews its STORAGE uri as the href
       * (the resolved url is server knowledge; the commit's markup swap
       * prints it), the same value the old hand-rolled preview showed.
       *
       * @param {Object} properties
       *   A property bag (readProperties() shape).
       *
       * @return {Object}
       *   The facade ({fields}).
       */
      dataFor(properties) {
        const fields = {};
        if (properties.field_text_text) {
          fields.field_text_text = properties.field_text_text;
        }
        if (properties.field_text_tag) {
          fields.field_text_tag = properties.field_text_tag;
        }
        if (properties.field_text_url?.uri) {
          fields.field_text_url = properties.field_text_url.uri;
        }
        return { fields };
      }

      /**
       * Previews property changes: resolve the new bag, morph the DOM to it.
       *
       * The one preview path for every structural gesture (tag switch, link
       * add/remove): current bag and previewed bag both resolve through the
       * client twin against the delivered declaration, and the shared morph
       * reconciles the leaf between the two trees, the typing-surface
       * markers following the text's mount. The previewed anchor is not
       * navigable in edit mode: clicks inside the leaf resolve to the leaf
       * and the open edit's press decision prevents their default.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {Object} changes
       *   The changed properties (readProperties() names).
       */
      previewProperties(EditableElement, changes) {
        const { Blueprint } = Drupal.Daedalus;
        const declaration = Blueprint.blockTypeDeclaration(
          this.mirrorComponent(EditableElement)?.block_type ?? 'text',
        );
        if (!declaration) {
          throw new Error(
            'The text block declaration is not in the delivered anatomy; the preview cannot resolve.',
          );
        }
        const current = this.readProperties(EditableElement);
        const target = { ...current, ...changes };
        elementPreview.applyResolved(
          EditableElement,
          Blueprint.resolveElement(declaration, this.dataFor(current)),
          Blueprint.resolveElement(declaration, this.dataFor(target)),
          { mountAttributes: { 'field:field_text_text': SURFACE_ATTRIBUTES } },
        );
      }

      /**
       * Previews a tag change, keeping the caret across the root swap.
       *
       * The morph retags the root in place; the caret survives because the
       * text node itself moves (same node object), so its recorded offsets
       * re-apply on the other side.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {string} tag
       *   The tag to preview (one of the field's allowed values).
       */
      previewTag(EditableElement, tag) {
        const root = EditableElement.getPageElement();
        if (!root || root.tagName.toLowerCase() === tag) {
          this.syncTagPicker(EditableElement);
          return;
        }
        const text = this.textElement(EditableElement);
        const selection = window.getSelection();
        const hadFocus =
          !!text &&
          (document.activeElement === text ||
            text.contains(document.activeElement));
        let caret = null;
        if (hadFocus && selection.rangeCount > 0) {
          const range = selection.getRangeAt(0);
          if (text.contains(range.startContainer)) {
            // Plain positions, not a Range: live Ranges collapse when their
            // boundary nodes move during the swap.
            caret = {
              startContainer: range.startContainer,
              startOffset: range.startOffset,
              endContainer: range.endContainer,
              endOffset: range.endOffset,
            };
          }
        }

        this.previewProperties(EditableElement, { field_text_tag: tag });

        if (hadFocus) {
          // Re-resolve: the surface is the retagged root when unlinked, the
          // moved-over anchor when linked (same node object).
          const surface = this.textElement(EditableElement);
          surface?.focus();
          if (caret && surface) {
            // A boundary on the old root itself (empty unlinked text) remaps
            // to the new surface; child nodes moved over as the same objects.
            const remap = (node) => (node.isConnected ? node : surface);
            const range = document.createRange();
            range.setStart(remap(caret.startContainer), caret.startOffset);
            range.setEnd(remap(caret.endContainer), caret.endOffset);
            selection.removeAllRanges();
            selection.addRange(range);
          }
        }
        this.syncTagPicker(EditableElement);
      }

      /**
       * The current edit, when it is this editor's.
       *
       * The hotkeys and verbs register at boot and exist on every Edit Mode
       * page, so every action guards through here: no text-block edit in
       * progress means "does not apply / did not handle" and the key falls
       * through.
       *
       * @return {?object}
       *   The BlueprintEditableElement, or null.
       */
      currentEdit() {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        return current?.plugin === this ? current : null;
      }

      /**
       * Registers the editor's direct-tag hotkeys, once.
       *
       * Cmd+Opt+0/1..6 set the tag directly (the Google Docs/Notion heading
       * convention), guarded by the field's allowed values (h1 included: a
       * text block may be the page's one h1, the theme's title does not own
       * the tag). Parameterized
       * shortcuts, not verbs: the Tag verb (with Cmd+Opt+T) is the
       * registry's one entry for the gesture, and these are its expert
       * accelerators. Each declares both the editing and typing
       * contexts — focus alternates between the contenteditable text
       * (typing) and the tag buttons (editing) — and is listed
       * remappable in the settings tray under the Text block section.
       */
      registerHotkeys() {
        if (this.hotkeysRegistered || !Drupal.Daedalus?.Hotkeys) {
          return;
        }
        const setTag = (tag) => () => {
          const current = this.currentEdit();
          if (
            !current ||
            !(tag in (current.settings.tag_allowed_values ?? {}))
          ) {
            return false;
          }
          this.previewTag(current, tag);
        };
        const bindings = [
          {
            id: 'daedalus_edit_text_block.tag_p',
            key: 'mod+alt+0',
            label: Drupal.t('Tag: paragraph'),
            action: setTag('p'),
          },
          {
            id: 'daedalus_edit_text_block.tag_h1',
            key: 'mod+alt+1',
            label: Drupal.t('Tag: heading 1'),
            action: setTag('h1'),
          },
          {
            id: 'daedalus_edit_text_block.tag_h2',
            key: 'mod+alt+2',
            label: Drupal.t('Tag: heading 2'),
            action: setTag('h2'),
          },
          {
            id: 'daedalus_edit_text_block.tag_h3',
            key: 'mod+alt+3',
            label: Drupal.t('Tag: heading 3'),
            action: setTag('h3'),
          },
          {
            id: 'daedalus_edit_text_block.tag_h4',
            key: 'mod+alt+4',
            label: Drupal.t('Tag: heading 4'),
            action: setTag('h4'),
          },
          {
            id: 'daedalus_edit_text_block.tag_h5',
            key: 'mod+alt+5',
            label: Drupal.t('Tag: heading 5'),
            action: setTag('h5'),
          },
          {
            id: 'daedalus_edit_text_block.tag_h6',
            key: 'mod+alt+6',
            label: Drupal.t('Tag: heading 6'),
            action: setTag('h6'),
          },
        ];
        bindings.forEach((binding) => {
          Drupal.Daedalus.Hotkeys.register({
            ...binding,
            when: ['editing', 'typing'],
            section: Drupal.t('Text block'),
            remappable: true,
          });
        });
        this.hotkeysRegistered = true;
      }
    }

    const editor = new TextCompositeEditor();
    editors.register(editor);
    // Register the hotkeys and the Tag verb at app boot, not at first edit:
    // the settings tray documents the bindings (and remaps apply) before any
    // text block is touched, and the registry is the one home of "what can I
    // do here". The queue runs the callback immediately when the
    // daedalus_ui app is already live, or at its boot when it isn't yet.
    (window.DaedalusUiQueue ||= []).push((app) => {
      editor.registerHotkeys();

      // The tag gesture as an EDIT-SCOPED WIDGET VERB on the selection
      // chip's face: the registry gates it to a live edit on the key
      // (editingKey === key, verbs.js), applies narrows that to THIS
      // editor's own edit, and the frame's widget slot renders the </>
      // toggle plus the tag picker popover (buildTagsWidget). Cmd+Opt+T is
      // the verb's typing-context hotkey: it toggles the picker and moves
      // focus in so arrows/Tab pick a tag; toggling closed returns focus
      // to the text. The link gesture is the shared daedalus_edit.link verb
      // (the plugin's `link` control opts in).
      app.verbs.register({
        id: 'daedalus_edit_text_block.tags',
        label: Drupal.t('Tag'),
        icon: CODE_ICON,
        kinds: ['block'],
        capability: 'content',
        editScoped: true,
        tab: true,
        hotkeys: 'mod+alt+t',
        typingHotkeys: true,
        applies: () => editor.currentEdit() !== null,
        run: () => {
          const current = editor.currentEdit();
          const entry = current?.verbWidgets?.get('tags');
          if (!entry?.popover) {
            return;
          }
          const show = !entry.open;
          verbWidgets.togglePopover(current, 'tags', show);
          if (show) {
            // Land on the current tag so arrows/Tab pick from there.
            (
              entry.popover.querySelector("[aria-pressed='true']") ??
              entry.popover.querySelector('button')
            )?.focus();
          }
          // Toggling closed with focus on a tag button returns focus to the
          // text through the widget's returnFocus (the surface's onClose).
        },
        widget: (key, container, { labels } = {}) => {
          const current = editor.currentEdit();
          if (!current) {
            return undefined;
          }
          return editor.buildTagsWidget(current, container, labels);
        },
      });
    });
  },
);
