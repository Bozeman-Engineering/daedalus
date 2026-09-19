/**
 * Resolves declaration cases through the CLIENT twin for the round-trip
 * lock.
 *
 * ElementResolverTest::testClientTwinEquivalence (PHPUnit) resolves the
 * same cases through the PHP ElementResolver — real entities, the real
 * layered declarations — and pipes each case's declaration, data facade,
 * and depth here as JSON on stdin:
 *   {cases: [{declaration, data, depth}, ...]}
 * (the PHP side's emptiness input rides the facade as data.empty)
 * stdout is a JSON array of the client twin's resolved trees; the test
 * asserts deep equality with the server's trees, so the two resolvers are
 * locked together (the CssPrinter/printer.js pattern): the live editing
 * preview must show exactly the tree the commit will render.
 */
import { readFileSync } from 'node:fs';
import { resolveElement } from '../../js/edit_mode/blueprint/element-resolver.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(
  (input.cases ?? []).map((entry) => resolveElement(entry.declaration, entry.data ?? null, entry.depth ?? 'root')),
));
