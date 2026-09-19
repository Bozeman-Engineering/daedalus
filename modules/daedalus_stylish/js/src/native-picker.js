import { h } from 'preact';
import { useRef } from 'preact/hooks';
import { pickerHex } from './color-field-model.js';

/**
 * The browser's own color parse as a picker hex: what the pure parser
 * cannot read (a named color, hsl(), the CSS Color 4 spaces) goes
 * through a 2D canvas fill style, whose getter serializes an sRGB color
 * as #rrggbb when opaque (rgba() otherwise, re-read by the pure parser).
 * Two seeds tell an unparsable spelling (the style stays put both times)
 * from a genuinely black one. Null outside a document (node), or when the
 * browser keeps the spelling in another color space.
 *
 * @param {string} spelling
 *
 * @return {?string}
 */
let probeContext = null;
export const browserHex = (spelling) => {
  if (typeof document === 'undefined') {
    return null;
  }
  probeContext =
    probeContext ?? document.createElement('canvas').getContext('2d');
  const context = probeContext;
  if (!context) {
    return null;
  }
  const seeded = ['#000000', '#ffffff'].map((seed) => {
    context.fillStyle = seed;
    context.fillStyle = spelling;
    return context.fillStyle;
  });
  if (seeded[0] !== seeded[1]) {
    return null;
  }
  return pickerHex(seeded[0]);
};

/**
 * The native color picker behind a lane: one hidden `<input type=color>`
 * and the gesture that opens it.
 *
 * The browser's own color chooser is the ONE door to a custom color on
 * every color surface (the color field's face grid, the basic wells'
 * popovers), reached through the shared palette popover's color lane
 * (palette-picker.js usePalettePopover `lanes`), never through a visible
 * swatch of its own: the well or face already wears the value, so a
 * second painted control beside it was a second door to the same thing.
 * The input stays in the consumer's field (outside the popover, which
 * closes before the lane runs) so it is still mounted when the chooser
 * fires its events, and it stays RENDERED (visually hidden, not
 * display:none) so showPicker() has an element to anchor to. The
 * consumer's mount point must be POSITIONED: the input pins to its
 * top-left, and the browser anchors the chooser popup to the input's
 * rect (an unpositioned parent lands it at the scroll container's end,
 * off-screen).
 *
 * The picker's events are the consumer's own literal path: the input
 * event previews (the wheel drags live), the change event settles (the
 * chooser closed on a value), exactly what the visible swatch used to
 * wire, so the FJS story drives the hidden input with the same two
 * events the native wheel fires.
 *
 * @param {object} options
 * @param {?string} options.hex
 *   The picker's face as #rrggbb (color-field-model.js pickerHex), or
 *   null when the current value is beyond the picker: the input then
 *   disables and open() is a no-op, so the consumer disables its lane
 *   with the reason.
 * @param {boolean} [options.disabled]
 *   A consumer-side gate over the picker beyond the hex rule.
 * @param {Function} options.onInput
 *   The preview handler (the raw event; `event.target.value` is the hex).
 * @param {Function} options.onChange
 *   The settle handler (the raw event).
 *
 * @return {{input: object, open: Function}}
 *   The hidden input vnode the consumer mounts beside its field, and the
 *   opener: showPicker() where the browser has it (Chrome 99+, Firefox
 *   101+, Safari 16+; it needs the transient user activation a lane click
 *   carries), the input's own click() otherwise.
 */
export const useNativePicker = ({
  hex,
  disabled = false,
  onInput,
  onChange,
}) => {
  const ref = useRef(null);
  const available = hex !== null && !disabled;

  const open = () => {
    const input = ref.current;
    if (!input || input.disabled) {
      return;
    }
    if (typeof input.showPicker === 'function') {
      input.showPicker();
      return;
    }
    input.click();
  };

  const input = (
    <input
      ref={ref}
      type="color"
      className="daedalus-stylish-native-picker"
      tabIndex={-1}
      aria-hidden="true"
      disabled={!available}
      value={hex ?? '#000000'}
      onInput={onInput}
      onChange={onChange}
    />
  );

  return { input, open };
};

/**
 * The color lane's glyph: a hue wheel, painted by CSS
 * (.daedalus-stylish-native-picker__glyph), so every color lane reads the same.
 *
 * @param {object} props
 * @param {?string} [props.className]
 */
export const PickerGlyph = ({ className = null }) => (
  <span
    className={`daedalus-stylish-native-picker__glyph${className ? ` ${className}` : ''}`}
    aria-hidden="true"
  />
);

/**
 * The color lane over a face: the hidden picker plus the lane entry the
 * shared popover renders ("Pick a color").
 *
 * One rule for every color surface (the color field, the basic wells):
 * the picker wears the face's paint brought to #rrggbb (pickerHex over
 * the pure parse, then the browser's own parse; never black over a legal
 * value), an UNSET value seeds the chooser's default (the lane must open
 * on an empty row: with the visible swatch gone it is the only picker
 * door), and a set spelling beyond both parsers disables the lane with
 * the reason on its title.
 *
 * @param {object} options
 * @param {{kind: string, paint: ?string}} options.face
 *   colorFace's answer for the shown value.
 * @param {boolean} [options.disabled]
 *   A consumer-side gate (a closed space, an unmounted field): the input
 *   disables and the lane stays out of the consumer's lane row.
 * @param {Function} options.onInput
 *   The preview handler (the raw event).
 * @param {Function} options.onChange
 *   The settle handler (the raw event).
 *
 * @return {{input: object, lane: object}}
 *   The hidden input vnode (mounted in the consumer's positioned field)
 *   and the lane entry for usePalettePopover's `lanes`.
 */
export const useColorLane = ({ face, disabled = false, onInput, onChange }) => {
  const hex =
    face.kind === 'unset' ? '#000000' : pickerHex(face.paint, browserHex);
  const picker = useNativePicker({ hex, disabled, onInput, onChange });
  const lane = {
    id: 'color',
    icon: <PickerGlyph />,
    label: Drupal.t('Pick a color'),
    disabled: hex === null,
    title:
      hex === null
        ? Drupal.t('This spelling is beyond the picker; edit the text instead.')
        : Drupal.t('Open the color picker'),
    run: picker.open,
  };
  return { input: picker.input, lane };
};
