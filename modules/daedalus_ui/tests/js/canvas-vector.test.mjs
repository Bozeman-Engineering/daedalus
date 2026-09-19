/**
 * @file
 * Locks the canvas state vector's pure halves: the state store's grammar
 * (vector-state.js) and the scheme/motion media rewriting
 * (emulate-media.js). Run with: node --test tests/js/canvas-vector.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  createVector,
  normalizeWidth,
  parseBootState,
  MIN_WIDTH,
  MAX_WIDTH,
} from '../../js/canvas_vector/vector-state.js';
import {
  rewriteMediaText,
  hasEmulatableTerm,
  applyToSheets,
} from '../../js/canvas_vector/emulate-media.js';

// ---------------------------------------------------------------------------
// vector-state
// ---------------------------------------------------------------------------

test('the default state is full width, auto scheme, auto motion', () => {
  const vector = createVector();
  assert.deepEqual(vector.get(), { width: null, scheme: 'auto', motion: 'auto' });
});

test('set() normalizes, clamps, and reports the changed axes', () => {
  const vector = createVector();
  assert.deepEqual(vector.set({ width: 375, scheme: 'dark' }), ['width', 'scheme']);
  assert.deepEqual(vector.get(), { width: 375, scheme: 'dark', motion: 'auto' });

  // Clamping to the bounds.
  vector.set({ width: 10 });
  assert.equal(vector.get().width, MIN_WIDTH);
  vector.set({ width: 99999 });
  assert.equal(vector.get().width, MAX_WIDTH);

  // Numeric strings are accepted (query params arrive as strings).
  vector.set({ width: '640' });
  assert.equal(vector.get().width, 640);

  // null / 'full' both mean the full canvas.
  vector.set({ width: 'full' });
  assert.equal(vector.get().width, null);
});

test('unrecognized values are ignored, not applied and not reported', () => {
  const vector = createVector();
  assert.deepEqual(vector.set({ width: 'bogus', scheme: 'sepia', motion: 'jitter' }), []);
  assert.deepEqual(vector.get(), { width: null, scheme: 'auto', motion: 'auto' });
});

test('setting the current value is not a change', () => {
  const vector = createVector();
  vector.set({ scheme: 'dark' });
  assert.deepEqual(vector.set({ scheme: 'dark' }), []);
});

test('subscribers hear each change once, with the changed axes', () => {
  const vector = createVector();
  const heard = [];
  const unsubscribe = vector.subscribe((state, changed) => heard.push([state.width, changed]));
  vector.set({ width: 375 });
  vector.set({ width: 375 });
  unsubscribe();
  vector.set({ width: 640 });
  assert.deepEqual(heard, [[375, ['width']]]);
});

test('get() returns a copy, never the live state', () => {
  const vector = createVector();
  vector.get().scheme = 'dark';
  assert.equal(vector.get().scheme, 'auto');
});

test('normalizeWidth distinguishes "full" from "not a width"', () => {
  assert.equal(normalizeWidth(null), null);
  assert.equal(normalizeWidth(''), null);
  assert.equal(normalizeWidth('full'), null);
  assert.equal(normalizeWidth('abc'), undefined);
  assert.equal(normalizeWidth(414.4), 414);
});

test('parseBootState reads only the recognized canvas parameters', () => {
  assert.deepEqual(
    parseBootState('?canvasWidth=375&canvasScheme=dark&canvasMotion=reduce&other=1'),
    { width: 375, scheme: 'dark', motion: 'reduce' },
  );
  assert.deepEqual(parseBootState('?canvasScheme=sepia&canvasWidth=junk'), {});
  assert.deepEqual(parseBootState(''), {});
  // The initial state seeds through the constructor.
  const vector = createVector(parseBootState('?canvasWidth=768'));
  assert.equal(vector.get().width, 768);
});

// ---------------------------------------------------------------------------
// emulate-media
// ---------------------------------------------------------------------------

const TRUE_TERM = '(min-width: 0px)';
const FALSE_TERM = '(max-width: 0px)';

test('forcing a scheme substitutes both polarity terms', () => {
  assert.equal(
    rewriteMediaText('(prefers-color-scheme: dark)', { scheme: 'dark' }),
    TRUE_TERM,
  );
  assert.equal(
    rewriteMediaText('(prefers-color-scheme: light)', { scheme: 'dark' }),
    FALSE_TERM,
  );
  assert.equal(
    rewriteMediaText('(prefers-color-scheme: dark)', { scheme: 'light' }),
    FALSE_TERM,
  );
});

test('rewriting is term-local: the rest of the condition survives', () => {
  assert.equal(
    rewriteMediaText('screen and (min-width: 700px) and (prefers-color-scheme: dark)', { scheme: 'dark' }),
    `screen and (min-width: 700px) and ${TRUE_TERM}`,
  );
  // `not` and whitespace/case variants stay intact around the term.
  assert.equal(
    rewriteMediaText('not ( Prefers-Color-Scheme :  DARK )', { scheme: 'dark' }),
    `not ${TRUE_TERM}`,
  );
});

test('motion forces both directions and auto leaves text untouched', () => {
  assert.equal(
    rewriteMediaText('(prefers-reduced-motion: reduce)', { motion: 'reduce' }),
    TRUE_TERM,
  );
  assert.equal(
    rewriteMediaText('(prefers-reduced-motion: reduce)', { motion: 'full' }),
    FALSE_TERM,
  );
  assert.equal(
    rewriteMediaText('(prefers-reduced-motion: no-preference)', { motion: 'full' }),
    TRUE_TERM,
  );
  const original = '(prefers-color-scheme: dark), (prefers-reduced-motion: reduce)';
  assert.equal(rewriteMediaText(original, { scheme: 'auto', motion: 'auto' }), original);
});

test('axes rewrite independently in one condition', () => {
  assert.equal(
    rewriteMediaText('(prefers-color-scheme: dark) and (prefers-reduced-motion: reduce)', { scheme: 'dark', motion: 'auto' }),
    `${TRUE_TERM} and (prefers-reduced-motion: reduce)`,
  );
});

test('hasEmulatableTerm spots either axis and nothing else', () => {
  assert.equal(hasEmulatableTerm('(prefers-color-scheme: dark)'), true);
  assert.equal(hasEmulatableTerm('(prefers-reduced-motion: no-preference)'), true);
  assert.equal(hasEmulatableTerm('(min-width: 700px)'), false);
});

/**
 * A stub conditional rule; nested rules ride cssRules like the CSSOM.
 */
const mediaRule = (mediaText, nested = []) => ({
  media: { mediaText },
  cssRules: nested,
});

test('applyToSheets rewrites, restores, and never compounds', () => {
  const dark = mediaRule('(prefers-color-scheme: dark)');
  const width = mediaRule('(min-width: 700px)');
  const sheets = [{ cssRules: [dark, width] }];
  const originals = new WeakMap();

  applyToSheets(sheets, { scheme: 'dark', motion: 'auto' }, originals);
  assert.equal(dark.media.mediaText, TRUE_TERM);
  assert.equal(width.media.mediaText, '(min-width: 700px)');

  // A second application starts from the REMEMBERED original, not the
  // rewritten text, so flipping the axis lands on the other substitution.
  applyToSheets(sheets, { scheme: 'light', motion: 'auto' }, originals);
  assert.equal(dark.media.mediaText, FALSE_TERM);

  // Full auto restores the authored condition verbatim.
  applyToSheets(sheets, { scheme: 'auto', motion: 'auto' }, originals);
  assert.equal(dark.media.mediaText, '(prefers-color-scheme: dark)');
});

test('applyToSheets walks nested group rules and skips unreadable sheets', () => {
  const nested = mediaRule('(prefers-reduced-motion: reduce)');
  const outer = mediaRule('(min-width: 700px)', [nested]);
  const sheets = [
    {
      get cssRules() {
        throw new DOMException('cross-origin');
      },
    },
    { cssRules: [outer] },
  ];
  const originals = new WeakMap();
  applyToSheets(sheets, { scheme: 'auto', motion: 'reduce' }, originals);
  assert.equal(nested.media.mediaText, TRUE_TERM);
  assert.equal(outer.media.mediaText, '(min-width: 700px)');
});
