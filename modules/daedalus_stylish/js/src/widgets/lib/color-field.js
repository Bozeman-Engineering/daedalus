import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { Face } from '../../face.js';
import {
  colorFace,
  gridEntries,
  literalSettle,
} from '../../color-field-model.js';
import { stopTitle, usePalettePopover } from '../../palette-picker.js';
import { useColorLane } from '../../native-picker.js';
import { useMintRow } from '../../mint-row.js';

/**
 * The color field: one editor for every color slot in the inspector,
 * over the property's value space.
 *
 * Replaces the advanced color row's native picker + text + six-digit
 * hex regex, the `{}` select, and the color swatch kind's face-as-picker
 * editor with ONE anatomy the value space shapes:
 * - The select of stops: a face trigger wearing the current value
 *   (colorFace: the worn stop's paint pair, a rostered reference's, a
 *   literal's own paint, the slashed empty face, the raw spelling for a
 *   reference no roster knows) that opens the shared palette popover
 *   over the space's stops as faces (gridEntries; usePalettePopover, the
 *   swatch well's chrome). A pick commits the stop's reference spelling;
 *   the lit stop's pick unsets (the Segments contract) where the
 *   consumer allows it.
 * - The color lane, an OPEN space's literal path: the popover's lane
 *   row carries "Pick a color", which closes the popover and opens the
 *   native color picker (native-picker.js useColorLane: a hidden input
 *   mounted in the field, the one door to the browser's chooser, its
 *   face the current paint as #rrggbb under the lane's own rule). Its
 *   input previews and its change settles like typing.
 * - Free text, an open space's other literal path: the display value of
 *   a worn reference (values on every face, the reference on the title),
 *   the spelling of a literal; typing previews per input event and the
 *   settle (blur, Enter) runs the literal settle rule
 *   (color-field-model.js literalSettle: magnetic capture on a detented
 *   space, since typing a swatch's hex IS reaching the swatch; an
 *   emptied field settles '' for unset). An untouched blur never
 *   rewrites provenance.
 * - Save as swatch, the popover's mint row (mint-row.js useMintRow)
 *   when the gate allows and the value is a literal color on an open
 *   space (mintOffered): the site-wide mint through the material op, its
 *   landed record referenced by this value from then on.
 * A CLOSED space renders the select alone (faces in the select, the
 * honest extra face for an off-vocabulary committed value): stops only,
 * no lane, no text, exactly what the server accepts there.
 *
 * A hook plus a component: the advanced row places the inline cluster
 * (face | text) on its line and the popover floats under the face (two
 * slots, one preact subtree each, the gradient-chip reason), while the
 * swatch editor stacks both (ColorField). Pure client over the roster and the
 * handlers, so it renders in the satellite unchanged.
 *
 * @param {object} options
 * @param {?object} options.space
 *   The property's value space (valueSpaceFor), or null to mount nothing
 *   (a row of another shape; the hooks still run, unconditionally).
 * @param {string} options.value
 *   The authoritative value (the consumer's committed or draft-layered
 *   value); the field layers its own typing over it until it changes.
 * @param {?object} options.guide
 *   The style guide payload.
 * @param {object} options.materials
 *   The materials roster.
 * @param {string} [options.condition]
 *   The editing compound.
 * @param {string} options.label
 *   The accessible name (the text input's aria-label, the grid's name).
 * @param {?string} [options.placeholder]
 *   The empty field's ghost (an inherited value, a skin default).
 * @param {boolean} [options.invalid]
 *   The row's rejected-commit state.
 * @param {boolean} [options.unsettable]
 *   Whether the lit stop's pick unsets (false for an editor whose value
 *   must never be empty: the swatch kind's).
 * @param {boolean} [options.canMint]
 *   The save-as-swatch grant (honest chrome; the op enforces).
 * @param {?object} [options.source]
 *   The state source (the mint commits through its batch commit; required
 *   with canMint).
 * @param {Function} options.onPreview
 *   Called with the raw text per input event ('' while emptied).
 * @param {Function} options.onSettle
 *   Called with the commit spelling on settle: a stop's reference, a
 *   captured reference, the literal as typed, or '' for unset.
 *
 * @return {{open: boolean, face: object, field: ?object, popover: ?object}}
 */
export const useColorField = ({
  space = null,
  value,
  guide = null,
  materials,
  condition = 'default',
  label,
  placeholder = null,
  invalid = false,
  unsettable = true,
  canMint = false,
  source = null,
  onPreview,
  onSettle,
}) => {
  const committed = typeof value === 'string' ? value : '';
  // The field's own draft (the field-gesture idiom): typed or picked
  // text echoes into the face, the picker and the text at once, whatever
  // the consumer does with the preview (a basic widget paints the canvas
  // only; the advanced row echoes it back through `value`). A new
  // authoritative value (the settled commit, an AI edit, a selection
  // move) supersedes it.
  const [draft, setDraft] = useState(null);
  useEffect(() => {
    setDraft(null);
  }, [committed]);
  const shown = draft ?? committed;
  const mounted = space !== null;
  const face = mounted
    ? colorFace(guide, materials, space, shown, condition)
    : {
        kind: 'unset',
        spelling: '',
        text: '',
        paint: null,
        paintDark: null,
        stop: null,
        token: null,
      };
  const closed = mounted && space.kind === 'closed';
  const grid = mounted
    ? gridEntries(space, face)
    : { entries: [], selected: null };
  // Whether the input received typed (or picked) text since the last
  // settle: a blur or Enter on an untouched field must never rewrite
  // provenance (the input shows a worn reference's display value, and
  // settling that text would commit the literal over the reference).
  const typedRef = useRef(false);
  const type = (text) => {
    typedRef.current = true;
    setDraft(text);
    onPreview(text);
  };

  const settleText = (text) => {
    const typed = typedRef.current;
    typedRef.current = false;
    if (!typed) {
      return;
    }
    const { spelling, preview } = literalSettle(space, text);
    if (preview !== null) {
      onPreview(preview);
    }
    onSettle(spelling);
  };

  // The color lane's hidden picker (native-picker.js): its input
  // previews and its change settles exactly like typing.
  const picker = useColorLane({
    face,
    disabled: !mounted || closed,
    onInput: (event) => type(event.target.value),
    onChange: (event) => settleText(event.target.value),
  });

  const mint = useMintRow({
    space,
    face,
    canMint,
    source,
    onSettle: (reference) => {
      typedRef.current = false;
      onSettle(reference);
    },
  });

  const palette = usePalettePopover({
    stops: grid.entries,
    selected: unsettable ? grid.selected : null,
    label,
    onPick: (stop) => {
      typedRef.current = false;
      if (stop === null) {
        onSettle('');
        return;
      }
      onPreview(stop.value);
      onSettle(stop.value);
    },
    lanes: closed ? [] : [picker.lane],
    below: mint.row,
  });

  if (!mounted) {
    return { open: false, face, field: null, popover: null };
  }

  const triggerTitle = (() => {
    switch (face.kind) {
      case 'stop':
        return stopTitle(
          grid.entries.find((entry) => entry.id === face.stop.ref) ?? {
            label: face.text,
            paint: face.paint,
            paintDark: face.paintDark,
          },
        );
      case 'reference':
        return Drupal.t('@name (@reference): pick a palette value', {
          '@name': face.token.name,
          '@reference': shown.trim(),
        });
      case 'literal':
        return Drupal.t(
          'A custom color: @value. Pick a palette value to replace it, or open the well for the color picker.',
          { '@value': face.text },
        );
      case 'unknown':
        return Drupal.t(
          'A reference the palette does not know: @value. Pick a palette value to replace it.',
          { '@value': face.text },
        );
      default:
        return Drupal.t('Pick a palette value');
    }
  })();

  const field = (
    <span
      className={`daedalus-stylish-color-field${closed ? ' daedalus-stylish-color-field--closed' : ''}${face.token ? ' daedalus-stylish-color-field--blessed' : ''}${invalid ? ' daedalus-stylish-color-field--invalid' : ''}`}
    >
      <button
        {...palette.triggerProps}
        type="button"
        className={`daedalus-stylish-color-field__well daedalus-stylish-color-field__well--${face.kind}${palette.open ? ' daedalus-stylish-color-field__well--open' : ''}`}
        title={triggerTitle}
        disabled={grid.entries.length === 0 && closed}
      >
        {face.kind !== 'unset' && (
          <Face
            className="daedalus-stylish-color-field__face"
            paint={face.paint}
            paintDark={face.paintDark}
          />
        )}
      </button>
      {closed ? (
        /* The closed input: the face IS the select; its text is the worn
           display value (the material's name on the title), the honest
           extra entry rides the grid for an off-vocabulary value. */
        <span
          className="daedalus-stylish-color-field__text daedalus-stylish-color-field__text--closed daedalus-ui-input"
          aria-label={label}
        >
          {face.kind === 'unset'
            ? (placeholder ?? Drupal.t('- Unset -'))
            : face.text}
        </span>
      ) : (
        /* The hidden picker rides the field, not the popover: the lane
           closes the popover before it opens the chooser, and the
           chooser's events must still land on a mounted input. */
        picker.input
      )}
      {!closed && (
        <input
          type="text"
          className="daedalus-stylish-color-field__text daedalus-ui-input"
          value={face.text}
          placeholder={placeholder ?? undefined}
          aria-label={label}
          title={
            face.token ? `${face.token.name} · ${shown.trim()}` : undefined
          }
          onInput={(event) => type(event.target.value)}
          onBlur={(event) => settleText(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              settleText(event.target.value);
            }
          }}
        />
      )}
    </span>
  );

  return { open: palette.open, face, field, popover: palette.popover };
};

/**
 * The color field as one stacked component: the inline cluster, with
 * the popover floating under it (the grid, the color lane, the mint
 * row). For the swatch kind's editor, where both slots stack anyway.
 *
 * @param {object} props
 *   useColorField's options.
 */
export const ColorField = (props) => {
  const { field, popover } = useColorField(props);
  return (
    <div className="daedalus-stylish-color-field__stack">
      {field}
      {popover}
    </div>
  );
};
