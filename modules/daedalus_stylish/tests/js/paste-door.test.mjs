/**
 * Self-asserting tests for the paste door's pure half (paste-door.js).
 *
 * Run directly (`node tests/js/paste-door.test.mjs`) or via JsSuitesTest
 * in the PHP tier. Locks what the Island treats as structure (the
 * chunks csstree and the brace scan find, closed blocks only, the
 * declarations around them kept), what it sends the door under which
 * key (a copied `#id` block to the element, everything else to the
 * island wrapped in its condition), what the door's answer becomes (one
 * additive update_style batch plus apply_variant for wear; a document
 * slot per entry), and where a corrective's line lands in the buffer.
 * Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  blankChunks,
  conditionWrap,
  documentSlot,
  doorRequest,
  entriesToOps,
  errorLines,
  lineCount,
  structuralChunks,
} from '../../js/src/code/paste-door.js';

const guide = {
  conditions: {
    hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
    narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
    dark: { label: 'Dark', wrapper: '@media (prefers-color-scheme: dark)', axis: 'scheme' },
  },
};

const ROOT = 'container:11111111-1111-1111-1111-111111111111';
const RULE = `${ROOT}::> pb-text`;
const OTHER = '22222222-2222-2222-2222-222222222222';

let current = null;
const test = (name, run) => {
  current = name;
  run();
  process.stdout.write(`ok - ${name}\n`);
};

process.on('uncaughtException', (error) => {
  process.stderr.write(`FAIL - ${current}\n${error.stack ?? error.message}\n`);
  process.exit(1);
});

// --- Chunk extraction --------------------------------------------------------

test('a nested rule beside a declaration is one chunk; the declaration is not', () => {
  const text = '&:hover { color: red; }\npadding: 1rem;';
  const chunks = structuralChunks(text);
  assert.equal(chunks.length, 1);
  assert.deepEqual(
    { ...chunks[0] },
    { from: 0, to: 23, text: '&:hover { color: red; }', kind: 'rule', prelude: '&:hover', root: false },
  );
});

test('an at-rule wrapper is a chunk, a known condition or not', () => {
  assert.deepEqual(
    structuralChunks('@container (max-width: 767px) { color: red; }').map((chunk) => [chunk.kind, chunk.prelude]),
    [['atrule', '@container (max-width: 767px)']],
  );
  assert.deepEqual(
    structuralChunks('@supports (display: grid) { gap: 1rem; }').map((chunk) => chunk.kind),
    ['atrule'],
  );
});

test('a copied #id block is a root chunk, and the declaration after it is read again', () => {
  const text = `#${OTHER} { color: red; &:hover { color: blue } }\npadding: 1rem;`;
  const chunks = structuralChunks(text);
  assert.equal(chunks.length, 1);
  assert.equal(chunks[0].kind, 'block');
  assert.equal(chunks[0].root, true);
  assert.equal(chunks[0].text, `#${OTHER} { color: red; &:hover { color: blue } }`);
  // The tail is not swallowed: blanked, the buffer parses its declaration.
  assert.equal(blankChunks(text, chunks).trim(), 'padding: 1rem;');
});

test('a part block and a copied rule block in one text are two chunks in order', () => {
  const text = `.quote { color: red; }\n#${OTHER} > pb-text:hover { gap: 1rem }`;
  const chunks = structuralChunks(text);
  assert.deepEqual(
    chunks.map((chunk) => [chunk.prelude, chunk.root]),
    [['.quote', false], [`#${OTHER} > pb-text:hover`, true]],
  );
  assert.equal(chunks[1].from, 23);
  assert.equal(text.slice(chunks[1].from, chunks[1].to), chunks[1].text);
});

test('a relative-selector block and a tag block are chunks (the grammar knows them)', () => {
  assert.deepEqual(
    structuralChunks('> pb-text { color: red; }').map((chunk) => chunk.prelude),
    ['> pb-text'],
  );
  assert.deepEqual(structuralChunks('p { color: red; }').map((chunk) => chunk.prelude), ['p']);
});

test('an unclosed block is not a chunk (mid-typing; the door takes it once it closes)', () => {
  assert.deepEqual(structuralChunks('&:hover { color: red;'), []);
  assert.deepEqual(structuralChunks('.quote { color: red;'), []);
  assert.deepEqual(structuralChunks('@container (max-width: 767px) { color: red;'), []);
});

test('a plain declaration list has no chunks; a block swallowed by a lost semicolon waits for it', () => {
  assert.deepEqual(structuralChunks('color: red;\npadding: 1rem;'), []);
  assert.deepEqual(structuralChunks(''), []);
  // csstree reads the block as the broken declaration's value: the
  // lint's lost-semicolon regime holds both until the semicolon lands.
  assert.deepEqual(structuralChunks('padding: 1rem\n.quote { color: red; }'), []);
});

test('a block between declarations leaves both declarations readable', () => {
  const text = 'padding: 1rem; &:hover { color: red; } margin: 0;';
  const chunks = structuralChunks(text);
  assert.deepEqual(chunks.map((chunk) => chunk.text), ['&:hover { color: red; }']);
  assert.equal(blankChunks(text, chunks), 'padding: 1rem;                         margin: 0;');
});

test('blanking keeps the length and every newline, so offsets and lines hold', () => {
  const text = 'gap: 0;\n&:hover {\n  color: red;\n}\nmargin: 0;';
  const blanked = blankChunks(text, structuralChunks(text));
  assert.equal(blanked.length, text.length);
  assert.equal(lineCount(blanked), lineCount(text));
  assert.equal(blanked.split('\n')[4], 'margin: 0;');
});

// --- The request -------------------------------------------------------------

test('conditionWrap spells the island condition as nesting on one line each side', () => {
  assert.equal(conditionWrap('color: red;', 'default', guide), 'color: red;');
  assert.equal(conditionWrap('color: red;', 'hover', guide), '&:hover { color: red; }');
  assert.equal(
    conditionWrap('color: red;', 'narrow', guide),
    '@container (max-width: 767px) { color: red; }',
  );
  assert.equal(
    conditionWrap('color: red;', 'hover+narrow', guide),
    '@container (max-width: 767px) { &:hover { color: red; } }',
  );
  assert.equal(
    conditionWrap('color: red;', 'hover+narrow+dark', guide),
    '@container (max-width: 767px) { @media (prefers-color-scheme: dark) { &:hover { color: red; } } }',
  );
  // Lines are the text's: the wrapper adds none.
  const wrapped = conditionWrap('color: red;\n&:focus { gap: 0 }', 'hover', guide);
  assert.equal(lineCount(wrapped), 2);
  assert.ok(wrapped.startsWith('&:hover { color: red;\n'));
});

test('a copied #id block goes to the element key unwrapped in the element document', () => {
  const text = `#${OTHER} { color: red; }\n#${OTHER}:hover { gap: 0 }`;
  const request = doorRequest(text, structuralChunks(text), {
    mapKey: RULE,
    rootKey: ROOT,
    condition: 'hover',
    guide,
  });
  assert.deepEqual(request, { key: ROOT, css: text });
});

test('a rule body goes to the island key wrapped in the island condition', () => {
  const text = '&:focus { gap: 0 }\ncolor: red;';
  assert.deepEqual(
    doorRequest(text, structuralChunks(text), { mapKey: RULE, rootKey: ROOT, condition: 'hover', guide }),
    { key: RULE, css: `&:hover { ${text} }` },
  );
  assert.deepEqual(
    doorRequest(text, structuralChunks(text), { mapKey: ROOT, rootKey: ROOT, condition: 'default', guide }),
    { key: ROOT, css: text },
  );
});

test('a document island always sends to its own anchoring key', () => {
  const text = `#${OTHER} { color: red; }`;
  assert.deepEqual(
    doorRequest(text, structuralChunks(text), {
      mapKey: RULE,
      rootKey: ROOT,
      condition: 'default',
      guide,
      document: true,
    }),
    { key: RULE, css: text },
  );
});

// --- The answer --------------------------------------------------------------

test('entries become one additive update_style batch, default bare, wear as apply_variant after', () => {
  const { operations, count } = entriesToOps(
    [
      { key: ROOT, condition: 'default', properties: { color: 'red', gap: '1rem' } },
      { key: ROOT, condition: 'hover', properties: { color: 'blue' } },
      { key: RULE, condition: 'default', properties: {} },
      { key: RULE, condition: 'narrow', properties: { margin: '0' } },
    ],
    [{ key: RULE, variant: 'band' }],
  );
  assert.equal(count, 4);
  assert.deepEqual(operations, [
    { op: 'update_style', key: ROOT, properties: { color: 'red', gap: '1rem' } },
    { op: 'update_style', key: ROOT, condition: 'hover', properties: { color: 'blue' } },
    { op: 'update_style', key: RULE, condition: 'narrow', properties: { margin: '0' } },
    { op: 'apply_variant', key: RULE, variant: 'band' },
  ]);
  // Nothing unsets: a pasted block sets what it names.
  assert.ok(operations.every((op) => Object.values(op.properties ?? {}).every((value) => value !== null)));
  assert.deepEqual(entriesToOps(), { operations: [], count: 0 });
});

test('documentSlot maps a door key back onto the island slot, continued by the nested tail', () => {
  assert.equal(documentSlot(ROOT, ROOT, ''), '');
  assert.equal(documentSlot(ROOT, ROOT, 'block(text,1)'), 'block(text,1)');
  assert.equal(documentSlot(`${ROOT}::> pb-text`, ROOT, ''), '::> pb-text');
  assert.equal(documentSlot(`${ROOT}::> pb-text`, ROOT, 'block(text,1)'), 'block(text,1)::> pb-text');
  assert.equal(documentSlot(`${RULE} > a`, RULE, 'block(text,1)::.quote'), 'block(text,1)::.quote > a');
  // A stranger: another element, or a key that merely shares a prefix.
  assert.equal(documentSlot(`container:${OTHER}`, ROOT, ''), null);
  assert.equal(documentSlot(`${RULE}.x`, RULE, ''), null);
});

// --- Error lines -------------------------------------------------------------

test('a corrective lands on its buffer line through the sent segments, hint appended', () => {
  const errors = [
    { code: 'UNKNOWN_STATE', message: 'Unknown state ":hovr" (line 2)', hint: 'Did you mean :hover?', context: { line: 2 } },
    { code: 'BAD_SELECTOR', message: 'Bad selector', context: { line: 4 } },
    { code: 'WEAR_ON_ELEMENT', message: 'Wear on the element' },
  ];
  // Two chunks sent joined: three lines from buffer line 5, one from 12.
  const segments = [
    { startLine: 5, lines: 3 },
    { startLine: 12, lines: 1 },
  ];
  assert.deepEqual(errorLines(errors, segments), [
    { line: 6, message: 'Unknown state ":hovr" (line 2) Did you mean :hover?' },
    { line: 12, message: 'Bad selector' },
    { line: 5, message: 'Wear on the element' },
  ]);
  // A line past the sent text falls back to the first sent line.
  assert.deepEqual(errorLines([{ message: 'x', context: { line: 9 } }], segments), [{ line: 5, message: 'x' }]);
  // No segments (the view is gone): line 1, the message still reads.
  assert.deepEqual(errorLines([{ message: 'x', context: { line: 2 } }], []), [{ line: 1, message: 'x' }]);
});

process.stdout.write('paste-door tests passed.\n');
