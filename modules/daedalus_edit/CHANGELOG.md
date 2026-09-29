# Changelog

## 2.4.0 (unreleased)

- Changed: the top bar's Save (`Controller\Tempstore::save`) is an adapter
  over daedalus_blueprint's commit door: each entity on the route is loaded
  as its working copy and handed to `EntityStorageHandler::commit()`, which
  applies the revision policy, saves, clears the stage and fires the
  persisted hook. The controller's own field copy onto a fresh load (which
  skipped computed fields and entity keys, so a staged URL alias, published
  flag or owner never reached the database), its revision policy and its
  by-hand render-tag invalidation are gone; it no longer takes the tempstore
  repository or the time service.
- Changed: a write that fills an empty field or empties one records
  daedalus_blueprint's new `TargetType::PRESENCE` target beside the field
  target, and the mutation response re-renders the containing subtree (the
  block root for a block field, the whole entity otherwise), because an
  empty field renders no wrapper for a keyed swap to land in. The entity
  form's `viewMode` rides the fetch URL's query (which core copies onto
  every `#ajax` URL the form builds) instead of the POST body, so a
  widget's own first AJAX round, which rebuilds the form from scratch,
  finds it; `DaedalusEditFormTrait::getViewMode()` no longer remembers it
  in form state. `FormCommit::fieldChanged()` is the one staged-versus-form
  comparison (two empty lists are equal whatever their item count).
  `ConfigureDialogFormAlterBase` stamps the dialog's Update button
  (`SUBMIT_ATTRIBUTE`) for the client half, since core's Layout Builder
  forms render it outside any actions container. The panel treats a
  revealed wrapper with no widget control (the hidden `changed` input, a
  withheld language select) as no session item, and the Fill with sample
  button as chrome, not a control. The image block's fallback tier mints
  its lookup identities through `EditIdentity` (property `target_id`).
- Changed: the Configure dialog (daedalus_layout's Configure verb opening
  core's `UpdateBlockForm` and `ConfigureSectionForm`) is on the form
  transport. `LayoutBuilderBlockFormAlter` and the new
  `LayoutBuilderSectionFormAlter` (both on `ConfigureDialogFormAlterBase`)
  take core's whole-storage tempstore submit out of the dialog's chain and
  commit what the dialog changed as one `update_element` on the subject
  through `FormCommit`: the placement's label, Display title toggle, view
  mode and managed configuration keys as values, the inline block's fields
  as `ExtractedValue`s, a section's label and layout settings; the AJAX
  callback answers the mutation payload (the subject swapped by key, no
  `#layout-builder` rebuild) plus the dialog's close, and the client half
  (`configure-dialog.js`) runs the dialog's Update as a job on the op
  queue. `FormCommit::commit()` takes a property bag. daedalus_blueprint's
  `update_element` accepts `label_display` and `view_mode` on every inline
  block (the placement configuration the read side already emits), and
  daedalus_layout's `BlueprintMirrorRefresh` (the envelope rider on core's
  form responses) is gone with the last core form response in edit mode.
  A `BlockPropertiesEvent` property is committed as a managed configuration
  key of the block type; an undeclared one is refused by the spine.
- Changed: the entity-form path is a widget host, never a write path. A
  panel form's Update button submits one named field
  (`FormCommit::FIELD_INPUT`) and the submit handler hands that field's
  extracted items to the daedalus_blueprint op spine as an `ExtractedValue`;
  the AJAX callback answers the standard mutation-response payload as one
  `daedalusBlueprintApplyResponse` command, applied through the app's one
  pipeline. Client-side there is ONE editor base (`EditorPluginBase`, with
  the value store and the commit transport supplied by the item: the
  blueprint mirror and a value op for a blueprint-edited leaf, the widget
  and the form's own submit for a form-edited leaf or a panel widget); the
  `PanelWidgetEditor` is the one editor for widgets with no canvas editor
  and commits on the generic triggers (native change, a Drupal AJAX
  response that touched the widget, the explicit floor). Field state has
  one client-side judge (`field-state.js`) and the empty field's "Add
  field" button is the Fill with sample verb (the spine's `_sample`
  request). The panel refreshes a subject form from a response's targets
  instead of the server evicting cached forms.
- Removed: `FormEditorBase` and `BlueprintEditorBase` (extend
  `EditorPluginBase`; a form-edited editor implements `readProperties` /
  `writeProperties` over its surface like every other editor),
  `DefaultPlugin`, the media and autocomplete plugins (the panel widget
  editor covers every widget), `updateTempstore`, the auto-save behavior
  and `data-daedalus-edit-auto-submit`, `daedalusEditIsDoneUpdating` /
  `Drupal.Daedalus.Edit.notUpdating` (Save waits on
  `Drupal.Daedalus.Blueprint.drained()`), `daedalusEditClearForm`,
  `BlueprintMutationClearForms`, `DaedalusUiReplaceMediaClearForm`, the
  `only_update_element` / `empty_field` / `view_mode` hidden inputs, the
  form's "Add field" buttons, `DaedalusEditFormTrait::updatePage` /
  `updateForm` / `handleErrors` / `refreshBlueprintMirror` /
  `entityContent`, daedalus_ui's `DaedalusUiFormTrait`, and the
  `daedalus_edit.tempstore.delete` route.
- Fixed: `daedalus_edit_formatted_text_block`'s status report entry for its
  shipped text format (Error when `daedalus_edit_formatted_text` is missing
  or disabled) never appeared on core 11.3+: it was a procedural
  `hook_requirements()` under `#[LegacyRequirementsHook]`, which core skips
  from 11.3, the module's floor. It is now a `#[Hook('runtime_requirements')]`
  class (`Hook\FormattedTextBlockRequirements`); `Install\TextFormatRequirement`
  is gone with its only caller.
- Changed: the identity minter no longer refuses a text format that allows a
  vocabulary tag (`p`, `h2` to `h4`, `blockquote`, `ul`, `ol`, `li`) without an
  unrestricted `class` attribute. Such tags are skipped (their elements stay
  unaddressable under that format) and the save proceeds, so installing
  daedalus_edit on a site with `basic_html` content no longer makes that
  content unsavable. A status report warning lists every enabled format and
  the tags it will not mint. The blueprint error code
  `IDENTITY_UNMINTABLE_FORMAT` is gone with the throw.
- Inline editors are now registered through `Drupal.Daedalus.Edit.Editors` via the
  `DaedalusEditQueue` push queue: `(window.DaedalusEditQueue ||= []).push((daedalusEdit) =>
  daedalusEdit.editors.register(new MyEditor()))`. Plugins extend `FormEditorBase`
  (entity-form commit path) or `BlueprintEditorBase` (daedalus_blueprint commit
  path) instead of the old field plugin base class.
- Removed: the legacy window-event registration seam and the registry's
  pre-split alias and base-class shim. Plugins register through the
  `DaedalusEditQueue` seam above; there is no BC path.
- Removed: the deprecated Layout Builder integration submodule (its
  functionality lives in daedalus_layout), the teaser block submodule, the
  `daedalus_edit.tempstore_repository` service alias (use
  `daedalus_tempstore.entity_tempstore_repository`), the zero-subscriber
  `FieldProperties` and `AddEmptyField` events, and the unused
  `ShowFieldWithErrors` AJAX command.
- Removed: the `daedalus_edit_non_lb_node` sample submodule. Test coverage now
  runs on `daedalus_edit_test_fixture` (tests/modules), a test-only fixture
  derived from the machinery inventory: a non-Layout-Builder node bundle,
  a reference target bundle that cannot self-reference, and a form-edited
  block_content type with no composed content class.
- Fixed: the panel's block form (now `PanelUpdateBlockForm`) fataled on
  load — the form trait's
  typed `$requestStack` declaration collided with core `FormBase`'s
  untyped one. The trait no longer declares the property.
