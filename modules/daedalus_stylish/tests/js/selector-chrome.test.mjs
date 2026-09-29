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
 * itself is prelude.test.mjs's). Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { registerJsx } from './jsx-loader.mjs';

registerJsx();
const { Prelude } = await import('../../js/src/code/selector-chrome.js');

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
  ['> *', '> pb-text', 'p', '.quote', '> pb-text .text-block-link', '> pb-container > p'].forEach((selector) => {
    assert.equal(text(Prelude({ selector, className: 'code' })), selector, selector);
  });
});

test('each direct-child combinator wears a chip; a descendant space wears nothing', () => {
  const chips = (selector) =>
    (html(Prelude({ selector, className: 'code' })).match(/daedalus-stylish-islands__combinator/g) ?? []).length;
  assert.equal(chips('> *'), 1);
  assert.equal(chips('> pb-container > p'), 2);
  assert.equal(chips('p'), 0);
  assert.equal(chips('> pb-text .text-block-link'), 1);
  assert.match(html(Prelude({ selector: '> *', className: 'code' })), /title="Direct children only"/);
  assert.match(html(Prelude({ selector: '> *', className: 'code' })), /^<code class="code">/);
});

test('the sibling combinators wear their own chips; qualifiers print as code text with their subject', () => {
  const chips = (selector) =>
    (html(Prelude({ selector, className: 'code' })).match(/daedalus-stylish-islands__combinator/g) ?? []).length;
  ['> p + p', '> p ~ p', '> pb-text:has(a)', '> *:not(pb-image)', '> pb-text:has(+ pb-image)', '> pb-text:not(:has(a))', '> pb-text .link ~ .link'].forEach((selector) => {
    assert.equal(text(Prelude({ selector, className: 'code' })), selector, selector);
  });
  assert.equal(chips('> p + p'), 2);
  assert.equal(chips('> p ~ p'), 2);
  assert.equal(chips('> pb-text:has(a)'), 1);
  assert.equal(chips('> pb-text .link ~ .link'), 2);
  assert.equal(chips('p + p'), 1);
  assert.match(html(Prelude({ selector: '> p + p', className: 'code' })), /title="Right after the previous">\+</);
  assert.match(html(Prelude({ selector: '> p ~ p', className: 'code' })), /title="After a previous">~</);
  // The qualifier's own combinator is text inside the parens, never a chip.
  assert.equal(chips('> pb-text:has(> a)'), 1);
});

test('a generated box prints as code text glued to its host, the owner\'s through the anchor; the `::` wears no chip', () => {
  const chips = (selector) =>
    (html(Prelude({ selector, className: 'code' })).match(/daedalus-stylish-islands__combinator/g) ?? []).length;
  ['&::before', '&::after', '.quote::after', 'p::before', '> *::before', '> pb-text::before', '> pb-container > p::after', '> p + p::after', '> pb-text:has(a)::before', '> pb-text .text-block-link::after'].forEach((selector) => {
    assert.equal(text(Prelude({ selector, className: 'code' })), selector, selector);
  });
  assert.equal(chips('&::before'), 0);
  assert.equal(chips('.quote::after'), 0);
  assert.equal(chips('> pb-text::before'), 1);
  assert.equal(chips('> pb-container > p::after'), 2);
  assert.equal(chips('> p + p::after'), 2);
  // The anchor and the pseudo are one text run after the chips, the
  // spelling the map key carries.
  assert.equal(html(Prelude({ selector: '&::before', className: 'code' })), '<code class="code">&::before</code>');
  assert.match(html(Prelude({ selector: '> pb-text::after', className: 'code' })), /<\/span> pb-text::after<\/code>$/);
});

test('a spelling outside the grammar prints verbatim, chipless', () => {
  assert.equal(html(Prelude({ selector: '::weird', className: 'code' })), '<code class="code">::weird</code>');
  ['&', '::before', '& ::before', '&::marker', '> pb-text ::before', '&::before .x'].forEach((selector) => {
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
  const chained = html(Prelude({ selector: '> pb-text', condition: 'hover', guide, className: 'code' }));
  assert.equal(text(chained), '& > pb-text:hover');
  assert.equal(
    chained,
    '<code class="code">& <span class="daedalus-stylish-islands__combinator" title="Direct children only">></span> pb-text:hover</code>',
  );
  // The condition defaults to the plain block: a selector row's face.
  assert.equal(text(Prelude({ selector: '> pb-text', guide, className: 'code' })), '> pb-text');
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
