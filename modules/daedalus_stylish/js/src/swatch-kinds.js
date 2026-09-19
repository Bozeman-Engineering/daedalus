import { h } from 'preact';
import { GradientBuilder } from './gradient-builder.js';
import { defaultGradientValue } from './gradient-builder-model.js';
import { paletteSpace } from './color-field-model.js';
import { colorSeed } from './swatch-kinds-model.js';
import { ColorField } from './widgets/lib/color-field.js';

/**
 * The swatch kind registry: the JS half of the SwatchKind plugin pattern.
 *
 * The PHP attribute plugin owns id, label, and server validation; this
 * registry owns each kind's client anatomy — the value EDITOR (a pure
 * value-in/value-out component the palette face mounts for minting and
 * for editing a swatch's value) and the mint SEED (a committable starting
 * draft). Keyed by plugin id,
 * last-write-wins, the controlWidgets posture: a module shipping a new
 * kind registers its client half here. A kind without a registered
 * editor renders no editor — the palette face degrades that card
 * honestly rather than inventing a control.
 *
 * Editor contract ({value, guide, materials, exclude, onPreview, onCommit}):
 * `value` is the working draft (condition-keyed swatches edit their
 * 'default' entry; the palette model splits and remerges), `materials` the
 * materials roster the palette offers read from, `exclude` a style
 * spelling the editor must not offer (a swatch cannot reference itself),
 * and every input previews per event / settles per gesture through the
 * caller's pair, exactly like a property row.
 */

const kinds = new Map();

/**
 * Registers a kind's client anatomy.
 *
 * @param {string} kind
 *   The SwatchKind plugin id ('color', 'gradient').
 * @param {{Editor: Function, seed: Function}} anatomy
 *   Editor is the value editor component; seed(guide, materials) answers
 *   a fresh mint's starting value.
 */
export const registerSwatchKind = (kind, anatomy) => {
  kinds.set(kind, anatomy);
};

/**
 * A kind's registered anatomy, or null (the honest-degrade signal).
 *
 * @param {string} kind
 *
 * @return {?{Editor: Function, seed: Function}}
 */
export const swatchKindAnatomy = (kind) => kinds.get(kind) ?? null;

/**
 * The color kind's editor IS the color field (widgets/lib/color-field.js)
 * over the palette as a value space: the select of palette entries as
 * faces (aliasing another entry commits its reference), the native
 * picker (reached through the face popover's color lane, the one door
 * to the browser's chooser) and free text, unconditionally: the palette card AUTHORS the
 * vocabulary, so the site's consumer constraint posture never gates it
 * (the palette is the sanctioned extension point that posture funnels
 * people toward; see swatch-kinds-model.js), and the space is never
 * closed. No mint chrome (the card owns label and save), no unset (a
 * swatch's value is never empty), and the swatch's own spelling left out
 * of the offer and the capture (`exclude`: one var() cycle and the
 * property is invalid everywhere it is used).
 */
const ColorValueEditor = ({
  value,
  guide = {},
  materials,
  exclude = null,
  onPreview,
  onCommit,
}) => (
  <ColorField
    space={paletteSpace(guide, materials, exclude)}
    value={typeof value === 'string' ? value : ''}
    guide={guide}
    materials={materials}
    label={Drupal.t('Color value')}
    unsettable={false}
    onPreview={onPreview}
    onSettle={onCommit}
  />
);

/**
 * The gradient kind's editor IS the gradient builder, whole-value: no
 * property constraints (a swatch's value is the gradient itself, not a
 * background-image layer stack) and no inner mint chrome — the palette
 * card owns the label and save gestures around it.
 */
const GradientValueEditor = ({
  value,
  guide = {},
  materials,
  onPreview,
  onCommit,
}) => (
  <GradientBuilder
    value={value}
    guide={guide}
    materials={materials}
    constraints={null}
    canMint={false}
    onPreview={onPreview}
    onCommit={onCommit}
  />
);

registerSwatchKind('color', { Editor: ColorValueEditor, seed: colorSeed });
registerSwatchKind('gradient', {
  Editor: GradientValueEditor,
  seed: (guide, materials) => defaultGradientValue(guide, materials),
});
