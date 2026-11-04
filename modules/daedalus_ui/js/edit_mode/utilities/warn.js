/**
 * Warn (edit mode only) when an expected entity UI element is missing.
 *
 * The editing UI's element lookups ([data-main-entity], the entity wrapper
 * id) silently no-op when the attributes never reached the page, e.g. an
 * entity template that does not print {{ attributes }} on its outermost
 * element, or markup replaced by something that dropped them. Silent no-ops
 * are how integrations break invisibly, so the miss is reported through
 * Drupal.throwError (rethrown outside the call stack: the caller's fallback
 * still runs, the error still surfaces). Edit mode only: outside it the
 * attributes are legitimately absent.
 *
 * @param {string} consumer
 *   The lookup site, e.g. 'rootContext'.
 * @param {string} missing
 *   What was not found, e.g. '[data-main-entity]'.
 */
export const warnMissingEntityUi = (consumer, missing) => {
  if (
    document.documentElement.getAttribute('data-daedalus-ui-mode') !== 'edit'
  ) {
    return;
  }
  Drupal.throwError(
    new Error(
      `Daedalus UI ${consumer}: ${missing} not found on the page. The entity UI attributes ride the entity template's root element; ensure the template prints {{ attributes }} on its outermost element.`,
    ),
  );
};

/**
 * Warn when the blueprint draft names a key the mirror does not hold.
 *
 * The draft slot (blueprint-slice.js) carries an open gesture's resolved
 * markup for one selectable key, and the effective tree
 * (blueprint/effective-blueprint.js) lays it over that key's node. A key
 * the lean tree no longer holds (the edited node left the page under a
 * foreign mutation, and the resumed gesture re-previewed) cannot be
 * overlaid, so the readers fall back to the committed mirror. Silent
 * fallback would hide a gesture whose preview reaches the canvas and not
 * the model, so the miss is reported through Drupal.throwError (the
 * fallback still runs); the effective tree memoizes on the (mirror, draft)
 * pair, so it is reported once per draft, not per read.
 *
 * @param {string} key
 *   The drafted selectable key.
 */
export const warnDraftKeyUnknown = (key) => {
  Drupal.throwError(
    new Error(
      `Daedalus UI effectiveBlueprint: the draft names ${key}, which the blueprint mirror does not hold; readers see the committed tree until the next preview or mirror replacement.`,
    ),
  );
};
