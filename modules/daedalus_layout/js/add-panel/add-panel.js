window.DaedalusUiQueue = window.DaedalusUiQueue || [];
window.DaedalusUiQueue.push((app) => {
  // The Add panel (id 'place_block') is a plain sidebar panel, not a tool:
  // dragging never required a mode, so there is no cursor to claim and no
  // tool switch to make. Its items are always-armed drag sources
  // feeding the one insertion caret, and the Insert verb arms click-to-place
  // against a pending anchor while the Selector stays current.

  // The drag data a panel item means: a stencil item carries its stencil
  // ID (data-stencil-id, stamped server-side) plus its root kind, any other
  // item's element id is the block plugin id. draggable-new-blocks.js
  // mirrors this shape for native drags; lifts and clicks read it here.
  const itemDragData = (item) =>
    item.dataset.stencilId
      ? { stencilId: item.dataset.stencilId }
      : { pluginId: item.id };

  // A stencil drop routes through the instantiate_stencil operation — the
  // stamping spine (daedalus_blueprint_stencil) — instead of add_element: the
  // server expands the stencil's tree through the same batch grammar a
  // panel drop uses, so restrictions, sample population, and styles all
  // apply server-side. The client-minted uuid names the ROOT element (the
  // grammar's optimistic seam). Section-rooted stencils ride SECTION gaps —
  // the location IS add_section's one placement grammar, so the same
  // stencil lands as a top-level band or nested into any region with no
  // client-side wrapping; which gaps are OFFERED is acceptsStencilTarget's
  // (a band, top level only). Block-rooted stencils ride block gaps and
  // keep block-drop parity: a between-sections drop wraps in a new section
  // exactly like newBlock does.
  const stencilOperations = (target, data) => {
    const operation = {
      op: 'instantiate_stencil',
      stencil: data.stencilId,
      uuid: app.generateUuid(),
      location: target.location,
    };
    if (target.newSection) {
      const sectionUuid = app.generateUuid();
      return [
        { op: 'add_section', uuid: sectionUuid, location: target.location },
        {
          ...operation,
          location: { anchor: sectionUuid, placement: 'inside' },
        },
      ];
    }
    return [operation];
  };

  // One entry for everything a panel item can place: blocks ride the
  // standard newBlock builder, stencils the instantiate expansion. The
  // LAST uuid-carrying op names the placed element.
  const newBlockOperations = (target, data) =>
    data.stencilId
      ? stencilOperations(target, data)
      : app.drag.builders.newBlock(target, data);

  // Whether a stencil item may land at a target: the ONE placement
  // predicate every door answers. It is the materials roster's
  // applicability twin (MaterialsRoster::applicable(): a band, a stencil
  // whose root wears a root-only variant, places at top level only) over
  // the depth of the gap the target's location lands in. The sources
  // below declare it as their acceptsTarget, so the pointer drag and the
  // keyboard lift never offer a refused gap (the caret is absent, not
  // red), and click-to-place consults the same spec before it places. A
  // stencil wrapped in a new section (a block-rooted one at a root gap)
  // lands inside that section, nested. Legality itself stays the spine's:
  // a raw instantiate at a refused depth is refused there, with the axis
  // named (VARIANT_ROOT_ONLY).
  const acceptsStencilTarget = (target, data) => {
    const Materials = Drupal.Daedalus.Materials;
    const record = Materials.get('stencil', data.stencilId);
    if (!record) {
      return false;
    }
    const topLevel =
      !target.newSection && app.drag.isRootFlowLocation(target.location);
    return Materials.applicable(
      record,
      record.root_kind === 'section' ? 'kind:container' : 'kind:block',
      topLevel,
    );
  };

  /**
   * The placed element's uuid: the last uuid-carrying op in a batch.
   */
  const placedUuidOf = (operations) =>
    [...operations].reverse().find((operation) => operation.uuid)?.uuid;

  // The Add panel's drag sources. A panel item drag is an ordinary caret
  // drag: the insertion caret shows the gap, the drop becomes a blueprint
  // operation batch, and the response's subtree swap lands the rendered
  // block. Sample population and the PlaceBlockEvent bridge ride the
  // add_element op server-side.
  app.drag.registerSource('new_block', {
    targets: 'block',
    // A fresh block with sample content is the one landing whose next act
    // is always typing: the drop opens the placed block's editor (the op
    // queue's edit intent beside its select).
    edit: true,
    // A plain block lands anywhere a block gap is; a block-rooted stencil
    // item answers the stencil predicate.
    acceptsTarget: (target, data) =>
      !data.stencilId || acceptsStencilTarget(target, data),
    operations: (target, data) => {
      const operations = newBlockOperations(target, data);
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
      // A brand-new section is born as a container — arrangement is
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

  // Section-rooted stencils ride SECTION gaps: the same insertion caret a
  // plain section uses, so one drag places the stencil as a top-level band
  // or nested into any section the predicate offers — the location passes
  // straight into instantiate_stencil's add_section grammar. The stencil
  // defines its own layout; a plain section is born a container.
  app.drag.registerSource('new_stencil_section', {
    targets: 'section',
    acceptsTarget: acceptsStencilTarget,
    operations: (target, data) => {
      const operations = stencilOperations(target, data);
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

  // --- The Insert verb: click-to-place through the Add panel. ---
  //
  // The keyboard/precision entrance to placement: select an element,
  // run Insert, and the Add panel opens with the selection armed as the
  // pending insert anchor; clicking a panel item places relative to it
  // (after a block, inside a region/section) through the same op builders
  // as caret drops. No tool changes hands: the panel opening IS the whole
  // mode shift. Opening the panel from its toggle button arms
  // nothing; without a pending anchor panel clicks do nothing and drag
  // remains the gesture.

  // The armed anchor: a selectable key, or null. Lives here (not in the
  // app) because the verb and the item list are both this panel's; promote it
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

  // The keyboard cursor over the panel items while armed, or null.
  let highlighted = null;

  // Insert-session listeners, live only while armed (see armSession).
  let panelObserver = null;
  let unsubscribeSelection = null;

  const panelElement = () => document.getElementById('place_block');

  const setHighlighted = (item) => {
    highlighted?.classList.remove('insert-highlight');
    highlighted = item;
    if (item) {
      item.classList.add('insert-highlight');
      item.scrollIntoView({ block: 'nearest' });
    }
  };

  const setPendingAnchor = (key) => {
    pendingAnchor = key;
    // The affordance hook: while armed, panel items read as clickable.
    document.documentElement.classList.toggle('daedalus-layout-insert-pending', !!key);
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
   * when the session is over — Escape falls back to the Selector's
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
    // The panel closing — its toggle button, a same-slot panel evicting it,
    // leaving Edit Mode — ends click-to-place: the pending anchor is a
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
   * The server location a panel click means, from the pending anchor.
   *
   * Blocks place after a block anchor and inside a container anchor.
   * Sections place after a container anchor (a sibling at that anchor's
   * depth) and after the containing container (resolved from the DOM, at
   * any nesting depth) for block anchors.
   *
   * @param {string} key
   *   The pending anchor's selectable key (kind block or container).
   * @param {boolean} placingSection
   *   Whether the clicked panel item is the section item.
   *
   * @return {?Object}
   *   A placement-grammar location, or null when the anchor cannot resolve
   *   one (e.g. its element left the page).
   */
  const anchorLocation = (key, placingSection) => {
    const [kind, uuid] = key.split(':');
    if (!placingSection) {
      if (kind === 'block') {
        return { anchor: uuid, placement: 'after' };
      }
      if (kind === 'container') {
        return { anchor: uuid, placement: 'inside' };
      }
      return null;
    }
    if (kind === 'container') {
      return { anchor: uuid, placement: 'after' };
    }
    if (kind === 'block') {
      const section = Drupal.Daedalus.Selection.getElement(key)?.closest(
        '[data-selectable^="container:"]',
      );
      const sectionUuid = section
        ?.getAttribute('data-selectable')
        .split(':')[1];
      return sectionUuid ? { anchor: sectionUuid, placement: 'after' } : null;
    }
    return null;
  };

  /**
   * The drag source a panel item places through, and its drag data.
   *
   * Section-rooted stencil items are draggable-sections carrying a
   * stencil ID: they place through their own source. The plain section
   * item carries no data. One reading for the keyboard lift and
   * click-to-place, so both doors answer the source the item's drag
   * handle would arm.
   *
   * @param {Element} item
   *   The panel item.
   *
   * @return {{source: string, data: Object, placingSection: boolean}}
   */
  const itemSource = (item) => {
    const placingSection = item.classList.contains('draggable-section');
    const data = itemDragData(item);
    let source = 'new_block';
    if (placingSection) {
      source = data.stencilId ? 'new_stencil_section' : 'new_section';
    }
    return {
      source,
      data: placingSection && !data.stencilId ? {} : data,
      placingSection,
    };
  };

  /**
   * Lifts a panel item into the keyboard caret walk.
   *
   * The lift arms the same source spec and builders as a panel drag, so
   * anchor advance rides along and the spec's acceptsTarget prunes the
   * gaps it walks. With a pending Insert anchor the caret starts at the
   * anchor's gap; without one (the anchor-less keyboard entry) `Drag.lift`
   * needs no anchor and starts the caret at the FIRST valid gap.
   */
  const liftItem = (item) => {
    const { source, data, placingSection } = itemSource(item);
    const options = {};
    if (pendingAnchor) {
      options.location =
        anchorLocation(pendingAnchor, placingSection) ?? undefined;
      options.near = Drupal.Daedalus.Selection.getElement(pendingAnchor);
    }
    Drupal.Daedalus.Drag.lift(source, data, options);
  };

  /**
   * Places a clicked panel item relative to the pending anchor.
   *
   * The same builders and parity hooks as the drag path (sample
   * population included), and the same target legality: the target is
   * built from the anchor and the item's source spec is asked whether it
   * accepts it, exactly as the drag controller asks for a resolved gap and
   * the lift for each enumerated one; a refused target places nothing
   * (the caret would never have offered it). On success the anchor
   * advances to the placed element, so consecutive clicks land in reading
   * order instead of stacking in reverse at the original anchor.
   */
  const placeFromPanel = async (item) => {
    const context = Drupal.Daedalus.Blueprint.rootContext();
    if (!context) {
      return;
    }
    const { source, data, placingSection } = itemSource(item);
    const anchorKey = pendingAnchor;
    const location = anchorLocation(anchorKey, placingSection);
    if (!location) {
      return;
    }
    const target = { location };
    const spec = app.drag.getSource(source);
    if (spec.acceptsTarget && !spec.acceptsTarget(target, data)) {
      return;
    }
    let operations;
    if (!placingSection) {
      operations = newBlockOperations(target, data);
    } else if (data.stencilId) {
      operations = stencilOperations(target, data);
    } else {
      operations = app.drag.builders.newSection(target);
    }
    // These builders' batches end with the op carrying the placed
    // element's uuid.
    const placedUuid = operations[operations.length - 1].uuid;
    const placedKey = `${placingSection ? 'container' : 'block'}:${placedUuid}`;
    try {
      // select lands focus on the placed element; the expectation keeps the
      // session's disarm-on-selection-change from reading it as a new
      // intent. A placed BLOCK is new content whose next act is typing, so
      // the request opens its editor too (the op queue's edit intent); a
      // section has no editor.
      expectPlacement(placedKey);
      await Drupal.Daedalus.Blueprint.mutate({
        ...context,
        operations,
        select: placedKey,
        edit: !placingSection,
      });
      setPendingAnchor(placedKey);
    } catch (error) {
      expectPlacement(null);
      // A failed placement has nothing on the page to flash; the anchor
      // element is where the placement was aimed.
      Drupal.Daedalus.Blueprint.reportFailure(error, anchorKey);
    }
  };

  // Delegated so it survives the panel's re-renders; inert unless a
  // pending anchor is armed.
  document.addEventListener('click', (event) => {
    if (!pendingAnchor || !(event.target instanceof Element)) {
      return;
    }
    const item = event.target.closest(
      '.tabbed-content .draggable-block, .tabbed-content .draggable-section',
    );
    if (!item) {
      return;
    }
    event.preventDefault();
    placeFromPanel(item);
  });

  // --- Keyboard panel navigation while armed. ---
  //
  // Arrow keys walk a highlight through the visible panel items, Enter
  // places the highlighted one, Escape ends the session and closes the
  // panel. The bindings live in the SELECTION context of the one hotkey
  // dispatcher and exist only while armed (armSession/endSession),
  // so an unarmed selection keeps every key's normal meaning; each action
  // still self-checks the armed state and returns false as a belt-and-
  // braces guard.

  // The clickable items of the visible tab, in DOM order. The highlight is
  // re-resolved against this list on every keypress, so a panel refresh
  // (stale element, indexOf -1) restarts cleanly at an end.
  const panelItems = () =>
    Array.from(
      document.querySelectorAll(
        '.tabbed-content.active .draggable-block, .tabbed-content.active .draggable-section',
      ),
    ).filter((item) => item.offsetParent !== null);

  const moveHighlight = (delta) => {
    if (!pendingAnchor) {
      return false;
    }
    const items = panelItems();
    if (!items.length) {
      return false;
    }
    const index = items.indexOf(highlighted);
    const next =
      index === -1
        ? items[delta > 0 ? 0 : items.length - 1]
        : items[(index + delta + items.length) % items.length];
    setHighlighted(next);
  };

  // Labeled so the settings tray documents them (locked) while the insert
  // session is armed — they only exist in the registry between arm/disarm.
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
          return false;
        }
        // Enter LIFTS the highlighted item: panel placement is the keyboard
        // move entered from the other end. The insertion caret starts at
        // the pending anchor's gap, arrows walk it through every
        // valid gap at any depth, Enter/Space drops, Escape returns to the
        // armed panel. A mouse click still places at the anchor directly.
        liftItem(highlighted);
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

  // --- Anchor-less panel keyboarding + type-to-filter. ---
  //
  // The keyboard entry that needs no armed Insert anchor: Tab into the Add
  // panel (each tab's filter box is its natural first stop), type to
  // filter, arrows rove FOCUS through the visible items, and Enter (or
  // Space) lifts the focused item into the caret walk; with a pending
  // anchor the caret starts at its gap, without one at the first valid gap
  // (Drag.lift never needed an anchor; this is the panel-side wiring).
  //
  // The panel is its own keyboard surface, like the page-elements tree: a
  // CAPTURE-phase document listener owns keys while the event targets the
  // panel, so the document-level hotkey dispatcher (whose selection-
  // context arrow traversal would otherwise move the SELECTION while the
  // user is arrowing the panel, and whose lift-context Enter would
  // double-fire on the very keypress that lifted) never sees a handled
  // key. While a lift holds, the surface defers entirely: the dispatcher's
  // lift context walks the caret wherever focus sits.

  // Every panel item, visible or not (roving bookkeeping needs them all;
  // keyboard NAVIGATION uses the visible-only panelItems()).
  const allItems = () =>
    Array.from(
      panelElement()?.querySelectorAll(
        '.tabbed-content .draggable-block, .tabbed-content .draggable-section',
      ) ?? [],
    );

  // Roving tabindex (the tree-row pattern): exactly ONE panel item
  // is the panel's Tab stop. Items render with tabindex="-1"; focusing one
  // (arrows, mouse, script) re-seats the stop on it.
  const setRovingStop = (item) => {
    allItems().forEach((candidate) => {
      candidate.tabIndex = candidate === item ? 0 : -1;
    });
  };

  // Seat the initial stop: the panel renders present-but-hidden with the
  // page, so the items exist before the panel first opens.
  setRovingStop(allItems()[0] ?? null);

  document.addEventListener('focusin', (event) => {
    const panel = panelElement();
    if (
      !panel ||
      !(event.target instanceof Element) ||
      !panel.contains(event.target)
    ) {
      return;
    }
    const item = event.target.closest('.draggable-block, .draggable-section');
    if (item) {
      setRovingStop(item);
    } else if (!allItems().some((candidate) => candidate.tabIndex === 0)) {
      // A panel re-render (sidebar delivery) dropped the stop — items
      // arrive with tabindex="-1"; entering the panel restores one.
      setRovingStop(panelItems()[0] ?? allItems()[0] ?? null);
    }
  });

  // The promoted tab's type-to-filter box: a plain label match, mirroring
  // the Other tab's category filter thresholds (filter from 2 characters).
  // Delegated so it survives panel re-renders.
  document.addEventListener('input', (event) => {
    if (
      !(event.target instanceof Element) ||
      !event.target.matches('.daedalus-layout-add-panel-filter')
    ) {
      return;
    }
    const content = event.target.closest('.tabbed-content');
    if (!content) {
      return;
    }
    const query = event.target.value.trim().toLowerCase();
    const items = Array.from(
      content.querySelectorAll('.draggable-block, .draggable-section'),
    );
    items.forEach((item) => {
      const matches =
        query.length < 2 || item.textContent.toLowerCase().includes(query);
      item.style.display = matches ? '' : 'none';
    });
    // A filtered-out roving stop would make the panel untabbable: move it
    // to the first visible item.
    const stop = items.find((item) => item.tabIndex === 0);
    if (stop && stop.style.display === 'none') {
      setRovingStop(panelItems()[0] ?? allItems()[0] ?? null);
    }
    if (query.length >= 2) {
      Drupal.announce?.(
        Drupal.formatPlural(
          items.filter((item) => item.style.display !== 'none').length,
          '1 block is available in the filtered list.',
          '@count blocks are available in the filtered list.',
        ),
      );
    } else {
      Drupal.announce?.(Drupal.t('All available blocks are listed.'));
    }
  });

  const handled = (event) => {
    event.preventDefault();
    event.stopPropagation();
  };

  document.addEventListener(
    'keydown',
    (event) => {
      const panel = panelElement();
      if (
        !panel ||
        !(event.target instanceof Element) ||
        !panel.contains(event.target)
      ) {
        return;
      }
      // A live lift owns the keys wherever focus sits: the dispatcher's lift
      // context walks the caret; the surface stays out of its way.
      if (Drupal.Daedalus.Drag?.isLifted?.()) {
        return;
      }
      if (event.target.matches('input[type="search"]')) {
        // The filter box hands off to the items: ArrowDown focuses the first
        // visible item, Enter lifts it directly (type, then place). Every
        // other key is native typing.
        if (event.key === 'ArrowDown' || event.key === 'Enter') {
          const first = panelItems()[0];
          if (first) {
            if (event.key === 'ArrowDown') {
              first.focus();
            } else {
              liftItem(first);
            }
            handled(event);
          }
        }
        return;
      }
      const item = event.target.closest('.draggable-block, .draggable-section');
      if (!item) {
        return;
      }
      if (event.key === 'Enter' || event.key === ' ') {
        liftItem(item);
        handled(event);
        return;
      }
      const deltas = {
        ArrowDown: 1,
        ArrowRight: 1,
        ArrowUp: -1,
        ArrowLeft: -1,
      };
      if (!(event.key in deltas)) {
        return;
      }
      const items = panelItems();
      if (!items.length) {
        return;
      }
      const index = items.indexOf(item);
      const next =
        index === -1
          ? items[deltas[event.key] > 0 ? 0 : items.length - 1]
          : items[(index + deltas[event.key] + items.length) % items.length];
      next.focus();
      handled(event);
    },
    true,
  );
});
