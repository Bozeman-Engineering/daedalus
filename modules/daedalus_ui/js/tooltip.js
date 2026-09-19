/**
 * @file
 * The chrome tooltip engine (Drupal.Daedalus.tooltip).
 *
 * Any chrome control grows an instant tooltip by declaring one attribute:
 * @code
 *   <button data-daedalus-ui-tooltip="Styles">
 * @endcode
 * Optional data-daedalus-ui-tooltip-position="top" flips the preferred side
 * (default bottom). The attribute is the whole contract: the engine is a
 * delegated listener pair per document driving one singleton pill on
 * body, so it serves server-rendered chrome and JS-reconciled chrome
 * (preact re-renders never clobber per-trigger DOM because there is
 * none).
 *
 * The tooltip is the label you can't see: a trigger whose inline label
 * (.toolbar-button__label / .daedalus-ui-frame__label) is currently visible shows
 * no tooltip. That one rule makes the engine density-aware with no
 * density wiring — full-density chrome shows labels inline and stays
 * quiet, minimal/clean hide them and the tooltip takes over, and
 * always-icon-only chrome (the tool pill) gets tooltips at every tier.
 *
 * A separate document (daedalus_stylish's satellite window) attaches its own
 * engine with Drupal.Daedalus.tooltip.attach(doc).
 */
((Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  const OFFSET = 6;
  const VIEWPORT_PADDING = 16;
  const LABEL_SELECTOR = '.toolbar-button__label, .daedalus-ui-frame__label';

  /**
   * Attaches a tooltip engine to a document. Idempotent per document.
   */
  const attach = (doc) => {
    if (doc.documentElement.hasAttribute('data-daedalus-ui-tooltip-engine')) {
      return;
    }
    doc.documentElement.setAttribute('data-daedalus-ui-tooltip-engine', '');

    let pill = null;
    let current = null;

    const ensurePill = () => {
      if (!pill) {
        pill = doc.createElement('div');
        pill.className = 'daedalus-ui-tooltip daedalus-ui-tooltip--floating';
        pill.setAttribute('role', 'tooltip');
        // Triggers carry their own aria-label; the pill is visual only.
        pill.setAttribute('aria-hidden', 'true');
        doc.body.appendChild(pill);
      }
      return pill;
    };

    const hide = () => {
      current = null;
      if (pill) {
        pill.classList.remove('daedalus-ui-tooltip--visible');
      }
    };

    const labelVisible = (trigger) => {
      const label = trigger.querySelector(LABEL_SELECTOR);
      return label !== null && label.getClientRects().length > 0;
    };

    const position = (trigger, element) => {
      const view = doc.defaultView;
      const rect = trigger.getBoundingClientRect();
      const tip = element.getBoundingClientRect();
      const below = trigger.getAttribute('data-daedalus-ui-tooltip-position') !== 'top';
      let top = below ? rect.bottom + OFFSET : rect.top - OFFSET - tip.height;
      // Flip to the other side when the preferred one leaves the viewport.
      if (below && top + tip.height > view.innerHeight - VIEWPORT_PADDING) {
        top = rect.top - OFFSET - tip.height;
      } else if (!below && top < VIEWPORT_PADDING) {
        top = rect.bottom + OFFSET;
      }
      const left = Math.max(
        VIEWPORT_PADDING,
        Math.min(
          rect.left + rect.width / 2 - tip.width / 2,
          view.innerWidth - VIEWPORT_PADDING - tip.width,
        ),
      );
      element.style.top = `${top}px`;
      element.style.left = `${left}px`;
    };

    const show = (trigger) => {
      if (labelVisible(trigger)) {
        hide();
        return;
      }
      current = trigger;
      const element = ensurePill();
      element.textContent = trigger.getAttribute('data-daedalus-ui-tooltip');
      element.classList.add('daedalus-ui-tooltip--visible');
      position(trigger, element);
    };

    doc.addEventListener('pointerover', (event) => {
      const trigger = event.target.closest('[data-daedalus-ui-tooltip]');
      if (trigger && trigger !== current) {
        show(trigger);
      }
    });
    doc.addEventListener('pointerout', (event) => {
      if (current && !current.contains(event.relatedTarget)) {
        hide();
      }
    });
    // Keyboard: :focus-visible only — a mouse click leaves the control
    // focused, and plain focus would pin the tooltip after the pointer
    // moved on (same rule as the anchored .daedalus-ui-tooltip-host pattern).
    doc.addEventListener('focusin', (event) => {
      const trigger = event.target.closest('[data-daedalus-ui-tooltip]');
      if (trigger && event.target.matches(':focus-visible')) {
        show(trigger);
      }
    });
    doc.addEventListener('focusout', () => hide());
    // Fixed positioning goes stale the moment anything scrolls; a press
    // means the pointer's work has moved on from reading the label.
    doc.addEventListener('scroll', () => hide(), true);
    doc.addEventListener('pointerdown', () => hide(), true);
  };

  attach(document);

  Drupal.Daedalus.tooltip = { attach };
})(Drupal);
