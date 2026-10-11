/**
 * daedalus_layout's verbs — Duplicate, Configure, and Remove.
 *
 * Object-first replacements for the old tools and stacked indicators:
 * instead of switching to a tool and clicking a per-tool icon, the user
 * selects a block or section and the verb offers itself in the floating
 * menu (or by hotkey). Duplicate and Remove mutate through
 * the one persistence spine (daedalus_blueprint_api → tempstore); Configure
 * opens the same entity forms the tools opened — forms are the permanent
 * carve-out, only the entry gesture changes. There is no Change layout
 * verb: a section is born as a container and arrangement is styling.
 *
 * This file rides the daedalus_ui edit-mode app bundle as a library
 * dependency (see daedalus_layout_library_info_alter()). Registration goes through
 * the DaedalusUiQueue seam, so load order doesn't matter.
 */

import { registerBlueprintVerbs } from './blueprint.js';
import { isSlotSection, registerSlotVerbs } from './slot.js';

const registerVerbs = (app) => {
  // Facades are read at run time, not registration time: a verb only runs
  // on user action, long after they all exist.

  /**
   * Queues operations against the page's root entity and reports failures
   * through the one mutation-failure display (flash + structured-error
   * notification; quiet for ops queued behind a failed one).
   */
  const mutate = (operations, optimistic = null, key = null, select = null) => {
    const { Blueprint } = Drupal.Daedalus;
    const context = Blueprint.rootContext();
    if (!context) {
      return Promise.resolve(false);
    }
    return Blueprint.mutate({ ...context, operations, optimistic, select })
      .then(() => true)
      .catch((error) => {
        Blueprint.reportFailure(error, key);
        return false;
      });
  };

  /**
   * Whether a key's element sits outside the main entity: inside the site
   * frame around the route (a page's fill in a frame slot), which the
   * mutation response does not re-render. A structure op on such an
   * element reloads the page on success, so the frame shows the result
   * (the slot's default after a release) instead of the optimistic hide.
   */
  const outsideMainEntity = (key) => {
    const element = Drupal.Daedalus.Selection.getElement(key);
    const main = document.querySelector('[data-main-entity]');
    return !!element && (!main || !main.contains(element));
  };

  /**
   * The Remove verb's optimistic hooks (a structure op applies
   * optimistically): the element disappears the moment the op is enqueued
   * and snaps back if the server rejects the removal; the revert is most of
   * the failure display.
   * The response's subtree swap discards the hidden node on confirm.
   */
  const optimisticRemove = (key) => {
    const { Selection } = Drupal.Daedalus;
    const element = Selection.getElement(key);
    if (!element) {
      return null;
    }
    const priorDisplay = element.style.display;
    return {
      apply: () => {
        element.style.setProperty('display', 'none', 'important');
        // The selection (and its verb menu) has nothing to point at while
        // the element is gone; the op queue prunes it for real on confirm.
        // Deselect just this key: a multi-select Trash removes members
        // one hook at a time, and clearing would kill the rest of the set
        // mid-batch.
        Selection.deselect(key);
      },
      rollback: () => {
        // A preceding op's subtree swap can have replaced the node; the
        // swapped-in markup is authoritative and was never hidden.
        if (!element.isConnected) {
          return;
        }
        if (priorDisplay) {
          element.style.setProperty('display', priorDisplay);
        } else {
          element.style.removeProperty('display');
        }
      },
    };
  };

  /**
   * Composes per-key optimistic remove hooks for a multi-select Trash:
   * all members vanish at enqueue, and a rejection snaps them back in
   * reverse order (LIFO, matching the op queue's rollback discipline).
   */
  const optimisticRemoveMany = (keys) => {
    const hooks = keys.map(optimisticRemove).filter(Boolean);
    if (hooks.length === 0) {
      return null;
    }
    return {
      apply: () => hooks.forEach((hook) => hook.apply()),
      rollback: () => [...hooks].reverse().forEach((hook) => hook.rollback()),
    };
  };

  /**
   * The UUID a selectable key qualifies: the component UUID for block keys,
   * the section UUID for section keys.
   */
  const uuidOf = (key) => key.split(':')[1];

  // The sidebar tools' icons (assets/*.svg), inlined with currentColor so
  // the menu button's text color drives them.
  const icons = {
    duplicate:
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M216,32H88a8,8,0,0,0-8,8V80H40a8,8,0,0,0-8,8V216a8,8,0,0,0,8,8H168a8,8,0,0,0,8-8V176h40a8,8,0,0,0,8-8V40A8,8,0,0,0,216,32ZM160,208H48V96H160Zm48-48H176V88a8,8,0,0,0-8-8H96V48H208Z"></path></svg>',
    gear: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M128,80a48,48,0,1,0,48,48A48.05,48.05,0,0,0,128,80Zm0,80a32,32,0,1,1,32-32A32,32,0,0,1,128,160Zm88-29.84q.06-2.16,0-4.32l14.92-18.64a8,8,0,0,0,1.48-7.06,107.21,107.21,0,0,0-10.88-26.25,8,8,0,0,0-6-3.93l-23.72-2.64q-1.48-1.56-3-3L186,40.54a8,8,0,0,0-3.94-6,107.71,107.71,0,0,0-26.25-10.87,8,8,0,0,0-7.06,1.49L130.16,40Q128,40,125.84,40L107.2,25.11a8,8,0,0,0-7.06-1.48A107.6,107.6,0,0,0,73.89,34.51a8,8,0,0,0-3.93,6L67.32,64.27q-1.56,1.49-3,3L40.54,70a8,8,0,0,0-6,3.94,107.71,107.71,0,0,0-10.87,26.25,8,8,0,0,0,1.49,7.06L40,125.84Q40,128,40,130.16L25.11,148.8a8,8,0,0,0-1.48,7.06,107.21,107.21,0,0,0,10.88,26.25,8,8,0,0,0,6,3.93l23.72,2.64q1.49,1.56,3,3L70,215.46a8,8,0,0,0,3.94,6,107.71,107.71,0,0,0,26.25,10.87,8,8,0,0,0,7.06-1.49L125.84,216q2.16.06,4.32,0l18.64,14.92a8,8,0,0,0,7.06,1.48,107.21,107.21,0,0,0,26.25-10.88,8,8,0,0,0,3.93-6l2.64-23.72q1.56-1.48,3-3L215.46,186a8,8,0,0,0,6-3.94,107.71,107.71,0,0,0,10.87-26.25,8,8,0,0,0-1.49-7.06Zm-16.1-6.5a73.93,73.93,0,0,1,0,8.68,8,8,0,0,0,1.74,5.48l14.19,17.73a91.57,91.57,0,0,1-6.23,15L187,173.11a8,8,0,0,0-5.1,2.64,74.11,74.11,0,0,1-6.14,6.14,8,8,0,0,0-2.64,5.1l-2.51,22.58a91.32,91.32,0,0,1-15,6.23l-17.74-14.19a8,8,0,0,0-5-1.75h-.48a73.93,73.93,0,0,1-8.68,0,8,8,0,0,0-5.48,1.74L100.45,215.8a91.57,91.57,0,0,1-15-6.23L82.89,187a8,8,0,0,0-2.64-5.1,74.11,74.11,0,0,1-6.14-6.14,8,8,0,0,0-5.1-2.64L46.43,170.6a91.32,91.32,0,0,1-6.23-15l14.19-17.74a8,8,0,0,0,1.74-5.48,73.93,73.93,0,0,1,0-8.68,8,8,0,0,0-1.74-5.48L40.2,100.45a91.57,91.57,0,0,1,6.23-15L69,82.89a8,8,0,0,0,5.1-2.64,74.11,74.11,0,0,1,6.14-6.14A8,8,0,0,0,82.89,69L85.4,46.43a91.32,91.32,0,0,1,15-6.23l17.74,14.19a8,8,0,0,0,5.48,1.74,73.93,73.93,0,0,1,8.68,0,8,8,0,0,0,5.48-1.74L155.55,40.2a91.57,91.57,0,0,1,15,6.23L173.11,69a8,8,0,0,0,2.64,5.1,74.11,74.11,0,0,1,6.14,6.14,8,8,0,0,0,5.1,2.64l22.58,2.51a91.32,91.32,0,0,1,6.23,15l-14.19,17.74A8,8,0,0,0,199.87,123.66Z"></path></svg>',
    trash:
      '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M216,48H180V36A28,28,0,0,0,152,8H104A28,28,0,0,0,76,36V48H40a12,12,0,0,0,0,24h4V208a20,20,0,0,0,20,20H192a20,20,0,0,0,20-20V72h4a12,12,0,0,0,0-24ZM100,36a4,4,0,0,1,4-4h48a4,4,0,0,1,4,4V48H100Zm88,168H68V72H188ZM116,104v64a12,12,0,0,1-24,0V104a12,12,0,0,1,24,0Zm48,0v64a12,12,0,0,1-24,0V104a12,12,0,0,1,24,0Z"></path></svg>',
  };

  app.verbs.register({
    id: 'daedalus_layout.duplicate',
    label: Drupal.t('Duplicate'),
    icon: icons.duplicate,
    // Blocks AND sections at every depth: the duplicate_element batch op
    // deep-clones a section's whole subtree with fresh identity; the
    // clone lands right after the original.
    kinds: ['block', 'container'],
    // A clone is an arrangement of the element (the server asks insert of
    // its parent as well; one declared capability per verb).
    capability: 'arrange',
    hotkeys: 'mod+d',
    weight: 10,
    // One of the selection chip's own face buttons (daedalus_ui
    // selection frame).
    tab: true,
    // Duplicate applies to a whole multi-select set: one batch,
    // one mutation, one history entry for all the clones.
    multi: true,
    run: (key) => {
      // Naming the clone client-side lets optimistic UI address it before
      // the server confirms; selection follows the clone once it lands
      // (the placed thing gets focus, same as a drop).
      const clone = app.generateUuid();
      mutate(
        [
          {
            op: 'duplicate_element',
            element: uuidOf(key),
            uuid: clone,
          },
        ],
        null,
        key,
        clone,
      );
    },
    runMany: (keys) => {
      // The clones become the new multi-select set, mirroring single
      // duplicate: the selection moves from the originals to what the
      // gesture created.
      const clones = keys.map(() => app.generateUuid());
      mutate(
        keys.map((key, index) => ({
          op: 'duplicate_element',
          element: uuidOf(key),
          uuid: clones[index],
        })),
        null,
        keys[keys.length - 1],
        clones,
      );
    },
  });

  app.verbs.register({
    id: 'daedalus_layout.configure',
    label: Drupal.t('Configure'),
    icon: icons.gear,
    kinds: ['block', 'container'],
    capability: 'configure',
    weight: 20,
    run: (key) => {
      const { Selection, ModeManager, ModePluginBase } = Drupal.Daedalus;
      const element = Selection.getElement(key);
      const info = ModeManager.getPlugin('edit').getSectionStorageInfo(element);
      if (!info) {
        return;
      }
      const path = key.startsWith('container:')
        ? `/layout_builder/configure/section/${info.storageType}/${info.storageId}/${info.sectionDelta}?section_uuid=${uuidOf(key)}`
        : `/layout_builder/update/block/${info.storageType}/${info.storageId}/${info.sectionDelta}/${info.region}/${info.blockUuid}`;
      ModePluginBase.dialog({
        url: ModePluginBase.url(path),
        width: 900,
        message: Drupal.t('Loading form...'),
      });
    },
  });

  app.verbs.register({
    id: 'daedalus_layout.trash',
    label: Drupal.t('Remove'),
    // Removing a slot section RELEASES the slot: the document around the
    // page shows the slot's default again. One op (remove_element); the
    // word is the gesture's where the selection is a slot section
    // (js/verbs/slot.js).
    menuLabel: (key) =>
      isSlotSection(key) ? Drupal.t('Release slot') : Drupal.t('Remove'),
    icon: icons.trash,
    kinds: ['block', 'container'],
    capability: 'arrange',
    // Both delete keys: a Mac keyboard's main delete reports Backspace.
    hotkeys: ['delete', 'backspace'],
    weight: 30,
    // One of the selection chip's own face buttons (daedalus_ui
    // selection frame).
    tab: true,
    // Trash applies to a whole multi-select set: one atomic batch removes
    // every member (one mutation, one history entry), so a single undo
    // restores them all.
    multi: true,
    run: (key) => {
      // No confirm: the change stages in tempstore, discard restores it.
      const outside = outsideMainEntity(key);
      mutate(
        [{ op: 'remove_element', element: uuidOf(key) }],
        optimisticRemove(key),
        key,
      ).then((done) => {
        if (done && outside) {
          window.location.reload();
        }
      });
    },
    runMany: (keys) => {
      mutate(
        keys.map((key) => ({ op: 'remove_element', element: uuidOf(key) })),
        optimisticRemoveMany(keys),
        keys[keys.length - 1],
      );
    },
  });

  // Save as blueprint… / Update blueprint 'X' / Open blueprint
  // (js/verbs/blueprint.js): the capture gesture, its provenance-driven
  // update twin, and the door to the blueprint's own canvas.
  registerBlueprintVerbs(app);

  // Detach into this slot (the portal chip's claim of a slot door's
  // default) and the Release word (js/verbs/slot.js).
  registerSlotVerbs(app);
};

window.DaedalusUiQueue = window.DaedalusUiQueue || [];
window.DaedalusUiQueue.push(registerVerbs);
