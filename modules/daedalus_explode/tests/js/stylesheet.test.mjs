/**
 * Byte-exact tests for the stylesheet printer (stylesheet.js): the stage
 * rules with the settings as variables, the neutralizer, the per-plane
 * lift, outline, surface, shadow and label (the chip opening with the
 * `all: initial` reset), the static-position anchor,
 * an unlifted plane's flat treatment, string escaping in the attribute
 * value and the `content` text, the selection and hover rules sized by
 * the emphasis factor the stage prints, part planes through a stub
 * selectorFor (a nested part at lift 1 above its
 * parent part, a claimed field above its shell, the anchor keyed by the
 * part address), and an empty plane set printing the stage alone.
 *
 * Run with `node --test tests/js/stylesheet.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { print } from '../../js/src/stylesheet.js';

const ROOT = 'entity:node:1';
const SECTION = 'container:a';
const TEXT = 'block:x:text';
const LINK = 'block:x:text::.text-block-link';
const ICON = 'block:x:text::.text-block-link .icon';
const IMAGE = 'block:img:image';
const FIGURE = 'block:img:image::.image-block-figure';
const MEDIA = 'field:block:field_media';

const SETTINGS = { tilt: 38, turn: -22, scale: 0.62, gap: 90 };

/**
 * One plane in plane-model.js's shape: an element unless owner is set.
 */
const plane = (fields) => ({
  kind: 'block',
  tier: 'element',
  owner: null,
  selector: null,
  lifted: true,
  labeled: true,
  ...fields,
});

const root = () =>
  plane({ address: ROOT, depth: 0, lift: 0, label: 'Page', kind: 'entity' });
const section = () =>
  plane({ address: SECTION, depth: 1, lift: 1, label: 'Section', kind: 'container' });
const text = () =>
  plane({ address: TEXT, depth: 2, lift: 1, label: 'Hello world' });

const VIEW = 'html[data-daedalus-ui-canvas-view="exploded"]';
const STAGE = `${VIEW} #daedalus-ui-stage`;

/**
 * The stage: the parent's variables and perspective, the projection,
 * the neutralizer. Exactly this text opens every sheet.
 */
const STAGE_CSS = `${VIEW} .dialog-off-canvas-main-canvas {
  --daedalus-explode-tilt: 38deg;
  --daedalus-explode-turn: -22deg;
  --daedalus-explode-scale: 0.62;
  --daedalus-explode-gap: 90px;
  --daedalus-explode-emphasis: calc(1 / var(--daedalus-explode-scale));
  perspective: var(--daedalus-explode-perspective, 2400px);
}
${STAGE} {
  transform-style: preserve-3d;
  isolation: auto;
  transform: rotateX(var(--daedalus-explode-tilt)) rotateZ(var(--daedalus-explode-turn)) scale(var(--daedalus-explode-scale));
  transition: transform var(--daedalus-explode-duration, 600ms) ease;
}
${STAGE} :has([data-selectable]) {
  transform-style: preserve-3d !important;
  isolation: auto !important;
  overflow: visible !important;
  opacity: 1 !important;
  filter: none !important;
  clip-path: none !important;
  contain: none !important;
  will-change: auto !important;
}
`;

const TRANSITION =
  '  transition: transform var(--daedalus-explode-duration, 600ms) ease !important;\n';

const SURFACE = `  outline: 1px solid var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)) !important;
  outline-offset: -1px !important;
  background-color: var(--daedalus-explode-surface, rgb(255 255 255 / 0.72)) !important;
  box-shadow: var(--daedalus-explode-shadow, 0 12px 32px rgb(0 0 0 / 0.18)) !important;
`;

/**
 * The label chip for a `content` string token (already quoted and
 * escaped by the caller).
 */
const label = (content) => `  &::before {
    all: initial;
    box-sizing: border-box;
    content: ${content};
    position: absolute;
    inset-block-start: 0;
    inset-inline-start: 0;
    translate: 0 -100%;
    max-inline-size: 100%;
    padding: 2px 6px;
    border-radius: 3px 3px 0 0;
    font: 600 11px/1.4 system-ui, sans-serif;
    letter-spacing: 0.02em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--daedalus-selection-text, #fff);
    background: var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55));
    pointer-events: none;
    z-index: 1;
  }
`;

/**
 * A lifted, labeled element plane's whole rule.
 */
const planeRule = (key, lift, content) =>
  `${STAGE} [data-selectable="${key}"] {
  transform: translateZ(calc(${lift} * var(--daedalus-explode-gap))) !important;
${TRANSITION}${SURFACE}${label(content)}}
`;

/**
 * The selection paint, every measure multiplied by the emphasis factor
 * the stage prints: the primary's stroke, offset, glow and chip; a set
 * member's stroke; the hover's dashed stroke. Exactly this text follows
 * the plane rules.
 */
const EMPHASIS = 'var(--daedalus-explode-emphasis)';
const primaryRule = (key) => `${STAGE} [data-selectable="${key}"] {
  outline: calc(4px * ${EMPHASIS}) solid var(--daedalus-selection, #0071e3) !important;
  outline-offset: calc(4px * ${EMPHASIS}) !important;
  box-shadow: 0 0 0 calc(12px * ${EMPHASIS}) var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)), var(--daedalus-explode-shadow, 0 12px 32px rgb(0 0 0 / 0.18)) !important;
  &::before {
    background: var(--daedalus-selection, #0071e3);
    font-size: calc(15px * ${EMPHASIS});
    padding: calc(3px * ${EMPHASIS}) calc(8px * ${EMPHASIS});
  }
}
`;
const memberRule = (key) => `${STAGE} [data-selectable="${key}"] {
  outline: calc(3px * ${EMPHASIS}) solid var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)) !important;
}
`;
const hoverRule = (key) => `${STAGE} [data-selectable="${key}"] {
  outline: calc(3px * ${EMPHASIS}) dashed var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)) !important;
}
`;

test('an empty plane set prints the stage alone', () => {
  assert.equal(print([], SETTINGS), STAGE_CSS);
  assert.equal(print([], SETTINGS, {}, {}), STAGE_CSS);
});

test('the settings print as the parent variables the stage rules read', () => {
  const css = print([], { tilt: 0, turn: 60, scale: 1, gap: 200 });
  assert.ok(
    css.startsWith(`${VIEW} .dialog-off-canvas-main-canvas {
  --daedalus-explode-tilt: 0deg;
  --daedalus-explode-turn: 60deg;
  --daedalus-explode-scale: 1;
  --daedalus-explode-gap: 200px;
  --daedalus-explode-emphasis: calc(1 / var(--daedalus-explode-scale));
  perspective: var(--daedalus-explode-perspective, 2400px);
}
`),
    css,
  );
});

test('element planes print the lift, transition, outline, surface, shadow and label', () => {
  assert.equal(
    print([root(), section(), text()], SETTINGS),
    STAGE_CSS +
      planeRule(ROOT, 0, '"Page"') +
      planeRule(SECTION, 1, '"Section"') +
      planeRule(TEXT, 1, '"Hello world"'),
  );
});

test('the chip opens with the reset, before any own line, and restores border-box', () => {
  // A page may style the same element's `&::before` (a generated box of
  // the map); the reset first means nothing the page set there leaks
  // into the chip, and `content` after it means the chip still reads.
  const css = print([section()], SETTINGS);
  const chip = css.slice(css.indexOf('&::before {'));
  assert.ok(
    chip.startsWith('&::before {\n    all: initial;\n    box-sizing: border-box;\n    content: "Section";'),
    chip,
  );
  assert.equal((css.match(/all: initial;/g) ?? []).length, 1, 'the reset is the chip\'s alone');
});

test('an anchored plane gets position: relative before its surface', () => {
  const css = print([section()], SETTINGS, {}, { anchored: [SECTION] });
  assert.equal(
    css,
    STAGE_CSS +
      `${STAGE} [data-selectable="${SECTION}"] {
  transform: translateZ(calc(1 * var(--daedalus-explode-gap))) !important;
${TRANSITION}  position: relative !important;
${SURFACE}${label('"Section"')}}
`,
  );
});

test('an unlabeled plane has no chip; an unlifted plane is a zero lift and the transition only', () => {
  const css = print(
    [
      plane({ address: SECTION, depth: 1, lift: 1, label: 'Section', labeled: false }),
      plane({ address: TEXT, depth: 2, lift: 0, label: 'Hello world', lifted: false, labeled: false }),
    ],
    SETTINGS,
    {},
    { anchored: [SECTION, TEXT] },
  );
  assert.equal(
    css,
    STAGE_CSS +
      `${STAGE} [data-selectable="${SECTION}"] {
  transform: translateZ(calc(1 * var(--daedalus-explode-gap))) !important;
${TRANSITION}  position: relative !important;
${SURFACE}}
${STAGE} [data-selectable="${TEXT}"] {
  transform: translateZ(calc(0 * var(--daedalus-explode-gap))) !important;
${TRANSITION}}
`,
  );
});

test('the attribute value and the content string are escaped', () => {
  const css = print(
    [
      plane({
        address: 'block:"q"\\z:text',
        depth: 1,
        lift: 1,
        label: 'Say "hi" \\ there\nnow\r\nand\rthen',
      }),
    ],
    SETTINGS,
  );
  assert.equal(
    css,
    STAGE_CSS +
      planeRule(
        'block:\\"q\\"\\\\z:text',
        1,
        '"Say \\"hi\\" \\\\ there\\a now\\a and\\a then"',
      ),
  );
});

test('the selection paints the primary full, the members muted, the hover dashed, all sized by the emphasis', () => {
  const css = print([root(), section(), text()], SETTINGS, {
    selected: TEXT,
    set: [SECTION, TEXT],
    hovered: ROOT,
  });
  assert.equal(
    css,
    STAGE_CSS +
      planeRule(ROOT, 0, '"Page"') +
      planeRule(SECTION, 1, '"Section"') +
      planeRule(TEXT, 1, '"Hello world"') +
      primaryRule(TEXT) +
      memberRule(SECTION) +
      hoverRule(ROOT),
  );
  // The paint reads the one emphasis factor the stage prints, so the
  // treatment holds its on-screen size at any stage scale; the plane
  // rules themselves never read it.
  const paint = css.slice(css.indexOf(primaryRule(TEXT)));
  assert.ok(paint.includes('var(--daedalus-explode-emphasis)'), paint);
  assert.ok(!css.slice(0, css.indexOf(primaryRule(TEXT))).includes('calc(4px'));
});

test('the hover yields to a selected key, and a key the planes do not carry paints nothing', () => {
  const base = print([root(), section(), text()], SETTINGS);
  assert.equal(
    print([root(), section(), text()], SETTINGS, {
      selected: TEXT,
      set: [TEXT],
      hovered: TEXT,
    }),
    base + primaryRule(TEXT),
    'the hovered primary paints once',
  );
  assert.equal(
    print([root(), section(), text()], SETTINGS, {
      selected: SECTION,
      set: [SECTION, TEXT],
      hovered: SECTION,
    }),
    base + primaryRule(SECTION) + memberRule(TEXT),
    'the hovered primary yields; the member still paints',
  );
  assert.equal(
    print([root(), section(), text()], SETTINGS, {
      selected: 'block:gone:text',
      set: ['block:gone:text'],
      hovered: 'block:also-gone:text',
    }),
    base,
    'keys outside the planes print no selection or hover rule',
  );
  assert.equal(
    print([root(), section(), text()], SETTINGS, { selected: null, set: [], hovered: null }),
    base,
  );
});

test('part planes print through selectorFor with the plane declarations nested', () => {
  const calls = [];
  const selectorFor = (owner, selector, declarations) => {
    calls.push([owner, selector, declarations]);
    return `@scope ([data-selectable="${owner}"]) {\n  :where(:scope) ${selector} {\n    ${declarations}\n  }\n}`;
  };
  const link = plane({
    address: LINK,
    depth: 3,
    lift: 1,
    label: 'Link',
    kind: 'part',
    tier: 'part',
    owner: TEXT,
    selector: '.text-block-link',
  });
  const css = print([text(), link], SETTINGS, {}, { selectorFor, anchored: [LINK] });
  const declarations = `transform: translateZ(calc(1 * var(--daedalus-explode-gap))) !important;
    transition: transform var(--daedalus-explode-duration, 600ms) ease !important;
    position: relative !important;
    outline: 1px solid var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55)) !important;
    outline-offset: -1px !important;
    background-color: var(--daedalus-explode-surface, rgb(255 255 255 / 0.72)) !important;
    box-shadow: var(--daedalus-explode-shadow, 0 12px 32px rgb(0 0 0 / 0.18)) !important;
    &::before {
      all: initial;
      box-sizing: border-box;
      content: "Link";
      position: absolute;
      inset-block-start: 0;
      inset-inline-start: 0;
      translate: 0 -100%;
      max-inline-size: 100%;
      padding: 2px 6px;
      border-radius: 3px 3px 0 0;
      font: 600 11px/1.4 system-ui, sans-serif;
      letter-spacing: 0.02em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      color: var(--daedalus-selection-text, #fff);
      background: var(--daedalus-selection-muted, rgba(0, 113, 227, 0.55));
      pointer-events: none;
      z-index: 1;
    }`;
  assert.deepEqual(calls, [[TEXT, '.text-block-link', declarations]]);
  assert.equal(
    css,
    STAGE_CSS +
      planeRule(TEXT, 1, '"Hello world"') +
      `@scope ([data-selectable="${TEXT}"]) {
  :where(:scope) .text-block-link {
    ${declarations}
  }
}
`,
  );
});

test('a nested part prints at lift 1 through the stub; a claimed field prints above its shell', () => {
  const calls = [];
  const selectorFor = (owner, selector, declarations) => {
    calls.push([owner, selector]);
    return `@scope ([data-selectable="${owner}"]) {\n  :where(:scope) ${selector} {\n    ${declarations}\n  }\n}`;
  };
  const part = (address, owner, selector, depth, label) =>
    plane({ address, depth, lift: 1, label, kind: 'part', tier: 'part', owner, selector });
  const link = part(LINK, TEXT, '.text-block-link', 3, 'Link');
  const icon = part(ICON, TEXT, '.text-block-link .icon', 4, 'Icon');
  const figure = part(FIGURE, IMAGE, '.image-block-figure', 3, 'Figure');
  const media = plane({ address: MEDIA, depth: 4, lift: 1, label: 'Media', kind: 'field' });
  const image = plane({ address: IMAGE, depth: 2, lift: 1, label: 'Image' });
  const css = print(
    [text(), link, icon, image, figure, media],
    SETTINGS,
    {},
    { selectorFor, anchored: [ICON, MEDIA] },
  );
  assert.deepEqual(calls, [
    [TEXT, '.text-block-link'],
    [TEXT, '.text-block-link .icon'],
    [IMAGE, '.image-block-figure'],
  ]);
  // A part's declarations are the element's, joined for the stub's nesting.
  const partLines = (lift, content, anchored) =>
    (`transform: translateZ(calc(${lift} * var(--daedalus-explode-gap))) !important;\n` +
      TRANSITION +
      (anchored ? '  position: relative !important;\n' : '') +
      SURFACE +
      label(content))
      .trimEnd()
      .replace(/\n {2}/g, '\n    ');
  const partRule = (owner, selector, lift, content, anchored) =>
    `@scope ([data-selectable="${owner}"]) {
  :where(:scope) ${selector} {
    ${partLines(lift, content, anchored)}
  }
}
`;
  assert.equal(
    css,
    STAGE_CSS +
      planeRule(TEXT, 1, '"Hello world"') +
      partRule(TEXT, '.text-block-link', 1, '"Link"', false) +
      partRule(TEXT, '.text-block-link .icon', 1, '"Icon"', true) +
      planeRule(IMAGE, 1, '"Image"') +
      partRule(IMAGE, '.image-block-figure', 1, '"Figure"', false) +
      `${STAGE} [data-selectable="${MEDIA}"] {
  transform: translateZ(calc(1 * var(--daedalus-explode-gap))) !important;
${TRANSITION}  position: relative !important;
${SURFACE}${label('"Media"')}}
`,
  );
  // The nested part's rule carries its own lift and chip; the anchor is
  // keyed by the part's address and lands on every match through the one
  // printed rule, while its parent part, unanchored, prints none.
  assert.ok(css.includes('.text-block-link .icon {\n    transform: translateZ(calc(1 * var(--daedalus-explode-gap)))'));
  assert.ok(css.includes('content: "Icon";'));
  assert.equal(
    (css.match(/position: relative !important;/g) ?? []).length,
    2,
    'the icon part and the claimed field anchor; the link and the figure do not',
  );
});

test('a part plane prints nothing without a behavior or outside its grammar', () => {
  const link = plane({
    address: LINK,
    depth: 3,
    lift: 1,
    label: 'Link',
    kind: 'part',
    tier: 'part',
    owner: TEXT,
    selector: '.text-block-link',
  });
  const base = STAGE_CSS + planeRule(TEXT, 1, '"Hello world"');
  assert.equal(print([text(), link], SETTINGS), base, 'no selectorFor');
  assert.equal(print([text(), link], SETTINGS, {}, { selectorFor: null }), base, 'null selectorFor');
  assert.equal(
    print([text(), link], SETTINGS, {}, { selectorFor: () => null }),
    base,
    'a selector outside the grammar',
  );
});

test('the printer never mutates its inputs', () => {
  const list = [root(), section()];
  const before = JSON.stringify(list);
  const settings = { ...SETTINGS };
  const paint = { selected: SECTION, set: [SECTION], hovered: ROOT };
  print(list, settings, paint, { anchored: [ROOT] });
  assert.equal(JSON.stringify(list), before);
  assert.deepEqual(settings, SETTINGS);
  assert.deepEqual(paint, { selected: SECTION, set: [SECTION], hovered: ROOT });
});
