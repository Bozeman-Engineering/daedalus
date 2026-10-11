import { h } from 'preact';
import {
  controlWidgets,
  litValues,
  ownAtPose,
  withheldReasonFor,
} from '../../controls.js';
import {
  parseKey,
  bundleForKey,
  declarationForKey,
} from '../../style-guide.js';
import { deliveredEntry } from '../../style-stack.js';
import {
  backgroundPick,
  backgroundState,
  gradientImageStop,
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
import { useRowMenu } from '../lib/use-row-menu.js';
import { originFor } from '../../choice-row-model.js';
import { widgetClasses } from '../lib/widget-classes.js';
import { Mark } from '../lib/mark.js';

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
 * what is set. The state read is the two-property backgroundState over
 * the pair's WINNERS at the pose (controls.js litValues over the
 * model's reads: a skin's surface color lights its palette stop exactly
 * as an own value does): media, layered, or foreign background-image
 * occupancy, off-palette colors, and every both-set pair render the
 * honest custom pill. The text recipe's occupancy is read from the
 * stack's delivered entry at the posed compound (the widget does not
 * own background-clip): its marker makes every pair member custom, and
 * a pick over it clears the recipe's set members too, because a
 * clipped background paints nothing (textRecipeOccupies). The lit
 * stop's unpick (the Unset gesture) exists only while the element's
 * own entry delivers the lit half at the pose (ownAtPose); otherwise
 * it does nothing, since a null write would remove nothing.
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
 * The image lane fires the pick through the widget contract's
 * onPickMedia (pickMediaSeeded): the widget names the property, the
 * label and its companions, the pipeline routes them exactly as a
 * commit (basic-controls.js route: the image and its set companions on
 * the one box the address rule answers for the group, each null on the
 * box its value lives on) and the source lands the whole delivery
 * (modal, URL-table extension, staged preview, one update_style op or
 * one atomic batch), so the widget just re-renders when the committed
 * map arrives. Its gates ride along unchanged: source.canPickMedia is
 * the honest media_library-installed chrome (the satellite hears the
 * leader's answer and relays the routed pick as an intent; the modal
 * only ever opens on the leader), and the variant editing session never
 * renders basic controls at all. A committed url('media:…') value joins
 * the image stop table as the synthetic media stop (mediaImageStop): the
 * well wears its thumbnail face instead of reading checker-"?" custom,
 * the grid shows it lit, and its toggle-off unpick clears
 * background-image only (backgroundPick's corners rule over the pair).
 * A committed lone gradient literal no offered stop claims joins the
 * same table the same way (gradientImageStop): lit in the grid, worn as
 * the painted gradient face, its toggle the unpick.
 * A pick over a set color clears it in the same gesture
 * (mediaPickReplacements), the explicit replacement the well's own picks
 * make, so the well faces the thumbnail and never a both-set custom;
 * on a two-box binding the clear reaches the box the color lives on.
 * The basic pick also seeds best-guess companions (size cover / repeat
 * no-repeat / position center, mediaCompanions) computed at the click
 * and carried beside the image, so the common case needs no
 * advanced-mode follow-up; the seed skips anything the stack already
 * answers (a worn variant's, the active skin's or a declaration's
 * companion included), and anything the guide does not sanction for
 * this target.
 *
 * The row is the ordinary basic row: the leading mark (lib/mark.js
 * Mark: hue, fill at the pose and sentence from the
 * choice row's originFor over whichever half's stack read the element
 * wears: background-image while one is delivered, else
 * background-color), the row label (model.label, the one source the
 * single-row widgets share), then the well at the row's end; the hidden
 * native picker rides the row too. Right-click opens the shared row
 * menu (lib/use-row-menu.js) over that origin: the doorways every
 * property row offers, its unset the pair's unpick.
 *
 * No dynamic gate on purpose (the widget matrix): any box can carry a
 * surface background, so sanction alone decides where the control
 * appears. The well disables whole, the reason on its title, when the
 * panel's write address withholds either half of the pair (controls.js
 * withheldReasonFor): a pick writes the pair as one.
 */
const Background = ({
  model,
  guide,
  materials,
  swatches,
  source,
  onPreview,
  onCommit,
  onPickMedia,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const { values, invalid, stage, pick } = useGesture(
    model.home.key,
    litValues(model.reads),
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
  // Its gradient twin: a lone gradient literal no offered stop claims,
  // lit so the grid's toggle is the unpick and the well paints it.
  const literalStop = gradientImageStop(
    guide,
    materials,
    values['background-image'],
    gradientRow,
  );
  const imageStops = [
    ...gradientRow,
    ...(mediaStop === null ? [] : [mediaStop]),
    ...(literalStop === null ? [] : [literalStop]),
  ];
  // The stack's delivered entry at the posed compound: the recipe's
  // occupancy read and the media seed's "already answered" read.
  const delivered = deliveredEntry(model.stacks['background-color']);
  const siblings = delivered[model.condition] ?? null;
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
    onPreview: (value) => onPreview({ 'background-image': value }),
    onCommit: (value) => pick({ 'background-image': value }),
  });
  const disabledTitle = withheldReasonFor(model, [
    'background-color',
    'background-image',
  ]);
  const disabled = disabledTitle !== null;
  const gradientOffered =
    !disabled &&
    (!spaceClosed(guide, model.constraints?.['background-image'] ?? null) ||
      gradient.swatch !== null);
  const canPickMedia = !disabled && Boolean(source.canPickMedia?.());
  // The seed's computation, at the click (mediaCompanions, the pure
  // half): the bound TARGET's effective declaration is re-derived here
  // because the model deliberately does not carry it (declarationForKey
  // drills part bindings to their elements entry, the sanction and
  // constraint source); what the stack already answers, the skin's
  // base:{tag} block included wherever the model's tag read knew the
  // rendered tag (the leader reads the canvas, the satellite the
  // relayed selection tags, so only a part binding outside the drilled
  // part degrades away there: accepted minor over-seed), is the
  // delivered entry above. The companions ride onPickMedia beside the
  // picked property, so the pipeline routes them with it as one group
  // and the source lands them in the SAME commit as the token; the
  // advanced media row passes none (basic-widget policy). The
  // explicit-replacement nulls (mediaPickReplacements: a set
  // background-color, the text recipe's set members) ride under the
  // seed in the same map and route as removals to the box each value
  // lives on, so a media pick over a worn color replaces it instead of
  // burying it as a both-set custom compound. The widget names no key:
  // the key read here only locates the declaration the seed is judged
  // against.
  const pickMediaSeeded = () => {
    const parsed = parseKey(model.keys['background-image']);
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
    onPickMedia({
      property: 'background-image',
      label: Drupal.t('Styled !control', { '!control': model.label }),
      companions: {
        ...mediaPickReplacements(values, siblings),
        ...mediaCompanions(guide, declaration, delivered, model.condition),
      },
    });
  };
  // The row's origin (the mark's hue and story) over the half the
  // element wears, and the half the Unset gesture would remove.
  const worn = image === '' ? 'background-color' : 'background-image';
  const origin = originFor({
    guide,
    materials,
    condition: model.condition,
    property: worn,
    stack: model.stacks[worn],
    read: model.reads[worn],
    blockTypes: source.getBlockTypes?.() ?? {},
    sectionLayouts: source.getSectionLayouts?.() ?? {},
  });
  const unsettable = ownAtPose(model.reads[worn], model.condition);
  // The one pick door (the well's grid, its lanes, the menu's unset): a
  // null stop is the unpick, refused while nothing own delivers at the
  // pose and with a withheld row.
  const choose = (stop, imagePick = false) => {
    if (stop === null && (!unsettable || disabled)) {
      return;
    }
    pick(backgroundPick(values, stop, imagePick, siblings));
  };
  const onContextMenu = useRowMenu({
    origin,
    source,
    onOpenDocument,
    onPickCondition,
    onUnset: () => choose(null),
  });
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
    onPick: choose,
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
    disabled,
    disabledTitle,
  });
  return (
    <div className={widgetClasses('background', invalid)}>
      <div
        className="daedalus-stylish-inspector__widget-row"
        style={origin?.color ? { '--daedalus-stylish-origin': origin.color } : undefined}
        onContextMenu={onContextMenu}
      >
        <span className="daedalus-stylish-inspector__widget-label">
          <Mark origin={origin} />
          <span className="daedalus-stylish-inspector__widget-axis">{model.label}</span>
        </span>
        <span className="daedalus-stylish-inspector__widget-value">
          {well}
          {/* The hidden picker rides the value cell (positioned), not the
              popover: the lane closes the popover before it opens the
              chooser, and the chooser's events must still land on a
              mounted input. */}
          {input}
        </span>
      </div>
      {palette}
      {gradientOffered && gradient.popover}
    </div>
  );
};

controlWidgets.register('background', Background);
