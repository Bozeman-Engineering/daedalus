/**
 * Self-asserting tests for the client printer's generated-box lane
 * (printer.js): the terminal pseudo-element on the @scope targets
 * (scopeTargets: the anchor as the scoping root's own box, the part dual
 * carrying the box on both halves, every chain shape glued last), its
 * place in a printed entry (after a state's suffix, after the hold's
 * :not(), never co-targeted), the rule-wear continuation of a fragment's
 * anchor, and the emit gate dropping every spelling outside the grammar.
 *
 * The byte lock between this printer and CssPrinter.php is
 * PrinterEquivalenceTest (tests/js/print-cases.mjs); this spec locks the
 * JS text on its own so a divergence names the side that moved.
 *
 * Run directly (`node --test tests/js/printer.test.mjs`) or via
 * JsSuitesTest. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import test from 'node:test';
import {
  donutLimit,
  mapUsesContainerConditions,
  printEntry,
  printFragmentStyles,
  printMap,
  scopeTargets,
} from '../../js/src/printer.js';

/**
 * The specificity of one compound or complex selector, as [a, b, c]
 * (CssPrinterTest::specificity's twin): CSS's algorithm over the
 * printer's output, so the weight discipline is proved rather than
 * eyeballed. :where() weighs nothing; :is(), :not() and :has() weigh
 * their most specific argument; an id 1-0-0; a class, an attribute and
 * every other pseudo-class (:scope included) 0-1-0; a tag and a
 * pseudo-element 0-0-1; `*` and combinators nothing.
 */
const specificity = (selector) => {
  const weight = [0, 0, 0];
  for (let i = 0; i < selector.length; i += 1) {
    const char = selector[i];
    if (char === '#' || char === '.') {
      const [ident] = selector.slice(i + 1).match(/^[A-Za-z0-9_-]+/);
      i += ident.length;
      weight[char === '#' ? 0 : 1] += 1;
    } else if (char === '[') {
      i = selector.indexOf(']', i);
      weight[1] += 1;
    } else if (char === ':') {
      const element = selector[i + 1] === ':';
      const [name] = selector.slice(i + (element ? 2 : 1)).match(/^[a-z-]+/);
      i += name.length + (element ? 1 : 0);
      let argument = null;
      if (selector[i + 1] === '(') {
        let depth = 0;
        let j = i + 1;
        for (; j < selector.length; j += 1) {
          if (selector[j] === '(') {
            depth += 1;
          } else if (selector[j] === ')') {
            depth -= 1;
          }
          if (depth === 0) {
            break;
          }
        }
        argument = selector.slice(i + 2, j);
        i = j;
      }
      if (element) {
        weight[2] += 1;
      } else if (['is', 'not', 'has'].includes(name)) {
        const most = selectorList(argument)
          .map(specificity)
          .sort((x, y) => x[0] - y[0] || x[1] - y[1] || x[2] - y[2])
          .pop();
        most.forEach((n, k) => {
          weight[k] += n;
        });
      } else if (name !== 'where') {
        weight[1] += 1;
      }
    } else if (/[A-Za-z]/.test(char)) {
      const [ident] = selector.slice(i).match(/^[A-Za-z0-9-]+/);
      i += ident.length - 1;
      weight[2] += 1;
    }
  }
  return weight;
};

/**
 * The members of a selector list, split at its TOP-LEVEL commas only: a
 * nested list (`:not(:where(:is(:hover, […])))`) stays one member, so a
 * zero-weight :where() around a dual weighs nothing as CSS says.
 */
const selectorList = (text) => {
  const members = [];
  let depth = 0;
  let start = 0;
  for (let i = 0; i < text.length; i += 1) {
    if (text[i] === '(') {
      depth += 1;
    } else if (text[i] === ')') {
      depth -= 1;
    } else if (depth === 0 && text.startsWith(', ', i)) {
      members.push(text.slice(start, i));
      start = i + 2;
    }
  }
  members.push(text.slice(start));
  return members;
};

const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  'focus-within': { label: 'Focus within', wrapper: ':focus-within', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
};

const BLOCK = 'block:aaaaaaaa-1111-2222-3333-444444444444';
const CONTAINER = 'container:aaaaaaaa-1111-2222-3333-444444444444';

test('scopeTargets glues the pseudo-element last on every target', () => {
  assert.deepEqual(scopeTargets('&::before'), [':scope::before']);
  assert.deepEqual(scopeTargets('&::after'), [':scope::after']);
  assert.deepEqual(scopeTargets('.quote::after'), [':scope.quote::after', '.quote::after']);
  assert.deepEqual(scopeTargets('.section > *::before'), [':scope:where(.section) > *::before', '.section > *::before']);
  assert.deepEqual(scopeTargets('.section + .section::before'), [':where(.section) + .section::before']);
  assert.deepEqual(scopeTargets('> d-text::before'), [':where(:scope) > [data-element="block_type:text"]::before']);
  assert.deepEqual(scopeTargets('> d-text:has(a)::after'), [':where(:scope) > [data-element="block_type:text"]:has(:where(a))::after']);
  assert.deepEqual(scopeTargets('> p + p::before'), [':where(:scope) > :where(p) + p::before']);
  assert.deepEqual(scopeTargets('> *::after'), [':scope > *::after']);
  assert.deepEqual(scopeTargets('p::before'), ['p::before']);
  assert.deepEqual(scopeTargets('d-block::before'), [':is([data-element^="block_type:"], [data-element^="block_plugin:"])::before']);
  // The pseudo-free shapes are untouched.
  assert.deepEqual(scopeTargets(null), [':scope']);
  assert.deepEqual(scopeTargets('.quote'), [':scope.quote', '.quote']);
});

test('the anchor prints the root\'s own box: states and the hold before it, no companion', () => {
  const css = printEntry(
    `[data-selectable="${BLOCK}"]`,
    '&::before',
    {
      default: { content: '""', color: 'red', 'animation-trigger': '--in-view play-once' },
      hover: { color: 'blue' },
      narrow: { display: 'none' },
    },
    CONDITIONS,
  );
  assert.equal(
    css,
    `@scope ([data-selectable="${BLOCK}"]) {
  :scope::before {
    content: "";
    color: red;
    animation-trigger: --in-view play-once;
    --daedalus-stylish-animation-trigger: --in-view play-once;
  }
  @supports not (animation-trigger: none) {
    :scope:not([data-daedalus-stylish-triggered])::before {
      animation-play-state: paused;
    }
  }
  :scope:is(:hover, [data-force-state~="hover"])::before {
    color: blue;
  }
  @container (max-width: 767px) {
    :scope::before {
      display: none;
    }
  }
}`,
  );
  assert.ok(!css.includes('data-claims-content'));
});

test('a part\'s box keeps the dual and the part\'s donut; a rule\'s box takes none', () => {
  const css = printMap(
    {
      [`${CONTAINER}::.quote::after`]: { default: { content: '""' }, hover: { color: 'purple' } },
      [`${CONTAINER}::> d-text::before`]: { default: { content: '""' }, hover: { color: 'blue' } },
    },
    CONDITIONS,
  );
  assert.equal(
    css,
    `@scope ([data-selectable="${CONTAINER}"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope.quote::after, .quote::after {
    content: "";
  }
  :scope.quote:is(:hover, [data-force-state~="hover"])::after, .quote:is(:hover, [data-force-state~="hover"])::after {
    color: purple;
  }
}
@scope ([data-selectable="${CONTAINER}"]) {
  :where(:scope) > [data-element="block_type:text"]::before {
    content: "";
  }
  :where(:scope) > [data-element="block_type:text"]:is(:hover, [data-force-state~="hover"])::before {
    color: blue;
  }
}`,
  );
});

test('a position qualifier prints inside :where() at its compound and the anchor\'s on the scope root, no weight added', () => {
  const cases = [
    ['> *:not(:last-child)', '> *', [':scope > *:not(:where(:last-child))']],
    ['> *:first-child', '> *', [':scope > *:where(:first-child)']],
    ['li:nth-child(2n+1)', 'li', ['li:where(:nth-child(2n+1))']],
    ['> d-text:first-of-type', '> d-text', [':where(:scope) > [data-element="block_type:text"]:where(:first-of-type)']],
    ['&:first-child', null, [':scope:where(:first-child)']],
    ['&:not(:last-child) > *', '> *', [':scope:not(:where(:last-child)) > *']],
    ['&:first-child > d-text', '> d-text', [':where(:scope):where(:first-child) > [data-element="block_type:text"]']],
    ['&:only-child > *:nth-last-child(-n+3)', '> *', [':scope:where(:only-child) > *:where(:nth-last-child(-n+3))']],
    ['&:first-child::before', '&::before', [':scope:where(:first-child)::before']],
    ['&:first-child .quote', '.quote', [':scope:where(:first-child).quote', ':where(:scope):where(:first-child) .quote']],
    ['&:first-child .quote > *', '.quote > *', [':scope:where(:first-child):where(.quote) > *', ':where(:scope):where(:first-child) .quote > *']],
    ['.section:not(:first-child) > *', '.section > *', [':scope:where(.section):not(:where(:first-child)) > *', '.section:not(:where(:first-child)) > *']],
  ];
  cases.forEach(([qualified, plain, targets]) => {
    assert.deepEqual(scopeTargets(qualified), targets, qualified);
    assert.deepEqual(
      targets.map(specificity),
      scopeTargets(plain).map(specificity),
      `${qualified} weighs what ${plain ?? 'the root'} weighs`,
    );
  });
  // The algorithm sees a bare position weigh, so the equalities above are
  // not vacuous.
  assert.deepEqual(specificity(':scope > *:first-child'), [0, 2, 0]);
  assert.deepEqual(specificity('li:nth-child(2n+1)'), [0, 1, 1]);
  assert.deepEqual(specificity(':scope:where(:first-child)'), [0, 1, 0]);
  // The donut follows the part behind the anchor; the anchor's own
  // children take none.
  assert.equal(donutLimit('&:first-child .quote'), donutLimit('.quote'));
  assert.equal(donutLimit('&:first-child .quote > *'), donutLimit('.quote > *'));
  assert.equal(donutLimit('&:first-child > *'), null);
  // A condition suffix and the box compose after the anchor's qualifiers.
  assert.equal(
    printMap(
      { [`${BLOCK}::&:first-child::before`]: { default: { content: '""' }, hover: { color: 'blue' } } },
      CONDITIONS,
    ),
    `@scope ([data-selectable="${BLOCK}"]) {
  :scope:where(:first-child)::before {
    content: "";
  }
  :scope:where(:first-child):is(:hover, [data-force-state~="hover"])::before {
    color: blue;
  }
}`,
  );
});

test('a class, attribute, pseudo-class or :is() qualifier prints inside :where() at its compound, a published state as the force-state dual, no weight added', () => {
  const hover = ':is(:hover, [data-force-state~="hover"])';
  const focus = ':is(:focus-within, [data-force-state~="focus-within"])';
  // Lily's compound: the dual per posable member, bare inside the one
  // :where() around the :is(), members in the grammar's sorted order.
  const either = `:is(${focus}, ${hover})`;
  const cases = [
    ['.menu-item:is(:focus-within, :hover) > ul', '.menu-item > ul', [`:where(:scope):where(.menu-item):where(${either}) > ul`, `:where(.menu-item):where(${either}) > ul`]],
    ['> d-text:is(:focus-within, :hover) a', '> d-text a', [`:where(:scope) > :where([data-element="block_type:text"]):where(${either}) a`]],
    ['> *:is(d-image, d-text)', '> *', [':scope > *:where(:is([data-element="block_type:image"], [data-element="block_type:text"]))']],
    ['> d-text:has(*:is(a, button))', '> d-text', [':where(:scope) > [data-element="block_type:text"]:has(*:where(:is(a, button)))']],
    ['li:hover > ul', 'li > ul', [`:where(li):where(${hover}) > ul`]],
    ['> d-text:hover a', '> d-text a', [`:where(:scope) > :where([data-element="block_type:text"]):where(${hover}) a`]],
    ['li.active > ul', 'li > ul', [':where(li):where(.active) > ul']],
    ['li[aria-current="page"] > a', 'li > a', [':where(li):where([aria-current="page"]) > a']],
    ['*[aria-expanded="true"] > ul', '> ul', ['*:where([aria-expanded="true"]) > ul']],
    ['&.open > ul', '> ul', [':where(:scope):where(.open) > ul']],
    ['&:hover > ul', '> ul', [`:where(:scope):where(${hover}) > ul`]],
    ['&.open', null, [':scope:where(.open)']],
    ['a:visited', 'a', ['a:where(:visited)']],
    ['> *:not(:hover)', '> *', [`:scope > *:not(:where(${hover}))`]],
    ['> d-text:has(a:hover)', '> d-text', [`:where(:scope) > [data-element="block_type:text"]:has(:where(a):where(${hover}))`]],
    ['.featured.quote', '.featured', [':scope.featured:where(.quote)', '.featured:where(.quote)']],
    ['li.active:hover[aria-current="page"] > a', 'li > a', [`:where(li):where(.active):where(${hover}):where([aria-current="page"]) > a`]],
  ];
  cases.forEach(([qualified, plain, targets]) => {
    assert.deepEqual(scopeTargets(qualified, CONDITIONS), targets, qualified);
    assert.deepEqual(
      targets.map(specificity),
      scopeTargets(plain, CONDITIONS).map(specificity),
      `${qualified} weighs what ${plain ?? 'the root'} weighs`,
    );
  });
  // The fold of `h1, h2` by descent (`*:is(h1, h2)`) weighs nothing, as
  // `*` does: the :is() rides inside :where(), its tag members bare. A
  // member's own qualifiers print by the same rules, unwrapped (the one
  // :where() has zeroed them): a `:has()` argument bare, a state the dual.
  [
    ['*:is(h1, h2)', '*:where(:is(h1, h2))'],
    ['*:is(.a, :not(.b))', '*:where(:is(.a, :not(.b)))'],
    ['*:is(.x:has(em), a:hover)', `*:where(:is(.x:has(em), a${hover}))`],
  ].forEach(([selector, target]) => {
    assert.deepEqual(scopeTargets(selector, CONDITIONS), [target], selector);
    assert.deepEqual(specificity(target), [0, 0, 0], `${selector} weighs nothing`);
  });
  assert.deepEqual(specificity('*:is(h1, h2)'), [0, 0, 1]);
  assert.deepEqual(specificity(`*:is(.x:has(em), a${hover})`), [0, 1, 1]);
  // Without a condition table no pseudo-class is a published state: the
  // dual is the table's verdict, never the grammar's, inside :is() too.
  assert.deepEqual(scopeTargets('li:hover > ul'), [':where(li):where(:hover) > ul']);
  assert.deepEqual(scopeTargets('.menu-item:is(:focus-within, :hover) > ul'), [':where(:scope):where(.menu-item):where(:is(:focus-within, :hover)) > ul', ':where(.menu-item):where(:is(:focus-within, :hover)) > ul']);
  // The algorithm sees a bare qualifier weigh, so the equalities above are
  // not vacuous.
  assert.deepEqual(specificity('li.active > ul'), [0, 1, 2]);
  assert.deepEqual(specificity('li:hover > ul'), [0, 1, 2]);
  assert.deepEqual(specificity('li[aria-current="page"] > a'), [0, 1, 2]);
  assert.deepEqual(specificity(`:scope${hover}`), [0, 2, 0]);
  assert.deepEqual(specificity(`:scope:where(${hover})`), [0, 1, 0]);
  // The box and the condition suffix compose after the qualified chain:
  // the state qualifier at its compound, the condition on the host.
  assert.equal(
    printMap(
      { [`${CONTAINER}::li:hover > ul::before`]: { default: { content: '""' }, hover: { color: 'blue' } } },
      CONDITIONS,
    ),
    `@scope ([data-selectable="${CONTAINER}"]) {
  :where(li):where(${hover}) > ul::before {
    content: "";
  }
  :where(li):where(${hover}) > ul${hover}::before {
    color: blue;
  }
}`,
  );
});

test('a literal clause nests as its own at-rule by its prefix, a pseudo-class literal is a suffix with the dual when posable and bare otherwise', () => {
  const css = printEntry(
    `[data-selectable="${BLOCK}"]`,
    null,
    {
      default: { padding: '0' },
      '@media (hover: hover)': { padding: '2px' },
      '@container (min-width: 500px)+hover': { padding: '1rem' },
      '@supports (display: grid)': { display: 'grid' },
      ':checked': { outline: '1px solid teal' },
      ':visited': { 'outline-color': 'purple' },
      'narrow+@media (prefers-contrast: more)': { 'outline-width': '2px' },
      // A literal spelling the published wrapper IS the reference (the
      // write path stores it as the name; printed as spelled it ranks as
      // an unknown position in the cascade order, after the named ones).
      '@container (max-width: 767px)': { margin: '0' },
    },
    CONDITIONS,
  );
  assert.equal(
    css,
    `@scope ([data-selectable="${BLOCK}"]) {
  :scope {
    padding: 0;
  }
  @media (hover: hover) {
    :scope {
      padding: 2px;
    }
  }
  @supports (display: grid) {
    :scope {
      display: grid;
    }
  }
  :scope:is(:checked, [data-force-state~="checked"]) {
    outline: 1px solid teal;
  }
  :scope:visited {
    outline-color: purple;
  }
  @container (max-width: 767px) {
    :scope {
      margin: 0;
    }
  }
  @container (max-width: 767px) {
    @media (prefers-contrast: more) {
      :scope {
        outline-width: 2px;
      }
    }
  }
  @container (min-width: 500px) {
    :scope:is(:hover, [data-force-state~="hover"]) {
      padding: 1rem;
    }
  }
}`,
  );
  // An unknown clause gates the block out: a prefixless query, a typo'd
  // reference, a literal spanning two axes.
  assert.equal(
    printEntry(`[data-selectable="${BLOCK}"]`, null, { '(min-width: 900px)': { color: 'red' }, 'narow': { color: 'red' }, '@media (hover: hover) and (pointer: fine)': { color: 'red' } }, CONDITIONS),
    '',
  );
  // The container-root gate counts a literal container clause, not a
  // media or supports one.
  assert.equal(mapUsesContainerConditions({ [BLOCK]: { '@container (min-width: 500px)': { color: 'red' } } }, CONDITIONS), true);
  assert.equal(mapUsesContainerConditions({ [BLOCK]: { '@container (min-width: 500px)+hover': { color: 'red' } } }, CONDITIONS), true);
  assert.equal(mapUsesContainerConditions({ [BLOCK]: { '@media (min-width: 500px)': { color: 'red' }, '@supports (display: grid)': { color: 'red' }, hover: { color: 'red' } } }, CONDITIONS), false);
  assert.equal(mapUsesContainerConditions({ [BLOCK]: { narrow: { color: 'red' } } }, CONDITIONS), true);
});

test('spellings outside the grammar print nothing', () => {
  assert.equal(
    printMap(
      {
        [`${BLOCK}::&::part(x)`]: { default: { color: 'red' } },
        [`${BLOCK}::&`]: { default: { color: 'red' } },
        [`${BLOCK}::& ::before`]: { default: { color: 'red' } },
        [`${BLOCK}::.quote::before::after`]: { default: { color: 'red' } },
      },
      CONDITIONS,
    ),
    '',
  );
});

test('a fragment\'s anchor continues onto the rule\'s landing; a box rule admits no continuation', () => {
  const styles = {
    '': { default: { padding: '1rem' } },
    '::&::before': { default: { content: '""' }, hover: { color: 'purple' } },
    '::.link::after': { default: { content: '""' } },
  };
  assert.equal(
    printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', styles, '', CONDITIONS, '> d-text'),
    `@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"] {
    padding: 1rem;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"]::before {
    content: "";
  }
  :where(:scope) > [data-element="block_type:text"]:is(:hover, [data-force-state~="hover"])::before {
    color: purple;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > :where([data-element="block_type:text"]) .link::after {
    content: "";
  }
}`,
  );
  assert.equal(
    printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', styles, '', CONDITIONS, '> d-text::before'),
    `@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"]::before {
    padding: 1rem;
  }
}`,
  );
});
