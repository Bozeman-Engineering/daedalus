/**
 * The stencil verbs: Save as stencil… and Update stencil 'X'.
 *
 * Object-first save-as-stencil: the user selects a composed element — a
 * SECTION at any depth or an inline block — and the verb offers itself
 * alongside Duplicate/Remove. "Save as stencil…" opens a server-rendered
 * dialog (inert markup, client-owned interaction) collecting the label and
 * description a stencil requires, then submits the save_material SPINE OP
 * (kind stencil, the material gesture family) through the ordinary
 * mutation queue: the verb is a thin adapter over exactly the operation
 * the AI composes with, one grammar, one validation path, one permission
 * gate ("save stencils", enforced by the op).
 *
 * The Update verb reads the placement's provenance off the blueprint
 * mirror: instantiate_stencil stamps the source stencil ID onto the placed
 * root (a block root's additional data, a section root's third-party
 * settings), and the serialization spine surfaces it as the element's
 * read-only `stencil` key. Whether that ID is updatable (a content stencil,
 * not a file-shipped one) and what to call it come from the stencil's
 * RECORD in the materials store (Drupal.Daedalus.Materials), the
 * same record the Add panel paints its item from, current within the
 * session: a save from any lane lands as a delta before the verb next
 * asks. Dead provenance (a deleted stencil, an unpublished one, a
 * file-shipped one) simply degrades the selection to "Save as stencil…";
 * save-as-new stays available even when Update shows. Placements never
 * re-sync: updating a stencil changes FUTURE placements only. The verb
 * opens the same server-rendered dialog pattern as save (its confirm step,
 * with adoption parity: the capture's unowned variants as checked
 * checkboxes) and submits the update_material op with the picked
 * adopt_variants.
 *
 * Both verbs are permission-gated chrome (no "save stencils" grant on
 * drupalSettings.daedalusLayout.canSaveStencils, attached by the Add panel, no
 * verb); the ops enforce the permission for real.
 */

// The gesture awaiting an open dialog: { key } while the save dialog is
// up, { key, target } while the update dialog is; null when no dialog is
// open. The dialogs' distinct commit attributes (data-stencil-save /
// data-stencil-update) keep the two commits apart.
let pendingGesture = null;

/**
 * Whether the user may save stencils (the Add panel's grant; chrome only,
 * absent off stencil-aware pages).
 */
const canSave = () => !!window.drupalSettings?.daedalusLayout?.canSaveStencils;

/**
 * The UUID a selectable block/section key qualifies.
 */
const uuidOf = (key) => key.split(':')[1];

/**
 * The capturable element node behind a selectable key, else null.
 *
 * Sections capture at any depth (a node carrying layout_id IS a section);
 * inline blocks capture too (the serializer emits `block_type` for inline
 * blocks only, so its presence is the gate — plugin blocks have no stencil
 * grammar and the capture would refuse them loudly).
 */
const capturableElement = (key) => {
  const { Blueprint } = Drupal.Daedalus;
  const node = Blueprint.componentForUuid(
    Blueprint.getBlueprint(),
    uuidOf(key),
  );
  if (!node) {
    return null;
  }
  return node.layout_id || node.block_type ? node : null;
};

/**
 * The updatable stencil a key's provenance resolves to, else null.
 *
 * Provenance rides the element's `stencil` key (the serialized stamp);
 * the stencil's record in the materials store says whether that ID is a
 * content stencil (file stencils are read-only everywhere) and what its
 * label is. No record means the stencil is gone, unpublished, or
 * file-shipped, all just "not updatable", never an error.
 *
 * @return {?{id: string, label: string}}
 */
const updatableStencil = (key) => {
  const id = capturableElement(key)?.stencil;
  const record = id ? Drupal.Daedalus.Materials.get('stencil', id) : null;
  return record?.updatable ? { id, label: record.label } : null;
};

/**
 * Submits a stencil gesture op through the mutation queue.
 *
 * The response's materials delta carries the saved stencil's RECORD as an
 * upsert (the op queue has already applied it to the store by the time
 * the payload resolves, so the Add panel item and the Update verb see it);
 * `warnings` carries capture warnings (inexpressible style keys the export
 * dropped).
 *
 * @param {Object} operation
 *   The save_material / update_material operation (kind stencil).
 * @param {string} label
 *   The history-entry label for the gesture.
 *
 * @return {Promise<Object>}
 *   Resolves with {stencil, warnings}: the stencil record (null when the
 *   delta carried none) and the warning messages.
 */
const submitGesture = async (operation, label) => {
  const context = Drupal.Daedalus.Blueprint.rootContext();
  if (!context) {
    throw new Error(Drupal.t('The page context is not available.'));
  }
  const payload = await Drupal.Daedalus.Blueprint.mutate({
    ...context,
    operations: [operation],
    label,
  });
  return {
    stencil:
      (payload.materials?.upsert ?? []).find(
        (record) => record.kind === 'stencil',
      ) ?? null,
    warnings: (payload.warnings ?? [])
      .filter((warning) => warning.code === 'MATERIAL_CAPTURE_WARNING')
      .map((warning) => warning.message),
  };
};

/**
 * Reports through the one edit-mode message display (toast + notifications
 * sidebar), keyed to the element so the sidebar entry can scroll back.
 */
const report = (text, type, key = null) => {
  Drupal.Daedalus.ModeManager.getPlugin('edit').message(
    text,
    type,
    {},
    key,
  );
};

/**
 * Surfaces a gesture's capture warnings (inexpressible style keys the
 * export dropped; drush-export parity).
 */
const reportWarnings = (warnings, key) => {
  (warnings ?? []).forEach((warning) => report(warning, 'warning', key));
};

/**
 * Opens the save dialog (server-rendered, inert markup the client
 * drives) and stashes the gesture it will complete.
 *
 * The element and host-entity coordinates ride the query so the server
 * can offer ADOPTION: the unowned style variants the captured subtree
 * references render as checked checkboxes (data-stencil-adopt), and
 * commitSave() folds the picked ones into the op's adopt_variants.
 */
const openSaveDialog = (key) => {
  const { ModePluginBase, Blueprint } = Drupal.Daedalus;
  const context = Blueprint.rootContext();
  pendingGesture = { key };
  ModePluginBase.dialog({
    url: ModePluginBase.url('/daedalus-layout/stencil-save-dialog', {
      element: uuidOf(key),
      ...(context
        ? { entity_type: context.entityType, entity_id: context.entityId }
        : {}),
    }),
    width: 480,
    message: Drupal.t('Loading...'),
  });
};

/**
 * Opens the update dialog (the push gesture's confirm step) and stashes
 * the gesture it will complete.
 *
 * The same coordinates as the save dialog ride the query so the server
 * offers the same ADOPTION checkboxes (update is when new looks usually
 * arrive on a stencil), plus the target stencil reference so the confirm
 * copy names it.
 */
const openUpdateDialog = (key) => {
  const target = updatableStencil(key);
  if (!target) {
    return;
  }
  const { ModePluginBase, Blueprint } = Drupal.Daedalus;
  const context = Blueprint.rootContext();
  pendingGesture = { key, target };
  ModePluginBase.dialog({
    url: ModePluginBase.url('/daedalus-layout/stencil-update-dialog', {
      element: uuidOf(key),
      stencil: target.id,
      ...(context
        ? { entity_type: context.entityType, entity_id: context.entityId }
        : {}),
    }),
    width: 480,
    message: Drupal.t('Loading...'),
  });
};

/**
 * Whether the open dialog's inputs are valid, surfacing the first problem
 * at its field.
 *
 * Validity is field state, not a notification: the server-rendered inputs
 * carry `required`, and reportValidity() shows the browser's own message
 * on the first empty one (it works without an enclosing <form>). The
 * dialog stays open either way.
 */
const inputsValid = () =>
  ['daedalus-layout-stencil-label', 'daedalus-layout-stencil-description']
    .map((id) => document.getElementById(id))
    .every((input) => !input || input.reportValidity());

const closeDialog = () => {
  const dialog = document.getElementById('layout-builder-modal');
  if (dialog) {
    jQuery(dialog).dialog('close');
  }
};

/**
 * The adoption picks off an open dialog's checkboxes.
 *
 * The save and update dialogs render the same data-stencil-adopt
 * contract; the checked variants become the stencil's own, offered only
 * on its placements from now on.
 */
const adoptPicks = () =>
  [...document.querySelectorAll('.daedalus-layout-stencil-save [data-stencil-adopt]')]
    .filter((input) => input.checked)
    .map((input) => input.getAttribute('data-stencil-adopt'));

/**
 * The icon pick off the open save dialog, or '' (no picker, or unset).
 *
 * The dialog's picker (StencilSaveDialog::iconField(), driven by
 * IconPickerOpener + icon-picker.js) only ever writes `media:<uuid>` into
 * its hidden input, exactly the spelling save_material accepts.
 */
const iconPick = () =>
  document
    .querySelector('.daedalus-layout-stencil-save [data-daedalus-layout-icon-picker-value]')
    ?.value.trim() ?? '';

/**
 * Commits the save dialog: submit the save_material op, close on success,
 * report the refusal through the one message display on failure (the
 * dialog stays open for the correction).
 */
const commitSave = (button) => {
  const key = pendingGesture?.key;
  if (!key || !inputsValid()) {
    return;
  }
  const label = document.getElementById('daedalus-layout-stencil-label').value.trim();
  const description = document
    .getElementById('daedalus-layout-stencil-description')
    .value.trim();
  const adopt = adoptPicks();
  const icon = iconPick();

  button.disabled = true;
  submitGesture(
    {
      op: 'save_material',
      kind: 'stencil',
      element: uuidOf(key),
      label,
      description,
      ...(adopt.length ? { adopt_variants: adopt } : {}),
      ...(icon ? { icon } : {}),
    },
    Drupal.t('Saved stencil'),
  )
    .then((result) => {
      pendingGesture = null;
      closeDialog();
      // The Add panel item and the just-saved element's Update verb already
      // read the record: the response's delta landed in the store before
      // the mutation resolved.
      report(
        Drupal.t(
          'Saved as the stencil "@label". It is now available in the Add panel.',
          { '@label': result.stencil?.label ?? label },
        ),
        'status',
        key,
      );
      reportWarnings(result.warnings, key);
    })
    .catch((error) => {
      button.disabled = false;
      Drupal.Daedalus.Blueprint.reportFailure(error, key);
    });
};

/**
 * Commits the update dialog: push the captured element into the content
 * stencil as a new revision (one revert away; workspace-staged on
 * workspace sites), adopting the picked variants alongside.
 */
const commitUpdate = (button) => {
  const { key, target } = pendingGesture ?? {};
  if (!key || !target) {
    return;
  }
  const adopt = adoptPicks();
  button.disabled = true;
  submitGesture(
    {
      op: 'update_material',
      kind: 'stencil',
      id: target.id,
      element: uuidOf(key),
      ...(adopt.length ? { adopt_variants: adopt } : {}),
    },
    Drupal.t('Updated stencil'),
  )
    .then((result) => {
      pendingGesture = null;
      closeDialog();
      report(
        Drupal.t('Updated the stencil "@label".', {
          '@label': result.stencil?.label ?? target.label,
        }),
        'status',
        key,
      );
      reportWarnings(result.warnings, key);
    })
    .catch((error) => {
      button.disabled = false;
      Drupal.Daedalus.Blueprint.reportFailure(error, key);
    });
};

// One delegated listener serves both dialogs: inert unless a gesture is
// pending and the event lands on its buttons. Buttons are real <button>
// elements, so click covers keyboard activation.
document.addEventListener('click', (event) => {
  if (!pendingGesture || !(event.target instanceof Element)) {
    return;
  }
  const save = event.target.closest('[data-stencil-save]');
  if (save) {
    event.preventDefault();
    commitSave(save);
    return;
  }
  const update = event.target.closest('[data-stencil-update]');
  if (update) {
    event.preventDefault();
    commitUpdate(update);
    return;
  }
  if (event.target.closest('[data-stencil-cancel]')) {
    event.preventDefault();
    pendingGesture = null;
    closeDialog();
  }
});

// Closing the dialog without committing is a cancel. Only the stencil
// dialog's own close counts: dialog events dispatch on the dialog element
// and bubble, and the icon picker opens the media library as a SECOND
// modal whose close must not cancel the pending gesture.
window.addEventListener('dialog:afterclose', (event) => {
  if (
    event.target instanceof Element &&
    event.target.querySelector('.daedalus-layout-stencil-save')
  ) {
    pendingGesture = null;
  }
});

// Phosphor icons (currentColor, like the other verb icons): bookmark for
// saving a reusable piece, arrow-circle-up for pushing an update into one.
const icons = {
  save: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M184,32H72A16,16,0,0,0,56,48V224a8,8,0,0,0,12.24,6.78L128,193.43l59.77,37.35A8,8,0,0,0,200,224V48A16,16,0,0,0,184,32Z"></path></svg>',
  update:
    '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm45.66,109.66a8,8,0,0,1-11.32,0L136,107.31V168a8,8,0,0,1-16,0V107.31l-26.34,26.35a8,8,0,0,1-11.32-11.32l40-40a8,8,0,0,1,11.32,0l40,40A8,8,0,0,1,173.66,133.66Z"></path></svg>',
};

/**
 * Registers the stencil verbs into the edit-mode app.
 *
 * @param {object} app
 *   The edit-mode app registration facade.
 */
export const registerStencilVerbs = (app) => {
  app.verbs.register({
    id: 'daedalus_layout.update_material',
    label: Drupal.t('Update stencil'),
    // The verb names its target per the provenance rule: "Update stencil
    // 'X'" when the placement's stamp resolves to a content stencil.
    menuLabel: (key) => {
      const target = updatableStencil(key);
      return target
        ? Drupal.t("Update stencil '@label'", { '@label': target.label })
        : Drupal.t('Update stencil');
    },
    icon: icons.update,
    kinds: ['block', 'container'],
    applies: (key) => canSave() && !!updatableStencil(key),
    weight: 26,
    run: openUpdateDialog,
  });

  app.verbs.register({
    id: 'daedalus_layout.save_material',
    label: Drupal.t('Save as stencil…'),
    icon: icons.save,
    kinds: ['block', 'container'],
    // Save-as-new is ALWAYS available on capturable elements (sections at
    // any depth, inline blocks) when the user may save stencils, even while
    // Update shows.
    applies: (key) => canSave() && !!capturableElement(key),
    weight: 27,
    run: openSaveDialog,
  });
};
