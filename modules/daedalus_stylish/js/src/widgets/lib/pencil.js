import { h } from 'preact';

/**
 * The ✎ toggle a reveal-carrying row shares (the padding row's box-model
 * diagram): one button shape, lit while its reveal is open. Rows differ
 * only in words: the accessible label is theirs, and the title pair
 * defaults to the precise-editor wording with overrides for reveals that
 * open something else (the padding diagram). Where it sits is the row's
 * call: a DimensionField row hands it in as the field's `tools`, between
 * the scrub label and the value column, so the field's grip keeps the
 * row's leading slot.
 *
 * @param {object} props
 *   The toggle's props.
 * @param {boolean} props.open
 *   Whether the reveal is open (the button's pressed state).
 * @param {Function} props.onToggle
 *   The click handler: flips the reveal.
 * @param {string} props.label
 *   The accessible name of the toggle (aria-label).
 * @param {?string} [props.openTitle]
 *   The title while open; null for the precise-editor wording.
 * @param {?string} [props.closedTitle]
 *   The title while closed; null for the precise-editor wording.
 *
 * @return {object}
 *   The button element.
 */
export const PencilToggle = ({
  open,
  onToggle,
  label,
  openTitle = null,
  closedTitle = null,
}) => (
  <button
    type="button"
    className="daedalus-stylish-inspector__pencil daedalus-ui-icon-button daedalus-ui-icon-button--muted"
    aria-pressed={open}
    aria-label={label}
    title={
      open
        ? (openTitle ?? Drupal.t('Hide the precise editor'))
        : (closedTitle ?? Drupal.t('Edit the exact value'))
    }
    onClick={onToggle}
  >
    ✎
  </button>
);
