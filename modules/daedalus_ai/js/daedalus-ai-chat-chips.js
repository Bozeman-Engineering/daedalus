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
   * The selection set as the selected_elements request context: spine keys
   * (the same string identity that syncs panel and canvas) plus projected
   * labels, in set order.
   */
  const selectedElements = () => {
    if (!app) {
      return null;
    }
    const keys = app.Selection.getSelectedSet();
    if (keys.length === 0) {
      return null;
    }
    return keys.map((key, index) => ({
      key,
      label: app.Blueprint.labelForKey(key),
      number: index + 1,
    }));
  };

  /**
   * Renders the chips into the chat container, idempotently — call it on
   * every set change AND every behavior attach (the lazy-built chat panel
   * can stream in after the app boots).
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
      const label = app.Blueprint.labelForKey(key);

      const chip = document.createElement('div');
      chip.className = 'daedalus-ai-context-item daedalus-ui-chip daedalus-ui-chip--tinted';
      chip.setAttribute('data-selection-key', key);

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
