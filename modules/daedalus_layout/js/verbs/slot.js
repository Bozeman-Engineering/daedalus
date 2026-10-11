/**
 * The slot gestures: Detach into this slot (the claim) and Release.
 *
 * A slot block around the page (a template's on a locked page, the site
 * frame's on every page of the theme) shows its default blueprint by
 * reference until a document beneath it fills the slot. The spine answers
 * what the door shows once (the showing, VerdictStamper::showing():
 * `shows`, `document`, `blueprint`, `portal`, `claimable`), and
 * `claimable` is true exactly when this page may claim the default: the
 * door shows its default, the spine's insert verdict on the door allows,
 * and nothing beneath the door fills the slot yet. "Detach into this slot"
 * is a doorway in daedalus_ui's registry (portal-actions.js: no verb
 * reaches a foreign document; the portal chip on the canvas and the Page
 * elements tree's shown-document row both offer the registered doorways
 * that apply to a showing), submitting the spine's detach_blueprint op
 * with `slot`: the default's tree lands as this page's own slot section,
 * an independent copy with provenance, so this page alone shows the copy
 * and every other page keeps the default. One op, the one the AI's
 * page_edit composes with; this is its thin adapter.
 *
 * The registry is DOM-free (`applies(showing)`, `run(showing, uuid)`) and
 * this action is DOM-aware inside run(): the door is found on the canvas
 * by its component uuid, the slot id read off it (SlotBlock::DOOR_ATTRIBUTE,
 * the stamp every door wears), and the reload rule asked of it. The
 * response re-renders the page's own document. A template's door sits
 * inside it (the page re-renders around its new fill); the frame's door
 * sits OUTSIDE the main entity, in the frame around the route, which the
 * mutation response does not re-render, so a claim of a frame slot reloads
 * the page: the staged document renders in Edit Mode on arrival, the slot
 * now showing the page's copy.
 *
 * Release is the Remove verb on a slot section: the one op is
 * remove_element, and daedalus-layout-verbs.js names the Remove verb
 * "Release slot" in the menu when the selection is a slot section
 * (isSlotSection), so the word matches the gesture and nothing is added
 * beside the verb that already does it.
 */

/**
 * The mirror node behind a selectable key, else null.
 */
const nodeOf = (key) => {
  const { Blueprint } = Drupal.Daedalus;
  return Blueprint.componentForUuid(Blueprint.getBlueprint(), key.split(':')[1]) ?? null;
};

/**
 * Whether a key is a slot section: a top-level container of this page's
 * own document filling a slot (the lean node carries the slot it fills).
 *
 * The mirror prints `slot` on a top-level container for the slot it FILLS
 * and on a slot block for the slot it DECLARES; the kind tells them apart.
 *
 * @param {string} key
 *   A selectable key.
 *
 * @return {boolean}
 */
export const isSlotSection = (key) =>
  key.startsWith('container:') && typeof nodeOf(key)?.slot === 'string';

// Phosphor arrow-line-down-left (currentColor): the default coming down
// into this page.
const claimIcon =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M208,40a8,8,0,0,0-8,8V200a8,8,0,0,0,16,0V48A8,8,0,0,0,208,40Zm-40,80H83.31l42.35-42.34a8,8,0,0,0-11.32-11.32l-56,56a8,8,0,0,0,0,11.32l56,56a8,8,0,0,0,11.32-11.32L83.31,136H168a8,8,0,0,0,0-16Z"></path></svg>';

/**
 * Registers the slot gestures into the edit-mode app.
 *
 * @param {object} app
 *   The edit-mode app registration facade.
 */
export const registerSlotVerbs = (app) => {
  app.portal.registerAction({
    id: 'daedalus_layout.claim_slot',
    label: Drupal.t('Detach into this slot'),
    icon: claimIcon,
    // The spine says where, and only where, the claim would land.
    applies: (showing) => showing.claimable === true,
    run: (showing, uuid) => {
      const { Blueprint } = Drupal.Daedalus;
      const context = Blueprint.rootContext();
      if (!context || showing.claimable !== true) {
        return;
      }
      // The door on the canvas: the slot it declares is the op's target,
      // and where it sits decides the reload.
      const door = document.querySelector(`[data-layout-builder-block-uuid="${uuid}"]`);
      const slot = door?.getAttribute('data-daedalus-slot');
      if (!slot) {
        throw new Error(`The slot door ${uuid} is not on this canvas.`);
      }
      const main = document.querySelector('[data-main-entity]');
      const outside = !main || !main.contains(door);
      Blueprint.mutate({
        ...context,
        operations: [{ op: 'detach_blueprint', slot }],
        label: Drupal.t('Detached into slot'),
      })
        .then(() => {
          if (outside) {
            // The frame around the route is not in the response: the staged
            // document shows the copy on arrival.
            window.location.reload();
          }
        })
        .catch((error) => {
          Blueprint.reportFailure(error, null);
        });
    },
  });
};
