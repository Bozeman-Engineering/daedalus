import { placementFor } from './panel-items.js';

/**
 * @file
 * Fetching entity forms into the panel's form cache: the hosting half.
 *
 * The entity form is a widget host and value extractor, never a write
 * path: every write leaves it through the form transport (transports.js)
 * and the op spine. What lives here is how a subject's form gets onto the
 * page: the entity-form endpoint (the root, any entity by type and id)
 * and the block-form endpoint (a Layout Builder placement's
 * PanelUpdateBlockForm), both answering the same open contract (an
 * AppendCommand into the form-cache mount), and the refetch that replaces
 * a cached form with a fresh one when a response left it stale.
 */

/**
 * Fetch an entity form into the panel's form cache.
 *
 * The one fetch both doors share: the form engine's leaf-driven load
 * (getForm) and the panel's own top-level load (fetchMainEntityForm).
 * The response's AppendCommand lands the wrapped form in the form-cache
 * mount; the caller decides what to do with it (typically make it the
 * panel's subject).
 *
 * @param {string} entityTypeId
 *   The entity type id.
 * @param {string} entityId
 *   The entity id.
 * @param {string} viewMode
 *   The view mode the canvas shows the entity in.
 * @param {boolean} quiet
 *   Skip the fullscreen progress indicator (background loads the user did
 *   not ask for, e.g. a refetch).
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
const fetchEntityForm = (entityTypeId, entityId, viewMode, quiet = false) => {
  return new Promise((resolve, reject) => {
    // The view mode rides the URL's query, not the POST body: core copies
    // the fetch request's query onto every #ajax URL the form builds, so a
    // widget's own rounds (remove, add more, a media library insert) and
    // the form transport's commits all carry it, and the server never has
    // to remember it across the form cache (which does not exist until a
    // form's first rebuild).
    const ajax = Drupal.Daedalus.ModePluginBase.ajax({
      url: `/daedalus-edit/entity-form/${entityTypeId}/${entityId}?viewMode=${encodeURIComponent(viewMode)}`,
      type: 'POST',
      dataType: 'text',
      submit: { ajaxReturnForm: true },
      progress: quiet
        ? { type: 'none' }
        : {
            type: 'fullscreen',
            message: Drupal.t('Loading form...'),
          },
      error: (error) => {
        console.error('Entity form load: ', error.responseText || error);
        Drupal.Daedalus.ModeManager.getPlugin('edit').handleError(
          error,
          'Unable to load the entity form.',
        );
        reject(error);
      },
      success: (response, status) => {
        Promise.resolve(
          Drupal.Ajax.prototype.success.call(ajax, response, status),
        ).then(() => {
          resolve({ status: 'new_form' });
        });
      },
    });
    ajax.execute();
  });
};

/**
 * Fetch a Layout Builder placement's block form into the form cache.
 *
 * The path carries placement identity only (storage + component uuid,
 * from the placement's Layout Builder attribution); the server derives
 * the section coordinates from the storage and responds with the same
 * open contract as the entity form endpoint.
 *
 * @param {Element} element
 *   The placement, or any element inside it.
 * @param {string} viewMode
 *   The view mode the block's content renders in.
 * @param {boolean} quiet
 *   Skip the fullscreen progress indicator.
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
export const fetchBlockForm = (element, viewMode, quiet = false) => {
  return new Promise((resolve, reject) => {
    const storageInfo =
      Drupal.Daedalus.ModeManager.getPlugin('edit').getSectionStorageInfo(
        element,
      );
    if (!storageInfo?.blockUuid) {
      reject(
        new Error(
          'Daedalus Edit: the element is not inside a Layout Builder placement to fetch a block form for.',
        ),
      );
      return;
    }
    const path = `/daedalus-edit/block-form/${storageInfo.storageType}/${storageInfo.storageId}/${storageInfo.blockUuid}`;

    // The view mode on the query, as for the entity form (see
    // fetchEntityForm).
    const ajax = Drupal.Daedalus.ModePluginBase.ajax({
      url: `${path}?viewMode=${encodeURIComponent(viewMode)}`,
      type: 'GET',
      dataType: 'text',
      submit: { ajaxReturnForm: true },
      progress: quiet
        ? { type: 'none' }
        : {
            type: 'fullscreen',
            message: Drupal.t('Loading form...'),
          },
      error: (error) => {
        console.error('Block form load: ', error.responseText || error);
        Drupal.Daedalus.ModeManager.getPlugin('edit').handleError(
          error,
          'Unable to load the block form.',
        );
        reject(error);
      },
      success: (response, status) => {
        Promise.resolve(
          Drupal.Ajax.prototype.success.call(ajax, response, status),
        ).then(() => {
          resolve({ status: 'new_form' });
        });
      },
    });
    ajax.execute();
  });
};

/**
 * Get form.
 *
 * @param {EditableElement} EditableElement
 *   The element info object.
 * @return {Promise<unknown>}
 *   A promise that retrieves either an Entity Form, or a Layout Builder (block)
 *   entity update form. If the form has already been fetched then it reveals
 *   the appropriate form.
 */
export const getForm = async (EditableElement) => {
  const plugin =
    Drupal.Daedalus.SidebarManager.getPlugin('daedalus_edit_form');

  // A cached form: retarget the panel's subject and open it. openOn()
  // handles the (side, slot) exclusivity — a different bottom-slot panel is
  // evicted by the manager, and top-slot panels (e.g. the Styles inspector)
  // stay open because slots stack.
  const existing = EditableElement.getForm();
  if (existing) {
    await plugin.openOn(existing);
    return { status: 'existing_form' };
  }

  // Fetch a new form into the panel's form cache. The leaf's attribution
  // declares its form endpoint kind: a placement that edits through the
  // Layout Builder block form wears data-daedalus-edit-form-endpoint="block-form"
  // on its block wrapper (the composed root for composed blocks), stamped
  // server-side by BlockFormEndpoint. A leaf resolves its endpoint from the
  // closest declaring ancestor; undeclared leaves ride the entity form.
  const pageElement = EditableElement.getPageElement();
  const endpoint =
    pageElement.closest('[data-daedalus-edit-form-endpoint]')?.dataset
      .daedalusEditFormEndpoint ?? 'entity-form';
  let status;
  if (endpoint === 'block-form') {
    status = await fetchBlockForm(pageElement, EditableElement.info.viewMode);
  } else if (endpoint === 'entity-form') {
    status = await fetchEntityForm(
      EditableElement.info.entityTypeId,
      EditableElement.info.entityId,
      EditableElement.info.viewMode,
    );
  } else {
    throw new Error(`Daedalus Edit: unknown form endpoint kind "${endpoint}".`);
  }

  const fetched = EditableElement.getForm();
  if (!fetched) {
    throw new Error(
      'Daedalus Edit: the fetched entity form did not arrive in the form cache.',
    );
  }
  await plugin.openOn(fetched);
  return status;
};

/**
 * The main entity wrapper, or null.
 *
 * @return {?Element}
 */
const mainEntityWrapper = () =>
  document.querySelector('[data-main-entity][data-daedalus-ui-entity-wrapper]');

/**
 * Fetch the TOP-LEVEL entity's form — the panel's default subject.
 *
 * The icon door and the boot restore have no EditableElement: the fetch
 * identity comes from the main entity wrapper's canonical id and recorded
 * view mode instead. Exposed on Drupal.Daedalus.Edit (daedalus-edit.js) for the
 * sidebar plugin, which lives outside this ES-module graph.
 *
 * @param {Object} options
 *   - quiet: skip the fullscreen progress indicator.
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
export const fetchMainEntityForm = ({ quiet = false } = {}) => {
  const wrapper = mainEntityWrapper();
  if (!wrapper) {
    return Promise.reject(
      new Error(
        'Daedalus Edit: no main entity wrapper to fetch the top-level entity form for.',
      ),
    );
  }
  const [entityTypeId, entityId] =
    wrapper.dataset.daedalusUiEntityWrapper.split('::');
  return fetchEntityForm(
    entityTypeId,
    entityId,
    wrapper.dataset.daedalusUiViewMode,
    quiet,
  );
};

/**
 * Fetch the form for a cached-form identity ("{type}::{id}").
 *
 * The refetch's fetch: the top-level entity through the entity-form
 * endpoint, a placed block's content through the block-form endpoint
 * (found by the placement rendering that entity). Exposed on
 * Drupal.Daedalus.Edit for the sidebar plugin.
 *
 * @param {string} formId
 *   The form identity.
 * @param {Object} options
 *   - quiet: skip the fullscreen progress indicator.
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
export const fetchFormFor = (formId, { quiet = false } = {}) => {
  const [entityTypeId, entityId] = formId.split('::');
  const main = mainEntityWrapper();
  if (
    main &&
    main.dataset.daedalusUiEntityWrapper.startsWith(
      `${entityTypeId}::${entityId}::`,
    )
  ) {
    return fetchEntityForm(
      entityTypeId,
      entityId,
      main.dataset.daedalusUiViewMode,
      quiet,
    );
  }
  const placement = placementFor(entityTypeId, entityId);
  if (!placement) {
    return Promise.reject(
      new Error(
        `Daedalus Edit: no placement on the page renders ${formId}; its form cannot be fetched.`,
      ),
    );
  }
  const wrapper = placement.matches('[data-daedalus-ui-entity-wrapper]')
    ? placement
    : placement.querySelector('[data-daedalus-ui-entity-wrapper]');
  return fetchBlockForm(placement, wrapper.dataset.daedalusUiViewMode, quiet);
};

/**
 * Replaces a cached form with a freshly fetched one.
 *
 * The client-side successor of server-side form eviction: a mutation
 * response whose targets name a field the panel shows (a foreign write,
 * an AI turn, an undo) leaves the cached form's widget stale, and a
 * canceled panel-widget edit leaves its staged state unknowable, so the
 * form is fetched again. The sidebar plugin owns the cache and the
 * subject, so the swap is its.
 *
 * @param {Element} form
 *   The cached form wrapper (.daedalus-edit-form).
 *
 * @return {Promise<Element>}
 *   Resolves with the fresh form element.
 */
export const refetchForm = (form) =>
  Drupal.Daedalus.SidebarManager.getPlugin('daedalus_edit_form').refetch(form);
