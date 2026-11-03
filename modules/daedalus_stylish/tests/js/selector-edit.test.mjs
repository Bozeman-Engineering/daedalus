/**
 * Self-asserting tests for the selector edit model (code/selector-edit.js):
 * the pure gesture model behind a rule row's editable prelude.
 *
 * Run directly (`node tests/js/selector-edit.test.mjs`) or via
 * JsSuitesTest in the PHP tier. Locks the four outcomes the chrome
 * dispatches over the digester: COMMIT (Enter or `{` on text that digests
 * to a rule selector differing from the row's, `to` canonical; a typed
 * list folded into its one `:is()` rule), NOOP (the same selector,
 * however spelled), REFUSED (the digester's refusals, a wrapper alone,
 * the element itself, and a state that would PEEL off the row: the
 * editor stays, the reason names why), CANCEL (Escape or blur,
 * whatever the text), plus the per-keystroke preview (the structural
 * reach while the text digests to one rule selector, null otherwise).
 * Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  CANCEL,
  COMMIT,
  LEAVE_GESTURES,
  NOOP,
  REFUSED,
  SETTLE_GESTURES,
  openSelectorEdit,
  selectorEditOutcome,
  selectorEditPreview,
  typedSelectorEdit,
} from '../../js/src/code/selector-edit.js';

const tests = [];
const test = (name, run) => tests.push([name, run]);

const conditions = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  focus: { label: 'Focus', wrapper: ':focus', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
};
const options = { conditions };

const typed = (selector, text) => typedSelectorEdit(openSelectorEdit(selector), text);
const outcome = (selector, text, gesture) => selectorEditOutcome(typed(selector, text), gesture, options);

test('opening seeds the text with the row\'s selector; a row without one is a caller bug', () => {
  assert.deepEqual(openSelectorEdit('> d-text:has(a)'), { from: '> d-text:has(a)', text: '> d-text:has(a)' });
  assert.throws(() => openSelectorEdit(''), TypeError);
  assert.throws(() => openSelectorEdit(null), TypeError);
});

test('typing replaces the text and keeps the row\'s selector; a non-string reads as empty', () => {
  const state = typed('> d-text', '> d-text:n');
  assert.deepEqual(state, { from: '> d-text', text: '> d-text:n' });
  assert.deepEqual(typedSelectorEdit(state, undefined), { from: '> d-text', text: '' });
});

test('Enter commits a differing selector in the grammar, `to` canonical', () => {
  assert.deepEqual(outcome('> d-text', '> d-text:not(:has(a))', 'Enter'), {
    kind: COMMIT,
    from: '> d-text',
    to: '> d-text:not(:has(a))',
  });
  // The grammar's spelling lands, not the typed one.
  assert.deepEqual(outcome('> d-text', '>d-text:has( a )  .link', 'Enter'), {
    kind: COMMIT,
    from: '> d-text',
    to: '> d-text:has(a) .link',
  });
  assert.deepEqual(outcome('p', '.quote::after', 'Enter'), {
    kind: COMMIT,
    from: 'p',
    to: '.quote::after',
  });
  // The WHERE opened: a state on a compound before the last, a class, an
  // attribute, a position and an unpublished pseudo-class are selector
  // text, so each is a plain move; `&` is the rule's anchor (CSS
  // nesting's), so `&:hover > ul` is a move onto the qualified anchor.
  assert.deepEqual(outcome('> ul', 'li:hover > ul', 'Enter'), { kind: COMMIT, from: '> ul', to: 'li:hover > ul' });
  assert.deepEqual(outcome('> ul', 'li.open > ul', '{'), { kind: COMMIT, from: '> ul', to: 'li.open > ul' });
  assert.deepEqual(outcome('> ul', '[aria-expanded="true"] > ul', 'Enter'), { kind: COMMIT, from: '> ul', to: '*[aria-expanded="true"] > ul' });
  assert.deepEqual(outcome('> d-text', '> d-text:first-child', 'Enter'), { kind: COMMIT, from: '> d-text', to: '> d-text:first-child' });
  assert.deepEqual(outcome('a', 'a:visited', 'Enter'), { kind: COMMIT, from: 'a', to: 'a:visited' });
  assert.deepEqual(outcome('> ul', '&:hover > ul', 'Enter'), { kind: COMMIT, from: '> ul', to: '&:hover > ul' });
  assert.deepEqual(outcome('> ul', 'LI:HOVER > UL', 'Enter'), { kind: COMMIT, from: '> ul', to: 'li:hover > ul' }, 'lenient on case');
});

test('a typed `{` is the same gate as Enter', () => {
  const state = typed('> d-text', '> d-image');
  assert.deepEqual(selectorEditOutcome(state, '{', options), selectorEditOutcome(state, 'Enter', options));
  assert.deepEqual(SETTLE_GESTURES, ['Enter', '{']);
});

test('the same selector, however spelled, is a no-op: nothing moves', () => {
  assert.deepEqual(outcome('> d-text', '> d-text', 'Enter'), { kind: NOOP, from: '> d-text' });
  assert.deepEqual(outcome('> d-text', '  >d-text ', '{'), { kind: NOOP, from: '> d-text' });
  assert.deepEqual(outcome('> d-text:has(a)', '> d-text:has( a )', 'Enter'), {
    kind: NOOP,
    from: '> d-text:has(a)',
  });
  assert.deepEqual(outcome('> d-text', '& > d-text', 'Enter'), { kind: NOOP, from: '> d-text' }, 'the anchor is nesting sugar');
});

test('text the digester refuses refuses on settle, carrying the text and the reason; the edit stays open', () => {
  ['', '   ', '> d-', '&hover', '> d-text ::before', '> d-text:has(', '> d-text {', 'color: red', '::part(x)', 'html p', '+ p', ':not(:is(a, b))', 'h1, .x > a', 'a, a.x'].forEach((text) => {
    ['Enter', '{'].forEach((gesture) => {
      const result = outcome('> d-text', text, gesture);
      assert.equal(result.kind, REFUSED, JSON.stringify(text));
      assert.equal(result.from, '> d-text', JSON.stringify(text));
      assert.equal(result.text, text, JSON.stringify(text));
      assert.equal(typeof result.reason, 'string', JSON.stringify(text));
    });
  });
  // The reason carries the digester's message and its hint.
  assert.match(outcome('> d-text', '&hover', 'Enter').reason, /written as a pseudo-class.*chrome's condition shorthand/);
});

test('a typed list commits a move to the one `:is()` rule it folds into; a wrapper alone, the element itself, and a state that peels off the row refuse with their correctives', () => {
  // A list is one rule in CSS: the digester folds it and the edit is a
  // plain move to the canonical `:is()` spelling (the header reads it
  // back); a typed `:is()` is the grammar's own and moves likewise.
  assert.deepEqual(outcome('> d-text', 'h1, h2', 'Enter'), { kind: COMMIT, from: '> d-text', to: '*:is(h1, h2)' });
  assert.deepEqual(outcome('> ul', 'li:hover > ul, li:focus-within > ul', '{'), { kind: COMMIT, from: '> ul', to: 'li:is(:focus-within, :hover) > ul' });
  assert.deepEqual(outcome('> d-text', '> *:is(d-text, d-image)', 'Enter'), { kind: COMMIT, from: '> d-text', to: '> *:is(d-image, d-text)' });
  assert.deepEqual(outcome('*:is(h1, h2)', 'h2, h1', 'Enter'), { kind: NOOP, from: '*:is(h1, h2)' }, 'the fold of the row\'s own members is the row');
  // A wrapper alone is a condition, which a row's selector never is.
  const wrapper = outcome('> d-text', '@container (max-width: 767px)', 'Enter');
  assert.equal(wrapper.kind, REFUSED);
  assert.match(wrapper.reason, /is a condition, not a selector/);
  // `&` alone is the element, not a rule.
  const owner = outcome('> d-text', '&', 'Enter');
  assert.equal(owner.kind, REFUSED);
  assert.match(owner.reason, /the element itself/);
  // Editing `> ul` to `> ul:hover` is a move plus a restructure of the
  // entry's conditions, which move_style does not express: refused with
  // the corrective that states nest inside the rule as `&:hover`.
  const peel = outcome('> ul', '> ul:hover', 'Enter');
  assert.deepEqual(peel, {
    kind: REFUSED,
    from: '> ul',
    text: '> ul:hover',
    reason: '"> ul:hover" peels "hover" off the row into a condition; a state on the addressed element nests inside the rule as "&:hover" (a state on a compound before it, "li:hover > ul", moves the rule).',
  });
  assert.equal(outcome('> d-text', '> d-text:has(a):hover', '{').kind, REFUSED, 'by name, not by the token\'s place');
  // An unpublished posable state peels as a LITERAL clause (the WHEN
  // landing): the corrective spells the literal's nesting from the
  // clause's own CSS, never a second colon.
  assert.deepEqual(outcome('> ul', '> ul:checked', 'Enter'), {
    kind: REFUSED,
    from: '> ul',
    text: '> ul:checked',
    reason: '"> ul:checked" peels ":checked" off the row into a condition; a state on the addressed element nests inside the rule as "&:checked" (a state on a compound before it, "li:hover > ul", moves the rule).',
  });
  // Without a condition table a posable state still peels, as a literal
  // (the chrome always hands the table); a state no stamp stands in for
  // is a qualifier and the edit a plain move.
  assert.equal(selectorEditOutcome(typed('> ul', '> ul:hover'), 'Enter').kind, REFUSED);
  assert.deepEqual(selectorEditOutcome(typed('> ul', '> ul:visited'), 'Enter'), { kind: COMMIT, from: '> ul', to: '> ul:visited' });
});

test('Escape and blur cancel whatever the text, a committable one included', () => {
  ['Escape', 'blur'].forEach((gesture) => {
    assert.deepEqual(outcome('> d-text', '> d-image', gesture), { kind: CANCEL, from: '> d-text' }, gesture);
    assert.deepEqual(outcome('> d-text', '> d-', gesture), { kind: CANCEL, from: '> d-text' }, gesture);
    assert.deepEqual(outcome('> d-text', '> d-text', gesture), { kind: CANCEL, from: '> d-text' }, gesture);
  });
  assert.deepEqual(LEAVE_GESTURES, ['Escape', 'blur']);
});

test('any other key is typing: no outcome', () => {
  ['a', 'Tab', 'ArrowLeft', 'Backspace', ' ', '}'].forEach((gesture) => {
    assert.equal(outcome('> d-text', '> d-tex', gesture), null, gesture);
  });
});

test('the preview is the typed text\'s structural reach while it digests to one rule selector, null otherwise', () => {
  const preview = (selector, text) => selectorEditPreview(typed(selector, text), options);
  assert.equal(preview('> d-text', '> d-text'), '> d-text');
  assert.equal(preview('> d-text', '>d-text:has(a)'), '> d-text:has(a)');
  assert.equal(preview('> d-text', '> d-text .link'), '> d-text .link');
  assert.equal(preview('> d-text', '&::before'), '&::before');
  // A posable state matches nothing until it holds: the reach strips it
  // (the canvas paints the items, the pose lights the reveal).
  assert.equal(preview('> ul', 'li:hover > ul'), 'li > ul');
  assert.equal(preview('> ul', '&:hover > ul'), '> ul');
  assert.equal(preview('> ul', '> ul:hover'), '> ul', 'a peeling state previews the row it would leave');
  assert.equal(preview('> d-text', '> d-text:first-child'), '> d-text:first-child', 'a position is live structure');
  // A list previews the reach of the one rule it folds into; a posable
  // state inside `:is()` strips per member, as reach() reads it (the
  // fixture publishes hover alone, so focus-within rides as a qualifier
  // and the one member left merges into the host).
  assert.equal(preview('> d-text', 'h1, h2'), '*:is(h1, h2)');
  assert.equal(preview('> ul', 'li:hover > ul, li:focus-within > ul'), 'li:focus-within > ul');
  ['> d-', '> d-text:has(', '', '&hover', '> d-text {', 'h1, .x > a', '@container (max-width: 767px)', '&'].forEach((text) => {
    assert.equal(preview('> d-text', text), null, JSON.stringify(text));
  });
});

let failed = 0;
for (const [name, run] of tests) {
  try {
    run();
    process.stdout.write(`ok - ${name}\n`);
  } catch (error) {
    failed += 1;
    process.stderr.write(`not ok - ${name}\n${error.stack ?? error.message}\n`);
  }
}
if (failed) {
  process.exit(1);
}
process.stdout.write('# all selector-edit tests passed\n');
