import { h } from 'preact';
import { controlWidgets } from '../../controls.js';
import {
  parseKey,
  bundleForKey,
  declarationForKey,
} from '../../style-guide.js';
import { skinDefaultEntry } from '../../variant-scope.js';
import {
  backgroundPick,
  backgroundState,
  gradientStops,
  mediaCompanions,
  mediaImageStop,
  mediaPickReplacements,
} from '../../widget-gradients.js';
import { spaceClosed } from '../../value-space.js';
import { useGradientChip } from '../../gradient-chip.js';
import { MediaGlyph } from '../../media-button.js';
import { useGesture } from '../lib/use-gesture.js';
import { useColorWell } from '../lib/color-well.js';
import { originFor } from '../../choice-row-model.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * background: one swatch well over the background-color +
 * background-image pair.
 *
 * The color half is the shared color well (lib/color-well.js
 * useColorWell) over background-color: its stops, face, color lane and
 * mint row are the well's own anatomy, and every write it produces (a
 * grid pick, a settled picker literal, a minted reference) comes back
 * through one pick door, mapped here by backgroundPick over the pair and
 * the media companions. Gradient-kind swatches offer AFTER the color
 * stops, gated on background-image's OWN constraints (gradientStops),
 * each committing its var() reference to background-image, and the well
 * tells the mapping which table a pick came from. The well IS the
 * control: every other door to a value the grid cannot offer (the
 * gradient builder, the media library) is a self-labelled lane inside
 * its popover, after the well's own color lane.
 *
 * A pick writes the represented state in one op (backgroundPick): its
 * own property plus a clear of the other half when set (explicit
 * replacement, never a silent both-set compound), and unpick clears only
 * what is set. The state read is the two-property backgroundState:
 * media, layered, or foreign background-image occupancy, off-palette
 * colors, and every both-set pair render the honest custom pill. The
 * text recipe's occupancy is read from the posed entry (the widget does
 * not own background-clip): its marker makes every pair member custom,
 * and a pick over it clears the recipe's set members too, because a
 * clipped background paints nothing (textRecipeOccupies).
 *
 * The gradient lane (gradient-chip.js, the media rows' shared wiring)
 * opens the GradientBuilder for custom authoring (commits write the
 * serialized literal, or the minted var() reference, to background-image)
 * or, when the committed value is exactly one gradient-swatch var(),
 * the edit-in-place swatch session over the swatch's own value. Custom
 * authoring hides while background-image's value space is CLOSED
 * (spaceClosed: a scale or allowed list under law, where the builder
 * could only manufacture rejections) unless that committed swatch
 * reference makes the session the offer.
 *
 * The image lane fires the advanced media row's opener gesture
 * (pickMediaSeeded): the pick lands ENTIRELY through the source (modal,
 * URL-table extension, staged preview, ordinary update_style commit to
 * background-image), so the widget just re-renders when the committed
 * map arrives. Its gates ride along unchanged: source.canPickMedia is
 * the honest media_library-installed chrome (the satellite hears the
 * leader's answer and relays the pick as an intent — the modal only ever
 * opens on the leader), and the variant editing session never renders
 * basic controls at all. A committed url('media:…') value joins the
 * image stop table as the synthetic media stop (mediaImageStop): the
 * well wears its thumbnail face instead of reading checker-"?" custom,
 * the grid shows it lit, and its toggle-off unpick clears
 * background-image only (backgroundPick's corners rule over the pair).
 * A pick does NOT clear an already-set color — the source owns the whole
 * delivery — so a color-under-image compound reads as the honest custom
 * face through the same occupancy reads as ever. The basic pick also
 * seeds best-guess companions (size cover / repeat no-repeat / position
 * center, mediaCompanions) computed at the click and threaded through
 * the pickMedia payload, so the leader commits image + companions as ONE
 * op and the common case needs no advanced-mode follow-up; the seed
 * skips anything the effective entry or the active skin's defaults
 * already answer, and anything the guide does not sanction for this
 * target.
 *
 * The row is the ordinary basic row: the leading provenance ring
 * (`.daedalus-stylish-inspector__mark`, hued through `--daedalus-stylish-origin` from the
 * choice row's originFor over whichever half the
 * element wears: background-image while one is set, else background-color), the row label
 * (model.label, the one source the single-row widgets share), then the
 * well at the row's end; the hidden native picker rides the row too.
 * The same originFor read hands the well its ghost: while the pair is
 * unset, the well wears the resting background-color's paint muted (a
 * block's skin background, the Base value at a pose) instead of the
 * slashed blank, so the mark's story and the well beneath it agree.
 *
 * No dynamic gate on purpose (the widget matrix): any box can carry a
 * surface background, so sanction alone decides where the control
 * appears.
 */
const Background = ({
  model,
  guide,
  materials,
  swatches,
  source,
  onPreview,
  onCommit,
}) => {
  const { values, invalid, stage, pick } = useGesture(
    model,
    onPreview,
    onCommit,
  );
  const gradientRow = gradientStops(
    guide,
    materials,
    model.constraints?.['background-image'],
  );
  // The current media token as its own stop (or null): appended AFTER the
  // offer so the existing state read lights it and the grid's lit-chip
  // toggle is the unpick. Derived from the draft-layered value, so the
  // face follows a staged pick before the op settles.
  const mediaStop = mediaImageStop(
    values['background-image'],
    source.getMediaUrls?.() ?? {},
  );
  const imageStops =
    mediaStop === null ? gradientRow : [...gradientRow, mediaStop];
  const siblings = model.entry?.[model.condition] ?? null;
  const image =
    typeof values['background-image'] === 'string'
      ? values['background-image']
      : '';
  const gradient = useGradientChip({
    guide,
    materials,
    committed: image,
    value: image,
    constraints: model.constraints?.['background-image'] ?? null,
    source,
    canMint: Boolean(swatches?.canCreate),
    onPreview: (value) => onPreview('background-image', value),
    onCommit: (value) => pick({ 'background-image': value }),
  });
  const gradientOffered =
    !spaceClosed(guide, model.constraints?.['background-image'] ?? null) ||
    gradient.swatch !== null;
  const canPickMedia = Boolean(source.canPickMedia?.());
  // The seed's computation, at the click (mediaCompanions, the pure
  // half): the bound TARGET's effective declaration is re-derived here
  // because the model deliberately does not carry it (declarationForKey
  // drills part bindings to their elements entry, the sanction and
  // constraint source), and the skin-defaults layer reads separately
  // (skinDefaultEntry is provenance, never merged into model.entry). The
  // base:{tag} layer needs the rendered tag only the canvas DOM knows;
  // the satellite answers it from the leader's relayed selection tags
  // (the element's and the drilled part's), so only a part binding
  // outside the drilled part degrades away there (accepted minor
  // over-seed). The companions ride the pickMedia
  // payload so the leader lands them in the SAME one-op commit as the
  // token; the advanced media row passes none (basic-widget policy).
  // The explicit-replacement nulls (mediaPickReplacements: a set
  // background-color, the text recipe's set members) ride under the
  // seed in the same map, so a media pick over a worn color replaces
  // it instead of burying it as a both-set custom compound.
  const pickMediaSeeded = () => {
    const parsed = parseKey(model.key);
    const blockTypes = source.getBlockTypes?.() ?? {};
    const sectionLayouts = source.getSectionLayouts?.() ?? {};
    const declaration =
      parsed === null
        ? null
        : declarationForKey(
            guide,
            parsed,
            bundleForKey(parsed, blockTypes, sectionLayouts),
          );
    const rootKey = model.key.split('::')[0];
    const tag = source.readCanvasTag?.(rootKey, parsed?.sub ?? null) ?? null;
    const skinEntry = skinDefaultEntry(
      guide,
      model.key,
      blockTypes,
      sectionLayouts,
      tag,
    );
    source.pickMedia?.({
      key: model.key,
      condition: model.condition,
      property: 'background-image',
      label: Drupal.t('Styled @control', { '@control': model.label }),
      companions: {
        ...mediaPickReplacements(values, siblings),
        ...mediaCompanions(
          guide,
          declaration,
          model.entry,
          skinEntry,
          model.condition,
        ),
      },
    });
  };
  // The row's origin (the mark's hue and story) and, beneath an unset
  // well, its ghost: one read for both, so they never disagree. While
  // an image is set the origin reads background-image, and the well is
  // then lit or custom, never resting, so the ghost is moot.
  const origin = model.provenance
    ? originFor({
        guide,
        materials,
        condition: model.condition,
        property: image === '' ? 'background-color' : 'background-image',
        entry: model.entry,
        provenance: model.provenance,
      })
    : null;
  const {
    well,
    input,
    popover: palette,
  } = useColorWell({
    model,
    property: 'background-color',
    guide,
    materials,
    values,
    stage,
    onPreview,
    state: (colorStops) =>
      backgroundState(
        guide,
        materials,
        values,
        colorStops,
        imageStops,
        siblings,
      ),
    stops: imageStops,
    onPick: (stop, imagePick) =>
      pick(backgroundPick(values, stop, imagePick, siblings)),
    lanes: [
      ...(gradientOffered ? [gradient.lane] : []),
      ...(canPickMedia
        ? [
            {
              id: 'image',
              icon: <MediaGlyph />,
              label: Drupal.t('Choose an image'),
              title: Drupal.t('Choose from the media library'),
              run: pickMediaSeeded,
            },
          ]
        : []),
    ],
    canMint: Boolean(swatches?.canCreate),
    source,
    ghost: origin?.ghost ?? null,
  });
  return (
    <div className={widgetClasses('background', invalid)}>
      <div
        className="daedalus-stylish-inspector__widget-row"
        style={origin?.color ? { '--daedalus-stylish-origin': origin.color } : undefined}
      >
        <span
          className="daedalus-stylish-inspector__mark daedalus-ui-dot"
          title={origin?.title}
          aria-hidden="true"
        />
        <span className="daedalus-stylish-inspector__widget-axis">{model.label}</span>
        {well}
        {/* The hidden picker rides the row (positioned), not the popover:
            the lane closes the popover before it opens the chooser, and
            the chooser's events must still land on a mounted input. */}
        {input}
      </div>
      {palette}
      {gradientOffered && gradient.popover}
    </div>
  );
};

controlWidgets.register('background', Background);
