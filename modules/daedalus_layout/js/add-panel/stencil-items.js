/**
 * The Add panel's stencil items: rendered from the materials store, with
 * their context menu (Edit details… and Delete…).
 *
 * Stencils are materials. The panel's block items are server-rendered
 * (they project block plugin definitions), but its stencil items project
 * the client materials store (Drupal.Daedalus.Materials, kind
 * 'stencil'): one renderer, driven by the store's boot and by every delta
 * any lane lands (this window's save-as-stencil, the details and delete
 * routes below, an AI turn's save_material, another window's relay). No
 * optimistic patch and no reload: the item appears, relabels, repaints or
 * disappears when its record does. The record carries everything an item
 * needs: label, description (the tooltip), the icon RESOLVED to a URL
 * (the provider resolves media references server-side; null paints the
 * panel's default icon), root_kind (a section-rooted stencil is a
 * draggable-section riding the section caret, a block-rooted one rides
 * block gaps), origin (shipped | user | ai, stamped on the item) and
 * updatable (content stencils; file-shipped ones are read-only in every
 * UI).
 *
 * The context menu is the panel's adapter over the shared menu primitive
 * (daedalus_ui/menu). Item SOURCES stay per noun-space: the
 * panel's stencils are never the selection, so no verb registry is
 * involved; this file supplies the two items directly. Both appear only
 * on updatable stencils while the user holds the "save stencils" grant
 * (drupalSettings.daedalusLayout.canSaveStencils, honest chrome; the routes
 * enforce for real). File-shipped stencils get NO items (absent, not
 * disabled) and with no items menu.open() declines, so the native
 * browser menu survives.
 *
 * Both gestures POST to daedalus_blueprint_stencil's dedicated CSRF routes,
 * deliberately OFF the op spine: stencil deletion is site-wide
 * destructive content with no save/discard net, and details-update is
 * metadata, not capture. Their responses carry the materials delta like
 * every write, and are handed to Blueprint.applyResponse() so this window
 * and every paired one learn the change through the store.
 *
 * Edit details… reuses the save-as-stencil dialog pattern (inert
 * server-rendered markup, same field ids and styling; StencilSaveDialog::
 * details()) and prefills client-side from the record.
 */

window.DaedalusUiQueue = window.DaedalusUiQueue || [];
window.DaedalusUiQueue.push(() => {
  const panelElement = () => document.getElementById('place_block');

  /**
   * The promoted tab's list, where the stencil items live, or null.
   */
  const listElement = () => document.getElementById('promoted-block-content');

  /**
   * Whether the user may save stencils (the panel's grant; chrome only).
   */
  const canSave = () => !!window.drupalSettings?.daedalusLayout?.canSaveStencils;

  const stencilRecords = () =>
    Drupal.Daedalus.Materials.ofKind('stencil');

  const stencilRecord = (id) =>
    Drupal.Daedalus.Materials.get('stencil', id);

  // ——— The items. ———

  const stencilItems = (content) =>
    Array.from(content.querySelectorAll('[data-stencil-id]'));

  /**
   * Paints one item from its record: the same face PlaceBlock renders for
   * a promoted block (daedalus-ui-panel-item with an image and a label), plus the
   * stencil facts the drag sources and the menu read.
   */
  const paint = (item, record, defaultIcon) => {
    item.className = `${record.root_kind === 'section' ? 'draggable-section' : 'draggable-block'} daedalus-ui-panel-item`;
    item.title = record.description;
    item.dataset.origin = record.origin;
    const image = item.querySelector('.daedalus-ui-panel-item__image');
    const src = record.icon ?? defaultIcon;
    image.style.backgroundImage = src ? `url('${src}')` : '';
    item.querySelector('.daedalus-ui-panel-item__label').textContent = record.label;
  };

  const createItem = (record) => {
    const item = document.createElement('div');
    // The id PlaceBlock's block items follow ("stencil:ID" beside plugin
    // ids); add-panel.js reads data-stencil-id to route the drop through
    // instantiate_stencil. Roving tabindex (add-panel.js seats the stop).
    item.id = `stencil:${record.id}`;
    item.draggable = true;
    item.tabIndex = -1;
    item.setAttribute('role', 'button');
    item.dataset.stencilId = record.id;
    const image = document.createElement('div');
    image.className = 'daedalus-ui-panel-item__image';
    const label = document.createElement('div');
    label.className = 'daedalus-ui-panel-item__label';
    item.append(image, label);
    return item;
  };

  /**
   * Reconciles the list's stencil items with the store: items keyed by
   * stencil id are updated in place (a relabel or repaint never loses
   * focus or the roving stop), missing ones are created, gone ones are
   * removed, and the run sits after the block items in roster order.
   */
  const render = () => {
    const content = listElement();
    if (!content) {
      return;
    }
    const existing = new Map(
      stencilItems(content).map((item) => [item.dataset.stencilId, item]),
    );
    const records = stencilRecords();
    const defaultIcon = content.dataset.defaultIcon ?? null;
    const wanted = new Set(records.map((record) => record.id));
    let removedStop = false;
    existing.forEach((item, id) => {
      if (!wanted.has(id)) {
        removedStop = removedStop || item.tabIndex === 0;
        item.remove();
        existing.delete(id);
      }
    });
    // Stencils follow the block items: the last child that is not a
    // stencil item anchors the run.
    let anchor =
      Array.from(content.children).findLast(
        (child) => !child.dataset.stencilId,
      ) ?? null;
    let created = false;
    records.forEach((record) => {
      let item = existing.get(record.id);
      if (!item) {
        item = createItem(record);
        created = true;
      }
      paint(item, record, defaultIcon);
      const expected = anchor ? anchor.nextSibling : content.firstChild;
      if (item !== expected) {
        content.insertBefore(item, expected);
      }
      anchor = item;
    });
    if (removedStop) {
      // The removed item held the panel's one roving Tab stop
      // (add-panel.js seats it); reseat it so the panel stays tabbable.
      const next = panelElement()?.querySelector(
        '.draggable-block, .draggable-section',
      );
      if (next) {
        next.tabIndex = 0;
      }
    }
    if (created) {
      // The drag behaviors are once()-guarded, so only the new items get
      // drag-wired.
      Drupal.attachBehaviors(content, window.drupalSettings);
    }
  };

  render();
  Drupal.Daedalus.Materials.onChange(render);
  // The panel can be re-delivered with the page (an editable-page
  // re-render replaces the markup); a fresh list renders the store again.
  Drupal.behaviors.DaedalusLayoutStencilItems = {
    attach: (context) => {
      once('daedalus-layout-stencil-items', '#promoted-block-content', context).forEach(
        render,
      );
    },
  };

  // ——— The gesture HTTP client (the variants-api pattern). ———

  let tokenPromise = null;
  const csrfToken = () => {
    tokenPromise =
      tokenPromise ??
      fetch(Drupal.url('session/token')).then((response) => response.text());
    return tokenPromise;
  };

  /**
   * POSTs to a stencil gesture route.
   *
   * Failures reject with an Error carrying the server's own message and
   * hint (the blueprint {path, code, message, hint} error shape), so
   * callers relay the refusal verbatim.
   */
  const post = async (path, payload = {}) => {
    const token = await csrfToken();
    const response = await fetch(Drupal.url(path), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: JSON.stringify(payload),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const first = body?.errors?.[0];
      const error = new Error(
        first?.message ??
          Drupal.t('The request failed (@status).', {
            '@status': response.status,
          }),
      );
      error.hint = first?.hint ?? null;
      throw error;
    }
    return body;
  };

  const failureMessage = (error) =>
    [error.message, error.hint].filter(Boolean).join(' ');

  /**
   * Reports through the one edit-mode message display (toast +
   * notifications sidebar).
   */
  const report = (text, type) => {
    Drupal.Daedalus.ModeManager.getPlugin('edit').message(text, type);
  };

  // ——— Edit details… (the save-dialog pattern, prefilled). ———

  // The details gesture awaiting the dialog's inputs; null when no dialog
  // is open. Uses its own data attributes (data-stencil-details-*) so the
  // save-as-stencil listener in js/verbs/stencil.js never cross-fires.
  let pendingDetails = null;

  const openDetailsDialog = (id) => {
    const { ModePluginBase } = Drupal.Daedalus;
    const record = stencilRecord(id);
    pendingDetails = {
      id,
      label: record?.label ?? '',
      description: record?.description ?? '',
    };
    ModePluginBase.dialog({
      // The stencil reference rides the query so the server can render the
      // icon picker prefilled with the stored icon (the one detail the
      // client cannot prefill) and scoped to this stencil as the media
      // library opener's access subject.
      url: ModePluginBase.url('/daedalus-layout/stencil-details-dialog', {
        stencil: id,
      }),
      width: 480,
      message: Drupal.t('Loading...'),
    });
  };

  // Prefill once the dialog markup lands (dialogs dispatch native
  // CustomEvents on the dialog element; js/verbs/stencil.js already
  // listens for afterclose the same way). The guard class keeps this away
  // from the save-as dialog, which shares the field ids but must open
  // empty; the event-target guard keeps it away from OTHER dialogs opening
  // while the details dialog is up (the icon picker's media library
  // modal), whose aftercreate must not reset the user's edits.
  window.addEventListener('dialog:aftercreate', (event) => {
    if (
      !pendingDetails ||
      !(event.target instanceof Element) ||
      !event.target.querySelector('.daedalus-layout-stencil-details')
    ) {
      return;
    }
    const label = document.getElementById('daedalus-layout-stencil-label');
    const description = document.getElementById('daedalus-layout-stencil-description');
    if (label) {
      label.value = pendingDetails.label;
    }
    if (description) {
      description.value = pendingDetails.description;
    }
  });

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
   * Commits the details dialog: POST the new label/description, apply the
   * response (its materials delta relabels and repaints the item through
   * the store) on success, report the server's refusal through the one
   * message display on failure (the dialog stays open for the correction).
   */
  const commitDetails = (button) => {
    const id = pendingDetails?.id;
    if (!id || !inputsValid()) {
      return;
    }
    const label = document.getElementById('daedalus-layout-stencil-label').value.trim();
    const description = document
      .getElementById('daedalus-layout-stencil-description')
      .value.trim();
    // Icon rides the presence-semantics contract: with a picker in the
    // dialog, send its current value (`media:<uuid>` writes, null clears);
    // without one (no media library, unresolved stencil), omit the key and
    // the stored icon stands.
    const iconInput = document.querySelector(
      '.daedalus-layout-stencil-details [data-daedalus-layout-icon-picker-value]',
    );
    const payload = { label, description };
    if (iconInput) {
      payload.icon = iconInput.value.trim() || null;
    }
    button.disabled = true;
    post(`daedalus-blueprint/stencil/${encodeURIComponent(id)}/details`, payload)
      .then((body) => {
        pendingDetails = null;
        closeDialog();
        Drupal.Daedalus.Blueprint.applyResponse(body);
        report(
          Drupal.t('Updated the stencil "@label".', {
            '@label': body.stencil.label,
          }),
          'status',
        );
      })
      .catch((error) => {
        button.disabled = false;
        report(failureMessage(error), 'error');
      });
  };

  // One delegated listener serves the dialog: inert unless a details
  // gesture is pending and the event lands on its buttons.
  document.addEventListener('click', (event) => {
    if (!pendingDetails || !(event.target instanceof Element)) {
      return;
    }
    const save = event.target.closest('[data-stencil-details-save]');
    if (save) {
      event.preventDefault();
      commitDetails(save);
      return;
    }
    if (event.target.closest('[data-stencil-details-cancel]')) {
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
      event.target.querySelector('.daedalus-layout-stencil-details')
    ) {
      pendingDetails = null;
    }
  });

  // ——— Delete… ———

  /**
   * Deletes a stencil; the response's delta removes the item through the
   * store, and dead provenance degrades a placed copy's "Update stencil
   * 'X'" verb back to plain Save as stencil.
   */
  const deleteStencil = (id) => {
    post(`daedalus-blueprint/stencil/${encodeURIComponent(id)}/delete`)
      .then((body) => {
        Drupal.Daedalus.Blueprint.applyResponse(body);
        report(
          Drupal.t('Deleted the stencil "@label".', {
            '@label': body.deleted.label,
          }),
          'status',
        );
      })
      .catch((error) => {
        report(failureMessage(error), 'error');
      });
  };

  // ——— The menu itself. ———

  // Phosphor icons (currentColor, the verb-icon convention): pencil-simple
  // for details, trash for delete.
  const icons = {
    pencil:
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M227.31,73.37,182.63,28.68a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96a16,16,0,0,0,0-22.63ZM92.69,208H48V163.31l88-88L180.69,120ZM192,108.68,147.31,64l24-24L216,84.68Z"></path></svg>',
    trash:
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M216,48H180V36A28,28,0,0,0,152,8H104A28,28,0,0,0,76,36V48H40a12,12,0,0,0,0,24h4V208a20,20,0,0,0,20,20H192a20,20,0,0,0,20-20V72h4a12,12,0,0,0,0-24ZM100,36a4,4,0,0,1,4-4h48a4,4,0,0,1,4,4V48H100Zm88,168H68V72H188ZM116,104v64a12,12,0,0,1-24,0V104a12,12,0,0,1,24,0Zm48,0v64a12,12,0,0,1-24,0V104a12,12,0,0,1,24,0Z"></path></svg>',
  };

  /**
   * The items a stencil panel item offers, or [] (file-shipped and unknown
   * stencils are absent-not-disabled: no items, no menu, native
   * right-click).
   */
  const menuItems = (item) => {
    const id = item.dataset.stencilId;
    const record = stencilRecord(id);
    if (!canSave() || !record?.updatable) {
      return [];
    }
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
        run: () => deleteStencil(id),
      },
    ];
  };

  const openStencilMenu = (item, x, y) =>
    Drupal.Daedalus.menu.open({ x, y, items: menuItems(item) });

  // Delegated so it survives the panel's re-renders. preventDefault only
  // when a menu actually opened (the primitive's no-items contract).
  document.addEventListener('contextmenu', (event) => {
    if (!(event.target instanceof Element)) {
      return;
    }
    const item = event.target.closest('#place_block [data-stencil-id]');
    if (!item) {
      return;
    }
    if (openStencilMenu(item, event.clientX, event.clientY)) {
      event.preventDefault();
      event.stopPropagation();
    }
  });

  // The keyboard opener on a focused stencil item (Shift+F10 / the menu
  // key), at the item's rect — the tree-row parity gesture. The primitive
  // restores the item's focus on keyboard dismissal.
  document.addEventListener('keydown', (event) => {
    if (
      event.key !== 'ContextMenu' &&
      !(event.shiftKey && event.key === 'F10')
    ) {
      return;
    }
    if (!(event.target instanceof Element)) {
      return;
    }
    const item = event.target.closest('#place_block [data-stencil-id]');
    if (!item) {
      return;
    }
    const rect = item.getBoundingClientRect();
    if (openStencilMenu(item, rect.left, rect.bottom)) {
      event.preventDefault();
      event.stopPropagation();
    }
  });
});
