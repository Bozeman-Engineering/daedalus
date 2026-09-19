((Drupal, once) => {
  /**
   * Drag new blocks.
   */
  Drupal.behaviors.DraggableNewBlocks = {
    attach(context, settings) {
      // Drag a new block from the Add panel and place it on the page. The
      // panel item's element id is the block plugin id; the newBlock op
      // builder normalizes inline_block:{type} to a block_type. A stencil
      // item carries data-stencil-id instead — same source, same caret; the
      // drop builds an instantiate_stencil operation (add-panel.js branches
      // on stencilId; keep this data shape in sync with its itemDragData).
      once(
        'draggable-new-blocks',
        '.tabbed-content .draggable-block',
        context,
      ).forEach((draggableBlock) => {
        window.DaedalusUiQueue = window.DaedalusUiQueue || [];
        window.DaedalusUiQueue.push((app) => {
          app.drag.makeDraggable(draggableBlock, 'new_block', (element) =>
            element.dataset.stencilId
              ? { stencilId: element.dataset.stencilId }
              : { pluginId: element.id },
          );
        });
      });
    },
  };
})(Drupal, once);
