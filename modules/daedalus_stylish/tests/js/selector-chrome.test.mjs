/**
 * Self-asserting tests for the chrome rows' code face (Prelude).
 *
 * Run directly (`node tests/js/selector-chrome.test.mjs`) or via
 * JsSuitesTest in the PHP tier. Renders the component's vnode tree (the
 * JSX compiled by jsx-loader.mjs) and locks what the inspector CSS and
 * the FJS scenario read: the canonical selector as a selector row's text, one
 * chip per `>`, `+` or `~` combinator, none for a descendant space or
 * inside a qualifier, the spelling kept verbatim outside the grammar, and
 * a condition row's prelude with its wrapper span and chips (the spelling
 * itself is prelude.test.mjs's). Locks the edit mode's chrome too: the
 * header click that opens it on Edit Mode's one double-click
 * (selectorHeaderClick over daedalus_ui's isDoubleClick), the editor
 * rendered in the code face's place when the host passes `edit`, seeded
 * with the row's selector (the editor itself is a CodeMirror mount, so
 * the lock is its vnode: type, selector, class and the host's
 * callbacks), and the completion presentation both surfaces share
 * (selectorCompletionItems, selectorOfferValidity). Exits non-zero on
 * failure.
 */
import { strict as assert } from 'node:assert';
import { registerJsx } from './jsx-loader.mjs';

registerJsx();
const {
  Prelude,
  SelectorEditor,
  selectorCompletionItems,
  selectorHeaderClick,
  selectorOfferValidity,
} = await import('../../js/src/code/selector-chrome.js');
const { POSITION, PSEUDO, QUALIFIER, STATE } = await import('../../js/src/code/element-model.js');
const { TAG, TYPE, UNIVERSAL, CLASS_PART } = await import(
  '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js'
);

globalThis.Drupal = {
  t: (text, args = {}) => Object.entries(args).reduce((out, [key, value]) => out.split(key).join(String(value)), text),
};

const tests = [];
const test = (name, run) => tests.push([name, run]);

/**
 * Renders a vnode tree to a plain HTML string: function components are
 * called, host elements print their class and title, handlers are
 * dropped.
 */
const html = (node) => {
  if (node == null || typeof node === 'boolean') {
    return '';
  }
  if (Array.isArray(node)) {
    return node.map(html).join('');
  }
  if (typeof node !== 'object') {
    return String(node);
  }
  const { type, props } = node;
  if (typeof type === 'function') {
    return html(type(props));
  }
  const attributes = ['className', 'title']
    .filter((name) => props[name] !== undefined)
    .map((name) => ` ${name === 'className' ? 'class' : name}="${props[name]}"`)
    .join('');
  return `<${type}${attributes}>${html(props.children)}</${type}>`;
};

const text = (node) => html(node).replace(/<[^>]+>/g, '');

test('the code face reads the canonical selector back as its text', () => {
  ['> *', '> d-text', 'p', '.quote', '> d-text .text-block-link', '> d-container > p'].forEach((selector) => {
    assert.equal(text(Prelude({ selector, className: 'code' })), selector, selector);
  });
});

test('each direct-child combinator wears a chip; a descendant space wears nothing', () => {
  const chips = (selector) =>
    (html(Prelude({ selector, className: 'code' })).match(/daedalus-stylish-islands__combinator/g) ?? []).length;
  assert.equal(chips('> *'), 1);
  assert.equal(chips('> d-container > p'), 2);
  assert.equal(chips('p'), 0);
  assert.equal(chips('> d-text .text-block-link'), 1);
  assert.match(html(Prelude({ selector: '> *', className: 'code' })), /title="Direct children only"/);
  assert.match(html(Prelude({ selector: '> *', className: 'code' })), /^<code class="code">/);
});

test('the sibling combinators wear their own chips; qualifiers print as code text with their subject', () => {
  const chips = (selector) =>
    (html(Prelude({ selector, className: 'code' })).match(/daedalus-stylish-islands__combinator/g) ?? []).length;
  ['> p + p', '> p ~ p', '> d-text:has(a)', '> *:not(d-image)', '> d-text:has(+ d-image)', '> d-text:not(:has(a))', '> d-text .link ~ .link', '.menu-item:is(:focus-within, :hover) > ul'].forEach((selector) => {
    assert.equal(text(Prelude({ selector, className: 'code' })), selector, selector);
  });
  assert.equal(chips('> p + p'), 2);
  assert.equal(chips('> p ~ p'), 2);
  assert.equal(chips('> d-text:has(a)'), 1);
  // A state inside an `:is()` member on a non-terminal compound is code
  // text with its subject; the chain's own `>` is the one chip.
  assert.equal(chips('.menu-item:is(:focus-within, :hover) > ul'), 1);
  assert.equal(chips('> d-text .link ~ .link'), 2);
  assert.equal(chips('p + p'), 1);
  assert.match(html(Prelude({ selector: '> p + p', className: 'code' })), /title="Right after the previous">\+</);
  assert.match(html(Prelude({ selector: '> p ~ p', className: 'code' })), /title="After a previous">~</);
  // The qualifier's own combinator is text inside the parens, never a chip.
  assert.equal(chips('> d-text:has(> a)'), 1);
});

test('a generated box prints as code text glued to its host, the owner\'s through the anchor; the `::` wears no chip', () => {
  const chips = (selector) =>
    (html(Prelude({ selector, className: 'code' })).match(/daedalus-stylish-islands__combinator/g) ?? []).length;
  ['&::before', '&::after', '&::marker', 'li::marker', 'input::placeholder', '.quote::after', 'p::before', '> *::before', '> d-text::before', '> d-container > p::after', '> p + p::after', '> d-text:has(a)::before', '> d-text .text-block-link::after'].forEach((selector) => {
    assert.equal(text(Prelude({ selector, className: 'code' })), selector, selector);
  });
  assert.equal(chips('&::before'), 0);
  assert.equal(chips('.quote::after'), 0);
  assert.equal(chips('> d-text::before'), 1);
  assert.equal(chips('> d-container > p::after'), 2);
  assert.equal(chips('> p + p::after'), 2);
  // The anchor and the pseudo are one text run after the chips, the
  // spelling the map key carries.
  assert.equal(html(Prelude({ selector: '&::before', className: 'code' })), '<code class="code">&::before</code>');
  assert.match(html(Prelude({ selector: '> d-text::after', className: 'code' })), /<\/span> d-text::after<\/code>$/);
});

test('a spelling outside the grammar prints verbatim, chipless', () => {
  assert.equal(html(Prelude({ selector: '::weird', className: 'code' })), '<code class="code">::weird</code>');
  ['&', '::before', '& ::before', '&::part(x)', '> d-text ::before', '&::before .x'].forEach((selector) => {
    assert.equal(html(Prelude({ selector, className: 'code' })), `<code class="code">${selector}</code>`, selector);
  });
});

test('a condition row prints its prelude: the wrapper in its own span, the chips kept, the states as text', () => {
  const guide = {
    conditions: {
      hover: { wrapper: ':hover' },
      narrow: { wrapper: '@container (max-width: 640px)' },
    },
  };
  assert.equal(
    html(Prelude({ selector: null, condition: 'hover', guide, className: 'code' })),
    '<code class="code">&:hover</code>',
  );
  assert.equal(
    html(Prelude({ selector: null, condition: 'hover+narrow', guide, className: 'code' })),
    '<code class="code"><span class="daedalus-stylish-islands__wrapper">@container (max-width: 640px)</span> &:hover</code>',
  );
  const chained = html(Prelude({ selector: '> d-text', condition: 'hover', guide, className: 'code' }));
  assert.equal(text(chained), '& > d-text:hover');
  assert.equal(
    chained,
    '<code class="code">& <span class="daedalus-stylish-islands__combinator" title="Direct children only">></span> d-text:hover</code>',
  );
  // The condition defaults to the plain block: a selector row's face.
  assert.equal(text(Prelude({ selector: '> d-text', guide, className: 'code' })), '> d-text');
});

test('a header click seats on a single click and opens the editor on the click that closes a pair', () => {
  const calls = [];
  const gestures = { seat: () => calls.push('seat'), edit: () => calls.push('edit') };
  // The OS click count on `click`: 1 seats, 2 opens (the pair's first
  // click already seated), 3 is a single click again, 4 a second pair.
  [1, 2, 3, 4].forEach((detail) => selectorHeaderClick({ detail }, gestures));
  assert.deepEqual(calls, ['seat', 'edit', 'seat', 'edit']);
  // A synthetic or keyboard click (count 0, no count) is a single click.
  calls.length = 0;
  selectorHeaderClick({ detail: 0 }, gestures);
  selectorHeaderClick({}, gestures);
  assert.deepEqual(calls, ['seat', 'seat']);
});

test('with `edit` given the prelude renders the editor in the code face\'s place, seeded with the selector', () => {
  const edit = {
    completions: { key: 'container:X', types: { 'd-text': 'Text' } },
    onPreview: () => {},
    onMove: () => {},
    onClose: () => {},
  };
  const vnode = Prelude({ selector: '> d-text:has(a)', guide: null, className: 'code', edit });
  assert.equal(vnode.type, SelectorEditor, 'the editor is the face');
  assert.equal(vnode.props.selector, '> d-text:has(a)');
  assert.equal(vnode.props.className, 'code');
  assert.equal(vnode.props.completions, edit.completions);
  assert.equal(vnode.props.onPreview, edit.onPreview);
  assert.equal(vnode.props.onMove, edit.onMove);
  assert.equal(vnode.props.onClose, edit.onClose);
  // Without `edit` (the default) the face is the code, unchanged.
  const face = Prelude({ selector: '> d-text:has(a)', className: 'code' });
  assert.equal(face.type, 'code');
  assert.equal(text(face), '> d-text:has(a)');
  assert.equal(text(Prelude({ selector: '> d-text:has(a)', className: 'code', edit: null })), '> d-text:has(a)');
});

test('the completion items: one per option in the editor\'s shape, the detail sentence, the undeclared class last and marked', () => {
  const offer = {
    options: [
      { selector: '> *', kind: UNIVERSAL, name: '*', label: null },
      { selector: '> d-text', kind: TYPE, name: 'd-text', label: 'Text' },
      { selector: '> p', kind: TAG, name: 'p', label: 'Paragraph' },
      { selector: '.quote', kind: CLASS_PART, name: '.quote', label: 'Quote' },
      { selector: '&::before', kind: PSEUDO, name: 'before', label: null },
    ],
    create: '.pull',
  };
  const applied = [];
  const items = selectorCompletionItems(offer, {
    label: (selector) => `[${selector}]`,
    apply: (selector) => () => applied.push(selector),
  });
  assert.deepEqual(
    items.map(({ label, type, detail, boost }) => [label, type, detail, boost]),
    [
      ['[> *]', 'keyword', 'Children', undefined],
      ['[> d-text]', 'type', 'Every text block here', undefined],
      ['[> p]', 'class', 'Every paragraph here', undefined],
      ['[.quote]', 'class', 'Quote', undefined],
      ['[&::before]', 'keyword', 'Before', undefined],
      ['[.pull]', 'class', 'Create this part', -1],
    ],
  );
  items.forEach((item) => item.apply());
  assert.deepEqual(applied, ['> *', '> d-text', '> p', '.quote', '&::before', '.pull']);
  // No create: no trailing item.
  assert.equal(selectorCompletionItems({ options: offer.options.slice(0, 1), create: null }, { label: (s) => s, apply: () => () => {} }).length, 1);
  // The `:` lane's options: a state worded by its condition, a position
  // by its place, and an open qualifier that inserts its text through
  // the caller's `insert` (never minting).
  const inserted = [];
  const lane = selectorCompletionItems({
    options: [
      { selector: '> d-text:hover', kind: STATE, name: 'hover', label: 'Hover' },
      { selector: '> d-text:first-child', kind: POSITION, name: 'first-child', label: null },
      { insert: '> d-text:not(', kind: QUALIFIER, name: 'not', label: null },
      { insert: '> d-text:is(', kind: QUALIFIER, name: 'is', label: null },
    ],
    create: null,
  }, {
    label: (selector) => `[${selector}]`,
    apply: (selector) => () => applied.push(selector),
    insert: (text) => () => inserted.push(text),
  });
  assert.deepEqual(
    lane.map(({ label, type, detail }) => [label, type, detail]),
    [
      ['[> d-text:hover]', 'keyword', 'While hover'],
      ['[> d-text:first-child]', 'keyword', 'The first among its siblings'],
      ['[> d-text:not(]', 'keyword', 'All but what the parens name'],
      ['[> d-text:is(]', 'keyword', 'Any of what the parens name'],
    ],
  );
  lane.forEach((item) => item.apply());
  assert.deepEqual(inserted, ['> d-text:not(', '> d-text:is(']);
  assert.deepEqual(applied.slice(-2), ['> d-text:hover', '> d-text:first-child']);
});

test('an offer holds while the token under the cursor grows and lapses at a new compound', () => {
  const holds = selectorOfferValidity('> d-te');
  assert.equal(holds('> d-text'), true);
  assert.equal(holds('> d-'), true);
  assert.equal(holds('> d-text '), false, 'a space starts a new compound');
  assert.equal(holds('> d-text >'), false, 'a combinator asks again');
  assert.equal(holds('> d-text:'), false, 'a qualifier asks again');
  const chained = selectorOfferValidity('> d-text .li');
  assert.equal(chained('> d-text .link'), true);
  assert.equal(chained('> d-text .link '), false);
  assert.equal(chained('> d-image .link'), false, 'the stem changed');
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
process.stdout.write('# all selector-chrome tests passed\n');
