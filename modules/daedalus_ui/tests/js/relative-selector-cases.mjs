/**
 * Answers relative selector cases through the CLIENT twin for the
 * round-trip lock.
 *
 * RelativeSelectorTest::testClientTwinEquivalence (daedalus_blueprint)
 * pipes a battery of selector texts and keys here as JSON on stdin:
 * {selectors: [text, ...], keys: [key, ...], unders: [[rule, suffix], ...]}.
 * stdout is {selectors: [{canonical, compounds, pseudo, partFirst,
 * reachesChildren, classes}], keys: [{root, selector} | null],
 * unders: [selector | null]}: the twin's parse, print and rebase per
 * case, in case order; the test asserts deep equality against the PHP
 * grammar's answers, so the two can never drift.
 */
import { readFileSync } from 'node:fs';
import {
  canonicalSelector,
  isPartFirst,
  parseSelector,
  reachesChildren,
  selectorClasses,
  splitKey,
  under,
} from '../../js/edit_mode/materials/relative-selector.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify({
  selectors: (input.selectors ?? []).map((text) => {
    const parsed = parseSelector(text);
    return parsed === null
      ? null
      : {
          canonical: canonicalSelector(text),
          compounds: parsed.compounds,
          pseudo: parsed.pseudo,
          partFirst: isPartFirst(text),
          reachesChildren: reachesChildren(text),
          classes: selectorClasses(text),
        };
  }),
  keys: (input.keys ?? []).map((key) => splitKey(key)),
  unders: (input.unders ?? []).map(([rule, suffix]) => under(rule, suffix)),
}));
