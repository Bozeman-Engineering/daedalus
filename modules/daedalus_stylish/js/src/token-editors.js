import { h, Fragment } from 'preact';
import { fontOptions } from './materials.js';
import { addFamilyToStack, tokenEditorKind } from './skin-model.js';
import { paletteSpace } from './color-field-model.js';
import { ColorField } from './widgets/lib/color-field.js';
import { GradientBuilder } from './gradient-builder.js';

/**
 * The value editors a token's shape mounts, one contract
 * ({value, guide, materials, exclude, onPreview, onCommit}): `value` is
 * the working draft (a condition-keyed token edits its 'default' entry;
 * the skin model splits and remerges), `materials` the roster the
 * editors offer read from, `exclude` a style spelling the editor must
 * not offer (a token cannot reference itself), and every input previews
 * per event / settles per gesture through the caller's pair, exactly
 * like a property row. The material card (material-card.js) and the
 * token mint card (skin.js) mount them through tokenEditor().
 */

/**
 * The color editor IS the color field (widgets/lib/color-field.js) over
 * the palette as a value space: the select of palette entries as faces
 * (aliasing another entry commits its reference), the native picker
 * (reached through the face popover's color lane, the one door to the
 * browser's chooser) and free text, unconditionally: the skin tab
 * AUTHORS the vocabulary, so the site's consumer constraint posture never
 * gates it, and the space is never closed. No mint chrome (the card owns
 * name and save), no unset (a token's value is never empty), and the
 * token's own spelling left out of the offer and the capture (`exclude`:
 * one var() cycle and the property is invalid everywhere it is used).
 */
export const ColorValueEditor = ({
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
 * The gradient editor IS the gradient builder, whole-value: no property
 * constraints (a token's value is the gradient itself, not a
 * background-image layer stack) and no inner mint chrome: the card owns
 * the name and save gestures around it.
 */
export const GradientValueEditor = ({
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

/**
 * The value editor for the shapes with no editor of their own: every
 * shape but color and gradient (a dimension, a number, a font stack, a
 * shadow) edits as text, references included, under the same editor
 * contract: every keystroke previews, blur settles. The server validates
 * the shape on commit.
 */
export const TextValueEditor = ({ value, onPreview, onCommit }) => (
  <input
    type="text"
    className="daedalus-ui-input daedalus-stylish-material-card__input"
    aria-label={Drupal.t('Token value')}
    value={typeof value === 'string' ? value : ''}
    onInput={(event) => onPreview(event.target.value)}
    onBlur={(event) => onCommit(event.target.value)}
    onKeyDown={(event) => {
      if (event.key === 'Enter') {
        onCommit(event.target.value);
      }
    }}
  />
);

/**
 * The stack editor: the text editor with the minted families as chips.
 *
 * A token on the scale the guide binds font-family to (the suite's
 * `font` scale: skin-model.js fontScale) holds a font-family stack, so
 * its editor offers every rostered font (materials.js fontOptions) as a
 * chip that adds the family to the stack (addFamilyToStack: alone on an
 * empty stack, before a trailing generic, else appended, never twice),
 * wearing its own family. The value stays the text (a generic, a theme
 * family and a minted family all live in one stack); a chip's add is a
 * settle, as typing then blurring is.
 */
export const FontStackEditor = ({ value, materials, onPreview, onCommit }) => {
  const stack = typeof value === 'string' ? value : '';
  const fonts = fontOptions(materials);
  return (
    <div className="daedalus-stylish-material-card__stack">
      <TextValueEditor value={value} onPreview={onPreview} onCommit={onCommit} />
      {fonts.length > 0 && (
        <div
          className="daedalus-stylish-material-card__families"
          role="group"
          aria-label={Drupal.t('Minted families')}
        >
          {fonts.map((font) => (
            <button
              key={font.name}
              type="button"
              className="daedalus-ui-chip daedalus-stylish-material-card__family"
              data-font={font.name}
              style={{ fontFamily: font.spelling }}
              title={Drupal.t('Add !family to the stack.', {
                '!family': font.family,
              })}
              onClick={() => onCommit(addFamilyToStack(stack, font.spelling))}
            >
              {font.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

/**
 * The editor a token's scale mounts (skin-model.js tokenEditorKind): the
 * color editor for color scales, the gradient editor for gradient scales,
 * the stack editor with the minted families for the scale font-family is
 * bound to, the text editor for the rest.
 */
export const tokenEditor = (guide, scale, shape) =>
  ({
    color: ColorValueEditor,
    gradient: GradientValueEditor,
    stack: FontStackEditor,
    text: TextValueEditor,
  })[tokenEditorKind(guide, scale, shape)] ?? TextValueEditor;

/**
 * The per-scheme pair control around one shape's value editor.
 *
 * Mint and edit author a {default, scheme} pair through the SAME shape
 * editor mounted per face: the default editor always, and, when the
 * guide declares a scheme axis (schemeCondition; single scheme-axis
 * condition names only), either the "add" gesture or the scheme face's
 * own editor with its remove chip. A fresh scheme draft seeds from the
 * default (the author darkens what exists, not a neutral). Face labels
 * appear only while the pair is split, so a single-valued value keeps
 * the editor anatomy it always had. Pure drafts-in/drafts-out like the
 * shape editors themselves: the caller owns commit (mergedPair) and the
 * save gesture.
 */
export const SchemePairEditor = ({
  guide,
  materials,
  Editor,
  draft,
  darkDraft,
  scheme,
  exclude = null,
  onDraft,
  onDark,
}) => {
  const schemeLabel = guide?.conditions?.[scheme]?.label ?? scheme;
  return (
    <div className="daedalus-stylish-scheme-pair">
      {darkDraft !== null && (
        <div className="daedalus-stylish-scheme-pair__head">
          <span className="daedalus-stylish-scheme-pair__scheme">
            {scheme === 'dark'
              ? Drupal.t('Default (light)')
              : Drupal.t('Default')}
          </span>
        </div>
      )}
      <Editor
        value={draft}
        guide={guide}
        materials={materials}
        exclude={exclude}
        onPreview={onDraft}
        onCommit={onDraft}
      />
      {scheme !== null &&
        (darkDraft === null ? (
          <button
            type="button"
            className="daedalus-ui-chip daedalus-stylish-scheme-pair__add"
            title={Drupal.t(
              'Author a separate value for the !scheme scheme; the face shows both halves.',
              { '!scheme': schemeLabel },
            )}
            onClick={() => onDark(draft)}
          >
            {Drupal.t('+ !scheme value', { '!scheme': schemeLabel })}
          </button>
        ) : (
          <Fragment>
            <div className="daedalus-stylish-scheme-pair__head">
              <span className="daedalus-stylish-scheme-pair__scheme">{schemeLabel}</span>
              <button
                type="button"
                className="daedalus-ui-chip daedalus-stylish-scheme-pair__remove"
                title={Drupal.t(
                  'Drop the !scheme entry; the default value serves both schemes.',
                  { '!scheme': schemeLabel },
                )}
                onClick={() => onDark(null)}
              >
                {Drupal.t('remove')}
              </button>
            </div>
            <Editor
              value={darkDraft}
              guide={guide}
              materials={materials}
              exclude={exclude}
              onPreview={onDark}
              onCommit={onDark}
            />
          </Fragment>
        ))}
    </div>
  );
};
