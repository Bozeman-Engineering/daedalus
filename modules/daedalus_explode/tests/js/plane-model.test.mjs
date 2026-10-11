/**
 * Self-asserting tests for the plane model (plane-model.js): the
 * projection index becomes planes with depth from rowParent ancestry,
 * the depth cap collapses deeper planes onto the cap, isolate keeps only
 * the selection's lineage lifted, reach decides the part tier (a shell
 * plane lifts only while the Markup view is on, at owner depth + 1: a
 * nested part one step above its parent part, a field claimed into a
 * shell one step above the shell, the lifts summing to the depths down
 * both chains; an instance carrier lifts in every view), a generated box
 * row (`&::before`, `&::after` under its owner) is never a plane, a key
 * absent from the index is no plane, and maxDepth ignores the cap.
 *
 * Reach is daedalus_ui's own predicate (reach.js reachOver, what
 * app.tree.reachable closes over the live index and view slice), driven
 * here over the hand-built index with the Markup view off and on. That
 * module reads the app store, so the suite registers an extension-adding
 * resolve hook, stubs the browser globals, then dynamically imports it.
 *
 * Run with `node --test tests/js/plane-model.test.mjs` from the module
 * root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { registerHooks } from 'node:module';
import { planes, maxDepth } from '../../js/src/plane-model.js';

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context);
    } catch (error) {
      if (specifier.startsWith('.') || specifier.startsWith('/')) {
        return nextResolve(`${specifier}.js`, context);
      }
      throw error;
    }
  },
});
globalThis.window = globalThis;
globalThis.document = {
  addEventListener: () => {},
  querySelector: () => null,
  documentElement: { addEventListener: () => {} },
};
globalThis.Drupal = { t: (string) => string };

const { reachOver } = await import(
  '../../../daedalus_ui/js/edit_mode/components/page-elements/reach.js'
);
const { MARKUP_VIEW_ID } = await import(
  '../../../daedalus_ui/js/edit_mode/components/page-elements/markup-view.js'
);

/**
 * One index entry in projection-index.js's shape.
 */
// Every entry carries an open verdict: reach (daedalus_ui's reach.js) is
// gated on the actor too, and a row whose verdict allows nothing is drawn
// but never reachable, so a plane never stands on it.
const OPEN = { content: true, configure: true, style: true, arrange: true, insert: true };
const entry = (fields) => ({
  bundle: null,
  children: [],
  rows: [],
  can: OPEN,
  ...fields,
});

const ROOT = 'entity:node:1';
const SECTION_A = 'container:a';
const TEXT = 'block:x:text';
const LINK = 'block:x:text::.text-block-link';
const ICON = 'block:x:text::.text-block-link .icon';
const BEFORE_BOX = 'block:x:text::&::before';
const AFTER_BOX = 'block:x:text::&::after';
const IMAGE = 'block:img:image';
const FIGURE = 'block:img:image::.image-block-figure';
const MEDIA = 'field:block:field_media';
const SECTION_B = 'container:b';
const OTHER = 'block:y:text';
const CARRIER = 'block:y:text::.text-instance-1';

/**
 * A small page in the index's pre-order: the root, section A holding a
 * text block (with a link part, a nested icon part, and its two generated
 * boxes rowed last, as the index lists them) and an image block whose
 * media field is claimed into its figure shell, and section B holding one
 * more text block with a rich-text instance carrier row. Every entry
 * carries its `source` as the index stamps it: 'element' for a model row,
 * 'instance' for the carrier, the Markup view's id for the shells and
 * the generated boxes.
 */
const index = () =>
  new Map([
    [
      ROOT,
      entry({
        label: 'Page',
        kind: 'entity',
        bundle: 'page',
        parent: null,
        rowParent: null,
        children: [SECTION_A, SECTION_B],
        rows: [SECTION_A, SECTION_B],
        tier: 'element',
        source: 'element',
      }),
    ],
    [
      SECTION_A,
      entry({
        label: 'Section',
        kind: 'container',
        parent: ROOT,
        rowParent: ROOT,
        children: [TEXT, IMAGE],
        rows: [TEXT, IMAGE],
        tier: 'element',
        source: 'element',
      }),
    ],
    [
      TEXT,
      entry({
        label: 'Hello world',
        kind: 'block',
        bundle: 'text',
        parent: SECTION_A,
        rowParent: SECTION_A,
        rows: [LINK, BEFORE_BOX, AFTER_BOX],
        tier: 'element',
        source: 'element',
      }),
    ],
    [
      LINK,
      entry({
        label: 'Link',
        kind: 'part',
        parent: TEXT,
        rowParent: TEXT,
        rows: [ICON],
        tier: 'part',
        source: MARKUP_VIEW_ID,
        owner: TEXT,
        tag: 'a',
      }),
    ],
    [
      ICON,
      entry({
        label: 'Icon',
        kind: 'part',
        parent: LINK,
        rowParent: LINK,
        tier: 'part',
        source: MARKUP_VIEW_ID,
        owner: TEXT,
        tag: 'span',
      }),
    ],
    // The generated boxes, in the index's shape for a pseudo row
    // (projection-index.js buildIndex): part-tier, owner the block, no
    // tag, the Markup view's source.
    [
      BEFORE_BOX,
      entry({
        label: 'Before',
        kind: 'part',
        parent: TEXT,
        rowParent: TEXT,
        tier: 'part',
        source: MARKUP_VIEW_ID,
        owner: TEXT,
        tag: null,
      }),
    ],
    [
      AFTER_BOX,
      entry({
        label: 'After',
        kind: 'part',
        parent: TEXT,
        rowParent: TEXT,
        tier: 'part',
        source: MARKUP_VIEW_ID,
        owner: TEXT,
        tag: null,
      }),
    ],
    [
      IMAGE,
      entry({
        label: 'Image',
        kind: 'block',
        bundle: 'image',
        parent: SECTION_A,
        rowParent: SECTION_A,
        children: [MEDIA],
        rows: [FIGURE],
        tier: 'element',
        source: 'element',
      }),
    ],
    [
      FIGURE,
      entry({
        label: 'Figure',
        kind: 'part',
        parent: IMAGE,
        rowParent: IMAGE,
        rows: [MEDIA],
        tier: 'part',
        source: MARKUP_VIEW_ID,
        owner: IMAGE,
        tag: 'figure',
      }),
    ],
    [
      MEDIA,
      entry({
        label: 'Media',
        kind: 'field',
        parent: IMAGE,
        rowParent: FIGURE,
        tier: 'element',
        source: 'element',
      }),
    ],
    [
      SECTION_B,
      entry({
        label: 'Section',
        kind: 'container',
        parent: ROOT,
        rowParent: ROOT,
        children: [OTHER],
        rows: [OTHER],
        tier: 'element',
        source: 'element',
      }),
    ],
    [
      OTHER,
      entry({
        label: 'Other text',
        kind: 'block',
        bundle: 'text',
        parent: SECTION_B,
        rowParent: SECTION_B,
        rows: [CARRIER],
        tier: 'element',
        source: 'element',
      }),
    ],
    // A rich-text instance carrier, in the index's shape for an instance
    // row (markup-view.js toInstanceRow): part-tier, owner the block,
    // source 'instance', depicted in every view.
    [
      CARRIER,
      entry({
        label: 'Paragraph',
        kind: 'part',
        parent: OTHER,
        rowParent: OTHER,
        tier: 'part',
        source: 'instance',
        owner: OTHER,
        tag: 'p',
      }),
    ],
  ]);

const ALL = { depthCap: null, isolate: false, labels: true };

/**
 * Reach over the fixture with the Markup view off (the shells and boxes
 * undrawn) and on: daedalus_ui's own predicate, as the host hands it in.
 */
const OFF = reachOver(index(), {});
const ON = reachOver(index(), { [MARKUP_VIEW_ID]: true });

const byAddress = (list) => new Map(list.map((plane) => [plane.address, plane]));

test('depth counts the plane ancestors on the rowParent chain, root at 0', () => {
  const result = planes(index(), ROOT, OFF, ALL, []);
  assert.deepEqual(
    result.map(({ address, depth }) => [address, depth]),
    [
      [ROOT, 0],
      [SECTION_A, 1],
      [TEXT, 2],
      [IMAGE, 2],
      [MEDIA, 3],
      [SECTION_B, 1],
      [OTHER, 2],
      [CARRIER, 3],
    ],
    'element and instance planes only, in the index order, with the shells transparent',
  );
});

test('a plane carries the entry label, kind and tier, lifted and labeled', () => {
  const [root, , text] = planes(index(), ROOT, OFF, ALL, []);
  assert.deepEqual(root, {
    address: ROOT,
    depth: 0,
    label: 'Page',
    kind: 'entity',
    tier: 'element',
    owner: null,
    selector: null,
    lifted: true,
    lift: 0,
    labeled: true,
  });
  assert.deepEqual(text, {
    address: TEXT,
    depth: 2,
    label: 'Hello world',
    kind: 'block',
    tier: 'element',
    owner: null,
    selector: null,
    lifted: true,
    lift: 1,
    labeled: true,
  });
});

test('labels off unlabels every plane and changes nothing else', () => {
  const result = planes(index(), ROOT, OFF, { ...ALL, labels: false }, []);
  assert.equal(result.length, 8);
  assert.ok(result.every((plane) => plane.labeled === false));
  assert.ok(result.every((plane) => plane.lifted === true));
});

test('the depth cap collapses deeper planes onto the cap, lifted but unlabeled', () => {
  const result = byAddress(planes(index(), ROOT, OFF, { ...ALL, depthCap: 1 }, []));
  assert.equal(result.get(ROOT).depth, 0, 'the root sits under the cap');
  assert.equal(result.get(SECTION_A).depth, 1);
  assert.equal(result.get(SECTION_A).labeled, true, 'at the cap is not collapsed');
  assert.equal(result.get(TEXT).depth, 1);
  assert.equal(result.get(TEXT).lifted, true);
  assert.equal(result.get(TEXT).labeled, false);
  assert.equal(result.get(MEDIA).depth, 1);
  assert.equal(result.get(MEDIA).labeled, false);
});

test('a depth cap of 2 keeps blocks at 2 and collapses only the field', () => {
  const result = byAddress(planes(index(), ROOT, OFF, { ...ALL, depthCap: 2 }, []));
  assert.equal(result.get(TEXT).depth, 2);
  assert.equal(result.get(TEXT).labeled, true);
  assert.equal(result.get(MEDIA).depth, 2);
  assert.equal(result.get(MEDIA).labeled, false);
});

test('a cap below 1 or not an integer means no cap', () => {
  for (const depthCap of [0, -1, 1.5, 'All', undefined]) {
    const result = byAddress(planes(index(), ROOT, OFF, { ...ALL, depthCap }, []));
    assert.equal(result.get(MEDIA).depth, 3, `cap ${String(depthCap)}`);
    assert.equal(result.get(MEDIA).labeled, true);
  }
});

test('isolate keeps the selection, its ancestors and its descendants lifted', () => {
  const result = byAddress(
    planes(index(), ROOT, OFF, { ...ALL, isolate: true }, [SECTION_A]),
  );
  for (const address of [ROOT, SECTION_A, TEXT, IMAGE, MEDIA]) {
    assert.equal(result.get(address).lifted, true, `${address} lifted`);
    assert.equal(result.get(address).labeled, true, `${address} labeled`);
  }
  for (const address of [SECTION_B, OTHER, CARRIER]) {
    assert.equal(result.get(address).lifted, false, `${address} flat`);
    assert.equal(result.get(address).labeled, false, `${address} unlabeled`);
  }
  assert.equal(result.get(SECTION_B).depth, 1, 'depth kept');
  assert.equal(result.get(OTHER).depth, 2, 'depth kept');
});

test('lift is the step above the nearest plane ancestor: one gap nested, zero collapsed or flat', () => {
  const lifts = (reach, settings, selection) =>
    planes(index(), ROOT, reach, settings, selection).map(({ address, lift }) => [
      address,
      lift,
    ]);
  assert.deepEqual(
    lifts(OFF, ALL, []),
    [
      [ROOT, 0],
      [SECTION_A, 1],
      [TEXT, 1],
      [IMAGE, 1],
      [MEDIA, 1],
      [SECTION_B, 1],
      [OTHER, 1],
      [CARRIER, 1],
    ],
    'every plane one gap above its parent plane: the lifts sum to the depths',
  );
  assert.deepEqual(
    lifts(OFF, { ...ALL, depthCap: 1 }, []),
    [
      [ROOT, 0],
      [SECTION_A, 1],
      [TEXT, 0],
      [IMAGE, 0],
      [MEDIA, 0],
      [SECTION_B, 1],
      [OTHER, 0],
      [CARRIER, 0],
    ],
    'a plane collapsed onto the cap its parent shares takes no step',
  );
  assert.deepEqual(
    lifts(OFF, { ...ALL, isolate: true }, [TEXT]),
    [
      [ROOT, 0],
      [SECTION_A, 1],
      [TEXT, 1],
      [IMAGE, 0],
      [MEDIA, 0],
      [SECTION_B, 0],
      [OTHER, 0],
      [CARRIER, 0],
    ],
    'an unlifted plane lies flat on whatever it sits in',
  );
  const markup = byAddress(planes(index(), ROOT, ON, ALL, []));
  assert.equal(markup.get(FIGURE).lift, 1, 'the shell one gap above its owner');
  assert.equal(markup.get(MEDIA).lift, 1, 'the claimed field one gap above the shell');
  assert.equal(markup.get(ICON).lift, 1, 'the nested part one gap above its parent part');
});

test('isolate on a leaf lifts its ancestry only; the set unions lineages', () => {
  const leaf = byAddress(planes(index(), ROOT, OFF, { ...ALL, isolate: true }, [OTHER]));
  assert.deepEqual(
    [...leaf.values()].filter((plane) => plane.lifted).map((plane) => plane.address),
    [ROOT, SECTION_B, OTHER, CARRIER],
  );
  const union = byAddress(
    planes(index(), ROOT, OFF, { ...ALL, isolate: true }, [OTHER, TEXT]),
  );
  assert.deepEqual(
    [...union.values()].filter((plane) => plane.lifted).map((plane) => plane.address),
    [ROOT, SECTION_A, TEXT, SECTION_B, OTHER, CARRIER],
  );
});

test('isolate descends through a transparent part row to the claimed field', () => {
  const result = byAddress(
    planes(index(), ROOT, OFF, { ...ALL, isolate: true }, [IMAGE]),
  );
  assert.equal(result.get(MEDIA).lifted, true, 'the field under the figure shell');
  assert.equal(result.get(TEXT).lifted, false);
  assert.equal(result.has(FIGURE), false, 'the shell itself is no plane with Markup off');
});

test('isolate with nothing selected, or a key the index does not place, is inert', () => {
  for (const selection of [[], ['block:gone:text']]) {
    const result = planes(index(), ROOT, OFF, { ...ALL, isolate: true }, selection);
    assert.equal(result.length, 8);
    assert.ok(result.every((plane) => plane.lifted === true));
    assert.ok(result.every((plane) => plane.address !== 'block:gone:text'));
  }
});

test('a shell plane lifts only while the Markup view is on, at owner depth + 1, nested parts deeper', () => {
  const off = planes(index(), ROOT, OFF, ALL, []);
  assert.ok(
    off.every((plane) => ![LINK, ICON, FIGURE].includes(plane.address)),
    'Markup off: no shell plane',
  );
  const result = planes(index(), ROOT, ON, ALL, []);
  assert.deepEqual(
    result.map(({ address, depth }) => [address, depth]),
    [
      [ROOT, 0],
      [SECTION_A, 1],
      [TEXT, 2],
      [LINK, 3],
      [ICON, 4],
      [IMAGE, 2],
      [FIGURE, 3],
      [MEDIA, 4],
      [SECTION_B, 1],
      [OTHER, 2],
      [CARRIER, 3],
    ],
    'Markup on: the field claimed into the figure shell lifts above the shell',
  );
  const link = result.find((plane) => plane.address === LINK);
  assert.deepEqual(link, {
    address: LINK,
    depth: 3,
    label: 'Link',
    kind: 'part',
    tier: 'part',
    owner: TEXT,
    selector: '.text-block-link',
    lifted: true,
    lift: 1,
    labeled: true,
  });
  assert.equal(
    result.find((plane) => plane.address === ICON).selector,
    '.text-block-link .icon',
  );
});

test('an instance carrier is a plane in every view, one step above its owner', () => {
  for (const [name, reach] of [['off', OFF], ['on', ON]]) {
    const result = byAddress(planes(index(), ROOT, reach, ALL, []));
    assert.deepEqual(
      result.get(CARRIER),
      {
        address: CARRIER,
        depth: 3,
        label: 'Paragraph',
        kind: 'part',
        tier: 'part',
        owner: OTHER,
        selector: '.text-instance-1',
        lifted: true,
        lift: 1,
        labeled: true,
      },
      `Markup ${name}`,
    );
  }
});

test('a nested part is a plane one step above its parent part; a claimed field one above its shell', () => {
  const result = byAddress(planes(index(), ROOT, ON, ALL, []));
  assert.deepEqual(result.get(ICON), {
    address: ICON,
    depth: 4,
    label: 'Icon',
    kind: 'part',
    tier: 'part',
    owner: TEXT,
    selector: '.text-block-link .icon',
    lifted: true,
    lift: 1,
    labeled: true,
  });
  assert.deepEqual(result.get(MEDIA), {
    address: MEDIA,
    depth: 4,
    label: 'Media',
    kind: 'field',
    tier: 'element',
    owner: null,
    selector: null,
    lifted: true,
    lift: 1,
    labeled: true,
  });
  // The lifts sum to the depths down both chains: owner 2, part 3, nested
  // part 4; owner 2, shell 3, claimed field 4.
  assert.equal(result.get(TEXT).lift + result.get(LINK).lift + result.get(ICON).lift, 3);
  assert.equal(result.get(IMAGE).lift + result.get(FIGURE).lift + result.get(MEDIA).lift, 3);
  // With Markup off the same field steps once above its block: the shell
  // between them is transparent, so the field's own lift is unchanged.
  const flat = byAddress(planes(index(), ROOT, OFF, ALL, []));
  assert.equal(flat.get(MEDIA).depth, 3);
  assert.equal(flat.get(MEDIA).lift, 1);
});

test('a generated box row is never a plane: no geometry of its own, it lifts with its host', () => {
  for (const [reach, settings] of [
    [OFF, ALL],
    [ON, ALL],
    [ON, { ...ALL, isolate: true }],
  ]) {
    const result = planes(index(), ROOT, reach, settings, [TEXT]);
    assert.ok(
      result.every((plane) => plane.address !== BEFORE_BOX && plane.address !== AFTER_BOX),
      `no box plane under ${reach === ON ? 'Markup on' : 'Markup off'} ${JSON.stringify(settings)}`,
    );
    assert.ok(
      result.every((plane) => plane.selector === null || !/::(before|after)$/.test(plane.selector)),
      'no plane selector ends in a pseudo-element',
    );
  }
  // The boxes are transparent to everything around them, even while the
  // Markup view draws their rows: the owner's real parts keep their
  // depths and the deepest depth is the icon's.
  const markup = byAddress(planes(index(), ROOT, ON, ALL, []));
  assert.equal(markup.size, 11);
  assert.equal(markup.get(LINK).depth, 3);
  assert.equal(markup.get(ICON).depth, 4);
  assert.equal(maxDepth(index(), ROOT, ON), 4);
});

test('the part tier composes with the cap and with isolate', () => {
  const capped = byAddress(planes(index(), ROOT, ON, { ...ALL, depthCap: 3 }, []));
  assert.equal(capped.get(LINK).depth, 3);
  assert.equal(capped.get(LINK).labeled, true);
  assert.equal(capped.get(ICON).depth, 3);
  assert.equal(capped.get(ICON).labeled, false);
  const isolated = byAddress(
    planes(index(), ROOT, ON, { ...ALL, isolate: true }, [TEXT]),
  );
  assert.deepEqual(
    [...isolated.values()].filter((plane) => plane.lifted).map((plane) => plane.address),
    [ROOT, SECTION_A, TEXT, LINK, ICON],
  );
  assert.equal(isolated.get(FIGURE).lifted, false);
});

test('a settings object without the toggles reads them off; the part tier is reach, not a setting', () => {
  const result = planes(index(), ROOT, OFF, {}, []);
  assert.equal(result.length, 8);
  assert.ok(result.every((plane) => plane.labeled === false), 'labels absent is off');
  assert.ok(result.every((plane) => plane.lifted === true), 'isolate absent is off');
  const markup = planes(index(), ROOT, ON, { parts: false }, []);
  assert.equal(markup.length, 11, 'a stale parts key changes nothing');
});

test('no index means no planes', () => {
  assert.deepEqual(planes(null, null, reachOver(null, {}), ALL, []), []);
  assert.deepEqual(planes(new Map(), null, reachOver(new Map(), {}), ALL, []), []);
  assert.equal(maxDepth(null, null, reachOver(null, {})), 0);
});

test('a root given ends the walk there even when the entry has a rowParent', () => {
  const nested = index();
  // Pretend the page root sits inside a foreign wrapper row: the walk
  // stops at the named root, so its depth stays 0.
  nested.get(ROOT).rowParent = 'entity:wrapper:0';
  nested.set(
    'entity:wrapper:0',
    entry({
      label: 'Wrapper',
      kind: 'entity',
      parent: null,
      rowParent: null,
      rows: [ROOT],
      tier: 'element',
      source: 'element',
    }),
  );
  const result = byAddress(planes(nested, ROOT, reachOver(nested, {}), ALL, []));
  assert.equal(result.get(ROOT).depth, 0);
  assert.equal(result.get(SECTION_A).depth, 1);
});

test('maxDepth is the deepest structural depth, ignoring the cap, the part tier counted while Markup is on', () => {
  assert.equal(maxDepth(index(), ROOT, OFF), 3);
  assert.equal(maxDepth(index(), ROOT, ON), 4);
});

test('the model never mutates the index', () => {
  const source = index();
  const before = JSON.stringify([...source]);
  planes(source, ROOT, reachOver(source, { [MARKUP_VIEW_ID]: true }), { ...ALL, isolate: true, depthCap: 2 }, [TEXT]);
  maxDepth(source, ROOT, reachOver(source, { [MARKUP_VIEW_ID]: true }));
  assert.equal(JSON.stringify([...source]), before);
});
