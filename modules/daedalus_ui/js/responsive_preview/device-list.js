/**
 * @file
 * The popup's device list: shipped defaults plus the user's own devices.
 *
 * A pure state module, DOM-free so node can test it. Defaults are fixed
 * (every user gets them, they cannot be removed); user devices are appended
 * after them and support add/remove. Every change hands the complete user
 * list to the injected persist callback, which the shell wires to the
 * generic editor-settings endpoint: storage is last-write-wins on the whole
 * list, so there is no per-device wire grammar to keep in sync.
 *
 * Entries are addressed by a stable string key ('default:2', 'custom:0')
 * rather than a flat index, so the shell's select options survive the list
 * being rebuilt around them.
 */

/**
 * Dimension bounds, mirrored from ResponsivePreview::MIN/MAX_DIMENSION.
 *
 * The server drops out-of-bounds entries on read; validating here too means
 * the user hears about a typo at entry time instead of the device silently
 * vanishing on the next popup open.
 */
export const MIN_DIMENSION = 50;
export const MAX_DIMENSION = 10000;

/**
 * Validates a candidate device.
 *
 * @param {Object} device
 *   The candidate ({label, width, height}); dimensions may still be
 *   strings fresh from form inputs.
 *
 * @return {string|null}
 *   A human-readable problem, or null when the device is valid.
 */
export const validateDevice = ({ label, width, height }) => {
  if (!String(label ?? '').trim()) {
    return 'A device needs a name.';
  }
  for (const value of [width, height]) {
    if (!/^\d+$/.test(String(value ?? '').trim())) {
      return 'Width and height must be whole numbers of pixels.';
    }
    const pixels = Number(value);
    if (pixels < MIN_DIMENSION || pixels > MAX_DIMENSION) {
      return `Width and height must be between ${MIN_DIMENSION} and ${MAX_DIMENSION} pixels.`;
    }
  }
  return null;
};

/**
 * Creates the device list state.
 *
 * @param {Object} options
 * @param {Array} options.defaults
 *   The shipped default devices ({label, width, height}).
 * @param {Array} options.custom
 *   The user's stored devices ({label, width, height}).
 * @param {Function} options.persist
 *   Receives the complete user device list after every change; returns a
 *   promise. The list mutates optimistically; persistence failures are the
 *   caller's to surface.
 *
 * @return {Object}
 *   The list API: entries(), get(key), isCustom(key), add(device),
 *   remove(key).
 */
export const createDeviceList = ({ defaults, custom, persist }) => {
  const entries = () => [
    ...defaults.map((device, i) => ({
      key: `default:${i}`,
      device,
      custom: false,
    })),
    ...custom.map((device, i) => ({
      key: `custom:${i}`,
      device,
      custom: true,
    })),
  ];

  const get = (key) =>
    entries().find((entry) => entry.key === key)?.device ?? null;

  return {
    entries,
    get,
    isCustom: (key) => key.startsWith('custom:'),

    /**
     * Adds a user device; returns {key, persisted} or {error}.
     */
    add: (device) => {
      const error = validateDevice(device);
      if (error) {
        return { error };
      }
      custom.push({
        label: String(device.label).trim(),
        width: Number(device.width),
        height: Number(device.height),
      });
      return {
        key: `custom:${custom.length - 1}`,
        persisted: persist([...custom]),
      };
    },

    /**
     * Removes a user device; returns {key, persisted} or {error}.
     *
     * The returned key is the entry to select next: the one that slid into
     * the removed slot, or the last remaining entry.
     */
    remove: (key) => {
      const index = Number(key.split(':')[1]);
      if (!key.startsWith('custom:') || !custom[index]) {
        return { error: 'Only your own devices can be removed.' };
      }
      custom.splice(index, 1);
      const remaining = entries();
      const next =
        remaining[Math.min(defaults.length + index, remaining.length - 1)];
      return { key: next.key, persisted: persist([...custom]) };
    },
  };
};
