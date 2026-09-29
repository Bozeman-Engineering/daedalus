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
  printEntry,
  printFragmentStyles,
  printMap,
  scopeTargets,
} from '../../js/src/printer.js';

const CONDITIONS = {
  hover: { label: 'Hover', wrapper: ':hover', axis: 'state' },
  narrow: { label: 'Narrow', wrapper: '@container (max-width: 767px)', axis: 'width' },
  'first-child': { label: 'First child', wrapper: ':first-child', axis: 'structural', fact: true },
};

const BLOCK = 'block:aaaaaaaa-1111-2222-3333-444444444444';
const CONTAINER = 'container:aaaaaaaa-1111-2222-3333-444444444444';

test('scopeTargets glues the pseudo-element last on every target', () => {
  assert.deepEqual(scopeTargets('&::before'), [':scope::before']);
  assert.deepEqual(scopeTargets('&::after'), [':scope::after']);
  assert.deepEqual(scopeTargets('.quote::after'), [':scope.quote::after', '.quote::after']);
  assert.deepEqual(scopeTargets('.band > *::before'), [':scope:where(.band) > *::before', '.band > *::before']);
  assert.deepEqual(scopeTargets('.band + .band::before'), [':where(.band) + .band::before']);
  assert.deepEqual(scopeTargets('> pb-text::before'), [':where(:scope) > [data-element="block_type:text"]::before']);
  assert.deepEqual(scopeTargets('> pb-text:has(a)::after'), [':where(:scope) > [data-element="block_type:text"]:has(:where(a))::after']);
  assert.deepEqual(scopeTargets('> p + p::before'), [':where(:scope) > :where(p) + p::before']);
  assert.deepEqual(scopeTargets('> *::after'), [':scope > *::after']);
  assert.deepEqual(scopeTargets('p::before'), ['p::before']);
  assert.deepEqual(scopeTargets('pb-block::before'), [':is([data-element^="block_type:"], [data-element^="block_plugin:"])::before']);
  // The pseudo-free shapes are untouched.
  assert.deepEqual(scopeTargets(null), [':scope']);
  assert.deepEqual(scopeTargets('.quote'), [':scope.quote', '.quote']);
});

test('the anchor prints the root\'s own box: states, facts and the hold before it, no companion', () => {
  const css = printEntry(
    `[data-selectable="${BLOCK}"]`,
    '&::before',
    {
      default: { content: '""', color: 'red', 'animation-trigger': '--in-view play-once' },
      hover: { color: 'blue' },
      'first-child': { display: 'none' },
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
  :scope:first-child::before {
    display: none;
  }
}`,
  );
  assert.ok(!css.includes('data-claims-content'));
});

test('a part\'s box keeps the dual and the part\'s donut; a rule\'s box takes none', () => {
  const css = printMap(
    {
      [`${CONTAINER}::.quote::after`]: { default: { content: '""' }, hover: { color: 'purple' } },
      [`${CONTAINER}::> pb-text::before`]: { default: { content: '""' }, hover: { color: 'blue' } },
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

test('spellings outside the grammar print nothing', () => {
  assert.equal(
    printMap(
      {
        [`${BLOCK}::&::marker`]: { default: { color: 'red' } },
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
    printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', styles, '', CONDITIONS, '> pb-text'),
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
    printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', styles, '', CONDITIONS, '> pb-text::before'),
    `@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"]::before {
    padding: 1rem;
  }
}`,
  );
});
