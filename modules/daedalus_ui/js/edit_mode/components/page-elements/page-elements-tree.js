import { h, render, createContext } from 'preact';
import { createPortal } from 'preact/compat';
import { Provider, useSelector, useDispatch } from 'react-redux';
import { useContext, useEffect, useRef, useState } from 'preact/hooks';
import store from '../../store/store';
import {
  setHover,
  setHoverSelector,
  setSelected,
  toggleSelected,
  setSelectedSet,
} from '../../store/slices/selection-slice';
import { commitAddress } from '../../selection/commit-address';
import { projectionIndex, selectorAddress } from './projection-index';
import { setRenameKey } from '../../store/slices/view-slice';
import { elementForKey } from '../../selection/resolver';
import { makeDraggable, removeDraggable } from '../../drag/drag-controller';
import { projectTree } from './projection';
import { rowRenamable, renameOperation } from './rename-commit';
import { verbMenuItems } from '../../verbs/verb-menu-items';
import {
  subscribeTreeViews,
  activeTreeView,
  registerTreeViewProvider,
  getPartBehavior,
} from './view-registry';
import { createMarkupView, withInstanceRows } from './markup-view';
import { instanceRows } from './instance-rows';
import { generatedRows, withGeneratedRows } from './generated-rows';
import { depictedRows } from './reach';
import { initMarkupVerification } from './markup-verify';
import {
  partPlacement,
  rootTag,
  partTag,
  partLabel,
} from '../../blueprint/markup-index';
import { ShowMenu } from './show-menu';
import { PickerToggle } from './picker-toggle';
import {
  ancestorsOf,
  expand,
  isCollapsed,
  reveal,
  toggle,
} from '../../../outline/collapse';
import { CollapseControls } from '../../../outline/collapse-controls';
import { registerCollapseHotkeys } from '../../../outline/collapse-hotkeys';

/**
 * The page-elements tree — the explicit face of the selection spine.
 *
 * Projected from the blueprint mirror on every mirror replacement, so the
 * tree is always the post-mutation model with no bookkeeping. Rows carry
 * selectable keys; selection sync with the canvas is string equality on the
 * selection slice in both directions (hovering a row draws the canvas
 * outline, canvas selection highlights and reveals the row).
 *
 * An active view provider (view-registry.js) replaces the projection with
 * its own view of the same model — extra PSEUDO rows interleaved with the
 * model rows. Model rows behave identically in every view (same keys, same
 * selection spine, same verbs and drags); a pseudo row commits the
 * address pair {key: owner, part: id}, the same `key::selector` address
 * the selection index (projection-index.js) carries for it in every view,
 * so which view renders never changes what EXISTS: the index composes
 * both tiers view-independently. What the tree DRAWS is reach (reach.js):
 * the tree appends the same pseudo-row sources the index composes with,
 * instance rows (identity-carrying rich-text content, chip-less under the
 * default projection via withInstanceRows) and the generated-box rows
 * (generated-rows.js: the "Before" / "After" boxes the part behavior's
 * cascade delivers on an element, appended under the owning row of
 * whichever tree renders), and then keeps the rows whose `source` is
 * depicted (depictedRows). An instance row is drawn in every view because
 * its source is 'instance'; a box row is the Markup view's, drawn only
 * while that toggle is on; so the rows on screen are exactly the rows an
 * entry road may land on.
 *
 * The tree renders into the page_elements sidebar panel's mount div —
 * outside #daedalus-root — as its own Preact root sharing the app store.
 *
 * Rows collapse and expand through the one collapse model
 * (outline/collapse.js): the Outline component between the tree and its
 * root row owns the posture (`folds`, explicit postures keyed by row id),
 * projects the rendered tree into the model's shape per render, applies
 * the selection rule and the drop rule as two effects and registers the
 * collapse-all and expand-all hotkeys; each row reads its own posture
 * through the outline context and its chevron toggles it, and the root
 * row carries the outline's tools (OutlineTools). The Outline is keyed on
 * the view id, so a view switch resets every posture to the new view's
 * defaults: reset is a key, never a function.
 */

/**
 * The outline's id: the registrar's binding prefix (`page_elements.
 * collapse_all`, `page_elements.expand_all`) and the control's key lookup.
 */
const OUTLINE_ID = 'page_elements';

/**
 * The Outline owner's posture (`folds`), its outline in the model's shape
 * and the state setter (`set`), read by every row.
 */
const OutlineContext = createContext(null);

/**
 * A model row in the collapse model's shape. Keyed by the node's key (a
 * keyless row by its label, the same fallback its render key uses). A
 * unit row presents as one element and starts collapsed (the panel's face
 * of the same default stop the canvas resolver honors: the node's
 * presentation ANNOTATION, projected from the blueprint), as does a row
 * with nothing under it; a merged row has no absorbed structure left to
 * hide, so only its real children (if any) show. The root row is the
 * whole page: there is nothing outside it to reveal by collapsing it, so
 * it is never foldable and renders no chevron.
 */
const modelRow = (node, isRoot) => ({
  id: node.key ?? node.label,
  foldable: !isRoot && node.children.length > 0,
  folded: !isRoot && (node.presentation === 'unit' || node.children.length === 0),
});

/**
 * A pseudo row in the model's shape. Pseudo siblings can repeat an id
 * under one parent row (two absorbed blocks hoisting identical shell
 * chains into one region), so the id includes the owning model key, as
 * the render key does. Pseudo rows start expanded: they are structure on
 * the way to real rows (a markup view's wrapper chain), so collapsing one
 * is manual noise-reduction.
 */
const pseudoRow = (part) => ({
  id: `pseudo:${part.parentKey}:${part.id}`,
  foldable: part.children?.length > 0,
  folded: false,
});

/**
 * The rendered tree in the collapse model's shape: one root node whose
 * subtree mirrors the projection, model rows and pseudo rows alike.
 */
const outlineOf = (tree) => {
  const project = (node, isRoot) => ({
    ...(node.pseudo ? pseudoRow(node) : modelRow(node, isRoot)),
    children: (node.children ?? []).map((child) => project(child, false)),
  });
  return [project(tree, true)];
};

/**
 * The outline's node for a row id, or null when the outline lacks it.
 */
const rowOf = (outline, id) => {
  for (const node of outline) {
    if (node.id === id) {
      return node;
    }
    const found = rowOf(node.children, id);
    if (found) {
      return found;
    }
  }
  return null;
};

/**
 * The rows from the outline's root down to a key, as one string: the
 * dependency of the two rules below. It changes when the key changes and
 * when a re-projection moves the key under other rows, and holds through
 * everything else, so a manual re-collapse of a row that still holds the
 * selection sticks (the rule is keyed to the CHANGE, as the per-row rule
 * it replaces was).
 */
const pathTo = (outline, key) =>
  key ? [...ancestorsOf(outline, key), key].join('\n') : '';

/**
 * Per-row inline-start padding: one disclosure-grammar child-step
 * (--daedalus-indent, shared with the drop-down bodies) per level. The
 * root row (the whole page) is always expanded and renders no disclosure,
 * so deeper rows give back that column: every level sits one step further
 * left.
 */
const rowIndent = (depth) =>
  `calc(${Math.max(depth - 1, 0)} * var(--daedalus-indent) + 4px)`;

/**
 * The children of a row in a mixed view tree: model nodes render as real
 * tree rows, pseudo nodes as PartRows. Pseudo siblings can repeat an id
 * under one parent row (two absorbed blocks hoisting identical shell
 * chains into one region), so their render key includes the owning model
 * key — id is only unique per owner.
 */
const ChildRows = ({ children, provider, depth }) => (
  <ul className="daedalus-ui-tree__children" role="group">
    {children.map((child) =>
      child.pseudo ? (
        <PartRow
          key={`pseudo:${child.parentKey}:${child.id}`}
          part={child}
          provider={provider}
          depth={depth}
        />
      ) : (
        <TreeRow
          key={child.key ?? child.label}
          node={child}
          provider={provider}
          depth={depth}
        />
      ),
    )}
  </ul>
);

/**
 * A pseudo-selection row an active view provider projected.
 *
 * Visually distinct (muted italic label, no verb menu), on the selection spine only as
 * a styling TARGET: clicking selects the row's OWNER key (its parentKey,
 * not necessarily the row it renders under) and targets the row id (the
 * part's selector) under it (commit-address.js), so "active" is spine
 * state (the target equals this row's (owner, id) pair), never provider
 * state. While a part row is active it is the tree's focus stop (the
 * owner row yields its primary treatment and its Tab stop, and focus
 * follows the target row the way it follows the primary). Hover
 * rides the spine too, as the hoverSelector facet ONLY (setHoverSelector,
 * never setHover): the part lights up — canvas dashed paint by whatever
 * subscribes to the facet, row glow here — without lighting the owner
 * element's hover outline. Hot = the spine's hoverSelector is this row's
 * (owner, id) pair, whoever wrote it (this row's own hover or the canvas
 * Cmd-hover resolving matching markup).
 *
 * Pseudo rows collapse like model rows but start expanded (pseudoRow), and
 * selection or drop targeting beneath one expands it the way it expands
 * model rows (the Outline's two rules).
 *
 * A pseudo row never carries data-tree-key (hover, selection, the context
 * menu and the visible-row order all read that attribute as a model
 * row's identity). A row whose part claims slots instead stamps
 * data-tree-owner (its owning model key) and data-tree-claims (the slot
 * kinds, space-separated): the drop resolver (gaps.js) reads the pair to
 * make the shell that claims the root's sections the root flow's drop
 * surface, since the section rows are that row's rows. A slot-less shell
 * carries neither attribute and is no drop surface.
 */
const PartRow = ({ part, provider, depth }) => {
  const dispatch = useDispatch();
  const isActive = useSelector(
    (state) =>
      !!part.parentKey &&
      state.selection.target?.key === part.parentKey &&
      state.selection.target?.selector === part.id,
  );
  const rowRef = useRef(null);
  // The primary's focus rule, on the target row: while the user is
  // working IN the panel, focus follows the active part row so keyboard
  // traversal reads out row by row across both tiers.
  useEffect(() => {
    const row = rowRef.current;
    if (!isActive || !row || row === document.activeElement) {
      return;
    }
    if (row.closest('.daedalus-ui-tree')?.contains(document.activeElement)) {
      row.focus();
    }
  }, [isActive]);
  const isHovered = useSelector(
    (state) =>
      !!part.parentKey &&
      state.selection.hoverSelector?.key === part.parentKey &&
      state.selection.hoverSelector?.selector === part.id,
  );
  const { folds, set } = useContext(OutlineContext);
  const row = pseudoRow(part);
  const collapsed = isCollapsed(folds, row);

  const classes = ['daedalus-ui-tree__row', 'daedalus-ui-tree__row--part'];
  if (isActive) {
    classes.push('daedalus-ui-tree__row--part-active');
  }
  if (isHovered) {
    classes.push('daedalus-ui-tree__row--part-hot');
  }

  const expandable = part.children?.length > 0;
  return (
    // The li is presentational; the focusable row div is the treeitem (the
    // ARIA tree pattern with real focusable rows).
    <li className="daedalus-ui-tree__item" role="none">
      <div
        ref={rowRef}
        role="treeitem"
        aria-selected={isActive}
        aria-level={depth + 1}
        aria-expanded={expandable ? !collapsed : undefined}
        tabIndex={isActive ? 0 : -1}
        className={classes.join(' ')}
        data-tree-address={
          part.parentKey ? selectorAddress(part.parentKey, part.id) : undefined
        }
        data-tree-owner={part.claims ? part.parentKey : undefined}
        data-tree-claims={part.claims ? part.claims.join(' ') : undefined}
        style={{ paddingInlineStart: rowIndent(depth) }}
        onMouseEnter={() => {
          // The hover FACET only, with this row's owner key — an ownerless
          // row has no valid facet to hover (a part is only ever valid
          // with its key), and setHover would light the owner element's
          // solid outline, which a part row deliberately never does.
          if (part.parentKey) {
            dispatch(
              setHoverSelector({ key: part.parentKey, selector: part.id }),
            );
          }
        }}
        onMouseLeave={() => {
          // Only clear a part hover this row set; never clobber the
          // canvas resolver's.
          const current = store.getState().selection.hoverSelector;
          if (
            current?.key === part.parentKey &&
            current?.selector === part.id
          ) {
            dispatch(setHoverSelector(null));
          }
        }}
        onContextMenu={(event) => {
          // No menu on pseudo rows: they carry no selectable key and no
          // verb set, so the NATIVE menu shows, and the event stops here
          // so no ancestor model row hijacks it on the way up.
          event.stopPropagation();
        }}
        onClick={(event) => {
          event.stopPropagation();
          // Part targeting on the spine: the owner selected, the row id
          // (the part's selector) the styling target under it. A rare
          // ownerless row (a markup-view row whose owner is never
          // stamped, like the layout element a collapsed block composes
          // to) is inert: a target is only ever valid with its key, so
          // there is nothing to commit.
          if (part.parentKey) {
            commitAddress(part.parentKey, part.id);
          }
          Drupal.Daedalus.scrollReveal(elementForKey(part.parentKey), {
            behavior: 'smooth',
          });
        }}
      >
        {expandable && (
          <button
            type="button"
            className="daedalus-ui-tree__disclosure"
            aria-expanded={!collapsed}
            aria-label={collapsed ? Drupal.t('Expand') : Drupal.t('Collapse')}
            onClick={(event) => {
              event.stopPropagation();
              set(toggle(folds, row));
            }}
          >
            <span
              className="daedalus-ui-tree__disclosure-marker"
              aria-hidden="true"
            ></span>
          </button>
        )}
        <span className="daedalus-ui-tree__label">{part.label}</span>
        {part.tag && <span className="daedalus-ui-tree__tag">{`<${part.tag}>`}</span>}
        {part.sublabel && (
          <span className="daedalus-ui-tree__sublabel">{part.sublabel}</span>
        )}
      </div>
      {!collapsed && expandable && (
        <ChildRows
          children={part.children}
          provider={provider}
          depth={depth + 1}
        />
      )}
    </li>
  );
};

/**
 * Activates an element row (plain click, Space on a focused row): a plain
 * selection, the same gesture a canvas click commits; editing is Enter's
 * (the dispatcher's one editor door, edit-session.js, which the tree's
 * rows reach because a tree widget's Enter is never a control's own).
 * Multi-select modifiers stay selection-only and part/pseudo rows never
 * come here. The canvas reveals the element by the one reveal rule
 * (Drupal.Daedalus.scrollReveal; zero-render-seam rows have none;
 * selection still commits and anchored chrome rides this row instead).
 */
const activateRow = (key) => {
  store.dispatch(setSelected(key));
  Drupal.Daedalus.scrollReveal(elementForKey(key), { behavior: 'smooth' });
};

/**
 * Selects the visible-row range between the primary selection and the
 * clicked row (the list convention: Shift+click ranges). A collapsed
 * row's children don't render, so the DOM order of [data-tree-key] rows
 * IS the visible order; no posture bookkeeping needed.
 */
const selectRowRange = (clickedKey) => {
  const primary = store.getState().selection.selectedKey;
  const rows = Array.from(
    document.querySelectorAll('.daedalus-ui-tree [data-tree-key]'),
  ).map((row) => row.getAttribute('data-tree-key'));
  const from = rows.indexOf(primary);
  const to = rows.indexOf(clickedKey);
  if (from === -1 || to === -1) {
    // No anchor to range from: a plain selection.
    store.dispatch(setSelected(clickedKey));
    return;
  }
  const [start, end] = from <= to ? [from, to] : [to, from];
  store.dispatch(
    setSelectedSet({ keys: rows.slice(start, end + 1), primary: clickedKey }),
  );
};

/**
 * The outline's tools, at the end of the root row: the element picker,
 * then the collapse-all and expand-all pair over the Outline's posture.
 *
 * The root row is the outline's head: always rendered, never collapsed,
 * pinned to the top of the panel's scrollport (page-elements-tree.css),
 * so the tools that reach into the outline sit on it and the collapse
 * all, then pick gesture is one short reach. A press on a tool is the
 * tool's alone: the click and the context menu stop here, so the row
 * under them neither selects nor opens its menu.
 */
const OutlineTools = () => {
  const { folds, outline, set } = useContext(OutlineContext);
  return (
    <span
      className="daedalus-ui-tree__tools"
      onClick={(event) => event.stopPropagation()}
      onContextMenu={(event) => event.stopPropagation()}
    >
      <PickerToggle />
      <CollapseControls
        id={OUTLINE_ID}
        folds={folds}
        outline={outline}
        apply={set}
      />
    </span>
  );
};

const TreeRow = ({ node, provider, depth }) => {
  // The root row is the whole page: there is nothing outside it to reveal
  // by collapsing it, so it renders without a disclosure and never folds.
  const isRoot = depth === 0;
  const dispatch = useDispatch();
  const isSelected = useSelector(
    (state) => !!node.key && state.selection.selectedKeys.includes(node.key),
  );
  // The primary row: the selected element's, unless a PLACED part of it
  // is targeted, when the part row is the active stop and this row keeps
  // only its selected treatment (an unplaced target, a tag tier or a
  // chain with no row, leaves the element row primary).
  const isPrimary = useSelector((state) => {
    const { selectedKey, target } = state.selection;
    if (!node.key || selectedKey !== node.key) {
      return false;
    }
    return !(
      target?.key === node.key &&
      projectionIndex()?.has(selectorAddress(node.key, target.selector))
    );
  });
  const isHovered = useSelector(
    (state) => !!node.key && state.selection.hoverKey === node.key,
  );
  // A row mark (app.tree.setRowMarks): another module outlining this row
  // in the focus treatment — e.g. daedalus_stylish's Outline Variant marking the
  // variant's wearers here and on the canvas as one gesture.
  const isMarked = useSelector(
    (state) => !!node.key && state.view.markedKeys.includes(node.key),
  );
  const hasSelection = useSelector((state) => !!state.selection.selectedKey);
  // The row's posture is the Outline's (modelRow gives the default, the
  // context the explicit postures); keyed by the node's key, so a manual
  // posture survives re-projections.
  const { folds, set } = useContext(OutlineContext);
  const row = modelRow(node, isRoot);
  const collapsed = isCollapsed(folds, row);
  const rowRef = useRef(null);

  // Inline rename (sections at every depth): the Rename verb, or its
  // direct-manipulation twin, double-clicking the label, puts this row's
  // key into the view slice's renameKey and the row swaps its label for an
  // input; Enter/blur commits ONE update_element op carrying the new label
  // (the section's one writable property, layout_settings.label
  // server-side), Escape cancels. Rename state lives in the STORE, not
  // row-local state, because rename is a verb: F2, the floating bar
  // button, and the context menu all run the same registry entry, which
  // reveals the panel and dispatches from outside this component. The
  // response's mirror replacement re-projects the row, the breadcrumb, the
  // outline badge, and the AI chips from the shared projection index, so
  // every surface renames at once. An empty commit clears the custom label
  // (the row falls back to its type word, the name the annotator mints for
  // an unrenamed section).
  //
  // The ROOT row is the second renamable kind: the entity's label field
  // presents AS the root row (the annotation's derived edit_target), so
  // renaming the row IS editing the title. Both kinds commit ONE
  // update_element op (root: properties keyed by the edit target, empty
  // CANCELS; section: {label}, empty clears); the gate and the op each
  // commit dispatches are the pure rename-commit grammar
  // (rename-commit.js); server validation (required, max length) surfaces
  // through the normal reportFailure path.
  const rootEditTarget = isRoot ? (node.editTarget ?? null) : null;
  // The current title, live against the mirror: it prefills the rename
  // input (unlike sections, whose input holds only the CUSTOM label) and
  // anchors the unchanged-commit no-op.
  const rootTitle = useSelector((state) => {
    const value = rootEditTarget
      ? state.blueprint.blueprint?.fields?.[rootEditTarget]
      : null;
    return typeof value === 'string' ? value : null;
  });
  const renamable = rowRenamable(node, isRoot, rootEditTarget);
  const renaming = useSelector(
    (state) => !!node.key && state.view.renameKey === node.key,
  );
  // Enter commits and refocuses the row, which blurs the input BEFORE the
  // state flush unmounts it — the ref (not state) is what stops that blur
  // from committing a second op. Escape rides the same guard.
  const renameDone = useRef(false);
  const startRename = () => {
    renameDone.current = false;
    dispatch(setRenameKey(node.key));
  };
  const commitRename = (value) => {
    dispatch(setRenameKey(null));
    const operation = renameOperation(node, value, {
      isRoot,
      editTarget: rootEditTarget,
      currentTitle: rootTitle,
    });
    if (!operation) {
      return;
    }
    const { Blueprint } = Drupal.Daedalus;
    const context = Blueprint.rootContext();
    if (!context) {
      return;
    }
    Blueprint.mutate({ ...context, operations: [operation] }).catch((error) =>
      Blueprint.reportFailure(error, node.key),
    );
  };

  // Right-click / Shift+F10: the row menu (the shared menu primitive,
  // daedalus_ui/menu), the third face of the verb registry, items
  // through the one shared verbs→items adapter.
  // File-tree selection semantics: an UNSELECTED row becomes the
  // selection first; a MEMBER of the current multi-select set leaves the
  // set intact, so the menu offers the SET's verbs (verbsForSet under the
  // adapter). Right-click mutating selection is the accepted consequence:
  // Trash is in that registry, and the menu must never act on a noun other
  // than the one the outline shows.
  const openRowMenu = (x, y) => {
    if (!store.getState().selection.selectedKeys.includes(node.key)) {
      dispatch(setSelected(node.key));
    }
    return Drupal.Daedalus.menu.open({
      x,
      y,
      items: verbMenuItems(store.getState().selection.selectedKeys),
    });
  };

  // Canvas → panel: reveal the row when it becomes the selection, by the
  // one reveal rule (the whole row in the panel's scrollport, centered
  // when it was out of view, unmoved when it already showed).
  useEffect(() => {
    if (isSelected) {
      Drupal.Daedalus.scrollReveal(rowRef.current);
    }
  }, [isSelected]);

  // While the user is working IN the panel (focus inside the tree), focus
  // follows the primary selection, so keyboard traversal reads out row by
  // row. Focus is never stolen from the canvas or other chrome.
  useEffect(() => {
    const row = rowRef.current;
    if (!isPrimary || !row || row === document.activeElement) {
      return;
    }
    if (row.closest('.daedalus-ui-tree')?.contains(document.activeElement)) {
      row.focus();
    }
  }, [isPrimary]);

  // Movable rows (block PLACEMENTS, block:{uuid} with two segments, and
  // sections) are drag SOURCES feeding the same move sources the canvas
  // hover arming uses: rows drag into canvas gaps and other rows, so
  // all four quadrants of the move gesture are one interaction. The drag
  // data's element is the row's CANVAS element, resolved at dragstart, so
  // the descendant guard and the optimistic move reason about the page
  // even when the gesture never touches it. The panel is its own surface,
  // so its drags never collide with a canvas gesture.
  useEffect(() => {
    const row = rowRef.current;
    const parts = node.key?.split(':') ?? [];
    const movable =
      parts[0] === 'container' || (parts[0] === 'block' && parts.length === 2);
    // While renaming, the row disarms: a draggable ancestor hijacks text
    // selection inside the rename input.
    if (!row || !movable || renaming) {
      return undefined;
    }
    makeDraggable(
      row,
      parts[0] === 'container' ? 'move_section' : 'move_block',
      () => ({ uuid: parts[1], element: elementForKey(node.key) }),
    );
    return () => removeDraggable(row);
  }, [node.key, renaming]);

  const classes = ['daedalus-ui-tree__row', `daedalus-ui-tree__row--${node.kind}`];
  if (isSelected) {
    classes.push('daedalus-ui-tree__row--selected');
  }
  if (isPrimary) {
    classes.push('daedalus-ui-tree__row--primary');
  }
  if (isHovered) {
    classes.push('daedalus-ui-tree__row--hovered');
  }
  if (isMarked) {
    classes.push('daedalus-ui-tree__row--marked');
  }
  if (!node.key) {
    classes.push('daedalus-ui-tree__row--unselectable');
  }
  if (isRoot) {
    classes.push('daedalus-ui-tree__row--root');
  }

  const expandable = node.children.length > 0;
  return (
    // The li is presentational; the row div is the treeitem, a REAL
    // focusable row: roving tabindex (the primary row is the tree's
    // Tab stop; the root row when nothing is selected), focus follows the
    // selection while focus is in the panel, and the document-level hotkey
    // dispatcher supplies the arrow-key behavior, the same bindings the
    // canvas uses, because both surfaces drive the one selection slice.
    <li className="daedalus-ui-tree__item" role="none">
      <div
        ref={rowRef}
        role="treeitem"
        aria-selected={isSelected}
        aria-level={depth + 1}
        aria-expanded={expandable && !isRoot ? !collapsed : undefined}
        tabIndex={isPrimary || (!hasSelection && isRoot) ? 0 : -1}
        className={classes.join(' ')}
        data-tree-key={node.key ?? undefined}
        style={{ paddingInlineStart: rowIndent(depth) }}
        onMouseEnter={() => node.key && dispatch(setHover(node.key))}
        onMouseLeave={() => {
          // Only clear a hover this row set; never clobber the canvas's.
          if (node.key && store.getState().selection.hoverKey === node.key) {
            dispatch(setHover(null));
          }
        }}
        onContextMenu={(event) => {
          // The event stops here either way: a keyless or renaming row
          // keeps the NATIVE menu (text editing needs cut/copy/paste),
          // and no ancestor row hijacks a child's right-click.
          event.stopPropagation();
          if (!node.key || renaming) {
            return;
          }
          // Only a menu that actually opened claims the event — the
          // primitive's no-items contract leaves the native menu alone.
          if (openRowMenu(event.clientX, event.clientY)) {
            event.preventDefault();
          }
        }}
        onClick={(event) => {
          event.stopPropagation();
          if (!node.key) {
            return;
          }
          // The list convention for the multi-select set: Cmd/Ctrl
          // toggles membership, Shift ranges from the primary over the
          // visible rows, plain click replaces the set.
          if (event.metaKey || event.ctrlKey) {
            dispatch(toggleSelected(node.key));
            return;
          }
          if (event.shiftKey) {
            selectRowRange(node.key);
            return;
          }
          activateRow(node.key);
        }}
        onKeyDown={(event) => {
          // F2 is NOT handled here: rename is a verb, so its hotkey lives
          // in the registry (daedalus_ui.rename) and dispatches
          // against the SELECTION at the document level — no row focus
          // required, remappable in the settings tray.
          // Shift+F10 / the menu key: the keyboard twin of row
          // right-click, anchored at the focused row's rect instead of a
          // pointer. The host restores this row's focus on keyboard
          // dismissal.
          if (
            (event.key === 'ContextMenu' ||
              (event.key === 'F10' && event.shiftKey)) &&
            node.key &&
            event.target === event.currentTarget
          ) {
            event.stopPropagation();
            const rect = event.currentTarget.getBoundingClientRect();
            if (openRowMenu(rect.left + 4, rect.bottom)) {
              event.preventDefault();
            }
            return;
          }
          // The ARIA treeitem activation key: Space on a focused row
          // activates it (activateRow, the keyboard twin of the row
          // click). Only the row itself (the disclosure button keeps its
          // native activation), and a PRIMARY row's Space stays with the
          // dispatcher, where it means lift. Enter is NOT handled here:
          // it is the dispatcher's editor door (edit-session.js), which
          // opens the primary's editor from the canvas and the panel
          // alike, so the row lets it propagate.
          if (
            event.key !== ' ' ||
            event.target !== event.currentTarget ||
            !node.key ||
            isPrimary
          ) {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          activateRow(node.key);
        }}
      >
        {expandable && !isRoot && (
          // No text: the triangle is the native disclosure list marker
          // (page-elements-tree.css), the same one the Settings and
          // Blocks cards' summaries wear. It rides an inner span because
          // browsers refuse to draw list markers on form controls.
          <button
            type="button"
            className="daedalus-ui-tree__disclosure"
            aria-expanded={!collapsed}
            aria-label={collapsed ? Drupal.t('Expand') : Drupal.t('Collapse')}
            onClick={(event) => {
              event.stopPropagation();
              set(toggle(folds, row));
            }}
          >
            <span
              className="daedalus-ui-tree__disclosure-marker"
              aria-hidden="true"
            ></span>
          </button>
        )}
        {renaming ? (
          <input
            className="daedalus-ui-tree__rename"
            type="text"
            defaultValue={isRoot ? (rootTitle ?? '') : (node.customLabel ?? '')}
            placeholder={node.label}
            aria-label={isRoot ? Drupal.t('Title') : Drupal.t('Container name')}
            ref={(input) => input?.focus()}
            onFocus={(event) => event.target.select()}
            onMouseDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
            onDblClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => {
              event.stopPropagation();
              if (event.key === 'Enter') {
                event.preventDefault();
                renameDone.current = true;
                commitRename(event.currentTarget.value);
                rowRef.current?.focus();
              }
              if (event.key === 'Escape') {
                event.preventDefault();
                renameDone.current = true;
                dispatch(setRenameKey(null));
                rowRef.current?.focus();
              }
            }}
            onBlur={(event) => {
              // Click-away commits; a blur Enter/Escape already resolved
              // stays quiet (renameDone).
              if (!renameDone.current) {
                renameDone.current = true;
                commitRename(event.currentTarget.value);
              }
            }}
          />
        ) : (
          <span
            className="daedalus-ui-tree__label"
            onDblClick={
              renamable
                ? (event) => {
                    event.stopPropagation();
                    startRename();
                  }
                : undefined
            }
          >
            {node.label}
          </span>
        )}
        {!renaming && node.tag && (
          <span className="daedalus-ui-tree__tag">{`<${node.tag}>`}</span>
        )}
        {!renaming && node.sublabel && (
          <span className="daedalus-ui-tree__sublabel">{node.sublabel}</span>
        )}
        {isRoot && <OutlineTools />}
      </div>
      {!collapsed && expandable && (
        <ChildRows
          children={node.children}
          provider={provider}
          depth={depth + 1}
        />
      )}
    </li>
  );
};

/**
 * The outline owner: the tree's one posture over the collapse model.
 *
 * Holds `folds` (the explicit postures; null is "every row at its
 * default"), projects the rendered tree into the model's shape, and runs
 * the two rules over it as effects:
 * - the selection rule: a collapsed row cannot show a selected descendant
 *   (its children do not render), so the selection reveals the path to it
 *   (deep select reaches inside unit rows) and the row's own posture
 *   stays (a unit row stays a unit when selected). Keyed to the CHANGE of
 *   the path, so a manual re-collapse of a row that still holds the
 *   selection sticks;
 * - the drop rule, the selection rule's twin: while a drag or lift
 *   targets a location under a row, the path to it reveals so the
 *   caret's panel face has rows to draw between, and the target itself
 *   expands too (an 'inside' drop points at its children's home).
 * The collapse-all and expand-all hotkeys (`3` and `4`, remappable in the
 * tray's Panels section) register for the outline's lifetime and read the
 * posture at press time; the pair of controls on the root row reads the
 * same state through the context. Mounted under a key on the view id by
 * the tree, so a view switch is a fresh outline at the new view's
 * defaults.
 */
const Outline = ({ tree, provider }) => {
  const [folds, setFolds] = useState(null);
  const outline = outlineOf(tree);
  // The registrar's actions run at press time, against the posture and
  // outline of the latest render, never the mount's.
  const latest = useRef(null);
  latest.current = { folds, outline };
  useEffect(
    () =>
      registerCollapseHotkeys({
        id: OUTLINE_ID,
        label: Drupal.t('Page elements'),
        keys: { collapseAll: '3', expandAll: '4' },
        read: () => latest.current,
        apply: setFolds,
      }),
    [],
  );

  const selectedKey = useSelector((state) => state.selection.selectedKey);
  const selectionPath = pathTo(outline, selectedKey);
  useEffect(() => {
    setFolds((current) => reveal(current, outline, selectedKey));
  }, [selectionPath]);

  const dropRowKey = useSelector((state) => state.drag.dropRowKey);
  const dropPath = pathTo(outline, dropRowKey);
  useEffect(() => {
    if (!dropRowKey) {
      return;
    }
    setFolds((current) => {
      const revealed = reveal(current, outline, dropRowKey);
      const target = rowOf(outline, dropRowKey);
      return target ? expand(revealed, target) : revealed;
    });
  }, [dropPath]);

  return (
    <OutlineContext.Provider value={{ folds, outline, set: setFolds }}>
      <TreeRow node={tree} provider={provider} depth={0} />
    </OutlineContext.Provider>
  );
};

/**
 * The tree, with its Show kebab ported into the panel header's slot.
 *
 * @param {object} props
 * @param {HTMLElement} props.showMenuSlot
 *   The header's #page-elements-show-menu span (PageElementsSidebar): the
 *   kebab renders there through a portal so it stays a child of this tree
 *   (one root, the store context flows) while sitting in the header's
 *   action cluster beside the close control instead of being positioned
 *   into the corner.
 */
const PageElementsTree = ({ showMenuSlot }) => {
  const root = useSelector((state) => state.blueprint.root);
  const blueprint = useSelector((state) => state.blueprint.blueprint);
  // View providers register at any point after mount, and a provider's
  // model announcements (a contrib view's own liveness) fan through
  // the registry — both re-read here on announcement. Subscribing to the
  // view slice's toggle state switches projections when a Show-menu
  // checkbox flips. (Pseudo-row glow and active state are store reads in
  // PartRow, not announcements.)
  const [, bump] = useState(0);
  useEffect(() => subscribeTreeViews(() => bump((count) => count + 1)), []);
  const visibility = useSelector((state) => state.view.views);
  // The generated-box rows read the materials record set too (a worn
  // look's box fragment can flip a box), and a materials-only response
  // replaces no mirror, so the tree subscribes to the records as well.
  useSelector((state) => state.materials.records);
  if (!root || !blueprint) {
    return null;
  }
  // The active provider's view replaces the projection; a failing provider
  // is contained — the tree falls back to the model projection. Either
  // tree then composes the pseudo rows the index composes with: the
  // model projection appends the instance rows chip-less (the markup
  // view lists its own, with chips), and the generated-box rows are
  // appended under whichever tree renders, after everything the view
  // lists. The draw is reach: depictedRows keeps the rows whose source
  // the view slice depicts, so instance rows (content, source
  // 'instance') survive in every view and box rows (the Markup view's)
  // only while that toggle is on, the same answer reach.js gives the
  // pointer and the keyboard.
  const provider = activeTreeView();
  let tree = null;
  if (provider) {
    try {
      tree = provider.projectTree(root, blueprint);
    } catch (error) {
      console.error('The active tree view provider failed:', error);
    }
  }
  const viewProvider = tree ? provider : null;
  const viewId = viewProvider ? viewProvider.displayToggle.id : 'model';
  tree ??= withInstanceRows(projectTree(root, blueprint), instanceRows);
  tree = depictedRows(withGeneratedRows(tree, generatedRows), visibility);
  return (
    // data-selection-hover-scope: rows drive selection hover through their
    // own enter/leave handlers; the canvas mousemove resolver stays out.
    // The Outline is KEYED on the view identity: switching projections
    // remounts the tree so every posture resets to the new view's defaults
    // (a view flip is a new view, not a re-render of the old one).
    <ul
      className="daedalus-ui-tree"
      role="tree"
      aria-label={Drupal.t('Page elements')}
      data-selection-hover-scope
    >
      {createPortal(<ShowMenu />, showMenuSlot)}
      <Outline key={viewId} tree={tree} provider={viewProvider} />
    </ul>
  );
};

/**
 * Mounts the tree into the page_elements panel.
 *
 * The panel markup renders server-side in page_top and persists across mode
 * switches, so one mount per page lifetime suffices; the behavior
 * guards re-attach with a dataset flag rather than once() to keep the app
 * bundle dependency-free.
 */
export const initPageElementsTree = () => {
  // The built-in markup view registers through the same seam contrib views
  // use: first in the provider list, so its Show-menu entry leads. The
  // annotation-backed instance model rides in with it: instance rows for
  // identity-carrying rich text (instance-rows.js), part placement for
  // the shell rows' element-projection gate (markup-index.js: only a
  // part placed as its own element mounts a row), and the resolved-markup
  // accessors for the rows' tag chips and matched-form labels, all read
  // from the `_element.markup` annotation on the mirror, mutation responses
  // replace the mirror and the tree re-renders from it, so no subscribe
  // seam is needed. The canvas observer survives only as a verification
  // logger (markup-verify.js).
  registerTreeViewProvider(
    createMarkupView(getPartBehavior, {
      rows: instanceRows,
      placement: partPlacement,
      rootTag,
      partTag,
      partLabel,
    }),
  );
  initMarkupVerification();
  Drupal.behaviors.NpPageElementsTree = {
    attach: () => {
      const mount = document.getElementById('page-elements-tree');
      if (!mount || mount.dataset.daedalusUiTreeMounted) {
        return;
      }
      // The header's slot for the Show kebab is part of the panel's
      // server-rendered contract (SidebarPanelHeaderTest); a missing one
      // is a broken panel, not a tree without a button.
      const showMenuSlot = document.getElementById('page-elements-show-menu');
      if (!showMenuSlot) {
        throw new Error(
          'The page_elements panel header has no #page-elements-show-menu slot for the Show kebab.',
        );
      }
      mount.dataset.daedalusUiTreeMounted = 'true';
      render(
        <Provider store={store}>
          <PageElementsTree showMenuSlot={showMenuSlot} />
        </Provider>,
        mount,
      );
    },
  };
};
