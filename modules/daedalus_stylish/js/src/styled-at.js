import { h } from 'preact';
import { qualifierStates, styledConditions } from './editing-at.js';
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
 * are not its conditions to drive to. A rule qualified by a state on one
 * of its compounds (`li:hover > ul`, the reveal) lives at that state as
 * much as a conditioned entry lives at its condition, so the target's
 * selector contributes its qualifier states (editing-at.js
 * qualifierStates) beside the entry's own. Clicking a chip drives the
 * canvas there, including Default, the way back; a qualifier state's
 * drive poses it, and the force state stamps the compound it stands on
 * (force-state.js), so the designer sees the reveal. A condition the
 * canvas cannot fully pose (a site-provided viewport @media width
 * condition) drives what it can and stays editable in full through the
 * code view. The chip row above is the pose surface (where the canvas
 * stands).
 */

/**
 * Renders the line.
 *
 * @param {object} props
 *   The line's props.
 * @param {?object} props.stack
 *   The target's floorless style stack (style-stack.js), or null.
 * @param {?string} [props.selector]
 *   The target's selector, for its qualifier states.
 * @param {object} [props.conditions]
 *   The condition table (guide.conditions).
 * @param {string} props.condition
 *   The condition the canvas stands at (the active chip).
 * @param {Function} props.onDrive
 *   Called with the condition name a chip drives to.
 */
export const StyledAt = ({
  stack,
  selector = null,
  conditions = {},
  condition,
  onDrive,
}) => {
  const styled = stack ? styledConditions(deliveredEntry(stack)) : [];
  qualifierStates(selector, conditions).forEach((name) => {
    if (!styled.includes(name)) {
      styled.push(name);
    }
  });
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
