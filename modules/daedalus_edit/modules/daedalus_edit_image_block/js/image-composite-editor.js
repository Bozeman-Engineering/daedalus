/**
 * @file
 * The image block's composed-element editor.
 *
 * The `image` block type renders COLLAPSED (no block sandwich): the composed
 * root is the block, stamped with data-daedalus-edit-editor="image_composite" by
 * the generative composed render (ImageComposedContent) on the media_library tier. The root is the
 * anchor when the image is a link, otherwise the picture itself — the img
 * on raster media, the inlined svg on vector, a placeholder div while the
 * block is empty. Root identity is NOT stable — a link change or the first
 * media selection replaces the root — so everything here re-resolves by
 * attribute, never holds elements.
 *
 * Nothing on the picture takes typed input (the img is a void element and
 * the svg's markup is server-derived), so the whole gesture is
 * click → chrome. The MEDIA gesture is a verb on the selection chip's
 * face (daedalus_edit_image_block.media, registered edit-scoped in the
 * daedalus_ui verb registry): while this editor's edit is open, the
 * chip offers Add/Replace image, and running it opens the media library
 * as an AJAX modal (the server-built media_library.ui URL rides the
 * editor settings; the selection returns as one AJAX command routed to
 * this edit's mediaSelected()). The LINK rides the shared daedalus_edit.link
 * widget verb (Cmd+K; the plugin's `link` control opts in). The two
 * commit differently, by design:
 * - The LINK stages to blur, text-block semantics: typing previews the
 *   anchor wrap in place, blur diffs against the mirror snapshot and sends
 *   one update_element op, Escape restores the snapshot.
 * - The MEDIA commits IMMEDIATELY as its own history entry: no client-side
 *   src exists (the src is derived server-side from the media's configured
 *   display), so the mutation's markup swap IS the preview and undo — not
 *   Escape — is its revert path.
 * An empty leaf's edit() auto-opens the library: picking an image is the
 * placeholder's whole point, so the gesture saves the click.
 *
 * @see daedalus_edit/js/daedalus_edit/plugins/editor-plugin-base.js
 * @see daedalus_edit/js/daedalus_edit/composed/verb-widgets.js
 * @see daedalus_edit/js/daedalus_edit/composed/link-control.js
 * @see daedalus_edit/js/daedalus_edit/composed/element-preview.js
 * @see daedalus_edit/js/daedalus_edit/media-library-selection.js
 * @see ../daedalus_edit_image_block.page_builder.block_type.image.yml
 */
(window.DaedalusEditQueue ||= []).push(
  ({ EditorPluginBase, editors, linkControl, elementPreview }) => {
    // Phosphor icon, the site's icon language (daedalus_ui/assets); the
    // chip's icon class sizes and colors it.
    const IMAGE_ICON =
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256" aria-hidden="true"><path d="M216,40H40A16,16,0,0,0,24,56V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A16,16,0,0,0,216,40Zm0,16V158.75l-26.07-26.06a16,16,0,0,0-22.63,0l-20,20-44-44a16,16,0,0,0-22.62,0L40,149.37V56ZM40,172l52-52,80,80H40Zm176,28H194.63l-36-36,20-20L216,181.38V200ZM144,100a12,12,0,1,1,12,12A12,12,0,0,1,144,100Z"></path></svg>';

    // The picture element's server-derived source set, per media kind:
    // element-OWN attributes that never migrate in the morph's identity
    // moves. They stay on the picture element (the raster img, or the
    // inlined vector svg), which continues as the same physical node across
    // the hoist boundary (the root when unlinked, the nested part when
    // linked) — and so does its part class, which is modeled, so nothing
    // re-seats anything. The href needs no declaration here either: it is
    // the anchor form's declared attribute, so the resolved tree owns where
    // it lives. The vector set mirrors the inliner's allowlist plus the
    // a11y attributes it stamps (_daedalus_edit_image_block_inline_svg()).
    const OWN_ATTRIBUTES = {
      raster: ['src', 'alt', 'width', 'height', 'loading'],
      vector: [
        'viewBox',
        'width',
        'height',
        'fill',
        'preserveAspectRatio',
        'role',
        'aria-label',
        'aria-hidden',
      ],
    };

    /**
     * Image composite editor.
     *
     * Per-edit state on the leaf (BlueprintEditableElement instances are
     * per-gesture; plugin instances are registry singletons):
     * - verbWidgets: the widget-verb registry while the edit's widgets are
     *   mounted (see composed/verb-widgets.js).
     * - linkPreview: the link control's previewed value (see
     *   composed/link-control.js).
     */
    class ImageCompositeEditor extends EditorPluginBase {
      id = 'image_composite';

      /**
       * The link control, bound to this editor's leaf grammar: a typed uri
       * previews as a property-bag change resolved through the twin.
       *
       * Nothing on an image leaf takes keyboard focus (the img is a void
       * element), so closing the link popover has nowhere to return focus to.
       */
      link = linkControl.create({
        fieldName: 'field_image_url',
        applyLink: (EditableElement, uri) =>
          this.previewProperties(EditableElement, {
            field_image_url: uri ? { uri } : null,
          }),
        returnFocus: () => null,
      });

      /**
       * The content element: the picture (img or svg), or the placeholder
       * div while empty.
       *
       * The root ITSELF when the block is not a link (the collapsed root is
       * the content element), the anchor's child when it is. Re-resolved
       * through the leaf on every call (never held), because link previews and
       * mutation markup swaps replace the elements — including the content
       * element's own identity (placeholder div → img on the first
       * selection's swap), which is why the anchor's child resolves
       * structurally rather than by tag.
       *
       * @param {EditableElement} EditableElement
       *   The BlueprintEditableElement being edited.
       *
       * @return {?Element}
       *   The content element, or null when the leaf is off the page.
       */
      imageElement(EditableElement) {
        const root = EditableElement.getPageElement();
        if (!root) {
          return null;
        }
        return root.matches('a') ? root.firstElementChild : root;
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
       * An EMPTY leaf auto-opens the media library: the placeholder's whole
       * point is picking an image, so the gesture saves the click. The first
       * selection's swap resumes the draft instead of re-entering here, so
       * the library does not re-open over the image it just delivered.
       *
       * @param {EditableElement} EditableElement
       *   The BlueprintEditableElement being edited.
       */
      edit(EditableElement) {
        this.link.reset(EditableElement);
        super.edit(EditableElement);
        if (!EditableElement.snapshot.field_image) {
          this.openMediaLibrary(EditableElement);
        }
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
        // Nothing on the leaf takes focus (the img is a void element); the
        // edit's controls are the chip's verbs. The Link widget stays mounted
        // while a draft resumes over swapped markup with a re-taken
        // snapshot, so re-sync it.
        this.link.refresh(EditableElement);
      }

      snapshot(EditableElement) {
        const snapshot = super.snapshot(EditableElement);
        // Empty fields serialize to null in the mirror (the base already
        // normalized form-only stubs); pin the shape so a gesture that
        // touches nothing diffs empty and sends nothing.
        snapshot.field_image ??= null;
        snapshot.field_image_url ??= null;
        return snapshot;
      }

      readProperties(EditableElement) {
        return {
          // The media reference has no client-side value to read back (the
          // src is server-derived and a selection commits immediately through
          // mediaSelected()), so the blur diff always sees it unchanged: the
          // snapshot value echoes back. It stays a snapshotted property so
          // the gesture re-baselines across the selection's markup swap: the
          // draft's resume re-snapshots, and the new target_id is the remote
          // that won.
          field_image: EditableElement.snapshot?.field_image ?? null,
          field_image_url: this.link.value(EditableElement),
        };
      }

      writeProperties(EditableElement, properties) {
        // field_image has no client-side write: the server render is the
        // preview, and the selection commit is its own history entry whose
        // revert path is undo. Escape and optimistic rollback therefore
        // restore only the staged half of the gesture, the link.
        if ('field_image_url' in properties) {
          this.link.write(EditableElement, properties.field_image_url ?? null);
        }
      }

      inputs(EditableElement) {
        // Nothing on the leaf takes typed input (the img is a void element);
        // the chip's widgets are hotkey/click surfaces with their own
        // internal keyboard handling.
        return [];
      }

      getFocusedSelectors() {
        return [
          ...super.getFocusedSelectors(),
          // The media library modal is part of the edit gesture: clicking
          // into it (or its jQuery UI chrome and overlay) must not blur —
          // commit — the edit underneath it, which is also the selection
          // command's addressee.
          '#drupal-modal',
          '.ui-dialog',
          '.ui-widget-overlay',
        ];
      }

      /**
       * Opens the media library modal.
       *
       * The URL is server-built (state hash included) by the
       * DaedalusEditMediaLibraryOpener and stamped into the editor settings by
       * preprocess, the CKEditor precedent: the widget never constructs
       * library state, it hands the URL to daedalus_ui's modal helper
       * (library daedalus_ui/media_library), the one place the modal is
       * shaped. The user's selection returns as one AJAX command that routes
       * to this edit's mediaSelected(); the modal's close command rides the
       * same response.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       */
      openMediaLibrary(EditableElement) {
        const url = EditableElement.settings.media_library_url;
        if (!url) {
          // Editor attribution without the URL means preprocess could not
          // resolve the host entity — a wiring bug worth hearing about, not
          // a silently dead button.
          console.warn(
            'Daedalus Edit image block: the editor settings carry no media_library_url; the media library cannot open.',
          );
          return;
        }
        Drupal.Daedalus.openMediaLibraryModal(url);
      }

      /**
       * Receives the media library selection for the current edit.
       *
       * The client half of the opener contract (see
       * media-library-selection.js): commits IMMEDIATELY as its own history
       * entry. No client-side preview exists for a media reference — the src
       * is derived server-side — so the mutation's markup swap delivers the
       * new image, and undo is the revert path. The edit itself survives the
       * swap as a draft (the engine resumes it through
       * EditorPluginBase.resume, whose three-way rule takes the new
       * target_id as the remote-won snapshot), so the link half of the
       * gesture keeps its blur/Escape semantics on the new markup.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {number[]} selectedIds
       *   The selected media ids (one: the library opens with remaining=1).
       */
      mediaSelected(EditableElement, selectedIds) {
        const targetId = selectedIds[0];
        if (targetId === undefined) {
          return;
        }
        if (EditableElement.snapshot?.field_image?.target_id === targetId) {
          // Re-picking the current image changes nothing; no history entry.
          return;
        }
        this.commit(EditableElement, { field_image: { target_id: targetId } });
      }

      commitLabel(EditableElement, properties) {
        if ('field_image' in properties) {
          return EditableElement.snapshot?.field_image
            ? Drupal.t('Replaced the image')
            : Drupal.t('Added an image');
        }
        return super.commitLabel(EditableElement, properties);
      }

      /**
       * The referenced media's kind, for the resolver twin's data facade.
       *
       * Read from the markup annotation, the model's own answer for the
       * committed data: the picture part's RESOLVED TAG (img on raster
       * media, svg on vector; the part is present on both, hoisted or
       * nested, so presence cannot tell the lanes apart). A link gesture
       * never changes the media (media commits immediately through
       * mediaSelected() and returns as a server swap), so the committed
       * kind holds for every preview this editor resolves.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       *
       * @return {?string}
       *   'raster', 'vector', or null while the block references no media.
       */
      mediaKind(EditableElement) {
        if (!EditableElement.snapshot?.field_image) {
          return null;
        }
        const tag = Drupal.Daedalus.Blueprint.partTag(
          EditableElement.selectableKey,
          '.image-block-image',
        );
        return tag === 'svg' ? 'vector' : 'raster';
      }

      /**
       * The data facade of a property bag, for the resolver twin.
       *
       * A key exists iff the field is non-empty (the media reference has no
       * printable string, so its key carries null); the link previews its
       * STORAGE uri as the href, the value the old hand-rolled preview
       * showed.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {Object} properties
       *   A property bag (readProperties() shape).
       *
       * @return {Object}
       *   The facade ({fields, mediaKind}).
       */
      dataFor(EditableElement, properties) {
        const fields = {};
        if (properties.field_image) {
          fields.field_image = null;
        }
        if (properties.field_image_url?.uri) {
          fields.field_image_url = properties.field_image_url.uri;
        }
        return { fields, mediaKind: this.mediaKind(EditableElement) };
      }

      /**
       * Previews property changes: resolve the new bag, morph the DOM to it.
       *
       * The declared root forms decide the shape (anchor when linked, img on
       * raster media, svg on vector, placeholder div when empty), and the
       * morph tracks identity by model key: linking nests the picture — the
       * same physical element, its own source set and part class staying
       * put — inside a fresh anchor root that receives the identity bag;
       * unlinking reverses it. The part class rides the model on both
       * sides, so the old re-seating fixups have no successor. The
       * previewed anchor is not navigable in edit mode: clicks resolve to
       * the leaf and the open edit's press decision prevents their
       * default.
       *
       * @param {EditableElement} EditableElement
       *   The element being edited.
       * @param {Object} changes
       *   The changed properties (readProperties() names).
       */
      previewProperties(EditableElement, changes) {
        const Blueprint = Drupal.Daedalus.Blueprint;
        const declaration = Blueprint.blockTypeDeclaration(
          this.mirrorComponent(EditableElement)?.block_type ?? 'image',
        );
        if (!declaration) {
          throw new Error(
            'The image block declaration is not in the delivered anatomy; the preview cannot resolve.',
          );
        }
        const current = this.readProperties(EditableElement);
        const target = { ...current, ...changes };
        elementPreview.applyResolved(
          EditableElement,
          Blueprint.resolveElement(
            declaration,
            this.dataFor(EditableElement, current),
          ),
          Blueprint.resolveElement(
            declaration,
            this.dataFor(EditableElement, target),
          ),
          {
            ownAttributes:
              OWN_ATTRIBUTES[this.mediaKind(EditableElement)] ?? [],
          },
        );
      }
    }

    const editor = new ImageCompositeEditor();
    editors.register(editor);
    // Register the media verb at app boot, not at first edit: the registry
    // is the one home of "what can I do here". The link gesture needs no
    // registration here — the shared daedalus_edit.link verb (link-control.js)
    // applies through the plugin's `link` control, Cmd+K included. The
    // queue runs the callback immediately when the daedalus_ui app is
    // already live, or at its boot when it isn't yet.
    (window.DaedalusUiQueue ||= []).push((app) => {
      // The current edit, when it is this editor's: the verb face renders
      // from the store's editingKey the moment the one setter reports the
      // edit, and the media library modal needs no other surface.
      const currentImageEdit = () => {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        return current?.plugin === editor ? current : null;
      };

      // The media gesture as an EDIT-SCOPED verb on the selection chip's
      // face: the registry gates it to a live edit on the key (editingKey ===
      // key, verbs.js), and applies narrows that to THIS editor's own edit —
      // another editor's edit on a block key offers nothing here. Running it
      // opens the media library modal, the same server-built URL the empty
      // leaf's edit() auto-open uses; the selection commits immediately
      // through mediaSelected() while the edit stays open. The face names the
      // gesture by the edit's snapshot: Add while the block references no
      // media, Replace once it does.
      app.verbs.register({
        id: 'daedalus_edit_image_block.media',
        label: Drupal.t('Replace image'),
        menuLabel: () =>
          currentImageEdit()?.snapshot?.field_image
            ? Drupal.t('Replace image')
            : Drupal.t('Add an image'),
        icon: IMAGE_ICON,
        kinds: ['block'],
        capability: 'content',
        editScoped: true,
        applies: () => currentImageEdit() !== null,
        // On the chip's face at clean density too, not behind the chevron:
        // the media pick is the block's primary gesture.
        tab: true,
        run: () => {
          const current = currentImageEdit();
          if (current) {
            editor.openMediaLibrary(current);
          }
        },
      });
    });
  },
);
