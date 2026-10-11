/**
 * @file
 * A blueprint row's gestures: Edit details…, Delete…, and the blueprint
 * half of Favorite for everyone (site-favorite.js dispatches to it).
 *
 * All appear in the row menu only on updatable blueprints while the user
 * holds the grant (drupalSettings.daedalusLayout.canSaveBlueprints, the
 * any-type chrome; the routes enforce by the blueprint's type for real).
 * File-shipped blueprints get none (absent, not disabled).
 *
 * Both gestures POST to daedalus_blueprint_library's dedicated CSRF
 * routes, deliberately OFF the op spine: blueprint deletion is site-wide
 * destructive content with no save/discard net, and details-update is
 * metadata, not capture. Their responses carry the materials delta like
 * every write, and are handed to Blueprint.applyResponse() so this window
 * and every paired one learn the change through the store: the row
 * relabels, repaints, moves set or leaves when its record does. Each
 * post names the canvas it is made from (the root entity's entity_type
 * and entity_id, the coordinates the dialog routes carry through
 * ModePluginBase.url()), so the server resolves the delta for THIS page:
 * a details change that ends the blueprint's fit here comes back as a
 * remove and the row leaves the open panel at once.
 *
 * Edit details… reuses the save-as-blueprint dialog pattern (inert
 * server-rendered markup, same field ids and styling;
 * BlueprintSaveDialog::details()), prefills the label and description
 * client-side from the record, and reads the icon, the subject select,
 * the allow-list, the group checkboxes and the favorite box the server
 * prefilled from the blueprint.
 *
 * The listeners this file installs are delegated on the document and
 * inert unless a details gesture is pending: install them once, at the
 * bundle's boot (installBlueprintGestures).
 */

/**
 * Whether the user may save blueprints (the panel's grant; chrome only).
 */
export const canSaveBlueprints = () =>
  !!window.drupalSettings?.daedalusLayout?.canSaveBlueprints;

const blueprintRecord = (id) => Drupal.Daedalus.Materials.get('blueprint', id);

// ——— The gesture HTTP client (the variants-api pattern). ———

let tokenPromise = null;

/**
 * The session's CSRF token, fetched once (the blueprint gestures' routes
 * and the block type favorite's route all take it in the header).
 */
export const csrfToken = () => {
  tokenPromise =
    tokenPromise ??
    fetch(Drupal.url('session/token')).then((response) => response.text());
  return tokenPromise;
};

/**
 * The canvas coordinates a gesture names: the page's root entity, as the
 * dialog routes carry it, or nothing off an editable page.
 */
const canvasCoordinates = () => {
  const context = Drupal.Daedalus.Blueprint.rootContext();
  return context
    ? { entity_type: context.entityType, entity_id: context.entityId }
    : {};
};

/**
 * POSTs a JSON body to a URL with the session's CSRF token.
 *
 * Failures reject with an Error carrying the server's own message and
 * hint (the blueprint {path, code, message, hint} error shape, which the
 * block type favorite's route answers too), so callers relay the refusal
 * verbatim (failureMessage).
 *
 * @param {string} url
 *   The absolute URL.
 * @param {object} payload
 *   The body.
 *
 * @return {Promise<object>}
 *   The decoded response body.
 */
export const postJson = async (url, payload = {}) => {
  const token = await csrfToken();
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': token,
    },
    body: JSON.stringify(payload),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw refusalError(body, response.status);
  }
  return body;
};

/**
 * A refused response as an Error carrying the server's own message and
 * hint (the blueprint {path, code, message, hint} error shape every
 * gesture route, the block type favorite's route and the peek door
 * answer), so callers relay the refusal verbatim (failureMessage).
 *
 * @param {object} body
 *   The decoded response body ({errors: [...]}), or {} when it was none.
 * @param {number} status
 *   The HTTP status, for the sentence when the body names nothing.
 *
 * @return {Error}
 */
export const refusalError = (body, status) => {
  const first = body?.errors?.[0];
  const error = new Error(
    first?.message ??
      Drupal.t('The request failed (@status).', { '@status': status }),
  );
  error.hint = first?.hint ?? null;
  return error;
};

/**
 * POSTs to a blueprint gesture route, naming the canvas on the query.
 */
const post = (path, payload = {}) =>
  postJson(Drupal.Daedalus.ModePluginBase.url(path, canvasCoordinates()), payload);

/**
 * A refusal as one sentence for the editor: the message, then the hint.
 */
export const failureMessage = (error) =>
  [error.message, error.hint].filter(Boolean).join(' ');

/**
 * Reports through the one edit-mode message display (toast +
 * notifications sidebar).
 */
export const report = (text, type) => {
  Drupal.Daedalus.ModeManager.getPlugin('edit').message(text, type);
};

// ——— Edit details… (the save-dialog pattern, prefilled). ———

// The details gesture awaiting the dialog's inputs; null when no dialog is
// open. Uses its own data attributes (data-blueprint-details-*) so the
// save-as-blueprint listener in js/verbs/blueprint.js never cross-fires.
let pendingDetails = null;

const openDetailsDialog = (id) => {
  const { ModePluginBase } = Drupal.Daedalus;
  const record = blueprintRecord(id);
  pendingDetails = {
    id,
    label: record?.label ?? '',
    description: record?.description ?? '',
  };
  ModePluginBase.dialog({
    // The blueprint reference rides the query so the server can render
    // what the client cannot prefill from the record: the icon picker
    // showing the stored icon (scoped to this blueprint as the media
    // library opener's access subject), the read-only scope, the subject
    // select, the allow-list, the groups and the favorite.
    url: ModePluginBase.url('/daedalus-layout/blueprint-details-dialog', {
      blueprint: id,
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
 * Commits the details dialog: POST the new face and what the blueprint
 * is material for, apply the response (its materials delta relabels and
 * repaints the row through the store) on success, report the server's
 * refusal through the one message display on failure (the dialog stays
 * open for the correction).
 */
const commitDetails = (button) => {
  const id = pendingDetails?.id;
  if (!id || !inputsValid()) {
    return;
  }
  const dialog = document.querySelector('.daedalus-layout-blueprint-details');
  const label = document.getElementById('daedalus-layout-blueprint-label').value.trim();
  const description = document
    .getElementById('daedalus-layout-blueprint-description')
    .value.trim();
  const payload = { label, description };
  // Icon, subject, hosts, groups and the favorite ride the
  // presence-semantics contract: a section the server rendered sends
  // its value (the icon's `media:<uuid>` writes, null clears; the
  // subject select's value; the checked hosts, [] for any; the checked
  // groups, [] for none; the favorite box's state); an absent section
  // (no media library, an unresolved blueprint, a site with no groups)
  // omits its key and the stored value stands.
  const iconInput = dialog?.querySelector(
    '[data-daedalus-layout-icon-picker-value]',
  );
  if (iconInput) {
    payload.icon = iconInput.value.trim() || null;
  }
  const subject = document.getElementById('daedalus-layout-blueprint-subject');
  if (subject) {
    payload.subject = subject.value;
  }
  const hosts = dialog ? [...dialog.querySelectorAll('[data-blueprint-bundle]')] : [];
  if (hosts.length) {
    payload.bundles = hosts
      .filter((input) => input.checked)
      .map((input) => input.getAttribute('data-blueprint-bundle'));
  }
  const groups = dialog ? [...dialog.querySelectorAll('[data-blueprint-group]')] : [];
  if (groups.length) {
    payload.groups = groups
      .filter((input) => input.checked)
      .map((input) => input.getAttribute('data-blueprint-group'));
  }
  const favorite = dialog?.querySelector('[data-blueprint-favorite]');
  if (favorite) {
    payload.favorite = favorite.checked;
  }
  button.disabled = true;
  post(
    `/daedalus-blueprint/blueprint/${encodeURIComponent(id)}/details`,
    payload,
  )
    .then((body) => {
      pendingDetails = null;
      closeDialog();
      Drupal.Daedalus.Blueprint.applyResponse(body);
      report(
        Drupal.t('Updated the blueprint "@label".', {
          '@label': body.blueprint.label,
        }),
        'status',
      );
    })
    .catch((error) => {
      button.disabled = false;
      report(failureMessage(error), 'error');
    });
};

// ——— Favorite for everyone (the details route, one key). ———

/**
 * Writes a blueprint's site default favorite through the details route.
 *
 * The same door Edit details… commits through, under its presence
 * semantics: the body carries `favorite` alone, so every other detail
 * stands. The response's materials delta repaints the row through the
 * store (the record's `favorite` moves, and with it the row's set), as
 * every blueprint write does.
 *
 * @param {string} id
 *   The blueprint record's id.
 * @param {boolean} favorite
 *   The new site default.
 *
 * @return {Promise<object>}
 *   The response body (its `blueprint` names the label).
 */
export const setBlueprintSiteFavorite = (id, favorite) =>
  post(`/daedalus-blueprint/blueprint/${encodeURIComponent(id)}/details`, {
    favorite,
  }).then((body) => {
    Drupal.Daedalus.Blueprint.applyResponse(body);
    return body;
  });

// ——— Delete… ———

/**
 * Deletes a blueprint; the response's delta removes the row through the
 * store, and dead provenance degrades a placed copy's "Update blueprint
 * 'X'" verb back to plain Save as blueprint.
 */
const deleteBlueprint = (id) => {
  post(`/daedalus-blueprint/blueprint/${encodeURIComponent(id)}/delete`)
    .then((body) => {
      Drupal.Daedalus.Blueprint.applyResponse(body);
      report(
        Drupal.t('Deleted the blueprint "@label".', {
          '@label': body.deleted.label,
        }),
        'status',
      );
    })
    .catch((error) => {
      report(failureMessage(error), 'error');
    });
};

// ——— The menu items. ———

// Phosphor icons (currentColor, the verb-icon convention): pencil-simple
// for details, trash for delete.
const icons = {
  pencil:
    '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M227.31,73.37,182.63,28.68a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96a16,16,0,0,0,0-22.63ZM92.69,208H48V163.31l88-88L180.69,120ZM192,108.68,147.31,64l24-24L216,84.68Z"></path></svg>',
  trash:
    '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M216,48H180V36A28,28,0,0,0,152,8H104A28,28,0,0,0,76,36V48H40a12,12,0,0,0,0,24h4V208a20,20,0,0,0,20,20H192a20,20,0,0,0,20-20V72h4a12,12,0,0,0,0-24ZM100,36a4,4,0,0,1,4-4h48a4,4,0,0,1,4,4V48H100Zm88,168H68V72H188ZM116,104v64a12,12,0,0,1-24,0V104a12,12,0,0,1,24,0Zm48,0v64a12,12,0,0,1-24,0V104a12,12,0,0,1,24,0Z"></path></svg>',
};

/**
 * The menu items a blueprint record offers, or [] (file-shipped and
 * unknown blueprints are absent-not-disabled).
 *
 * @param {object} record
 *   The materials record (the face's `blueprint`).
 *
 * @return {Array<object>}
 *   Items in the shared menu primitive's grammar.
 */
export const blueprintMenuItems = (record) => {
  if (!canSaveBlueprints() || !record?.updatable) {
    return [];
  }
  const { id } = record;
  return [
    {
      label: Drupal.t('Edit details…'),
      icon: icons.pencil,
      run: () => openDetailsDialog(id),
    },
    {
      label: Drupal.t('Delete…'),
      icon: icons.trash,
      danger: true,
      // The in-menu two-step: the first activation arms, the second runs.
      confirm: Drupal.t('Confirm delete?'),
      run: () => deleteBlueprint(id),
    },
  ];
};

/**
 * Installs the details dialog's delegated listeners, once.
 */
export const installBlueprintGestures = () => {
  // Prefill once the dialog markup lands (dialogs dispatch native
  // CustomEvents on the dialog element; js/verbs/blueprint.js already
  // listens for afterclose the same way). The guard class keeps this away
  // from the save-as dialog, which shares the field ids but must open
  // empty; the event-target guard keeps it away from OTHER dialogs opening
  // while the details dialog is up (the icon picker's media library
  // modal), whose aftercreate must not reset the user's edits.
  window.addEventListener('dialog:aftercreate', (event) => {
    if (
      !pendingDetails ||
      !(event.target instanceof Element) ||
      !event.target.querySelector('.daedalus-layout-blueprint-details')
    ) {
      return;
    }
    const label = document.getElementById('daedalus-layout-blueprint-label');
    const description = document.getElementById('daedalus-layout-blueprint-description');
    if (label) {
      label.value = pendingDetails.label;
    }
    if (description) {
      description.value = pendingDetails.description;
    }
  });

  // One delegated listener serves the dialog: inert unless a details
  // gesture is pending and the event lands on its buttons.
  document.addEventListener('click', (event) => {
    if (!pendingDetails || !(event.target instanceof Element)) {
      return;
    }
    const save = event.target.closest('[data-blueprint-details-save]');
    if (save) {
      event.preventDefault();
      commitDetails(save);
      return;
    }
    if (event.target.closest('[data-blueprint-details-cancel]')) {
      event.preventDefault();
      pendingDetails = null;
      closeDialog();
    }
  });

  // Closing the dialog without saving is a cancel. Only the details
  // dialog's own close counts: the icon picker's media library modal
  // closing must not cancel the pending gesture.
  window.addEventListener('dialog:afterclose', (event) => {
    if (
      event.target instanceof Element &&
      event.target.querySelector('.daedalus-layout-blueprint-details')
    ) {
      pendingDetails = null;
    }
  });
};
