(($, Drupal) => {
  /**
   * AJAX command for surgical element replacement.
   *
   * Parses the full rendered content into a detached DOM, then for each
   * selector finds the element in both the rendered content and the live page,
   * replacing the live element with the rendered one. Highlights each replaced
   * element to indicate the change.
   */
  Drupal.AjaxCommands.prototype.updateElement = function (ajax, response) {
    if (!response.selectors?.length || !response.content) {
      return;
    }

    const container = document.createElement('div');
    container.innerHTML = response.content;

    // Check if any target exists in the rendered content but not on the
    // live page (element was added or changed identity).
    const hasNewElements = response.selectors.some((selector) => {
      return (
        container.querySelector(selector) && !document.querySelector(selector)
      );
    });

    if (hasNewElements) {
      // Fall back to replacing the entity wrapper. This handles adds,
      // removes, and edits in a single replacement.
      // @todo When the page elements panel provides the entity blueprint
      // tree client-side, walk up from the unmatched UUID to find the
      // nearest existing ancestor and replace at that level instead.
      // Not a bundle module, so the missing-wrapper warning (see
      // edit_mode/utilities/warn.js) is inlined: silent no-ops are how
      // integrations break invisibly. Edit mode only.
      const warn = (missing) => {
        if (
          document.documentElement.getAttribute('data-daedalus-ui-mode') ===
          'edit'
        ) {
          console.warn(
            `Daedalus UI updateElement: ${missing} not found; the fallback whole-entity replacement was skipped. The entity UI attributes ride the entity template's root element; ensure the template prints {{ attributes }} on its outermost element.`,
          );
        }
      };
      const wrapper = container.querySelector(
        '[data-daedalus-ui-entity-wrapper]',
      );
      if (wrapper) {
        const attr = wrapper.getAttribute(
          'data-daedalus-ui-entity-wrapper',
        );
        const liveWrapper = document.querySelector(
          `[data-daedalus-ui-entity-wrapper="${attr}"]`,
        );
        if (liveWrapper) {
          liveWrapper.replaceWith(wrapper);
          Drupal.Daedalus.highlightElement(wrapper);
        } else {
          warn(
            `a live [data-daedalus-ui-entity-wrapper="${attr}"] element`,
          );
        }
      } else {
        warn('[data-daedalus-ui-entity-wrapper] in the rendered content');
      }
    } else {
      response.selectors.forEach((selector) => {
        const rendered = container.querySelector(selector);
        const live = document.querySelector(selector);
        if (rendered && live) {
          live.replaceWith(rendered);
          Drupal.Daedalus.highlightElement(rendered);
        } else if (live && !rendered) {
          live.remove();
        }
      });
    }

    Drupal.attachBehaviors();
  };
})(jQuery, Drupal);
