/**
 * The blueprint verbs: Save as blueprint…, Save page as blueprint…, Update
 * blueprint 'X', Open blueprint and Detach.
 *
 * Object-first save-as-blueprint: the user selects a composed CONTAINER
 * (a section at any depth) and the verb offers itself alongside
 * Duplicate/Remove. A blueprint's root is always a container, so the verb
 * never offers on a block: capture its container. "Save as blueprint…"
 * opens a server-rendered dialog (inert markup, client-owned interaction)
 * collecting the label and description a blueprint requires, then submits
 * the save_material SPINE OP (kind blueprint, the material gesture family)
 * through the ordinary mutation queue: the verb is a thin adapter over
 * exactly the operation the AI composes with, one grammar, one validation
 * path, one permission gate (the blueprint entity's own create access,
 * enforced by the op).
 *
 * "Save page as blueprint…" is the same gesture on the page ROOT: the
 * spine captures the whole document as a page-scope blueprint when the
 * element is the host's own uuid (BlueprintManager::capture()), so the
 * verb registers on the root entity key, opens the same dialog (worded for
 * a page by the server, which reads the same uuid) and commits the same
 * op with the root's uuid. It carries the registry's `document` flag, so
 * the top bar's Save caret is the surface that shows it (daedalus_ui owns
 * the control and the menu; the gesture is this module's). Offered on a
 * page the actor may configure (the capability gate: a host that does not
 * own its document is refused configure on its root), never on a
 * template (the label would lie) and never on a blueprint's own canvas (a
 * fork is a different gesture). A page-scope blueprint is not offered in
 * the Add panel, so the response's delta lands no record on this canvas:
 * the notice names the blueprint by the label the dialog took.
 *
 * The Update verb reads the placement's provenance off the blueprint
 * mirror: place_blueprint stamps the source blueprint ID onto the placed
 * root (a section root's third-party settings), and the serialization
 * spine surfaces it as the element's read-only `blueprint` key. Whether
 * that ID is updatable (a content blueprint, not a file-shipped one) and
 * what to call it come from the blueprint's RECORD in the materials store
 * (Drupal.Daedalus.Materials), the same record the Add panel paints its
 * item from, current within the session: a save from any lane lands as a
 * delta before the verb next asks. Dead provenance (a deleted blueprint,
 * an unpublished one, a file-shipped one) simply degrades the selection to
 * "Save as blueprint…"; save-as-new stays available even when Update
 * shows. Placements never re-sync: updating a blueprint changes FUTURE
 * placements only. The verb opens the same server-rendered dialog pattern
 * as save (its confirm step, with adoption parity: the capture's unowned
 * variants as checked checkboxes) and submits the update_material op with
 * the picked adopt_variants.
 *
 * The Open verb is direct editing's door: a saved blueprint is a page of
 * its own, so the menu navigates to the blueprint's canvas. Two spine
 * sources, one per node kind, and the client never derives a route: on a
 * join (a linked placement, a slot door) the URL rides the mirror's
 * element annotation (`_element.showing.portal.url`, the spine's showing
 * as daedalus_ui's annotator copies it, the portal present only when the
 * actor may update the shown blueprint); on a DETACHED placement (a
 * container stamped with provenance, no join: the copy is the page's own)
 * it is the blueprint record's `url`, read through the same provenance
 * rule as Update and under Update's gate, since Open is the caret half of
 * the "Update blueprint 'X'" split control. The record is
 * actor-independent, so the canvas route enforces access for real there,
 * as the update op does.
 *
 * The Detach verb is the road from a linked placement to a detached one:
 * on a linked node (the mirror's `{kind: linked}`, a block showing a saved
 * blueprint by reference) it submits the spine's detach_blueprint op with
 * the node's uuid, and the node becomes, in its place, the independent
 * copy it showed (provenance stamped, so Update blueprint works on it from
 * then on). Gated on arrange like Remove: the node is the page's own to
 * rearrange; its content was never the page's, which is why detaching is
 * the gesture that makes it so.
 *
 * The capture verbs are permission-gated chrome (no grant on
 * drupalSettings.daedalusLayout.canSaveBlueprints, attached by the Add
 * panel: may the actor save a blueprint of some type; no verb); the ops
 * enforce the blueprint type's permission for real.
 *
 * Open, Update and Save as share the registry group
 * `daedalus_layout.blueprint`, so on a placed blueprint the three are ONE
 * control where a face can draw one (the selection chip's split button:
 * Update leads as the main half, Open and "Save as new blueprint…" sit
 * behind its caret) and the plain members everywhere else (the context
 * menu, the tree's row menu, the hotkeys list them flat in weight order).
 * On a container that is not a placement only Save as applies, and a
 * group of one is the plain "Save as blueprint…" button it always was;
 * nothing here special-cases that, the registry's fold does
 * (daedalus_ui's verb-groups.js). Save as names itself per the same
 * provenance rule as Open and Update: "Save as new blueprint…" when the
 * container is a placed blueprint, "Save as blueprint…" otherwise.
 */

// The gesture awaiting an open dialog: { key } while the save dialog is
// up, { key, target } while the update dialog is; null when no dialog is
// open. The dialogs' distinct commit attributes (data-blueprint-save /
// data-blueprint-update) keep the two commits apart.
let pendingGesture = null;

/**
 * Whether the user may save blueprints (the Add panel's grant; chrome
 * only, absent off blueprint-aware pages).
 */
const canSave = () => !!window.drupalSettings?.daedalusLayout?.canSaveBlueprints;

/**
 * The UUID a selectable key qualifies: the component for a block, the
 * section for a container, the host for the root entity.
 */
const uuidOf = (key) => key.split(':')[1];

/**
 * Whether a key is the page root's: the host entity the mirror booted on.
 */
const isRootKey = (key) => {
  const root = Drupal.Daedalus.Blueprint.getRoot();
  return !!root && key === `entity:${root.uuid}`;
};

/**
 * Whether the canvas host is a PAGE, the kind of host a page capture
 * reads: a blueprint's own canvas (the library's `blueprint` entity) and
 * a template canvas (the display host, core's entity_view_display) are
 * the two canvases whose root is not a page.
 */
const hostIsPage = () => {
  const context = Drupal.Daedalus.Blueprint.rootContext();
  return (
    !!context &&
    context.entityType !== 'blueprint' &&
    context.entityType !== 'entity_view_display'
  );
};

/**
 * The mirror node behind a selectable key, else null.
 */
const nodeOf = (key) => {
  const { Blueprint } = Drupal.Daedalus;
  return Blueprint.componentForUuid(Blueprint.getBlueprint(), uuidOf(key)) ?? null;
};

/**
 * The capturable element node behind a selectable key, else null.
 *
 * A blueprint's root is always a container: a node carrying layout_id IS
 * a section, at any depth. Blocks are not capturable (capture the
 * container they sit in).
 */
const capturableElement = (key) => {
  const node = nodeOf(key);
  return node?.layout_id ? node : null;
};

/**
 * The updatable blueprint a key's provenance resolves to, else null.
 *
 * Provenance rides the element's `blueprint` key (the serialized stamp);
 * the blueprint's record in the materials store says whether that ID is a
 * content blueprint (file blueprints are read-only everywhere), what its
 * label is and where its canvas is (`url`, the canonical route). No record
 * means the blueprint is gone, unpublished, or file-shipped, all just "not
 * updatable", never an error.
 *
 * @return {?{id: string, label: string, url: ?string}}
 */
const updatableBlueprint = (key) => {
  const id = nodeOf(key)?.blueprint;
  const record = id ? Drupal.Daedalus.Materials.get('blueprint', id) : null;
  return record?.updatable
    ? { id, label: record.label, url: record.url ?? null }
    : null;
};

/**
 * The showing of a join, else null.
 *
 * The annotator stamps `_element.showing` on every join (a linked
 * placement, a slot door): what it shows, the shown document and, for an
 * actor who may update the shown blueprint, the portal with its canvas
 * url. A detached placement is a copy and carries none.
 *
 * @return {?Object}
 */
const showingOf = (key) => nodeOf(key)?._element?.showing ?? null;

/**
 * The canvas URL the Open verb leaves for from a key, else null.
 *
 * A join's is its showing's portal (present only when the actor may update
 * the shown blueprint); a detached placement's is its provenance record's
 * `url`, offered under the Update verb's own gate (the grant and an
 * updatable record), the split control's two halves agreeing.
 *
 * @return {?string}
 */
const openUrl = (key) => {
  const url =
    showingOf(key)?.portal?.url ??
    (canSave() ? updatableBlueprint(key)?.url : null);
  return typeof url === 'string' && url !== '' ? url : null;
};

/**
 * The label of the blueprint the Open verb would open from a key, else null.
 *
 * @return {?string}
 */
const openLabel = (key) =>
  showingOf(key)?.document?.label ?? updatableBlueprint(key)?.label ?? null;

/**
 * Submits a blueprint gesture op through the mutation queue.
 *
 * The response's materials delta carries the saved blueprint's RECORD as
 * an upsert (the op queue has already applied it to the store by the time
 * the payload resolves, so the Add panel item and the Update verb see it);
 * `warnings` carries capture warnings (inexpressible style keys the
 * capture dropped).
 *
 * @param {Object} operation
 *   The save_material / update_material operation (kind blueprint).
 * @param {string} label
 *   The history-entry label for the gesture.
 *
 * @return {Promise<Object>}
 *   Resolves with {blueprint, warnings}: the blueprint record (null when
 *   the delta carried none) and the warning messages.
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
    blueprint:
      (payload.materials?.upsert ?? []).find(
        (record) => record.kind === 'blueprint',
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
 * capture dropped).
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
 * references render as checked checkboxes (data-blueprint-adopt), and
 * commitSave() folds the picked ones into the op's adopt_variants.
 */
const openSaveDialog = (key) => {
  const { ModePluginBase, Blueprint } = Drupal.Daedalus;
  const context = Blueprint.rootContext();
  pendingGesture = { key };
  ModePluginBase.dialog({
    url: ModePluginBase.url('/daedalus-layout/blueprint-save-dialog', {
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
 * arrive on a blueprint), plus the target blueprint reference so the
 * confirm copy names it.
 */
const openUpdateDialog = (key) => {
  const target = updatableBlueprint(key);
  if (!target) {
    return;
  }
  const { ModePluginBase, Blueprint } = Drupal.Daedalus;
  const context = Blueprint.rootContext();
  pendingGesture = { key, target };
  ModePluginBase.dialog({
    url: ModePluginBase.url('/daedalus-layout/blueprint-update-dialog', {
      element: uuidOf(key),
      blueprint: target.id,
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
  ['daedalus-layout-blueprint-label', 'daedalus-layout-blueprint-description']
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
 * The save and update dialogs render the same data-blueprint-adopt
 * contract; the checked variants become the blueprint's own, offered only
 * on its placements from now on.
 */
const adoptPicks = () =>
  [...document.querySelectorAll('.daedalus-layout-blueprint-save [data-blueprint-adopt]')]
    .filter((input) => input.checked)
    .map((input) => input.getAttribute('data-blueprint-adopt'));

/**
 * The icon pick off the open save dialog, or '' (no picker, or unset).
 *
 * The dialog's picker (BlueprintSaveDialog::iconField(), driven by
 * IconPickerOpener + icon-picker.js) only ever writes `media:<uuid>` into
 * its hidden input, exactly the spelling save_material accepts.
 */
const iconPick = () =>
  document
    .querySelector('.daedalus-layout-blueprint-save [data-daedalus-layout-icon-picker-value]')
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
  const label = document.getElementById('daedalus-layout-blueprint-label').value.trim();
  const description = document
    .getElementById('daedalus-layout-blueprint-description')
    .value.trim();
  const adopt = adoptPicks();
  const icon = iconPick();

  button.disabled = true;
  submitGesture(
    {
      op: 'save_material',
      kind: 'blueprint',
      element: uuidOf(key),
      label,
      description,
      ...(adopt.length ? { adopt_variants: adopt } : {}),
      ...(icon ? { icon } : {}),
    },
    Drupal.t('Saved blueprint'),
  )
    .then((result) => {
      pendingGesture = null;
      closeDialog();
      // The Add panel item and the just-saved element's Update verb already
      // read the record: the response's delta landed in the store before
      // the mutation resolved.
      // A page capture is page scope: the canvas does not offer it, the
      // delta lands no record here, and the notice names the label the
      // dialog took.
      report(
        isRootKey(key)
          ? Drupal.t('Saved the page as the blueprint "@label".', {
              '@label': label,
            })
          : Drupal.t(
              'Saved as the blueprint "@label". It is now available in the Add panel.',
              { '@label': result.blueprint?.label ?? label },
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
 * blueprint as a new revision (one revert away; workspace-staged on
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
      kind: 'blueprint',
      id: target.id,
      element: uuidOf(key),
      ...(adopt.length ? { adopt_variants: adopt } : {}),
    },
    Drupal.t('Updated blueprint'),
  )
    .then((result) => {
      pendingGesture = null;
      closeDialog();
      report(
        Drupal.t('Updated the blueprint "@label".', {
          '@label': result.blueprint?.label ?? target.label,
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
  const save = event.target.closest('[data-blueprint-save]');
  if (save) {
    event.preventDefault();
    commitSave(save);
    return;
  }
  const update = event.target.closest('[data-blueprint-update]');
  if (update) {
    event.preventDefault();
    commitUpdate(update);
    return;
  }
  if (event.target.closest('[data-blueprint-cancel]')) {
    event.preventDefault();
    pendingGesture = null;
    closeDialog();
  }
});

// Closing the dialog without committing is a cancel. Only the blueprint
// dialog's own close counts: dialog events dispatch on the dialog element
// and bubble, and the icon picker opens the media library as a SECOND
// modal whose close must not cancel the pending gesture.
window.addEventListener('dialog:afterclose', (event) => {
  if (
    event.target instanceof Element &&
    event.target.querySelector('.daedalus-layout-blueprint-save')
  ) {
    pendingGesture = null;
  }
});

// Phosphor icons (currentColor, like the other verb icons): bookmark for
// saving a reusable piece, arrow-circle-up for pushing an update into one,
// arrow-square-out for opening the blueprint's own canvas.
const icons = {
  save: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M184,32H72A16,16,0,0,0,56,48V224a8,8,0,0,0,12.24,6.78L128,193.43l59.77,37.35A8,8,0,0,0,200,224V48A16,16,0,0,0,184,32Z"></path></svg>',
  update:
    '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm45.66,109.66a8,8,0,0,1-11.32,0L136,107.31V168a8,8,0,0,1-16,0V107.31l-26.34,26.35a8,8,0,0,1-11.32-11.32l40-40a8,8,0,0,1,11.32,0l40,40A8,8,0,0,1,173.66,133.66Z"></path></svg>',
  open: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M224,104a8,8,0,0,1-16,0V59.32l-66.33,66.34a8,8,0,0,1-11.32-11.32L196.68,48H152a8,8,0,0,1,0-16h64a8,8,0,0,1,8,8Zm-40,24a8,8,0,0,0-8,8v72H48V80h72a8,8,0,0,0,0-16H48A16,16,0,0,0,32,80V208a16,16,0,0,0,16,16H176a16,16,0,0,0,16-16V136A8,8,0,0,0,184,128Z"></path></svg>',
  // Phosphor link-break: the live reference cut, the copy left in place.
  detach:
    '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M96,56V24a8,8,0,0,1,16,0V56a8,8,0,0,1-16,0Zm64,152v24a8,8,0,0,0,16,0V208a8,8,0,0,0-16,0ZM56,96H24a8,8,0,0,0,0,16H56a8,8,0,0,0,0-16Zm176,56H200a8,8,0,0,0,0,16h32a8,8,0,0,0,0-16Zm-58.34-8.34a8,8,0,0,0,11.32,0l24-24a40,40,0,0,0-56.57-56.57l-24,24a8,8,0,0,0,11.32,11.32l24-24a24,24,0,0,1,33.94,33.94l-24,24A8,8,0,0,0,173.66,143.66ZM82.34,112.34l-24,24a40,40,0,0,0,56.57,56.57l24-24a8,8,0,0,0-11.32-11.32l-24,24a24,24,0,0,1-33.94-33.94l24-24a8,8,0,0,0-11.32-11.32Z"></path></svg>',
};

/**
 * Whether a key is a linked placement: a block node showing a blueprint
 * by reference (the mirror's `{kind: linked}`).
 */
const isLinkedNode = (key) =>
  key.startsWith('block:') && nodeOf(key)?.kind === 'linked';

/**
 * Registers the blueprint verbs into the edit-mode app.
 *
 * @param {object} app
 *   The edit-mode app registration facade.
 */
export const registerBlueprintVerbs = (app) => {
  app.verbs.register({
    id: 'daedalus_layout.open_blueprint',
    label: Drupal.t('Open blueprint'),
    // The verb names the blueprint the join shows, or the one a detached
    // placement was placed from.
    menuLabel: (key) => {
      const label = openLabel(key);
      return label
        ? Drupal.t("Open blueprint '@label'", { '@label': label })
        : Drupal.t('Open blueprint');
    },
    icon: icons.open,
    // A join is a block: a linked placement (showing the blueprint by
    // reference) or a slot door (showing its default). A detached
    // placement is a container (roots are containers) carrying provenance.
    kinds: ['block', 'container'],
    // Opening the blueprint is editing its configuration elsewhere; the
    // actor's right to the blueprint itself is the showing's on a join
    // (the portal rides only when the blueprint may be updated) and the
    // canvas route's on a detached placement (the record carries no grant).
    capability: 'configure',
    applies: (key) => !!openUrl(key),
    // One control with Update and Save as on a placed blueprint; Update
    // (25) leads it, so Open sits behind the caret.
    group: 'daedalus_layout.blueprint',
    weight: 26,
    // Opening the blueprint leaves this page for a foreign host's canvas:
    // the one leave every such gesture takes (Drupal.Daedalus.Portal.leave,
    // selection-facade.js), which waits for writes still on the wire and
    // carries this page as the way back, so Save and Discard on the
    // blueprint return here instead of stranding the editor there.
    run: (key) => {
      const url = openUrl(key);
      if (url) {
        Drupal.Daedalus.Portal.leave(url);
      }
    },
  });

  app.verbs.register({
    id: 'daedalus_layout.detach_blueprint',
    label: Drupal.t('Detach'),
    menuLabel: (key) => {
      const name = nodeOf(key)?.blueprint;
      const record = name ? Drupal.Daedalus.Materials.get('blueprint', name) : null;
      return record
        ? Drupal.t("Detach from blueprint '@label'", { '@label': record.label })
        : Drupal.t('Detach');
    },
    icon: icons.detach,
    kinds: ['block'],
    // The node is the page's to rearrange; detaching replaces it in place
    // with the copy it showed (the nested batch judges that insert too).
    capability: 'arrange',
    applies: isLinkedNode,
    // The group's lightest member on a linked node: the split control's
    // main half, Open blueprint behind its caret.
    group: 'daedalus_layout.blueprint',
    weight: 24,
    run: (key) => {
      const { Blueprint } = Drupal.Daedalus;
      const context = Blueprint.rootContext();
      if (!context) {
        return;
      }
      Blueprint.mutate({
        ...context,
        operations: [{ op: 'detach_blueprint', element: uuidOf(key) }],
        label: Drupal.t('Detached blueprint'),
      }).catch((error) => {
        Blueprint.reportFailure(error, key);
      });
    },
  });

  app.verbs.register({
    id: 'daedalus_layout.update_material',
    label: Drupal.t('Update blueprint'),
    // The verb names its target per the provenance rule: "Update blueprint
    // 'X'" when the placement's stamp resolves to a content blueprint.
    menuLabel: (key) => {
      const target = updatableBlueprint(key);
      return target
        ? Drupal.t("Update blueprint '@label'", { '@label': target.label })
        : Drupal.t('Update blueprint');
    },
    icon: icons.update,
    kinds: ['container'],
    // A capture reads the element's configuration into the material; an
    // element whose configuration is not the actor's to touch (a
    // template's, on a plain host) is not theirs to capture either.
    capability: 'configure',
    applies: (key) => canSave() && !!updatableBlueprint(key),
    // The group's lightest member: the split control's main half.
    group: 'daedalus_layout.blueprint',
    weight: 25,
    run: openUpdateDialog,
  });

  app.verbs.register({
    id: 'daedalus_layout.save_material',
    label: Drupal.t('Save as blueprint…'),
    // On a placed blueprint the verb sits beside Update, so it says what
    // it does there: a NEW blueprint, not an update of the placed one.
    menuLabel: (key) =>
      updatableBlueprint(key)
        ? Drupal.t('Save as new blueprint…')
        : Drupal.t('Save as blueprint…'),
    icon: icons.save,
    kinds: ['container'],
    capability: 'configure',
    // Save-as-new is ALWAYS available on containers (sections at any
    // depth) when the user may save blueprints, even while Update shows.
    applies: (key) => canSave() && !!capturableElement(key),
    // The group's heaviest member: last in the caret menu.
    group: 'daedalus_layout.blueprint',
    weight: 27,
    run: openSaveDialog,
  });

  app.verbs.register({
    id: 'daedalus_layout.save_page_material',
    label: Drupal.t('Save page as blueprint…'),
    icon: icons.save,
    kinds: ['entity'],
    // The capture reads the whole document's configuration; a host that
    // does not own its document (a page rendering its template) is
    // refused configure on its root, so the gate keeps the verb off it.
    capability: 'configure',
    // The top bar's Save caret lists it (daedalus_ui's document menu).
    document: true,
    applies: (key) => canSave() && isRootKey(key) && hostIsPage(),
    weight: 28,
    run: openSaveDialog,
  });
};
