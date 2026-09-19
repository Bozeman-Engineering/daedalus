/**
 * Self-asserting tests for the slot-key grammar mirror.
 *
 * Run directly (`node tests/js/slot-key.test.mjs`) or via
 * PrinterEquivalenceTest, which executes this file inside the PHPUnit
 * suite. PHP is authoritative (\Drupal\daedalus_stylish\SlotKey, locked by
 * SlotKeyTest); these tests hold the mirror to the same grammar: the
 * degenerate key space, slot paths, root-kind-parameterized structural
 * rules, the scope-to-root mapping, and the format round trip. Exits
 * non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  ROOT_KINDS,
  compareSegments,
  parseSlotKey,
  formatSlotKey,
  rootKindForScope,
  fragmentStyles,
} from '../../js/src/slot-key.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('degenerate keys parse for every root kind, unknown included', () => {
  for (const rootKind of [...ROOT_KINDS, null]) {
    assert.deepEqual(parseSlotKey('', rootKind), { segments: [], part: null, children: false });
    assert.deepEqual(parseSlotKey('::.cta__button', rootKind), { segments: [], part: '.cta__button', children: false });
  }
});

test('container roots: child-first paths, recursion, part suffixes', () => {
  assert.deepEqual(parseSlotKey('block(text,2)', 'container'), {
    segments: [{ type: 'block', name: 'text', index: 2 }],
    part: null,
    children: false,
  });
  const deep = parseSlotKey('block(text,12)::.quote', 'container');
  assert.equal(deep.segments[0].index, 12);
  assert.equal(deep.part, '.quote');
  assert.deepEqual(
    parseSlotKey('block(image,1)/field(field_media)', 'container').segments[1],
    { type: 'field', name: 'field_media' },
  );
  // container(n) is the recursion point back into a child flow — a nested
  // container is an ordinary container (containers all the way down).
  assert.deepEqual(parseSlotKey('container(2)', 'container').segments[0], { type: 'container', index: 2 });
  assert.equal(
    parseSlotKey('container(1)/container(2)/block(text,1)', 'container').segments.length,
    3,
  );
  // A container root never opens at a field segment.
  assert.equal(parseSlotKey('field(field_media)', 'container'), null);
});

test('block roots: field-first paths, no index, no container entry', () => {
  assert.deepEqual(parseSlotKey('field(field_image)', 'block'), {
    segments: [{ type: 'field', name: 'field_image' }],
    part: null,
    children: false,
  });
  assert.notEqual(parseSlotKey('field(field_image)::.caption', 'block'), null);
  assert.equal(parseSlotKey('container(1)', 'block'), null);
  assert.equal(parseSlotKey('block(text,1)', 'block'), null);
  // One field per name: an index on a field segment never parses.
  assert.equal(parseSlotKey('field(field_image,1)', 'block'), null);
});

test('field, entity, region and unknown roots carry no paths', () => {
  for (const rootKind of ['field', 'entity', 'region', null]) {
    assert.equal(parseSlotKey('container(1)', rootKind), null);
    assert.equal(parseSlotKey('field(field_image)', rootKind), null);
    assert.equal(parseSlotKey('container(1)/block(text,1)', rootKind), null);
  }
});

test('segment ordering rules hold at every depth', () => {
  // The retired region segment is gone from the grammar entirely.
  assert.equal(parseSlotKey('region(first)', 'container'), null);
  assert.equal(parseSlotKey('region(first)/block(text,1)', 'container'), null);
  // The retired section segment spelling is gone too.
  assert.equal(parseSlotKey('section(1)', 'container'), null);
  assert.equal(parseSlotKey('container(1)/field(field_x)', 'container'), null);
  assert.equal(parseSlotKey('field(field_a)/field(field_b)', 'block'), null);
  assert.equal(parseSlotKey('block(text,1)/field(field_a)/field(field_b)', 'container'), null);
  // Blocks never recurse into containers; only containers do.
  assert.equal(parseSlotKey('block(text,1)/container(1)', 'container'), null);
  assert.equal(parseSlotKey('block(text,1)/block(text,2)', 'container'), null);
});

test('malformed keys never parse', () => {
  const bad = [
    'container()',
    'block(text)',
    'block(text,0)',
    'block(text,01)',
    'block(text, 1)',
    'block(Text,1)',
    'container(0)',
    'container(01)',
    'container(x)',
    'container(x,1)',
    'container(1)/',
    '/container(1)',
    'container(1)//block(text,1)',
    'container (1)',
    ' container(1)',
    'container(1) ',
    'region(first)',
    'section(1)',
    'container(1)::card',
    'container(1)::.a::.b',
    'container(1)::.a/block(text,1)',
    '::.',
    '::',
    '::.bad{selector}',
    '::.café',
    'not a key',
  ];
  for (const key of bad) {
    assert.equal(parseSlotKey(key, 'container'), null, `"${key}" must not parse.`);
  }
  // Non-strings are data, not keys.
  assert.equal(parseSlotKey(null, 'container'), null);
  assert.equal(parseSlotKey(42, 'container'), null);
  // The part charset is the class charset: hyphens and case are legal.
  assert.notEqual(parseSlotKey('::.Card-9_x', 'container'), null);
});

test('the ::> children pseudo-part terminates any key (PHP mirror)', () => {
  // Bare root children.
  assert.deepEqual(parseSlotKey('::>', 'container'), { segments: [], part: null, children: true });
  // A part's children: the part suffix keeps parsing, children rides last.
  assert.deepEqual(parseSlotKey('::.daedalus-stylish-layout-container::>', 'container'), {
    segments: [],
    part: '.daedalus-stylish-layout-container',
    children: true,
  });
  // Slot paths compose too, with and without a part.
  assert.deepEqual(parseSlotKey('container(1)::>', 'container'), {
    segments: [{ type: 'container', index: 1 }],
    part: null,
    children: true,
  });
  assert.equal(parseSlotKey('container(1)/block(text,1)::.card::>', 'container').children, true);
  // The children suffix parses for every root kind, unknown included.
  for (const rootKind of [...ROOT_KINDS, null]) {
    assert.equal(parseSlotKey('::>', rootKind).children, true);
  }
  // It is the LAST suffix and never doubles.
  assert.equal(parseSlotKey('::>::.card', 'container'), null);
  assert.equal(parseSlotKey('::>::>', 'container'), null);
  assert.equal(parseSlotKey('::>x', 'container'), null);
  assert.equal(parseSlotKey('::.card::>::>', 'container'), null);
});

test('format round-trips every canonical key losslessly', () => {
  const keys = [
    '',
    '::.cta__button',
    'container(1)',
    'container(1)::.card',
    'block(text,2)',
    'container(1)/container(2)/block(image,3)/field(field_media)::.caption',
    '::>',
    '::.daedalus-stylish-layout-container::>',
    'container(1)::>',
    'container(1)/block(text,1)::.card::>',
  ];
  for (const key of keys) {
    const parsed = parseSlotKey(key, 'container');
    assert.notEqual(parsed, null, `"${key}" must parse.`);
    assert.equal(formatSlotKey(parsed.segments, parsed.part, parsed.children), key);
  }
  const block = parseSlotKey('field(field_image)::.caption', 'block');
  assert.equal(formatSlotKey(block.segments, block.part), 'field(field_image)::.caption');
});

test('rootKindForScope maps the scope vocabulary structurally', () => {
  assert.equal(rootKindForScope('kind:container'), 'container');
  assert.equal(rootKindForScope('layout:daedalus_stylish_container'), 'container');
  assert.equal(rootKindForScope('kind:section'), null);
  assert.equal(rootKindForScope('kind:block'), 'block');
  assert.equal(rootKindForScope('block_type:text'), 'block');
  assert.equal(rootKindForScope('kind:field'), 'field');
  assert.equal(rootKindForScope('kind:entity'), 'entity');
  assert.equal(rootKindForScope('kind:region'), 'region');
  assert.equal(rootKindForScope('kind:bogus'), null);
  assert.equal(rootKindForScope('block_type:'), null);
  assert.equal(rootKindForScope('layout:Bad-Name'), null);
  assert.equal(rootKindForScope('bogus'), null);
  assert.equal(rootKindForScope(''), null);
  assert.equal(rootKindForScope(undefined), null);
});

test('an unknown non-null root kind is a caller bug and throws', () => {
  assert.throws(() => parseSlotKey('', 'bogus'), /not a slot-key root kind/);
});

test('fragmentStyles keys one fragment by part suffix (PHP mirror)', () => {
  const styles = {
    '': { default: { padding: '4rem' } },
    '::.inner': { default: { margin: '0' } },
    '::.inner::>': { default: { flex: '1 1 0' } },
    '::>': { default: { 'min-width': '0' } },
    'container(1)': { default: { display: 'flex' } },
    'block(text,2)': { default: { color: 'red' } },
    'block(text,2)::.quote': { default: { 'font-style': 'italic' } },
    'block(text,2)/field(field_body)': { default: { color: 'blue' } },
  };
  // The '' fragment reads the root entry and its suffixed entries (parts
  // and children pseudo-parts alike); slot paths are OTHER fragments and
  // never read.
  assert.deepEqual(fragmentStyles(styles, ''), {
    '': { default: { padding: '4rem' } },
    '::.inner': { default: { margin: '0' } },
    '::.inner::>': { default: { flex: '1 1 0' } },
    '::>': { default: { 'min-width': '0' } },
  });
  // A distributed fragment reads its own entry and part entries; deeper
  // paths under it (the field fragment) belong to their own carriers.
  assert.deepEqual(fragmentStyles(styles, 'block(text,2)'), {
    '': { default: { color: 'red' } },
    '::.quote': { default: { 'font-style': 'italic' } },
  });
  // An unheld fragment is valid but inert; empty and non-object entries
  // drop like the emit gates drop them.
  assert.deepEqual(fragmentStyles(styles, 'container(2)'), {});
  assert.deepEqual(fragmentStyles({ '': {}, '::.x': 'bogus' }, ''), {});
  assert.deepEqual(fragmentStyles(null, ''), {});
});

test('fragmentStyles reads values only: nested references are stripped', () => {
  // Entry-shaped fragments may carry a "variants" reference list; it
  // distributes at APPLY time (bind-once), so the carrier's fragment
  // lookup never sees it — a ref-only entry reads as unheld.
  const styles = {
    '': { variants: [{ variant: 'mood', fragment: '' }], default: { padding: '4rem' } },
    'container(1)': { variants: [{ variant: 'inner', fragment: '' }] },
  };
  assert.deepEqual(fragmentStyles(styles, ''), {
    '': { default: { padding: '4rem' } },
  });
  assert.deepEqual(fragmentStyles(styles, 'container(1)'), {});
});

test('compareSegments orders parents before children, siblings by index then name', () => {
  const seg = (key, kind) => parseSlotKey(key, kind).segments;
  // A parent path precedes everything under it.
  assert.ok(compareSegments(seg('container(1)', 'container'), seg('container(1)/block(text,1)', 'container')) < 0);
  // Siblings order by index...
  assert.ok(compareSegments(seg('block(text,1)', 'container'), seg('block(text,2)', 'container')) < 0);
  // ...then by name at the same index.
  assert.ok(compareSegments(seg('block(intro,1)', 'container'), seg('block(text,1)', 'container')) < 0);
  // The root ([] — no segments) precedes every path; equals tie at 0.
  assert.ok(compareSegments([], seg('block(text,1)', 'container')) < 0);
  assert.equal(compareSegments(seg('block(text,1)', 'container'), seg('block(text,1)', 'container')), 0);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} slot-key tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} slot-key tests passed.`);
