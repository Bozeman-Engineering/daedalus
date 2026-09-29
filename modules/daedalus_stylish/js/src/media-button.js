import { h } from 'preact';

/**
 * The media library opener chip and its photo glyph.
 *
 * Extracted from the advanced media row's inline markup when the basic
 * Background widget became the second consumer: one opener face
 * everywhere the media library mounts. The chip is pure chrome over an
 * onClick the consumer owns (the advanced row calls media.onPick, the
 * basic widget its onPickMedia contract; either way the routed pick
 * reaches the SOURCE, which lands the whole delivery: modal, table
 * extension, staged preview, commit). The honest
 * offer gate (media.canPick / source.canPickMedia) stays with the
 * consumer too: it is a source read, not chip state, and a gated-off
 * chip renders nothing rather than a disabled button.
 */

/**
 * The photo glyph (frame, sun, mountains): the chip's empty face, and
 * the swatch well's honest face for a media value whose thumbnail the
 * client URL table cannot answer.
 *
 * @param {object} props
 * @param {?string} [props.className]
 */
export const MediaGlyph = ({ className = null }) => (
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
      stroke-width="1.5"
    />
    <circle cx="5.5" cy="6.5" r="1.25" fill="currentColor" />
    <path
      d="M2.5 12l3.5-3.5 2.5 2.5 2.5-2.5 2.5 2.5"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linejoin="round"
    />
  </svg>
);

/**
 * The opener chip: a thumbnail face when the consumer passes one, the
 * photo glyph otherwise.
 *
 * The advanced row wears the committed token's first layer here; the
 * basic Background widget passes none on purpose — its WELL wears the
 * current value, and the chip stays the opener.
 *
 * @param {object} props
 * @param {?string} [props.thumbnail]
 *   A resolved image URL for the face, or null for the glyph.
 * @param {Function} props.onClick
 */
export const MediaButton = ({ thumbnail = null, onClick }) => (
  <button
    type="button"
    className="daedalus-stylish-inspector__media-button"
    title={Drupal.t('Choose from the media library')}
    onClick={onClick}
  >
    {thumbnail ? (
      <img
        className="daedalus-stylish-inspector__media-thumbnail"
        src={thumbnail}
        alt=""
      />
    ) : (
      <MediaGlyph />
    )}
  </button>
);
