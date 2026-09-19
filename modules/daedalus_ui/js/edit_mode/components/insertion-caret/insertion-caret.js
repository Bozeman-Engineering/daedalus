import { h } from 'preact';
import { useSelector } from 'react-redux';
import { useEffect, useRef } from 'preact/hooks';
import { getCurrentDrop } from '../../drag/drag-controller';
import {
  liveCaret,
  treeCaretForLocation,
  containerKeyForLocation,
} from '../../drag/gaps';
import { projectionIndex } from '../page-elements/projection-index';

/**
 * The insertion caret: ONE accent line at the gap a drop would fill.
 *
 * The single caret replaces per-region dropzones: while a registered
 * drag is active, the drag controller resolves the cursor to the nearest
 * gap and this component draws it, a line between siblings, or a centered
 * "Drop here" affordance for empty containers, plus a faint highlight on
 * the container being targeted. After the drop it lingers in a committing
 * style until the mutation settles, so a non-optimistic move visibly
 * points at where the block is about to land.
 *
 * Element targets (kind 'element' — a drop that lands ON a compatible
 * element instead of in a gap) get the element face: one highlight box
 * over the landing element with the source's optional label, same
 * committing linger. The controller's target chrome for both kinds lives
 * here; sources never draw their own.
 *
 * The drop's LOCATION is the one carrier, rendered on both surfaces:
 * the canvas face from the gap's live item geometry, and a panel face
 * derived per frame from the location alone (treeCaretForLocation), a
 * line between the page-elements rows the placement would land between.
 * Pointer and keyboard drops alike get both faces; a drop resolved FROM a
 * tree row already draws on the row, so it skips the derived panel face.
 *
 * The caret also carries the depth indicator's at-a-glance face: a chip
 * naming the container the drop would land in (containerKeyForLocation —
 * the same derivation the breadcrumb's drop trail reads through the
 * store), anchored to the line's start, so a line between rows five levels
 * deep no longer looks like a line at the root. The full trail is the
 * breadcrumb's job.
 *
 * Geometry is read imperatively each animation frame from the controller's
 * module state and the live elements — the outline's pattern. The store
 * only gates existence (drag active / committing); per-mousemove targets
 * never touch it.
 */
const InsertionCaret = () => {
  const active = useSelector((state) => state.drag.active);
  const committing = useSelector((state) => state.drag.committing);

  const lineRef = useRef(null);
  const treeLineRef = useRef(null);
  const containerRef = useRef(null);
  const emptyRef = useRef(null);
  const chipRef = useRef(null);
  const elementRef = useRef(null);
  const elementLabelRef = useRef(null);

  useEffect(() => {
    const hide = () => {
      [
        lineRef,
        treeLineRef,
        containerRef,
        emptyRef,
        chipRef,
        elementRef,
      ].forEach((ref) => {
        if (ref.current) {
          ref.current.hidden = true;
        }
      });
    };
    if (!active && !committing) {
      hide();
      return undefined;
    }

    const place = (box, rect) => {
      box.hidden = false;
      box.style.transform = `translate(${rect.left}px, ${rect.top}px)`;
      box.style.width = `${rect.width}px`;
      box.style.height = `${rect.height}px`;
    };

    // The container chip at the caret's start — the depth indicator's
    // at-a-glance face. Content changes per gap transition; position rides
    // the frame like everything else. The CSS lifts it above the anchor.
    const placeChip = (drop, anchor) => {
      const chip = chipRef.current;
      const key = containerKeyForLocation(drop.location);
      const label = key ? projectionIndex()?.get(key)?.label : null;
      if (!label || !anchor) {
        chip.hidden = true;
        return;
      }
      const text = drop.newSection
        ? Drupal.t('New container in @label', { '@label': label })
        : label;
      if (chip.textContent !== text) {
        chip.textContent = text;
      }
      chip.hidden = false;
      chip.style.transform = `translate(${anchor.left}px, ${anchor.top}px)`;
    };

    let frame;
    const loop = () => {
      frame = requestAnimationFrame(loop);
      const current = getCurrentDrop();
      const anchor =
        current &&
        (current.drop.kind === 'element'
          ? current.drop.element
          : current.drop.container);
      if (!current || !anchor.isConnected) {
        hide();
        return;
      }
      const { drop } = current;
      // The element face (kind 'element'): one highlight over the landing
      // element, with the source's optional label — no line, no container
      // highlight, no chip (an element target addresses no insertion
      // location).
      if (drop.kind === 'element') {
        [lineRef, treeLineRef, containerRef, emptyRef, chipRef].forEach(
          (ref) => {
            ref.current.hidden = true;
          },
        );
        place(elementRef.current, drop.element.getBoundingClientRect());
        const label = drop.label ?? '';
        if (elementLabelRef.current.textContent !== label) {
          elementLabelRef.current.textContent = label;
        }
        elementLabelRef.current.hidden = !label;
        return;
      }
      elementRef.current.hidden = true;
      place(containerRef.current, drop.container.getBoundingClientRect());
      // The panel face: derived from the location, whatever surface
      // resolved the drop — except tree-row drops, whose canvas-face line
      // already sits on the row.
      const treeLine = drop.gapId?.startsWith('tree|')
        ? null
        : treeCaretForLocation(drop.location);
      if (treeLine) {
        place(treeLineRef.current, treeLine);
      } else {
        treeLineRef.current.hidden = true;
      }
      if (drop.empty) {
        lineRef.current.hidden = true;
        const rect = drop.container.getBoundingClientRect();
        place(emptyRef.current, rect);
        placeChip(drop, rect);
        return;
      }
      emptyRef.current.hidden = true;
      const line = liveCaret(drop);
      if (!line) {
        lineRef.current.hidden = true;
        chipRef.current.hidden = true;
        return;
      }
      placeChip(drop, line);
      place(lineRef.current, {
        left: line.left,
        top: line.top,
        width: line.width,
        height: line.height,
      });
      lineRef.current.classList.toggle(
        'daedalus-ui-caret__line--vertical',
        line.orientation === 'horizontal',
      );
    };
    loop();

    return () => cancelAnimationFrame(frame);
  }, [active, committing]);

  if (!active && !committing) {
    return null;
  }

  return (
    <div
      className={`daedalus-ui-caret${committing ? ' daedalus-ui-caret--committing' : ''}`}
      aria-hidden="true"
    >
      <div ref={containerRef} className="daedalus-ui-caret__container" hidden />
      <div ref={lineRef} className="daedalus-ui-caret__line" hidden />
      <div ref={chipRef} className="daedalus-ui-caret__chip" hidden />
      <div
        ref={treeLineRef}
        className="daedalus-ui-caret__line daedalus-ui-caret__line--tree"
        hidden
      />
      <div ref={emptyRef} className="daedalus-ui-caret__empty" hidden>
        <span className="daedalus-ui-caret__empty-label">{Drupal.t('Drop here')}</span>
      </div>
      <div ref={elementRef} className="daedalus-ui-caret__element" hidden>
        <span
          ref={elementLabelRef}
          className="daedalus-ui-caret__element-label"
          hidden
        />
      </div>
    </div>
  );
};

export default InsertionCaret;
