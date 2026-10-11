import { h } from 'preact';
import { useLayoutEffect, useRef, useState } from 'preact/hooks';
import { ATTR } from '../panel-dom.js';

/**
 * @file
 * The peek's layer: one frame beside the panel, scaled, with a loading
 * state, never focusable, hidden from assistive technology.
 *
 * The layer sits beside the panel at the rested-on row's height (fixed,
 * outside the rail, which clips; on the rail's page-facing side, or the
 * other side when that one is short), and holds a face: the loading
 * shimmer until the door's document arrives, then a frame whose srcdoc
 * is that document, laid out at a desktop width and scaled onto the face
 * from its top left exactly as the start chooser's cards are (the scale
 * is the CSS's, --daedalus-layout-peek-scale). The face takes the placed
 * element's own height once the frame loads (read off the frame's
 * document, same-origin as a srcdoc is), between a floor and a ceiling;
 * past the ceiling the face clips and fades at its foot. A refused peek
 * shows the refusal's sentence in the frame's place.
 *
 * Inert throughout: the layer is `inert` and `aria-hidden` (the cut
 * element carries the editor's stamps, which must never be focused or
 * read as the canvas), the frame's tabindex is -1 and nothing in it takes
 * the pointer. A caption names the face, for the sighted editor the
 * layer is for.
 */

/**
 * The gap between the rail and the layer, and the layer's viewport
 * margin, in pixels.
 */
const GAP = 8;

/**
 * The face's floor and ceiling, in pixels: a one-line block still reads
 * as a thing; a long blueprint shows its top.
 */
export const FACE_MIN = 48;
export const FACE_MAX = 384;

/**
 * The face's height before the frame loads: the shimmer's.
 */
const FACE_LOADING = 128;

/**
 * The scale the CSS lays the frame out at.
 * @param element
 */
const scaleOf = (element) =>
  parseFloat(
    getComputedStyle(element).getPropertyValue('--daedalus-layout-peek-scale'),
  ) || 0.4;

/**
 * The placed element's height in its document: the shown element's
 * bottom edge, else the body's.
 * @param frame
 * @param show
 */
const contentHeight = (frame, show) => {
  const doc = frame.contentDocument;
  if (!doc) {
    return 0;
  }
  const element = doc.querySelector(`[data-selectable="${show}"]`);
  if (element) {
    return (
      element.getBoundingClientRect().bottom +
      (doc.defaultView?.scrollY ?? 0) +
      GAP
    );
  }
  return doc.body?.scrollHeight ?? 0;
};

/**
 * The layer.
 *
 * @param {object} props
 *   - peek: {faceId, label, show, status, document, message, top}: the
 *     face shown, the key of what the document shows, `loading`, `ready`
 *     or `refused`, the document (ready) or the refusal's sentence
 *     (refused), and the rested-on row's top edge.
 *   - panel: the panel element the layer sits beside.
 * @param props.peek
 * @param props.panel
 */
export const PeekFrame = ({ peek, panel }) => {
  const ref = useRef(null);
  const [face, setFace] = useState({ height: FACE_LOADING, clipped: false });

  // A new peek starts at the loading height.
  useLayoutEffect(() => {
    setFace({ height: FACE_LOADING, clipped: false });
  }, [peek.faceId, peek.show]);

  // Beside the panel, at the row's height, clamped into the viewport.
  useLayoutEffect(() => {
    const layer = ref.current;
    if (!layer) {
      return;
    }
    const rail = panel?.getBoundingClientRect();
    const width = layer.offsetWidth;
    const height = layer.offsetHeight;
    let left = rail ? rail.right + GAP : GAP;
    if (left + width > window.innerWidth - GAP && rail) {
      left = Math.max(GAP, rail.left - GAP - width);
    }
    const top = Math.max(
      GAP,
      Math.min(peek.top, window.innerHeight - height - GAP),
    );
    layer.style.left = `${left}px`;
    layer.style.top = `${top}px`;
  });

  const scale = ref.current ? scaleOf(ref.current) : 0.4;

  const onLoad = (event) => {
    const measured = contentHeight(event.target, peek.show) * scale;
    const height = Math.min(FACE_MAX, Math.max(FACE_MIN, measured));
    setFace({ height, clipped: measured > FACE_MAX });
  };

  return (
    <div
      ref={ref}
      className="daedalus-layout-add-panel-peek"
      inert
      aria-hidden="true"
      data-clipped={face.clipped ? '' : undefined}
      {...{
        [ATTR.peek]: peek.status,
        [ATTR.peekFor]: peek.faceId,
      }}
    >
      {peek.status === 'refused' ? (
        <p className="daedalus-layout-add-panel-peek__refused">
          {peek.message}
        </p>
      ) : (
        <div
          className="daedalus-layout-add-panel-peek__face"
          style={{ height: `${face.height}px` }}
        >
          {peek.status === 'ready' && (
            <iframe
              className="daedalus-layout-add-panel-peek__frame"
              srcdoc={peek.document}
              title={Drupal.t('Preview: @label', { '@label': peek.label })}
              tabIndex={-1}
              aria-hidden="true"
              scrolling="no"
              style={{ height: `${face.height / scale}px` }}
              onLoad={onLoad}
              {...{ [ATTR.peekFrame]: '' }}
            />
          )}
        </div>
      )}
      <p className="daedalus-layout-add-panel-peek__caption">{peek.label}</p>
    </div>
  );
};
