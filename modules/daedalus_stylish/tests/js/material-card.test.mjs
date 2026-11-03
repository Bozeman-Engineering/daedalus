/**
 * Self-asserting tests for the one material card and its row
 * (material-card.js): the row's anatomy (preview, name, value summary,
 * NO origin pill), the origin sentence an open card states, the reasons a
 * shown identity gesture is disabled with, and the use line.
 *
 * Run directly (`node tests/js/material-card.test.mjs`) or with the rest
 * of the suite (`node --test tests/js/*.test.mjs`). Renders the row's
 * vnode tree (the JSX compiled by jsx-loader.mjs) and reads the pure
 * helpers the card phrases its sentences with. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { registerJsx } from './jsx-loader.mjs';
import {
  animationRecord,
  fontRecord,
  roster,
  tokenRecord,
} from './materials-fixture.mjs';

registerJsx();

globalThis.Drupal = {
  t: (text, args = {}) =>
    Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
  formatPlural: (count, singular, plural, args = {}) =>
    Object.entries({ '@count': count, ...args }).reduce(
      (out, [key, value]) => out.split(key).join(String(value)),
      count === 1 ? singular : plural,
    ),
  url: (path) => `/${path}`,
};

const { MaterialRow, lockReason, originSentence, rowSummary, usageText } = await import(
  '../../js/src/material-card.js'
);
const { materialCardGestures, skinSections } = await import('../../js/src/skin-model.js');

const tests = [];
const test = (name, run) => tests.push([name, run]);

/**
 * Every host vnode in the tree the predicate accepts, document order.
 */
const find = (node, predicate, found = []) => {
  if (node == null || typeof node !== 'object') {
    return found;
  }
  if (Array.isArray(node)) {
    node.forEach((child) => find(child, predicate, found));
    return found;
  }
  if (typeof node.type === 'function') {
    return find(node.type(node.props), predicate, found);
  }
  if (predicate(node)) {
    found.push(node);
  }
  return find(node.props.children, predicate, found);
};

/**
 * The text a vnode tree renders, host elements flattened.
 */
const text = (node) => {
  if (node == null || typeof node === 'boolean') {
    return '';
  }
  if (Array.isArray(node)) {
    return node.map(text).join('');
  }
  if (typeof node !== 'object') {
    return String(node);
  }
  if (typeof node.type === 'function') {
    return text(node.type(node.props));
  }
  return text(node.props.children);
};

const classes = (node) => String(node.props.className ?? '').split(/\s+/).filter(Boolean);

const GUIDE = {
  conditions: { dark: { label: 'Dark', axis: 'scheme' } },
  tokenScales: {
    color: { label: 'Colors', shape: 'color', prefix: 'color' },
    space: { label: 'Spacing', shape: 'dimension', prefix: 'space' },
  },
};
const REGULAR = { weight: '400', style: 'normal', source: { file: 'f-regular' } };
const BOLD = { weight: '700', style: 'normal', source: { file: 'f-bold' } };
const ACCENT = tokenRecord('color', 'accent', { default: '#1a1a2e', dark: '#e8e8f0' });
const HERO = tokenRecord('space', 'hero', '3rem', 'space', { origin: 'user', description: 'The hero gap.' });
const FADE = animationRecord('fade_in', 'fade_in', { '0%': { opacity: '0' }, '50%': { opacity: '0.5' }, '100%': { opacity: '1' } }, { origin: 'shipped' });
const INTER = fontRecord('inter', 'inter', 'Inter', [REGULAR, BOLD]);
const MATERIALS = roster(ACCENT, HERO, FADE, INTER);
const rows = Object.fromEntries(
  skinSections(GUIDE, MATERIALS).flatMap((section) => section.rows.map((row) => [row.reference, row])),
);

const GRANTS = { canCreate: true, canUpdate: true, canDelete: true, canDeleteInUse: true };
const CONSUMING = { usages: 0, layer: 'package', locked: true, provider: 'olivero', shipped_state: 'unmodified' };
const OVERRIDDEN = { ...CONSUMING, layer: 'both', shipped_state: 'modified' };
const MINTED = { usages: 2, layer: 'site', locked: true, provider: 'olivero', shipped_state: null };

test('rowSummary: a token\'s value, an animation\'s step count, a font\'s face count', () => {
  assert.equal(rowSummary(rows['color/accent']), '#1a1a2e');
  assert.equal(rowSummary(rows['space/hero']), '3rem');
  assert.equal(rowSummary(rows.fade_in), '3 steps');
  assert.equal(rowSummary(rows.inter), '2 faces');
  assert.equal(rowSummary({ kind: 'animation', offsets: ['0%'] }), '1 step');
  assert.equal(rowSummary({ kind: 'font', faces: ['400'] }), '1 face');
});

test('MaterialRow: a button carrying its material, with no origin pill whatever ships it', () => {
  for (const [reference, row] of Object.entries(rows)) {
    const tree = MaterialRow({ row, open: false, schemeLabel: 'Dark', onToggle: () => {} });
    assert.equal(tree.type, 'button');
    assert.equal(tree.props['data-material'], `${row.kind}:${reference}`);
    assert.equal(tree.props[`data-${row.kind}`], reference);
    assert.equal(tree.props['aria-expanded'], false);
    assert.ok(classes(tree).includes('daedalus-stylish-skin__row'));
    assert.ok(classes(tree).includes(`daedalus-stylish-skin__row--${row.kind}`));
    // No chip of any kind on a row: shipped-ness is the open card's
    // sentence, not a pill.
    const chips = find(tree, (node) => classes(node).includes('daedalus-ui-chip'));
    assert.deepEqual(chips, [], `${reference} renders no chip`);
    assert.equal(text(tree).includes('shipped'), false, reference);
    assert.equal(text(tree).includes('Built-in'), false, reference);
    assert.equal(text(tree).includes('not offered'), false, reference);
  }
  // The shipped token and the site-minted one render the same anatomy.
  const shipped = MaterialRow({ row: rows['color/accent'], open: false, onToggle: () => {} });
  const own = MaterialRow({ row: rows['space/hero'], open: false, onToggle: () => {} });
  // The row's parts (the button itself wears the kind modifier, not a part).
  const part = (name) => /^daedalus-stylish-skin__row-[a-z]/.test(name);
  const anatomy = (tree) => find(tree, (node) => classes(node).some(part)).map((node) => classes(node).filter(part).join(' '));
  assert.deepEqual(anatomy(shipped), ['daedalus-stylish-skin__row-face', 'daedalus-stylish-skin__row-name', 'daedalus-stylish-skin__row-value', 'daedalus-stylish-skin__row-value daedalus-stylish-skin__row-value--dark']);
  assert.deepEqual(anatomy(own), ['daedalus-stylish-skin__row-name', 'daedalus-stylish-skin__row-value']);
});

test('MaterialRow: the preview is a face for a color token, the family for a font, nothing for the rest', () => {
  const accent = MaterialRow({ row: rows['color/accent'], open: true, schemeLabel: 'Dark', onToggle: () => {} });
  const [face] = find(accent, (node) => classes(node).includes('daedalus-stylish-skin__row-face'));
  assert.equal(face.props.style.background, '#1a1a2e');
  assert.ok(classes(face).includes('daedalus-stylish-face--paired'), 'the scheme half splits the face');
  const [, dark] = find(accent, (node) => classes(node).includes('daedalus-stylish-skin__row-value'));
  assert.equal(text(dark), '#e8e8f0');
  assert.equal(dark.props.title, 'The value under the Dark scheme.');
  assert.equal(accent.props['aria-expanded'], true);
  assert.ok(classes(accent).includes('daedalus-stylish-skin__row--open'));

  const inter = MaterialRow({ row: rows.inter, open: false, onToggle: () => {} });
  assert.deepEqual(find(inter, (node) => classes(node).includes('daedalus-stylish-skin__row-face')), []);
  const [name] = find(inter, (node) => classes(node).includes('daedalus-stylish-skin__row-name'));
  assert.deepEqual(name.props.style, { fontFamily: '"Inter"' });
  assert.equal(inter.props.title, '"Inter"');

  const fade = MaterialRow({ row: rows.fade_in, open: false, onToggle: () => {} });
  assert.deepEqual(find(fade, (node) => classes(node).includes('daedalus-stylish-skin__row-face')), []);
  assert.equal(fade.props.title, 'motion-fade_in');
});

test('MaterialRow: the click is the toggle', () => {
  let toggled = 0;
  const tree = MaterialRow({ row: rows.fade_in, open: false, onToggle: () => { toggled += 1; } });
  tree.props.onClick();
  assert.equal(toggled, 1);
});

test('originSentence: who shipped it and that the edit layers over it; nothing for the site\'s own', () => {
  assert.equal(originSentence(rows['space/hero'], MINTED, materialCardGestures(HERO, GRANTS, MINTED)), null);
  assert.equal(
    originSentence(rows['color/accent'], CONSUMING, materialCardGestures(ACCENT, GRANTS, CONSUMING)),
    'Shipped by olivero. Your value layers over it.',
  );
  assert.equal(
    originSentence(rows.fade_in, CONSUMING, materialCardGestures(FADE, GRANTS, CONSUMING)),
    'Shipped by olivero. Your steps layers over it.'.replace('layers', 'layers'),
  );
  // The revert tail joins only while the gesture is live.
  assert.equal(
    originSentence(rows['color/accent'], OVERRIDDEN, materialCardGestures(ACCENT, GRANTS, OVERRIDDEN)),
    'Shipped by olivero. Your value layers over it. Revert to defaults restores the shipped one.',
  );
  assert.equal(
    originSentence(rows['color/accent'], OVERRIDDEN, materialCardGestures(ACCENT, {}, OVERRIDDEN)),
    'Shipped by olivero. Your value layers over it.',
  );
  // Before the read lands the sentence names a module.
  assert.equal(
    originSentence(rows['color/accent'], null, materialCardGestures(ACCENT, GRANTS, null)),
    'Shipped by a module. Your value layers over it.',
  );
});

test('lockReason: the disabled gesture\'s reason, in the card\'s words', () => {
  assert.equal(lockReason('rename', null, CONSUMING), null);
  assert.equal(lockReason('delete', undefined, CONSUMING), null);
  assert.equal(lockReason('rename', 'shipped', CONSUMING), "Shipped by olivero; rename it in the module's skins file.");
  assert.equal(lockReason('delete', 'shipped', CONSUMING), 'Shipped by olivero; withhold it from the AI instead.');
  assert.equal(lockReason('rename', 'shipped', null), "Shipped by its module; rename it in the module's skins file.");
  assert.equal(lockReason('delete', 'in-use', MINTED), 'In use: deleting it needs the "delete skin materials in use" permission.');
});

test('usageText: the live count in the kind\'s words', () => {
  assert.equal(usageText('token', null), 'counting uses…');
  assert.equal(usageText('token', { usages: 0 }), 'used by nothing');
  assert.equal(usageText('token', { usages: 1 }), 'used in 1 place');
  assert.equal(usageText('token', { usages: 3 }), 'used in 3 places');
  assert.equal(usageText('animation', { usages: 0 }), 'worn by nothing');
  assert.equal(usageText('animation', { usages: 2 }), 'worn in 2 places');
  assert.equal(usageText('font', { usages: 0 }), 'named by nothing');
  assert.equal(usageText('font', { usages: 1 }), 'named in 1 place');
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} material-card tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} material-card tests passed.`);
