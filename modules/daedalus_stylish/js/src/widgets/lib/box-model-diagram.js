import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
  clamp,
  formatDimension,
  lengthIn,
  unitOptions,
} from './dimension-value.js';
import { DimensionField } from './dimension-field.js';
import { useFieldGesture } from './use-gesture.js';
import { valueSpaceFor, wearing } from '../../value-space.js';
import {
  conditionText,
  declarationLabel,
  tokenItems,
} from '../../provenance-model.js';
import { useRowMenu } from './use-row-menu.js';
import { layerColor } from '../../code/layers.js';
import { layerAt } from '../../style-stack.js';
import { stacksIdentity } from './diagram-host.js';
import {
  SIDES,
  familyLonghand,
  settleSpelling,
  sideAnchor,
  sideMeasured,
  sideProvenance,
  sideScrubMove,
  sideScrubSeed,
  sideStepValue,
  spreadSides,
} from './box-model.js';

/**
 * BoxModelDiagram: the devtools-style per-side surface for basic mode —
 * a single ring of four side cells around a content readout, over ONE
 * box family (box-model.js BOX_FAMILIES: the padding ring, the border
 * ring over border-width).
 *
 * The pro-survey division of labor: the DIAGRAM selects and scrubs, the
 * EDITOR edits (Bricks and Builder.io proved cells are too cramped for
 * full inputs). Cells display each side's WINNER with five-state
 * provenance (box-model.js sideProvenance, the per-side read of the
 * style stack) — authored on this element / supplied by a worn variant
 * / defaulted by the active skin / defaulted by the element's
 * declaration, each lit in its layer's hue (the display rule: the shown
 * value is the winner's, never an unlit resting value) / computed from
 * the theme (a muted live placeholder the consumer reads from the
 * canvas, the one value outside the stack, converted into the cell's
 * gesture unit through the canvas's measured bases so `32px` beneath a
 * rem scale reads `2rem`). A supplied side whose value is outside the
 * dimension grammar (the Site width variant's `padding-inline:
 * max(...)`, a skin's calc() shorthand) MEASURES too (box-model.js
 * sideMeasured): the cell shows the converted measurement in the
 * supplier's hue, its editor opens empty over that placeholder, and a
 * gesture writes the physical longhand over the supplier (the wear bar
 * names the override). The cells speak the diagram gesture vocabulary.
 * A cell is its own mark, the property rows' two channels on a text
 * box: its HUE is its delivering layer's, the one identity system the
 * marks read (layers.js layerColor over the layer id, the element's own
 * blue included), and its FILL says "at the pose" (`--here` when the
 * delivering block is the posed compound, sideProvenance's atPose;
 * washed in the hue when the value cascades in from another condition,
 * so an own value from Base viewed at Hover reads "from Base", never
 * "set here"). The hue is set as `--daedalus-stylish-origin` on the
 * cell for the stylesheet's wash and fill. The gesture vocabulary:
 * - Drag a cell sideways to scrub its value THROUGH ITS VALUE SPACE
 *   (value-space.js, per side longhand): a closed vocabulary jumps
 *   detent to detent across the stops, a detented one scrubs
 *   continuously with sticky magnets, a free one keeps the plain
 *   diagram grain. The Figma modifier split applies (Shift = coarse on
 *   free spaces, Alt = mirror the opposite side, Alt+Shift = all four;
 *   the full DevTools grain ladder lives only in the editor row, where
 *   no spread gestures compete for the keys).
 * - Focused cell: ArrowUp/ArrowDown step with the same modifiers —
 *   one stop on closed spaces, the diagram grain landing ON crossed
 *   stops on detented ones — preview per press, one commit on release.
 * - Drag the ring label ('padding') to scrub ALL FOUR sides in unison
 *   (the all-four spread as a grabbable surface, anchored at the top
 *   side's value); arrows on the focused label are the keyboard
 *   equivalent. All four bands light while the label scrubs.
 * - Click a cell to open that side's editor row beneath the diagram —
 *   the full DimensionField over the same value space (its closed
 *   render IS the stop select, so no separate strict picker exists);
 *   right-click on the editor row opens the shared row menu
 *   (use-row-menu.js) over the side's token items, the advanced rows'
 *   token half.
 * - Alt-click a value authored at the pose to clear it (the layer
 *   beneath returns, or the computed placeholder). Escape closes the
 *   editor.
 *
 * Faces show VALUES, never var() syntax: a committed reference renders
 * as its display value at the editing condition (wearing) with the
 * blessed tint; a settle that lands on a stop commits the stop's
 * reference (settleSpelling — the magnetic-capture doctrine, so closed
 * commits are always references).
 *
 * Value semantics ride the box-model pure lib: the shorthand field
 * elsewhere keeps writing the shorthand; the first per-side edit here
 * expands it into four longhands in one atomic commit
 * (sideCommit/sidesPatch), so shorthand and authored longhands never
 * coexist. An own shorthand the expansion cannot split honestly
 * (calc()/var()) renders as an opaque set-but-unsplittable state and the
 * cells go inert — committing guessed per-side values would silently
 * change the element.
 *
 * One component, three seams (the settled shape: no second ring). The
 * diagram owns what is one value per side in every ring — scrub, keys,
 * spread, highlight, provenance over the scrubbed family — and the
 * consumer supplies what differs:
 * - `patchFor(valuesBySide)`: the commit shape for a gesture's per-side
 *   values (null for an unset side), the ONE seam through which a
 *   write reaches an own layer: the diagram reads none itself, since
 *   the layer a patch expands and collapses follows the write (the box
 *   its set lands on, lib/write-layer.js), never a render-time key.
 *   The padding ring folds each gesture through the family's sidesPatch
 *   over that layer; the border ring through the stroke completion rule
 *   and the three-family expansion (stroke-model.js strokeSidesPatch).
 *   The empty gesture is the BLOCKED probe: null means an own shorthand
 *   the expansion cannot split, and the ring degrades to display-only.
 * - `renderCellFace(cell)`: what a cell shows; the default is the
 *   side's value text, the border ring draws the side's line glyph
 *   beside its width.
 * - `renderEditor(cell, label)`: the side editor beneath the ring; the
 *   default is the DimensionField over the side's longhand
 *   (SideDimensionEditor), the border ring mounts the per-side stroke
 *   row.
 *
 * The component is a pure consumer of the widget contract: previews per
 * gesture tick through onPreview, one update_style commit per gesture
 * through onCommit (a failed commit drops the optimistic draft and
 * marks the diagram invalid until the next authoritative value),
 * releases through onRelease when a gesture ends where it began.
 * onHighlight reports which sides the user is touching (hover, scrub,
 * open editor) so the consumer can map them to longhands and drive the
 * canvas highlight (source.setCanvasHighlight); null clears, including
 * on unmount.
 *
 * @param {object} props
 * @param {?object} props.guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide) — the value
 *   spaces derive from it.
 * @param {string} props.condition
 *   The editing compound (model.condition); reference displays resolve
 *   here.
 * @param {Object<string, object>} props.stacks
 *   The per-property layer stacks (the widget model's `stacks`): the
 *   cells read them per side (box-model.js sideProvenance, each side
 *   from its longhand's box), a variant cell's label and a declared
 *   cell's scope come from its delivering layer, and its hue from the
 *   layer's id.
 * @param {?Object<string, string>} props.computed
 *   side → live computed longhand value ('16px') read from the canvas
 *   (computed-read.js, first match), or null when no canvas element.
 * @param {?{rootPx: ?number, fontPx: ?number}} props.bases
 *   The canvas's measured conversion bases (readCanvas), what a
 *   computed placeholder converts into the cell's unit through; null
 *   off canvas (the placeholder then shows whole when it cannot
 *   convert, and seeds 0).
 * @param {?string} props.center
 *   The content-size readout for the diagram center ('272 × 96').
 * @param {string} props.family
 *   The box family this ring scrubs (a BOX_FAMILIES key: 'padding',
 *   'border-width'); its shorthand is the own layer's expansion source
 *   and its longhands the cells' value spaces.
 * @param {?string} props.name
 *   The ring's one-word name on its label and in its stylesheet class
 *   ('padding', 'border'); falls back to the family.
 * @param {?string} props.label
 *   The human label for the ring (Drupal.t('Padding')); falls back to
 *   the name.
 * @param {Function} props.patchFor
 *   (valuesBySide) => patch | null — the gesture commit shape (see
 *   above), the consumer's own layer read per write inside it.
 * @param {?Function} props.renderCellFace
 *   (cell) => node — the cell's face; defaults to the value text.
 * @param {?Function} props.renderEditor
 *   (cell, label) => node — the side editor; defaults to the
 *   DimensionField over the side's longhand.
 * @param {Object<string, object>} props.definitions
 *   model.propertyDefinitions (per longhand).
 * @param {Object<string, object>} props.constraints
 *   model.constraints (per longhand).
 * @param {Function} props.onPreview
 *   (properties) — the widget contract's preview handler, the whole
 *   staged patch per tick.
 * @param {Function} props.onRelease
 *   (properties) — release staged previews without committing.
 * @param {Function} props.onCommit
 *   (properties) => Promise — one update_style op for the gesture.
 * @param {?Function} props.onHighlight
 *   (sides: string[] | null) — the canvas-band hook; null clears.
 * @param {string[]} props.sides
 *   The sides the roster GRANTS here (grantedSides over model.granted):
 *   a target whose spacing grant is a side subset (a container's
 *   vertical rhythm) renders the withheld sides as inert, display-only
 *   cells — no scrub, no editor, no spread reaches them, so no gesture
 *   can ever write a withheld longhand.
 */
export const BoxModelDiagram = ({
  guide = null,
  materials = null,
  condition = 'default',
  stacks,
  computed = null,
  bases = null,
  center = null,
  family = 'padding',
  name = null,
  label = null,
  patchFor,
  renderCellFace = null,
  renderEditor = null,
  definitions = {},
  constraints = {},
  onPreview,
  onRelease,
  onCommit,
  onHighlight = null,
  sides,
}) => {
  // The optimistic draft: side → previewed text (null = cleared),
  // layered over the authoritative layers for rendering, superseded by
  // any new authoritative value (a settled commit, an AI edit, a
  // selection move) — the useGesture discipline. The stacks are rebuilt
  // every render, so their identity is what their layers say.
  const identity = JSON.stringify([condition, stacksIdentity(stacks)]);
  const [draft, setDraft] = useState(null);
  const [invalid, setInvalid] = useState(false);
  const [selected, setSelected] = useState(null);
  const [hovered, setHovered] = useState(null);
  const [scrubSides, setScrubSides] = useState(null);
  const gestureRef = useRef(null);
  const clickGuardRef = useRef(false);
  useEffect(() => {
    setDraft(null);
    setInvalid(false);
  }, [identity]);

  const ringName = name ?? family;
  const ringLabel = label ?? ringName;
  // The gesture commit shape: the consumer's seam, every write's own
  // layer read inside it.
  const patch = patchFor;
  // The granted sides in SIDES order; gestures clamp their targets to
  // this, so a spread can never write a withheld longhand.
  const offered = SIDES.filter((side) => sides.includes(side));
  const states = sideProvenance(
    stacks,
    family,
    condition,
    guide?.conditions ?? {},
  );
  // The origin's hue: a set cell wears its delivering layer's color
  // (the element's own blue, a variant's hash, the floor tiers' teal:
  // the one identity system the property rows' marks read); a computed
  // cell carries none (the stylesheet's muted ink).
  const originColor = (prov) =>
    prov.id === null ? null : layerColor(prov.id);
  // An own shorthand the expansion cannot split (calc(), a
  // multi-component var(); a lone token reference splits) blocks EVERY
  // per-side write (sideCommit's whole-patch contract), so the diagram
  // degrades to display-only; the shorthand's own editor (the padding
  // field row, the Border row) still works. The empty gesture is the
  // probe, through the same seam every commit takes.
  const blocked = patch({}) === null;

  const cellFor = (side) => {
    const granted = offered.includes(side);
    const longhand = familyLonghand(family, side);
    const prov = states[side];
    // The side's OWN value space — the same derivation the fields make,
    // so the cells scrub exactly what the editor offers.
    const space = valueSpaceFor(
      guide,
      materials,
      longhand,
      definitions[longhand] ?? null,
      constraints[longhand] ?? null,
      condition,
    );
    const drafted = draft !== null && side in draft;
    const state = drafted
      ? draft[side] === null
        ? 'computed'
        : 'authored'
      : prov.state;
    const value = drafted
      ? draft[side]
      : prov.state === 'computed'
        ? null
        : prov.value;
    // Values, never var(): a committed reference renders its display
    // value at the editing condition, blessed.
    const worn =
      value === null
        ? null
        : wearing(guide, materials, space, String(value), condition);
    const face = value === null ? null : (worn?.face ?? value);
    // A measured side (box-model.js sideMeasured): computed, or
    // supplied by a value outside the grammar. It shows the canvas's
    // measurement converted into the gesture's unit, and its editor
    // opens empty over that placeholder.
    const measured = sideMeasured(state, face);
    // Set-but-unsplittable: the side IS styled by an own calc()/var()
    // shorthand but no per-side value can honestly be shown.
    const opaque = !measured && value === null;
    const raw = measured ? (computed?.[side] ?? null) : null;
    // The gesture's unit and start (box-model.js sideAnchor): a set
    // side's own, a measured side's through contextUnit (the
    // vocabulary's unit) seeded from the placeholder converted into it
    // through the canvas's bases, so the cell, the ring label and the
    // side editor beneath write one unit.
    const anchor = sideAnchor(
      space,
      measured ? raw : face,
      measured,
      unitOptions(constraints[longhand] ?? {}),
      bases,
    );
    // The measured face: the placeholder in the gesture's unit (`32px`
    // beneath rem at a 16px root is `2rem`), whole when it cannot
    // convert (`50%` beneath rem), null off canvas.
    const placeholder =
      raw === null ? null : (lengthIn(raw, anchor.unit, bases) ?? raw);
    const shown = measured ? placeholder : face;
    const blessed = !measured && (worn?.token ?? null) !== null;
    // A closed space with an empty vocabulary offers nothing legal; any
    // other kind is editable through the field.
    const editable =
      granted &&
      !blocked &&
      (space.kind !== 'closed' || space.stops.length > 0);
    // Closed cells scrub the stops (unset enters at the value-low end);
    // open cells need a numeric anchor (a calc()/var() longhand degrades
    // to click-to-edit, like DimensionField's inert scrub).
    const scrubbable =
      editable &&
      (space.kind === 'closed'
        ? space.stops.length > 0
        : anchor.mode !== 'text');
    const { unit } = anchor;
    // At the pose: the delivering block is the posed compound
    // (sideProvenance's atPose, the fill every mark reads); a set draft
    // is being written at the pose. A value reaching the pose from
    // another condition is lit like any winner (hollow: the wash), and
    // an edit over it writes an override at the pose.
    const here = drafted ? draft[side] !== null : prov.atPose;
    // The own layer delivering the side AT the pose: the Unset verb's
    // test (an Alt-click clear, the editor's emptied field).
    const ownAtPose = !drafted && prov.state === 'authored' && here;
    const clearable = granted && !blocked && ownAtPose;
    // A draft is this element's (or cleared): no origin hue mid-gesture.
    const reference = drafted ? null : prov.reference;
    const layer = drafted
      ? null
      : layerAt(stacks[longhand] ?? null, prov.layer);
    const color = drafted ? null : originColor(prov);
    const from = drafted ? null : prov.condition;
    return {
      side,
      longhand,
      space,
      granted,
      state,
      measured,
      opaque,
      value,
      shown,
      blessed,
      editable,
      scrubbable,
      unit,
      anchor,
      here,
      ownAtPose,
      clearable,
      reference,
      layer,
      color,
      from,
    };
  };
  const cells = {};
  SIDES.forEach((side) => {
    cells[side] = cellFor(side);
  });

  const sideLabels = {
    top: Drupal.t('top'),
    right: Drupal.t('right'),
    bottom: Drupal.t('bottom'),
    left: Drupal.t('left'),
  };
  // The origin in words (the aria state, the title's leading phrase),
  // in the row marks' vocabulary (provenance-model.js originOf): an
  // authored cell at the pose is "set here" ("set here at Hover + Dark"
  // when posed), an own value cascading in from another condition is
  // "from Base"; a variant cell names its variant when the library can
  // label it, a defaults cell its supplier (the declaring layer's scope
  // for a declaration, so a kind block says "kind"), each with "at
  // !condition" when the delivering block is not the pose ("from the
  // active skin at Base").
  const stateLabel = (cell) => {
    if (cell.state === 'computed') {
      return Drupal.t('computed from the theme');
    }
    const conditions = guide?.conditions ?? {};
    if (cell.state === 'authored') {
      if (cell.here) {
        return condition === 'default'
          ? Drupal.t('set here')
          : Drupal.t('set here at !condition', {
              '!condition': conditionText(condition, conditions),
            });
      }
      return Drupal.t('from !condition', {
        '!condition': conditionText(cell.from, conditions),
      });
    }
    let supplier;
    if (cell.state === 'variant') {
      supplier = cell.layer?.label ?? Drupal.t('a worn variant');
    } else if (cell.state === 'skin') {
      supplier = Drupal.t('the active skin');
    } else {
      supplier = Drupal.t('the !declaration declaration', {
        '!declaration':
          declarationLabel(cell.layer?.scope ?? null) ?? Drupal.t('element'),
      });
    }
    return cell.here
      ? Drupal.t('from !supplier', { '!supplier': supplier })
      : Drupal.t('from !supplier at !condition', {
          '!supplier': supplier,
          '!condition': conditionText(cell.from, conditions),
        });
  };

  // --- gesture plumbing: preview per tick, ONE commit at settle.
  const applyGesture = (updates, targets) => {
    const gesture = gestureRef.current;
    gesture.bySide = { ...gesture.bySide, ...updates };
    const staged = patch(gesture.bySide);
    if (staged === null) {
      return;
    }
    setDraft((current) => ({ ...(current ?? {}), ...updates }));
    setScrubSides(targets);
    onPreview(staged);
  };

  const settleGesture = () => {
    const gesture = gestureRef.current;
    gestureRef.current = null;
    setScrubSides(null);
    if (!gesture || Object.keys(gesture.bySide).length === 0) {
      return;
    }
    const changed = Object.entries(gesture.bySide).some(
      ([side, text]) => text !== gesture.base[side],
    );
    if (!changed) {
      // The gesture ended where it began: release the staged previews
      // instead of committing a no-op (the useFieldGesture discipline).
      const staged = patch(gesture.bySide);
      if (staged !== null) {
        onRelease(staged);
      }
      setDraft(null);
      return;
    }
    // The commit spelling per side: a value landing on a stop commits
    // the stop's reference through that side's own space (magnetic
    // capture — closed scrubs only ever land on stops).
    const spellings = {};
    Object.entries(gesture.bySide).forEach(([side, text]) => {
      spellings[side] =
        text === null ? null : settleSpelling(cells[side].space, text);
    });
    const settled = patch(spellings);
    if (settled === null) {
      return;
    }
    onCommit(settled).then(
      () => setInvalid(false),
      (error) => {
        setDraft(null);
        if (!error?.abandoned) {
          setInvalid(true);
        }
      },
    );
  };

  // The faces a gesture starts from, per side: what "ended where it
  // began" compares against at settle.
  const snapshotFaces = () => {
    const faces = {};
    SIDES.forEach((side) => {
      faces[side] = cells[side].shown ?? null;
    });
    return faces;
  };

  const sideBounds = (side) => [
    definitions[familyLonghand(family, side)] ?? {},
    constraints[familyLonghand(family, side)] ?? {},
  ];

  // A gesture's clamp: single-side gestures clamp against the grabbed
  // side (spread siblings follow it, matching the cell Alt+Shift
  // behavior); the label's unison gesture clamps through EVERY side's
  // bounds so no side previews a value its own constraint would reject.
  // Closed gestures never reach here — their values ARE the stops.
  const clampFor = (gesture, number, unit) =>
    (gesture.spread ?? [gesture.side]).reduce((result, side) => {
      const [definition, bounds] = sideBounds(side);
      return clamp(result, unit, definition, bounds);
    }, number);

  // The ring label's unison gesture: the all-offered spread as a
  // grabbable surface, anchored at the first granted side (top wherever
  // top is granted — the same anchor an Alt+Shift scrub from that cell
  // would use). Under a side-subset grant "all" means all GRANTED sides.
  const allFour =
    offered.length === SIDES.length
      ? spreadSides('top', { altKey: true, shiftKey: true })
      : [...offered];
  const anchorSide = offered[0] ?? 'top';
  const labelScrubbable =
    !blocked && offered.length > 0 && cells[anchorSide].scrubbable;

  // Alt-click clear: preview and commit in one breath (the useGesture
  // chip semantics).
  const clearSide = (side) => {
    const cleared = patch({ [side]: null });
    if (cleared === null) {
      return;
    }
    setDraft((current) => ({ ...(current ?? {}), [side]: null }));
    setInvalid(false);
    onPreview(cleared);
    onCommit(cleared).then(
      () => setInvalid(false),
      (error) => {
        setDraft(null);
        if (!error?.abandoned) {
          setInvalid(true);
        }
      },
    );
    setSelected((current) => (current === side ? null : current));
  };

  // --- pointer: scrub with capture; the tap decisions (select,
  // Alt-clear) ride the click event that follows an unmoved release.
  const scrubGesture = (cell, spread) => ({
    kind: 'scrub',
    side: cell.side,
    spread,
    space: cell.space,
    scrub: sideScrubSeed(cell.space, cell.shown, cell.anchor.number, cell.unit),
    base: snapshotFaces(),
    bySide: {},
    last: null,
    moved: false,
  });

  const onCellPointerDown = (cell) => (event) => {
    // A fresh press always re-arms the tap path — a stale guard (a
    // browser that skipped the post-drag click) must never swallow it.
    clickGuardRef.current = false;
    if (!cell.scrubbable) {
      return;
    }
    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    gestureRef.current = scrubGesture(cell, null);
  };

  const onLabelPointerDown = (event) => {
    if (!labelScrubbable) {
      return;
    }
    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    gestureRef.current = scrubGesture(cells[anchorSide], allFour);
  };

  const onCellPointerMove = (event) => {
    const gesture = gestureRef.current;
    if (
      !gesture ||
      gesture.kind !== 'scrub' ||
      !event.currentTarget.hasPointerCapture(event.pointerId)
    ) {
      return;
    }
    if (!gesture.moved && event.movementX === 0) {
      return;
    }
    gesture.moved = true;
    const advanced = sideScrubMove(
      gesture.space,
      gesture.scrub,
      event.movementX,
      event,
    );
    let { text } = advanced;
    if (gesture.space.kind !== 'closed') {
      const number = clampFor(gesture, advanced.number, advanced.unit);
      // Pin the accumulator at a clamp so recovery is immediate — no
      // rubber-band distance to scrub back through (DimensionField's
      // rule).
      advanced.raw = number === advanced.number ? advanced.raw : number;
      text = formatDimension(number, advanced.unit);
    }
    gesture.scrub = advanced;
    const targets = (gesture.spread ?? spreadSides(gesture.side, event)).filter(
      (side) => offered.includes(side),
    );
    const tick = `${text}|${targets.join(',')}`;
    if (tick === gesture.last) {
      return;
    }
    gesture.last = tick;
    const updates = {};
    targets.forEach((target) => {
      updates[target] = text;
    });
    applyGesture(updates, targets);
  };

  const onCellPointerUp = (event) => {
    const gesture = gestureRef.current;
    if (!gesture || gesture.kind !== 'scrub') {
      return;
    }
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    const { side, moved, spread } = gesture;
    settleGesture();
    if (moved && !spread) {
      // A scrub selects the grabbed side (its editor opens on the
      // scrubbed value); suppress the click that follows the release.
      // A label (unison) scrub grabbed no side, so it selects nothing.
      clickGuardRef.current = true;
      setSelected(side);
    }
  };

  const onCellClick = (cell) => (event) => {
    if (clickGuardRef.current) {
      clickGuardRef.current = false;
      return;
    }
    if (!cell.editable) {
      return;
    }
    if (event.altKey && cell.clearable) {
      clearSide(cell.side);
      return;
    }
    setSelected((current) => (current === cell.side ? null : cell.side));
  };

  // --- keyboard: arrows step through the cell's value space (one stop
  // when closed, the diagram grain landing on crossed stops when
  // detented, plain grain when free), with the diagram spread; preview
  // per press, settle on release (the DimensionField pattern).
  const stepKeyGesture = (cell, spread, event) => {
    if (!gestureRef.current || gestureRef.current.kind !== 'keys') {
      gestureRef.current = {
        kind: 'keys',
        side: cell.side,
        spread,
        space: cell.space,
        anchor:
          cell.space.kind === 'closed'
            ? {
                index: sideScrubSeed(
                  cell.space,
                  cell.shown,
                  cell.anchor.number,
                  cell.unit,
                ).index,
                unit: cell.unit,
              }
            : { number: cell.anchor.number, unit: cell.unit },
        base: snapshotFaces(),
        bySide: {},
      };
    }
    const gesture = gestureRef.current;
    const direction = event.key === 'ArrowUp' ? 1 : -1;
    const stepped = sideStepValue(
      gesture.space,
      gesture.anchor,
      direction,
      event,
    );
    if (stepped === null) {
      return;
    }
    let { text } = stepped;
    if (gesture.space.kind === 'closed') {
      gesture.anchor = { ...gesture.anchor, index: stepped.index };
    } else {
      const number = clampFor(gesture, stepped.number, gesture.anchor.unit);
      gesture.anchor = { ...gesture.anchor, number };
      text = formatDimension(number, gesture.anchor.unit);
    }
    const targets = (gesture.spread ?? spreadSides(gesture.side, event)).filter(
      (side) => offered.includes(side),
    );
    const updates = {};
    targets.forEach((target) => {
      updates[target] = text;
    });
    applyGesture(updates, targets);
  };

  const onCellKeyDown = (cell) => (event) => {
    if (event.key === 'Escape') {
      setSelected(null);
      return;
    }
    if (
      (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') ||
      !cell.scrubbable
    ) {
      return;
    }
    event.preventDefault();
    stepKeyGesture(cell, null, event);
  };

  const onLabelKeyDown = (event) => {
    if (
      (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') ||
      !labelScrubbable
    ) {
      return;
    }
    event.preventDefault();
    stepKeyGesture(cells[anchorSide], allFour, event);
  };

  const onCellKeyUp = (event) => {
    if (
      (event.key === 'ArrowUp' || event.key === 'ArrowDown') &&
      gestureRef.current?.kind === 'keys'
    ) {
      settleGesture();
    }
  };

  // --- the side editor: the consumer's seam, or the DimensionField
  // over the side's longhand (SideDimensionEditor), mounted beneath
  // the ring while an editable side is selected and keyed by side so a
  // switch starts the field afresh.
  const editorSide = selected ?? anchorSide;
  const editorCell = cells[editorSide];

  // --- canvas-band hook: scrub targets win, then hover (the ring label
  // hovers all four), then the open editor's side; null on nothing (and
  // on unmount).
  const hoveredSides = hovered === 'all' ? allFour : hovered ? [hovered] : null;
  // (allFour is already clamped to the offered sides above.)
  const active = scrubSides ?? hoveredSides ?? (selected ? [selected] : null);
  const activeKey = active ? active.join(',') : '';
  useEffect(() => {
    if (onHighlight) {
      onHighlight(active);
    }
  }, [activeKey]);
  useEffect(
    () => () => {
      if (onHighlight) {
        onHighlight(null);
      }
    },
    [],
  );

  // --- rendering.
  const face = renderCellFace ?? cellText;

  // The hover title: the origin's words first on every set cell (the
  // "every mark has a sentence" rule the property rows' marks follow:
  // "1rem set here at Hover + Dark", "1rem from Base", "2rem from the
  // active skin at Base"), then the gesture hints.
  const cellTitle = (cell) => {
    const gesture = gestureTitle(cell);
    if (cell.granted && !blocked && !cell.opaque && cell.state !== 'computed') {
      const origin = Drupal.t('!value !origin', {
        '!value': cell.shown ?? '',
        '!origin': stateLabel(cell),
      }).trim();
      return gesture ? `${origin} · ${gesture}` : origin;
    }
    return gesture;
  };

  const gestureTitle = (cell) => {
    if (!cell.granted) {
      return Drupal.t('This side is not stylable here.');
    }
    if (blocked) {
      return Drupal.t(
        'A !property value the diagram cannot split per side is set (calc/var). Edit !property itself.',
        { '!property': ringName },
      );
    }
    if (cell.opaque) {
      return Drupal.t(
        'This side is styled by a value the diagram cannot show per side (calc/var).',
      );
    }
    if (cell.scrubbable && cell.space.kind === 'closed') {
      return cell.clearable
        ? Drupal.t(
            'Drag sideways to step through the allowed values · Alt mirrors · Alt+Shift all four · Alt-click clears',
          )
        : Drupal.t(
            'Drag sideways to step through the allowed values · Alt mirrors · Alt+Shift all four',
          );
    }
    if (cell.clearable) {
      return Drupal.t(
        'Drag sideways to change · Alt mirrors · Alt+Shift all four · Alt-click clears',
      );
    }
    if (cell.scrubbable) {
      return Drupal.t(
        'Drag sideways to change · Alt mirrors · Alt+Shift all four',
      );
    }
    return cell.editable ? Drupal.t('Click to pick a value') : undefined;
  };

  const renderCell = (cell, vertical) => {
    // A withheld side (the guide's allowlist grants a subset) renders as
    // an inert display-only cell: the value stays honest (the computed
    // placeholder, or a legacy authored value), but no gesture, editor,
    // or spread can reach it.
    if (!cell.granted) {
      return (
        <span
          className={[
            'daedalus-stylish-boxmodel__cell',
            vertical ? 'daedalus-stylish-boxmodel__cell--v' : '',
            `daedalus-stylish-boxmodel__cell--${cell.state}`,
            cell.here ? 'daedalus-stylish-boxmodel__cell--here' : '',
            'daedalus-stylish-boxmodel__cell--withheld',
          ]
            .filter(Boolean)
            .join(' ')}
          style={cell.color ? { '--daedalus-stylish-origin': cell.color } : undefined}
          title={cellTitle(cell)}
          aria-label={Drupal.t('!label !side: !value (not stylable here)', {
            '!label': ringLabel,
            '!side': sideLabels[cell.side],
            '!value': cell.shown ?? Drupal.t('not set'),
          })}
        >
          {face(cell)}
        </span>
      );
    }
    const scrubbing = scrubSides !== null && scrubSides.includes(cell.side);
    const classes = [
      'daedalus-stylish-boxmodel__cell',
      vertical ? 'daedalus-stylish-boxmodel__cell--v' : '',
      `daedalus-stylish-boxmodel__cell--${cell.state}`,
      cell.here ? 'daedalus-stylish-boxmodel__cell--here' : '',
      cell.opaque ? 'daedalus-stylish-boxmodel__cell--opaque' : '',
      cell.blessed ? 'daedalus-stylish-boxmodel__cell--blessed' : '',
      selected === cell.side ? 'daedalus-stylish-boxmodel__cell--selected' : '',
      scrubbing ? 'daedalus-stylish-boxmodel__cell--scrubbing' : '',
      cell.scrubbable ? 'daedalus-stylish-boxmodel__cell--scrubbable' : '',
    ]
      .filter(Boolean)
      .join(' ');
    return (
      <button
        type="button"
        className={classes}
        aria-pressed={selected === cell.side}
        style={cell.color ? { '--daedalus-stylish-origin': cell.color } : undefined}
        aria-label={Drupal.t('!label !side: !value (!state)', {
          '!label': ringLabel,
          '!side': sideLabels[cell.side],
          '!value': cell.shown ?? Drupal.t('not set'),
          '!state': stateLabel(cell),
        })}
        title={cellTitle(cell)}
        onPointerDown={onCellPointerDown(cell)}
        onPointerMove={onCellPointerMove}
        onPointerUp={onCellPointerUp}
        onPointerCancel={onCellPointerUp}
        onPointerEnter={() => setHovered(cell.side)}
        onPointerLeave={() =>
          setHovered((current) => (current === cell.side ? null : current))
        }
        onClick={onCellClick(cell)}
        onKeyDown={onCellKeyDown(cell)}
        onKeyUp={onCellKeyUp}
      >
        {face(cell)}
      </button>
    );
  };

  const editorLabel = Drupal.t('!label !side', {
    '!label': ringLabel,
    '!side': sideLabels[editorSide],
  });

  const rootClasses = [
    'daedalus-stylish-boxmodel',
    invalid ? 'daedalus-stylish-boxmodel--invalid' : '',
    blocked ? 'daedalus-stylish-boxmodel--blocked' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={rootClasses}>
      <div
        className={`daedalus-stylish-boxmodel__ring daedalus-stylish-boxmodel__ring--${ringName}`}
      >
        {labelScrubbable ? (
          <button
            type="button"
            className={[
              'daedalus-stylish-boxmodel__ring-label',
              'daedalus-stylish-boxmodel__ring-label--scrubbable',
              scrubSides !== null && scrubSides.length === offered.length
                ? 'daedalus-stylish-boxmodel__ring-label--scrubbing'
                : '',
            ]
              .filter(Boolean)
              .join(' ')}
            aria-label={
              offered.length === SIDES.length
                ? Drupal.t('!label, all four sides', { '!label': ringLabel })
                : Drupal.t('!label, all stylable sides', {
                    '!label': ringLabel,
                  })
            }
            title={
              offered.length === SIDES.length
                ? Drupal.t('Drag sideways to change all four sides')
                : Drupal.t('Drag sideways to change every stylable side')
            }
            onPointerDown={onLabelPointerDown}
            onPointerMove={onCellPointerMove}
            onPointerUp={onCellPointerUp}
            onPointerCancel={onCellPointerUp}
            onPointerEnter={() => setHovered('all')}
            onPointerLeave={() =>
              setHovered((current) => (current === 'all' ? null : current))
            }
            onKeyDown={onLabelKeyDown}
            onKeyUp={onCellKeyUp}
          >
            {ringName}
          </button>
        ) : (
          <span className="daedalus-stylish-boxmodel__ring-label" aria-hidden="true">
            {ringName}
          </span>
        )}
        {renderCell(cells.top, true)}
        <div className="daedalus-stylish-boxmodel__mid">
          {renderCell(cells.left, false)}
          <div className="daedalus-stylish-boxmodel__center" aria-hidden="true">
            {center ?? ''}
          </div>
          {renderCell(cells.right, false)}
        </div>
        {renderCell(cells.bottom, true)}
      </div>
      {selected && editorCell.editable && (
        <div className="daedalus-stylish-boxmodel__editor" key={editorSide}>
          {renderEditor ? (
            renderEditor(editorCell, editorLabel)
          ) : (
            <SideDimensionEditor
              cell={editorCell}
              label={editorLabel}
              guide={guide}
              materials={materials}
              condition={condition}
              definition={definitions[editorCell.longhand] ?? {}}
              constraints={constraints[editorCell.longhand] ?? {}}
              bases={bases}
              placeholder={
                editorCell.measured
                  ? (computed?.[editorSide] ?? undefined)
                  : undefined
              }
              patchFor={(value) => patch({ [editorSide]: value })}
              onPreview={onPreview}
              onRelease={onRelease}
              onCommit={onCommit}
            />
          )}
        </div>
      )}
    </div>
  );
};

/**
 * A cell's default face: the side's value text (a measured side's
 * placeholder already converted into its unit). A px value shows its
 * bare number to keep the cramped cells readable; any other unit stays
 * spelled out. Exported for a consumer's own face to compose with (the
 * border ring draws its glyph beside it).
 */
export const cellText = (cell) => {
  if (cell.shown === null || cell.shown === undefined) {
    return cell.opaque ? '…' : '–';
  }
  const text = String(cell.shown).trim();
  return /^-?(?:\d+(?:\.\d+)?|\.\d+)px$/.test(text) ? text.slice(0, -2) : text;
};

/**
 * The default side editor: the full DimensionField over the side's
 * longhand and value space (its closed render IS the stop select, so no
 * separate strict picker exists), through the ring's commit shape
 * (patchFor over the one side). The field shows the side's winner, lit,
 * whichever layer delivers it (the display rule); typing over it writes
 * an own value at the pose, and the emptied field commits the unset
 * only while the own layer delivers the side at the exact pose (the
 * cell's ownAtPose, useFieldGesture's `own`), releasing otherwise: a
 * skin's or a variant's side has nothing here to remove. A measured
 * side opens the field EMPTY over its measurement as the placeholder
 * (the field converts it into its unit through the same bases the
 * cell did): a `max(...)` a variant supplies is no text to edit per
 * side, and what is typed here writes the physical longhand over it.
 * Right-click opens the shared row menu (use-row-menu.js) over the
 * side's token items, the advanced rows' token half.
 */
const SideDimensionEditor = ({
  cell,
  label,
  guide,
  materials,
  condition,
  definition,
  constraints,
  bases,
  placeholder,
  patchFor,
  onPreview,
  onRelease,
  onCommit,
}) => {
  const field = useFieldGesture(
    cell.measured ? '' : (cell.value ?? ''),
    cell.longhand,
    (properties) => {
      const patch = patchFor(properties[cell.longhand] ?? null);
      if (patch !== null) {
        onPreview(patch);
      }
    },
    (properties) => {
      const patch = patchFor(properties[cell.longhand] ?? null);
      if (patch !== null) {
        onRelease(patch);
      }
    },
    (properties) => {
      const patch = patchFor(properties[cell.longhand] ?? null);
      return patch === null ? Promise.resolve() : onCommit(patch);
    },
    cell.ownAtPose ? (cell.value ?? '') : null,
  );
  const onContextMenu = useRowMenu({
    origin: null,
    tokens: () =>
      tokenItems(guide, materials, condition, cell.space, field.shown, {
        onPreview: field.preview,
        onSettle: field.settle,
      }),
    face: () =>
      wearing(guide, materials, cell.space, field.shown, condition).face ||
      field.shown,
  });
  return (
    <div
      className="daedalus-stylish-boxmodel__editor-field"
      onContextMenu={onContextMenu}
    >
      <DimensionField
        label={label}
        value={field.shown}
        property={cell.longhand}
        definition={definition}
        constraints={constraints}
        guide={guide}
        materials={materials}
        condition={condition}
        bases={bases}
        placeholder={placeholder}
        invalid={field.invalid}
        // The cell is the mark (its state class and origin wash); the
        // field inside it leads with none.
        mark={null}
        onPreview={field.preview}
        onSettle={field.settle}
      />
    </div>
  );
};
