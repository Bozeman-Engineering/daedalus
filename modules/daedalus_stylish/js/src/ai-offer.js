import { h } from 'preact';

/**
 * The usage display's URL, or null when the user may not read it.
 *
 * Read at render (never grabbed at bundle load): the settings land with
 * the surface that attaches the bundle, and the satellite window carries
 * its own copy.
 *
 * @return {?string}
 *   The URL, or null without the permission.
 */
export const aiContextUrl = () =>
  window.drupalSettings?.daedalusStylish?.aiContext?.url ?? null;

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
 * Beside the control, for a user who may read the AI context usage, the
 * door to it: "See what the AI holds" opens daedalus_blueprint's usage
 * display (the bar of what the site shares with the AI by kind, against
 * the budget) in the daedalus popup. The URL rides drupalSettings
 * .daedalusStylish.aiContext.url (InspectorGates::aiContext(): set only
 * with the permission, so the link's absence is honest chrome) and the
 * link wears data-daedalus-modal, the attribute the popup's one opener
 * (Drupal.Daedalus.openModal, daedalus_ui/modal) catches at the
 * document, with the display's class for its styling and the tests.
 * Sharing past the budget is refused with the same display named in the
 * hint; the link is where the user sees why.
 *
 * @param {object} props
 *   The toggle's props.
 * @param {?boolean} props.offered
 *   The record's flag; anything but false reads as offered.
 * @param {boolean} [props.disabled]
 *   Whether the control is inert (a save in flight).
 * @param {Function} props.onChange
 *   Called with the next boolean.
 * @param {string} [props.className]
 *   The surface's own placement class beside the shared one.
 * @param {?string} [props.label]
 *   The control's text when the flag means more than the AI (a token's
 *   `offered` is also whether the basic rows list it); the AI wording by
 *   default.
 * @param {?string} [props.title]
 *   The control's tooltip, beside the label for the same reason.
 */
export const AiOfferToggle = ({
  offered,
  disabled = false,
  onChange,
  className = '',
  label = null,
  title = null,
}) => {
  const contextUrl = aiContextUrl();
  return (
    <span
      className={`daedalus-stylish-ai-offer${className ? ` ${className}` : ''}`}
    >
      <label
        className="daedalus-stylish-ai-offer__control"
        title={
          title ??
          Drupal.t(
            'Off, the AI is not told about this material: it leaves the brief and the schema but still applies on the page.',
          )
        }
      >
        <input
          type="checkbox"
          checked={offered !== false}
          disabled={disabled}
          onChange={(event) => onChange(event.target.checked)}
        />
        {label ?? Drupal.t('Offer to the AI')}
      </label>
      {contextUrl ? (
        <a
          className="daedalus-stylish-ai-offer__context"
          href={contextUrl}
          data-daedalus-modal=""
          data-daedalus-modal-class="daedalus-ai-context-dialog"
          title={Drupal.t(
            'What the site shares with the AI, by kind, against the budget.',
          )}
        >
          {Drupal.t('See what the AI holds')}
        </a>
      ) : null}
    </span>
  );
};

/**
 * A record's offered flag: an absent key reads as offered, the default.
 *
 * @param {?{offered?: boolean}} record
 *   A material record, or nothing.
 *
 * @return {boolean}
 *   Whether the record is offered to the AI.
 */
export const isOffered = (record) => record?.offered !== false;
