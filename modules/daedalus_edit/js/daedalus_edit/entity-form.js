import * as editableElement from './editable-element.js';

/**
 * Get the Layout Builder block form.
 *
 * Fetches the placement's block form (UpdateBlockFormForEditTool) into the
 * panel's form cache. The path carries placement identity only (storage +
 * component uuid); the server derives the section coordinates from the
 * storage and responds with the same open contract as the entity form
 * endpoint (an AppendCommand into the form-cache mount), so no command
 * rewriting happens here.
 *
 * @param {EditableElement} EditableElement
 *   The element info object.
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
const getBlockForm = (EditableElement) => {
  return new Promise((resolve, reject) => {
    const pageElement = EditableElement.getPageElement();
    const storageInfo =
      Drupal.Daedalus.ModeManager.getPlugin('edit').getSectionStorageInfo(
        pageElement,
      );
    const path = `/daedalus-edit/block-form/${storageInfo.storageType}/${storageInfo.storageId}/${storageInfo.blockUuid}`;

    EditableElement.info.ajaxReturnForm = true;
    const ajax = Drupal.Daedalus.ModePluginBase.ajax({
      url: path,
      type: 'GET',
      dataType: 'text',
      submit: EditableElement.info,
      progress: {
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
    EditableElement.info.ajaxReturnForm = false;
  });
};

/**
 * Fetch an entity form into the panel's form cache.
 *
 * The one fetch both doors share: the form engine's leaf-driven load
 * (getEntityForm) and the panel's own top-level load (fetchMainEntityForm).
 * The response's AppendCommand lands the wrapped form in the form-cache
 * mount; the caller decides what to do with it (typically make it the
 * panel's subject).
 *
 * @param {string} entityTypeId
 *   The entity type id.
 * @param {string} entityId
 *   The entity id.
 * @param {Object} submit
 *   The POST payload (must carry viewMode; ajaxReturnForm makes the server
 *   respond with the append command).
 * @param {boolean} quiet
 *   Skip the fullscreen progress indicator (background loads the user did
 *   not ask for, e.g. the subject revert).
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
const fetchEntityForm = (entityTypeId, entityId, submit, quiet = false) => {
  return new Promise((resolve, reject) => {
    const ajax = Drupal.Daedalus.ModePluginBase.ajax({
      url: `/daedalus-edit/entity-form/${entityTypeId}/${entityId}`,
      type: 'POST',
      dataType: 'text',
      submit,
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
 * Get Entity Form.
 *
 * @param {EditableElement} EditableElement
 *   The element info object.
 *
 * @return {Promise<unknown>}
 *   A promise to retrieve the form.
 */
const getEntityForm = (EditableElement) => {
  EditableElement.info.ajaxReturnForm = true;
  const fetch = fetchEntityForm(
    EditableElement.info.entityTypeId,
    EditableElement.info.entityId,
    EditableElement.info,
  );
  EditableElement.info.ajaxReturnForm = false;
  return fetch;
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
    status = await getBlockForm(EditableElement);
  } else if (endpoint === 'entity-form') {
    status = await getEntityForm(EditableElement);
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
 * Fetch the TOP-LEVEL entity's form — the panel's default subject.
 *
 * The icon door and the subject revert have no EditableElement: the fetch
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
  const wrapper = document.querySelector(
    '[data-main-entity][data-daedalus-ui-entity-wrapper]',
  );
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
    {
      viewMode: wrapper.dataset.daedalusUiViewMode,
      ajaxReturnForm: true,
    },
    quiet,
  );
};

/**
 * Flag that the page is currently being updated.
 *
 * @see Drupal.Daedalus.Edit.notUpdating
 *
 * @type {boolean}
 */
export let daedalusEditIsUpdating = false;

/**
 * Get background color.
 *
 *   The element to get the background color for.
 *
 * @return {string}
 *   The inherited background color of this element.
 */
const getBackgroundColor = (element) => {
  let currentElement = element;
  while (currentElement) {
    const bgColor = window.getComputedStyle(currentElement).backgroundColor;
    if (bgColor !== 'rgba(0, 0, 0, 0)' && bgColor !== 'transparent') {
      return bgColor;
    }
    currentElement = currentElement.parentElement;
  }

  return 'white';
};

/**
 * Get text color for background.
 *
 * We don't want to have black text on a black background or other similarly
 * un-contrasty situations.
 *
 *   The background color the text will appear on.
 *
 * @return {string}
 *   white or black, which would be a better choice given the background color.
 */
const getTextColorForBackground = (backgroundColor) => {
  // Extract the RGB values from the string.
  const rgb = backgroundColor.match(/\d+/g);
  const [r, g, b] = rgb
    ? [parseInt(rgb[0], 10), parseInt(rgb[1], 10), parseInt(rgb[2], 10)]
    : [255, 255, 255];
  // Calculate the luminance of the color.
  const a = [r, g, b].map((v) => {
    v /= 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  const luminance = a[0] * 0.2126 + a[1] * 0.7152 + a[2] * 0.0722;

  return luminance < 0.5 ? 'white' : 'black';
};

/**
 * Update tempstore.
 *
 * @param {EditableElement} EditableElement
 *   The form item whose form needs updating.
 * @param isEmpty
 *   Whether the field has been emptied or not. Emptied fields are moved to the
 *   sidebar.
 * @param form
 *   Pass the form element directly when an EditableElement isn't available,
 *   but you still need to submit the form like when a sidebar form item changes.
 * @param onlyUpdateElement
 *   Pass the element to update when an EditableElement isn't available,
 *   but you still need to submit the form like when a sidebar form item changes.
 */
export const updateTempstore = async (
  EditableElement,
  isEmpty,
  form = null,
  onlyUpdateElement = null,
) => {
  if (updateTempstore.alreadyUpdating) {
    return;
  }
  if (!EditableElement && Drupal.Daedalus.Edit.CurrentlyEditingElement) {
    // We are saving something other than an EditableElement while an
    // EditableElement is focused. Probably something in the sidebar. Let's
    // blur the CurrentlyEditingElement to get any changes from it.
    // Flag that we are already updating as blur can call updateTempstore again.
    updateTempstore.alreadyUpdating = true;
    try {
      await Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    } finally {
      updateTempstore.alreadyUpdating = false;
    }
  }

  if (!form) {
    form = EditableElement.getFormItemWrapper().closest('form');
  }

  // Move emptied fields to the sidebar.
  if (EditableElement && isEmpty) {
    form.querySelector('#empty-field').value = EditableElement.info.fieldName;
  }

  // Update the hidden view mode input in order to persist the view mode across
  // multiple form submissions as it could be changing in this update.
  let viewMode;
  if (EditableElement) {
    // I think we can standardize on getting the view mode from the form > entity
    // wrapper relationship detailed in the else, but we might as well get it
    // from EditableElement when it's available as it's a tiny bit faster.
    viewMode = EditableElement.info.viewMode;

    // Disable editing the item while it's being updated.
    const pageElementWrapper = EditableElement.getPageElementWrapper();
    pageElementWrapper.classList.add('disabled-updating');
    // Add an AJAX throbber to indicate the item is being updated. The
    // throbber is a SIBLING of the wrapper, never a child: the wrapper can
    // be an <svg> root (the image block's vector lane), and HTML inserted
    // inside an svg parses in the SVG namespace and renders nothing.
    // 'afterend' parses in the HTML parent's context, and the overlay
    // takes its geometry from the wrapper's rect (svg-safe) instead of
    // absolute positioning within it.
    const throbber = Drupal.theme.ajaxProgressThrobber(Drupal.t('Updating...'));
    pageElementWrapper.insertAdjacentHTML('afterend', throbber);
    const progress = pageElementWrapper.nextElementSibling;
    progress.classList.add('daedalus-edit-element-throbber');
    const rect = pageElementWrapper.getBoundingClientRect();
    progress.style.top = `${rect.top}px`;
    progress.style.left = `${rect.left}px`;
    progress.style.width = `${rect.width}px`;
    progress.style.height = `${rect.height}px`;
    setTimeout(() => {
      const backgroundColor = getBackgroundColor(pageElementWrapper);
      progress.style.backgroundColor = backgroundColor;
      progress.style.color = getTextColorForBackground(backgroundColor);
      progress.style.opacity = '1';
    }, 50);
    // Flag to hide the submit handler ajax throbber in the sidebar.
    document.querySelector('body').classList.add('element-ajax-throbbing');
    // Flag to only update the rendered output of this field, not the entire
    // entity. This allows users to continue editing other items on the page.
    form.querySelector('.daedalus-edit-only-update-element').value =
      EditableElement.getPageElement().dataset.daedalusEditId;
  } else {
    // EditableElement won't be available when submitting the form via DaedalusEditEntityFormAutoSave.
    // @todo review if this entityWrapper getting works. It seems like it shouldn't.
    const entityWrapper = document.querySelector(
      `[data-daedalus-ui-entity-wrapper^="${
        form.closest('.daedalus-edit-form').dataset.daedalusEditFormId
      }"]`,
    );
    viewMode = entityWrapper.dataset.daedalusUiViewMode;
    // Flag to update the render output of the entire entity.
    form.querySelector('.daedalus-edit-only-update-element').value =
      onlyUpdateElement;
  }
  // Update the hidden view mode input in order to persist the view mode across
  // multiple form submissions as it could be changing in this update.
  form.querySelector('.daedalus-edit-view-mode').value = viewMode;

  // Click the update button (formerly the save submit).
  const updateButton = form.querySelector('.daedalus-edit-update-button');
  jQuery(updateButton).mousedown();
  daedalusEditIsUpdating = true;
};

/**
 * Daedalus Edit is done updating.
 *
 * AJAX callback that flags that the update is complete.
 */
jQuery.fn.daedalusEditIsDoneUpdating = () => {
  daedalusEditIsUpdating = false;
  document.querySelector('body').classList.remove('element-ajax-throbbing');
  // The throbber is a sibling of the updated element's wrapper (see
  // updateTempstore), so the response's markup swap does not take it out
  // with the wrapper; remove any stragglers explicitly.
  document
    .querySelectorAll('.daedalus-edit-element-throbber')
    .forEach((element) => element.remove());
};

/**
 * Clear form.
 *
 * Evicts a cached entity form so the next edit fetches fresh values.
 * Removal routes through the sidebar plugin's subject-death entry point: an
 * evicted form that was the OPEN panel's subject reverts the panel to the
 * top-level entity. Manually re-attaching behaviors prevents the automatic
 * detachBehaviors that RemoveCommand would trigger without a subsequent
 * attachBehaviors.
 */
jQuery.fn.daedalusEditClearForm = (selector) => {
  const element = document.querySelector(selector);
  if (element) {
    Drupal.Daedalus.SidebarManager.getPlugin('daedalus_edit_form').removeForm(
      element,
    );
    Drupal.attachBehaviors();
    Drupal.displace();
  }
};

/**
 * Handle validation error.
 *
 * AJAX callback for when the form is submitted, but there are errors. Re-edit
 * the field that had errors.
 *
 * @param elementId
 *   The element ID of the form item or form item wrapper that has an error
 *   and needs to be edited.
 */
jQuery.fn.epHandleValidationError = (elementId) => {
  try {
    const EditableElement = new editableElement.EditableElement(elementId);
    EditableElement.setWidget();
    const pageElementWrapper = EditableElement.getPageElementWrapper();

    // Remove spinner and disabled state. The throbber is a sibling of the
    // wrapper, not a child (see updateTempstore).
    document
      .querySelectorAll('.daedalus-edit-element-throbber')
      .forEach((element) => element.remove());
    pageElementWrapper.classList.remove('disabled-updating');

    const body = document.querySelector('body');
    if (body) {
      body.classList.remove('element-ajax-throbbing');
    }
    EditableElement.plugin.edit(EditableElement);

    // Set up server-side validation clearing after field is re-opened.
    const inputs = EditableElement.getFormItemInputs();
    if (inputs && inputs.length > 0) {
      inputs.forEach((input) => {
        EditableElement.plugin.clearValidationMessageOnUserInput(
          input,
          EditableElement.plugin.removeServersideValidationMessage.bind(
            EditableElement.plugin,
          ),
        );
      });
    }
  } catch (error) {
    console.error('Error handling validation error:', error);
  }
};

(($, Drupal, once) => {
  /**
   * Daedalus Edit entity form auto save.
   *
   * Auto saves form items in the sidebar when the user changes its value.
   */
  Drupal.behaviors.DaedalusEditEntityFormAutoSave = {
    attach: (context, settings) => {
      once(
        'DaedalusEditEntityFormAutoSave',
        '[data-daedalus-edit-auto-submit]',
        context,
      ).forEach((formItem) => {
        formItem.addEventListener('change', (e) => {
          const input = e.target;
          // The nearest auto-submit element owns the change, judged at
          // event time rather than from the once record: auto-submit
          // elements nest (a revealed field wrapper around an advanced
          // pane's own auto-submit child), and the visibility behavior
          // withdraws a wrapper's attribute when its field gains a canvas
          // leaf (the leaf's editor owns the widget then), which once
          // cannot unbind.
          if (input.closest('[data-daedalus-edit-auto-submit]') !== formItem) {
            return;
          }
          const onlyUpdateElement = input.dataset.daedalusEditUpdateElement ?? null;
          updateTempstore(
            null,
            false,
            input.closest('form'),
            onlyUpdateElement,
          );
        });
      });
    },
  };
})(jQuery, Drupal, once);
