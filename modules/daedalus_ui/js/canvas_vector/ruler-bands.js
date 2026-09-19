/**
 * @file
 * The ruler's band vocabulary: pure, DOM-free so node can test it.
 *
 * Modules contribute width BANDS — {min?, max?, label, name}, inclusive px
 * edges, a missing min extending to zero and a missing max upward without
 * bound. daedalus_ui knows nothing about what the bands mean (that
 * vocabulary belongs to the contributor, e.g. daedalus_stylish's container
 * breakpoint conditions); this module only normalizes the contributed
 * entries and answers the geometric questions the ruler asks: where are
 * the band edges, which band contains a given width, and whether the
 * canvas counts as constrained at all. The space above every contributed
 * ceiling is deliberately bandless; for daedalus_stylish that is the
 * unconditioned base layer, which has no condition to contribute.
 */

/**
 * Normalizes contributed band entries.
 *
 * @param {Array} entries
 *   Candidate bands {min?, max?, label, name}. An entry with neither a
 *   finite positive min nor max is dropped (it bounds nothing).
 *
 * @return {Array}
 *   Clean bands, ascending. MAX_SAFE_INTEGER (not Infinity) backs the
 *   missing-max sort so two unbounded bands still compare stably.
 */
export const normalizeBands = (entries) => {
  const bands = [];
  (entries ?? []).forEach((entry) => {
    const min = Number(entry?.min);
    const max = Number(entry?.max);
    const band = {
      label: String(entry?.label ?? ''),
      name: String(entry?.name ?? ''),
    };
    if (Number.isFinite(min) && min > 0) {
      band.min = min;
    }
    if (Number.isFinite(max) && max > 0) {
      band.max = max;
    }
    if (band.min !== undefined || band.max !== undefined) {
      bands.push(band);
    }
  });
  return bands.sort(
    (a, b) =>
      (a.min ?? 0) - (b.min ?? 0) ||
      (a.max ?? Number.MAX_SAFE_INTEGER) - (b.max ?? Number.MAX_SAFE_INTEGER),
  );
};

/**
 * The px values where band edges fall — the ruler's tick positions.
 *
 * Every finite ceiling ticks. A finite floor ticks only when it does not
 * abut the previous band's ceiling (min === thatMax + 1 is the same
 * boundary already marked; two ticks 1px apart would just smear).
 *
 * @param {Array} bands
 *   Normalized bands.
 *
 * @return {Array}
 *   Ascending unique tick px values.
 */
export const bandEdgeTicks = (bands) => {
  const ticks = new Set();
  const ceilings = new Set(
    bands.map((band) => band.max).filter((max) => max !== undefined),
  );
  bands.forEach((band) => {
    if (band.max !== undefined) {
      ticks.add(band.max);
    }
    if (band.min !== undefined && !ceilings.has(band.min - 1)) {
      ticks.add(band.min);
    }
  });
  return [...ticks].sort((a, b) => a - b);
};

/**
 * The band containing a width, or null.
 *
 * Null width (nothing posed, nothing measured yet) and a width above
 * every band both answer null: the ruler then highlights nothing, which
 * is exactly right for the bandless space above the contributed ceilings.
 *
 * @param {Array} bands
 *   Normalized bands.
 * @param {?number} width
 *   The effective canvas width.
 *
 * @return {?Object}
 *   The containing band, or null.
 */
export const bandFor = (bands, width) => {
  if (width === null || width === undefined) {
    return null;
  }
  return (
    bands.find(
      (band) =>
        width >= (band.min ?? 0) &&
        (band.max === undefined || width <= band.max),
    ) ?? null
  );
};

/**
 * Whether the canvas is constrained in any way, the ruler's visibility
 * rule. A posed width always constrains, even one landing in the bandless
 * space above every band: a scrub through base's floor must not blink the
 * ruler away. Unposed, the canvas is constrained only when its measured
 * full width sits inside a contributed band (rails or a narrow window
 * squeezing the stage below base's floor); at natural full width above
 * every band, nothing constrains and the ruler has nothing to say.
 *
 * @param {Array} bands
 *   Normalized bands.
 * @param {?number} posed
 *   The posed width axis, null when nothing is posed.
 * @param {?number} measured
 *   The measured full stage width, null before the first measurement.
 *
 * @return {boolean}
 *   TRUE when the ruler should show.
 */
export const constrained = (bands, posed, measured) =>
  (posed !== null && posed !== undefined) || bandFor(bands, measured) !== null;
