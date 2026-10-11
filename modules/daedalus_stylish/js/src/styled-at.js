import { h } from 'preact';
import { styledConditions } from './editing-at.js';
import { deliveredEntry } from './style-stack.js';

/**
 * The "Styled at" line: the conditions carrying styles on the element.
 *
 * The pose group's second line, under the chip row and pinned with it
 * (inspector.js), in basic AND advanced mode (code view enumerates
 * every condition as its own island, so it needs no line). Chips come
 * from the target's floorless stack (style-stack.js,
 * `floor: false`: the root's worn references and the own entry, an
 * editing session's document overlaid as its layer), so variant-supplied
 * conditions count and the skin's and the declaration's do not: this row
 * is the map of where styles already live, and the tiers beneath the map
 * are not its conditions to drive to. Clicking a chip drives the canvas
 * there, including Default, the way back. A condition the canvas cannot
 * fully pose (a site-provided viewport @media width condition) drives what
 * it can and stays editable in full through the code view. The chip row
 * above is the pose surface (where the canvas stands).
 */
export const StyledAt = ({ stack, condition, onDrive }) => {
  const styled = stack ? styledConditions(deliveredEntry(stack)) : [];
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
            className={`daedalus-stylish-inspector__styled-chip daedalus-ui-chip${name === condition ? ' daedalus-ui-chip--active' : ''}`}
            title={Drupal.t('Drive the canvas to "!condition"', {
              '!condition': name,
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
