import { h } from 'preact';
import { useSelector, useDispatch } from 'react-redux';
import {
  setTarget,
  setDrill,
  removeSelected,
} from '../../store/slices/selection-slice';
import { commitAddress } from '../../selection/commit-address';
import { elementForKey } from '../../selection/resolver';
import { projectionIndex } from '../page-elements/projection-index';
import { reachPredicate } from '../page-elements/reach';
import { labelFor } from '../../selection/label-for-key';
import { ancestryOf } from '../../selection/ancestry';

/**
 * The selection indicator: breadcrumb + chips, two projections of the
 * ONE selection slice.
 *
 * The BREADCRUMB answers "how deep am I?": the ancestry of the PRIMARY
 * selection and its styling target, read as one address over the shared
 * projection index (ancestry.js: the same map that labels the tree, the
 * chat chips, and keyboard traversal — unison by construction, no
 * cross-wiring) through reach (reach.js: the rows the panel currently
 * draws), so a targeted drawn part (Raster with the Markup view on, a
 * list item in every view) trails through its drawn markup parents up
 * to its owner and on to the root, and any other target (a tag tier, a
 * chain, an ancestor rule, a shell part the Markup view is not drawing)
 * ends the trail as a target crumb after the element's ("Welcome › every
 * link"). Ancestor
 * crumbs are clickable to climb: a crumb click commits what the crumb
 * addresses (the element key, plus the part's selector as the target for
 * a part crumb) and re-seats the drill context on the crumb's element
 * parent (the owner's parent for a part), exactly the shape ArrowLeft
 * commits (the crumb click and ArrowLeft are the step-up gestures; Escape
 * deselects and never climbs). While a target stands, the CURRENT crumb
 * is clickable too: it drops the target back to the bare element, the
 * same drop Escape's target rung makes.
 *
 * The CHIPS answer "what all is selected?": one chip per member of the
 * multi-select set, in selection order, each dismissible (dismiss IS
 * deselection, the same Selection.deselect the AI chat's chips call). They
 * render only for a REAL set (two or more members); a single selection's
 * indicator is the breadcrumb plus the outline.
 *
 * While a drag or lift holds the caret, the breadcrumb answers the same
 * "how deep am I?" for the CARET instead: the trail of the container the
 * current drop location inserts into (state.drag.dropContainerKey — per
 * gap transition, the same identity the tree's drop reveal consumes), so
 * arrowing or dragging through nested layouts always names the level the
 * drop would land at. The drop trail is read-only — clicking a crumb
 * mid-gesture would cancel the very gesture it describes.
 *
 * The family floats at the canvas's bottom-left, offset past the left
 * rail by the displace var (selection-indicator.css). A chrome theme owns
 * the placement and the skin; this component owns the grammar.
 */

const Breadcrumb = ({
  index,
  primaryKey,
  target = null,
  interactive = true,
  label = null,
}) => {
  const dispatch = useDispatch();
  const trail = ancestryOf(index, reachPredicate(), primaryKey, target);

  return (
    <nav
      className="daedalus-ui-breadcrumb"
      aria-label={label ?? Drupal.t('Selection path')}
    >
      {trail.map((crumb, position) => {
        const current = position === trail.length - 1;
        return (
          <span key={crumb.address} className="daedalus-ui-breadcrumb__segment">
            {position > 0 && (
              <span className="daedalus-ui-breadcrumb__separator" aria-hidden="true">
                ›
              </span>
            )}
            {current && interactive && target ? (
              <button
                type="button"
                className="daedalus-ui-breadcrumb__crumb daedalus-ui-breadcrumb__crumb--current daedalus-ui-breadcrumb__crumb--target"
                aria-current
                title={Drupal.t('Back to the whole element')}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  // The target crumb drops the target: the selection
                  // stays, styling goes back to the element itself.
                  dispatch(setTarget(null));
                }}
              >
                {crumb.label}
              </button>
            ) : current || !interactive ? (
              <span
                className={`daedalus-ui-breadcrumb__crumb${current ? ' daedalus-ui-breadcrumb__crumb--current' : ''}`}
                aria-current={current || undefined}
              >
                {crumb.label}
              </span>
            ) : (
              <button
                type="button"
                className="daedalus-ui-breadcrumb__crumb"
                title={Drupal.t('Select @label', { '@label': crumb.label })}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  // The step-up shape (ArrowLeft's): the crumb becomes
                  // the selection (its part the target for a part crumb, the
                  // tree row's own commit), its element parent the drill
                  // context (crumb.key is the owner for a part, so this is
                  // the owner's parent), so pointer resolution keeps working
                  // at the climbed-to level. The canvas reveals the owner
                  // element: a part has no key of its own to look up.
                  commitAddress(crumb.key, crumb.selector);
                  dispatch(setDrill(index?.get(crumb.key)?.parent ?? null));
                  Drupal.Daedalus.scrollReveal(elementForKey(crumb.key), {
                    behavior: 'smooth',
                  });
                }}
              >
                {crumb.label}
              </button>
            )}
          </span>
        );
      })}
    </nav>
  );
};

const Chips = ({ index, keys, primaryKey }) => {
  const dispatch = useDispatch();

  return (
    <div
      className="daedalus-ui-chips"
      role="group"
      aria-label={Drupal.t('Selected elements')}
    >
      {keys.map((key, position) => {
        const label = labelFor(index, key);
        return (
          <span
            key={key}
            className={`daedalus-ui-chip${key === primaryKey ? ' daedalus-ui-chip--primary' : ''}`}
            data-selection-key={key}
          >
            <span className="daedalus-ui-chip__number">{position + 1}</span>
            <span className="daedalus-ui-chip__label">{label}</span>
            <button
              type="button"
              className="daedalus-ui-chip__dismiss"
              aria-label={Drupal.t('Deselect @label', { '@label': label })}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                // Chip dismiss IS deselection — every rendering of the one
                // slice (outlines, tree, chat chips) follows.
                dispatch(removeSelected(key));
              }}
            >
              ×
            </button>
          </span>
        );
      })}
    </div>
  );
};

const SelectionIndicator = () => {
  const primaryKey = useSelector((state) => state.selection.selectedKey);
  const target = useSelector((state) => state.selection.target);
  const selectedKeys = useSelector((state) => state.selection.selectedKeys);
  const mode = useSelector((state) => state.mode.mode);
  const dragActive = useSelector((state) => state.drag.active);
  const dropContainerKey = useSelector((state) => state.drag.dropContainerKey);
  // Re-render on mirror replacement so labels and ancestry re-derive from
  // the fresh projection (the index itself is read at render time), and
  // on a view flip so the trail re-derives from the rows now drawn (the
  // reach predicate is taken at render time too).
  useSelector((state) => state.blueprint.blueprint);
  useSelector((state) => state.view.views);

  // A drag or lift owns the indicator: the breadcrumb
  // becomes the caret's depth — the container the drop would land in. No
  // target under the cursor, no trail.
  if (dragActive) {
    return dropContainerKey ? (
      <div className="daedalus-ui-selection-indicator daedalus-ui-selection-indicator--drop">
        <Breadcrumb
          index={projectionIndex()}
          primaryKey={dropContainerKey}
          interactive={false}
          label={Drupal.t('Insertion path')}
        />
      </div>
    ) : null;
  }

  // The indicator serves the selection's grammar, like the verb menu.
  if (mode !== 'edit' || !primaryKey) {
    return null;
  }

  const index = projectionIndex();

  return (
    <div className="daedalus-ui-selection-indicator">
      {selectedKeys.length > 1 && (
        <Chips index={index} keys={selectedKeys} primaryKey={primaryKey} />
      )}
      <Breadcrumb index={index} primaryKey={primaryKey} target={target} />
    </div>
  );
};

export default SelectionIndicator;
