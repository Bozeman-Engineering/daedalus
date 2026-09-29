import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { ElementIslands } from './element-view.js';
import {
  pageNodes,
  scopeNodes,
  visibleTree,
  reapplyRef,
  rowWords,
  hasBody,
  outlineOf,
  foldSummary,
} from './page-model.js';
import {
  isCollapsed,
  toggle,
  expand,
  reveal,
} from '../../../../daedalus_ui/js/outline/collapse.js';
import { CollapseControls } from '../../../../daedalus_ui/js/outline/collapse-controls.js';
import { OUTLINE_ID, mountOutline } from './collapse-seam.js';
import { layerColor } from './layers.js';
import { createStitching } from './stitching.js';
import { useMenu } from '../menu.js';
import { CODE_SCOPES } from '../code-scope.js';
import { findVariant } from '../variant-scope.js';

/**
 * The scope sentence's words, keyed by the scope vocabulary
 * (code-scope.js): "Showing this element", "Showing this element and
 * what is inside it", "Showing the whole page". Read at render, never
 * at load, because the satellite window's Drupal is its own.
 */
const scopeWords = () => ({
  element: Drupal.t('this element'),
  subtree: Drupal.t('this element and what is inside it'),
  page: Drupal.t('the whole page'),
});

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
 * scopes without a selection project nothing; the view renders the
 * inspector's shared "select an element" empty state (the `empty` prop)
 * under the showing row instead of the tree.
 *
 * The scope is the third of the inspector's four controls (place, mode,
 * scope, pose; surface-state.js), the one that exists only in code
 * mode. It reads as the SHOWING ROW at the top of this view, after the
 * wear bar: the sentence "Showing this element ▾" whose scope words are
 * a button opening the shared menu with the three scopes (checked on
 * the current one; a pick writes the scope store through `onScope`, so
 * both windows agree), and, beside it, the outline's collapse-all and
 * expand-all pair (daedalus_ui's shared control). No second segmented
 * track under the mode row.
 *
 * The page is an OUTLINE that is also a working surface: every row
 * carries a chevron collapsing it to one summary line (page-model.js
 * foldSummary: the element's own declaration count and one dot per
 * variant it wears, in the layer's hue) and hiding its islands and its
 * children, the outline's rule. The posture is this view's own state
 * over daedalus_ui's one collapse model (js/outline/collapse.js, the
 * page elements tree's too): the rendered tree projected into the
 * model's shape per render (page-model.js outlineOf, every row open by
 * default so nothing is hidden until collapsed), the explicit postures
 * as a plain object the chevron, the pair, the hotkeys and the
 * selection rule all write through the model. Independent of the
 * selection except for one rule: a selection change reveals the
 * selected row (its ancestors open) and expands it, then scrolls to it,
 * so canvas selection sync always lands on a visible row. Per window,
 * never persisted: a collapse is a reading posture, not a preference,
 * and the satellite collapses its own outline (the leader's hotkeys
 * reach it as a gesture over the pop-out protocol; collapse-seam.js).
 * A collapsed row unmounts its islands' print (no editor is ever held;
 * the stitching registry chains the open rows only), so the Tab chain
 * walks what is on screen; this is collapsing, not virtualization (no
 * row-height bookkeeping, nothing kept mounted hidden). The SELECTED
 * row's islands print
 * the defaults floor beneath the map's lines (its stacks are built with
 * the floor, element-view.js stackFor; one `declared:{scope}` /
 * `skin:{scope}` layer per declaring scope) and, after its own rows,
 * one rule island per ancestor rule reaching it (element-view.js
 * rulesFor over element-model.js ancestorIslands, nearest ancestor
 * first, headed by the address words the subject line's chip speaks,
 * lit under the write address it carries down as `address`) in every
 * scope; every other row prints the map alone, DevTools' rule
 * (inherited rules for the element you are inspecting), so the Page
 * scope stays readable.
 *
 * Every element of the scoped tree is a row (page-model.js visibleTree
 * annotates, the scope alone drops rows): the outline is a structure
 * view in layout order, and what an element's styling decides is the
 * row's body. An unstyled row is BARE, a header alone with nothing
 * beneath (no editor for nothing to print, the honesty rule) and no
 * chevron, only its footprint so the words align. Unselected styled rows
 * are SPARSE (ElementIslands' occupiedRoster): only islands with lines
 * render, so an element styled purely through its parts shows no empty
 * root placeholder. The way in for an empty island is selection (a row
 * click, the page elements panel, or the canvas), because the SELECTED
 * element always mounts the full ElementIslands body (gestures, drafts,
 * and placeholder islands included), styled or not; leave it empty and
 * deselect, and the row prints bare again on re-print, the ghost-island
 * honesty rule one level up. As the selection, that element alone also
 * mints the posed compound's ghost island (posedGhost; element-view.js).
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
 * PARTS sync the same two ways, one level down, over the spine's hover
 * facet and styling target: a part header's hover/click drives
 * hoverSelector/target through the source (element-view.js owns the
 * headers), the target wears the active mark there via the
 * `targetSelector` prop, and the incoming hoverSelector facet (canvas
 * Cmd-hover, tree part-row hover) paints the matching header's hot mark
 * imperatively here — the element hover pattern applied to the part rows.
 * The selected element's RULE headers (its ancestor rules) ride the same
 * seam with the owner's key in the data attributes: hover paints the
 * rule's reach, a click seats the rule as the target, and the `address`
 * prop (the inspector's write address) lights the seated one.
 *
 * The tree carries no wearing chrome of its own (the wear bar mounts
 * above it, first in the body, as in every mode) and always prints the
 * layered EFFECTIVE document: the wearing story reads off the islands
 * themselves (variant-supplied lines wear their layer's identity hue;
 * hover names the supplier; a shadowed line's winner dot navigates),
 * and variant management lives in the wearing rows and the editing
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
  targetSelector = null,
  address = null,
  styleMap,
  variants,
  scope = 'page',
  onScope = null,
  empty = null,
  posed = 'default',
  onPose = null,
  onOpenDocument = null,
}) => {
  // The row context menu (the shared primitive): the wearing rows'
  // right-click pattern on tree rows, carrying the Re-apply gesture. The
  // showing row's scope menu opens the same primitive through the same
  // ownership (one consumer, one menu at a time).
  const menu = useMenu();
  const containerRef = useRef(null);
  // The outline's posture (daedalus_ui/js/outline/collapse.js): the
  // explicit postures by row key, null for "every row at its default"
  // (open). View state, per window: a collapse is a reading posture,
  // not a preference, and the satellite collapses its own outline.
  const [folds, setFolds] = useState(null);
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

  const pageTree = pageNodes(layout, source.getRootUuid());
  const nodes = scopeNodes(pageTree, scope, selected);
  const tree = visibleTree(nodes, map);
  // The rendered tree in the collapse model's shape, projected per
  // render (the scope decides the rows; a row projected away keeps its
  // posture for its return). The hotkeys registered at leader boot and
  // the satellite's routed gesture read the LATEST render's posture and
  // outline through the seam, never the mount's.
  const outline = outlineOf(tree, selected);
  const latest = useRef(null);
  latest.current = { folds, outline };
  useEffect(
    () => mountOutline({ read: () => latest.current, apply: setFolds }),
    [],
  );
  // The two anchored scopes without a selection project nothing: the
  // showing row still renders (it is the way to the Page scope), the
  // body is the inspector's shared empty state.
  const anchorless = scope !== 'page' && !selected;

  // Selection moves reset the row menu (the variant area's rule): the
  // menu's row may have scrolled away or changed meaning.
  useEffect(() => {
    menu.close();
  }, [selected]);

  // Canvas -> tree: a selection change reveals the selected row (every
  // row above it opens, the model's selection rule) and expands the row
  // itself, so its islands print (the posture keeps its identity when
  // nothing was collapsed, so no re-render), and arms the scroll below.
  // A part facet re-arms it too: the part's own header is the
  // navigation target then (the element row sits just above). The row
  // is expanded by its id at code mode's default (open): a key the
  // outline lacks then changes nothing, as reveal changes nothing.
  const scrollPendingRef = useRef(false);
  useEffect(() => {
    if (!selected) {
      return;
    }
    setFolds((current) =>
      expand(reveal(current, outline, selected), {
        id: selected,
        folded: false,
      }),
    );
    scrollPendingRef.current = true;
  }, [selected, targetSelector]);
  // The scroll runs after the render that holds the row: a row under a
  // collapsed ancestor mounts only once the opening above has re-rendered,
  // so the arm stays set until the row is found (an element the tree
  // never prints leaves it armed for the next selection, harmlessly).
  // The row reveals by the one reveal rule (Drupal.Daedalus.scrollReveal,
  // daedalus_ui/scroll_reveal, read off this window's facade so the
  // satellite reveals in its own document): the whole row in the tree's
  // scrollport, centered when it was out of view, unmoved when it showed.
  useEffect(() => {
    if (!scrollPendingRef.current || !selected || !containerRef.current) {
      return;
    }
    const escaped = (value) => value.replace(/[\\"]/g, '\\$&');
    const row =
      (targetSelector
        ? containerRef.current.querySelector(
            `[data-daedalus-stylish-part-owner="${escaped(selected)}"][data-daedalus-stylish-part="${escaped(targetSelector)}"]`,
          )
        : null) ??
      containerRef.current.querySelector(
        `[data-daedalus-stylish-page-key="${escaped(selected)}"]`,
      );
    if (row) {
      Drupal.Daedalus.scrollReveal(row);
      scrollPendingRef.current = false;
    }
  });

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
  // Canvas Cmd-hover / tree part-row hover -> tree: the spine's hoverSelector
  // facet lights the matching part header's hot mark. Same imperative
  // regime as the element hover above; a facet whose part row isn't
  // rendered (an unstyled part) just matches nothing.
  const partHotPaintedRef = useRef(null);
  const partHeaderFor = (pair) =>
    pair && containerRef.current
      ? containerRef.current.querySelector(
          `[data-daedalus-stylish-part-owner="${pair.key.replace(/[\\"]/g, '\\$&')}"][data-daedalus-stylish-part="${pair.selector.replace(/[\\"]/g, '\\$&')}"]`,
        )
      : null;
  const paintPartHot = (pair) => {
    const next = pair ?? null;
    if (
      partHotPaintedRef.current?.key === next?.key &&
      partHotPaintedRef.current?.selector === next?.selector
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
    if (!source.onHoverSelectorChange) {
      return undefined;
    }
    paintPartHot(source.getHoveredSelector?.() ?? null);
    const unsubscribe = source.onHoverSelectorChange(paintPartHot);
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

  // A row's title: the element's two minted words (page-model's `type`
  // and `name`, the annotator's spine: "Section Welcome", "Text
  // Welcome!", a bare "Section" when the element was never renamed;
  // rowWords owns the rule). No machine id ever rides a row. The entity
  // row is the page.
  const nodeTitle = (node) =>
    node.kind === 'entity'
      ? { word: Drupal.t('Page'), name: null }
      : rowWords(node, Drupal.t('Element'));

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

  // A folded row's summary line: the count and one dot per worn variant
  // in its layer hue, titled with the variant's label (the library's word
  // for it; the reference itself when the library lacks it).
  const renderSummary = (key) => {
    const { count, worn } = foldSummary(map, key);
    if (!count && !worn.length) {
      return null;
    }
    return (
      <span className="daedalus-stylish-page-tree__summary">
        {count > 0 && (
          <span className="daedalus-stylish-page-tree__count">{count}</span>
        )}
        {worn.map((layer) => {
          const reference = layer.slice(layer.indexOf(':') + 1);
          return (
            <span
              key={layer}
              className="daedalus-stylish-page-tree__worn daedalus-ui-dot"
              style={{ background: layerColor(layer) }}
              title={findVariant(library, reference)?.label ?? reference}
            />
          );
        })}
      </span>
    );
  };

  const renderNode = (node) => {
    const isSelected = node.key === selected;
    const active = node.styled || isSelected;
    // The row in the model's shape, the same rule outlineOf projects
    // (the model reads only the id and the default), so the row never
    // looks itself up in the outline.
    const row = {
      id: node.key,
      foldable: hasBody(node, selected),
      folded: false,
    };
    const open = !isCollapsed(folds, row);
    const reapply = reapplyRef(map, node.key, library);
    const { word, name } = nodeTitle(node);

    return (
      <div
        key={node.key}
        className={`daedalus-stylish-page-tree__node daedalus-stylish-page-tree__node--${node.kind}${open ? '' : ' daedalus-stylish-page-tree__node--collapsed'}${active ? '' : ' daedalus-stylish-page-tree__node--bare'}`}
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
          {/* The outline's chevron: the shared disclosure grammar's
              marker (daedalus_ui panel-controls.css, the page-elements
              tree's carrier) on this tree's own button. Mouse chrome
              like the rest of the row (tabindex -1): the keyboard chain
              walks declarations, not rows. Only a row with a body to
              hide (hasBody) carries it; a bare leaf renders the
              button's footprint as an inert spacer so its words start
              in the same column. */}
          {row.foldable ? (
            <button
              type="button"
              tabIndex={-1}
              className="daedalus-stylish-page-tree__chevron daedalus-ui-tree__disclosure"
              aria-expanded={open}
              aria-label={open ? Drupal.t('Collapse') : Drupal.t('Expand')}
              onClick={() => setFolds((current) => toggle(current, row))}
            >
              <span
                className="daedalus-ui-tree__disclosure-marker"
                aria-hidden="true"
              ></span>
            </button>
          ) : (
            <span
              className="daedalus-stylish-page-tree__chevron-spacer"
              aria-hidden="true"
            ></span>
          )}
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
            <span className="daedalus-stylish-page-tree__kind">{word}</span>
            {name !== null && (
              <span className="daedalus-stylish-page-tree__name">{name}</span>
            )}
          </button>
          {!open && renderSummary(node.key)}
        </div>
        {/* A bare header row is an unstyled element's whole rendering:
            clicking it selects, and selection mounts real islands. A
            collapsed row mounts neither islands nor children. */}
        {open && active && (
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
              targetSelector={isSelected ? targetSelector : null}
              address={isSelected ? address : null}
              onOpenDocument={onOpenDocument}
            />
          </div>
        )}
        {open && node.children.length > 0 && (
          <div className="daedalus-stylish-page-tree__children">
            {node.children.map(renderNode)}
          </div>
        )}
      </div>
    );
  };

  // The showing row's scope menu: the three scopes in vocabulary order,
  // the current one checked, a pick written through the store (onScope).
  const words = scopeWords();
  const openScopeMenu = (event) => {
    menu.open({
      anchor: event.currentTarget,
      header: Drupal.t('Showing'),
      items: CODE_SCOPES.map((value) => ({
        label: words[value],
        checked: value === scope,
        run: () => onScope?.(value),
      })),
    });
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
      {/* The showing row: the scope as a sentence with a menu, and the
          outline's collapse-all and expand-all pair at the row's end
          (daedalus_ui's shared control over this view's posture: each
          button disabled when its gesture would do nothing, a tree of
          bare leaves included; the tooltips name the key once the user
          binds one in the tray). */}
      <div className="daedalus-stylish-page-tree__showing">
        <span className="daedalus-stylish-page-tree__showing-word">
          {Drupal.t('Showing')}
        </span>
        <button
          type="button"
          className="daedalus-stylish-page-tree__scope"
          aria-haspopup="menu"
          data-scope={scope}
          onClick={openScopeMenu}
        >
          {words[scope] ?? words.element}
        </button>
        <CollapseControls
          id={OUTLINE_ID}
          folds={folds}
          outline={outline}
          apply={(next) => setFolds(next)}
        />
      </div>
      {anchorless ? (
        empty
      ) : tree.length ? (
        tree.map(renderNode)
      ) : (
        /* Every element is a row, so the Page scope is empty only when
           the page holds no element: the empty state names the way in. */
        <div className="daedalus-stylish-inspector__empty">
          {Drupal.t(
            'Nothing on the page yet: add an element on the canvas to start.',
          )}
        </div>
      )}
    </div>
  );
};
