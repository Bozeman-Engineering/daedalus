import { registerEditSessionEngine } from './daedalus_edit/edit-session-engine.js';
import { isEmptyValue } from './daedalus_edit/field-state.js';
import { mirrorValueForKey } from './daedalus_edit/panel-items.js';
import { fillWithSample } from './daedalus_edit/sample-fill.js';

/**
 * daedalus_edit's Daedalus UI plugin: the editing engine and the verbs.
 *
 * Editing is a state of the ONE selection (daedalus_ui edit-session.js):
 * this module registers the engine that does the editing and the verbs
 * that reach it; the keyboard and pointer grammar that opens, walks and
 * ends an edit is the app's.
 */
(window.DaedalusUiQueue ||= []).push((app) => {
  registerEditSessionEngine(app);

  /**
   * The Edit verb: the chip's face button for "edit this", the same door
   * Enter and a leaf's double-click take (edit-session.js openEditor). No
   * hotkey of its own: Enter is the keyboard's.
   *
   * A MERGED block (data-presentation="merged") absorbs its single content
   * into the placement node, and its verbs are the union ("move me" from
   * the block, "edit me" from the absorbed content), so the verb also
   * applies to merged block selections. Editing still routes to the
   * inner leaf: the door resolves the merged root's boundary item.
   *
   * The ROOT entity row's editor is the entity-form panel (the Page form),
   * never a canvas leaf: the root's label may have no canvas render at
   * all (a theme that places no page title block, which the product theme
   * does not), and even where the theme prints the H1 it stays editable
   * as a leaf on its own. The root therefore always has exactly one
   * editor, so the verb applies to it unconditionally (the delivered
   * `_element` annotation's edit_target says which field the row stands
   * in for, not whether the row is editable), and the route is the same
   * door as every other key: the door, finding no canvas leaf for the
   * root, presents the root as the panel's subject through the engine
   * (edit-session-engine.js presentSubject), opening the panel on the
   * top-level form with its first revealed widget as the edit. Other
   * entity keys (inline paragraphs) get no Edit verb.
   */
  app.verbs.register({
    id: 'daedalus_edit.edit',
    label: Drupal.t('Edit'),
    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M227.31,73.37,182.63,28.68a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96a16,16,0,0,0,0-22.63ZM92.69,208H48V163.31l88-88L180.69,120ZM192,108.68,147.31,64l24-24L216,84.68Z"></path></svg>',
    kinds: ['field', 'block', 'entity'],
    applies: (key) => {
      if (key.startsWith('entity:')) {
        const root = Drupal.Daedalus.Blueprint.getRoot();
        return !!root && key === `entity:${root.uuid}`;
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
      Drupal.Daedalus.EditSession.openEditor(key);
    },
  });

  /**
   * The Fill with sample verb: an EMPTY subject field is filled with a
   * generated value through the spine's `_sample` request
   * (sample-fill.js), the successor of the form's server-built "Add
   * field" button. It applies to a field key whose mirror value says the
   * field is empty (the field-state judge's own reading, isEmptyValue: a
   * form-only stub flagged empty; never "is a stub", because a populated
   * field the blueprint cannot spell is not empty), so the verb and the
   * panel's widget can never disagree about the field. The panel renders
   * the same gesture as a button on the empty widget's row.
   */
  app.verbs.register({
    id: 'daedalus_edit.fill_sample',
    label: Drupal.t('Fill with sample'),
    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M208,144a15.78,15.78,0,0,1-10.42,14.94L146,178l-19.07,51.6a16,16,0,0,1-29.88,0L78,178l-51.6-19.07a16,16,0,0,1,0-29.88L78,110l19.07-51.6a16,16,0,0,1,29.88,0L146,110l51.6,19.07A15.78,15.78,0,0,1,208,144Zm-56-96h16V64a8,8,0,0,0,16,0V48h16a8,8,0,0,0,0-16H184V16a8,8,0,0,0-16,0V32H152a8,8,0,0,0,0,16Zm88,32h-8V72a8,8,0,0,0-16,0v8h-8a8,8,0,0,0,0,16h8v8a8,8,0,0,0,16,0V96h8a8,8,0,0,0,0-16Z"></path></svg>',
    kinds: ['field'],
    applies: (key) => isEmptyValue(mirrorValueForKey(key)),
    weight: 5,
    run: (key) => {
      fillWithSample(key);
    },
  });
});
