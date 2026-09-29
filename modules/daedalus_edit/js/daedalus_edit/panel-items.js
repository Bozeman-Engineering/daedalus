import { fetchBlockForm } from './entity-form.js';

/**
 * @file
 * The entity-form panel as seen by the edit session's engine: the panel's
 * revealed widgets as keyed session items, the mirror value a widget's
 * key names, and the subject a selectable key names.
 *
 * The panel shows its SUBJECT's cached form (daedalus-edit-sidebar.js),
 * and panel-widget-visibility.js reveals exactly the wrappers whose field
 * has no canvas leaf. Those revealed wrappers are the session's panel
 * items: the far end of the same Tab walk as the canvas leaves, each keyed
 * the way the spine addresses the field, `field:{root}:{f}` for the root
 * entity's fields and `block:{c}:{f}` (`block:{c}:label` for the block
 * label pseudo-field) for a block component's content. The key is derived
 * from the wrapper's form identity ("{entity_type}::{id}::{field}::...")
 * and the mirror's root envelope or the placement's DOM addressing, so a
 * widget and a leaf of one field always agree on the key. A widget whose
 * entity is neither the root nor a placed block (a paragraph's, a
 * subject the spine does not address yet) has no key and is no item.
 */

const PANEL_ID = 'daedalus_edit_entity_form';
const MOUNT_ID = 'daedalus-edit-form-cache';

/**
 * The class of the Fill with sample button the visibility judge prepends
 * to an empty widget's row (panel-widget-visibility.js).
 */
export const SAMPLE_FILL_CLASS = 'daedalus-edit-sample-fill';

/**
 * The controls of a widget, inside its form-item wrapper.
 *
 * The Fill with sample button is the judge's chrome on the row, not a
 * control of the widget: the editor's entry focus and the Tab edge walk
 * the widget's own controls, never the pill prepended beside them. A
 * hidden input is no control either (the `changed` timestamp
 * ContentEntityForm carries under a field name).
 */
export const WIDGET_CONTROL_SELECTOR = [
  'input:not([type="hidden"])',
  'select',
  'textarea',
  `button:not(.${SAMPLE_FILL_CLASS})`,
  '[contenteditable="true"]',
].join(', ');

/**
 * A wrapper's enabled widget controls, document order.
 *
 * @param {Element} wrapper
 *   A form-item wrapper.
 *
 * @return {Element[]}
 */
export const widgetControls = (wrapper) =>
  Array.from(wrapper.querySelectorAll(WIDGET_CONTROL_SELECTOR)).filter(
    (control) => !control.disabled,
  );

/**
 * The form-cache mount, or null.
 *
 * @return {?Element}
 */
export const formCacheMount = () => document.getElementById(MOUNT_ID);

/**
 * Whether the panel is open (rendered and not hidden).
 *
 * @return {boolean}
 */
const panelOpen = () => {
  const panel = document.getElementById(PANEL_ID);
  return !!panel && !panel.classList.contains('daedalus-ui-hidden');
};

/**
 * The mirror's root envelope when it names an entity.
 *
 * @param {string} entityTypeId
 *   The entity type id.
 * @param {string} entityId
 *   The entity id.
 *
 * @return {?object}
 *   The root envelope, or null when the entity is not the root.
 */
const rootFor = (entityTypeId, entityId) => {
  const root = Drupal.Daedalus.Blueprint.getRoot();
  return root &&
    root.entityType === entityTypeId &&
    String(root.entityId) === String(entityId)
    ? root
    : null;
};

/**
 * The placement wrapper (`[data-selectable="block:{uuid}"]`) rendering an
 * entity as a block component's content, or null. The placement carries
 * (or contains) the entity's wrapper identity "{type}::{id}::{bundle}";
 * the trailing separator keeps "block_content::5" from matching
 * "block_content::55".
 *
 * @param {string} entityTypeId
 *   The entity type id.
 * @param {string} entityId
 *   The entity id.
 *
 * @return {?Element}
 */
export const placementFor = (entityTypeId, entityId) =>
  document
    .querySelector(
      `[data-daedalus-ui-entity-wrapper^="${entityTypeId}::${entityId}::"]`,
    )
    ?.closest('[data-selectable^="block:"]') ?? null;

/**
 * The session key for a revealed panel widget's field.
 *
 * @param {Element} wrapper
 *   A form-item wrapper carrying data-daedalus-edit-form-item-wrapper-id.
 *
 * @return {?string}
 *   `field:{root}:{f}` or `block:{c}:{f}`, or null when the widget's
 *   entity is neither the root nor a placed block.
 */
export const panelItemKey = (wrapper) => {
  const [entityTypeId, entityId, fieldName] =
    wrapper.dataset.daedalusEditFormItemWrapperId.split('::');
  const root = rootFor(entityTypeId, entityId);
  if (root) {
    return `field:${root.uuid}:${fieldName}`;
  }
  const placement = placementFor(entityTypeId, entityId);
  if (!placement) {
    return null;
  }
  const uuid = placement.getAttribute('data-selectable').split(':')[1];
  return `block:${uuid}:${fieldName}`;
};

/**
 * The mirror value a session key names.
 *
 * The field-state judge's first input (field-state.js): the root entity's
 * `fields[name]`, a block placement's `block_fields[name]`, the
 * placement's own `label` for the label pseudo-field. Undefined when the
 * mirror has no node for the field (a non-processable field the form's
 * advanced panes edit), which the judge reads as present, or before the
 * mirror boots.
 *
 * @param {?string} key
 *   A session key.
 *
 * @return {*}
 */
export const mirrorValueForKey = (key) => {
  const parts = typeof key === 'string' ? key.split(':') : [];
  if (parts.length !== 3) {
    return undefined;
  }
  const [kind, uuid, name] = parts;
  const { Blueprint } = Drupal.Daedalus;
  const blueprint = Blueprint.getBlueprint();
  if (kind === 'field') {
    const root = Blueprint.getRoot();
    return root && root.uuid === uuid ? blueprint?.fields?.[name] : undefined;
  }
  if (kind !== 'block') {
    return undefined;
  }
  const component = Blueprint.componentForUuid(blueprint, uuid);
  if (!component) {
    return undefined;
  }
  return name === 'label' ? component.label : component.block_fields?.[name];
};

/**
 * Whether a wrapper's field has a leaf on the canvas.
 *
 * The field-state judge's second input: a
 * [data-daedalus-edit-field-value-wrapper] outside the form-cache mount (a
 * render inside a cached form, a widget's own entity preview, is not the
 * canvas) whose id opens with the wrapper's `{type}::{id}::{field}::`
 * prefix; the leaf's id ends in the field's main property, as the
 * wrapper's does.
 *
 * @param {Element} wrapper
 *   A form-item wrapper carrying data-daedalus-edit-form-item-wrapper-id.
 *
 * @return {boolean}
 *   True when at least one canvas leaf carries the field's identity.
 */
export const hasCanvasLeaf = (wrapper) => {
  const mount = formCacheMount();
  const prefix = wrapper.dataset.daedalusEditFormItemWrapperId
    .split('::')
    .slice(0, 3)
    .join('::');
  return [
    ...document.querySelectorAll(
      `[data-daedalus-edit-field-value-wrapper^="${prefix}::"]`,
    ),
  ].some((leaf) => !mount || !mount.contains(leaf));
};

/**
 * The panel's items: the subject form's revealed widgets in form order,
 * keyed. Empty while the panel is hidden (a hidden widget is no Tab stop)
 * or shows no subject. A revealed wrapper with no widget control is no
 * item: nothing in it can take the edit (the hidden `changed` input, a
 * language widget whose select the site withholds), so a Tab stop there
 * would be a stop on nothing.
 *
 * @return {{item: Element, key: ?string}[]}
 */
export const panelItems = () => {
  const mount = formCacheMount();
  if (!mount || !panelOpen()) {
    return [];
  }
  const form = mount.querySelector(':scope > .daedalus-edit-form--subject');
  if (!form) {
    return [];
  }
  return Array.from(
    form.querySelectorAll(
      '.daedalus-edit-form-item-wrapper[data-daedalus-edit-form-item-wrapper-id]:not(.daedalus-edit-hidden)',
    ),
  )
    .filter((item) => widgetControls(item).length > 0)
    .map((item) => ({ item, key: panelItemKey(item) }));
};

/**
 * The session keys of every field a cached form carries a wrapper for,
 * hidden or revealed: the fields whose widget that form holds, and so
 * the fields a foreign write leaves stale in it.
 *
 * @param {Element} form
 *   A cached form wrapper (.daedalus-edit-form).
 *
 * @return {string[]}
 */
export const formFieldKeys = (form) =>
  Array.from(
    form.querySelectorAll(
      '.daedalus-edit-form-item-wrapper[data-daedalus-edit-form-item-wrapper-id]',
    ),
  )
    .map(panelItemKey)
    .filter(Boolean);

/**
 * Whether an element is one of the panel's widget items.
 *
 * @param {Element} element
 *   The element.
 *
 * @return {boolean}
 */
export const isPanelItem = (element) =>
  element.hasAttribute('data-daedalus-edit-form-item-wrapper-id');

/**
 * Shows the subject a selectable key names in the panel.
 *
 * The root entity (its own key or one of its field keys) is the panel's
 * top-level subject; a block placement (`block:{uuid}` or one of its
 * field keys) is the placement's content entity, whose form is fetched
 * through the block-form endpoint when it is not cached yet; a field
 * block placement (stamped with the root field it presents,
 * data-presented-field) is the root's. The panel opens on the subject
 * through the sidebar plugin's form-path door (openOn), which is a no-op
 * when it already shows it.
 *
 * @param {string} key
 *   A selectable key.
 *
 * @return {Promise<boolean>}
 *   True when the panel shows the subject; false when the key names no
 *   subject the panel can show (a section, a region, a foreign key, a
 *   placement rendering no addressable entity), or when the panel's
 *   (side, slot) refused to free up.
 */
export const presentSubject = async (key) => {
  const { SidebarManager, SidebarPluginBase, Blueprint } = Drupal.Daedalus;
  const plugin = SidebarManager.getPlugin('daedalus_edit_form');
  const [kind, uuid] = key.split(':');
  const root = Blueprint.getRoot();
  try {
    if (root && (kind === 'entity' || kind === 'field') && uuid === root.uuid) {
      await plugin.ensureTopLevelSubject();
      await plugin.openOn(plugin.subjectForm());
      return true;
    }
    if (kind !== 'block') {
      return false;
    }
    const placement = document.querySelector(`[data-selectable="block:${uuid}"]`);
    const presented = placement?.getAttribute('data-presented-field');
    if (presented) {
      // A field block presents a root field: the root is its subject.
      return presentSubject(presented);
    }
    const wrapper = placement?.matches('[data-daedalus-ui-entity-wrapper]')
      ? placement
      : placement?.querySelector('[data-daedalus-ui-entity-wrapper]');
    if (!wrapper) {
      return false;
    }
    const [entityTypeId, entityId] =
      wrapper.dataset.daedalusUiEntityWrapper.split('::');
    const formId = `${entityTypeId}::${entityId}`;
    let cached = formCacheMount()?.querySelector(
      `:scope > [data-daedalus-edit-form-id="${formId}"]`,
    );
    if (!cached) {
      await fetchBlockForm(placement, wrapper.dataset.daedalusUiViewMode);
      cached = formCacheMount()?.querySelector(
        `:scope > [data-daedalus-edit-form-id="${formId}"]`,
      );
      if (!cached) {
        throw new Error(
          'Daedalus Edit: the fetched block form did not arrive in the form cache.',
        );
      }
    }
    await plugin.openOn(cached);
    return true;
  } catch (error) {
    if (error === SidebarPluginBase.CLOSE_BLOCKED) {
      // The same-(side, slot) panel refused to close (an invalid form
      // item); it stays open and surfaces its own feedback.
      return false;
    }
    throw error;
  }
};
