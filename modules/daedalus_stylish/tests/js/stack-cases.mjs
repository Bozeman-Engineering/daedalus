/**
 * Builds style stacks through the CLIENT twin for the round-trip lock.
 *
 * StackEquivalenceTest (the kernel test) builds the same stacks through
 * the PHP StyleStack and pipes the fixtures here as JSON on stdin:
 *   {batteries: [{guide, variants, blockTypes, sectionLayouts, tree,
 *                 cases: [{key, map, conditions}]}]}
 * one battery per guide payload (with a skin, without), `variants` the
 * roster's delivery records (MaterialRecord::toArray()), the two uuid
 * tables the client's scope capture reads (the blueprint's component
 * block types and section layouts), `tree` the annotated lean node tree
 * the ancestor tier and the base layer's subject tag read (null leaves
 * both out), each case a key, its map and the stored conditions to read
 * lines at. stdout is {batteries: [[{stack, lines: {condition:
 * lines}}]]}: buildStack() and layersAt() per case, the axis vocabulary
 * resolved the way the code view resolves it (axisVocabulary over the
 * served root); the test asserts deep equality against
 * StyleStack::stack() and linesAt(), so the twin and the server are
 * locked together. stackAt() is a client-only projection (the posed
 * cascade) and is node-pinned instead.
 */
import { readFileSync } from 'node:fs';
import { buildStack, layersAt } from '../../js/src/style-stack.js';
import { axisVocabulary } from '../../js/src/variant-scope.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

const answer = (battery) => {
  const guide = battery.guide ?? {};
  const variants = battery.variants ?? [];
  const blockTypes = battery.blockTypes ?? {};
  const sectionLayouts = battery.sectionLayouts ?? {};
  const tree = battery.tree ?? null;
  return (battery.cases ?? []).map(({ key, map, conditions = [] }) => {
    const stack = buildStack({
      guide,
      map,
      key,
      variants,
      axes: axisVocabulary(guide, key, blockTypes, sectionLayouts),
      blockTypes,
      sectionLayouts,
      tree,
    });
    const lines = {};
    conditions.forEach((condition) => {
      lines[condition] = layersAt(stack, condition);
    });
    return { stack, lines };
  });
};

process.stdout.write(JSON.stringify({
  batteries: (input.batteries ?? []).map(answer),
}));
