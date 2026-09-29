/**
 * @file
 * The formatted text block's blueprint editor.
 *
 * The `formatted_text` block type renders COLLAPSED: the composed root is a
 * single div stamped with data-daedalus-edit-editor="formatted_text" by
 * the generative composed render (FormattedTextComposedContent), showing the field's FILTERED
 * output. Editing is canvas-native rich text on the blueprint mutation
 * spine: clicking the leaf saves the filtered markup, seeds the root with
 * the RAW value from the blueprint mirror's {value, format} pair, and
 * attaches an inline CKEditor to the root itself through the shared
 * Drupal.behaviors.DaedalusEditInlineEditors machinery. Blur diffs the editor's
 * data against the mirror snapshot and commits ONE `update_element` op; the
 * mutation response's markup swap repaints the filtered output. When
 * nothing changed (or the edit is canceled) no re-render happens, so the
 * saved filtered markup is restored by hand.
 *
 * The snapshot's value is re-baselined from editor.getData() once the
 * editor attaches: CKEditor normalizes markup, so diffing against the
 * mirror's byte-exact raw value would commit a junk history entry on the
 * first blur of an untouched block.
 *
 * The open edit is a DRAFT (EditorPluginBase's three-way rule). A
 * foreign mutation's response (a style scrub from the Daedalus Stylish inspector,
 * an undo, an AI turn) swaps the root out from under the CKEditor: the
 * engine captures the draft as the swap detaches the root (captureDraft
 * reads the live editor's {value, format} and destroys the orphan, which
 * rides the detached element) and hands it to resume() once the response
 * is fully applied. resume() decides by the rule: draft = the captured
 * pair, base = the snapshot, remote = the mirror after the response; a
 * changed draft survives when the remote left the field alone and is
 * seeded into a fresh CKEditor over the swapped-in root, otherwise the
 * remote's value is. Because the snapshot re-baselines to CKEditor's
 * normalization (below), the remote is compared against the mirror's OWN
 * bytes at edit open (mirrorBaseline), never against the normalized
 * snapshot: a response echoing the stored markup is not a remote change.
 * The caret is not preserved across the rebuild; the typing is.
 *
 * The gesture opens on the value's stored format (falling back to the
 * format stamped in the editor settings), and the Text format verb — an
 * EDIT-SCOPED WIDGET VERB on the selection chip's face, riding the shared
 * widget-popover grammar — offers a format selector listing every format
 * the user may use that has a CKEditor configured (preprocess stamps the
 * list). Picking a
 * format ports core editor.js's filterXssWhenSwitching: the current data is
 * re-filtered through core's editor/filter_xss/{format} endpoint (unless
 * the target editor is XSS-safe), the CKEditor is detached, and a new one
 * attaches with the picked format's configuration. The format rides the
 * committed {value, format} bag, so blur commits the switch and the spine
 * enforces format access on write. Formats with no CKEditor are excluded
 * from this widget by design: a loud failure beats a secret degraded mode.
 *
 * @see daedalus_edit/js/daedalus_edit/plugins/editor-plugin-base.js
 * @see daedalus_edit/js/daedalus_edit/plugins/inline-editor.js
 * @see daedalus_edit/js/daedalus_edit/composed/verb-widgets.js
 * @see ../daedalus_edit_formatted_text_block.page_builder.block_type.formatted_text.yml
 */
(window.DaedalusEditQueue ||= []).push(
  ({ EditorPluginBase, editors, verbWidgets }) => {
    const FIELD = 'field_formatted_text_text';

    // Phosphor's text-aa glyph, the site's icon language (daedalus_ui
    // assets); the chip's icon class sizes and colors it.
    const FORMAT_ICON =
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256" aria-hidden="true"><path d="M87.24,52.59a8,8,0,0,0-14.48,0l-64,136a8,8,0,1,0,14.48,6.81L39.9,160h80.2l16.66,35.4a8,8,0,1,0,14.48-6.81ZM47.43,144,80,74.79,112.57,144ZM200,96c-12.76,0-22.73,3.47-29.63,10.32a8,8,0,0,0,11.26,11.36c3.8-3.77,10-5.68,18.37-5.68,13.23,0,24,9,24,20v3.22A42.76,42.76,0,0,0,200,128c-22.06,0-40,16.15-40,36s17.94,36,40,36a42.73,42.73,0,0,0,24-7.25,8,8,0,0,0,16-.75V132C240,112.15,222.06,96,200,96Zm0,88c-13.23,0-24-9-24-20s10.77-20,24-20,24,9,24,20S213.23,184,200,184Z"></path></svg>';

    /**
     * Formatted text editor.
     *
     * Per-edit state on the leaf (BlueprintEditableElement instances are
     * per-gesture; plugin instances are registry singletons):
     * - format: the gesture's text format id, resolved at edit start and
     *   updated by the Text format widget's picker.
     * - ckeditorId: the canvas CKEditor's instance id while one is attached
     *   (kept on the leaf because a markup swap destroys the element that
     *   carries the data-ckeditor5-id attribute).
     * - filteredMarkup: the root's filtered innerHTML at edit start (or at
     *   the last resume: the swapped-in root's), restored by hand when no
     *   re-render is coming (no-change blur, cancel, commit rollback).
     * - mirrorBaseline: the field's mirror value when the editor was
     *   seeded, the bytes the snapshot held before it re-baselined to
     *   CKEditor's normalization; resume() compares the remote against it.
     * - verbWidgets: the widget-verb registry while the edit's widgets are
     *   mounted (see composed/verb-widgets.js).
     * - formatSwitch: the in-flight format-switch chain; a second pick lands
     *   after the first finishes rebuilding the editor.
     */
    class FormattedTextEditor extends EditorPluginBase {
      id = 'formatted_text';

      /**
       * Edit.
       *
       * The fresh entry. A format with no CKEditor cannot be edited by this
       * widget; failing here, before any state is touched, keeps the failure
       * loud and the session coherent.
       *
       * @param {EditableElement} EditableElement
       *   The BlueprintEditableElement being edited.
       */
      edit(EditableElement) {
        const format = this.resolveFormat(EditableElement);
        if (!this.editorBacked(format)) {
          throw new Error(
            `No CKEditor settings for text format "${format}"; the formatted text editor only supports editor-backed formats.`,
          );
        }
        EditableElement.format = format;
        super.edit(EditableElement);
      }

      /**
       * Whether a format has a CKEditor to edit with, warning when not.
       *
       * @param {?string} format
       *   The format id.
       *
       * @return {boolean}
       *   TRUE when the format's editor settings are stamped.
       */
      editorBacked(format) {
        if (drupalSettings.editor?.formats?.[format]) {
          return true;
        }
        Drupal.Daedalus?.ModeManager?.getPlugin('edit')?.message(
          Drupal.t(
            'The "@format" text format has no text editor configured, so this block cannot be edited on the canvas.',
            { '@format': format },
          ),
          'warning',
          { duration: 5000, scroll: false },
        );
        return false;
      }

      /**
       * The gesture's text format.
       *
       * The value's stored format from the blueprint mirror when one exists
       * (bare-string writes normalize to a null format there), falling back
       * to the field's default format stamped into the editor settings by
       * preprocess.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       *
       * @return {?string}
       *   The format id.
       */
      resolveFormat(EditableElement) {
        const component = this.mirrorComponent(EditableElement);
        let value = component?.block_fields?.[FIELD];
        if (Drupal.Daedalus.Blueprint.isFormOnlyStub(value)) {
          value = null;
        }
        return value?.format ?? EditableElement.settings.format ?? null;
      }

      /**
       * The gesture's live canvas CKEditor, once its async create resolves.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       *
       * @return {?Object}
       *   The CKEditor instance, or null while none is attached (before the
       *   create resolves, after teardown).
       */
      liveEditor(EditableElement) {
        return EditableElement.ckeditorId
          ? (Drupal.Daedalus.Edit.Ckeditor5Instances.get(
              EditableElement.ckeditorId,
            ) ?? null)
          : null;
      }

      snapshot(EditableElement) {
        const snapshot = super.snapshot(EditableElement);
        // An empty field mirrors as null while the editing surface's empty
        // rendering is ''; a stored bare-string value mirrors with a null
        // format. Normalize both so an untouched gesture diffs empty.
        snapshot[FIELD] ??= { value: '', format: EditableElement.format };
        snapshot[FIELD].format ??= EditableElement.format;
        return snapshot;
      }

      /**
       * Focus element.
       *
       * The fresh entry's surface: the root seeded with the snapshot's raw
       * value (see openSurface).
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       */
      focusElement(EditableElement) {
        this.openSurface(
          EditableElement,
          EditableElement.snapshot[FIELD].value ?? '',
        );
      }

      /**
       * Opens the editing surface on the root, seeded with a value.
       *
       * The raw-vs-filtered swap, shared by the fresh entry (seeded with the
       * snapshot's value) and a resume (seeded with the surviving draft or
       * the remote): save the root's filtered markup, record the mirror's
       * bytes as the baseline the remote is compared against, seed the root,
       * attach the inline CKEditor. The editor focuses itself when its async
       * create resolves (see attachEditor). When the seed IS the snapshot's
       * value, that resolution re-baselines the snapshot from
       * editor.getData(): CKEditor normalizes markup, and a byte-exact diff
       * against the mirror's raw value would commit a junk history entry on
       * first blur. A surviving draft leaves the snapshot alone (it is the
       * remote, the value blur diffs against and Escape restores).
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {string} value
       *   The raw value to seed the editor with.
       */
      openSurface(EditableElement, value) {
        const pageElement = EditableElement.getPageElement();
        EditableElement.filteredMarkup = pageElement.innerHTML;
        EditableElement.mirrorBaseline = structuredClone(
          EditableElement.snapshot[FIELD],
        );
        pageElement.innerHTML = value;
        // The shared attach machinery's create guard keys on this class; it
        // also keeps clicks on the editing surface from ending the edit.
        pageElement.classList.add('daedalus-edit-inline-editing');
        const attached =
          Drupal.behaviors.DaedalusEditInlineEditors.attachEditor(
            pageElement,
            drupalSettings.editor.formats[EditableElement.format],
          );
        // attachEditor stamps the instance id synchronously; the leaf carries
        // it because a markup swap destroys the stamped element.
        const id = pageElement.dataset.ckeditor5Id;
        EditableElement.ckeditorId = id;
        const seededSnapshot = value === EditableElement.snapshot[FIELD].value;
        // The gesture's OPENING format, captured now: the re-baseline below
        // must record the pre-edit truth even when a picker format switch
        // has already moved EditableElement.format by the time create
        // resolves (the switch must diff as a change).
        const { format } = EditableElement;
        attached.then(() => {
          // Only this attach's own editor re-baselines: a swap during the
          // create window tears this one down and a resume attaches another.
          if (!seededSnapshot || EditableElement.ckeditorId !== id) {
            return;
          }
          const editor = this.liveEditor(EditableElement);
          if (editor && EditableElement.snapshot) {
            EditableElement.snapshot[FIELD] = {
              value: editor.getData(),
              format,
            };
          }
        });
      }

      readProperties(EditableElement) {
        const editor = this.liveEditor(EditableElement);
        if (!editor) {
          // No live editor means no user input yet (the async create hasn't
          // resolved) or the gesture is past teardown: the snapshot's value
          // stands. Before the snapshot exists this returns the property
          // NAMES, which is all the base snapshot() consumes.
          return { [FIELD]: EditableElement.snapshot?.[FIELD] ?? null };
        }
        return {
          [FIELD]: {
            value: editor.getData(),
            format: EditableElement.format,
          },
        };
      }

      writeProperties(EditableElement, properties) {
        if (!(FIELD in properties)) {
          return;
        }
        const pageElement = EditableElement.getPageElement();
        if (!pageElement) {
          return;
        }
        const editor = this.liveEditor(EditableElement);
        if (editor) {
          editor.setData(properties[FIELD]?.value ?? '');
          return;
        }
        // Only the snapshot is ever written back without a live editor (the
        // commit rollback); its on-canvas truth is the filtered markup saved
        // at edit start, not the raw value.
        if (EditableElement.filteredMarkup !== undefined) {
          pageElement.innerHTML = EditableElement.filteredMarkup;
        } else {
          pageElement.innerHTML = properties[FIELD]?.value ?? '';
        }
      }

      /**
       * Blur element.
       *
       * Reads the editor's data while it is alive, tears the editor down,
       * then commits the diff as one `update_element` op. The commit's markup
       * swap repaints the filtered output; an empty diff restores the saved
       * filtered markup by hand because no re-render is coming.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       *
       * @return {Promise<void>}
       */
      blurElement(EditableElement) {
        const pageElement = EditableElement.getPageElement();
        // Diff before teardown: readProperties needs the live editor. A
        // gesture whose editor never finished building has no user input
        // (attachEditor's create guard destroys the orphan on resolve).
        const changed =
          pageElement && this.liveEditor(EditableElement)
            ? this.changedProperties(EditableElement)
            : {};
        return this.teardownEditor(EditableElement).then(() => {
          if (!pageElement) {
            return;
          }
          this.releaseSurface(pageElement);
          if (Object.keys(changed).length > 0) {
            this.commit(EditableElement, changed);
          } else if (EditableElement.filteredMarkup !== undefined) {
            // No re-render is coming; put the filtered output back ourselves.
            pageElement.innerHTML = EditableElement.filteredMarkup;
          }
        });
      }

      /**
       * Cancel element.
       *
       * Escape: destroy the editor and restore the saved filtered markup by
       * hand (writing the snapshot's raw value back would leave raw markup on
       * the canvas). Nothing is committed.
       *
       * @param {EditableElement} EditableElement
       *   The element being canceled.
       *
       * @return {Promise<void>}
       */
      cancelElement(EditableElement) {
        return this.teardownEditor(EditableElement).then(() => {
          const pageElement = EditableElement.getPageElement();
          if (!pageElement) {
            return;
          }
          this.releaseSurface(pageElement);
          if (EditableElement.filteredMarkup !== undefined) {
            pageElement.innerHTML = EditableElement.filteredMarkup;
          }
        });
      }

      /**
       * Capture draft.
       *
       * Called from the swap's detach while the root still stands: the live
       * editor's {value, format} is the draft (the snapshot's when the create
       * has not resolved yet: no user input to carry). The CKEditor rides the
       * element being replaced, so it is torn down here, by instance id; a
       * create still in flight finds the editing mark gone on its element
       * and destroys its own orphan (attachEditor's guard). The saved
       * filtered markup dies with the old root; resume() re-captures it from
       * the swapped-in one.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       *
       * @return {Object<string, *>}
       *   The draft bag.
       */
      captureDraft(EditableElement) {
        const draft = this.readProperties(EditableElement);
        EditableElement.getPageElement()?.classList.remove(
          'daedalus-edit-inline-editing',
        );
        this.teardownEditor(EditableElement);
        delete EditableElement.filteredMarkup;
        return draft;
      }

      /**
       * Resume.
       *
       * The three-way rule over the swapped-in root (see the file docblock).
       * The remote is compared against the mirror's bytes at the last seed
       * (mirrorBaseline), the draft against the normalized snapshot. A
       * surviving draft is seeded with its own format (a picker switch is
       * part of the draft); a winning remote brings its stored format, and a
       * remote whose format has no CKEditor cannot be edited here, so the
       * edit is dropped without committing (the swapped-in filtered markup
       * already stands).
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {Object<string, *>} draft
       *   The bag captureDraft() returned.
       *
       * @return {Promise<void>}
       */
      resume(EditableElement, draft) {
        const base = EditableElement.snapshot ?? {};
        const mirrorBase = {
          ...base,
          [FIELD]: EditableElement.mirrorBaseline ?? base[FIELD],
        };
        delete EditableElement.mirrorBaseline;
        this.resetGesture(EditableElement);
        const remote = this.snapshot(EditableElement);
        EditableElement.snapshot = remote;
        const survivors = this.survivingProperties(
          draft,
          base,
          remote,
          mirrorBase,
        );
        const seed = survivors[FIELD] ?? remote[FIELD];
        const format = seed.format ?? EditableElement.format;
        if (!this.editorBacked(format)) {
          return this.cancelEdit(EditableElement);
        }
        EditableElement.format = format;
        this.syncFormatPicker(EditableElement);
        EditableElement.getPageElement().classList.add('daedalus-edit-editing');
        this.openSurface(EditableElement, seed.value ?? '');
        return Promise.resolve();
      }

      /**
       * Destroys the gesture's CKEditor, wherever its element is.
       *
       * The shared detachEditor() needs the stamped element; this teardown
       * resolves the instance through the leaf's ckeditorId instead, so it
       * also covers the orphan cases (the stamped element replaced by a swap
       * or removed with its component).
       *
       * @param {EditableElement} EditableElement
       *   The element whose editor needs destroying.
       *
       * @return {Promise<void>}
       */
      teardownEditor(EditableElement) {
        const id = EditableElement.ckeditorId;
        EditableElement.ckeditorId = null;
        const editor = id
          ? Drupal.Daedalus.Edit.Ckeditor5Instances.get(id)
          : null;
        if (!editor) {
          return Promise.resolve();
        }
        Drupal.behaviors.DaedalusEditInlineEditors.defocusEditor(editor);
        return editor.destroy().then(() => {
          Drupal.Daedalus.Edit.Ckeditor5Instances.delete(id);
        });
      }

      /**
       * Releases the editing-surface marks the edit put on the root.
       *
       * Must run AFTER the editor's destroy resolves: CKEditor snapshots the
       * element's attributes at attach and restores them on destroy, which
       * resurrects the editing classes (and the data-ckeditor5-id stamp) that
       * were on the element when the editor attached, including the
       * daedalus-edit-editing mark defocus() already removed.
       *
       * @param {Element} pageElement
       *   The composed root.
       */
      releaseSurface(pageElement) {
        pageElement.classList.remove('daedalus-edit-inline-editing');
        pageElement.classList.remove('daedalus-edit-editing');
        pageElement.removeAttribute('data-ckeditor5-id');
      }

      inputs(EditableElement) {
        // The root is the editor; Tab flows leaf to leaf from it. The chip's
        // widgets are pointer/hotkey surfaces with their own internal
        // keyboard handling, never part of the session's Tab flow.
        return [EditableElement.getPageElement()].filter(Boolean);
      }

      /**
       * Builds the Text format widget: the toggle and the format picker.
       *
       * The Text format verb's widget callback body: called by the frame's
       * widget slot while this editor's edit is open (the verb's applies
       * gate already required at least two accessible editor-backed
       * formats — one format means nothing to switch, so no entry renders
       * at all). composed/verb-widgets.js owns the toggle/popover grammar;
       * the picker lists the formats stamped into the editor settings by
       * preprocess.
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
      buildFormatWidget(EditableElement, container, showLabel) {
        verbWidgets.buildWidgetToggle(EditableElement, container, {
          name: 'formats',
          label: Drupal.t('Text format'),
          icon: FORMAT_ICON,
          showLabel,
        });
        const popover = verbWidgets.buildPopover(EditableElement, 'formats', {
          // The surface closing with focus on a format button (a pick, a
          // scroll, a press outside) hands focus back to the editing root.
          returnFocus: (element) => element.getPageElement(),
        });
        // The picker is the kit's segmented control on the surface's card
        // (daedalus_ui/panel_controls): one track, one segment per format,
        // the pressed format raised by daedalus-ui-segment--active.
        const formats = document.createElement('div');
        formats.className = 'daedalus-edit-format-picker daedalus-ui-segments';
        formats.setAttribute('role', 'group');
        formats.setAttribute('aria-label', Drupal.t('Text format'));
        popover.append(formats);
        Object.entries(EditableElement.settings.formats ?? {}).forEach(
          ([format, label]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className =
              'daedalus-edit-format-picker__format daedalus-ui-segment';
            button.dataset.format = format;
            button.textContent = label;
            // Reached by roving focus (arrows/Tab within the popover), never by
            // the page's native tab order.
            button.tabIndex = -1;
            button.addEventListener('mousedown', (event) => {
              event.preventDefault();
              this.applyFormat(EditableElement, format);
            });
            button.addEventListener('keydown', (event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopPropagation();
                this.applyFormat(EditableElement, format);
              }
            });
            formats.append(button);
          },
        );
        // Roving focus within the open picker: arrows and Tab cycle the
        // format buttons (trapped — leaving the picker is Escape's job, and
        // Escape is the surface's: it closes the picker at document capture
        // and returns focus to the editing surface, the opener).
        // stopPropagation keeps these presses from the document-level hotkey
        // dispatcher, so the session's Tab grammar only sees keys from the
        // surface itself.
        formats.addEventListener('keydown', (event) => {
          const buttons = Array.from(
            formats.querySelectorAll('.daedalus-edit-format-picker__format'),
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

        this.syncFormatPicker(EditableElement);
        return () => verbWidgets.releaseWidget(EditableElement, 'formats');
      }

      /**
       * Reflects the gesture's current format in the widget's picker.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       */
      syncFormatPicker(EditableElement) {
        EditableElement.verbWidgets
          ?.get('formats')
          ?.popover?.querySelectorAll('.daedalus-edit-format-picker__format')
          .forEach((button) => {
            const pressed = button.dataset.format === EditableElement.format;
            button.setAttribute('aria-pressed', String(pressed));
            button.classList.toggle('daedalus-ui-segment--active', pressed);
          });
      }

      /**
       * Switches the gesture to another text format.
       *
       * The core form flow's mid-edit format switch, on the canvas: the
       * current editor data is re-filtered for the target format through
       * core's editor/filter_xss endpoint (see filterXss), the CKEditor is
       * detached, and a new one attaches with the target format's
       * configuration. The switch is a preview like every other edit in the
       * gesture — blur commits the {value, format} bag and the spine enforces
       * format access on write; Escape discards it.
       *
       * Switches queue on the leaf's formatSwitch chain: a second pick lands
       * after the first finishes rebuilding the editor, reading its data from
       * the first switch's editor. A pick made before the opening editor's
       * async create has resolved is ignored (there is no editor to read yet
       * and no user input to carry); picking again once the toolbar is up
       * works.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {string} format
       *   The target format id (one of the stamped accessible formats).
       */
      applyFormat(EditableElement, format) {
        verbWidgets.togglePopover(EditableElement, 'formats', false);
        if (
          format === EditableElement.format ||
          !this.liveEditor(EditableElement)
        ) {
          return;
        }
        if (!drupalSettings.editor?.formats?.[format]) {
          throw new Error(
            `No CKEditor settings for text format "${format}"; the picker only lists editor-backed formats.`,
          );
        }
        const previous = EditableElement.format;
        EditableElement.format = format;
        this.syncFormatPicker(EditableElement);
        EditableElement.formatSwitch = (
          EditableElement.formatSwitch ?? Promise.resolve()
        )
          .then(() => {
            const editor = this.liveEditor(EditableElement);
            if (
              !editor ||
              Drupal.Daedalus.Edit.CurrentlyEditingElement !== EditableElement
            ) {
              // The edit ended while the switch was queued; nothing to
              // rebuild.
              return;
            }
            return this.filterXss(editor.getData(), format, previous).then(
              (value) => this.reattachEditor(EditableElement, value, format),
            );
          })
          .catch((error) => {
            // The filter request failed before any teardown, so the previous
            // format's editor still stands; put the gesture's format back in
            // line with it.
            EditableElement.format = previous;
            this.syncFormatPicker(EditableElement);
            Drupal.Daedalus?.ModeManager?.getPlugin('edit')?.message(
              Drupal.t(
                'Switching the text format failed; the previous format is still active.',
              ),
              'error',
              { duration: 5000, scroll: false },
            );
            console.error(error);
          });
      }

      /**
       * Port of core editor.js filterXssWhenSwitching.
       *
       * Switching to a format whose editor is not XSS-safe must not seed that
       * editor with markup the target format would never allow, so the server
       * re-filters the value through core's editor/filter_xss/{format} route
       * (access: the target format's 'use' permission). The route answers
       * with the filtered value, or false when the target format needs no
       * filtering (it has no XSS protection to enforce), in which case the
       * value passes through untouched. XSS-safe editors skip the round trip
       * entirely — exactly core's behavior.
       *
       * @param {string} value
       *   The editor data to filter.
       * @param {string} format
       *   The target format id.
       * @param {?string} originalFormat
       *   The format the value currently has, for the server's relaxed
       *   filtering rules when switching between formats.
       *
       * @return {Promise<string>}
       *   The value to seed the target format's editor with.
       */
      filterXss(value, format, originalFormat) {
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

      /**
       * Rebuilds the canvas editor with another format's configuration.
       *
       * Detach, reseed, reattach: the current CKEditor is destroyed (its
       * destroy restores the attach-time attribute snapshot, stale
       * data-ckeditor5-id included — attachEditor stamps a fresh one), the
       * root is seeded with the XSS-filtered value, and the target format's
       * editor attaches to it. The new editor focuses itself when its create
       * resolves, like the gesture's opening attach.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {string} value
       *   The (filtered) data to seed the new editor with.
       * @param {string} format
       *   The format whose editor configuration to attach.
       *
       * @return {Promise<void>}
       */
      reattachEditor(EditableElement, value, format) {
        return this.teardownEditor(EditableElement).then(() => {
          const pageElement = EditableElement.getPageElement();
          if (
            !pageElement ||
            Drupal.Daedalus.Edit.CurrentlyEditingElement !== EditableElement
          ) {
            // The edit ended while the filter request was in flight; blur or
            // cancel already settled the surface.
            return;
          }
          pageElement.innerHTML = value ?? '';
          const attached =
            Drupal.behaviors.DaedalusEditInlineEditors.attachEditor(
              pageElement,
              drupalSettings.editor.formats[format],
            );
          EditableElement.ckeditorId = pageElement.dataset.ckeditor5Id;
          return attached;
        });
      }

      commitLabel(EditableElement, properties) {
        return Drupal.t('Edited formatted text');
      }
    }

    const editor = new FormattedTextEditor();
    editors.register(editor);
    // Register the Text format verb at app boot: an EDIT-SCOPED WIDGET VERB
    // on the selection chip's face, gated by the registry to a live edit on
    // the key (editingKey === key, verbs.js) and narrowed by applies to
    // THIS editor's own edit with at least two accessible formats. The
    // queue runs the callback immediately when the daedalus_ui app is
    // already live, or at its boot when it isn't yet.
    (window.DaedalusUiQueue ||= []).push((app) => {
      const currentFormattedEdit = () => {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        return current?.plugin === editor ? current : null;
      };
      app.verbs.register({
        id: 'daedalus_edit_formatted_text_block.format',
        label: Drupal.t('Text format'),
        icon: FORMAT_ICON,
        kinds: ['block'],
        editScoped: true,
        tab: true,
        applies: () => {
          const current = currentFormattedEdit();
          return (
            !!current && Object.keys(current.settings.formats ?? {}).length >= 2
          );
        },
        widget: (key, container, { labels } = {}) => {
          const current = currentFormattedEdit();
          if (!current) {
            return undefined;
          }
          return editor.buildFormatWidget(current, container, labels);
        },
      });
    });
  },
);
