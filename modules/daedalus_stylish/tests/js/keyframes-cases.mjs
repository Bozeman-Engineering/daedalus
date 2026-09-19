/**
 * Answers keyframes-name cases through the CLIENT twin for the round-trip
 * lock.
 *
 * KeyframesNamesTest::testClientTwinEquivalence (PHPUnit) runs the same
 * cases through KeyframesNames::inValue() and pipes them here as JSON on
 * stdin: {cases: [{property, value}]}. stdout is {cases: [[name, ...]]},
 * one name list per case in case order; the test asserts deep equality, so
 * the PHP grammar and its client twin are locked together.
 */
import { readFileSync } from 'node:fs';
import { keyframesNamesInValue } from '../../js/src/keyframes-names.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify({
  cases: (input.cases ?? []).map((c) => keyframesNamesInValue(c.property, c.value)),
}));
