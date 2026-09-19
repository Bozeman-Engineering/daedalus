import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import { ElementIslands } from './element-view.js';
import {
  pageNodes,
  scopeNodes,
  visibleTree,
  reapplyRef,
} from './page-model.js';
import { createStitching } from './stitching.js';
import { useMenu } from '../menu.js';
import { KIND_LABELS } from '../style-guide.js';

/**
 * The tree code view: a tree of islands over the layout.
 *
 * Code mode's styling body, the stylesheet read at a SCOPE: the layout
 * — Page, sections, regions, blocks, nested sections recursively
 * (page-model.js walks the source's staged layout tree) — projected by
 * the code scope preference (code-scope.js) through scopeNodes: the
 * selected element's row alone (`element`), the selection with its
 * descendants (`subtree`), or the whole page (`page`); one chrome row
 * per selectable element in layout order with the element's islands
 * beneath it. Structure is chrome here exactly as in the variant
 * document view, just one level up: the tree's nesting IS the layout,
 * rows cannot be typed into existence, and the canvas alone changes
 * structure. (A variant's own document is not viewed here: the Materials
 * place renders it as its own root + children view.) The two anchored
 * scopes without a selection project nothing; the inspector renders the
 * shared "select an element" empty state instead of mounting this view.
 *
 * The scope is the third of the inspector's four controls (place, mode,
 * scope, pose; surface-state.js), the one that exists only in code
 * mode, so its segments sit at the top of this body rather than on the
 * chrome row under the panel title. The SELECTED row's islands print
 * the defaults floor beneath the map's lines (element-view.js floorFor;
 * layers.js SKIN_LAYER / DECLARED_LAYER) in every scope; every other row
 * prints the map alone, DevTools' rule (inherited rules for the element
 * you are inspecting), so the Page scope stays readable.
 *
 * The tree is styled-only, always: unstyled elements render no row, and
 * unstyled ANCESTORS of styled elements render a bare header row (their
 * structural context) with nothing beneath. Unselected rows are also
 * SPARSE (ElementIslands' occupiedRoster): only islands with lines
 * render, so an element styled purely through its parts shows no empty
 * root placeholder. The way in for an unstyled element or an empty
 * island is selection (a row click, the page elements panel, or the
 * canvas), because the SELECTED element always mounts the full
 * ElementIslands body (gestures, drafts, and placeholder islands
 * included), styled or not; leave it empty and deselect, and the row
 * vanishes on re-print, the ghost-island honesty rule one level up. As
 * the selection, that element alone also mints the posed compound's
 * ghost island (posedGhost; element-view.js).
 *
 * Selection syncs both ways: clicking any chrome row drives the canvas
 * selection through the source's select() seam (the satellite posts an
 * intent; the leader owns the spine), and a canvas selection change
 * highlights and scrolls to the element's row (the selected prop re-renders
 * this component through the Inspector's subscription).
 *
 * HOVER syncs both ways too, over the same shared selector state the
 * canvas outline and the page-elements rows read: pointing at a row
 * lights the element on the canvas and in the panel (the source's
 * hover() seam; rows sit in a data-selection-hover-scope so the canvas
 * resolver leaves their hover alone), and the canvas/panel hover lights
 * the element's row here. The incoming direction paints imperatively
 * (classList on the row headers) instead of threading a prop: hover
 * moves at pointer speed, and re-rendering every mounted island body
 * per boundary crossing would be pure waste.
 *
 * PARTS sync the same two ways, one level down, over the spine's part
 * facets: a part header's hover/click drives hoverPart/select through
 * the source (element-view.js owns the headers), the selected facet
 * wears the active mark there via the `selectedPart` prop, and the
 * incoming hoverPart facet (canvas Cmd-hover, tree part-row hover)
 * paints the matching header's hot mark imperatively here — the element
 * hover pattern applied to the part rows.
 *
 * The tree carries no wearing chrome and always prints the layered
 * EFFECTIVE document: the wearing story reads off the islands themselves
 * (variant-supplied lines wear their layer's identity hue; hover names
 * the supplier; a shadowed line's winner dot navigates), and variant
 * management lives in the widget modes' wearing rows and the editing
 * session. The one row gesture: right-clicking a row whose
 * element wears a composition ROOT opens the row context menu (the
 * shared menu primitive, the wearing rows' pattern) carrying Re-apply — the same
 * fresh-resolution gesture as the wearing rows', reapplyRef-gated
 * exactly like the old inline button.
 *
 * The keyboard walks the whole tree as one surface: a shared stitching
 * registry (stitching.js) chains every element's islands in layout order,
 * so Tab past an element's last declaration lands on the next element's
 * first, and arrows cross the same boundaries. Row selects and the row
 * menu are mouse chrome — tabindex="-1", never cursor stops.
 *
 *
 * Everything flows through the state source, so the satellite renders this
 * view with full parity: the layout tree, uuid tables, and root uuid ride
 * the state broadcast, and edits/gestures post back as intents.
 */
export const PageCodeView = ({
  source,
  guide,
  materials,
  selected,
  selectedPart = null,
  styleMap,
  variants,
  scope = 'page',
  posed = 'default',
  onPose = null,
}) => {
  // The row context menu (the shared primitive): the wearing rows'
  // right-click pattern on tree rows, carrying the Re-apply gesture.
  const menu = useMenu();
  const containerRef = useRef(null);
  // One stitching registry for the whole tree, so the keyboard chain
  // crosses element boundaries in render order — which IS pageNodes'
  // layout order. Tree chrome (row selects, chips, the row menu,
  // placeholders) carries tabindex="-1": the mouse's gestures, never
  // stops on the keyboard chain.
  const stitchRef = useRef(null);
  if (!stitchRef.current) {
    stitchRef.current = createStitching();
  }
  const library = variants ?? [];
  const map = styleMap ?? {};
  const layout = source.getLayout?.() ?? [];

  const nodes = scopeNodes(
    pageNodes(layout, source.getRootUuid()),
    scope,
    selected,
  );
  const tree = visibleTree(nodes, map, {
    keep: [selected].filter(Boolean),
  });

  // Selection moves reset the row menu (the variant area's rule): the
  // menu's row may have scrolled away or changed meaning.
  useEffect(() => {
    menu.close();
  }, [selected]);

  // Canvas -> tree: a selection change brings the element's row into
  // view — or, with a part facet on it, the part's own header (the drill
  // is the navigation target then; the element row sits just above).
  useEffect(() => {
    if (!selected || !containerRef.current) {
      return;
    }
    const escaped = (value) => value.replace(/[\\"]/g, '\\$&');
    const row =
      (selectedPart
        ? containerRef.current.querySelector(
            `[data-daedalus-stylish-part-owner="${escaped(selected)}"][data-daedalus-stylish-part="${escaped(selectedPart)}"]`,
          )
        : null) ??
      containerRef.current.querySelector(
        `[data-daedalus-stylish-page-key="${escaped(selected)}"]`,
      );
    row?.scrollIntoView({ block: 'nearest' });
  }, [selected, selectedPart]);

  // Canvas/panel -> tree: the shared selector hover lights the element's
  // row. Painted imperatively at pointer speed (see the header comment);
  // no scroll — hover is a glance, selection is the navigation gesture.
  // Keys carry a validated charset; escape the two characters that could
  // break out of the quoted attribute value (tree-parts' idiom).
  const hoverPaintedRef = useRef(null);
  const hoverHeaderFor = (key) =>
    key && containerRef.current
      ? containerRef.current.querySelector(
          `[data-daedalus-stylish-page-key="${key.replace(/[\\"]/g, '\\$&')}"]`,
        )
      : null;
  const paintHover = (key) => {
    const next = key ?? null;
    if (hoverPaintedRef.current === next) {
      return;
    }
    hoverHeaderFor(hoverPaintedRef.current)?.classList.remove(
      'daedalus-stylish-page-tree__header--hovered',
    );
    hoverPaintedRef.current = next;
    hoverHeaderFor(next)?.classList.add('daedalus-stylish-page-tree__header--hovered');
  };
  useEffect(() => {
    if (!source.onHoverChange) {
      return undefined;
    }
    paintHover(source.getHovered?.() ?? null);
    const unsubscribe = source.onHoverChange(paintHover);
    return () => {
      paintHover(null);
      unsubscribe();
    };
  }, [source]);
  // Canvas Cmd-hover / tree part-row hover -> tree: the spine's hoverPart
  // facet lights the matching part header's hot mark. Same imperative
  // regime as the element hover above; a facet whose part row isn't
  // rendered (an unstyled part) just matches nothing.
  const partHotPaintedRef = useRef(null);
  const partHeaderFor = (pair) =>
    pair && containerRef.current
      ? containerRef.current.querySelector(
          `[data-daedalus-stylish-part-owner="${pair.key.replace(/[\\"]/g, '\\$&')}"][data-daedalus-stylish-part="${pair.part.replace(/[\\"]/g, '\\$&')}"]`,
        )
      : null;
  const paintPartHot = (pair) => {
    const next = pair ?? null;
    if (
      partHotPaintedRef.current?.key === next?.key &&
      partHotPaintedRef.current?.part === next?.part
    ) {
      return;
    }
    partHeaderFor(partHotPaintedRef.current)?.classList.remove(
      'daedalus-stylish-islands__part--hot',
    );
    partHotPaintedRef.current = next;
    partHeaderFor(next)?.classList.add('daedalus-stylish-islands__part--hot');
  };
  useEffect(() => {
    if (!source.onHoverPartChange) {
      return undefined;
    }
    paintPartHot(source.getHoveredPart?.() ?? null);
    const unsubscribe = source.onHoverPartChange(paintPartHot);
    return () => {
      paintPartHot(null);
      unsubscribe();
    };
  }, [source]);
  // A re-render rewrites row classNames from props, wiping the painted
  // classes while a hover is parked on a row — re-assert them after every
  // render (a no-op classList.add in the common case).
  useEffect(() => {
    hoverHeaderFor(hoverPaintedRef.current)?.classList.add(
      'daedalus-stylish-page-tree__header--hovered',
    );
    partHeaderFor(partHotPaintedRef.current)?.classList.add(
      'daedalus-stylish-islands__part--hot',
    );
  });

  const nodeTitle = (node) => {
    if (node.kind === 'entity') {
      return { kind: Drupal.t('Page'), detail: null };
    }
    const kind = KIND_LABELS[node.kind]
      ? Drupal.t(KIND_LABELS[node.kind])
      : node.kind;
    const detail =
      node.kind === 'container'
        ? node.layoutId
        : node.kind === 'block'
          ? node.blockType
          : null;
    return { kind, detail };
  };

  // The row context menu's items (the wearing-row pattern, the shared menu
  // chrome): the Re-apply gesture on a worn composition root — the exact
  // gate the old inline button had (reapplyRef) — same fresh-resolution
  // semantics as the variant area's menu item.
  const rowMenuItems = (key, reapply) => [
    {
      label: Drupal.t('Re-apply'),
      title: Drupal.t(
        'Resolve this variant against the current structure again: children added since it was applied get dressed where its fragments are not yet worn.',
      ),
      run: () => {
        source
          .applyVariant(key, reapply.variant, Drupal.t('Re-applied a variant'))
          .catch(() => {});
      },
    },
  ];

  const renderNode = (node) => {
    const isSelected = node.key === selected;
    const active = node.styled || isSelected;
    const reapply = reapplyRef(map, node.key, library);
    const { kind, detail } = nodeTitle(node);

    return (
      <div
        key={node.key}
        className={`daedalus-stylish-page-tree__node daedalus-stylish-page-tree__node--${node.kind}`}
      >
        {/* Tree -> canvas/panel: the row IS the element for hover too —
            enter drives the shared selector hover, and leave clears only
            a hover this row set (the panel rows' guard; never clobber
            the canvas's). The islands beneath stay hover-neutral: they
            are an editing surface, not the element's chrome row. */}
        <div
          className={`daedalus-stylish-page-tree__header${isSelected ? ' daedalus-stylish-page-tree__header--selected' : ''}`}
          data-daedalus-stylish-page-key={node.key}
          onMouseEnter={() => source.hover?.(node.key)}
          onMouseLeave={() => {
            if (source.hover && source.getHovered?.() === node.key) {
              source.hover(null);
            }
          }}
          onContextMenu={
            reapply
              ? (event) => {
                  event.preventDefault();
                  menu.open({
                    x: event.clientX,
                    y: event.clientY,
                    items: rowMenuItems(node.key, reapply),
                  });
                }
              : undefined
          }
        >
          {/* Tree -> canvas: the row IS the element; clicking it selects on
              the canvas (via intent from the satellite), and the echo comes
              back through the selection subscription as the highlight. */}
          <button
            type="button"
            tabIndex={-1}
            className="daedalus-stylish-page-tree__select"
            title={node.key}
            aria-pressed={isSelected}
            onClick={() => source.select?.(node.key)}
          >
            <span className="daedalus-stylish-page-tree__kind">{kind}</span>
            {detail && (
              <code className="daedalus-stylish-page-tree__detail">{detail}</code>
            )}
          </button>
        </div>
        {/* A bare header row is a structural ancestor's whole rendering:
            clicking it selects, and selection mounts real islands. */}
        {active && (
          <div className="daedalus-stylish-page-tree__islands">
            <ElementIslands
              source={source}
              guide={guide}
              materials={materials}
              elementKey={node.key}
              styleMap={styleMap}
              variants={library}
              stitch={stitchRef.current}
              posed={posed}
              onPose={onPose}
              posedGhost={isSelected}
              sparse={!isSelected}
              selectedPart={isSelected ? selectedPart : null}
            />
          </div>
        )}
        {node.children.length > 0 && (
          <div className="daedalus-stylish-page-tree__children">
            {node.children.map(renderNode)}
          </div>
        )}
      </div>
    );
  };

  return (
    /* data-selection-hover-scope: rows drive the shared selection hover
       through their own enter/leave handlers; without the scope, the
       canvas resolver would clear a row's hover on every pointer move
       (the page-elements tree's arrangement). Inert in the satellite,
       whose window runs no canvas resolver. */
    <div
      className="daedalus-stylish-page-tree"
      ref={containerRef}
      data-selection-hover-scope
    >
      {tree.length ? (
        tree.map(renderNode)
      ) : (
        /* Styled-only has no in-panel way in, so the empty state teaches
           the real one. */
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t(
            'Nothing styled yet — select an element on the canvas to start.',
          )}
        </div>
      )}
    </div>
  );
};
