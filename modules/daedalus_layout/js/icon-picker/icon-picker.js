(($, Drupal, once) => {
  /**
   * The icon picker's client half.
   *
   * The server half is IconPickerOpener: a media library pick returns as
   * two InvokeCommands on the picker's hidden input — `val` writes the
   * canonical grammar value (media:<uuid>), then a jQuery `daedalusLayoutIconPicked`
   * trigger carries the server-resolved preview URL (null when the media
   * has no file). This behavior routes that event to the preview and wires
   * the Remove button; the value only persists when the host form is saved.
   *
   * @see Drupal\daedalus_layout\Element\IconPicker
   * @see Drupal\daedalus_layout\IconPickerOpener
   */
  Drupal.behaviors.daedalusLayoutIconPicker = {
    attach: (context) => {
      once(
        'daedalus-layout-icon-picker',
        '[data-daedalus-layout-icon-picker]',
        context,
      ).forEach((wrapper) => {
        const input = wrapper.querySelector('[data-daedalus-layout-icon-picker-value]');
        const preview = wrapper.querySelector('.daedalus-layout-icon-picker__preview');
        const remove = wrapper.querySelector('.daedalus-layout-icon-picker__remove');
        if (!input || !preview) {
          return;
        }
        // jQuery event: InvokeCommand's `trigger` rides jQuery's event
        // system, and its extra-parameters (the preview URL) only arrive
        // through a jQuery listener.
        $(input).on('daedalusLayoutIconPicked', (e, url) => {
          preview.src = url || preview.dataset.defaultIcon;
          wrapper.classList.add('has-icon');
          if (remove) {
            // Inline style, mirroring the server render: the admin theme
            // layers module CSS, so a class-driven display rule would lose
            // to the theme's unlayered .button styles.
            remove.style.display = '';
          }
        });
        remove?.addEventListener('click', () => {
          input.value = '';
          preview.src = preview.dataset.defaultIcon;
          wrapper.classList.remove('has-icon');
          remove.style.display = 'none';
        });
      });
    },
  };
})(jQuery, Drupal, once);
