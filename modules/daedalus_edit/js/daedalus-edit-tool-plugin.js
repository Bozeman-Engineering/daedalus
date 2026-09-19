import { registerEditSessionEngine } from './daedalus_edit/edit-session-engine.js';

(window.DaedalusUiQueue ||= []).push((app) => {
  /**
   * The Edit tool's Daedalus UI plugin.
   *
   * Activating the tool arms the edit session (armed = current tool); the
   * session grammar itself lives in the daedalus_ui app, driving the
   * engine registered below.
   */
  class EditToolPlugin extends app.ToolPluginBase {
    id = 'edit';

    disable = async () => {
      return Drupal.Daedalus.Edit.DisableEditMode();
    };
  }
  app.tools.register(new EditToolPlugin());
  registerEditSessionEngine(app);

  /**
   * The Edit verb: on a selected field, switch to the Edit tool; the
   * session opens the selection's editor on arm. No hotkey of its own; the
   * tool's hotkey covers the keyboard path with or without a selection.
   *
   * A MERGED block (data-presentation="merged") absorbs its single content
   * into the placement node, and its verbs are the union ("move me" from
   * the block, "edit me" from the absorbed content), so the verb also
   * applies to merged block selections. Editing still routes to the
   * inner leaf: the armed session resolves the merged root's boundary item.
   *
   * The ROOT entity row has a different editor: the entity-form panel (the
   * Page form), never a canvas leaf. The root's label may have no canvas
   * render at all (a theme that places no page title block, which the
   * product theme does not), so a leaf route would arm the Edit tool over
   * nothing; and even where the theme prints the H1 it stays click-editable
   * as a leaf on its own. One route on every theme: the verb applies to
   * the root when the delivered `_element` annotation carries an
   * edit_target (the label is a real editable field) and opens the panel
   * through the sidebar manager, whose plain open() defaults the subject
   * to the top-level entity. No tool change: panel widgets stage to
   * tempstore on change under any tool, and arming the Edit tool would
   * make it claim an edit that has no canvas leaf. The verb is the panel's
   * toolbar icon with a selection. Other entity keys (inline paragraphs)
   * get no Edit verb.
   */
  app.verbs.register({
    id: 'daedalus_edit.edit',
    label: Drupal.t('Edit'),
    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M227.31,73.37,182.63,28.68a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96a16,16,0,0,0,0-22.63ZM92.69,208H48V163.31l88-88L180.69,120ZM192,108.68,147.31,64l24-24L216,84.68Z"></path></svg>',
    kinds: ['field', 'block', 'entity'],
    applies: (key) => {
      if (key.startsWith('entity:')) {
        const { Blueprint } = Drupal.Daedalus;
        const root = Blueprint.getRoot();
        return (
          !!root &&
          key === `entity:${root.uuid}` &&
          !!Blueprint.getBlueprint()?._element?.presentation?.edit_target
        );
      }
      // Only PLACEMENT keys (block:{uuid}, two segments) are gated on
      // merged; block:{uuid}:{name} keys are semantically fields (the
      // 'field' kind already admitted them).
      if (!key.startsWith('block:') || key.split(':').length !== 2) {
        return true;
      }
      const element = document.querySelector(`[data-selectable="${key}"]`);
      return (
        !!element && element.getAttribute('data-presentation') === 'merged'
      );
    },
    weight: 0,
    // One of the selection chip's own face buttons (daedalus_ui
    // selection frame).
    tab: true,
    run: (key) => {
      // applies() admits only the root's entity key, so an entity key here
      // IS the root: its editor is the panel.
      if (key.startsWith('entity:')) {
        const { SidebarManager, SidebarPluginBase } = Drupal.Daedalus;
        SidebarManager.openSidebar('daedalus_edit_entity_form').catch((error) => {
          // The same-(side, slot) panel refused to close (an invalid form
          // item); it stays open and surfaces its own feedback.
          if (error !== SidebarPluginBase.CLOSE_BLOCKED) {
            console.error('Cannot open the entity form panel:', error);
          }
        });
        return;
      }
      Drupal.Daedalus.ModeManager.getPlugin('edit').changeTool('edit');
    },
  });
});
