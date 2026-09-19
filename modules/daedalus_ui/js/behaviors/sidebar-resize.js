((Drupal, once) => {
  /**
   * Narrower than this breaks form layouts; also the SSR floor
   * (DaedalusUi::buildSidebars ignores smaller cookie values).
   */
  const MIN_WIDTH = 220;

  /**
   * User-resizable sidebar rails.
   *
   * One drag handle per sidebar wrapper. Dragging sets the wrapper-level
   * --daedalus-ui-sidebar-width custom property every panel consumes (a panel may
   * declare its own default as the var's fallback — never a fixed width),
   * so both slots of a rail follow together. On release the width persists
   * in the "{side}_sidebar_width" cookie the server stamps back on the next
   * load (no flash) — the same cookie-driven SSR pattern as panel open
   * state. Double-click restores the stylesheet default.
   *
   * Panels carry data-offset-{side}, so content follows with just
   * Drupal.displace() (synchronous — see applyWidth).
   */
  Drupal.behaviors.NpSidebarResize = {
    attach(context) {
      once(
        'daedalus-ui-sidebar-resize',
        '.daedalus-ui-sidebar-wrapper',
        context,
      ).forEach((wrapper) => {
        const side =
          wrapper.id === 'daedalus-ui-left-sidebar' ? 'left' : 'right';
        const handle = document.createElement('div');
        handle.className = 'daedalus-ui-sidebar-resize-handle';
        wrapper.appendChild(handle);

        // Displace synchronously with the width write so the selection
        // outline's per-frame measure (geometry-tracker.js) reads post-shift
        // geometry; deferring to rAF lagged content and outline behind the
        // panel edge by a frame.
        const applyWidth = (width) => {
          wrapper.style.setProperty('--daedalus-ui-sidebar-width', `${width}px`);
          Drupal.displace();
        };

        const widthFromPointer = (event) => {
          const width =
            side === 'right'
              ? window.innerWidth - event.clientX
              : event.clientX;
          return Math.round(
            Math.min(Math.max(width, MIN_WIDTH), window.innerWidth / 2),
          );
        };

        handle.addEventListener('pointerdown', (event) => {
          let lastWidth = null;
          // The document mark pins cursor, selection and transition locks
          // page-wide; the handle mark lights only the dragged seam.
          Drupal.Daedalus.drag.begin(handle, event, {
            marks: [
              [document.documentElement, 'daedalus-ui-sidebar-resizing'],
              [handle, 'daedalus-ui-sidebar-resize-handle--dragging'],
            ],
            onMove: (moveEvent) => {
              lastWidth = widthFromPointer(moveEvent);
              applyWidth(lastWidth);
            },
            onEnd: () => {
              if (lastWidth !== null) {
                // A user preference, not mode state: keep it for a year.
                Drupal.Daedalus.setCookieValue(
                  `${side}_sidebar_width`,
                  lastWidth,
                  { maxAge: 31536000 },
                );
              }
            },
          });
        });

        handle.addEventListener('dblclick', () => {
          wrapper.style.removeProperty('--daedalus-ui-sidebar-width');
          Drupal.Daedalus.deleteCookieValue(`${side}_sidebar_width`);
          Drupal.displace();
        });
      });
    },
  };
})(Drupal, once);
