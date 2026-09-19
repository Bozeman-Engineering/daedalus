((Drupal) => {
  const { behaviors } = Drupal;

  /**
   * A previewed block in the Layout Builder canvas.
   *
   * Placement identity is daedalus_ui's data-layout-builder-block-uuid,
   * stamped on every block render in edit mode, including mutation-response
   * subtree swaps.
   */
  const BLOCK = '#layout-builder [data-layout-builder-block-uuid]';

  /**
   * Chrome inside a block whose links stay live: contextual links and
   * Symfony var-dumper output.
   *
   * @see \Drupal\contextual\Element\ContextualLinksPlaceholder
   */
  const LIVE_CHROME = '[data-contextual-id], .sf-dump';

  /**
   * Interactive elements to remove from the tabbing order.
   */
  const TABBABLE =
    'button, [href], input, select, textarea, iframe, [tabindex]:not([tabindex="-1"]):not(.tabbable)';

  /**
   * Swallows a click on a link inside a previewed block.
   *
   * The edit-session and selector click handlers only intercept clicks that
   * resolve to one of their targets, so without this a click on e.g. a menu
   * link would navigate away mid-edit. One delegated capture-phase listener
   * on the document covers links the canvas gains after boot (mutation
   * swaps, daedalus_edit's innerHTML rewrites) with no per-link binding, and
   * covers a composed block whose ROOT is the anchor (an image block with a
   * URL), which a descendant search never reached.
   */
  const onClick = (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const link = target?.closest('a');
    if (!link || !link.closest(BLOCK) || link.closest(LIVE_CHROME)) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
  };
  document.addEventListener('click', onClick, true);

  /**
   * Disables interactive elements in previewed blocks.
   *
   * Page-wide on every attach by design: the commonest mutation context IS
   * the block root (a context-scoped search matches descendants only), and
   * this is the one convergence sweep over canvas DOM that daedalus_edit rewrites
   * without attaching behaviors.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach disabling interactive elements behavior to the Layout Builder UI.
   */
  behaviors.layoutBuilderDisableInteractiveElements = {
    attach() {
      document
        .querySelectorAll(`${BLOCK} :is(input, textarea, select)`)
        .forEach((element) => {
          element.disabled = true;
        });

      // In preview blocks, remove from the tabbing order all input elements
      // and elements specifically assigned a tab index (the block root
      // included, for an anchor-rooted block), other than those related to
      // contextual links. daedalus_ui's keyboard traversal relies on the
      // -1: focus on a live link declines the traversal keys.
      document
        .querySelectorAll(
          `:is(${BLOCK}, ${BLOCK} *):is(${TABBABLE}):not([data-contextual-id], [data-contextual-id] *)`,
        )
        .forEach((element) => {
          element.setAttribute('tabindex', '-1');
        });
    },
  };
})(Drupal);
