/**
 * Generates data/wide-properties.json, the wide property tier.
 *
 * Grows the spine's recognized-property vocabulary from the ~80 curated
 * PropertyGroup properties to every standard CSS property mdn-data knows.
 * Each property's Value Definition Syntax is resolved (named <syntaxes> and
 * <'property'> refs expanded) and classified onto the existing typed
 * validator shapes where they honestly fit: keyword, dimension (with the
 * number-union and list flags), number, color, and ident. Everything the
 * typed machinery cannot model (shorthands, url/string/function-valued
 * grammars, slash-separated grammars) is emitted as {type: "any", syntax}:
 * the server then guarantees name membership plus emit-gate hygiene only,
 * the client lints the real grammar via csstree's embedded lexer, and the
 * AI schema carries the syntax string as its hint. A safe-but-ungrammatical
 * value is inert in the browser, never unsafe, so the tail is deliberately
 * permissive; bleeding-edge properties are harmlessly any-typed.
 *
 * Run at developer gesture (`npm run generate:wide-properties`) when the
 * pinned mdn-data dependency bumps; the output is committed so the diff is
 * reviewable and no runtime node is involved. The committed file must stay
 * in lockstep with the installed mdn-data: the wide-properties test suite
 * regenerates in memory and compares, so a drifted file fails the battery.
 */

import { writeFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { definitionSyntax } from 'css-tree';

const require = createRequire(import.meta.url);

/**
 * Atom types the typed validators model, mapped to their validator type.
 *
 * These are terminal in the walk: their own grammar definitions (the color
 * function zoo, calc() internals) are the validators' problem space already
 * and never expand.
 */
const ATOMS = {
  length: 'dimension',
  'length-percentage': 'dimension',
  percentage: 'dimension',
  time: 'dimension',
  angle: 'dimension',
  flex: 'dimension',
  number: 'number',
  integer: 'number',
  color: 'color',
};

/**
 * Maximum ref-expansion depth; grammars deeper than this classify as any.
 */
const DEPTH_CAP = 12;

/**
 * Walks one parsed VDS tree, expanding refs and collecting classification
 * evidence into the accumulator.
 *
 * @param {object} node
 *   A definitionSyntax AST node.
 * @param {object} acc
 *   {keywords: Set, atoms: Set, opaque: Set, list: bool, nonNegative: bool}
 *   where atoms holds validator type names and opaque holds the terminal
 *   names the typed machinery cannot model.
 * @param {object} data
 *   {properties, syntaxes} from mdn-data.
 * @param {Set} seen
 *   Ref names already being expanded (cycle guard).
 * @param {number} depth
 *   Current expansion depth.
 */
const walkNode = (node, acc, data, seen, depth) => {
  if (depth > DEPTH_CAP) {
    acc.opaque.add('depth-cap');
    return;
  }
  switch (node.type) {
    case 'Group': {
      // A sequence (' ') or any-order combination ('||', '&&') of more than
      // one term is a space-separated multi-value grammar; the list flag's
      // split-and-validate-each is the honest typed model for it (a plain
      // scalar type would falsely reject "underline overline"). Single
      // alternation ('|') stays scalar.
      const meaningful = node.terms.filter((term) => term.type !== 'Token');
      if (node.combinator !== '|' && meaningful.length > 1) {
        acc.list = true;
      }
      for (const term of node.terms) {
        walkNode(term, acc, data, seen, depth);
      }
      return;
    }

    case 'Multiplier':
      if (node.comma || node.max === 0 || node.max > 1 || node.min > 1) {
        acc.list = true;
      }
      walkNode(node.term, acc, data, seen, depth);
      return;

    case 'Keyword':
      acc.keywords.add(node.name.toLowerCase());
      return;

    case 'Type': {
      const atom = ATOMS[node.name];
      if (atom !== undefined) {
        acc.atoms.add(atom);
        // A missing range keeps the CSS type's full span, negatives
        // included; an explicit non-negative range ([0,∞], [1,∞]) marks the
        // atom safe for the validators' default no-negatives posture.
        if (atom !== 'color' && !(node.opts?.type === 'Range' && node.opts.min !== null && node.opts.min >= 0)) {
          acc.negative = true;
        }
        return;
      }
      const expansion = data.syntaxes[node.name]?.syntax;
      if (expansion === undefined) {
        acc.opaque.add(node.name);
        return;
      }
      expandRef(node.name, expansion, acc, data, seen, depth);
      return;
    }

    case 'Property': {
      const expansion = data.properties[node.name]?.syntax;
      if (expansion === undefined) {
        acc.opaque.add(node.name);
        return;
      }
      expandRef(node.name, expansion, acc, data, seen, depth);
      return;
    }

    case 'Function':
      acc.opaque.add(`${node.name}()`);
      return;

    case 'Token':
      // ")" closes function notation (the Function node already classified
      // it opaque) and "," only separates function arguments. Any other
      // literal token (the slash in ratio and shorthand grammars) is
      // structure the typed value space cannot express: splitList would
      // hand it to a scalar validator and falsely reject, so it must force
      // the any tier instead.
      if (node.value !== ')' && node.value !== ',') {
        acc.opaque.add(`token:${node.value}`);
      }
      return;

    default:
      acc.opaque.add(node.type);
  }
};

/**
 * Expands one named ref (a <syntax> or <'property'>) into the accumulator.
 */
const expandRef = (name, syntax, acc, data, seen, depth) => {
  if (seen.has(name)) {
    return;
  }
  seen.add(name);
  try {
    walkNode(definitionSyntax.parse(syntax), acc, data, seen, depth + 1);
  }
  catch {
    acc.opaque.add(name);
  }
  seen.delete(name);
};

/**
 * Humanizes a property name into a definition label.
 */
const labelFor = (property) => {
  const label = property.replaceAll('-', ' ');
  return label.charAt(0).toUpperCase() + label.slice(1);
};

/**
 * The property's spec-area label, from mdn-data's group tags.
 */
const areaFor = (definition) => {
  const group = definition.groups?.[0] ?? 'Other';
  return group.replace(/^CSS /, '');
};

/**
 * Classifies one property's collected evidence onto a typed definition.
 *
 * @returns {object}
 *   The definition to emit: a typed shape when the evidence fits the
 *   validators, otherwise {type: "any", syntax}.
 */
const classify = (property, definition, acc) => {
  const base = { label: labelFor(property) };
  const keywords = [...acc.keywords];
  const typed = () => {
    if (acc.opaque.size !== 0) {
      return null;
    }
    // Pure keyword vocabulary; combination grammars ("underline overline")
    // carry the list flag so each space-separated component validates alone.
    if (acc.atoms.size === 0) {
      if (keywords.length === 0) {
        return null;
      }
      const def = { ...base, type: 'keyword', keywords };
      if (acc.list) {
        def.list = true;
      }
      return def;
    }
    // Dimensions and numbers, together at most: the dimension validator
    // carries the number union as a flag.
    if ([...acc.atoms].every((atom) => atom === 'dimension' || atom === 'number')) {
      const type = acc.atoms.has('dimension') ? 'dimension' : 'number';
      const def = { ...base, type };
      if (keywords.length !== 0) {
        def.keywords = keywords;
      }
      if (acc.negative) {
        def.negative = true;
      }
      if (type === 'dimension' && acc.atoms.has('number')) {
        def.number = true;
      }
      if (acc.list) {
        def.list = true;
      }
      return def;
    }
    if (acc.atoms.size === 1 && acc.atoms.has('color')) {
      const def = { ...base, type: 'color' };
      if (keywords.length !== 0) {
        def.keywords = keywords;
      }
      if (acc.list) {
        def.list = true;
      }
      return def;
    }
    return null;
  };
  const ident = () => {
    // A custom-ident value space (animation names, grid lines) with nothing
    // the ident validator cannot absorb; keywords ride along.
    if (acc.atoms.size !== 0 || acc.opaque.size !== 1 || !acc.opaque.has('custom-ident')) {
      return null;
    }
    const def = { ...base, type: 'ident' };
    if (keywords.length !== 0) {
      def.keywords = keywords;
    }
    if (acc.list) {
      def.list = true;
    }
    return def;
  };
  return typed() ?? ident() ?? { ...base, type: 'any', syntax: definition.syntax };
};

/**
 * Builds the wide-properties data structure from the installed mdn-data.
 *
 * @returns {object}
 *   {mdnData: version, properties: {name: definition}} with properties in
 *   alphabetical order for stable committed diffs.
 */
export const buildWideProperties = () => {
  const css = require('mdn-data/css');
  const version = require('mdn-data/package.json').version;
  const data = { properties: css.properties, syntaxes: css.syntaxes };
  const properties = {};
  const names = Object.keys(css.properties)
    .filter((name) => css.properties[name].status === 'standard' && !name.startsWith('-'))
    .sort();
  for (const name of names) {
    const definition = css.properties[name];
    const acc = { keywords: new Set(), atoms: new Set(), opaque: new Set(), list: false, negative: false };
    try {
      walkNode(definitionSyntax.parse(definition.syntax), acc, data, new Set(), 0);
    }
    catch {
      acc.opaque.add('unparseable');
    }
    const emitted = classify(name, definition, acc);
    emitted.area = areaFor(definition);
    properties[name] = emitted;
  }
  return { mdnData: version, properties };
};

const main = () => {
  const data = buildWideProperties();
  const moduleRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
  const target = join(moduleRoot, 'data', 'wide-properties.json');
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, `${JSON.stringify(data, null, 2)}\n`);

  const counts = {};
  for (const definition of Object.values(data.properties)) {
    counts[definition.type] = (counts[definition.type] ?? 0) + 1;
  }
  const total = Object.keys(data.properties).length;
  const any = counts.any ?? 0;
  console.log(`Wrote ${target}`);
  console.log(`mdn-data ${data.mdnData}: ${total} standard properties, ${total - any} typed (${Math.round(((total - any) / total) * 100)}%)`);
  console.log(Object.entries(counts).map(([type, count]) => `${type}: ${count}`).join(', '));
};

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main();
}
