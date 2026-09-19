# Changelog

## 2.4.0 (unreleased)

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
