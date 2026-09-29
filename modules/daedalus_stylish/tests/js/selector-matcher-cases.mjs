/**
 * Answers selector matcher queries through the CLIENT twin for the
 * round-trip lock.
 *
 * SelectorMatcherTest::testClientTwinEquivalence pipes a tree and a
 * battery of queries here as JSON on stdin: {tree, queries: [{op,
 * args}]}, op one of has, facts, ancestors, matches, matching, reaches,
 * satisfies with the twin method's arguments. stdout is {answers: [...]}
 * in query order (a query the twin refuses answers {error: true}); the
 * test asserts deep equality against the PHP matcher's answers over the
 * same tree, so the two can never drift.
 */
import { readFileSync } from 'node:fs';
import { createMatcher } from '../../js/src/selector-matcher.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
const matcher = createMatcher(input.tree ?? []);

process.stdout.write(JSON.stringify({
  answers: (input.queries ?? []).map(({ op, args = [] }) => {
    try {
      return matcher[op](...args);
    } catch (error) {
      return { error: true };
    }
  }),
}));
