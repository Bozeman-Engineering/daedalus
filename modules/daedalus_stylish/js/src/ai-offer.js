import { h } from 'preact';

/**
 * The "Offer to the AI" toggle: one material's AI opt-out, the same
 * control on every mint and edit surface (the save session's draft, the
 * variant card, the animation and palette mint cards and cards).
 *
 * Offered is the default every material has (the AI's roster is the
 * design system; hiding by default would empty it). Off keeps the
 * material for UI use only: it leaves the brief and the schema, so its
 * description has no audience (the surfaces hide the description with
 * it), while it still applies wherever its scope fits. The flag is the
 * record's `offered` (MaterialRecord::$offered on the server), written
 * through the same gesture ops the AI submits (`offered: false` on
 * save_material / update_material) or the variant details route.
 *
 * @param {object} props
 * @param {?boolean} props.offered
 *   The record's flag; anything but false reads as offered.
 * @param {boolean} [props.disabled]
 * @param {Function} props.onChange
 *   Called with the next boolean.
 * @param {string} [props.className]
 *   The surface's own placement class beside the shared one.
 */
export const AiOfferToggle = ({
  offered,
  disabled = false,
  onChange,
  className = '',
}) => (
  <label
    className={`daedalus-stylish-ai-offer${className ? ` ${className}` : ''}`}
    title={Drupal.t(
      'Off, the AI is not told about this material: it leaves the brief and the schema but still applies on the page.',
    )}
  >
    <input
      type="checkbox"
      checked={offered !== false}
      disabled={disabled}
      onChange={(event) => onChange(event.target.checked)}
    />
    {Drupal.t('Offer to the AI')}
  </label>
);

/**
 * A record's offered flag: an absent key reads as offered, the default.
 */
export const isOffered = (record) => record?.offered !== false;
