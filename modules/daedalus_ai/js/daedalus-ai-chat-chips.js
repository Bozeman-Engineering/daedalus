/**
 * @file
 * Chat chips: the AI chat's rendering of the ONE selection set.
 *
 * The chat panel LIVE-FOLLOWS the selection spine: there is no AI-owned
 * selection state. Chips render from Selection.getSelectedSet() on every
 * set change, a chip's dismiss button is Selection.deselect(key), and the
 * selected_elements request context emits the same spine keys with labels
 * projected from the blueprint mirror. Canvas feedback is the spine's
 * outlines; the old badge/selection-manager UX died with the DaedalusAi tool.
 *
 * The styling TARGET rides the same chip. When the single selection
 * carries a target (Selection.getTarget(): a part, a tag tier, a chain, or
 * an ancestor rule reaching it), its chip reads "Welcome › every link"
 * (the element's label, then the target's words through the same seam the
 * breadcrumb uses) and the request context sends the ADDRESS styling acts
 * at: {key: the target's key, selector, label}, so the AI sees a rule
 * where the user pointed at one. The chip's dismiss stays the element's
 * deselection (the target never outlives the selection).
 */

(() => {
  const PANEL_CLASS = 'daedalus-ai-context-panel';
  const HAS_CONTEXT_CLASS = 'daedalus-ai-has-context';

  /**
   * The app handed to the registration callback; null until the edit-mode
   * app boots (chips and the selection context only exist in edit mode).
   */
  let app = null;

  /**
   * The styling target riding a selected key, or null.
   *
   * A target belongs to the ONE selected element (the facade clears it on
   * every selection write and refuses one under a multi-selection), so
   * only the selected key's chip can carry it.
   *
   * @param {string} key
   *   A key of the selection set.
   *
   * @return {?{key: string, selector: string}}
   */
  const targetOf = (key) =>
    key === app.Selection.getSelected() ? app.Selection.getTarget() : null;

  /**
   * The chip's words for a key: the element's label, then the target's.
   *
   * @param {string} key
   *   A key of the selection set.
   * @param {?{key: string, selector: string}} target
   *   The target riding it, if any.
   *
   * @return {string}
   */
  const labelOf = (key, target) => {
    const label = app.Blueprint.labelForKey(key);
    if (!target) {
      return label;
    }
    const owner =
      target.key === key ? label : app.Blueprint.labelForKey(target.key);
    return `${owner} › ${app.Blueprint.targetLabel(target.key, target.selector)}`;
  };

  /**
   * The selection set as the selected_elements request context: spine keys
   * (the same string identity that syncs panel and canvas) plus projected
   * labels, in set order. A key carrying a styling target sends the
   * target's address instead (its key, which is the element or an
   * ancestor of it, and its selector).
   */
  const selectedElements = () => {
    if (!app) {
      return null;
    }
    const keys = app.Selection.getSelectedSet();
    if (keys.length === 0) {
      return null;
    }
    return keys.map((key, index) => {
      const target = targetOf(key);
      const element = {
        key: target ? target.key : key,
        label: labelOf(key, target),
        number: index + 1,
      };
      if (target) {
        element.selector = target.selector;
      }
      return element;
    });
  };

  /**
   * Renders the chips into the chat container, idempotently — call it on
   * every set or target change AND every behavior attach (the lazy-built
   * chat panel can stream in after the app boots).
   */
  const renderChips = () => {
    const container = document.querySelector('.chat-container[data-chat-id]');
    if (!container) {
      return;
    }

    const keys = app ? app.Selection.getSelectedSet() : [];
    container.classList.toggle(HAS_CONTEXT_CLASS, keys.length > 0);

    let panel = container.querySelector(`.${PANEL_CLASS}`);
    if (keys.length === 0) {
      panel?.remove();
      return;
    }
    if (!panel) {
      panel = document.createElement('div');
      panel.className = PANEL_CLASS;
      container.prepend(panel);
    }

    panel.innerHTML = '';
    keys.forEach((key, index) => {
      const target = targetOf(key);
      const label = labelOf(key, target);

      const chip = document.createElement('div');
      chip.className = 'daedalus-ai-context-item daedalus-ui-chip daedalus-ui-chip--tinted';
      chip.setAttribute('data-selection-key', key);
      if (target) {
        chip.setAttribute('data-target-selector', target.selector);
      }

      const number = document.createElement('span');
      number.className = 'daedalus-ai-context-number';
      number.textContent = String(index + 1);

      const text = document.createElement('span');
      text.className = 'daedalus-ai-context-label';
      text.textContent = label;

      const dismiss = document.createElement('button');
      dismiss.className = 'daedalus-ai-context-remove daedalus-ui-icon-button';
      dismiss.type = 'button';
      dismiss.setAttribute(
        'aria-label',
        Drupal.t('Deselect @label', { '@label': label }),
      );
      dismiss.innerHTML = '&times;';
      dismiss.addEventListener('click', (e) => {
        e.stopPropagation();
        // Chip dismiss IS deselection — the set change re-renders the chips
        // (and every other rendering of the one selection slice).
        app.Selection.deselect(key);
      });

      chip.append(number, text, dismiss);
      panel.append(chip);
    });
  };

  (window.DaedalusUiQueue = window.DaedalusUiQueue || []).push(
    (registeredApp) => {
      app = registeredApp;
      app.Selection.onSetChange(() => renderChips());
      // A target move under the same selection re-words the chip; the set
      // subscription never fires for it (it is key-only by design).
      app.Selection.onTargetChange(() => renderChips());
      renderChips();
    },
  );

  /**
   * Registers the selected_elements provider and re-renders on attach.
   *
   * The provider registration is idempotent and rides behavior attach
   * because the context manager arrives with the deep-chat library, a
   * dynamically-inserted script whose load order this file must not race;
   * by the time anything sends a chat request, behaviors have attached.
   */
  Drupal.behaviors.daedalusAiChatChips = {
    attach() {
      if (Drupal.Daedalus.AiContext) {
        Drupal.Daedalus.AiContext.register('selected_elements', selectedElements);
        // The turn's mutation-response payloads diff their asset attachments
        // against the
        // page state (libraries ride compressed, exactly as
        // drupalSettings holds them); without this every payload would
        // re-ship all core scripts and re-executing drupal.js would reset
        // window.Drupal.
        Drupal.Daedalus.AiContext.register(
          'ajax_page_state',
          () => drupalSettings.ajaxPageState,
        );
      }
      renderChips();
    },
  };
})();
