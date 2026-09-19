import { h } from 'preact';
import { useSelector, useDispatch } from 'react-redux';
import {
  setSelected,
  setDrill,
  removeSelected,
} from '../../store/slices/selection-slice';
import { elementForKey } from '../../selection/resolver';
import { projectionIndex } from '../page-elements/projection-index';
import { labelFor } from '../../selection/label-for-key';
import { ancestryOf } from '../../selection/ancestry';

/**
 * The selection indicator: breadcrumb + chips, two projections of the
 * ONE selection slice.
 *
 * The BREADCRUMB answers "how deep am I?": the ancestry of the PRIMARY
 * selection, the {selectedKey, selectedPart} pair read as one address over
 * the shared projection index (ancestry.js: the same map that labels the
 * tree, the chat chips, and keyboard traversal — unison by construction,
 * no cross-wiring), so a selected part (Raster, a list item) trails through
 * its markup parents up to its owner and on to the root. Ancestor crumbs
 * are clickable to climb: a crumb click commits what the crumb addresses
 * (the element key, or the {key, part} pair for a part crumb) and re-seats
 * the drill context on the crumb's element parent (the owner's parent for
 * a part), exactly the shape Escape's step-up and ArrowLeft commit.
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
 * gap transition, the same identity the tree's drop-unfold consumes), so
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
  primaryPart = null,
  interactive = true,
  label = null,
}) => {
  const dispatch = useDispatch();
  const trail = ancestryOf(index, primaryKey, primaryPart);

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
            {current || !interactive ? (
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
                  // The step-up shape (ArrowLeft / Escape): the crumb becomes
                  // the selection (the {key, part} pair for a part crumb, the
                  // tree row's own commit), its element parent the drill
                  // context (crumb.key is the owner for a part, so this is
                  // the owner's parent), so pointer resolution keeps working
                  // at the climbed-to level. The canvas reveals the owner
                  // element: a part has no key of its own to look up.
                  dispatch(
                    setSelected(
                      crumb.part
                        ? { key: crumb.key, part: crumb.part }
                        : crumb.key,
                    ),
                  );
                  dispatch(setDrill(index?.get(crumb.key)?.parent ?? null));
                  elementForKey(crumb.key)?.scrollIntoView({
                    block: 'nearest',
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
  const primaryPart = useSelector((state) => state.selection.selectedPart);
  const selectedKeys = useSelector((state) => state.selection.selectedKeys);
  const currentTool = useSelector((state) => state.tool.currentTool);
  const dragActive = useSelector((state) => state.drag.active);
  const dropContainerKey = useSelector((state) => state.drag.dropContainerKey);
  // Re-render on mirror replacement so labels and ancestry re-derive from
  // the fresh projection (the index itself is read at render time).
  useSelector((state) => state.blueprint.blueprint);

  // A drag or lift owns the indicator regardless of tool: the breadcrumb
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

  // The indicator serves the Selector's grammar, like the verb menu: the
  // armed edit session owns its own feedback.
  if (currentTool !== 'selector' || !primaryKey) {
    return null;
  }

  const index = projectionIndex();

  return (
    <div className="daedalus-ui-selection-indicator">
      {selectedKeys.length > 1 && (
        <Chips index={index} keys={selectedKeys} primaryKey={primaryKey} />
      )}
      <Breadcrumb
        index={index}
        primaryKey={primaryKey}
        primaryPart={primaryPart}
      />
    </div>
  );
};

export default SelectionIndicator;
