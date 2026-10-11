import { h, Fragment, render } from 'preact';
import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'preact/hooks';
import { CollapseControls } from '../../../../daedalus_ui/js/outline/collapse-controls.js';
import { registerCollapseHotkeys } from '../../../../daedalus_ui/js/outline/collapse-hotkeys.js';
import {
  collapse,
  expand,
  isCollapsed,
  toggle,
} from '../../../../daedalus_ui/js/outline/collapse.js';
import {
  BLUEPRINT_PREFIX,
  KIND_BASIC,
  KIND_BLUEPRINT,
  faceList,
  isFavorite,
  newBlueprintIds,
  rowKey,
  toggledMarks,
} from '../model/faces.js';
import { FAVORITES_ID, buildSets } from '../model/sets.js';
import { SEARCH_ID, search } from '../model/search.js';
import { outlineOf } from '../model/outline.js';
import { readFolds, windowStorage, writeFolds } from '../model/fold-store.js';
import { createDwell } from '../model/dwell.js';
import { createPeekCache } from '../model/peek-cache.js';
import { saveFavorites } from '../favorites.js';
import { requestPeek } from '../peek.js';
import { PeekFrame } from './peek-frame.js';
import { canEditFace, setSiteFavorite } from '../site-favorite.js';
import {
  blueprintMenuItems,
  failureMessage,
  report,
} from '../blueprint-gestures.js';
import {
  ATTR,
  PEEK_ID,
  ROW_SELECTOR,
  SEARCH_SELECTOR,
  STOP_SELECTOR,
  TOOLS_ID,
  allStops,
  mountElement,
  panelElement,
  rowKindOf,
  rowSource,
  visibleRows,
} from '../panel-dom.js';

/**
 * @file
 * The Add panel: one list of folding sets, drawn from two feeds.
 *
 * The search box, then the sets as folding headings with counts
 * (Favorites, each group that holds anything, All nested by kind), rows
 * not cards: an icon, a label, a star. The sets are the model's
 * (../model/sets.js) over the faces (../model/faces.js), which project
 * the server's answer (drupalSettings.daedalusLayout.addPanel: the
 * blocks, the vocabulary, the editor's marks) and the materials store's
 * blueprint records onto one face, so one renderer draws every row and a
 * blueprint saved anywhere repaints here the moment its delta lands.
 *
 * Folding is daedalus_ui's: the collapse model for the posture rule, the
 * CollapseControls pair seated in the header's slot and the registrar's
 * two hotkeys (unbound by default, since the page elements tree holds 3
 * and 4 and both panels may be open at once). The explicit postures are
 * this component's state, seeded from the fold store (localStorage) and
 * written back on every change, so the panel opens as the editor left
 * it; a blocked store degrades to the defaults (Favorites open, the rest
 * folded).
 *
 * The panel is its own keyboard surface, like the page elements tree: a
 * CAPTURE-phase document listener owns keys while the event targets the
 * panel, so the document-level hotkey dispatcher (whose selection-context
 * arrow traversal would otherwise move the SELECTION while the user is
 * arrowing the panel, and whose lift-context Enter would double-fire on
 * the very keypress that lifted) never sees a handled key. While a lift
 * holds, the surface defers entirely. The keys: Down from the box enters
 * the list and Enter there lifts the first drawn row; Up and Down walk
 * the stops (headings and rows), Up off the first stop returns to the
 * box; a heading toggles on Enter or Space, collapses on Left and
 * expands on Right; a row lifts on Enter or Space, stars on S, opens its
 * menu on Shift+F10 or the menu key, and Left returns to its heading.
 * Exactly one stop is the panel's Tab stop (roving tabindex): focusing a
 * stop re-seats it, and a re-render that drew the seated stop away seats
 * the first.
 *
 * The star and the row menu ("Add to my favorites", "Remove from my
 * favorites") flip the editor's mark (../model/faces.js toggledMarks),
 * repaint at once and post the whole map (../favorites.js); a failed
 * save rolls the mark back and reports. A blueprint this editor mints
 * (the Save as verb, an AI turn in the editor's request) is the
 * creator's favorite through the same path the moment its record lands
 * (../model/faces.js newBlueprintIds): the materials store's delta is
 * per request, so a record new to this session's store is this editor's
 * own, while a boot (the page's envelope, which may carry other editors'
 * records) marks nothing. Where the face says the actor
 * may write its site default (`editable`), the menu also offers
 * "Favorite for everyone" (../site-favorite.js): a blueprint's lands
 * through the details route and repaints through the store, a block
 * type's through daedalus_layout's route and is restated in the block
 * faces this component holds. A blueprint row's menu keeps Edit
 * details… and Delete… (../blueprint-gestures.js).
 *
 * The insert session (../insert-session.js) owns the drag sources, the
 * Insert verb, click-to-place and the armed highlight; the rows reach it
 * through the DOM contract (../panel-dom.js): each row arms its drag
 * source on mount and the session reads a row's source off its hooks.
 *
 * The peek: resting on a row for a moment (the dwell, ../model/dwell.js,
 * armed by the pointer entering the row or the row taking focus) posts
 * the row's own drop batch, the one the session composes for a drop at
 * the end of the page (session.peekRequest), to the peek door
 * (../peek.js) and frames the answer beside the panel (./peek-frame.js).
 * Every row peeks through this one mechanism: a blueprint as it is, a
 * view with its rows, a field with this page's value, a block with the
 * sample content a placement fills in. Nothing is preloaded: a row asks
 * the door on its first rest, and its answer is held for the page load
 * (../model/peek-cache.js, keyed on the face id, with the key of the
 * element it shows, since the drop batch mints a fresh uuid per call),
 * so a second rest on the row shows it at once with no request; the
 * dwell still gates a hit, so a sweep flashes nothing. A fired request
 * is never aborted (the server renders either way, so its answer may as
 * well land in the cache); the frame takes an answer only while its face
 * is still the shown one. Refusals are not held. The held answers go
 * whenever this session's staged copy changes (every mutation, undo,
 * redo and history jump replaces the blueprint mirror), and every
 * blueprint row's goes on a materials delivery (a record does not say
 * what changed inside it). The shown frame is gone on leave, blur,
 * Escape, a drag start or a lift, the list scrolling, the menu opening,
 * the panel closing.
 */

/**
 * The outline's id in the hotkey registry and the controls' tooltips.
 */
export const OUTLINE_ID = 'daedalus_layout.add_panel';

/**
 * The star glyph: a five-point star, stroked when off and filled when on
 * (the CSS keys the fill off aria-pressed).
 */
const STAR_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" aria-hidden="true"><path d="M128 24l32 68 74 9-55 51 15 73-66-37-66 37 15-73-55-51 74-9z"/></svg>';
const PLACE_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M224,128a8,8,0,0,1-8,8H136v80a8,8,0,0,1-16,0V136H40a8,8,0,0,1,0-16h80V40a8,8,0,0,1,16,0v80h80A8,8,0,0,1,224,128Z"></path></svg>';
const LINK_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M137.54,186.36a8,8,0,0,1,0,11.31l-9.94,10A56,56,0,0,1,48.38,128.4L72.5,104.28A56,56,0,0,1,149.31,102a8,8,0,1,1-10.64,12,40,40,0,0,0-54.85,1.63L59.7,139.72a40,40,0,0,0,56.58,56.58l9.94-9.94A8,8,0,0,1,137.54,186.36Zm70.08-138a56.08,56.08,0,0,0-79.22,0l-9.94,9.95a8,8,0,0,0,11.32,11.31l9.94-9.94a40,40,0,0,1,56.58,56.58L172.18,140.4A40,40,0,0,1,117.33,142,8,8,0,1,0,106.69,154a56,56,0,0,0,76.81-2.26l24.12-24.12A56.08,56.08,0,0,0,207.62,48.38Z"></path></svg>';

/**
 * The "for everyone" glyph: Phosphor users (currentColor, the verb-icon
 * convention), beside the star that marks the editor's own.
 */
const EVERYONE_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256" aria-hidden="true"><path d="M117.25,157.92a60,60,0,1,0-66.5,0A95.83,95.83,0,0,0,3.53,195.63a8,8,0,1,0,13.4,8.74,80,80,0,0,1,134.14,0,8,8,0,0,0,13.4-8.74A95.83,95.83,0,0,0,117.25,157.92ZM40,108a44,44,0,1,1,44,44A44.05,44.05,0,0,1,40,108Zm210.14,98.7a8,8,0,0,1-11.07-2.33A79.83,79.83,0,0,0,172,168a8,8,0,0,1,0-16,44,44,0,1,0-16.34-84.87,8,8,0,1,1-5.94-14.85,60,60,0,0,1,55.53,105.64,95.83,95.83,0,0,1,47.22,37.71A8,8,0,0,1,250.14,206.7Z"></path></svg>';

const handled = (event) => {
  event.preventDefault();
  event.stopPropagation();
};

/**
 * The key a stop answers to across a re-render: a row's set and face, a
 * heading's set.
 */
const stopKey = (stop) =>
  stop.hasAttribute(ATTR.set)
    ? `set|${stop.getAttribute(ATTR.set)}`
    : rowKey(stop.getAttribute(ATTR.in), stop.getAttribute(ATTR.row));

/**
 * Every node of an outline by id.
 */
const nodesById = (outline) => {
  const nodes = new Map();
  const walk = (list) =>
    (list ?? []).forEach((node) => {
      nodes.set(node.id, node);
      walk(node.children);
    });
  walk(outline);
  return nodes;
};

/**
 * A row: the one face, in one set.
 */
const Row = ({
  app,
  face,
  setId,
  depth,
  home,
  favorite,
  defaultIcon,
  onStar,
  onPeek,
  onPeekEnd,
}) => {
  const ref = useRef(null);
  // The row is a drag source for its whole life: the source and its data
  // are read off the row's own hooks at dragstart (one reading with the
  // keyboard lift and click-to-place), and a fresh data object per drag
  // since the sources write the placed uuid onto it.
  useEffect(() => {
    const element = ref.current;
    app.drag.makeDraggable(
      element,
      rowSource(element).source,
      (el) => rowSource(el).data,
    );
    return () => app.drag.removeDraggable(element);
  }, []);
  const src = face.icon ?? defaultIcon;
  const starLabel = favorite
    ? Drupal.t('Remove from my favorites')
    : Drupal.t('Add to my favorites');
  return (
    <div
      ref={ref}
      className="daedalus-ui-panel-item daedalus-layout-add-panel__row"
      role="button"
      tabIndex={-1}
      title={face.description || undefined}
      // The outline row grammar's depth token (variables.css), the one
      // the headings step in by, so a kind's rows sit under their heading;
      // the row is a panel item, not an outline row, so add-panel.css
      // spends the token as the card's margin.
      style={{ '--daedalus-outline-depth': depth }}
      data-origin={face.blueprint?.origin}
      {...{
        [ATTR.row]: face.id,
        [ATTR.in]: setId,
        [ATTR.kind]: rowKindOf(face.id),
      }}
      // The peek's four gestures: rest (enter or focus) arms it, leave
      // and blur end it, a drag start ends it (the lift ends it in the
      // panel's keyboard surface).
      onMouseEnter={() => onPeek(face, ref.current, setId)}
      onMouseLeave={onPeekEnd}
      onFocus={() => onPeek(face, ref.current, setId)}
      onBlur={onPeekEnd}
      onDragStart={onPeekEnd}
    >
      <div
        className="daedalus-ui-panel-item__image"
        style={{ backgroundImage: src ? `url("${src}")` : '' }}
      ></div>
      <div className="daedalus-ui-panel-item__label daedalus-layout-add-panel__label">
        {face.label}
        {home && (
          <span className="daedalus-layout-add-panel__home" {...{ [ATTR.home]: '' }}>
            {home}
          </span>
        )}
      </div>
      <button
        type="button"
        className="daedalus-layout-add-panel__star"
        tabIndex={-1}
        aria-pressed={favorite}
        aria-label={starLabel}
        data-daedalus-ui-tooltip={starLabel}
        {...{ [ATTR.star]: '' }}
        onClick={(event) => {
          // Never a click-to-place: the star is the row's own control.
          event.stopPropagation();
          onStar(face, ref.current);
        }}
        dangerouslySetInnerHTML={{ __html: STAR_ICON }}
      />
    </div>
  );
};

/**
 * A heading: a set's name, its count and its disclosure.
 *
 * One button wearing daedalus_ui's outline row grammar (panel-controls.css
 * .daedalus-ui-outline__row, the page elements tree's and code mode's) in
 * its heading voice: the chevron column holds the disclosure marker (the
 * heading itself is the toggle, so the column is a span, not the tree's
 * button, and the grammar keys the marker's rotation off the row's own
 * aria-expanded), the name zone the set's label, the facts zone its count.
 * Depth is the grammar's token set inline, so the row pads itself and its
 * wash spans the panel; data-depth stays beside it for the sticky rule
 * alone (add-panel.css), since CSS cannot select on a custom property.
 */
const Heading = ({ set, depth, collapsed, onToggle }) => (
  <button
    type="button"
    className="daedalus-ui-outline__row daedalus-ui-outline__row--heading daedalus-layout-add-panel__heading"
    tabIndex={-1}
    aria-expanded={!collapsed}
    title={set.description || undefined}
    data-depth={depth}
    style={{ '--daedalus-outline-depth': depth }}
    {...{ [ATTR.set]: set.id }}
    onClick={onToggle}
  >
    <span className="daedalus-ui-outline__chevron" aria-hidden="true">
      <span className="daedalus-ui-tree__disclosure-marker"></span>
    </span>
    <span className="daedalus-ui-outline__name">{set.label}</span>
    <span className="daedalus-ui-outline__facts">
      <span className="daedalus-ui-outline__count">{set.count}</span>
    </span>
  </button>
);

/**
 * The panel.
 *
 * @param {object} props
 *   - app: the app the queue hands a bundle.
 *   - session: the insert session (createInsertSession).
 *   - settings: drupalSettings.daedalusLayout.addPanel (faces, groups,
 *     favorites, defaultIcon).
 */
export const AddPanel = ({ app, session, settings }) => {
  const words = useMemo(
    () => ({
      favorites: Drupal.t('Favorites'),
      all: Drupal.t('All'),
      basics: Drupal.t('Basics'),
      blueprints: Drupal.t('Blueprints'),
    }),
    [],
  );
  const groups = settings.groups ?? [];
  const defaultIcon = settings.defaultIcon ?? null;

  // The blocks arrive once with the page; the component holds them so a
  // "Favorite for everyone" on a block type restates the one face it
  // moved without a reload.
  const [blockFaces, setBlockFaces] = useState(() => settings.faces ?? []);
  const [records, setRecords] = useState(() =>
    Drupal.Daedalus.Materials.ofKind('blueprint'),
  );
  const [marks, setMarks] = useState(() => ({ ...(settings.favorites ?? {}) }));
  const [folds, setFolds] = useState(() => readFolds(windowStorage()));
  const [query, setQuery] = useState('');
  const [armed, setArmed] = useState(() => session.pendingAnchor());
  const [peek, setPeek] = useState(null);

  const faces = useMemo(() => faceList(blockFaces, records), [blockFaces, records]);
  const sets = useMemo(() => buildSets(faces, groups, marks, words), [faces, groups, marks, words]);
  const outline = useMemo(() => outlineOf(sets), [sets]);
  const nodes = useMemo(() => nodesById(outline), [outline]);
  const hits = useMemo(() => search(faces, groups, query, words), [faces, groups, query, words]);
  const facesById = useMemo(() => new Map(faces.map((face) => [face.id, face])), [faces]);

  // The latest state for the document-level listeners, which are
  // installed once.
  const latest = useRef({});
  latest.current = { folds, outline, nodes, marks, facesById };

  // The peek's dwell (one pending peek at a time) and the answers held
  // for the page load, by face (the panel mounts once per page lifetime,
  // so component state lives as long as the cache should).
  const dwell = useMemo(() => createDwell(), []);
  const peekCache = useMemo(() => createPeekCache(), []);

  /**
   * Ends the peek: the pending one and the shown one alike. A request in
   * flight is left to land; its answer fills the cache for the next rest.
   */
  const endPeek = () => {
    dwell.cancel();
    setPeek(null);
  };

  /**
   * Fires a peek for a rested-on row: the session composes the drop's
   * batch (null where that drop is refused: nothing to show, nothing
   * requested); a held answer frames at once, else the door answers the
   * document, the cache keeps it and the frame shows it.
   *
   * The held answer carries the key of the element it shows: the frame
   * measures that element in the document, and the batch composed on
   * this rest names a uuid the held document never rendered.
   */
  const beginPeek = (face, row) => {
    const context = Drupal.Daedalus.Blueprint.rootContext();
    const request =
      context && row.isConnected ? session.peekRequest(row) : null;
    if (!request) {
      return;
    }
    const top = row.getBoundingClientRect().top;
    const held = peekCache.get(face.id);
    if (held) {
      setPeek({
        faceId: face.id,
        label: face.label,
        show: held.show,
        status: 'ready',
        document: held.document,
        message: null,
        top,
      });
      return;
    }
    setPeek({
      faceId: face.id,
      label: face.label,
      show: request.show,
      status: 'loading',
      document: null,
      message: null,
      top,
    });
    // The answer applies only while this face is still the shown one: a
    // later rest on another row, or any ending gesture, has moved on.
    const stillShown = (current) => current?.faceId === face.id;
    requestPeek(context, request)
      .then((document) => {
        peekCache.put(face.id, { show: request.show, document });
        setPeek((current) =>
          stillShown(current)
            ? { ...current, show: request.show, status: 'ready', document }
            : current,
        );
      })
      .catch((error) => {
        setPeek((current) =>
          stillShown(current)
            ? { ...current, status: 'refused', message: failureMessage(error) }
            : current,
        );
      });
  };

  /**
   * Arms a row's peek: it fires once the row has been rested on.
   */
  const armPeek = (face, row, setId) => {
    dwell.arm(rowKey(setId, face.id), () => beginPeek(face, row));
  };

  /**
   * Lifts a row into the caret walk; a lift is never followed by a peek
   * of the thing being lifted.
   */
  const liftRow = (row) => {
    endPeek();
    session.liftRow(row);
  };

  const searchRef = useRef(null);
  // A stop to focus after the next render: the row whose star was
  // toggled (it may have left Favorites), by key, else the stop at its
  // old index.
  const pendingFocus = useRef(null);

  // The blueprint records held before the latest delivery, seeded from
  // the records read at mount so the first delivery is measured against
  // what the panel already drew, not against nothing.
  const heldRecords = useRef(records);

  // The blueprints arrive live: every delta any lane lands repaints. A
  // blueprint record new to this session's store is one this editor's
  // own request minted (the materials change set is per request, and the
  // Save as verb and an AI turn in the editor's request land the same
  // delta), so it becomes the creator's favorite through the one marks
  // path, the same way the star does. An update upserts a held id and a
  // page-scope save arrives as a remove, so neither marks. A boot is a
  // whole-set replacement from the page's envelope, re-delivered on
  // every editable-page render and carrying whatever other editors wrote
  // meanwhile: it repaints and marks nothing. Either way every blueprint
  // row's held peek goes: a record does not say what changed inside it.
  useEffect(
    () =>
      Drupal.Daedalus.Materials.onChange((roster, { booted }) => {
        peekCache.drop((faceId) => faceId.startsWith(BLUEPRINT_PREFIX));
        const next = roster.ofKind('blueprint');
        const previous = heldRecords.current;
        heldRecords.current = next;
        setRecords(next);
        if (!booted) {
          markCreated(newBlueprintIds(previous, next), next);
        }
      }),
    [],
  );

  // This session's staged copy changing (every mutation, undo, redo and
  // history jump replaces the mirror) moves what every row's drop would
  // give, so every held peek goes.
  useEffect(
    () => Drupal.Daedalus.Blueprint.onBlueprintChange(() => peekCache.clear()),
    [],
  );

  // The armed state, for the sentence: the session stamps the document
  // while an anchor is pending.
  useEffect(() => {
    const observer = new MutationObserver(() => setArmed(session.pendingAnchor()));
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    });
    return () => observer.disconnect();
  }, []);

  /**
   * Lands a posture: the state, and the store.
   */
  const applyFolds = (next) => {
    if (next === latest.current.folds) {
      return;
    }
    latest.current.folds = next;
    setFolds(next);
    writeFolds(windowStorage(), next);
  };

  /**
   * Rolls the marks back to what they were before a save the server
   * refused, and says so.
   */
  const restoreMarks = (previous, error) => {
    latest.current.marks = previous;
    setMarks(previous);
    report(error.message, 'error');
  };

  /**
   * Flips a face's effective favorite: repaint at once, post the map,
   * roll back on a refusal.
   */
  const toggleFavorite = (face, stop = null) => {
    const previous = latest.current.marks;
    const next = toggledMarks(face, previous);
    if (stop) {
      pendingFocus.current = { key: stopKey(stop), index: allStops().indexOf(stop) };
    }
    latest.current.marks = next;
    setMarks(next);
    saveFavorites(next).catch((error) => restoreMarks(previous, error));
  };

  /**
   * Marks the blueprints this editor just minted as the creator's own
   * favorites: each new face not already a favorite is flipped through
   * the star's own rule, the batch repaints at once and posts the map
   * once, and a refusal rolls the whole batch back.
   *
   * @param {Array<string>} faceIds
   *   The new records' face ids (newBlueprintIds).
   * @param {Array<object>} blueprintRecords
   *   The blueprint records the delivery carries, where the faces are read.
   */
  const markCreated = (faceIds, blueprintRecords) => {
    const previous = latest.current.marks;
    const faces = faceList([], blueprintRecords);
    const next = faceIds.reduce((marks, id) => {
      const face = faces.find((candidate) => candidate.id === id);
      return face && !isFavorite(face, marks) ? toggledMarks(face, marks) : marks;
    }, previous);
    if (next === previous) {
      return;
    }
    latest.current.marks = next;
    setMarks(next);
    saveFavorites(next).catch((error) => restoreMarks(previous, error));
  };

  /**
   * Writes a face's site default for everyone, where its face lives.
   *
   * A blueprint's repaints through the store (the response's delta); a
   * block's is restated in the block faces held here. The outcome is
   * reported either way: the row may not move (the editor's own mark
   * can hide a default), so the sentence is the one sure sign.
   */
  const setEveryonesFavorite = (face, favorite) => {
    setSiteFavorite(face, favorite)
      .then((result) => {
        if (face.kind !== KIND_BLUEPRINT) {
          setBlockFaces((current) =>
            current.map((block) =>
              block.id === result.id
                ? { ...block, favorite: result.favorite }
                : block,
            ),
          );
        }
        report(
          favorite
            ? Drupal.t('"@label" is now a favorite for everyone.', {
                '@label': face.label,
              })
            : Drupal.t('"@label" is no longer a favorite for everyone.', {
                '@label': face.label,
              }),
          'status',
        );
      })
      .catch((error) => {
        report(failureMessage(error), 'error');
      });
  };

  /**
   * Opens a row's menu at a point; false when it has no items.
   */
  const openRowMenu = (row, x, y) => {
    const face = latest.current.facesById.get(row.getAttribute(ATTR.row));
    if (!face) {
      return false;
    }
    endPeek();
    const favorite = isFavorite(face, latest.current.marks);
    const items = [
      {
        label: favorite
          ? Drupal.t('Remove from my favorites')
          : Drupal.t('Add to my favorites'),
        icon: STAR_ICON,
        run: () => toggleFavorite(face, row),
      },
    ];
    // The face's own items: the site default where the actor may write
    // it (a blueprint's or a block type's face; a plain plugin has none),
    // then a blueprint's details and delete.
    const faceItems = [];
    if (canEditFace(face)) {
      faceItems.push({
        label: face.favorite
          ? Drupal.t('Remove favorite for everyone')
          : Drupal.t('Favorite for everyone'),
        icon: EVERYONE_ICON,
        run: () => setEveryonesFavorite(face, !face.favorite),
      });
    }
    if (face.kind === KIND_BLUEPRINT) {
      faceItems.push(...blueprintMenuItems(face.blueprint));
    }
    if (faceItems.length) {
      items.push({ separator: true }, ...faceItems);
    }
    // The two ways a blueprint lands, spelled where the row's click is
    // ambiguous: while an Insert anchor is armed, Place (the row's click,
    // a copy to adapt) and Place linked (the blueprint shown by
    // reference, following its later edits). The server judges the fit
    // and the mode (place_blueprint's mode); these only choose.
    if (face.kind === KIND_BLUEPRINT && session.pendingAnchor()) {
      items.unshift(
        {
          label: Drupal.t('Place'),
          icon: PLACE_ICON,
          run: () => session.placeRow(row),
        },
        {
          label: Drupal.t('Place linked'),
          icon: LINK_ICON,
          run: () => session.placeRow(row, { linked: true }),
        },
        { separator: true },
      );
    }
    return Drupal.Daedalus.menu.open({ x, y, items });
  };

  // The collapse pair in the header's slot (a second root: the slot is
  // outside the mount), re-drawn on every posture or outline change.
  useEffect(() => {
    const slot = document.getElementById(TOOLS_ID);
    if (slot) {
      render(
        <CollapseControls id={OUTLINE_ID} folds={folds} outline={outline} apply={applyFolds} />,
        slot,
      );
    }
  }, [folds, outline]);
  useEffect(
    () => () => {
      const slot = document.getElementById(TOOLS_ID);
      if (slot) {
        render(null, slot);
      }
    },
    [],
  );

  // The peek's layer: a second root appended to body (the rail clips),
  // present only while a peek is pending an answer or showing one.
  useEffect(() => {
    let layer = document.getElementById(PEEK_ID);
    if (!peek) {
      if (layer) {
        render(null, layer);
        layer.remove();
      }
      return;
    }
    if (!layer) {
      layer = document.createElement('div');
      layer.id = PEEK_ID;
      document.body.appendChild(layer);
    }
    render(<PeekFrame peek={peek} panel={panelElement()} />, layer);
  }, [peek]);
  useEffect(
    () => () => {
      const layer = document.getElementById(PEEK_ID);
      if (layer) {
        render(null, layer);
        layer.remove();
      }
    },
    [],
  );

  // The gestures beyond a row that end the peek: Escape wherever focus
  // sits (not consumed: the key keeps its other meanings), the window
  // resizing (the layer's place is stale), and the panel closing (its
  // rows leave without a mouseleave).
  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        endPeek();
      }
    };
    document.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('resize', endPeek);
    const panel = panelElement();
    const observer = new MutationObserver(() => {
      if (!panel || panel.classList.contains('daedalus-ui-hidden')) {
        endPeek();
      }
    });
    if (panel) {
      observer.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      window.removeEventListener('resize', endPeek);
      observer.disconnect();
    };
  }, []);

  // The two all-gestures as bindings, for the outline's lifetime.
  useEffect(
    () =>
      registerCollapseHotkeys({
        id: OUTLINE_ID,
        label: Drupal.t('Blocks'),
        read: () => ({ folds: latest.current.folds, outline: latest.current.outline }),
        apply: applyFolds,
      }),
    [],
  );

  // The keyboard surface and the roving stop.
  useEffect(() => {
    const onKeyDown = (event) => {
      const panel = panelElement();
      if (
        !panel ||
        !(event.target instanceof Element) ||
        !panel.contains(event.target)
      ) {
        return;
      }
      // A live lift owns the keys wherever focus sits: the dispatcher's
      // lift context walks the caret; the surface stays out of its way.
      if (Drupal.Daedalus.Drag?.isLifted?.()) {
        return;
      }
      if (event.target.matches(SEARCH_SELECTOR)) {
        // The box hands off to the list: Down focuses the first stop,
        // Enter lifts the first drawn row directly (type, then place).
        // Every other key is native typing.
        if (event.key === 'ArrowDown') {
          const first = allStops()[0];
          if (first) {
            first.focus();
            handled(event);
          }
        } else if (event.key === 'Enter') {
          const first = visibleRows()[0];
          if (first) {
            liftRow(first);
            handled(event);
          }
        }
        return;
      }
      const stop = event.target.closest(STOP_SELECTOR);
      const mount = mountElement();
      if (!stop || !mount || !mount.contains(stop)) {
        return;
      }
      const stops = allStops();
      const index = stops.indexOf(stop);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const next = stops[index + (event.key === 'ArrowDown' ? 1 : -1)];
        if (next) {
          next.focus();
        } else if (event.key === 'ArrowUp') {
          searchRef.current?.focus();
        }
        handled(event);
        return;
      }
      if (stop.hasAttribute(ATTR.set)) {
        const node = latest.current.nodes.get(stop.getAttribute(ATTR.set));
        if (!node) {
          return;
        }
        const { folds: current } = latest.current;
        if (event.key === 'Enter' || event.key === ' ') {
          applyFolds(toggle(current, node));
        } else if (event.key === 'ArrowRight') {
          applyFolds(expand(current, node));
        } else if (event.key === 'ArrowLeft') {
          applyFolds(collapse(current, node));
        } else {
          return;
        }
        handled(event);
        return;
      }
      // A row.
      if (event.key === 'Enter' || event.key === ' ') {
        liftRow(stop);
        handled(event);
        return;
      }
      if (
        (event.key === 's' || event.key === 'S') &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey
      ) {
        const face = latest.current.facesById.get(stop.getAttribute(ATTR.row));
        if (face) {
          toggleFavorite(face, stop);
        }
        handled(event);
        return;
      }
      if (event.key === 'ArrowLeft') {
        const heading = stops.find(
          (candidate) =>
            candidate.hasAttribute(ATTR.set) &&
            candidate.getAttribute(ATTR.set) === stop.getAttribute(ATTR.in),
        );
        heading?.focus();
        handled(event);
        return;
      }
      if (event.key === 'ArrowRight') {
        // Nothing to open on a row; swallowed so the selection's arrow
        // traversal never moves while the panel has focus.
        handled(event);
        return;
      }
      if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
        // The keyboard opener on a focused row, at the row's rect: the
        // tree-row parity gesture. The primitive restores the row's focus
        // on keyboard dismissal.
        const rect = stop.getBoundingClientRect();
        if (openRowMenu(stop, rect.left, rect.bottom)) {
          handled(event);
        }
      }
    };
    // Roving tabindex (the tree-row pattern): focusing a stop (arrows,
    // mouse, script) re-seats the panel's one Tab stop on it.
    const onFocusIn = (event) => {
      const mount = mountElement();
      if (!mount || !(event.target instanceof Element) || !mount.contains(event.target)) {
        return;
      }
      const stop = event.target.closest(STOP_SELECTOR);
      if (stop) {
        allStops().forEach((candidate) => {
          candidate.tabIndex = candidate === stop ? 0 : -1;
        });
      }
    };
    document.addEventListener('keydown', onKeyDown, true);
    document.addEventListener('focusin', onFocusIn);
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      document.removeEventListener('focusin', onFocusIn);
    };
  }, []);

  // After every render: exactly one stop is the Tab stop (the focused
  // one, else the seated one if still drawn, else the first), and a
  // pending refocus lands on its key or its old index.
  useLayoutEffect(() => {
    const stops = allStops();
    if (!stops.length) {
      return;
    }
    const active = document.activeElement;
    let seat = stops.find((stop) => stop.tabIndex === 0);
    if (active instanceof Element && stops.includes(active)) {
      seat = active;
    }
    seat = seat ?? stops[0];
    stops.forEach((stop) => {
      stop.tabIndex = stop === seat ? 0 : -1;
    });
    if (pendingFocus.current) {
      const { key, index } = pendingFocus.current;
      pendingFocus.current = null;
      const target =
        stops.find((stop) => stopKey(stop) === key) ??
        stops[Math.min(index, stops.length - 1)];
      target?.focus();
    }
  });

  // The search's answer, announced.
  useEffect(() => {
    if (hits === null) {
      return;
    }
    Drupal.announce?.(
      Drupal.formatPlural(
        hits.length,
        '1 block matches.',
        '@count blocks match.',
      ),
    );
  }, [hits]);

  const rows = (items, setId, depth, homeOf = null) =>
    items.map((face) => (
      <Row
        key={rowKey(setId, face.id)}
        app={app}
        face={face}
        setId={setId}
        depth={depth}
        home={homeOf ? homeOf(face) : null}
        favorite={isFavorite(face, marks)}
        defaultIcon={defaultIcon}
        onStar={toggleFavorite}
        onPeek={armPeek}
        onPeekEnd={endPeek}
      />
    ));

  const kindLabelOf = (face) => {
    if (face.kind === KIND_BASIC) {
      return words.basics;
    }
    return face.kind === KIND_BLUEPRINT ? Drupal.t('Blueprint') : face.kind;
  };

  const setBody = (set) => {
    if (set.children) {
      return set.children.map((kind) => {
        const node = nodes.get(kind.id);
        const collapsed = isCollapsed(folds, node);
        return (
          <Fragment key={kind.id}>
            <Heading
              set={kind}
              depth={1}
              collapsed={collapsed}
              onToggle={() => applyFolds(toggle(folds, node))}
            />
            {!collapsed && rows(kind.items, kind.id, 1)}
          </Fragment>
        );
      });
    }
    if (set.items.length) {
      // Under Favorites a row names where it lives, unless it is one of
      // the basics: the mock's reading, since a favorite comes from
      // anywhere in All.
      return rows(
        set.items,
        set.id,
        0,
        set.id === FAVORITES_ID
          ? (face) => (face.kind === KIND_BASIC ? null : kindLabelOf(face))
          : null,
      );
    }
    if (set.id === FAVORITES_ID) {
      return (
        <p className="daedalus-layout-add-panel__empty" {...{ [ATTR.empty]: 'favorites' }}>
          {Drupal.t('No favorites yet. Star any block to keep it here.')}
        </p>
      );
    }
    return null;
  };

  return (
    <Fragment>
      <div className="daedalus-layout-add-panel__search-row">
        <input
          ref={searchRef}
          type="search"
          className="daedalus-ui-input daedalus-layout-add-panel__search"
          placeholder={Drupal.t('Find a block')}
          aria-label={Drupal.t('Find a block')}
          title={Drupal.t('Type two or more characters to search every block.')}
          value={query}
          autoComplete="off"
          {...{ [ATTR.search]: '' }}
          onInput={(event) => setQuery(event.target.value)}
        />
      </div>
      {armed && (
        <p className="daedalus-layout-add-panel__armed">
          {armed.startsWith('entity:')
            ? Drupal.t('Click a block to place it on the empty page. Esc cancels.')
            : Drupal.t('Click a block to place it at the selection. Esc cancels.')}
        </p>
      )}
      <div
        className="daedalus-ui-panel__body daedalus-ui-panel-list daedalus-layout-add-panel__list"
        {...{ [ATTR.list]: '' }}
        onScroll={endPeek}
        onContextMenu={(event) => {
          if (!(event.target instanceof Element)) {
            return;
          }
          const row = event.target.closest(ROW_SELECTOR);
          // preventDefault only when a menu actually opened (the
          // primitive's no-items contract).
          if (row && openRowMenu(row, event.clientX, event.clientY)) {
            handled(event);
          }
        }}
      >
        {hits === null ? (
          sets.map((set) => {
            const node = nodes.get(set.id);
            const collapsed = isCollapsed(folds, node);
            return (
              <Fragment key={set.id}>
                <Heading
                  set={set}
                  depth={0}
                  collapsed={collapsed}
                  onToggle={() => applyFolds(toggle(folds, node))}
                />
                {!collapsed && setBody(set)}
              </Fragment>
            );
          })
        ) : hits.length ? (
          <Fragment>
            <p className="daedalus-layout-add-panel__count-line" {...{ [ATTR.count]: hits.length }}>
              {Drupal.formatPlural(hits.length, '1 block matches', '@count blocks match')}
            </p>
            {rows(
              hits.map((hit) => hit.face),
              SEARCH_ID,
              0,
              (face) => kindLabelOf(face),
            )}
          </Fragment>
        ) : (
          <p className="daedalus-layout-add-panel__empty" {...{ [ATTR.empty]: 'search' }}>
            {Drupal.t('Nothing matches "@query".', { '@query': query.trim() })}
          </p>
        )}
      </div>
    </Fragment>
  );
};
