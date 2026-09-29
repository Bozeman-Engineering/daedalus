import { h } from 'preact';

/**
 * A header icon button wearing the chrome's tooltip pattern (the top
 * bar's: icon-only buttons, the label rides a pill tooltip).
 *
 * Core's data-drupal-tooltip component can't serve preact-owned chrome:
 * its behavior attaches once at page load and inserts sibling DOM inside
 * markup preact reconciles (which would clobber it), and its styles and
 * tokens are scoped to [data-drupal-admin-styles] — absent in the
 * sidebar panels and the satellite window. So the same look is rendered
 * preact-side on daedalus tokens instead: an inverse pill (text and
 * background swapped, so it flips with the scheme) under the button,
 * shown on hover/focus.
 *
 * `tooltip` overrides the label as the pill's text and `show` forces the
 * pill visible — together they carry transient gesture feedback ("CSS
 * copied") in place, instead of a message line the eye must go find.
 */
export const IconButton = ({
  label,
  tooltip = null,
  show = false,
  disabled = false,
  onClick,
  children,
}) => (
  <span
    className={`daedalus-stylish-icon-button daedalus-ui-tooltip-host${show ? ' daedalus-ui-tooltip-host--show' : ''}`}
  >
    <button
      type="button"
      className="daedalus-stylish-inspector__icon-button daedalus-ui-icon-button"
      disabled={disabled}
      aria-label={label}
      onClick={onClick}
    >
      {children}
    </button>
    <span className="daedalus-ui-tooltip" role="tooltip" aria-hidden="true">
      {tooltip ?? label}
    </span>
  </span>
);
