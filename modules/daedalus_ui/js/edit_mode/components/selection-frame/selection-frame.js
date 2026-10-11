import { h } from 'preact';
import { useSelector } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import { createSelectableTracker } from '../../selection/selectable-tracker';
import { documentPosition } from '../../utilities/geometry-tracker';
import { typeForKey } from '../../selection/type-for-key';
import { verbsForSet, runVerbOn, onChange } from '../../verbs/verbs';
import { verbMenuItems } from '../../verbs/verb-menu-items';
import { chipPlacement, chipFace, frameEditing } from './selection-frame-model';

/**
 * The selection frame: the ONE owner of everything anchored to the
 * selection's rect. Strokes, one chip per member, and the primary's verb
 * face are one component, so anchored chrome cannot collide by
 * construction.
 *
 * Strokes: a hover box plus one box per member of the multi-select SET,
 * driven by the selection slice's keys; the PRIMARY gets the
 * strong stroke, secondary members a lighter one. Document-positioned
 * siblings in #daedalus-root with pointer-events: none, so they never
 * capture a click or stack, and they scroll with the content instead of
 * trailing it (geometry-tracker.js on why fixed chrome lags a
 * compositor scroll). The stroke is DASHED while a LIVE edit lands on
 * the primary (daedalus-ui-frame--editing): the store's editingKey IS the
 * primary (frameEditing), a reactive fact the engine's editChanged
 * report writes — never a prediction from editability, so ending an
 * edit any way (Escape included) restores the solid stroke and the
 * chip's verbs.
 *
 * Chips: every member wears `N Type` above its top-left corner (flipped
 * below when there is no room above, never inside, clamped to the
 * canvas the rails leave; selection-frame-model.js decides, this host
 * measures and paints). N is the member's position in the ordered set,
 * shown for a real set only; Type comes from the one type resolver
 * (type-for-key.js) — the canvas already shows what the element SAYS, so
 * its chip names what it IS ("Text", "Section"), while the tree, the
 * breadcrumb, and the AI chips keep the content-derived labelForKey
 * read of the same projection. The primary's chip always carries the
 * verb face: the registry's `tab`-flagged verbs as icon buttons plus a
 * chevron opening the context menu with the rest. The face is read from
 * chipFace's entries (selection-frame-model.js): a plain `{verb}` or a
 * `{group, primary, rest}` folded by the registry's `group` key
 * (verb-groups.js), the group seated where its lightest member sits.
 * This host draws a group as ONE split control (GroupControl): the
 * primary's verb button as the main half and a caret half that opens the
 * shared menu over the rest, the same control and stylesheet as the top
 * bar's Save (ButtonBuilderTrait::buildSplitButton(), split-button.css,
 * which rides the modes library that edit_mode depends on), so the chip
 * adds only the skin its halves need (selection-frame.css). WHICH verbs
 * it holds is the registry's applies gate (verbs.js): selection verbs while no
 * live edit lands on the primary, edit-scoped verbs while the store's
 * editingKey IS it — so opening an edit swaps the face's contents
 * instead of hiding it, and the chip stays the one home of "what can I
 * do here" through the whole edit lifecycle. An edit's own controls
 * (tag, link, format pickers) enter as widget verbs whose popovers ride
 * the widget slot; no other module mounts DOM into the frame.
 *
 * Geometry rides the shared per-frame tracker (selectable flavor):
 * elements are measured each animation frame and re-resolved by key
 * when a subtree swap replaces the node, so scroll, resize, animation,
 * and markup swaps are all covered by one mechanism.
 */

// The caret-down glyph (daedalus_ui/assets/caret-down.svg, the one the
// top bar's Save caret draws through the icon pack): the chip's chevron
// and a split control's caret are the same "more of this" gesture, so
// they wear the same glyph.
const CARET_DOWN =
  '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 256 256"><path d="M213.66,101.66l-80,80a8,8,0,0,1-11.32,0l-80-80A8,8,0,0,1,53.66,90.34L128,164.69l74.34-74.35a8,8,0,0,1,11.32,11.32Z"/></svg>';

/**
 * A verb's label on this chip: the single-selection menuLabel names the
 * target ("Update blueprint 'X'"); a real set keeps the static label.
 */
const chipVerbLabel = (verb, selectedKeys) =>
  verb.menuLabel && selectedKeys.length === 1
    ? verb.menuLabel(selectedKeys[0])
    : verb.label;

/**
 * One verb as a chip button: icon, and the text label at full density
 * (or always, for a verb with no icon). `className` layers a split
 * control's half modifier on the verb skin.
 */
const VerbButton = ({ verb, selectedKeys, labels, className = '' }) => {
  const verbLabel = chipVerbLabel(verb, selectedKeys);
  return (
    <button
      type="button"
      className={`daedalus-ui-frame__verb${className ? ` ${className}` : ''}`}
      data-daedalus-ui-tooltip={verbLabel}
      data-daedalus-ui-tooltip-position="top"
      aria-label={verbLabel}
      onClick={(event) => {
        event.preventDefault();
        event.stopPropagation();
        runVerbOn(verb, selectedKeys);
      }}
    >
      {verb.icon && (
        <span
          className="daedalus-ui-frame__icon"
          aria-hidden="true"
          dangerouslySetInnerHTML={{ __html: verb.icon }}
        />
      )}
      {(labels || !verb.icon) && (
        <span className="daedalus-ui-frame__label">{verbLabel}</span>
      )}
    </button>
  );
};

/**
 * A verb group as one split control (verb-groups.js: `{group, primary,
 * rest}`). The main half IS the primary's verb button (the group's
 * lightest member, which is why the fold seats the group where it sits);
 * the caret half opens the shared menu over the rest, read through
 * verbMenuItems at open time like every other face of the registry, so
 * the menu lists the members flat in weight order with their menuLabels.
 * The control's shape (one pill, a seam between the halves) is the top
 * bar's split-button.css; the chip's own modifiers (`__verb--main`,
 * `__verb--caret`) carry only what its blue chip needs over it
 * (selection-frame.css). Group members are run verbs: a widget verb has
 * no run to put behind a caret and never carries a group.
 */
const GroupControl = ({ entry, selectedKeys, labels }) => {
  const { primary, rest } = entry;
  const caretLabel = Drupal.t('More @label actions', {
    '@label': primary.label,
  });
  return (
    <div className="daedalus-ui-split-button daedalus-ui-frame__group">
      <VerbButton
        verb={primary}
        selectedKeys={selectedKeys}
        labels={labels}
        className="daedalus-ui-split-button__main daedalus-ui-frame__verb--main"
      />
      <button
        type="button"
        className="daedalus-ui-frame__verb daedalus-ui-split-button__caret daedalus-ui-frame__verb--caret"
        data-daedalus-ui-tooltip={caretLabel}
        data-daedalus-ui-tooltip-position="top"
        aria-label={caretLabel}
        aria-haspopup="menu"
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
          Drupal.Daedalus.menu.open({
            anchor: event.currentTarget,
            align: 'end',
            items: verbMenuItems(selectedKeys, (verb) => rest.includes(verb)),
          });
        }}
      >
        <span
          className="daedalus-ui-frame__icon"
          aria-hidden="true"
          dangerouslySetInnerHTML={{ __html: CARET_DOWN }}
        />
      </button>
    </div>
  );
};

/**
 * A widget verb's slot: an empty container the verb populates itself (a
 * stepper, a popover control, a small control cluster; chrome a plain
 * button can't express). The verb's widget(key, container, {labels})
 * runs on mount, whenever the selection key changes, and when the
 * chrome density flips its labels flag (widgets follow the same density
 * ladder as the plain verb buttons); its returned cleanup runs before
 * each re-run and on unmount, and the slot is cleared so a widget never
 * sees a predecessor's DOM.
 */
const WidgetSlot = ({ verb, selectedKey, labels }) => {
  const slotRef = useRef(null);

  useEffect(() => {
    const slot = slotRef.current;
    const cleanup = verb.widget(selectedKey, slot, { labels });
    return () => {
      if (typeof cleanup === 'function') {
        cleanup();
      }
      slot.replaceChildren();
    };
  }, [verb, selectedKey, labels]);

  return (
    <div
      ref={slotRef}
      className="daedalus-ui-frame__widget"
      role="group"
      aria-label={verb.label}
      data-daedalus-ui-tooltip={verb.label}
      data-daedalus-ui-tooltip-position="top"
    />
  );
};

const SelectionFrame = () => {
  const hoverKey = useSelector((state) => state.selection.hoverKey);
  const primaryKey = useSelector((state) => state.selection.selectedKey);
  const selectedKeys = useSelector((state) => state.selection.selectedKeys);
  const editingKey = useSelector((state) => state.selection.editingKey);
  const mode = useSelector((state) => state.mode.mode);
  // Chips name members through the type resolver's projection read, so
  // a mirror replacement (e.g. a block-type change on re-place) must
  // re-render them.
  useSelector((state) => state.blueprint.blueprint);
  // Re-render when verbs register after a selection already exists.
  const [, bump] = useState(0);
  useEffect(() => onChange(() => bump((version) => version + 1)), []);

  // The frame serves the one selection, with or without an open edit.
  // EDITING is the LIVE edit, read reactively from the store's editingKey
  // (frameEditing): the dash appears when an edit actually opens on the
  // primary and retires the moment it ends — never a prediction from
  // editability. verbsForSet runs while editing too: the registry's
  // applies gate (appliesTo reads the same editingKey) decides whether
  // the face holds selection verbs or the edit's own, and this component
  // re-renders on every editingKey change, so the swap is reactive.
  const active = mode === 'edit';
  const editing = frameEditing({ editingKey, primary: primaryKey });
  const verbs = primaryKey ? verbsForSet(selectedKeys) : [];

  // The verb face is one more consumer of the chrome-density spine: the
  // html attribute is the one thing CSS, the reveal engine, and now the
  // frame read, and it mutates live (the settings select mirrors the
  // saved value onto html), so the frame re-renders on its mutation.
  const [density, setDensity] = useState(
    document.documentElement.getAttribute('data-daedalus-ui-chrome-density') ?? 'full',
  );
  useEffect(() => {
    const observer = new MutationObserver(() => {
      setDensity(
        document.documentElement.getAttribute('data-daedalus-ui-chrome-density') ??
          'full',
      );
    });
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-daedalus-ui-chrome-density'],
    });
    return () => observer.disconnect();
  }, []);

  const hoverRef = useRef(null);
  // Stroke boxes and chip anchors by selectable key; the ref callbacks
  // below maintain the maps as members enter and leave the set.
  const boxRefs = useRef(new Map());
  const chipRefs = useRef(new Map());
  // The shared geometry tracker, one per frame instance so its element
  // cache survives effect restarts (box names: 'hover' or the key).
  const trackerRef = useRef(null);
  if (!trackerRef.current) {
    trackerRef.current = createSelectableTracker();
  }

  useEffect(() => {
    const tracker = trackerRef.current;
    tracker.prune(['hover', ...selectedKeys]);

    if (!active || (!hoverKey && selectedKeys.length === 0)) {
      // Nothing to track: hide everything so a cleared selection doesn't
      // leave a stale stroke at its last position.
      if (hoverRef.current) {
        hoverRef.current.hidden = true;
      }
      boxRefs.current.forEach((box) => {
        box.hidden = true;
      });
      chipRefs.current.forEach((chip) => {
        chip.hidden = true;
      });
      return undefined;
    }

    const paintStroke = (name, key, box) => {
      if (!box) {
        return null;
      }
      const measured = key ? tracker.measure(name, key) : null;
      if (!measured) {
        box.hidden = true;
        return null;
      }
      const { rect } = measured;
      box.hidden = false;
      const { left, top } = documentPosition(rect);
      box.style.transform = `translate(${left}px, ${top}px)`;
      box.style.width = `${rect.width}px`;
      box.style.height = `${rect.height}px`;
      return measured;
    };

    // A zero-render-seam selection (a blueprint element with no canvas
    // presence) anchors the PRIMARY's chip to its page-elements panel
    // row instead: the row is the element's only DOM representation. The
    // row sits in the FIXED rail, so that branch keeps viewport
    // positioning and skips the canvas clamp (its anchor lives inside a
    // rail on purpose).
    const paintChip = (anchor, key, measured) => {
      if (!anchor) {
        return;
      }
      let rect = measured?.rect ?? null;
      let viewportAnchored = false;
      if (!rect && key === primaryKey) {
        const row = document.querySelector(
          `[data-tree-key="${CSS.escape(key)}"]`,
        );
        const rowRect = row?.getBoundingClientRect();
        if (rowRect && (rowRect.width > 0 || rowRect.height > 0)) {
          rect = rowRect;
          viewportAnchored = true;
        }
      }
      if (!rect) {
        anchor.hidden = true;
        return;
      }
      anchor.hidden = false;
      anchor.classList.toggle('daedalus-ui-frame__anchor--viewport', viewportAnchored);
      const offsets = viewportAnchored
        ? { right: -Infinity, top: -Infinity }
        : (Drupal.displace?.offsets ?? {});
      const placed = chipPlacement({
        rect,
        chip: { width: anchor.offsetWidth, height: anchor.offsetHeight },
        viewport: { width: window.innerWidth, height: window.innerHeight },
        offsets,
      });
      anchor.classList.toggle('daedalus-ui-frame__anchor--below', placed.below);
      const pos = viewportAnchored ? placed : documentPosition(placed);
      anchor.style.transform = `translate(${pos.left}px, ${pos.top}px)`;
    };

    tracker.start(() => {
      // The hover box duplicates a set member's box when they coincide;
      // show only the stronger one.
      paintStroke(
        'hover',
        selectedKeys.includes(hoverKey) ? null : hoverKey,
        hoverRef.current,
      );
      selectedKeys.forEach((key) => {
        const measured = paintStroke(key, key, boxRefs.current.get(key));
        paintChip(chipRefs.current.get(key), key, measured);
      });
    });

    return () => tracker.stop();
  }, [active, editing, hoverKey, primaryKey, selectedKeys]);

  if (!active) {
    return null;
  }

  const keyRef = (map) => (key) => (node) => {
    if (node) {
      map.set(key, node);
    } else {
      map.delete(key);
    }
  };
  const boxRef = keyRef(boxRefs.current);
  const chipRef = keyRef(chipRefs.current);

  return (
    <div className={`daedalus-ui-frame${editing ? ' daedalus-ui-frame--editing' : ''}`}>
      <div
        ref={hoverRef}
        className="daedalus-ui-frame__stroke daedalus-ui-frame__stroke--hover"
        aria-hidden="true"
        hidden
      />
      {selectedKeys.map((key) => (
        <div
          key={key}
          ref={boxRef(key)}
          className={`daedalus-ui-frame__stroke ${key === primaryKey ? 'daedalus-ui-frame__stroke--primary' : 'daedalus-ui-frame__stroke--secondary'}`}
          aria-hidden="true"
          hidden
        />
      ))}
      {selectedKeys.map((key) => {
        const face = chipFace({
          key,
          keys: selectedKeys,
          primary: primaryKey,
          verbs,
          density,
        });
        const label = typeForKey(key);
        return (
          <div
            key={`chip:${key}`}
            ref={chipRef(key)}
            className="daedalus-ui-frame__anchor"
            hidden
          >
            <div
              className={`daedalus-ui-frame__chip${face.primary ? ' daedalus-ui-frame__chip--primary' : ''}`}
              role={face.primary ? 'toolbar' : undefined}
              aria-label={face.primary ? Drupal.t('Selection') : undefined}
              data-selection-key={key}
            >
              {face.number !== null && (
                <span className="daedalus-ui-frame__number">{face.number}</span>
              )}
              <span className="daedalus-ui-frame__name">{label}</span>
              {(face.faceVerbs.length > 0 || face.overflow.length > 0) && (
                <div className="daedalus-ui-frame__verbs">
                  {face.faceVerbs.map((entry) => {
                    // A group entry (verb-groups.js) is one split control
                    // (GroupControl); a plain entry is one verb.
                    if (entry.group) {
                      return (
                        <GroupControl
                          key={`group:${entry.group}`}
                          entry={entry}
                          selectedKeys={selectedKeys}
                          labels={face.labels}
                        />
                      );
                    }
                    const { verb } = entry;
                    // Widget verbs render their own DOM in a slot; they
                    // only reach here for single selections (multi
                    // defaults false and widget verbs never opt in).
                    if (verb.widget) {
                      return (
                        <WidgetSlot
                          key={verb.id}
                          verb={verb}
                          selectedKey={key}
                          labels={face.labels}
                        />
                      );
                    }
                    return (
                      <VerbButton
                        key={verb.id}
                        verb={verb}
                        selectedKeys={selectedKeys}
                        labels={face.labels}
                      />
                    );
                  })}
                  {face.overflow.length > 0 && (
                    <button
                      type="button"
                      className="daedalus-ui-frame__verb daedalus-ui-frame__more"
                      data-daedalus-ui-tooltip={Drupal.t('More actions')}
                      data-daedalus-ui-tooltip-position="top"
                      aria-label={Drupal.t('More actions')}
                      aria-haspopup="menu"
                      onClick={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        // The overflow menu hangs off the chevron (the shared
                        // menu primitive, daedalus_ui/menu).
                        Drupal.Daedalus.menu.open({
                          anchor: event.currentTarget,
                          items: verbMenuItems(
                            selectedKeys,
                            (verb) => !verb.tab,
                          ),
                        });
                      }}
                    >
                      <span
                        className="daedalus-ui-frame__icon"
                        aria-hidden="true"
                        dangerouslySetInnerHTML={{ __html: CARET_DOWN }}
                      />
                    </button>
                  )}
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default SelectionFrame;
