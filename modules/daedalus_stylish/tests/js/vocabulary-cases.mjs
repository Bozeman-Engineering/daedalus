/**
 * Answers vocabulary roster cases through the CLIENT twin for the
 * round-trip lock.
 *
 * VocabularyRosterTest::testClientTwinEquivalence (the kernel test)
 * builds the client payload (ClientStyleGuide) and the roster for a
 * battery of addresses, then pipes the payload and the battery here as
 * JSON on stdin: {guide, cases: [{key, bundle, properties}]}, each case
 * an element key plus its bundle discriminator (the client's address
 * derivation) and the property names to ask. stdout is {closed, law,
 * cases: [{declared, groups, granted, spaces, axes, rootOnly,
 * descriptions, owns}]}: the reads style-guide.js and variant-scope.js
 * make of the served node (declaredForKey, declaredSets,
 * propertyGranted, constraintsFor, axisVocabulary, the axes' depth
 * stamps, axisDescription, axisOwns), in case order; the test asserts
 * deep equality against the PHP roster's declared(), groups(),
 * grantsProperty(), valueSpace(), axisNames(), rootOnlyAxes(),
 * axisDescriptions() and axisOwns(), so the client twin and the roster
 * are locked together (a pseudo-element's box, declared or derived,
 * included).
 */
import { readFileSync } from 'node:fs';
import {
  bundleForKey, constraintsAreLaw, constraintsFor, declarationForKey, declaredForKey, declaredSets, parseKey, propertiesClosed, propertyGranted,
} from '../../js/src/style-guide.js';
import { axisDescription, axisOwns, axisVocabulary } from '../../js/src/variant-scope.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
const guide = input.guide ?? {};

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
  properties.forEach((property) => {
    spaces[property] = constraintsFor(node, property);
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
