import { h } from 'preact';
import { useState } from 'preact/hooks';
import { mintOffered } from './color-field-model.js';
import { styleReference } from './materials.js';
import { saveMaterial } from './material-gestures.js';

/**
 * The save-as-swatch row: name + Save, minting the shown literal color
 * as a site-wide swatch and handing its reference back.
 *
 * Extracted from the color field when the basic wells' popovers became
 * the second consumer: one mint gesture under one gate (mintOffered:
 * the grant, an open space, a literal to save) wherever a color value
 * is edited, rendered inside the shared palette popover's `below` slot
 * so the popover carries the palette, the lanes, then the mint. The
 * mint itself is the save_material op through the source's batch
 * commit (the op the AI submits), its landed record referenced by the
 * consumer's own settle: the value becomes the swatch.
 *
 * @param {object} options
 * @param {?object} options.space
 *   The value space (null while the consumer mounts no color field:
 *   the row then renders nothing).
 * @param {{kind: string, spelling: string}} options.face
 *   colorFace's answer for the shown value; `spelling` is what mints.
 * @param {boolean} options.canMint
 *   The save-as-swatch grant (honest chrome; the op enforces).
 * @param {?object} options.source
 *   The state source the mint commits through (required with canMint).
 * @param {Function} options.onSettle
 *   Called with the minted swatch's style reference.
 *
 * @return {{row: ?object}}
 *   The row vnode (null when the gate is closed) for the consumer's
 *   `below` slot. A failed mint toasts through the messenger (the op
 *   queue's source reports it); the row stays for a correction.
 */
export const useMintRow = ({ space, face, canMint, source, onSettle }) => {
  const [label, setLabel] = useState('');
  const [minting, setMinting] = useState(false);
  const offered = space !== null && mintOffered(space, face, canMint);

  const mint = () => {
    const name = label.trim();
    setMinting(true);
    saveMaterial(
      source,
      { kind: 'swatch', label: name, group: 'color', value: face.spelling },
      Drupal.t('Added the swatch !label', { '!label': name }),
    )
      .then((minted) => {
        // The op queue landed the minted record on the store; its style
        // spelling is what this value references from now on.
        setLabel('');
        onSettle(styleReference(minted));
      })
      .catch(() => {
        // The op queue's source reported it (reportFailure on the leader, the
        // satellite's settle); the row stays for a correction.
      })
      .finally(() => setMinting(false));
  };

  const row = !offered ? null : (
    <div className="daedalus-stylish-mint-row">
      <input
        type="text"
        className="daedalus-stylish-mint-row__name daedalus-ui-input"
        placeholder={Drupal.t('Swatch name…')}
        aria-label={Drupal.t('Swatch name')}
        value={label}
        onInput={(event) => setLabel(event.target.value)}
      />
      <button
        type="button"
        className="daedalus-stylish-mint-row__save daedalus-ui-pill-button"
        title={Drupal.t(
          'Save this color as a palette swatch the whole site can reuse; this value becomes a reference to it.',
        )}
        disabled={minting || label.trim() === ''}
        onClick={mint}
      >
        {Drupal.t('Save as swatch')}
      </button>
    </div>
  );

  return { row };
};
