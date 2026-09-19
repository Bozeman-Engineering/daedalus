((Drupal, once) => {
  /**
   * Drag new sections and section-rooted stencils.
   */
  Drupal.behaviors.DraggableNewSections = {
    attach(context, settings) {
      // Drag a new section from the Add panel and place it on the page.
      // The drop is one plain add_section — a section is born a container;
      // arrangement is styling (the inspector's Layout section). A
      // section-rooted stencil item is a draggable-section carrying
      // data-stencil-id: it rides the SAME section caret through its own
      // source, whose drop is an instantiate_stencil at the caret's
      // location (add-panel.js; keep this data shape in sync with its
      // itemDragData) — the stencil defines its own structure.
      once(
        'draggable-new-sections',
        '.tabbed-content .draggable-section',
        context,
      ).forEach((draggableSection) => {
        window.DaedalusUiQueue = window.DaedalusUiQueue || [];
        window.DaedalusUiQueue.push((app) => {
          if (draggableSection.dataset.stencilId) {
            app.drag.makeDraggable(
              draggableSection,
              'new_stencil_section',
              (element) => ({ stencilId: element.dataset.stencilId }),
            );
          } else {
            app.drag.makeDraggable(draggableSection, 'new_section');
          }
        });
      });
    },
  };
})(Drupal, once);
