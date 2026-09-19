import { h } from 'preact';
import { styledConditions } from './editing-at.js';

/**
 * The "Styled at" row: the conditions carrying styles on the element.
 *
 * A slim readout under the element breadcrumb in basic AND advanced mode
 * (code view enumerates every condition as its own island, so it needs no
 * row). Chips come from the resolved entry, so variant-supplied
 * conditions count; clicking one drives the canvas there, including
 * Default, the way back. A condition the canvas cannot fully pose (a
 * site-provided viewport @media width condition) drives what it can and
 * stays editable in full through the code view. The chip row above is the
 * pose surface (where the canvas stands); this row is the map of where
 * styles already live.
 */
export const StyledAt = ({ entry, condition, onDrive }) => {
  const styled = styledConditions(entry);
  if (styled.length === 0) {
    return null;
  }
  return (
    <div className="daedalus-stylish-inspector__styled-at">
      <span className="daedalus-stylish-inspector__property-label">
        {Drupal.t('Styled at')}
      </span>
      <span className="daedalus-stylish-inspector__styled-chips">
        {['default', ...styled].map((name) => (
          <button
            key={name}
            type="button"
            className={`daedalus-stylish-inspector__styled-chip daedalus-ui-chip${name === condition ? ' daedalus-stylish-inspector__styled-chip--active' : ''}`}
            title={Drupal.t('Drive the canvas to "@condition"', {
              '@condition': name,
            })}
            onClick={() => onDrive(name)}
          >
            {name}
          </button>
        ))}
      </span>
    </div>
  );
};
