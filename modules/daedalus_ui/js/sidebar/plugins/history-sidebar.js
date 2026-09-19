(($, Drupal, once) => {
  const DefaultSidebar = Drupal.Daedalus.DefaultSidebar;

  /**
   * History Sidebar Plugin
   *
   * The edit-history timeline: one row per staged change, newest first,
   * with the current state marked and undone (redoable) entries dimmed.
   * Clicking a row jumps the staged state to that entry; the header's
   * Undo/Redo buttons step. All data comes from the History facade — the
   * client mirror of the server-side timeline — which this panel refreshes
   * on open and subscribes to while the app applies mutations and restores.
   */
  class HistorySidebar extends DefaultSidebar {
    type = 'history';

    subscribed = false;

    /**
     * The History facade, or null before the app bundle boots.
     */
    history() {
      return Drupal.Daedalus.History ?? null;
    }

    /**
     * Format a request timestamp (seconds) as a relative time.
     */
    formatTimestamp(seconds) {
      const date = new Date(seconds * 1000);
      const diffMins = Math.floor((Date.now() - date) / 60000);
      const diffHours = Math.floor(diffMins / 60);
      if (diffMins < 1) {
        return Drupal.t('Just now');
      }
      if (diffMins < 60) {
        return Drupal.formatPlural(diffMins, '1 min ago', '@count mins ago');
      }
      if (diffHours < 24) {
        return Drupal.formatPlural(diffHours, '1 hour ago', '@count hours ago');
      }
      return date.toLocaleDateString();
    }

    /**
     * Renders the timeline and header buttons from the facade state.
     */
    renderTimeline() {
      const sidebar = this.getElement('history');
      const history = this.history();
      if (!sidebar || !history) {
        return;
      }
      const state = history.getState();

      const undoButton = sidebar.querySelector('#history-undo');
      const redoButton = sidebar.querySelector('#history-redo');
      if (undoButton) {
        undoButton.disabled = !state.canUndo;
      }
      if (redoButton) {
        redoButton.disabled = !state.canRedo;
      }

      const listContainer = sidebar.querySelector('.history-list');
      if (!listContainer) {
        return;
      }
      if (!state.loaded || state.entries.length === 0) {
        listContainer.innerHTML = `<div class="history-empty daedalus-ui-panel-empty">${Drupal.t('No changes yet.')}</div>`;
        return;
      }

      // Newest first; entries after the pointer are the undone future.
      const rows = [...state.entries]
        .reverse()
        .map((entry) => {
          const classes = ['daedalus-ui-panel-item'];
          if (entry.seq === state.pointer) {
            classes.push('history-item--current');
          }
          if (entry.seq > state.pointer) {
            classes.push('history-item--future');
          }
          return `<div class="${classes.join(' ')}" data-history-seq="${entry.seq}" role="button" tabindex="0">
          <div class="daedalus-ui-panel-item__label">${Drupal.checkPlain(entry.label)}</div>
          <div class="daedalus-ui-panel-item__subtitle">${this.formatTimestamp(entry.timestamp)}</div>
        </div>`;
        })
        .join('');
      listContainer.innerHTML = rows;

      // Handler properties stay idempotent across re-renders.
      const activate = (event) => {
        const row = event.target.closest('[data-history-seq]');
        if (!row) {
          return;
        }
        event.preventDefault();
        this.history()?.restoreTo(Number(row.getAttribute('data-history-seq')));
      };
      listContainer.onclick = activate;
      listContainer.onkeydown = (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          activate(event);
        }
      };
    }

    /**
     * Wires the header buttons and the facade subscription, idempotently.
     */
    connect() {
      const sidebar = this.getElement('history');
      const history = this.history();
      if (sidebar) {
        const undoButton = sidebar.querySelector('#history-undo');
        const redoButton = sidebar.querySelector('#history-redo');
        if (undoButton) {
          undoButton.onclick = () => this.history()?.undo();
        }
        if (redoButton) {
          redoButton.onclick = () => this.history()?.redo();
        }
      }
      if (!this.subscribed && history) {
        this.subscribed = true;
        history.onChange(() => this.renderTimeline());
      }
    }

    /**
     * Open the sidebar: fill from the server, then track changes.
     *
     * @param {string|null} id
     *   The specific sidebar instance ID to open.
     *
     * @return {Promise}
     *   Resolves when the sidebar is opened.
     */
    open(id = null) {
      return super.open(id).then(() => {
        this.connect();
        this.renderTimeline();
        this.history()?.refresh();
      });
    }
  }

  Drupal.Daedalus.HistorySidebar = HistorySidebar;

  const sidebarManager = Drupal.Daedalus.SidebarManager;
  const historySidebar = new HistorySidebar();
  sidebarManager.registerPlugin(historySidebar);

  const fillIfVisible = () => {
    const panel = historySidebar.getElement('history');
    if (panel && !panel.classList.contains('daedalus-ui-hidden')) {
      historySidebar.renderTimeline();
      historySidebar.history()?.refresh();
    }
  };

  // The History facade lives in the app bundle, which this library must not
  // read at load time (delivery order is not a guarantee). The queue
  // runs the callback once the app has booted, whenever that is.
  (window.DaedalusUiQueue ||= []).push(() => {
    historySidebar.connect();
    fillIfVisible();
  });

  /**
   * Covers panel markup that arrives after the app booted (the edit-mode
   * page loading via AJAX): wire and fill on attach. Idempotent with the
   * queue callback above — whichever runs second finds the work done.
   */
  Drupal.behaviors.DaedalusUiHistoryInit = {
    attach: (context, settings) => {
      once('history-init', '#history', context).forEach(() => {
        historySidebar.connect();
        fillIfVisible();
      });
    },
  };
})(jQuery, Drupal, once);
