(($, Drupal, once) => {
  const DefaultSidebar = Drupal.Daedalus.DefaultSidebar;

  /**
   * Notifications Sidebar Plugin
   *
   * Renders the notification spine's history (Drupal.Daedalus.messenger,
   * daedalus_ui/messenger). A consumer only: the messenger owns the
   * history and its storage; this panel reads it and re-renders when the
   * messenger writes, from any window.
   */
  class NotificationsSidebar extends DefaultSidebar {
    type = 'notifications';

    /**
     * Format timestamp
     *
     * @param {number} timestamp
     *   Unix timestamp in milliseconds
     * @return {string}
     *   Formatted time string
     */
    formatTimestamp(timestamp) {
      const date = new Date(timestamp);
      const now = new Date();
      const diffMs = now - date;
      const diffMins = Math.floor(diffMs / 60000);
      const diffHours = Math.floor(diffMs / 3600000);

      if (diffMins < 1) {
        return 'Just now';
      }
      if (diffMins < 60) {
        return `${diffMins} min${diffMins > 1 ? 's' : ''} ago`;
      }
      if (diffHours < 24) {
        return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
      }
      const hours = date.getHours().toString().padStart(2, '0');
      const minutes = date.getMinutes().toString().padStart(2, '0');
      return `${date.toLocaleDateString()} ${hours}:${minutes}`;
    }

    /**
     * Render messages in the sidebar
     */
    renderMessages() {
      const sidebar = this.getElement('notifications');
      if (!sidebar) {
        return;
      }

      const listContainer = sidebar.querySelector('.notifications-list');
      if (!listContainer) {
        return;
      }

      const history = Drupal.Daedalus.messenger.history();

      if (history.length === 0) {
        listContainer.innerHTML = Drupal.theme('notificationsEmpty');
        return;
      }

      // Reverse to show newest first
      const reversed = [...history].reverse();
      const html = reversed
        .map((notification) => {
          return Drupal.theme(
            'notificationItem',
            notification,
            this.formatTimestamp(notification.timestamp),
          );
        })
        .join('');

      listContainer.innerHTML = html;

      // Click-to-scroll: a notification carrying an element target (a DOM
      // id or a selectable key, e.g. a mutation failure's reverted element)
      // scrolls back to it. Handler properties stay idempotent across
      // re-renders.
      const activate = (event) => {
        const item = event.target.closest('[data-notification-target]');
        if (!item) {
          return;
        }
        const target = item.getAttribute('data-notification-target');
        const element =
          document.getElementById(target) ??
          document.querySelector(`[data-selectable="${CSS.escape(target)}"]`);
        if (!element) {
          return;
        }
        event.preventDefault();
        const editMode = Drupal.Daedalus.ModeManager.getPlugin('edit');
        editMode.scrollToElement(element);
        editMode.highlight(element);
      };
      listContainer.onclick = activate;
      listContainer.onkeydown = (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          activate(event);
        }
      };
    }

    /**
     * Whether the panel is showing, so a history write is worth a render.
     *
     * @return {boolean}
     *   True while the notifications panel is open.
     */
    isShowing() {
      const sidebar = this.getElement('notifications');
      return !!sidebar && !sidebar.classList.contains('daedalus-ui-hidden');
    }

    /**
     * Open the sidebar
     *
     * @param {string|null} id
     *   The specific sidebar instance ID to open
     *
     * @return {Promise}
     *   Resolves when the sidebar is opened.
     */
    open(id = null) {
      return super.open(id).then(() => {
        this.renderMessages();
      });
    }
  }

  // Make NotificationsSidebar available globally
  Drupal.Daedalus.NotificationsSidebar = NotificationsSidebar;

  // Register the notifications sidebar plugin
  const sidebarManager = Drupal.Daedalus.SidebarManager;
  const notificationsSidebar = new NotificationsSidebar();
  sidebarManager.registerPlugin(notificationsSidebar);

  // A history write re-renders the open panel. The browser fires `storage`
  // in every same-origin window but the writer (daedalus_stylish's satellite writes
  // this key too); the messenger fires the same event in its own window, so
  // this one listener covers every writer. The messenger is a deferred
  // module, so its key is read at event time, not here at parse time.
  window.addEventListener('storage', (event) => {
    if (event.key !== Drupal.Daedalus.messenger.historyKey) {
      return;
    }
    if (notificationsSidebar.isShowing()) {
      notificationsSidebar.renderMessages();
    }
  });

  /**
   * Initialize notifications on page load
   */
  Drupal.behaviors.DaedalusUiNotificationsInit = {
    attach: (context, settings) => {
      // If the notifications sidebar is visible on page load, render messages
      once('notifications-init', '#notifications', context).forEach(
        (sidebar) => {
          if (!sidebar.classList.contains('daedalus-ui-hidden')) {
            notificationsSidebar.renderMessages();
          }
        },
      );
    },
  };

  /**
   * Theme function for empty notifications state.
   *
   * @return {string}
   *   HTML markup for empty state.
   */
  Drupal.theme.notificationsEmpty = function () {
    return `<div class="notifications-empty daedalus-ui-panel-empty">${Drupal.t(
      'No notifications yet.',
    )}</div>`;
  };

  /**
   * Theme function for a single notification item.
   *
   * @param {Object} notification
   *   The history entry: message, type, timestamp and an optional target.
   * @param {string} formattedTime
   *   The formatted timestamp string.
   *
   * @return {string}
   *   HTML markup for the notification item.
   */
  Drupal.theme.notificationItem = function (notification, formattedTime) {
    // A notification with an element target (DOM id or selectable key) is
    // clickable: the list's delegated handler scrolls back to the element.
    const target = notification.target
      ? ` data-notification-target="${Drupal.checkPlain(notification.target)}" role="button" tabindex="0"`
      : '';
    return `<div class="notification-item notification-item--${notification.type}${notification.target ? ' notification-item--linked' : ''}"${target}>
      <div class="notification-item__content">
        <div class="notification-item__message">${notification.message}</div>
        <div class="notification-item__time">${formattedTime}</div>
      </div>
    </div>`;
  };
})(jQuery, Drupal, once);
