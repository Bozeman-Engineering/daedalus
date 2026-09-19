/**
 * Prints style maps through the CLIENT printer for the round-trip test.
 *
 * PrinterEquivalenceTest (PHPUnit) prints the same cases through the PHP
 * printer and pipes them here as JSON on stdin:
 *   {conditions: {name: {label, wrapper}},
 *    cases: [styleMap, ...],
 *    variantCases: [{scopeRoot, scope, styles, fragment}, ...],
 *    keyframesCases: [{name, steps}, ...]}
 * stdout is a JSON object {cases: [...], variantCases: [...],
 * keyframesCases: [...]} of the client printer's CSS text per case; the
 * test asserts byte-equality with the server's output (the two
 * printers are locked together — the preview and code view must show
 * exactly what will persist). variantCases exercise the fragment printer
 * (CssPrinter::printFragmentStyles / printFragmentStyles): one fragment's
 * styles per case, part suffixes and the section donut included.
 * keyframesCases exercise the named at-rule printer
 * (CssPrinter::printKeyframes / printKeyframes): one animation per case,
 * the name and offset gates and step hygiene included. The motion trigger
 * lane rides cases and variantCases: the marker custom properties beside
 * `timeline-trigger` / `animation-trigger` and the `@supports not` hold
 * beside any bound block, on element and variant blocks alike.
 */
import { readFileSync } from 'node:fs';
import { printMap, printFragmentStyles, printKeyframes } from '../../js/src/printer.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify({
  cases: (input.cases ?? []).map((entry) => printMap(entry, input.conditions)),
  variantCases: (input.variantCases ?? []).map((entry) => printFragmentStyles(entry.scopeRoot, entry.scope, entry.styles, entry.fragment, input.conditions)),
  keyframesCases: (input.keyframesCases ?? []).map((entry) => printKeyframes(entry.name, entry.steps)),
}));
