(($, Drupal, once) => {
  /**
   * Hotkeys settings tray.
   *
   * Renders every labeled binding from the hotkey registry
   * (Drupal.Daedalus.Hotkeys), grouped by section — the server only
   * provides the empty .hotkeys-list container, so anything registered
   * client-side (tools, verbs, contrib bindings) is listed with no
   * server-side knowledge of it. The tray doubles as the documentation of
   * what exists: non-remappable bindings render locked rather than being
   * hidden. Clicking a remappable binding enters capture mode: the next
   * key combo becomes the binding. A bare modifier (for hold-style
   * bindings) is committed on its keyup, so modifier+key combos can still
   * be formed.
   */

  const MODIFIER_KEYS = ['alt', 'meta', 'control', 'shift', 'os'];

  const displayText = (key) => (key ? key.toUpperCase() : '—');

  const saveBinding = (id, bindingString) => {
    const hotkeys = Drupal.Daedalus.Hotkeys;
    hotkeys.rebind(id, bindingString);
    if (drupalSettings.daedalusUi?.hotKeys?.[id]) {
      drupalSettings.daedalusUi.hotKeys[id].key = bindingString;
    }

    const ajaxConfig = {
      url: `/daedalus-ui/save-user-hotkey/${id}/${encodeURIComponent(bindingString)}`,
      event: 'click',
      progress: {
        type: 'fullscreen',
        message: Drupal.t('Updating hotkey...'),
      },
      error: (error) => {
        console.error('Unable to update hotkey: ', error.responseText || error);
        Drupal.Daedalus.ModeManager.getPlugin('edit').handleError(
          error,
          'Unable to update hotkey.',
        );
      },
    };
    Drupal.Daedalus.ModePluginBase.ajax(ajaxConfig).execute();
  };

  const buildRow = (binding) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'setting-wrapper daedalus-ui-details__item';
    wrapper.append(binding.label, ' ');

    const display = document.createElement('div');
    display.className = 'configured-hotkey';
    display.dataset.bindingId = binding.id;
    display.textContent = displayText(binding.key);
    wrapper.appendChild(display);

    if (!binding.remappable) {
      display.classList.add('configured-hotkey--locked');
      display.title = Drupal.t('This hotkey cannot be changed.');
      return wrapper;
    }

    const input = document.createElement('input');
    input.style.display = 'none';
    input.readOnly = true;
    input.placeholder = Drupal.t('Press a key…');
    wrapper.appendChild(input);

    const endCapture = () => {
      input.style.display = 'none';
      display.style.display = 'inline';
      delete input.dataset.pendingModifier;
    };

    display.addEventListener('click', () => {
      display.style.display = 'none';
      input.style.display = 'inline';
      input.focus();
    });

    input.addEventListener('keydown', (e) => {
      e.preventDefault();
      e.stopPropagation();

      if (e.key === 'Escape') {
        endCapture();
        return;
      }
      const bindingString =
        Drupal.Daedalus.Hotkeys.eventToBindingString(e);
      if (bindingString === null) {
        // A bare modifier went down: commit it on keyup (hold-style
        // bindings), unless more keys turn it into a combo.
        input.dataset.pendingModifier =
          e.key.toLowerCase() === 'control' ? 'ctrl' : e.key.toLowerCase();
        return;
      }
      display.textContent = displayText(bindingString);
      endCapture();
      saveBinding(binding.id, bindingString);
    });

    input.addEventListener('keyup', (e) => {
      const pending = input.dataset.pendingModifier;
      if (!pending || !MODIFIER_KEYS.includes(e.key.toLowerCase())) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      const bindingString = pending === 'os' ? 'meta' : pending;
      display.textContent = displayText(bindingString);
      endCapture();
      saveBinding(binding.id, bindingString);
    });

    input.addEventListener('blur', endCapture);

    return wrapper;
  };

  const render = (list) => {
    // The list is wiped and rebuilt on every registry change, and changes
    // arrive mid-session (verb bindings ride other modules' AJAX-delivered
    // scripts) — carry the user's open/closed choices across the rebuild.
    const openStates = new Map();
    list.querySelectorAll(':scope > details > summary').forEach((summary) => {
      openStates.set(summary.textContent, summary.parentElement.open);
    });
    list.innerHTML = '';
    const fallback = Drupal.t('Other');
    const groups = new Map();
    Drupal.Daedalus.Hotkeys.getBindings()
      .filter((binding) => binding.label)
      .forEach((binding) => {
        const section = binding.section ?? fallback;
        if (!groups.has(section)) {
          groups.set(section, []);
        }
        groups.get(section).push(binding);
      });
    // The core sections render in a fixed order regardless of registration
    // timing (server tool bindings often arrive late via AJAX merge);
    // contrib sections follow in encounter order, the catch-all last.
    const preferred = [
      Drupal.t('Tools'),
      Drupal.t('Selection'),
      Drupal.t('Editing'),
      Drupal.t('Actions'),
    ];
    const sections = [
      ...preferred.filter((section) => groups.has(section)),
      ...[...groups.keys()].filter(
        (section) => !preferred.includes(section) && section !== fallback,
      ),
      ...(groups.has(fallback) ? [fallback] : []),
    ];
    // Each section is a native drop-down wearing the shared .daedalus-ui-details
    // recipe, nested inside the server-rendered Hotkeys group. The tray
    // doubles as documentation, so sections default open.
    sections.forEach((section) => {
      const details = document.createElement('details');
      details.className = 'daedalus-ui-details';
      details.open = openStates.get(section) ?? false;
      const summary = document.createElement('summary');
      summary.textContent = section;
      details.appendChild(summary);
      const body = document.createElement('div');
      body.className = 'daedalus-ui-details__body';
      groups
        .get(section)
        .forEach((binding) => body.appendChild(buildRow(binding)));
      details.appendChild(body);
      list.appendChild(details);
    });
  };

  Drupal.behaviors.HotkeysConfig = {
    attach(context, settings) {
      once('hotkeys-config', '.hotkeys-list', context).forEach((list) => {
        // The registry lives in the app bundle, which loads independently
        // of this behavior; the queue runs the setup immediately when the
        // app is live, or at app boot when it isn't yet.
        (window.DaedalusUiQueue ||= []).push(() => {
          // The registry keeps filling after this behavior attaches — verb
          // hotkeys ride other modules' scripts, whose delivery order is
          // not guaranteed (AJAX/big_pipe) — so the list re-renders on
          // registry changes instead of snapshotting once. Renders coalesce
          // on a microtask: getBindings() lazily registers the server's
          // tool bindings, and a synchronous re-render inside render()
          // would duplicate rows.
          let queued = false;
          const scheduleRender = () => {
            if (queued) {
              return;
            }
            queued = true;
            queueMicrotask(() => {
              queued = false;
              if (list.isConnected) {
                render(list);
              } else {
                // A re-rendered sidebar delivers a fresh list element with
                // its own once-attach; this subscription dies with its list.
                unsubscribe();
              }
            });
          };
          const unsubscribe =
            Drupal.Daedalus.Hotkeys.onChange(scheduleRender);
          scheduleRender();
        });
      });
    },
  };
})(jQuery, Drupal, once);
