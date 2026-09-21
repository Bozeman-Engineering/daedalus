# Changelog

## 2.4.0 (unreleased)

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
- Fixed: `UpdateBlockFormForEditTool` fataled on load — the form trait's
  typed `$requestStack` declaration collided with core `FormBase`'s
  untyped one. The trait no longer declares the property.
