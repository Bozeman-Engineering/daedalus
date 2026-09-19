/**
 * @file
 * Chat panel niceties: scroll to the newest message when the panel opens.
 *
 * The single <deep-chat> element is long-lived and renders while the panel
 * is hidden (zero dimensions), so its own load-time auto-scroll can land at
 * the top of the restored history. No tool opens the sidebar, so the
 * panel's visibility class is the open signal, watched through a
 * MutationObserver like the Add panel's.
 */

((Drupal, once) => {
  Drupal.behaviors.daedalusAiChatPanelScroll = {
    attach(context) {
      once('daedalus-ai-chat-panel-scroll', '#daedalus_ai', context).forEach(
        (panel) => {
          const scrollToBottom = () => {
            if (!panel.classList.contains('daedalus-ui-hidden')) {
              setTimeout(
                () => panel.querySelector('deep-chat')?.scrollToBottom?.(),
                100,
              );
            }
          };
          new MutationObserver(scrollToBottom).observe(panel, {
            attributes: true,
            attributeFilter: ['class'],
          });
          scrollToBottom();
        },
      );
    },
  };
})(Drupal, once);
