/**
 * The doorway registry: gestures a module offers on what a join shows.
 *
 * A join (a slot door, a linked placement) shows another document by
 * reference, and the spine answers once what it shows (the showing:
 * VerdictStamper::showing(), daedalus_ui.api.php). The shown document is
 * no element of this canvas, so no verb reaches it; the gestures made on
 * it are DOORWAYS, offered by two faces over the one answer: the portal
 * chip on the canvas (portal.js, building the showing from the stamps the
 * server projected onto the placement root) and the Page elements tree's
 * shown-document row under the door (page-elements/shown-rows.js, reading
 * the annotation's `_element.showing`). Edit, the one leave to where the
 * shown document is edited (Drupal.Daedalus.Portal.leave), is each face's
 * own; a gesture that is this CANVAS's, made on the shown document (the
 * claim of a slot door's default: daedalus_layout's "Detach into this
 * slot", the spine's detach_blueprint op with `slot`), registers here and
 * both faces offer it when it applies.
 *
 * The registry is DOM-free: a module registers `{id, label, icon?,
 * applies, run}`, `applies(showing)` answering from the showing alone and
 * `run(showing, uuid)` taking the showing and the door's component uuid
 * (`data-layout-builder-block-uuid` on the canvas; the row's parentKey
 * minus its `block:` prefix), so the chip and the row call one run and
 * neither invents a fact for it. An action that must read the canvas (the
 * claim's reload rule: a frame door sits outside the mutation response)
 * finds the door by uuid inside run(). Re-registering an id replaces the
 * action in place.
 */

const actions = new Map();

/**
 * Registers a portal action.
 *
 * @param {object} action
 *   - id: a unique string.
 *   - label: the gesture's text (a string, or a function of the showing).
 *   - icon: optional SVG markup string (fill="currentColor"), drawn before
 *     the label.
 *   - applies: (showing) => boolean.
 *   - run: (showing, uuid) => void.
 *
 * @return {function(): void}
 *   Unregisters the action.
 */
export const registerPortalAction = (action) => {
  if (!action || typeof action.id !== 'string' || action.id === '') {
    throw new Error('A portal action declares a string id.');
  }
  if (typeof action.applies !== 'function' || typeof action.run !== 'function') {
    throw new Error(`Portal action "${action.id}" declares applies(showing) and run(showing, uuid).`);
  }
  actions.set(action.id, action);
  return () => {
    if (actions.get(action.id) === action) {
      actions.delete(action.id);
    }
  };
};

/**
 * The registered actions applying to a showing, in registration order.
 *
 * @param {?object} showing
 *   What the join shows (the annotation's `_element.showing`, or the chip's
 *   reading of the stamps: portal-model.js portalShowing), or null.
 *
 * @return {object[]}
 *   The applying actions, each with its label resolved to a string.
 */
export const portalActionsFor = (showing) => {
  if (!showing) {
    return [];
  }
  return [...actions.values()]
    .filter((action) => action.applies(showing))
    .map((action) => ({
      ...action,
      label:
        typeof action.label === 'function'
          ? action.label(showing)
          : action.label,
    }));
};

/**
 * The applying actions as menu items (js/menu/menu-state.js grammar),
 * bound to one join: the row's doorways after Edit, or any face that lists
 * rather than draws buttons.
 *
 * @param {?object} showing
 *   What the join shows, or null.
 * @param {?string} uuid
 *   The door's component uuid.
 *
 * @return {Array<{label: string, icon: ?string, run: function(): void}>}
 */
export const portalActionItems = (showing, uuid) =>
  portalActionsFor(showing).map((action) => ({
    label: action.label,
    icon: action.icon ?? null,
    run: () => action.run(showing, uuid),
  }));
