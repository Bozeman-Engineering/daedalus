/**
 * Answers vocabulary roster cases through the CLIENT twin for the
 * round-trip lock.
 *
 * VocabularyRosterTest::testClientTwinEquivalence (the kernel test)
 * builds the client payload (ClientStyleGuide), the materials roster's
 * token records (MaterialRecord::toArray(), the delivery shape) and the
 * roster for a battery of addresses, then pipes them here as JSON on
 * stdin: {guide, materials, cases: [{key, bundle, properties}]}, each
 * case an element key plus its bundle discriminator (the client's
 * address derivation) and the property names to ask. stdout is
 * {closed, law, cases: [{declared, groups, granted, spaces, stops, axes,
 * rootOnly, descriptions, owns}]}: the reads style-guide.js,
 * widget-values.js and variant-scope.js make of the served node
 * (declaredForKey, declaredSets, propertyGranted, constraintsFor,
 * stopsFor over the roster for a scale-bound property, axisVocabulary,
 * the axes' depth stamps, axisDescription, axisOwns), in case order;
 * the test asserts deep equality against the PHP roster's declared(),
 * groups(), grantsProperty(), valueSpace(), scaleStops(), axisNames(),
 * rootOnlyAxes(), axisDescriptions() and axisOwns(), so the client twin
 * and the roster are locked together (a pseudo-element's box, declared
 * or derived, and the row a scale-bound property offers included).
 */
import { readFileSync } from 'node:fs';
import { createRoster } from '../../../daedalus_ui/js/edit_mode/materials/roster.js';
import {
  bundleForKey, constraintsAreLaw, constraintsFor, declarationForKey, declaredForKey, declaredSets, parseKey, propertiesClosed, propertyGranted,
} from '../../js/src/style-guide.js';
import { axisDescription, axisOwns, axisVocabulary } from '../../js/src/variant-scope.js';
import { stopsFor } from '../../js/src/widget-values.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
const guide = input.guide ?? {};
const materials = createRoster(input.materials ?? []);

const answer = ({ key, bundle = null, properties = [] }) => {
  const parsed = parseKey(key);
  // The uuid tables the readers resolve bundles through, built from the
  // case's discriminator exactly as the blueprint tables would carry it.
  const blockTypes = parsed?.kind === 'block' ? { [parsed.uuid]: bundle } : {};
  const layouts = parsed?.kind === 'container' ? { [parsed.uuid]: bundle } : {};
  const bundleOf = bundleForKey(parsed, blockTypes, layouts);
  const node = declarationForKey(guide, parsed, bundleOf);
  const rootKey = key.split('::')[0];
  const root = declarationForKey(guide, parseKey(rootKey), bundleForKey(parsed, blockTypes, layouts));
  const axes = root?.axes !== null && typeof root?.axes === 'object' && !Array.isArray(root.axes) ? root.axes : {};
  const spaces = {};
  const stops = {};
  properties.forEach((property) => {
    spaces[property] = constraintsFor(node, property);
    // The row a scale-bound property offers (the roster's scaleStops
    // twin): stopsFor's stop values over the roster, the allowed
    // literals after the tokens, with no built-in fallback (a bound row
    // never reads one); null where no scale binds.
    stops[property] =
      typeof spaces[property]?.scale === 'string'
        ? stopsFor(guide, materials, spaces[property], []).map((stop) => stop.value)
        : null;
  });
  // The root posture (no layout tree): every axis of the vocabulary.
  const vocabulary = axisVocabulary(guide, rootKey, blockTypes, layouts);
  const descriptions = {};
  const owns = {};
  vocabulary.forEach((axis) => {
    const description = axisDescription(guide, rootKey, axis, blockTypes, layouts);
    if (description !== null) {
      descriptions[axis] = description;
    }
    const owned = axisOwns(guide, rootKey, axis, blockTypes, layouts);
    if (owned.length) {
      owns[axis] = owned;
    }
  });
  return {
    declared: declaredForKey(guide, parsed, bundleOf),
    groups: declaredSets(node),
    granted: properties.filter((property) => propertyGranted(node, property)),
    spaces,
    stops,
    axes: vocabulary,
    rootOnly: Object.keys(axes).filter((axis) => axes[axis]?.root_only === true),
    descriptions,
    owns,
  };
};

process.stdout.write(JSON.stringify({
  closed: propertiesClosed(guide),
  law: constraintsAreLaw(guide),
  cases: (input.cases ?? []).map(answer),
}));
