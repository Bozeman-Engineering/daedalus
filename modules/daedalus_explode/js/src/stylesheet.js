/**
 * The stylesheet printer, pure: planes plus the view settings and the
 * selection paint become the one CSS text the host injects.
 *
 * Everything the exploded view does to the canvas is this text (the
 * pattern of daedalus_stylish's canvas selector paint): no attribute is
 * stamped on a canvas node, so the canvas mirror sees no drift and
 * swapped markup carries no residue. The sheet is self-gating: every
 * rule sits under `html[data-daedalus-ui-canvas-view="exploded"]`, the
 * axis canvas-view.js writes, so the text is inert the moment the
 * attribute drops, and the `html … #daedalus-ui-stage` prefix outranks
 * daedalus_ui's own `html[data-daedalus-ui-mode] #daedalus-ui-stage`
 * (the isolation the stage must lose) and any theme rule without an id.
 * The canvas is the theme's territory, so the declarations that make a
 * plane a plane carry !important, as the selector paint's outlines do.
 *
 * The stage (from the spike): perspective on the canvas parent, the
 * stage tilted, turned and scaled under preserve-3d with its isolation
 * released, and the neutralizer on every wrapper holding a selectable
 * (preserve-3d and the flattening properties reset) so the 3D space
 * reaches every plane. The settings print as custom properties on the
 * parent; the rules read the properties, so a reader of the injected
 * sheet sees the spike's constants replaced by variables.
 *
 * Per plane: the lift, `translateZ(calc(lift * gap))`, where lift is
 * the model's step above the nearest plane ancestor (translations
 * compose under preserve-3d, so a per-element depth would stack
 * geometrically and a capped plane would float above its slab); the
 * outline in the muted selection stroke; a translucent surface so the
 * planes below read through; a soft shadow; and, when labeled, a
 * `::before` chip carrying the label. An unlifted plane (outside an
 * isolated lineage) gets the transition and a zero lift only, so it
 * lies flat on whatever it sits in and animates down and back.
 *
 * The chip asserts its own box: its block opens with `all: initial`
 * before any own line. A page may style the same element's `&::before`
 * (a generated box is a styling address of the map), and the chip's
 * rule outranks that map rule by the stage id, so every property the
 * chip sets already wins; the reset makes the properties it does NOT
 * set win too (a width, an inset, a transform or a border the page
 * gave its box would otherwise leak into the chip). `all` also drops
 * the theme's global `*::before { box-sizing: border-box }`, so the
 * chip restores it (its padding must fit inside `max-inline-size`).
 * The chip only ever exists while the view is exploded, so the page's
 * box is exactly hidden behind it for exactly that long.
 *
 * Element planes address `[data-selectable="key"]` directly. Part planes
 * have no element of their own: their declarations go through the
 * registered part behavior's selectorFor(owner, selector, declarations),
 * which prints the scoped rule reaching every match (every instance of
 * the part in its owner), exactly the selector paint's road; without a
 * behavior, or for a selector outside its grammar, a part plane prints
 * nothing. The label rides inside the declarations as a nested
 * `&::before` rule, so one declaration builder serves both tiers.
 *
 * The selection, oversized: while exploded the selection frame hides
 * (it measures rects) and the stage scale shrinks everything on the
 * canvas, so the printed paint is the only thing marking the selection
 * and must read at a constant on-screen size at any zoom. The stage
 * rules print one emphasis factor beside the settings,
 * `--daedalus-explode-emphasis: calc(1 / var(--daedalus-explode-scale))`,
 * and every selection measurement multiplies by it: the primary's
 * outline in the full selection color at 4px with a positive offset of
 * the same size (the stroke stands off the plane's edge instead of
 * covering its content), a glow as a box-shadow layer ahead of the
 * plane's own shadow (listed first so the shadow survives), and its
 * label chip in the selection color at a 15px font with padding to
 * match; the other members of the set in the muted stroke at 3px; the
 * hover dashed at 3px (yielding to a selected key, as the selector
 * paint's hover yields to its target). The plain plane outline and chips
 * stay at their own size: they are structure, the selection is the
 * signal.
 *
 * Strings are escaped where CSS reads a string: the attribute value and
 * the `content` text both take a backslash before a backslash or a
 * double quote (the repo's rule for keys), and a newline becomes the
 * `\a ` escape, since a raw newline ends a CSS string and a content
 * label may come from page text.
 */

/**
 * The attribute gate every rule sits under.
 */
const VIEW = 'html[data-daedalus-ui-canvas-view="exploded"]';

/**
 * The stage, gated.
 */
const STAGE = `${VIEW} #daedalus-ui-stage`;

/**
 * The transition every transformed element shares.
 */
const TRANSITION =
  'transition: transform var(--daedalus-explode-duration, 600ms) ease !important;';

/**
 * The selection palette and the plane shadow, with their fallbacks.
 */
const SELECTION = 'var(--daedalus-selection, #0071e3)';
const MUTED = 'var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55))';
const SHADOW = 'var(--daedalus-explode-shadow, 0 12px 32px rgb(0 0 0 / 0.18))';

/**
 * A pixel measure multiplied by the emphasis factor the stage prints,
 * so it reads at a constant on-screen size at any stage scale.
 *
 * @param {number} px
 *   The on-screen size in pixels.
 *
 * @return {string}
 */
const emphasized = (px) => `calc(${px}px * var(--daedalus-explode-emphasis))`;

/**
 * A CSS string token for a value: quoted, with the two characters that
 * could break out escaped and any newline as the `\a ` escape.
 *
 * @param {string} value
 *   The raw text.
 *
 * @return {string}
 */
const quoted = (value) =>
  `"${String(value)
    .replace(/[\\"]/g, '\\$&')
    .replace(/\r\n|\r|\n/g, '\\a ')}"`;

/**
 * The element selector for a key.
 *
 * @param {string} key
 *   An element key.
 *
 * @return {string}
 */
const elementSelector = (key) => `${STAGE} [data-selectable=${quoted(key)}]`;

/**
 * A rule block from a selector and its declaration lines.
 *
 * @param {string} selector
 *   The selector.
 * @param {string[]} lines
 *   The declaration lines (nested rules included), unindented.
 *
 * @return {string}
 */
const rule = (selector, lines) =>
  `${selector} {\n${lines.map((line) => `  ${line}`).join('\n')}\n}`;

/**
 * The stage rules: the parent's perspective and the settings as custom
 * properties, the stage's projection, and the neutralizer.
 *
 * @param {{tilt: number, turn: number, scale: number, gap: number}} settings
 *   The view settings the stage reads.
 *
 * @return {string[]}
 */
const stageRules = (settings) => [
  rule(`${VIEW} .dialog-off-canvas-main-canvas`, [
    `--daedalus-explode-tilt: ${settings.tilt}deg;`,
    `--daedalus-explode-turn: ${settings.turn}deg;`,
    `--daedalus-explode-scale: ${settings.scale};`,
    `--daedalus-explode-gap: ${settings.gap}px;`,
    '--daedalus-explode-emphasis: calc(1 / var(--daedalus-explode-scale));',
    'perspective: var(--daedalus-explode-perspective, 2400px);',
  ]),
  rule(STAGE, [
    'transform-style: preserve-3d;',
    'isolation: auto;',
    'transform: rotateX(var(--daedalus-explode-tilt)) rotateZ(var(--daedalus-explode-turn)) scale(var(--daedalus-explode-scale));',
    'transition: transform var(--daedalus-explode-duration, 600ms) ease;',
  ]),
  rule(`${STAGE} :has([data-selectable])`, [
    'transform-style: preserve-3d !important;',
    'isolation: auto !important;',
    'overflow: visible !important;',
    'opacity: 1 !important;',
    'filter: none !important;',
    'clip-path: none !important;',
    'contain: none !important;',
    'will-change: auto !important;',
  ]),
];

/**
 * The label chip as a nested `&::before` rule: the reset first, so a
 * page's own `&::before` on the same element leaks nothing into it.
 *
 * @param {string} label
 *   The label text.
 *
 * @return {string[]}
 */
const labelLines = (label) => [
  '&::before {',
  '  all: initial;',
  '  box-sizing: border-box;',
  `  content: ${quoted(label)};`,
  '  position: absolute;',
  '  inset-block-start: 0;',
  '  inset-inline-start: 0;',
  '  translate: 0 -100%;',
  '  max-inline-size: 100%;',
  '  padding: 2px 6px;',
  '  border-radius: 3px 3px 0 0;',
  '  font: 600 11px/1.4 system-ui, sans-serif;',
  '  letter-spacing: 0.02em;',
  '  white-space: nowrap;',
  '  overflow: hidden;',
  '  text-overflow: ellipsis;',
  '  color: var(--daedalus-selection-text, #fff);',
  `  background: ${MUTED};`,
  '  pointer-events: none;',
  '  z-index: 1;',
  '}',
];

/**
 * A plane's declaration lines, the same for both tiers.
 *
 * @param {import('./plane-model.js').Plane} plane
 *   The plane.
 * @param {boolean} anchored
 *   Whether the element's computed position is static (the host's read),
 *   so the label needs `position: relative` to anchor.
 *
 * @return {string[]}
 */
const planeLines = (plane, anchored) => {
  const lines = [
    `transform: translateZ(calc(${plane.lift} * var(--daedalus-explode-gap))) !important;`,
    TRANSITION,
  ];
  if (!plane.lifted) {
    return lines;
  }
  if (anchored) {
    lines.push('position: relative !important;');
  }
  lines.push(
    `outline: 1px solid ${MUTED} !important;`,
    'outline-offset: -1px !important;',
    'background-color: var(--daedalus-explode-surface, rgb(255 255 255 / 0.72)) !important;',
    `box-shadow: ${SHADOW} !important;`,
  );
  if (plane.labeled) {
    lines.push(...labelLines(plane.label));
  }
  return lines;
};

/**
 * The selection and hover rules over the planes: the primary, the other
 * members of the set, then the hover (skipped on a selected key). Every
 * measure is multiplied by the emphasis factor the stage prints; these
 * rules follow the plane rules at the same specificity, so their
 * outline, offset and shadow replace the plane's own.
 *
 * @param {Set<string>} addresses
 *   The plane addresses (a key the planes do not carry prints nothing).
 * @param {{selected?: ?string, set?: string[], hovered?: ?string}} paint
 *   The selection paint.
 *
 * @return {string[]}
 */
const paintRules = (addresses, paint) => {
  const rules = [];
  const set = (paint.set ?? []).filter((key) => addresses.has(key));
  const primary = addresses.has(paint.selected) ? paint.selected : null;
  if (primary !== null) {
    rules.push(
      rule(elementSelector(primary), [
        `outline: ${emphasized(4)} solid ${SELECTION} !important;`,
        `outline-offset: ${emphasized(4)} !important;`,
        `box-shadow: 0 0 0 ${emphasized(12)} ${MUTED}, ${SHADOW} !important;`,
        '&::before {',
        `  background: ${SELECTION};`,
        `  font-size: ${emphasized(15)};`,
        `  padding: ${emphasized(3)} ${emphasized(8)};`,
        '}',
      ]),
    );
  }
  for (const key of set) {
    if (key !== primary) {
      rules.push(
        rule(elementSelector(key), [
          `outline: ${emphasized(3)} solid ${MUTED} !important;`,
        ]),
      );
    }
  }
  const hovered = paint.hovered ?? null;
  if (
    hovered !== null &&
    addresses.has(hovered) &&
    hovered !== primary &&
    !set.includes(hovered)
  ) {
    rules.push(
      rule(elementSelector(hovered), [
        `outline: ${emphasized(3)} dashed ${MUTED} !important;`,
      ]),
    );
  }
  return rules;
};

/**
 * Prints the exploded view's stylesheet.
 *
 * @param {import('./plane-model.js').Plane[]} planes
 *   The planes (plane-model.js); empty prints the stage alone.
 * @param {{tilt: number, turn: number, scale: number, gap: number}} settings
 *   The view settings the stage reads: tilt and turn in degrees, the
 *   stage scale, the gap in pixels (the settings store's clamps apply
 *   before this; the printer prints what it is given).
 * @param {{selected?: ?string, set?: string[], hovered?: ?string}} paint
 *   The selection paint: the primary key (app.Selection.getSelected()),
 *   the ordered set (getSelectedSet()) and the hovered key
 *   (getHovered()); each may be absent or null.
 * @param {{selectorFor?: ?Function, anchored?: Iterable<string>}} [tools]
 *   selectorFor: the registered part behavior's
 *   selectorFor(owner, selector, declarations), or null when no
 *   behavior is registered (part planes then print nothing).
 *   anchored: the element keys whose computed position is static (the
 *   host's read), given `position: relative` so their label anchors.
 *
 * @return {string}
 *   The CSS text.
 */
export const print = (planes, settings, paint = {}, tools = {}) => {
  const selectorFor = tools.selectorFor ?? null;
  const anchored = new Set(tools.anchored ?? []);
  const rules = stageRules(settings);
  const addresses = new Set();
  for (const plane of planes) {
    addresses.add(plane.address);
    if (plane.tier === 'element') {
      rules.push(
        rule(elementSelector(plane.address), planeLines(plane, anchored.has(plane.address))),
      );
      continue;
    }
    if (selectorFor === null) {
      continue;
    }
    const printed = selectorFor(
      plane.owner,
      plane.selector,
      planeLines(plane, anchored.has(plane.address)).join('\n    '),
    );
    if (printed !== null) {
      rules.push(printed);
    }
  }
  rules.push(...paintRules(addresses, paint));
  return `${rules.join('\n')}\n`;
};
