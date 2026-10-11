/**
 * @file
 * The insert session: the drag sources, the Insert verb, click-to-place,
 * the armed keyboard highlight and the empty canvas.
 *
 * The Add panel (id 'place_block') is a plain sidebar panel, not a tool:
 * dragging never required a mode, so there is no cursor to claim and no
 * tool switch to make. Its rows are always-armed drag sources feeding
 * the one insertion caret, and the Insert verb arms click-to-place
 * against a pending anchor while the Selector stays current; on an empty
 * canvas the panel opening arms the root itself ("The empty canvas"
 * below). The rows are the component's (components/add-panel.js) and are
 * read here through the DOM contract (panel-dom.js): the session never
 * holds a row, since a row is in the DOM once per set it belongs to and
 * comes and goes with the folds.
 *
 * What survived the swap unchanged as behaviour: the three drag sources
 * (`new_block`, `new_section`, `new_blueprint_section`) feeding the one
 * caret, the Insert verb's pending anchor with click-to-place and its
 * arrow-key highlight, the armed root on an empty canvas (the blank-page
 * button, the `i` hotkey and the top-bar button stay one door), and
 * acceptsBlueprintTarget (a band places at top level only).
 *
 * Every door composes a row's drop through one composer (model/drop.js):
 * the blueprint source, click-to-place at the pending anchor, and the
 * peek (peekRequest), which is the drop at the end of the page, dry run,
 * under the same gates a click asks.
 */

import {
  ATTR,
  ROW_SELECTOR,
  mountElement,
  panelElement,
  rowSource,
  visibleRows,
} from './panel-dom.js';
import {
  anchorLocation,
  blueprintOperations,
  composeDrop,
  peekRequest as composePeek,
  resolveTarget,
  rootEndTarget,
} from './model/drop.js';

/**
 * The class a row wears while it is the armed keyboard cursor.
 */
export const HIGHLIGHT_CLASS = 'daedalus-layout-add-panel__row--highlighted';

/**
 * The class the document wears while an anchor is armed (the affordance
 * hook: rows read as click-to-place targets).
 */
export const PENDING_CLASS = 'daedalus-layout-insert-pending';

/**
 * Creates the session: registers the sources and the verb, installs the
 * delegated click and the empty-canvas arming.
 *
 * @param {object} app
 *   The app the DaedalusUiQueue hands a bundle.
 *
 * @return {{liftRow: Function, placeRow: Function, peekRequest: Function, pendingAnchor: Function}}
 *   `liftRow(row)` lifts a row into the caret walk; `placeRow(row,
 *   {linked})` places it at the pending anchor by click (a blueprint row
 *   as a copy, or linked); `peekRequest(row)` answers the
 *   peek door's request for a row (its drop at the end of the page) or
 *   null where that drop is refused; `pendingAnchor()` answers the armed
 *   key or null.
 */
export const createInsertSession = (app) => {
  // The drop's composer (model/drop.js) over the app's standard builders
  // and uuid generator: click-to-place, the peek and the blueprint source
  // all compose through it, so every door sends the one batch.
  const dropDeps = {
    get builders() {
      return app.drag.builders;
    },
    generateUuid: app.generateUuid,
  };

  // Whether a blueprint row may land at a target: the ONE placement
  // predicate every door answers. It is the materials roster's
  // applicability twin (MaterialsRoster::applicable(): a band, a blueprint
  // whose root wears a root-only variant, places at top level only) over
  // the depth of the gap the target's location lands in. The source below
  // declares it as its acceptsTarget, so the pointer drag and the keyboard
  // lift never offer a refused gap (the caret is absent, not red), and
  // click-to-place consults the same spec before it places. Legality
  // itself stays the spine's: a raw placement at a refused depth is
  // refused there, with the axis named (VARIANT_ROOT_ONLY).
  const acceptsBlueprintTarget = (target, data) => {
    const Materials = Drupal.Daedalus.Materials;
    const record = Materials.get('blueprint', data.blueprintId);
    if (!record) {
      return false;
    }
    const topLevel =
      !target.newSection && app.drag.isRootFlowLocation(target.location);
    // A placement is always a container (a blueprint's roots are
    // containers), so the verdict is the container kind's.
    return Materials.applicable(record, 'kind:container', topLevel);
  };

  /**
   * The placed element's uuid: the last uuid-carrying op in a batch.
   */
  const placedUuidOf = (operations) =>
    [...operations].reverse().find((operation) => operation.uuid)?.uuid;

  /**
   * Whether a key is a slot door: a slot block (the lean node carries the
   * slot it declares) whose published insert verdict is this document's
   * own. On a locked page the template's slot blocks are the doors the
   * page fills (the spine stamps insert on exactly those); on the
   * template's own canvas a slot block is refused insert
   * (SLOT_OWN_DOCUMENT) and is an ordinary block to place beside. The
   * node fact and the verdict are both the server's; nothing here reads a
   * plugin id.
   */
  const isSlotDoor = (key) => {
    const [kind, uuid] = key.split(':');
    if (kind !== 'block') {
      return false;
    }
    const { Selection, Blueprint } = Drupal.Daedalus;
    const node = Blueprint.componentForUuid(Blueprint.getBlueprint(), uuid);
    return !!node?.slot && Selection.capable(key, 'insert');
  };

  // The armed anchor: a selectable key, or null. Lives here (not in the
  // app) because the verb and the rows are both this panel's; promote it
  // only when another consumer exists.
  let pendingAnchor = null;

  // The key a committed placement is about to select (the op queue's select
  // option lands focus on what was placed). The armed session's disarm-on-
  // selection-change must not read that selection as the user moving on:
  // the handler consumes a matching key (one-shot) and stays armed.
  let expectedPlacedKey = null;

  const expectPlacement = (key) => {
    expectedPlacedKey = key || null;
  };

  // The keyboard cursor over the rows while armed, or null.
  let highlighted = null;

  // Insert-session listeners, live only while armed (see armSession).
  let panelObserver = null;
  let unsubscribeSelection = null;

  // The Add panel's drag sources. A row drag is an ordinary caret drag:
  // the insertion caret shows the gap, the drop becomes a blueprint
  // operation batch, and the response's subtree swap lands the rendered
  // block. Sample population and the PlaceBlockEvent bridge ride the
  // add_element op server-side.
  app.drag.registerSource('new_block', {
    targets: 'block',
    operations: (target, data) => {
      const operations = app.drag.builders.newBlock(target, data);
      // The builder names the block client-side; keep the UUID on the drag
      // data so onComplete can advance the insert anchor to it.
      data.placedUuid = placedUuidOf(operations);
      expectPlacement(data.placedUuid && `block:${data.placedUuid}`);
      return operations;
    },
    onComplete: (response, target, data) => {
      // While the insert session is armed, a completed placement advances
      // the pending anchor to the placed block, so consecutive placements
      // (keyboard lifts and drags alike) land in reading order.
      if (pendingAnchor && data.placedUuid) {
        setPendingAnchor(`block:${data.placedUuid}`);
      }
    },
  });

  app.drag.registerSource('new_section', {
    targets: 'section',
    operations: (target, data) => {
      // A brand-new section is born as a container: arrangement is
      // styling (the inspector's Layout section), so there is no layout to
      // choose and no ceremony at the drop: one plain add_section.
      const operations = app.drag.builders.newSection(target);
      // The batch names the section client-side (always operations[0]);
      // keep the UUID on the drag data so onComplete can address the
      // rendered section.
      data.sectionUuid = operations[0].uuid;
      expectPlacement(`container:${data.sectionUuid}`);
      return operations;
    },
    onComplete: (response, target, data) => {
      if (pendingAnchor && data.sectionUuid) {
        setPendingAnchor(`container:${data.sectionUuid}`);
      }
    },
  });

  // Blueprints ride SECTION gaps: the same insertion caret a plain section
  // uses, so one drag places the blueprint as a top-level band or nested
  // into any section the predicate offers (the location passes straight
  // into place_blueprint's placement grammar). The blueprint defines its
  // own structure; a plain section is born a container.
  app.drag.registerSource('new_blueprint_section', {
    targets: 'section',
    acceptsTarget: acceptsBlueprintTarget,
    operations: (target, data) => {
      const operations = blueprintOperations(app.generateUuid, target, data);
      data.sectionUuid = placedUuidOf(operations);
      expectPlacement(`container:${data.sectionUuid}`);
      return operations;
    },
    onComplete: (response, target, data) => {
      if (pendingAnchor && data.sectionUuid) {
        setPendingAnchor(`container:${data.sectionUuid}`);
      }
    },
  });

  // A LINKED blueprint rides BLOCK gaps: one node showing the saved
  // blueprint by reference (place_blueprint with mode linked), so it lands
  // where a block lands, at the block caret. Whether the blueprint fits
  // the page is the server's judgement at the op door; the client only
  // asks that the record exists. Armed by the row menu's "Place linked"
  // (click-to-place through placeRow), never by the row's own drag, which
  // stays the copy.
  app.drag.registerSource('new_blueprint_block', {
    targets: 'block',
    acceptsTarget: (target, data) =>
      !!Drupal.Daedalus.Materials.get('blueprint', data.blueprintId),
    operations: (target, data) => {
      const operations = blueprintOperations(app.generateUuid, target, data);
      data.placedUuid = placedUuidOf(operations);
      expectPlacement(`block:${data.placedUuid}`);
      return operations;
    },
    onComplete: (response, target, data) => {
      if (pendingAnchor && data.placedUuid) {
        setPendingAnchor(`block:${data.placedUuid}`);
      }
    },
  });

  // --- The Insert verb: click-to-place through the Add panel. ---
  //
  // The keyboard/precision entrance to placement: select an element,
  // run Insert, and the Add panel opens with the selection armed as the
  // pending insert anchor; clicking a row places relative to it (after a
  // block, inside a region/section) through the same op builders as
  // caret drops. No tool changes hands: the panel opening IS the whole
  // mode shift. Opening the panel from its toggle button arms nothing
  // while the document has elements to anchor on; without a pending
  // anchor row clicks do nothing and drag remains the gesture. The one
  // exception is the empty canvas, below: nothing is there to select, so
  // the root is the anchor.

  const setHighlighted = (row) => {
    highlighted?.classList.remove(HIGHLIGHT_CLASS);
    highlighted = row;
    if (row) {
      row.classList.add(HIGHLIGHT_CLASS);
      row.scrollIntoView({ block: 'nearest' });
    }
  };

  const setPendingAnchor = (key) => {
    pendingAnchor = key;
    // The affordance hook: while armed, rows read as clickable.
    document.documentElement.classList.toggle(PENDING_CLASS, !!key);
    if (key) {
      armSession();
    } else {
      setHighlighted(null);
      endSession();
    }
  };

  const disarm = () => {
    if (pendingAnchor) {
      setPendingAnchor(null);
    }
  };

  /**
   * Starts the insert session's listeners. Idempotent: advancing the anchor
   * after a placement re-enters here with the session already live.
   *
   * The session's keyboard bindings register in the SELECTION context only
   * while armed: an armed anchor implies a live selection (arming rides the
   * Insert verb, and any selection change disarms), and dynamic
   * registration means the Add panel never shadows selection or tool keys
   * when the session is over: Escape falls back to the Selector's
   * clear-selection binding the moment the session ends.
   */
  const armSession = () => {
    if (panelObserver) {
      return;
    }
    // A fresh session starts with no placement in flight; an expectation
    // left over from an unarmed drag must not shield a later user click.
    expectedPlacedKey = null;
    insertHotkeys.forEach((binding) =>
      app.Hotkeys.register({
        ...binding,
        when: 'selection',
        section: Drupal.t('Actions'),
        remappable: false,
      }),
    );
    // The panel closing (its toggle button, a same-slot panel evicting it,
    // leaving Edit Mode) ends click-to-place: the pending anchor is a
    // one-panel-session intent, not durable state.
    panelObserver = new MutationObserver(() => {
      const panel = panelElement();
      if (!panel || panel.classList.contains('daedalus-ui-hidden')) {
        disarm();
      }
    });
    panelObserver.observe(panelElement(), {
      attributes: true,
      attributeFilter: ['class'],
    });
    // A new selection is a new intent: the user moved on from the anchor.
    // Except the one a placement itself commits (focus follows the placed
    // element): that is the session advancing, and the anchor advance in
    // onComplete keeps anchor and selection on the same key.
    unsubscribeSelection = app.Selection.onChange((key) => {
      if (key && key === expectedPlacedKey) {
        expectedPlacedKey = null;
        return;
      }
      disarm();
    });
  };

  const endSession = () => {
    if (!panelObserver) {
      return;
    }
    panelObserver.disconnect();
    panelObserver = null;
    unsubscribeSelection();
    unsubscribeSelection = null;
    insertHotkeys.forEach((binding) => app.Hotkeys.unregister(binding.id));
  };

  app.verbs.register({
    id: 'daedalus_layout.insert',
    label: Drupal.t('Block'),
    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M224,128a8,8,0,0,1-8,8H136v80a8,8,0,0,1-16,0V136H40a8,8,0,0,1,0-16h80V40a8,8,0,0,1,16,0v80h80A8,8,0,0,1,224,128Z"></path></svg>',
    kinds: ['block', 'container'],
    // Insert asks the destination: after a block, the container it sits
    // in; a container takes the placement inside itself, and so does a
    // slot door (the page's slot section is its interior).
    capability: 'insert',
    of: (key, kind) =>
      kind === 'block' && !isSlotDoor(key) ? 'parent' : 'element',
    hotkeys: 'i',
    weight: 5,
    run: (key) => {
      const panel = panelElement();
      if (!panel) {
        return;
      }
      // Arm first: openSidebar is a no-op when the panel is already open,
      // and the observer only reacts to class MUTATIONS, so arming before
      // the open never races a hidden panel. A rejected open (the slot's
      // current panel refused to close) rolls the arming back.
      setPendingAnchor(key);
      Drupal.Daedalus.SidebarManager.openSidebar('place_block').catch(
        () => disarm(),
      );
    },
  });

  /**
   * The uuid of the container a block key's element sits in, read from
   * the DOM at any nesting depth, for the anchor's location grammar
   * (model/drop.js anchorLocation: a section placed at a block anchor
   * lands after the block's own container).
   */
  const enclosingContainerOf = (key) =>
    Drupal.Daedalus.Selection.getElement(key)
      ?.closest('[data-selectable^="container:"]')
      ?.getAttribute('data-selectable')
      .split(':')[1] ?? null;

  /**
   * A row's resolved target at a location, or null where the drop is
   * refused.
   *
   * The same target legality every placement door asks: the one gate
   * first (insert of the container the location inserts into, as the
   * drag controller asks of every resolved gap), then the source's own
   * legality (its acceptsTarget, as the lift asks for each enumerated
   * gap). A refused target places nothing and peeks nothing: the caret
   * would never have offered it, so no request leaves the client.
   */
  const legalTarget = (rowSpec, location) => {
    const target = resolveTarget(app.drag.isRootFlowLocation, rowSpec, {
      location,
    });
    if (!app.drag.dropAllowed(target)) {
      return null;
    }
    const spec = app.drag.getSource(rowSpec.source);
    if (spec.acceptsTarget && !spec.acceptsTarget(target, rowSpec.data)) {
      return null;
    }
    return target;
  };

  /**
   * Lifts a row into the keyboard caret walk.
   *
   * The lift arms the same source spec and builders as a row drag, so
   * anchor advance rides along and the spec's acceptsTarget prunes the
   * gaps it walks. With a pending Insert anchor the caret starts at the
   * anchor's gap; without one (the anchor-less keyboard entry) `Drag.lift`
   * needs no anchor and starts the caret at the FIRST valid gap.
   */
  const liftRow = (row) => {
    const { source, data, placingSection } = rowSource(row);
    const options = {};
    if (pendingAnchor) {
      options.location =
        anchorLocation(
          pendingAnchor,
          placingSection,
          enclosingContainerOf,
          isSlotDoor,
        ) ?? undefined;
      options.near = Drupal.Daedalus.Selection.getElement(pendingAnchor);
    }
    Drupal.Daedalus.Drag.lift(source, data, options);
  };

  /**
   * The peek door's request for a row: its drop at the end of the page.
   *
   * The batch the same composer sends for a click-to-place of the row
   * at the root anchor (model/drop.js), under the same gates, with `show`
   * the key the batch names for what it places. Null where that drop is
   * refused (a block blueprint's canvas with its one root placed, a band
   * where the root flow is not offered) or off an editable page: nothing
   * to show, so nothing is requested.
   *
   * @param {Element} row
   *   A row element.
   *
   * @return {?{operations: object[], show: string}}
   */
  const peekRequest = (row) => {
    const root = Drupal.Daedalus.Blueprint.getRoot();
    if (!root) {
      return null;
    }
    const rowSpec = rowSource(row);
    const target = legalTarget(rowSpec, rootEndTarget(root.uuid).location);
    return target ? composePeek(dropDeps, rowSpec, target) : null;
  };

  /**
   * Places a clicked row relative to the pending anchor.
   *
   * The same builders and parity hooks as the drag path (sample
   * population included), and the same target legality (legalTarget). On
   * success the anchor advances to the placed element, so consecutive
   * clicks land in reading order instead of stacking in reverse at the
   * original anchor. A blueprint row places a copy by default and a
   * linked node (the blueprint shown by reference) with `linked`, the
   * row menu's "Place linked".
   */
  const placeRow = async (row, { linked = false } = {}) => {
    const context = Drupal.Daedalus.Blueprint.rootContext();
    if (!context || !pendingAnchor) {
      return;
    }
    const rowSpec = rowSource(row, { linked });
    const anchorKey = pendingAnchor;
    const location = anchorLocation(
      anchorKey,
      rowSpec.placingSection,
      enclosingContainerOf,
      isSlotDoor,
    );
    if (!location) {
      return;
    }
    const target = legalTarget(rowSpec, location);
    if (!target) {
      return;
    }
    const { operations, placedKey } = composeDrop(dropDeps, rowSpec, target);
    try {
      // select lands focus on the placed element; the expectation keeps the
      // session's disarm-on-selection-change from reading it as a new
      // intent. The placement lands a plain selection: editing is the
      // user's next, explicit gesture (Enter, double-click, the Edit chip).
      expectPlacement(placedKey);
      await Drupal.Daedalus.Blueprint.mutate({
        ...context,
        operations,
        select: placedKey,
      });
      setPendingAnchor(placedKey);
    } catch (error) {
      expectPlacement(null);
      // A failed placement has nothing on the page to flash; the anchor
      // element is where the placement was aimed.
      Drupal.Daedalus.Blueprint.reportFailure(error, anchorKey);
    }
  };

  // Delegated so it survives the rows' re-renders; inert unless a
  // pending anchor is armed. The star and the row's own buttons stop
  // their clicks before they get here, so a star never places.
  document.addEventListener('click', (event) => {
    if (!pendingAnchor || !(event.target instanceof Element)) {
      return;
    }
    const mount = mountElement();
    if (!mount || !mount.contains(event.target)) {
      return;
    }
    if (event.target.closest(`[${ATTR.star}]`)) {
      return;
    }
    const row = event.target.closest(ROW_SELECTOR);
    if (!row) {
      return;
    }
    event.preventDefault();
    placeRow(row);
  });

  // --- Keyboard panel navigation while armed. ---
  //
  // Arrow keys walk a highlight through the drawn rows, Enter places
  // the highlighted one, Escape ends the session and closes the panel.
  // The bindings live in the SELECTION context of the one hotkey
  // dispatcher and exist only while armed (armSession/endSession), so
  // an unarmed selection keeps every key's normal meaning; each action
  // still self-checks the armed state and returns false as a belt-and-
  // braces guard. Enter is the one key the session shares with a live
  // selection binding (edit.enter): with nothing highlighted the place
  // action PASSES the stroke on instead, so Enter right after a
  // click-to-place opens the placed block's editor. While focus is INSIDE
  // the panel the component's own keyboard surface owns the keys at
  // document capture and these bindings never see them.

  // The highlight is re-resolved against the drawn rows on every
  // keypress, so a re-render (stale element, indexOf -1) restarts
  // cleanly at an end.
  const moveHighlight = (delta) => {
    if (!pendingAnchor) {
      return false;
    }
    const rows = visibleRows();
    if (!rows.length) {
      return false;
    }
    const index = rows.indexOf(highlighted);
    const next =
      index === -1
        ? rows[delta > 0 ? 0 : rows.length - 1]
        : rows[(index + delta + rows.length) % rows.length];
    setHighlighted(next);
  };

  // Labeled so the settings tray documents them (locked) while the insert
  // session is armed: they only exist in the registry between arm/disarm.
  const insertHotkeys = [
    {
      id: 'daedalus_layout.insert.next',
      key: 'arrowdown',
      label: Drupal.t('Insert: highlight next'),
      action: () => moveHighlight(1),
    },
    {
      id: 'daedalus_layout.insert.next2',
      key: 'arrowright',
      label: Drupal.t('Insert: highlight next'),
      action: () => moveHighlight(1),
    },
    {
      id: 'daedalus_layout.insert.previous',
      key: 'arrowup',
      label: Drupal.t('Insert: highlight previous'),
      action: () => moveHighlight(-1),
    },
    {
      id: 'daedalus_layout.insert.previous2',
      key: 'arrowleft',
      label: Drupal.t('Insert: highlight previous'),
      action: () => moveHighlight(-1),
    },
    {
      id: 'daedalus_layout.insert.place',
      key: 'enter',
      label: Drupal.t('Insert: place the highlighted block'),
      action: () => {
        if (!pendingAnchor || !highlighted) {
          // Not the session's stroke: with no row highlighted, Enter
          // belongs to the next live binding on the key, the selection's
          // edit.enter, which opens the selected (just placed) block's
          // editor. A pass, not a false: false would stop the key here and
          // shadow edit.enter for as long as the session is armed.
          return app.Hotkeys.PASS;
        }
        // Enter LIFTS the highlighted row: panel placement is the keyboard
        // move entered from the other end. The insertion caret starts at
        // the pending anchor's gap, arrows walk it through every
        // valid gap at any depth, Enter/Space drops, Escape returns to the
        // armed panel. A mouse click still places at the anchor directly.
        liftRow(highlighted);
      },
    },
    {
      id: 'daedalus_layout.insert.done',
      key: 'escape',
      label: Drupal.t('Insert: done'),
      action: () => {
        if (!pendingAnchor) {
          return false;
        }
        // Ending the session closes the panel the verb opened; the panel
        // observer's disarm on that close is a no-op because the session
        // already ended here.
        disarm();
        Drupal.Daedalus.SidebarManager.closeSidebar('place_block');
      },
    },
  ];

  // --- The empty canvas: the root is the insert anchor. ---
  //
  // The blank-page affordance (LayoutBuilderPlus::buildBlankPage()) promises
  // that the Blocks key works, and a keyboard user cannot drag. With no
  // element to select there is no Insert verb to run, so while the document
  // is empty an OPEN Add panel is armed against the root entity: the
  // blank-page button, the panel's hotkey and the top-bar button are one
  // road (SidebarManager.openSidebar), so one observer of the panel's
  // visibility serves all three, and a panel the sidebar cookie rendered
  // open on landing arms at boot or when the mirror boots (the first
  // blueprint change). The gate is the root's insert capability through
  // the one client gate, as the verb's gate asks it of a selected element
  // and the drop gate of a gap's container; a block blueprint with no root
  // grants it and refuses it once the root is placed (BlueprintScopePolicy).
  // The emptiness read is the mirror's: its lean tree lists the root flow's
  // containers, and no container is an empty document. An anchor already
  // armed (the Insert verb arms before it opens the panel; a placement
  // advances the anchor) is left alone.
  const rootAnchorKey = () => {
    const root = Drupal.Daedalus.Blueprint.getRoot();
    return root ? `entity:${root.uuid}` : null;
  };

  const documentIsEmpty = () => {
    const blueprint = Drupal.Daedalus.Blueprint.getBlueprint();
    return !!blueprint && (blueprint.layout ?? []).length === 0;
  };

  const armRootOnEmptyCanvas = () => {
    const panel = panelElement();
    if (
      pendingAnchor ||
      !panel ||
      panel.classList.contains('daedalus-ui-hidden') ||
      !documentIsEmpty()
    ) {
      return;
    }
    const key = rootAnchorKey();
    if (key && Drupal.Daedalus.Selection.capable(key, 'insert')) {
      setPendingAnchor(key);
    }
  };

  // The panel renders present-but-hidden with the page (the Add panel
  // mounts wherever insert is allowed somewhere on the host), so the
  // element is here to observe; a page without it has no panel to arm.
  const observedPanel = panelElement();
  if (observedPanel) {
    let wasHidden = observedPanel.classList.contains('daedalus-ui-hidden');
    new MutationObserver(() => {
      const hidden = observedPanel.classList.contains('daedalus-ui-hidden');
      if (wasHidden && !hidden) {
        armRootOnEmptyCanvas();
      }
      wasHidden = hidden;
    }).observe(observedPanel, {
      attributes: true,
      attributeFilter: ['class'],
    });
    Drupal.Daedalus.Blueprint.onBlueprintChange(armRootOnEmptyCanvas);
    armRootOnEmptyCanvas();
  }

  return {
    liftRow,
    placeRow,
    peekRequest,
    pendingAnchor: () => pendingAnchor,
  };
};
