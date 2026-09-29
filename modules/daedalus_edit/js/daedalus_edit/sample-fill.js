/**
 * @file
 * Fill with sample: the spine's `_sample` request on an empty field.
 *
 * The client-side successor of the form's server-built "Add field"
 * button: an empty subject field (the field-state judge's WIDGET_SAMPLE,
 * field-state.js) offers to be filled with a generated value, and the
 * fill is a spine op, `update_element` with `{field: {"_sample": true}}`
 * (the reserved underscore key), resolved server-side through the sample
 * ladder's request entrance (the field's default value, then the site's
 * configured field_sample_value generator, whose answer is final even when
 * empty, then core's type-level sample only when no generator is
 * configured) into the same ExtractedValue a form submit hands the spine.
 * The request means "make one up", so unlike a palette drop it may invent
 * a value for a field with no policy; a field the ladder cannot fill fails
 * with NO_SAMPLE_VALUE and the widget stays. Labeled, undoable, AI-usable,
 * and never in disagreement with the widget beside it: the response's
 * markup swap materializes the field's canvas leaf and the judge hides the
 * widget on the next attach.
 *
 * Two doors, one op: the Fill with sample verb on the field's selection
 * (registered in daedalus-edit-plugin.js) and the button the panel
 * renders on the empty widget's row (panel-widget-visibility.js).
 */

/**
 * The reserved key of a sample request.
 */
export const SAMPLE_KEY = '_sample';

/**
 * Fills a subject field with a sample value through the spine.
 *
 * @param {string} key
 *   The field's session key: `field:{root}:{f}` or `block:{c}:{f}`.
 *
 * @return {Promise<Object>}
 *   The op-queue promise; failures are already reported.
 */
export const fillWithSample = (key) => {
  const { Blueprint } = Drupal.Daedalus;
  const [, uuid, fieldName] = key.split(':');
  const context = Blueprint.rootContext();
  if (!context || !uuid || !fieldName) {
    throw new Error(`Daedalus Edit: cannot fill "${key}" with a sample.`);
  }
  const request = Blueprint.mutate({
    ...context,
    operations: [
      {
        op: 'update_element',
        element: uuid,
        properties: { [fieldName]: { [SAMPLE_KEY]: true } },
      },
    ],
    label: Drupal.t('Filled @label with a sample', {
      '@label': Blueprint.labelForKey(key),
    }),
  });
  request.catch((error) => {
    Blueprint.reportFailure(error, key);
  });
  return request;
};
