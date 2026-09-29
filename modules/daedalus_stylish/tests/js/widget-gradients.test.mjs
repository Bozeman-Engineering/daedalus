/**
 * @file
 * Locks the color widgets' gradient model layer (widget-gradients.js):
 * stop derivation, the two- and three-property state honesty rules, and
 * the text recipe batch. Run with: node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { EMPTY_MATERIALS, materialsFrom } from './materials-fixture.mjs';
import { gradientSwatchOf } from '../../js/src/materials.js';
import {
  backgroundPick,
  backgroundState,
  gradientImageStop,
  gradientStops,
  MEDIA_COMPANIONS,
  mediaCompanions,
  mediaPickReplacements,
  mediaImageStop,
  singleGradient,
  textGradientBatch,
  textGradientState,
  textPick,
} from '../../js/src/widget-gradients.js';
import { paletteFallback, stopsFor } from '../../js/src/widget-values.js';

// The guide payload's palette half, as ClientStyleGuide serves it: token
// scale prefixes resolved, swatches label-sorted with resolved variables.
const guide = {
  // The scheme axis makes 'dark' a scheme key (paintDark reads null
  // without it, whatever the value carries).
  conditions: {
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  },
  tokenScales: {
    color: { label: 'Color', shape: 'color', prefix: 'color' },
  },
  tokens: {
    color: { accent: '#1a1a2e' },
  },
  swatchKinds: { color: { label: 'Colors' }, gradient: { label: 'Gradients' } },
  swatches: [
    { machine_name: 'brand_red', label: 'Brand red', kind: 'color', value: '#b91c1c', variable: '--swatch-brand_red' },
    { machine_name: 'hero_fade', label: 'Hero fade', kind: 'gradient', value: 'linear-gradient(180deg, #000, #fff)', variable: '--swatch-hero_fade' },
    {
      machine_name: 'sunset',
      label: 'Sunset',
      kind: 'gradient',
      value: { default: 'radial-gradient(circle, #f97316, #7c2d12)', dark: 'radial-gradient(circle, #431407, #000)' },
      variable: '--swatch-sunset',
    },
  ],
};

// The same palette as the materials store carries it.
const materials = materialsFrom(guide);

const colorRow = stopsFor(guide, materials, null, paletteFallback(guide, materials));
const gradientRow = gradientStops(guide, materials, materials, null);

test('gradientStops: unconstrained rows offer every gradient-kind swatch', () => {
  // var() references in payload order, faces painted from payload data (a
  // condition-keyed value shows its default entry), never var()
  // resolution; color swatches and tokens never offer on the gradient
  // side.
  assert.deepEqual(gradientRow.map((stop) => stop.value), [
    'var(--swatch-hero_fade)', 'var(--swatch-sunset)',
  ]);
  assert.deepEqual(gradientRow.map((stop) => stop.paint), [
    'linear-gradient(180deg, #000, #fff)', 'radial-gradient(circle, #f97316, #7c2d12)',
  ]);
  assert.deepEqual(gradientRow.map((stop) => stop.label), ['Hero fade', 'Sunset']);
  // Scheme-paired swatches thread their dark half onto the stop (the
  // split face's other panel); single-valued stops carry null and stay
  // whole.
  assert.deepEqual(gradientRow.map((stop) => stop.paintDark), [
    null, 'radial-gradient(circle, #431407, #000)',
  ]);
  // The kind label rides as the group: the swatch-well popover sections
  // gradient stops under it, after the color groups.
  assert.deepEqual(gradientRow.map((stop) => stop.group), ['Gradients', 'Gradients']);
});

test('gradientStops: the allowed path carries the scheme pair too', () => {
  const stops = gradientStops(guide, materials, { allowed: ['var(--swatch-sunset)', 'var(--swatch-hero_fade)', 'linear-gradient(45deg, #111, #999)'] });
  assert.deepEqual(stops.map((stop) => stop.paint), [
    'radial-gradient(circle, #f97316, #7c2d12)',
    'linear-gradient(180deg, #000, #fff)',
    'linear-gradient(45deg, #111, #999)',
  ]);
  // Curated swatch references pair exactly like unconstrained stops;
  // literal gradient functions never pair.
  assert.deepEqual(stops.map((stop) => stop.paintDark), [
    'radial-gradient(circle, #431407, #000)', null, null,
  ]);
});

test('gradientStops: no palette is the honest empty vocabulary', () => {
  assert.deepEqual(gradientStops({}, EMPTY_MATERIALS, null), []);
  assert.deepEqual(gradientStops(undefined, EMPTY_MATERIALS, undefined), []);
});

test('gradientStops: a bare allowed list closes the offer to its entries', () => {
  // The server's allowed check is verbatim and exhaustive: a swatch
  // reference outside the list would only produce rejections, so it never
  // offers. Non-gradient entries (none, url patterns, colors) are
  // background-image vocabulary but not gradient stops.
  assert.deepEqual(gradientStops(guide, materials, { allowed: ['none', 'url(media:abc)'] }), []);
  const curated = gradientStops(guide, materials, { allowed: ['none', 'var(--swatch-sunset)'] });
  assert.deepEqual(curated.map((stop) => stop.value), ['var(--swatch-sunset)']);
  assert.equal(curated[0].label, 'Sunset');
  assert.equal(curated[0].paint, 'radial-gradient(circle, #f97316, #7c2d12)');
  // hero_fade exists in the palette but not in the list: never offered.
  assert.ok(!curated.some((stop) => stop.value.includes('hero_fade')));
});

test('gradientStops: a curated literal gradient offers, painted as itself', () => {
  const stops = gradientStops(guide, materials, { allowed: ['linear-gradient(45deg, #111, #999)'] });
  assert.equal(stops.length, 1);
  assert.equal(stops[0].value, 'linear-gradient(45deg, #111, #999)');
  assert.equal(stops[0].paint, 'linear-gradient(45deg, #111, #999)');
  // A layered entry is not ONE gradient; it derives nothing.
  assert.deepEqual(
    gradientStops(guide, materials, { allowed: ['linear-gradient(#000, #fff), url(media:abc)'] }),
    [],
  );
});

test('gradientStops: a scale constraint without allowed closes the offer', () => {
  // No token scale shape maps onto images; swatches never extend a
  // scale-locked space. Allowed entries beside the scale still offer.
  assert.deepEqual(gradientStops(guide, materials, { scale: 'color' }), []);
  const beside = gradientStops(guide, materials, { scale: 'color', allowed: ['var(--swatch-hero_fade)'] });
  assert.deepEqual(beside.map((stop) => stop.value), ['var(--swatch-hero_fade)']);
});

test('gradientStops: equivalent spellings collapse into the first stop', () => {
  const stops = gradientStops(guide, materials, {
    allowed: ['var(--swatch-sunset)', 'VAR(--swatch-sunset)'],
  });
  assert.equal(stops.length, 1);
  assert.equal(stops[0].value, 'var(--swatch-sunset)');
});

test('singleGradient: one chip-ownable layer answers, everything else null', () => {
  // A literal gradient function, any type, repeating included.
  assert.equal(singleGradient(guide, materials, ' linear-gradient(#000, #fff) '), 'linear-gradient(#000, #fff)');
  assert.equal(
    singleGradient(guide, materials, 'repeating-radial-gradient(circle, #111 0, #999 10px)'),
    'repeating-radial-gradient(circle, #111 0, #999 10px)',
  );
  // One var() naming a gradient-kind swatch the guide knows.
  assert.equal(singleGradient(guide, materials, 'var(--swatch-hero_fade)'), 'var(--swatch-hero_fade)');
  // Layered, media, url, color-kind and unknown references: occupancy the
  // color widgets cannot represent.
  assert.equal(singleGradient(guide, materials, 'linear-gradient(#000, #fff), url(media:abc)'), null);
  assert.equal(singleGradient(guide, materials, 'url(media:1b671a64-40d5-491e-99b0-da01ff1f3341)'), null);
  assert.equal(singleGradient(guide, materials, 'url("/photo.jpg")'), null);
  assert.equal(singleGradient(guide, materials, 'var(--swatch-brand_red)'), null);
  assert.equal(singleGradient(guide, materials, 'var(--swatch-unminted)'), null);
  assert.equal(singleGradient(guide, materials, 'none'), null);
  assert.equal(singleGradient(guide, materials, ''), null);
  assert.equal(singleGradient(guide, materials, null), null);
});

test('backgroundState: unset is honestly nothing', () => {
  assert.deepEqual(
    backgroundState(guide, materials, {}, colorRow, gradientRow),
    { selected: null, custom: false, value: null, gradient: null, literal: null },
  );
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-color': '', 'background-image': '' }, colorRow, gradientRow),
    { selected: null, custom: false, value: null, gradient: null, literal: null },
  );
});

test('backgroundState: a lone background-color reads through the color stops', () => {
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-color': 'var(--swatch-brand_red)' }, colorRow, gradientRow),
    { selected: 'var(--swatch-brand_red)', custom: false, value: null, gradient: null, literal: null },
  );
  // Off-palette colors are the explicit custom pill, never "nothing set".
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-color': '#b91c1c' }, colorRow, gradientRow),
    { selected: null, custom: true, value: '#b91c1c', gradient: null, literal: '#b91c1c' },
  );
});

test('backgroundState: a gradient-swatch reference lights its gradient stop', () => {
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-image': 'var(--swatch-hero_fade)' }, colorRow, gradientRow),
    { selected: 'var(--swatch-hero_fade)', custom: false, value: null, gradient: 'var(--swatch-hero_fade)', literal: null },
  );
  // Spelling variants light the same stop; custom property names are
  // lowercase by minting, so the normalized match is honest.
  assert.equal(
    backgroundState(guide, materials, { 'background-image': ' VAR(--swatch-sunset) ' }, colorRow, gradientRow).selected,
    'var(--swatch-sunset)',
  );
});

test('backgroundState round trip: every stop re-reads its own canonical write', () => {
  colorRow.forEach((stop) => {
    assert.equal(
      backgroundState(guide, materials, { 'background-color': stop.value }, colorRow, gradientRow).selected,
      stop.id,
      `${stop.id} does not re-read its own value "${stop.value}"`,
    );
  });
  gradientRow.forEach((stop) => {
    assert.equal(
      backgroundState(guide, materials, { 'background-image': stop.value }, colorRow, gradientRow).selected,
      stop.id,
      `${stop.id} does not re-read its own value "${stop.value}"`,
    );
  });
});

test('backgroundState: a chip-built literal gradient is represented, not custom', () => {
  // No stop claims it, but the chip does: `gradient` carries the layer
  // and the custom pill stays away.
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-image': 'linear-gradient(45deg, #111, #999)' }, colorRow, gradientRow),
    { selected: null, custom: false, value: null, gradient: 'linear-gradient(45deg, #111, #999)', literal: null },
  );
});

test('backgroundState: media, layered and foreign images are custom', () => {
  const media = backgroundState(guide, materials, { 'background-image': 'url(media:1b671a64-40d5-491e-99b0-da01ff1f3341)' }, colorRow, gradientRow);
  assert.equal(media.custom, true);
  assert.equal(media.gradient, null);
  const layered = backgroundState(guide, materials, { 'background-image': 'linear-gradient(#000, #fff), url(media:abc)' }, colorRow, gradientRow);
  assert.equal(layered.custom, true);
  assert.equal(layered.gradient, null);
  // A color-kind swatch reference in an image slot is foreign occupancy.
  const foreign = backgroundState(guide, materials, { 'background-image': 'var(--swatch-brand_red)' }, colorRow, gradientRow);
  assert.equal(foreign.custom, true);
});

test('mediaImageStop: exactly one media token derives the synthetic stop', () => {
  const uuid = '1b671a64-40d5-491e-99b0-da01ff1f3341';
  const token = `url('media:${uuid}')`;
  // The URL table answers: the grid chip's paint is the cover thumbnail
  // and the well can wear the bare URL.
  const resolved = mediaImageStop(token, { [uuid]: '/files/hero.png' });
  assert.equal(resolved.id, `media:${uuid}`);
  assert.equal(resolved.value, token);
  assert.deepEqual(resolved.matches, [token.toLowerCase()]);
  assert.equal(resolved.media, true);
  assert.equal(resolved.group, 'Image');
  assert.equal(resolved.thumbnail, '/files/hero.png');
  assert.equal(resolved.paint, 'url("/files/hero.png") center / cover');
  assert.equal(resolved.paintDark, null);
  // No answer (lost file, satellite pre-broadcast): thumbnail null, the
  // paint degrades to the honest checker — never a broken url() paint.
  const unresolved = mediaImageStop(token, {});
  assert.equal(unresolved.thumbnail, null);
  assert.match(unresolved.paint, /^repeating-conic-gradient/);
  // Not-exactly-one-token values derive nothing: they stay the custom
  // occupancy backgroundState already reads.
  assert.equal(mediaImageStop(`linear-gradient(#000, #fff), ${token}`, {}), null);
  assert.equal(mediaImageStop('linear-gradient(#000, #fff)', {}), null);
  assert.equal(mediaImageStop(undefined, {}), null);
});

test('backgroundState: the synthetic media stop lights as the media posture', () => {
  const uuid = '1b671a64-40d5-491e-99b0-da01ff1f3341';
  const token = `url('media:${uuid}')`;
  const mediaStop = mediaImageStop(token, { [uuid]: '/files/hero.png' });
  const offer = [...gradientRow, mediaStop];
  // A lone media token reads as its own lit stop through the EXISTING
  // match walk: represented, never checker-"?" custom, and its unpick
  // (the corners rule over the family) clears only what is set — here
  // background-image alone.
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-image': token }, colorRow, offer),
    { selected: `media:${uuid}`, custom: false, value: null, gradient: null, literal: null },
  );
  assert.deepEqual(backgroundPick({ 'background-image': token }, null), { 'background-image': null });
  // Collision honesty is untouched occupancy reading: a color beside the
  // token, and the text recipe's marker, both stay custom with no lit
  // stop even though the synthetic stop sits in the table.
  const beside = backgroundState(
    guide,
    materials,
    { 'background-color': 'var(--swatch-brand_red)', 'background-image': token },
    colorRow,
    offer,
  );
  assert.equal(beside.selected, null);
  assert.equal(beside.custom, true);
  const clipped = backgroundState(
    guide,
    materials,
    { 'background-image': token },
    colorRow,
    offer,
    { 'background-image': token, 'background-clip': 'text' },
  );
  assert.equal(clipped.selected, null);
  assert.equal(clipped.custom, true);
});

test('backgroundState: the seed\'s companions are ignored by the state read', () => {
  // The widget owns size/repeat/position (the seed's writes ride its
  // values), but the state faces on the pair alone: seeded values never
  // flip the lit media stop custom, an advanced-mode tweak of one keeps
  // the well lit, and the custom pill's raw compound never picks them up.
  const uuid = '1b671a64-40d5-491e-99b0-da01ff1f3341';
  const token = `url('media:${uuid}')`;
  const offer = [...gradientRow, mediaImageStop(token, { [uuid]: '/files/hero.png' })];
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-image': token, ...MEDIA_COMPANIONS }, colorRow, offer),
    { selected: `media:${uuid}`, custom: false, value: null, gradient: null, literal: null },
  );
  assert.equal(
    backgroundState(
      guide,
      materials,
      { 'background-image': token, ...MEDIA_COMPANIONS, 'background-size': 'contain' },
      colorRow,
      offer,
    ).selected,
    `media:${uuid}`,
  );
  // Companions without any pair member are honestly nothing: inert
  // sizing knobs never light or custom the well.
  assert.deepEqual(
    backgroundState(guide, materials, { ...MEDIA_COMPANIONS }, colorRow, offer),
    { selected: null, custom: false, value: null, gradient: null, literal: null },
  );
  // A custom pair still reads its raw compound WITHOUT the companions.
  assert.equal(
    backgroundState(
      guide,
      materials,
      { 'background-color': 'rebeccapurple', 'background-image': token, ...MEDIA_COMPANIONS },
      colorRow,
      offer,
    ).value,
    `rebeccapurple ${token}`,
  );
});

test('backgroundState: a color AND an image is a compound no stop claims', () => {
  // Both halves individually representable, together custom: a single
  // stop's pick writes one property, so lighting either would lie.
  const state = backgroundState(
    guide,
    materials,
    { 'background-color': 'var(--swatch-brand_red)', 'background-image': 'var(--swatch-hero_fade)' },
    colorRow,
    gradientRow,
  );
  assert.equal(state.selected, null);
  assert.equal(state.custom, true);
  assert.equal(state.value, 'var(--swatch-brand_red) var(--swatch-hero_fade)');
  // The chip still knows what background-image holds.
  assert.equal(state.gradient, 'var(--swatch-hero_fade)');
});

test('backgroundState: a swatch reference outside a closed vocabulary keeps the chip', () => {
  // The offer closed (bare allowed list without it), but the committed
  // value IS one gradient the chip represents: no stop, no custom pill.
  const closed = gradientStops(guide, materials, { allowed: ['none'] });
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-image': 'var(--swatch-hero_fade)' }, colorRow, closed),
    { selected: null, custom: false, value: null, gradient: 'var(--swatch-hero_fade)', literal: null },
  );
});

test('backgroundState: the text recipe\'s occupancy reads as the collision pill', () => {
  // The other direction of the cross-widget honesty: the recipe's
  // background-clip: text (read from the posed entry, not the pair) means
  // nothing here paints the surface — the gradient stop that would match
  // background-image must NOT light, or its unpick would strand a partial
  // recipe (invisible text). The chip still knows what the image holds.
  const siblings = {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  };
  const state = backgroundState(guide, materials, { 'background-image': 'var(--swatch-hero_fade)' }, colorRow, gradientRow, siblings);
  assert.equal(state.selected, null);
  assert.equal(state.custom, true);
  assert.equal(state.gradient, 'var(--swatch-hero_fade)');
  // Keyword case-insensitive, like every recipe read.
  assert.equal(
    backgroundState(guide, materials, { 'background-image': 'var(--swatch-hero_fade)' }, colorRow, gradientRow, { ...siblings, 'background-clip': 'TEXT' }).custom,
    true,
  );
  // A clipped background-color is just as invisible as a clipped image.
  assert.equal(
    backgroundState(guide, materials, { 'background-color': '#b91c1c' }, colorRow, gradientRow, { 'background-color': '#b91c1c', 'background-clip': 'text' }).custom,
    true,
  );
  // A non-text clip does not repurpose the background: the stop lights.
  assert.equal(
    backgroundState(guide, materials, { 'background-image': 'var(--swatch-hero_fade)' }, colorRow, gradientRow, { 'background-image': 'var(--swatch-hero_fade)', 'background-clip': 'padding-box' }).selected,
    'var(--swatch-hero_fade)',
  );
});

test('textGradientState: unset is honestly nothing', () => {
  assert.deepEqual(
    textGradientState(guide, materials, {}, colorRow, gradientRow),
    { selected: null, custom: false, value: null, gradient: null, literal: null },
  );
});

test('textGradientState: the plain color row when no recipe property is set', () => {
  assert.deepEqual(
    textGradientState(guide, materials, { color: 'var(--color-accent)' }, colorRow, gradientRow),
    { selected: 'var(--color-accent)', custom: false, value: null, gradient: null, literal: null },
  );
  assert.deepEqual(
    textGradientState(guide, materials, { color: 'rebeccapurple' }, colorRow, gradientRow),
    { selected: null, custom: true, value: 'rebeccapurple', gradient: null, literal: 'rebeccapurple' },
  );
  // A lone transparent is NOT gradient text; it is an off-palette color.
  assert.equal(
    textGradientState(guide, materials, { color: 'transparent' }, colorRow, gradientRow).custom,
    true,
  );
});

test('textGradientState: the exact recipe with a swatch reference lights its stop', () => {
  const values = {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  };
  assert.deepEqual(
    textGradientState(guide, materials, values, colorRow, gradientRow),
    { selected: 'var(--swatch-hero_fade)', custom: false, value: null, gradient: 'var(--swatch-hero_fade)', literal: null },
  );
});

test('textGradientState: recipe keywords recognize case-insensitively', () => {
  // CSS keyword comparison is case-insensitive (normalizeValue), so an
  // AI's or advanced-mode respelling of the same recipe still recognizes.
  const values = {
    color: ' Transparent ',
    'background-image': 'VAR(--swatch-sunset)',
    'background-clip': 'TEXT',
  };
  assert.equal(textGradientState(guide, materials, values, colorRow, gradientRow).selected, 'var(--swatch-sunset)');
});

test('textGradientState: a literal-gradient recipe is represented through the chip', () => {
  const values = {
    color: 'transparent',
    'background-image': 'linear-gradient(90deg, #f00, #00f)',
    'background-clip': 'text',
  };
  assert.deepEqual(
    textGradientState(guide, materials, values, colorRow, gradientRow),
    { selected: null, custom: false, value: null, gradient: 'linear-gradient(90deg, #f00, #00f)', literal: null },
  );
});

test('textGradientState: partial recipes are EXPLICITLY custom', () => {
  // Gradient + clip without the transparent color: the text still paints
  // its own color, not the gradient. Custom, never a lit stop.
  const noColor = textGradientState(guide, materials, {
    color: 'red',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  }, colorRow, gradientRow);
  assert.equal(noColor.selected, null);
  assert.equal(noColor.custom, true);
  assert.equal(noColor.value, 'red var(--swatch-hero_fade) text');
  // Gradient + transparent without the clip paints a plain background
  // behind invisible text.
  assert.equal(textGradientState(guide, materials, {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
  }, colorRow, gradientRow).custom, true);
  // A bare clip is a fragment of the recipe.
  assert.equal(
    textGradientState(guide, materials, { 'background-clip': 'text' }, colorRow, gradientRow).custom,
    true,
  );
});

test('textGradientState: media or layered images never recognize as the recipe', () => {
  const state = textGradientState(guide, materials, {
    color: 'transparent',
    'background-image': 'url(media:1b671a64-40d5-491e-99b0-da01ff1f3341)',
    'background-clip': 'text',
  }, colorRow, gradientRow);
  assert.equal(state.custom, true);
  assert.equal(state.gradient, null);
});

test('textGradientState: foreign background-clip values are custom occupancy', () => {
  // border-box is a legal curated keyword an advanced write may commit;
  // the widget owns the property, cannot represent the compound, and says
  // so.
  assert.equal(textGradientState(guide, materials, {
    color: 'var(--color-accent)',
    'background-clip': 'border-box',
  }, colorRow, gradientRow).custom, true);
});

test('textGradientState: the background widget\'s gradient reads as the collision pill', () => {
  // The cross-widget collision honesty: a background gradient occupies
  // background-image without the recipe, so the text widget shows custom
  // even though the color alone would light a stop.
  const state = textGradientState(guide, materials, {
    color: 'var(--color-accent)',
    'background-image': 'var(--swatch-hero_fade)',
  }, colorRow, gradientRow);
  assert.equal(state.selected, null);
  assert.equal(state.custom, true);
});

test('textGradientBatch: a pick writes the whole recipe in one batch', () => {
  assert.deepEqual(textGradientBatch('var(--swatch-hero_fade)'), {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  });
  assert.deepEqual(textGradientBatch('linear-gradient(90deg, #f00, #00f)'), {
    color: 'transparent',
    'background-image': 'linear-gradient(90deg, #f00, #00f)',
    'background-clip': 'text',
  });
});

test('textGradientBatch: unpick clears every property the pick wrote', () => {
  assert.deepEqual(textGradientBatch(null), {
    color: null,
    'background-image': null,
    'background-clip': null,
  });
});

test('recipe round trip: what the batch writes, the state recognizes', () => {
  // The contract between the write and read halves: a stop pick's batch
  // must light that stop, and a builder commit's batch must land on the
  // chip-represented state.
  gradientRow.forEach((stop) => {
    const state = textGradientState(guide, materials, textGradientBatch(stop.value), colorRow, gradientRow);
    assert.equal(state.selected, stop.id, `${stop.id} does not re-read its own batch`);
    assert.equal(state.custom, false);
  });
  const literal = textGradientState(
    guide,
    materials,
    textGradientBatch('conic-gradient(from 45deg, #111, #999)'),
    colorRow,
    gradientRow,
  );
  assert.equal(literal.custom, false);
  assert.equal(literal.gradient, 'conic-gradient(from 45deg, #111, #999)');
});

test('backgroundPick: a pick writes only its own property on a clean pair', () => {
  // Nothing else set: the batch never carries a null the click did not
  // need (no phantom unsets in the op).
  assert.deepEqual(backgroundPick({}, { value: 'var(--swatch-brand_red)' }, false), {
    'background-color': 'var(--swatch-brand_red)',
  });
  assert.deepEqual(backgroundPick({}, { value: 'var(--swatch-hero_fade)' }, true), {
    'background-image': 'var(--swatch-hero_fade)',
  });
});

test('backgroundPick: picking over the occupied other half is an explicit replacement', () => {
  // Segments' custom-pill promise: a pick over an occupied pair replaces
  // the whole background in ONE op; leaving the other half behind would
  // commit a both-set compound no stop represents.
  assert.deepEqual(
    backgroundPick({ 'background-image': "url('media:abc')" }, { value: '#b91c1c' }, false),
    { 'background-color': '#b91c1c', 'background-image': null },
  );
  assert.deepEqual(
    backgroundPick({ 'background-color': '#fff' }, { value: 'var(--swatch-sunset)' }, true),
    { 'background-image': 'var(--swatch-sunset)', 'background-color': null },
  );
});

test('backgroundPick: unpick clears only what is set', () => {
  // The corners rule: never reset what a click didn't say.
  assert.deepEqual(backgroundPick({ 'background-color': '#fff' }, null), {
    'background-color': null,
  });
  assert.deepEqual(backgroundPick({ 'background-image': 'var(--swatch-hero_fade)' }, null), {
    'background-image': null,
  });
  assert.deepEqual(
    backgroundPick({ 'background-color': '#fff', 'background-image': 'var(--swatch-hero_fade)' }, null),
    { 'background-color': null, 'background-image': null },
  );
  assert.deepEqual(backgroundPick({}, null), {});
});

test('backgroundPick: unpick clears the media seed\'s companion family', () => {
  // The unpick-clears-family decision: the corners rule grows to the
  // seeded companions, and clears them even when advanced-mode edited
  // since the seed (contain is not the seeded cover) — companions are
  // inert without an image, so clearing is visually safe and keeps the
  // map clean.
  const token = "url('media:1b671a64-40d5-491e-99b0-da01ff1f3341')";
  assert.deepEqual(
    backgroundPick({ 'background-image': token, ...MEDIA_COMPANIONS }, null),
    {
      'background-image': null,
      'background-size': null,
      'background-repeat': null,
      'background-position': null,
    },
  );
  assert.deepEqual(
    backgroundPick({ 'background-image': token, 'background-size': 'contain' }, null),
    { 'background-image': null, 'background-size': null },
  );
  // The corners rule still holds inside the family: an unset companion
  // never earns a phantom null.
  assert.deepEqual(
    backgroundPick({ 'background-color': '#fff', 'background-repeat': 'repeat-x' }, null),
    { 'background-color': null, 'background-repeat': null },
  );
});

test('backgroundPick: a pick clears the companion family too', () => {
  // The pick-clears-family decision: a stop's represented state carries
  // no size/repeat/position, so a pick nulls set companions for the
  // same reason it nulls the other pair half — image -> color would
  // strand them inert, image -> gradient would let them silently
  // reshape the gradient.
  const token = "url('media:1b671a64-40d5-491e-99b0-da01ff1f3341')";
  assert.deepEqual(
    backgroundPick({ 'background-image': token, ...MEDIA_COMPANIONS }, { value: '#b91c1c' }, false),
    {
      'background-color': '#b91c1c',
      'background-image': null,
      'background-size': null,
      'background-repeat': null,
      'background-position': null,
    },
  );
  assert.deepEqual(
    backgroundPick({ 'background-image': token, ...MEDIA_COMPANIONS }, { value: 'var(--swatch-sunset)' }, true),
    {
      'background-image': 'var(--swatch-sunset)',
      'background-size': null,
      'background-repeat': null,
      'background-position': null,
    },
  );
  // Advanced-mode edits since the seed clear too (the unpick stance,
  // grown to picks): the family rides the replaced background.
  assert.deepEqual(
    backgroundPick({ 'background-image': token, 'background-size': 'contain' }, { value: '#b91c1c' }, false),
    { 'background-color': '#b91c1c', 'background-image': null, 'background-size': null },
  );
  // The corners rule still holds: a clean pick carries no phantom
  // companion nulls (the clean-pair test's guarantee, restated beside
  // the family growth).
  assert.deepEqual(backgroundPick({ 'background-color': '#fff' }, { value: '#b91c1c' }, false), {
    'background-color': '#b91c1c',
  });
});

test('backgroundPick: a pick over the text recipe clears the recipe\'s set members', () => {
  // Explicit replacement over the trio, the textPick symmetry: with
  // background-clip: text in force every background clips to the glyphs,
  // so leaving color/background-clip behind would commit an invisible
  // half-compound no stop represents.
  const siblings = {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  };
  assert.deepEqual(
    backgroundPick({ 'background-image': 'var(--swatch-hero_fade)' }, { value: '#b91c1c' }, false, siblings),
    { 'background-color': '#b91c1c', 'background-image': null, color: null, 'background-clip': null },
  );
  assert.deepEqual(
    backgroundPick({ 'background-image': 'var(--swatch-hero_fade)' }, { value: 'var(--swatch-sunset)' }, true, siblings),
    { 'background-image': 'var(--swatch-sunset)', color: null, 'background-clip': null },
  );
  // Only SET recipe members clear (the corners rule): a container-grade
  // partial (clip without color) never manufactures a phantom color unset
  // the target may not even declare.
  assert.deepEqual(
    backgroundPick({ 'background-image': 'var(--swatch-hero_fade)' }, { value: '#b91c1c' }, false, {
      'background-image': 'var(--swatch-hero_fade)',
      'background-clip': 'text',
    }),
    { 'background-color': '#b91c1c', 'background-image': null, 'background-clip': null },
  );
  // Without the marker, siblings change nothing.
  assert.deepEqual(
    backgroundPick({ 'background-image': 'var(--swatch-hero_fade)' }, { value: '#b91c1c' }, false, {
      'background-image': 'var(--swatch-hero_fade)',
      'background-clip': 'padding-box',
    }),
    { 'background-color': '#b91c1c', 'background-image': null },
  );
});

test('backgroundPick round trip: what a pick writes, the state lights', () => {
  colorRow.forEach((stop) => {
    const state = backgroundState(guide, materials, backgroundPick({ 'background-image': "url('media:abc')" }, stop, false), colorRow, gradientRow);
    assert.equal(state.selected, stop.id, `${stop.id} does not re-read its own pick`);
    assert.equal(state.custom, false);
  });
  gradientRow.forEach((stop) => {
    const state = backgroundState(guide, materials, backgroundPick({ 'background-color': '#fff' }, stop, true), colorRow, gradientRow);
    assert.equal(state.selected, stop.id, `${stop.id} does not re-read its own pick`);
    assert.equal(state.custom, false);
  });
});

test('textPick: a color pick writes only color on a clean row', () => {
  // Nothing else set: the batch never carries a null the click did not
  // need (no phantom unsets in the op).
  assert.deepEqual(textPick({}, { value: 'var(--color-accent)' }, false), {
    color: 'var(--color-accent)',
  });
});

test('textPick: a gradient pick is the whole recipe batch', () => {
  // Delegates to textGradientBatch: three positive writes replace any
  // prior state without a null in sight.
  assert.deepEqual(
    textPick({ color: 'red' }, { value: 'var(--swatch-hero_fade)' }, true),
    {
      color: 'transparent',
      'background-image': 'var(--swatch-hero_fade)',
      'background-clip': 'text',
    },
  );
});

test('textPick: a color pick over occupancy is an explicit replacement', () => {
  // Over the recipe: all three replaced in ONE op, because leaving
  // background-image or background-clip behind would commit a partial
  // recipe no color stop represents.
  assert.deepEqual(
    textPick({
      color: 'transparent',
      'background-image': 'var(--swatch-hero_fade)',
      'background-clip': 'text',
    }, { value: 'var(--color-accent)' }, false),
    { color: 'var(--color-accent)', 'background-image': null, 'background-clip': null },
  );
  // Over the background widget's collision occupancy: the same
  // replacement, but only the set property clears (no phantom clip
  // unset).
  assert.deepEqual(
    textPick({ 'background-image': 'var(--swatch-sunset)' }, { value: '#b91c1c' }, false),
    { color: '#b91c1c', 'background-image': null },
  );
});

test('textPick: unpick clears only what is set', () => {
  // The corners rule over the trio: a plain color unpick never touches
  // the recipe properties, and a recipe unpick clears all three.
  assert.deepEqual(textPick({ color: 'var(--color-accent)' }, null), { color: null });
  assert.deepEqual(
    textPick({
      color: 'transparent',
      'background-image': 'var(--swatch-hero_fade)',
      'background-clip': 'text',
    }, null),
    { color: null, 'background-image': null, 'background-clip': null },
  );
  assert.deepEqual(textPick({}, null), {});
});

test('textPick round trip: what a pick writes, the state lights', () => {
  const recipe = {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  };
  colorRow.forEach((stop) => {
    const state = textGradientState(guide, materials, { ...recipe, ...textPick(recipe, stop, false) }, colorRow, gradientRow);
    assert.equal(state.selected, stop.id, `${stop.id} does not re-read its own pick`);
    assert.equal(state.custom, false);
  });
  gradientRow.forEach((stop) => {
    const state = textGradientState(guide, materials, textPick({ color: 'red' }, stop, true), colorRow, gradientRow);
    assert.equal(state.selected, stop.id, `${stop.id} does not re-read its own pick`);
    assert.equal(state.custom, false);
  });
});

test('gradientSwatchOf: the chip gate matches one gradient-swatch var(), case-honest', () => {
  // The edit-in-place gate: exactly one var() naming a gradient-kind
  // swatch, the var head case-insensitive per CSS (custom property NAMES
  // stay case-sensitive).
  assert.equal(gradientSwatchOf(guide, materials, 'var(--swatch-hero_fade)')?.name, 'hero_fade');
  assert.equal(gradientSwatchOf(guide, materials, '  VAR(--swatch-hero_fade)  ')?.name, 'hero_fade');
  // A color-kind swatch, a foreign var(), a literal, layers: never a gate.
  assert.equal(gradientSwatchOf(guide, materials, 'var(--swatch-brand_red)'), null);
  assert.equal(gradientSwatchOf(guide, materials, 'var(--unknown)'), null);
  assert.equal(gradientSwatchOf(guide, materials, 'linear-gradient(180deg, #000, #fff)'), null);
  assert.equal(gradientSwatchOf(guide, materials, "var(--swatch-hero_fade), url('media:abc')"), null);
  assert.equal(gradientSwatchOf(guide, materials, null), null);
});

// --- mediaCompanions: the basic media pick's pure seed model. ---

// The guide half the seed reads: the group table for the property
// definitions; the sanction itself is the served node's grant.
const seedGuide = {
  groups: {
    background: {
      label: 'Background',
      properties: {
        'background-color': { type: 'color' },
        'background-image': { type: 'text' },
        'background-position': { type: 'dimension' },
        'background-size': { type: 'dimension' },
        'background-repeat': { type: 'keyword' },
      },
    },
    typography: { label: 'Typography', properties: { color: { type: 'color' } } },
  },
};

const BACKGROUND = ['background-color', 'background-image', 'background-position', 'background-size', 'background-repeat'];
const seedDeclaration = { grants: { background: BACKGROUND } };

test('mediaCompanions: a clean stack seeds the whole best-guess set', () => {
  // Nothing answers size/repeat/position, the declaration sanctions the
  // background group unconstrained: every companion seeds, canonical
  // spellings, and the constant is the single source of what they are.
  assert.deepEqual(mediaCompanions(seedGuide, seedDeclaration, null, 'default'), {
    'background-size': 'cover',
    'background-repeat': 'no-repeat',
    'background-position': 'center',
  });
  assert.deepEqual(mediaCompanions(seedGuide, seedDeclaration, null), MEDIA_COMPANIONS);
  // Unrelated styling in the stack changes nothing.
  assert.deepEqual(
    mediaCompanions(seedGuide, seedDeclaration, { default: { 'background-color': 'red' } }),
    MEDIA_COMPANIONS,
  );
});

test('mediaCompanions: the delivered entry answers at the write condition or default', () => {
  // A default-compound value answers everywhere (it cascades under every
  // posed compound), so its companion never seeds.
  assert.deepEqual(
    mediaCompanions(seedGuide, seedDeclaration, { default: { 'background-size': 'contain' } }),
    { 'background-repeat': 'no-repeat', 'background-position': 'center' },
  );
  // Posed at md: an md value answers there too.
  assert.deepEqual(
    mediaCompanions(seedGuide, seedDeclaration, { md: { 'background-repeat': 'repeat-x' } }, 'md'),
    { 'background-size': 'cover', 'background-position': 'center' },
  );
  // A value at an OTHER condition answers nothing at the write
  // condition: it neither delivers there nor is buried by the seed.
  assert.deepEqual(
    mediaCompanions(seedGuide, seedDeclaration, { md: { 'background-size': 'contain' } }, 'default'),
    MEDIA_COMPANIONS,
  );
});

test('mediaCompanions: a compound pose answered by a single-condition block seeds nothing for it', () => {
  // Writing at md+dark, an md block delivers there (a subset compound
  // cascades under the posed one), so its companion is answered and the
  // seed would only bury it; the cascade read, never "the exact
  // compound or default".
  const guide = { ...seedGuide, conditions: { md: { label: 'Medium', axis: 'width' }, dark: { label: 'Dark', axis: 'scheme' } } };
  assert.deepEqual(
    mediaCompanions(guide, seedDeclaration, { md: { 'background-size': 'contain' } }, 'md+dark'),
    { 'background-repeat': 'no-repeat', 'background-position': 'center' },
  );
  // A skin's subset block rides the same delivered entry and answers
  // the same way.
  assert.deepEqual(
    mediaCompanions(guide, seedDeclaration, { dark: { 'background-position': 'top' } }, 'md+dark'),
    { 'background-size': 'cover', 'background-repeat': 'no-repeat' },
  );
  // A sibling single condition is not a subset: it answers nothing.
  assert.deepEqual(
    mediaCompanions(guide, seedDeclaration, { dark: { 'background-size': 'contain' } }, 'md'),
    MEDIA_COMPANIONS,
  );
});

test('mediaCompanions: a skin default is a layer of the delivered entry and answers the same way', () => {
  // The skin is the weakest layer: a seed over its value would override
  // site styling the pick never asked to change, so the companion skips.
  // It rides the stack's delivered entry like every other layer (the
  // winner per stored condition), so one read answers for all of them.
  assert.deepEqual(
    mediaCompanions(seedGuide, seedDeclaration, { default: { 'background-position': 'top' } }),
    { 'background-size': 'cover', 'background-repeat': 'no-repeat' },
  );
  assert.deepEqual(
    mediaCompanions(seedGuide, seedDeclaration, { md: { 'background-size': 'contain' } }, 'md'),
    { 'background-repeat': 'no-repeat', 'background-position': 'center' },
  );
  // A fully answered stack seeds nothing.
  assert.deepEqual(
    mediaCompanions(
      seedGuide,
      seedDeclaration,
      { default: { 'background-size': 'contain', 'background-repeat': 'round', 'background-position': 'top left' } },
    ),
    {},
  );
});

test('mediaCompanions: the seed filters to roster-granted properties', () => {
  // The served node must grant the property (the strict validator's
  // not-declared rule, the roster's read); an ungranted background family
  // means no seed at all, and no node means no assumption.
  assert.deepEqual(mediaCompanions(seedGuide, { grants: { typography: ['color'] } }, null), {});
  assert.deepEqual(mediaCompanions(seedGuide, null, null), {});
  assert.deepEqual(mediaCompanions(seedGuide, {}, null), {});
  // A narrowed grant sanctions exactly its names.
  assert.deepEqual(mediaCompanions(seedGuide, { grants: { background: ['background-size'] } }, null), { 'background-size': 'cover' });
});

test('mediaCompanions: constraints gate each companion, allowed spellings adopted', () => {
  // An allowed list is the complete value space: a normalized match
  // adopts the guide's EXACT spelling (allowed checks are verbatim on
  // both validators), no match means that companion never seeds.
  const constrained = {
    grants: { background: BACKGROUND },
    valueSpaces: {
      'background-size': { allowed: ['Cover', 'contain'] },
      'background-repeat': { allowed: ['repeat'] },
    },
  };
  assert.deepEqual(mediaCompanions(seedGuide, constrained, null), {
    'background-size': 'Cover',
    'background-position': 'center',
  });
  // A scale constraint closes the property to minted tokens and no
  // keyword is one; an allowed match beside it still seeds.
  const scaled = {
    grants: { background: BACKGROUND },
    valueSpaces: {
      'background-position': { scale: 'spacing' },
      'background-size': { scale: 'spacing', allowed: ['cover'] },
    },
  };
  assert.deepEqual(mediaCompanions(seedGuide, scaled, null), {
    'background-size': 'cover',
    'background-repeat': 'no-repeat',
  });
});

test('mediaPickReplacements: a media pick replaces the set color half', () => {
  // backgroundPick's explicit-replacement rule at the media gesture: a
  // set background-color clears in the same batch as the token, never
  // buried as a both-set compound the well reads custom.
  assert.deepEqual(
    mediaPickReplacements({ 'background-color': 'var(--swatch-brand_red)' }),
    { 'background-color': null },
  );
  // Nothing set, nothing cleared (the corners rule): no phantom nulls.
  assert.deepEqual(mediaPickReplacements({}), {});
  assert.deepEqual(mediaPickReplacements({ 'background-image': "url('media:abc')" }), {});
  // Standing companions are deliberately NOT cleared: a photo swap keeps
  // the art direction; the fresh seed skips what is already answered.
  assert.deepEqual(mediaPickReplacements({ 'background-size': 'contain' }), {});
});

test('mediaPickReplacements: a media pick over the text recipe clears its set members', () => {
  // The backgroundPick symmetry: with background-clip: text in force the
  // image would clip to the glyphs, so the recipe's set members clear
  // too — only the SET ones (a partial recipe earns no phantom unsets).
  const siblings = {
    color: 'transparent',
    'background-image': 'var(--swatch-hero_fade)',
    'background-clip': 'text',
  };
  assert.deepEqual(
    mediaPickReplacements({ 'background-color': '#fff' }, siblings),
    { 'background-color': null, color: null, 'background-clip': null },
  );
  assert.deepEqual(
    mediaPickReplacements({}, { 'background-clip': 'text' }),
    { 'background-clip': null },
  );
});

test('backgroundState / textGradientState: a lone inline color rides as literal, compounds and unknown references do not', () => {
  // The well's painted literal face and the color chip's edit target:
  // only a LONE color the grammar calls literal on the color property.
  // A both-set pair, the text recipe, and a var() the roster lacks keep
  // literal null (the honest "?" face).
  assert.equal(backgroundState(guide, materials, { 'background-color': 'rgb(1 2 3 / 0.5)' }, colorRow, gradientRow).literal, 'rgb(1 2 3 / 0.5)');
  assert.equal(backgroundState(guide, materials, { 'background-color': ' #abc ' }, colorRow, gradientRow).literal, '#abc');
  assert.equal(backgroundState(guide, materials, { 'background-color': 'var(--swatch-nope)' }, colorRow, gradientRow).literal, null);
  assert.equal(backgroundState(guide, materials, { 'background-color': '#abc', 'background-image': 'var(--swatch-hero_fade)' }, colorRow, gradientRow).literal, null);
  assert.equal(backgroundState(guide, materials, { 'background-color': '#abc' }, colorRow, gradientRow, { 'background-clip': 'text' }).literal, null);
  assert.equal(textGradientState(guide, materials, { color: 'transparent' }, colorRow, gradientRow).literal, 'transparent');
  assert.equal(textGradientState(guide, materials, { color: 'var(--nope)' }, colorRow, gradientRow).literal, null);
  assert.equal(textGradientState(guide, materials, { color: '#abc', 'background-clip': 'text' }, colorRow, gradientRow).literal, null);
});

test('gradientImageStop: a lone gradient literal no offered stop claims derives the synthetic stop', () => {
  const literal = 'linear-gradient(to right, #1b9ae4, #116392)';
  const stop = gradientImageStop(guide, materials, literal, gradientRow);
  assert.equal(stop.id, 'gradient:literal');
  assert.equal(stop.value, literal);
  assert.deepEqual(stop.matches, [literal.toLowerCase()]);
  assert.equal(stop.gradient, true);
  assert.equal(stop.group, 'Gradient');
  assert.equal(stop.paint, literal);
  assert.equal(stop.paintDark, null);
  // Nested swatch references paint through the face rule.
  const nested = gradientImageStop(guide, materials, 'linear-gradient(var(--swatch-brand_red), #fff)', gradientRow);
  assert.equal(nested.paint, 'linear-gradient(#b91c1c, #fff)');
  // A value an offered stop already claims derives nothing: that stop
  // lights instead (a gradient swatch's var(), an allowed literal).
  assert.equal(gradientImageStop(guide, materials, 'var(--swatch-hero_fade)', gradientRow), null);
  assert.equal(gradientImageStop(guide, materials, literal, [{ matches: [literal.toLowerCase()] }]), null);
  // Not-exactly-one-gradient values derive nothing: they stay the custom
  // occupancy the state reads already answer.
  assert.equal(gradientImageStop(guide, materials, `${literal}, url('media:1b671a64-40d5-491e-99b0-da01ff1f3341')`, gradientRow), null);
  assert.equal(gradientImageStop(guide, materials, "url('media:1b671a64-40d5-491e-99b0-da01ff1f3341')", gradientRow), null);
  assert.equal(gradientImageStop(guide, materials, '#fff', gradientRow), null);
  assert.equal(gradientImageStop(guide, materials, undefined, gradientRow), null);
});

test('backgroundState: the synthetic gradient stop lights and its unpick clears the image', () => {
  const literal = 'linear-gradient(to right, #1b9ae4, #116392)';
  const offer = [...gradientRow, gradientImageStop(guide, materials, literal, gradientRow)];
  assert.deepEqual(
    backgroundState(guide, materials, { 'background-image': literal }, colorRow, offer),
    { selected: 'gradient:literal', custom: false, value: null, gradient: literal, literal: null },
  );
  assert.deepEqual(backgroundPick({ 'background-image': literal }, null), { 'background-image': null });
  // A color beside it stays the honest custom compound, lit stop or not.
  const beside = backgroundState(guide, materials, { 'background-color': '#fff', 'background-image': literal }, colorRow, offer);
  assert.equal(beside.selected, null);
  assert.equal(beside.custom, true);
});

test('textGradientState: the recipe over a lone literal lights the synthetic stop', () => {
  const literal = 'linear-gradient(to right, #1b9ae4, #116392)';
  const offer = [...gradientRow, gradientImageStop(guide, materials, literal, gradientRow)];
  const recipe = { color: 'transparent', 'background-image': literal, 'background-clip': 'text' };
  assert.deepEqual(
    textGradientState(guide, materials, recipe, colorRow, offer),
    { selected: 'gradient:literal', custom: false, value: null, gradient: literal, literal: null },
  );
  assert.deepEqual(textPick(recipe, null), { color: null, 'background-image': null, 'background-clip': null });
});
