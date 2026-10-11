/**
 * Answers value-grammar and roster cases through the CLIENT twins for the
 * round-trip lock.
 *
 * ValueGrammarTest::testClientTwinEquivalence (PHPUnit, daedalus_blueprint)
 * resolves the same cases through the PHP ValueGrammar and MaterialsRoster
 * (whose scope door delegates to the Address value object; scopes travel
 * here as printed addresses)
 * and pipes the roster's records (MaterialRecord::toArray()) and the case
 * lists here as JSON on stdin:
 *   {records: [...],
 *    resolve: [{value, slot}], references: [{value, slot}],
 *    canonical: [{value, slot}],
 *    spell: [{kind, id, slot}],
 *    applicable: [{kind, id, scope, topLevel, withinBlueprint}],
 *    scopesPair: [{variant, element}]}
 * stdout is a JSON object with one array per case list, in case order:
 * resolve/references answers as {kind, id, spelling} (the record is a
 * lookup convenience, not part of the contract), canonical and spell as
 * strings, applicable/scopesPair as booleans. The test asserts deep
 * equality with the PHP answers, so the two grammars are locked together.
 */
import { readFileSync } from 'node:fs';
import { canonical, references, resolveValue, spell } from '../../js/edit_mode/materials/grammar.js';
import { createRoster, scopesPair } from '../../js/edit_mode/materials/roster.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
const roster = createRoster(input.records ?? []);
const contract = ({ kind, id, spelling }) => ({ kind, id, spelling });

process.stdout.write(JSON.stringify({
  resolve: (input.resolve ?? []).map((c) => contract(resolveValue(c.value, c.slot, roster))),
  references: (input.references ?? []).map((c) => references(c.value, c.slot, roster).map(contract)),
  canonical: (input.canonical ?? []).map((c) => canonical(c.value, c.slot, roster)),
  spell: (input.spell ?? []).map((c) => spell(roster.get(c.kind, c.id), c.slot)),
  applicable: (input.applicable ?? []).map((c) => roster.applicable(roster.get(c.kind, c.id), c.scope, c.topLevel ?? true, c.withinBlueprint ?? null)),
  scopesPair: (input.scopesPair ?? []).map((c) => scopesPair(c.variant, c.element)),
}));
