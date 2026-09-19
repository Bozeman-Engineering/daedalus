import store from '../store/store';
import { setRenameKey } from '../store/slices/view-slice';
import { register } from './verbs';

/**
 * The Rename verb: inline section rename as a first-class verb.
 *
 * Its effect is a SURFACE state change, not a blueprint op: running it
 * reveals the page-elements panel and puts the section's tree row into
 * rename state (view slice renameKey); the row owns the input and commits
 * the one update_element op. Registering it here, instead of leaving
 * rename a tree-local affordance, gives it all three registry faces for
 * free: the floating bar button, this menu item, and a remappable
 * F2 binding in the settings tray. The label double-click in the tree
 * stays as the direct-manipulation twin, dispatching the same action.
 */

// Phosphor pencil-simple, matching the other verbs' icon set; currentColor
// so the menu button's text color drives it. Exported: the portal chip's
// Edit link (components/portal) wears the same pencil as daedalus_edit's Edit
// verb, so "edit this" reads the same on both sides of the entity boundary.
export const pencilIcon =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M227.31,73.37,182.63,28.68a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96a16,16,0,0,0,0-22.63ZM92.69,208H48V163.31l88-88L180.69,120ZM192,108.68,147.31,64l24-24L216,84.68Z"></path></svg>';

/**
 * Registers the Rename verb. Called once by the app entry, after
 * initVerbs().
 */
export const registerRenameVerb = () => {
  register({
    id: 'daedalus_ui.rename',
    label: Drupal.t('Rename'),
    icon: pencilIcon,
    // Containers at every depth (the container label is their one renamable
    // property) and the ROOT entity row: the entity's label field presents
    // AS the root row (the `_element` annotation's derived edit_target), so
    // renaming the row IS editing the title. Rows for other kinds have
    // nothing to rename.
    kinds: ['container', 'entity'],
    applies: (key) => {
      if (!key.startsWith('entity:')) {
        return true;
      }
      // Only the ROOT entity, and only when the annotation names a label
      // field (edit_target) for the row to stand in for — inline entity
      // rows (paragraphs) carry no such target and don't grow rename yet.
      const { Blueprint } = Drupal.Daedalus;
      const root = Blueprint.getRoot();
      return (
        !!root &&
        key === `entity:${root.uuid}` &&
        !!Blueprint.getBlueprint()?._element?.presentation?.edit_target
      );
    },
    // F2, the platform rename convention. It used to be a hard-coded
    // tree-row keydown; as a verb hotkey it works with the panel closed
    // and is remappable in the settings tray.
    hotkeys: 'f2',
    weight: 15,
    run: (key) => {
      // The sidebar manager is the single open/close owner: reveal the
      // panel first so the rename input has a visible row to live in,
      // then flip the row into rename state. A refused open (the
      // same-slot panel would not close, e.g. an invalid daedalus_edit form)
      // already surfaced its own failure — rename simply doesn't start.
      Drupal.Daedalus.SidebarManager.openSidebar('page_elements')
        .then(() => store.dispatch(setRenameKey(key)))
        .catch(() => {});
    },
  });
};
