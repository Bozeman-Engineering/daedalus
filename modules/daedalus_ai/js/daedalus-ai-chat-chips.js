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
 * The two styling FACTS ride the same chip. When the single selection
 * carries a subject facet (Selection.getTarget(): a part, a tag tier, a
 * chain ON the selected element) its chip reads "Welcome › every link"
 * (the element's label, then the facet's words through the same seam the
 * breadcrumb uses); when it carries a write address
 * (Selection.getAddress(): a rule on the element or an ancestor that the
 * write rebases to) the chip adds ", at Welcome › every link" (the
 * address's owner, then the rule's words). The request context keeps
 * `key` as the ELEMENT always, `selector` as the facet and `address` as
 * {key, selector}, so the AI sees what the user is looking at and where
 * styling acts as two facts, never a rule in the element's place. The
 * chip's dismiss stays the element's deselection (neither fact outlives
 * the selection).
 */

(() => {
  const PANEL_CLASS = 'daedalus-ai-context-panel';

  /**
   * The app handed to the registration callback; null until the edit-mode
   * app boots (chips and the selection context only exist in edit mode).
   */
  let app = null;

  /**
   * The styling facts riding a selected key: the subject facet and the
   * write address, each null when none stands.
   *
   * Both belong to the ONE selected element (the facade clears them on
   * every selection write and refuses either under a multi-selection), so
   * only the selected key's chip can carry them.
   *
   * @param {string} key
   *   A key of the selection set.
   *
   * @return {{target: ?{key: string, selector: string}, address: ?{key: string, selector: string}}}
   */
  const stylingOf = (key) =>
    key === app.Selection.getSelected()
      ? { target: app.Selection.getTarget(), address: app.Selection.getAddress() }
      : { target: null, address: null };

  /**
   * The words for a rule under an owner: the rule's words alone on the
   * element itself, else the owner's label then the rule's.
   *
   * @param {string} key
   *   The selected element's key.
   * @param {{key: string, selector: string}} rule
   *   A facet or an address.
   *
   * @return {string}
   */
  const ruleWords = (key, rule) => {
    const words = app.Blueprint.targetLabel(rule.key, rule.selector);
    return rule.key === key
      ? words
      : `${app.Blueprint.labelForKey(rule.key)} › ${words}`;
  };

  /**
   * The chip's words for a key: the element's label, then the facet's,
   * then the address it is styled at.
   *
   * @param {string} key
   *   A key of the selection set.
   * @param {?{key: string, selector: string}} target
   *   The subject facet riding it, if any.
   * @param {?{key: string, selector: string}} address
   *   The write address riding it, if any.
   *
   * @return {string}
   */
  const labelOf = (key, target, address) => {
    const label = app.Blueprint.labelForKey(key);
    const subject = target ? `${label} › ${ruleWords(key, target)}` : label;
    return address ? `${subject}, at ${ruleWords(key, address)}` : subject;
  };

  /**
   * The selection set as the selected_elements request context: spine keys
   * (the same string identity that syncs panel and canvas) plus projected
   * labels, in set order. `key` is always the element; a subject facet
   * rides as `selector` and a write address as `address` ({key, selector},
   * its key the element or an ancestor of it).
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
      const { target, address } = stylingOf(key);
      const element = {
        key,
        label: labelOf(key, target, address),
        number: index + 1,
      };
      if (target) {
        element.selector = target.selector;
      }
      if (address) {
        element.address = { key: address.key, selector: address.selector };
      }
      return element;
    });
  };

  /**
   * Renders the chips into the chat container, idempotently — call it on
   * every set or styling change AND every behavior attach (the lazy-built
   * chat panel can stream in after the app boots).
   */
  const renderChips = () => {
    const container = document.querySelector('.chat-container[data-chat-id]');
    if (!container) {
      return;
    }

    const keys = app ? app.Selection.getSelectedSet() : [];

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
      const { target, address } = stylingOf(key);
      const label = labelOf(key, target, address);

      const chip = document.createElement('div');
      chip.className = 'daedalus-ai-context-item daedalus-ui-chip daedalus-ui-chip--tinted';
      chip.setAttribute('data-selection-key', key);
      if (target) {
        chip.setAttribute('data-target-selector', target.selector);
      }
      if (address) {
        chip.setAttribute('data-address-key', address.key);
        chip.setAttribute('data-address-selector', address.selector);
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
      // A facet or address move under the same selection re-words the
      // chip; the set subscription never fires for it (it is key-only by
      // design), and the one styling subscription carries both facts.
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
