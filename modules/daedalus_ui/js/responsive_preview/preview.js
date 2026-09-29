/**
 * @file
 * The responsive preview shell: a device selector over a device-sized iframe.
 *
 * The iframe is the device viewport (the popup window itself cannot be:
 * browsers clamp popups to a minimum outer width well above phone size), so
 * media and container queries respond to the selected device's dimensions.
 * The src arrives pre-validated from the controller with
 * navigationMode=preview already forced onto it, which renders the staged
 * tempstore state without editing chrome.
 *
 * Live updates: this file is a native module script (served standalone, see
 * the library definition) so it can import daedalus_ui's shared
 * BroadcastChannel wire relative to its own URL. It listens on prefix
 * 'preview' with the pop-out document's session scope (the opener's
 * ?scope, delivered as daedalusUi.popout.scope by the pop-out route
 * contract), and reloads the iframe on the editing window's mutated pings,
 * preserving the scroll position.
 */
import { openChannel } from '../broadcast/channel.js';
import { createReloadCoordinator } from './reload-coordinator.js';
import { createDeviceList } from './device-list.js';

(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.daedalusUiResponsivePreview = {
    attach: (context) => {
      once(
        'DaedalusUiResponsivePreview',
        '#daedalus-ui-responsive-preview-root',
        context,
      ).forEach((root) => {
        const { devices, userDevices, src } =
          drupalSettings.daedalusUi.responsivePreview;
        const { scope } = drupalSettings.daedalusUi.popout;
        root.classList.add('responsive-preview');

        const header = document.createElement('div');
        header.className = 'responsive-preview__header';

        const label = document.createElement('label');
        label.className = 'responsive-preview__device-label';
        label.htmlFor = 'responsive-preview-device';
        label.textContent = Drupal.t('Device');

        // The user's own devices persist through the generic editor-settings
        // endpoint (namespace daedalus_ui): the whole list is POSTed as
        // JSON on every add/remove, last write wins. State changes apply
        // optimistically; a failed save is surfaced in the message line.
        const list = createDeviceList({
          defaults: devices,
          custom: [...(userDevices ?? [])],
          persist: (all) =>
            fetch(
              Drupal.url(
                'daedalus-ui/setting/daedalus_ui/responsive_preview_devices',
              ),
              {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(all),
              },
            ).then((response) => {
              if (!response.ok) {
                throw new Error(
                  Drupal.t('Saving devices failed (HTTP @status).', {
                    '@status': response.status,
                  }),
                );
              }
            }),
        });

        const select = document.createElement('select');
        select.id = 'responsive-preview-device';
        select.className = 'responsive-preview__device';

        const renderOptions = (selectedKey) => {
          select.replaceChildren();
          const groups = [
            { label: Drupal.t('Defaults'), custom: false },
            { label: Drupal.t('My devices'), custom: true },
          ];
          groups.forEach((group) => {
            const entries = list
              .entries()
              .filter((entry) => entry.custom === group.custom);
            if (!entries.length) {
              return;
            }
            const optgroup = document.createElement('optgroup');
            optgroup.label = group.label;
            entries.forEach((entry) => {
              const option = document.createElement('option');
              option.value = entry.key;
              option.textContent = entry.device.label;
              optgroup.append(option);
            });
            select.append(optgroup);
          });
          select.value = selectedKey;
        };
        renderOptions('default:0');

        const size = document.createElement('span');
        size.className = 'responsive-preview__size';

        const message = document.createElement('span');
        message.className = 'responsive-preview__message';
        message.setAttribute('role', 'alert');

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'responsive-preview__button';
        removeButton.textContent = Drupal.t('Remove');
        removeButton.title = Drupal.t('Remove this device from my devices');

        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'responsive-preview__button';
        addButton.textContent = Drupal.t('Add device');

        const stage = document.createElement('div');
        stage.className = 'responsive-preview__stage';

        const iframe = document.createElement('iframe');
        iframe.className = 'responsive-preview__frame';
        iframe.title = Drupal.t('Page preview');
        iframe.src = src;

        // Live updates: the editing window posts a 'mutated' ping on the
        // shared wire (op-queue's applyResponse) every time a change lands.
        // Edits stage server-side in tempstore, so a plain reload always
        // renders the staged state; the coordinator preserves the reader's
        // scroll position and coalesces pings that arrive mid-reload.
        // No scope means no edit session to pair with (the opener always
        // sends one; a hand-typed popup URL may not): the preview is then
        // static.
        const coordinator = createReloadCoordinator({
          capture: () => {
            const win = iframe.contentWindow;
            return win ? { x: win.scrollX, y: win.scrollY } : null;
          },
          reload: () => iframe.contentWindow.location.reload(),
          restore: ({ x, y }) => iframe.contentWindow.scrollTo(x, y),
        });
        iframe.addEventListener('load', () => coordinator.loaded());
        if (scope) {
          openChannel('preview', scope, {
            mutated: () => coordinator.mutated(),
          });
        }

        const applyDevice = () => {
          const device = list.get(select.value);
          iframe.style.width = `${device.width}px`;
          iframe.style.height = `${device.height}px`;
          size.textContent = `${device.width} × ${device.height}`;
          removeButton.disabled = !list.isCustom(select.value);
        };
        select.addEventListener('change', applyDevice);
        applyDevice();

        // The add form: name/width/height, revealed by the Add device
        // button. A real form so Enter submits.
        const form = document.createElement('form');
        form.className = 'responsive-preview__form';
        form.hidden = true;

        const field = (labelText, name, type, attributes = {}) => {
          const wrapper = document.createElement('label');
          wrapper.className = 'responsive-preview__field';
          wrapper.append(labelText);
          const input = document.createElement('input');
          input.name = name;
          input.type = type;
          input.required = true;
          Object.assign(input, attributes);
          wrapper.append(input);
          return wrapper;
        };
        form.append(
          field(Drupal.t('Name'), 'label', 'text', {
            size: 14,
            placeholder: Drupal.t('e.g. Kiosk'),
          }),
          field(Drupal.t('Width'), 'width', 'number', { min: 50, max: 10000 }),
          field(Drupal.t('Height'), 'height', 'number', {
            min: 50,
            max: 10000,
          }),
        );

        const saveButton = document.createElement('button');
        saveButton.type = 'submit';
        saveButton.className = 'responsive-preview__button';
        saveButton.textContent = Drupal.t('Save');
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'responsive-preview__button';
        cancelButton.textContent = Drupal.t('Cancel');
        form.append(saveButton, cancelButton);

        const surface = (result) => {
          if (result.error) {
            message.textContent = result.error;
            return null;
          }
          message.textContent = '';
          renderOptions(result.key);
          applyDevice();
          // Optimistic UI: the list already changed; a failed save only
          // means it will not stick, so say exactly that.
          result.persisted.catch((error) => {
            message.textContent = error.message;
          });
          return result.key;
        };

        addButton.addEventListener('click', () => {
          form.hidden = !form.hidden;
          if (!form.hidden) {
            form.elements.label.focus();
          }
        });
        cancelButton.addEventListener('click', () => {
          form.reset();
          form.hidden = true;
          message.textContent = '';
        });
        form.addEventListener('submit', (event) => {
          event.preventDefault();
          const added = surface(
            list.add({
              label: form.elements.label.value,
              width: form.elements.width.value,
              height: form.elements.height.value,
            }),
          );
          if (added) {
            form.reset();
            form.hidden = true;
          }
        });
        removeButton.addEventListener('click', () => {
          surface(list.remove(select.value));
        });

        header.append(label, select, size, addButton, removeButton, message);
        stage.append(iframe);
        root.append(header, form, stage);
      });
    },
  };
})(Drupal, drupalSettings, once);
