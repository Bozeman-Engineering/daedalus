(($, Drupal, once) => {
  /**
   * Cursor debugging.
   *
   * Mouse cursors are set in the tool plugin with something like:
   *
   * 'mouse_icon' => "url('/$path/assets/text-mouse.svg') 2 3",
   *
   * Where 2 and 3 are the offset of the image to the hotspot of the cursor. This
   * behavior adds a red dot on the hotspot so you can tweak the image placement.
   */
  Drupal.behaviors.CursorDebugging = {
    attach(context, settings) {
      once('cursor-debugging', 'html').forEach((page) => {
        const dot = document.createElement('div');
        dot.className = 'debug-dot';
        document.body.appendChild(dot);
        document.addEventListener('mousemove', (e) => {
          const hotspotX = 0;
          const hotspotY = 0;
          dot.style.left = `${e.clientX + hotspotX}px`;
          dot.style.top = `${e.clientY + hotspotY}px`;
        });
      });
    },
  };
})(jQuery, Drupal, once);
