import { h, render } from 'preact';
import { createPortal } from 'preact/compat';
import { Provider, useSelector, useDispatch } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import store from '../../store/store';
import {
  setHover,
  setHoverPart,
  setSelected,
  toggleSelected,
  setSelectedSet,
} from '../../store/slices/selection-slice';
import { setRenameKey } from '../../store/slices/view-slice';
import { elementForKey } from '../../selection/resolver';
import { makeDraggable, removeDraggable } from '../../drag/drag-controller';
import { projectTree } from './projection';
import { rowRenamable, renameOperation } from './rename-commit';
import { isArmed, editItemForKey } from '../../edit_session/edit-session';
import { verbMenuItems } from '../../verbs/verb-menu-items';
import {
  subscribeTreeViews,
  activeTreeView,
  registerTreeViewProvider,
  getPartBehavior,
} from './view-registry';
import { createMarkupView, withInstanceRows } from './markup-view';
import { instanceRows } from './instance-rows';
import { initMarkupVerification } from './markup-verify';
import {
  partPlacement,
  rootTag,
  partTag,
  partLabel,
} from '../../blueprint/markup-index';
import { ShowMenu } from './show-menu';

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
 * so which view renders never changes what is selectable: the index
 * composes both tiers view-independently. One pseudo-row source renders in
 * EVERY view: instance rows (identity-carrying rich-text content), which
 * the default projection appends chip-less via withInstanceRows — content
 * is never gated behind the markup view's developer toggle.
 *
 * The tree renders into the page_elements sidebar panel's mount div —
 * outside #daedalus-root — as its own Preact root sharing the app store.
 */

/**
 * Whether a node's subtree contains a selectable key.
 */
const containsKey = (node, key) =>
  !!key &&
  node.children.some((child) => child.key === key || containsKey(child, key));

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
 * a FACET: clicking commits the row's OWNER key (its parentKey, not
 * necessarily the row it renders under) with the row id as the part facet,
 * so "active" is spine state ({selectedKey, selectedPart} equality), never
 * provider state. Hover rides the spine too, as the hoverPart facet ONLY
 * (setHoverPart, never setHover): the part lights up — canvas dashed paint
 * by whatever subscribes to the facet, row glow here — without lighting
 * the owner element's hover outline. Hot = the spine's hoverPart is this
 * row's (owner, id) pair, whoever wrote it (this row's own hover or the
 * canvas Cmd-hover resolving matching markup).
 *
 * Pseudo rows fold like model rows but start UNFOLDED: they are structure
 * on the way to real rows (a markup view's wrapper chain), so folding is
 * manual noise-reduction, and selection or drop targeting beneath one
 * unfolds it the way it unfolds model rows.
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
      state.selection.selectedKey === part.parentKey &&
      state.selection.selectedPart === part.id,
  );
  const isHovered = useSelector(
    (state) =>
      !!part.parentKey &&
      state.selection.hoverPart?.key === part.parentKey &&
      state.selection.hoverPart?.part === part.id,
  );
  const [folded, setFolded] = useState(false);
  const containsSelected = useSelector((state) =>
    containsKey(part, state.selection.selectedKey),
  );
  useEffect(() => {
    if (containsSelected) {
      setFolded(false);
    }
  }, [containsSelected]);
  const containsDrop = useSelector(
    (state) =>
      !!state.drag.dropRowKey && containsKey(part, state.drag.dropRowKey),
  );
  useEffect(() => {
    if (containsDrop) {
      setFolded(false);
    }
  }, [containsDrop]);

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
        role="treeitem"
        aria-selected={isActive}
        aria-level={depth + 1}
        aria-expanded={expandable ? !folded : undefined}
        tabIndex={-1}
        className={classes.join(' ')}
        data-tree-owner={part.claims ? part.parentKey : undefined}
        data-tree-claims={part.claims ? part.claims.join(' ') : undefined}
        style={{ paddingInlineStart: rowIndent(depth) }}
        onMouseEnter={() => {
          // The hover FACET only, with this row's owner key — an ownerless
          // row has no valid facet to hover (a part is only ever valid
          // with its key), and setHover would light the owner element's
          // solid outline, which a part row deliberately never does.
          if (part.parentKey) {
            dispatch(setHoverPart({ key: part.parentKey, part: part.id }));
          }
        }}
        onMouseLeave={() => {
          // Only clear a part hover this row set; never clobber the
          // canvas resolver's.
          const current = store.getState().selection.hoverPart;
          if (current?.key === part.parentKey && current?.part === part.id) {
            dispatch(setHoverPart(null));
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
          // Part selection on the spine: the owner's key with the row id
          // as the part facet, one dispatch. A rare ownerless row (a
          // markup-view row whose owner is never stamped, like the layout
          // element a collapsed block composes to) is inert: a part is
          // only ever valid with its key, so there is nothing to commit.
          if (part.parentKey) {
            dispatch(setSelected({ key: part.parentKey, part: part.id }));
          }
          elementForKey(part.parentKey)?.scrollIntoView({
            block: 'center',
            behavior: 'smooth',
          });
        }}
      >
        {expandable && (
          <button
            type="button"
            className="daedalus-ui-tree__disclosure"
            aria-expanded={!folded}
            aria-label={folded ? Drupal.t('Expand') : Drupal.t('Collapse')}
            onClick={(event) => {
              event.stopPropagation();
              setFolded(!folded);
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
      {!folded && expandable && (
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
 * Selects the visible-row range between the primary selection and the
 * clicked row (the list convention: Shift+click ranges). Folded rows
 * don't render, so the DOM order of [data-tree-key] rows IS the visible
 * order; no fold-state bookkeeping needed.
 */
/**
 * Activates an element row (plain click, Enter/Space): the tool defines
 * the gesture on every surface, so under the armed session a row whose
 * key owns an editable leaf opens its editor, the same gesture a canvas
 * click commits; anything else (a container, a foreign host's row)
 * falls through to plain selection. Multi-select modifiers stay
 * selection-only and part/pseudo rows never come here. Either way the
 * canvas scrolls to the element (zero-render-seam rows have none;
 * selection still commits and anchored chrome rides this row instead).
 */
const activateRow = (key) => {
  const editing = isArmed() ? editItemForKey(key) : null;
  if (!editing) {
    store.dispatch(setSelected(key));
  }
  elementForKey(key)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
};

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

const TreeRow = ({ node, provider, depth }) => {
  // The root row is the whole page: there is nothing outside it to reveal
  // by collapsing it, so it renders without a disclosure and never folds.
  const isRoot = depth === 0;
  const dispatch = useDispatch();
  const isSelected = useSelector(
    (state) => !!node.key && state.selection.selectedKeys.includes(node.key),
  );
  const isPrimary = useSelector(
    (state) => !!node.key && state.selection.selectedKey === node.key,
  );
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
  const containsSelected = useSelector((state) =>
    containsKey(node, state.selection.selectedKey),
  );
  // Unit rows present as one element: they start folded (the panel's face
  // of the same default stop the canvas resolver honors — the node's
  // presentation ANNOTATION, projected from the blueprint, replaces the
  // old isCollapseRoot DOM reach-back). Merged rows have no absorbed
  // structure left to hide, so only their real children (if any) show.
  // Manual fold state then survives re-projections — rows are keyed.
  // The root row never folds — it has no disclosure to unfold with.
  const [folded, setFolded] = useState(
    () =>
      !isRoot && (node.presentation === 'unit' || node.children.length === 0),
  );
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
  // (the row falls back to the layout-derived name).
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

  // Canvas → panel: reveal the row when it becomes the selection.
  useEffect(() => {
    if (isSelected) {
      rowRef.current?.scrollIntoView({ block: 'nearest' });
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
  // even when the gesture never touches it. Rows arm regardless of the
  // current tool: the panel is its own surface, so its drags never collide
  // with a canvas gesture.
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

  // A folded row can't reveal a selected descendant (its children don't
  // render), so selection unfolds the path to it — deep select (Cmd+click)
  // reaches inside collapse roots. Keyed to the CHANGE: re-folding a row
  // that still contains the selection sticks.
  useEffect(() => {
    if (containsSelected) {
      setFolded(false);
    }
  }, [containsSelected]);

  // The drop's twin of the selection unfold: while a drag or lift
  // targets a location under this row, unfold so the caret's panel face
  // has rows to draw between. The target container itself unfolds too:
  // an 'inside' drop points at its children's home.
  const containsDrop = useSelector(
    (state) =>
      !!state.drag.dropRowKey &&
      (node.key === state.drag.dropRowKey ||
        containsKey(node, state.drag.dropRowKey)),
  );
  useEffect(() => {
    if (containsDrop) {
      setFolded(false);
    }
  }, [containsDrop]);

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
        aria-expanded={expandable && !isRoot ? !folded : undefined}
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
          // The ARIA treeitem activation keys: Enter/Space on a focused
          // row activates it (activateRow, the keyboard twin of the row
          // click). Only the row itself (the disclosure button keeps its
          // native activation), and a PRIMARY row's Space stays with the
          // dispatcher, where it means lift.
          if (
            (event.key !== 'Enter' && event.key !== ' ') ||
            event.target !== event.currentTarget ||
            !node.key ||
            (event.key === ' ' && isPrimary)
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
            aria-expanded={!folded}
            aria-label={folded ? Drupal.t('Expand') : Drupal.t('Collapse')}
            onClick={(event) => {
              event.stopPropagation();
              setFolded(!folded);
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
      </div>
      {!folded && expandable && (
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
  useSelector((state) => state.view.parts);
  if (!root || !blueprint) {
    return null;
  }
  // The active provider's view replaces the projection; a failing provider
  // is contained — the tree falls back to the model projection. The model
  // projection is not bare: instance rows are CONTENT (user-authored
  // rich-text structure), so they render in every view — the fallback
  // appends them chip-less (withInstanceRows), while shell rows and tag
  // chips stay the markup view's own, behind its Show-menu toggle.
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
  return (
    // data-selection-hover-scope: rows drive selection hover through their
    // own enter/leave handlers; the canvas mousemove resolver stays out.
    // The root row is KEYED on the view identity: switching projections
    // remounts the tree so fold state resets to the new view's defaults (a
    // view flip is a new view, not a re-render of the old one).
    <ul
      className="daedalus-ui-tree"
      role="tree"
      aria-label={Drupal.t('Page elements')}
      data-selection-hover-scope
    >
      {createPortal(<ShowMenu />, showMenuSlot)}
      <TreeRow key={viewId} node={tree} provider={viewProvider} depth={0} />
    </ul>
  );
};

/**
 * Mounts the tree into the page_elements panel.
 *
 * The panel markup renders server-side in page_top and persists across mode
 * and tool switches, so one mount per page lifetime suffices; the behavior
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
      // server-rendered contract (SidebarPanelHeaderTest); its absence is
      // a broken panel, not a tree without a menu.
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
