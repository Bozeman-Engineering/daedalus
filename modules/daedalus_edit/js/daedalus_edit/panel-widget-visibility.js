import { fieldState, HIDDEN, WIDGET_SAMPLE } from './field-state.js';
import {
  formCacheMount,
  panelItemKey,
  mirrorValueForKey,
  hasCanvasLeaf,
  SAMPLE_FILL_CLASS,
} from './panel-items.js';
import { fillWithSample } from './sample-fill.js';

/**
 * @file
 * Panel widget visibility: the field-state judge's wiring.
 *
 * The server ships every field wrapper hidden (FormDefaultFieldAttributes,
 * FormMediaFieldAttributes) and decides nothing else: whether a field
 * renders on the canvas is a theme and layout fact (the product theme
 * places no page title block; an empty or unplaced Layout Builder field
 * renders nothing) that only the client can see. This behavior is the one
 * place the decision is made, for every field alike, by the one judge
 * (field-state.js) over two client facts: the field's mirror value
 * (panel-items.js mirrorValueForKey) and whether a canvas leaf carries
 * its identity (hasCanvasLeaf):
 *
 * - HIDDEN (a leaf exists): the wrapper is hidden; the leaf's editor owns
 *   the widget, revealing it for the edit's duration where the widget is
 *   the editor.
 * - WIDGET (no leaf, a value): the wrapper (and any form-item handle a
 *   previous edit's teardown left hidden inside it) is revealed in-flow;
 *   it is a session item, and its widget commits through the form
 *   transport on the generic triggers (plugins/panel-widget-editor.js).
 * - WIDGET_SAMPLE (no leaf, empty): revealed the same, plus the Fill with
 *   sample button on its row (the `_sample` op, sample-fill.js), the
 *   client-side successor of the server-built "Add field" button.
 *
 * Exemption: the wrapper of the CURRENT edit
 * (Drupal.Daedalus.Edit.CurrentlyEditingElement) is never touched. The
 * editor owns its visibility while the edit is open (a leaf's widget
 * revealed in the panel, the canvas CKEditor's revealed format selector),
 * and a behavior pass mid-edit (a widget's own AJAX, a foreign mutation's
 * swap) must not hide the widget under the user. A blueprint-edited leaf
 * has no wrapper, so while one is open nothing is exempt.
 *
 * Runs on every behavior attach regardless of context and re-evaluates
 * every wrapper in the form-cache mount against the live document: a form
 * arrival (AppendCommand), a form rebuild (ReplaceCommand) and a canvas
 * swap (the op queue attaches on the replaced subtree) all change the
 * answer, and the answer is one querySelector per wrapper.
 */

const HIDDEN_CLASS = 'daedalus-edit-hidden';
const SAMPLE_CLASS = SAMPLE_FILL_CLASS;

/**
 * Keeps the Fill with sample button on a wrapper in step with its state.
 *
 * @param {Element} wrapper
 *   The form-item wrapper.
 * @param {?string} key
 *   The wrapper's session key.
 * @param {boolean} offer
 *   Whether the field is empty (the button belongs on the row).
 */
const reconcileSampleButton = (wrapper, key, offer) => {
  const existing = wrapper.querySelector(`:scope > .${SAMPLE_CLASS}`);
  if (!offer || !key) {
    existing?.remove();
    return;
  }
  if (existing) {
    existing.dataset.daedalusEditSampleKey = key;
    return;
  }
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `daedalus-ui-pill-button ${SAMPLE_CLASS}`;
  button.dataset.daedalusEditSampleKey = key;
  button.textContent = Drupal.t('Fill with sample');
  button.title = Drupal.t('Fill this empty field with generated sample content');
  button.addEventListener('click', (event) => {
    event.preventDefault();
    fillWithSample(button.dataset.daedalusEditSampleKey);
  });
  wrapper.prepend(button);
};

/**
 * Reconciles every cached wrapper's visibility with the judge's answer.
 *
 * Exported for the engine, which re-judges after ending an edit whose
 * field gained or lost its canvas leaf under the edit (the ended edit's
 * wrapper was exempt during the swap's own attach).
 */
export const reconcilePanelWidgets = () => {
  const mount = formCacheMount();
  if (!mount) {
    return;
  }
  const editing = Drupal.Daedalus.Edit?.CurrentlyEditingElement;
  const exempt = editing?.getFormItemWrapper?.() ?? null;
  mount
    .querySelectorAll(
      '.daedalus-edit-form-item-wrapper[data-daedalus-edit-form-item-wrapper-id]',
    )
    .forEach((wrapper) => {
      if (wrapper === exempt) {
        return;
      }
      const key = panelItemKey(wrapper);
      const state = fieldState({
        value: mirrorValueForKey(key),
        leaf: hasCanvasLeaf(wrapper),
      });
      if (state === HIDDEN) {
        wrapper.classList.add(HIDDEN_CLASS);
        reconcileSampleButton(wrapper, key, false);
        return;
      }
      wrapper.classList.remove(HIDDEN_CLASS);
      wrapper
        .querySelectorAll(`.daedalus-edit-form-item.${HIDDEN_CLASS}`)
        .forEach((handle) => handle.classList.remove(HIDDEN_CLASS));
      reconcileSampleButton(wrapper, key, state === WIDGET_SAMPLE);
    });
};

Drupal.behaviors.daedalusEditPanelWidgetVisibility = {
  attach: reconcilePanelWidgets,
};
