/**
 * Warn (edit mode only) when an expected entity UI element is missing.
 *
 * The editing UI's element lookups ([data-main-entity], the entity wrapper
 * id) silently no-op when the attributes never reached the page, e.g. an
 * entity template that does not print {{ attributes }} on its outermost
 * element, or markup replaced by something that dropped them. Silent no-ops
 * are how integrations break invisibly, so the miss is reported. Edit mode
 * only: outside it the attributes are legitimately absent.
 *
 * @param {string} consumer
 *   The lookup site, e.g. 'rootContext'.
 * @param {string} missing
 *   What was not found, e.g. '[data-main-entity]'.
 */
export const warnMissingEntityUi = (consumer, missing) => {
  if (
    document.documentElement.getAttribute('data-daedalus-ui-mode') !==
    'edit'
  ) {
    return;
  }
  console.warn(
    `Daedalus UI ${consumer}: ${missing} not found on the page. The entity UI attributes ride the entity template's root element; ensure the template prints {{ attributes }} on its outermost element.`,
  );
};
