import { h } from 'preact';
import { useState } from 'preact/hooks';
import { GradientBuilder, GradientSwatchSession } from './gradient-builder.js';
import { gradientSwatchOf } from './materials.js';

/**
 * The gradient popover wiring, shared at the second-consumer threshold
 * (the advanced media row's PropertyWidget and the basic-mode color
 * widgets both mount it).
 *
 * One hook carries everything the consumers used to wire by hand: the
 * open/close state, the edit-in-place gate (a committed value that is
 * exactly one var() naming a gradient-kind swatch opens the swatch update
 * session over the swatch's OWN value; the row's value never changes, the
 * live link is the point), the builder popover over anything else, and
 * the save-as-swatch mint seam (the mint response's materials delta
 * handed to source.applyResponse, so the store, both windows and the
 * canvas custom property learn the swatch before any reload). The opener
 * is the consumer's: the basic wells offer `lane` inside their palette
 * popover (the well IS the control, so every door to a value the grid
 * cannot offer is a lane there), the advanced media row renders its own
 * opener button over `open`/`close`. A hook rather than one component
 * because the popover flows full-width beneath the row while the opener
 * sits inline on it, and preact renders one subtree per component.
 *
 * canMint is honest chrome per permission (the swatch gates'
 * `canCreate`); the mint route enforces. The popover is a pure client
 * widget over the handlers, so it renders in the satellite too.
 *
 * @param {object} options
 * @param {object} options.guide
 *   The style guide payload.
 * @param {object} options.materials
 *   The materials roster.
 * @param {string} options.committed
 *   The row's committed value, the edit-in-place gate's input. Pass ''
 *   to keep the gate off (the builder always opens).
 * @param {string} options.value
 *   The row's current (draft-layered) value the builder edits.
 * @param {?object} [options.constraints]
 *   The bound property's guide constraints (the builder's stop
 *   vocabulary inherits them).
 * @param {object} options.source
 *   The state source (the swatch session's endpoints + the mint seam).
 * @param {boolean} [options.canMint]
 *   Whether the save-as-swatch gesture renders.
 * @param {Function} options.onPreview
 *   Called with the whole rebuilt value per builder input event.
 * @param {Function} options.onCommit
 *   Called with the whole rebuilt value on builder settle.
 *
 * @return {{open: Function, close: Function, expanded: boolean,
 *   swatch: ?object, lane: object, popover: ?object}}
 *   open()/close() drive the popover; expanded is its state (an opener's
 *   aria-expanded); swatch is the edit-in-place gate's answer, so a
 *   consumer can wear the swatch's label on its own chrome; lane is the
 *   entry for usePalettePopover's `lanes` (its run opens the popover);
 *   popover is the (expanded ? mounted : null) vnode the consumer places.
 */
export const useGradientChip = ({
  guide,
  materials,
  committed,
  value,
  constraints = null,
  source,
  canMint = false,
  onPreview,
  onCommit,
}) => {
  const [expanded, setExpanded] = useState(false);
  const swatch = gradientSwatchOf(guide, materials, committed);
  const open = () => setExpanded(true);
  const close = () => setExpanded(false);

  const lane = {
    id: 'gradient',
    icon: <GradientGlyph />,
    label: swatch ? Drupal.t('Edit the swatch') : Drupal.t('Build a gradient'),
    title: swatch
      ? Drupal.t('Edit the swatch this value references')
      : Drupal.t('Build a custom gradient'),
    run: open,
  };

  const popover = !expanded ? null : swatch ? (
    /* The edit-in-place session: the value IS a gradient-kind swatch
       reference, so the opener reaches the builder over the swatch's own
       value in session chrome (frame, identity, blast line, Update
       swatch / Discard). Committing restyles every use through the live
       link. Pure client over direct endpoints and the source's materials
       seams; the satellite renders it unchanged. */
    <GradientSwatchSession
      swatch={swatch}
      guide={guide}
      materials={materials}
      constraints={constraints}
      source={source}
      onClose={close}
    />
  ) : (
    /* The gradient builder popover: edits ONE gradient layer of the
       value honestly (other layers verbatim) through the consumer's own
       preview/commit pair, so the canvas answers instantly and the
       commit rides the consumer's ordinary write path. The
       save-as-swatch gesture mints site-wide through the source's batch
       commit (save_material): the op queue lands the record on the store
       and relays it, so this window's vocabulary, the paired window's, and
       the canvas custom property all learn it at once. */
    <GradientBuilder
      value={value}
      guide={guide}
      materials={materials}
      constraints={constraints}
      canMint={canMint}
      source={source}
      onPreview={onPreview}
      onCommit={onCommit}
      onClose={close}
    />
  );

  return { open, close, expanded, swatch, lane, popover };
};

/**
 * The gradient glyph: a fading bar triptych reads as a gradient without
 * an SVG <defs> id (ids would duplicate across rows). The lane's icon
 * and the advanced media row's opener face.
 *
 * @param {object} props
 * @param {?string} [props.className]
 */
export const GradientGlyph = ({ className = null }) => (
  <svg
    className={className ?? undefined}
    viewBox="0 0 16 16"
    width="12"
    height="12"
    aria-hidden="true"
  >
    <rect
      x="1.25"
      y="2.25"
      width="13.5"
      height="11.5"
      rx="1.5"
      fill="none"
      stroke="currentColor"
      stroke-width="1.25"
    />
    <rect
      x="3"
      y="4"
      width="3.3"
      height="8"
      fill="currentColor"
      opacity="0.9"
    />
    <rect
      x="6.3"
      y="4"
      width="3.3"
      height="8"
      fill="currentColor"
      opacity="0.55"
    />
    <rect
      x="9.6"
      y="4"
      width="3.4"
      height="8"
      fill="currentColor"
      opacity="0.25"
    />
  </svg>
);
