/**
 * Generates the wide selector tier and the wide condition tier:
 * data/wide-selectors.json and data/wide-conditions.json.
 *
 * What "the browser knows" means for selector vocabulary: every standard
 * pseudo-class and pseudo-element mdn-data names, and every HTML element
 * name html-tag-names lists (mdn-data carries no element list, so the
 * tag half rides a second pinned package). The tier is the universe
 * admission is judged against, never a grant: the roster admits an
 * undeclared tag, attribute, markup class or unpublished pseudo-class by
 * enforcement mode (open and guarded admit, strict refuses with the
 * corrective), and a word outside this tier is a typo refused in every
 * mode with the nearest names suggested (StyleMapValidator's
 * STYLE_SELECTOR_UNKNOWN). The pseudo-element list is data for the box
 * slot (landing 4); nothing reads it yet.
 *
 * Functional pseudo-classes and pseudo-elements are listed by their bare
 * name (`nth-child`, `is`, `slotted`), parens stripped and the functional
 * and plain spellings of one name (`:host`, `:host()`) folded to one
 * entry: the grammar owns which functional forms parse (`:has()`,
 * `:not()`, the nth family) and refuses the rest before admission, so the
 * tier only needs to know the name exists.
 *
 * What "the browser knows" means for a LITERAL condition clause (the WHEN
 * side: `@media (hover: hover)`, `@container (min-width: 500px)`) is the
 * media and container features and their value spaces. mdn-data carries
 * no feature list either (its at-rules file names `@media` and
 * `@container` without descriptors), so this half rides a third pinned
 * package, W3C's webref CSS extract: every `@media` and `@container`
 * descriptor the specs define, by name, with its `type` (`range`, so the
 * `min-`/`max-` prefixed spellings exist too, or `discrete`) and its
 * value space (`values`, the ident alternatives of a discrete feature,
 * the two words of a boolean one; `syntax` the spec's own for a range
 * feature, which the validator judges as a number, dimension or ratio).
 * Vendor-prefixed features are left out as vendor-prefixed selectors
 * are. The validator (StyleMapValidator::validateCondition) admits a
 * literal the tier knows by enforcement mode and refuses an unknown
 * feature or value everywhere with the nearest names; a `@supports` test
 * is judged against the wide PROPERTY tier (its tests are declarations)
 * and a pseudo-class literal against the selector tier above.
 *
 * Run at developer gesture (`npm run generate:wide-selectors`) when any
 * pinned dependency bumps; the output is committed so the diff is
 * reviewable and no runtime node is involved. The committed files must
 * stay in lockstep with the installed packages: the wide-selectors and
 * wide-conditions test suites regenerate in memory and compare, so a
 * drifted file fails the battery.
 */

import { writeFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { htmlTagNames } from 'html-tag-names';

const require = createRequire(import.meta.url);

/**
 * The bare name of an mdn-data selector key: `:nth-child()` is `nth-child`.
 */
const bareName = (key) => key.replace(/^:+/, '').replace(/\(\)$/, '');

/**
 * Builds the wide-selectors data structure from the installed packages.
 *
 * @returns {object}
 *   {mdnData, htmlTagNames, pseudoClasses, pseudoElements, tags}, the
 *   three lists sorted and deduplicated for stable committed diffs.
 */
export const buildWideSelectors = () => {
  const selectors = require('mdn-data/css/selectors.json');
  const pseudoClasses = new Set();
  const pseudoElements = new Set();
  for (const [key, definition] of Object.entries(selectors)) {
    if (definition.status !== 'standard' || !key.startsWith(':') || key.startsWith(':-') || key.startsWith('::-')) {
      continue;
    }
    // A pseudo-element exists only by its bare name: the grammar's
    // terminal slot takes no argument, so a functional form (`::part()`,
    // `::slotted()`, `::highlight()`, the view-transition family) adds
    // nothing the slot can take; `::cue` rides its bare twin.
    if (key.startsWith('::') && key.endsWith('()')) {
      continue;
    }
    (key.startsWith('::') ? pseudoElements : pseudoClasses).add(bareName(key));
  }
  return {
    mdnData: require('mdn-data/package.json').version,
    htmlTagNames: require('html-tag-names/package.json').version,
    pseudoClasses: [...pseudoClasses].sort(),
    pseudoElements: [...pseudoElements].sort(),
    tags: [...new Set(htmlTagNames)].sort(),
  };
};

/**
 * The value space of a webref feature descriptor.
 *
 * A discrete feature whose syntax is a plain alternation of keywords
 * (`none | hover`) lists them as `values`; the boolean syntax
 * `<mq-boolean>` is the two words `0` and `1`; anything else (a range
 * feature's `<length>`, a production the validator cannot enumerate)
 * keeps the spec's `syntax` and lists no values.
 *
 * @param {{type: string, syntax: string}} descriptor
 *   The webref descriptor.
 *
 * @returns {{type: string, syntax: string, values: ?string[]}}
 *   The feature record.
 */
const feature = ({ type, syntax }) => {
  const alternatives = syntax.split('|').map((word) => word.trim());
  let values = null;
  if (syntax === '<mq-boolean>') {
    values = ['0', '1'];
  } else if (type === 'discrete' && alternatives.every((word) => /^[a-z][a-z0-9-]*$/.test(word))) {
    values = alternatives;
  }
  return { type, syntax, values };
};

/**
 * Builds the wide-conditions data structure from the pinned webref extract.
 *
 * @returns {object}
 *   {webrefCss, media, container}: the package version and, per at-rule,
 *   the features by name (sorted for stable committed diffs), each
 *   {type, syntax, values}.
 */
export const buildWideConditions = () => {
  const { atrules } = require('@webref/css/css.json');
  const features = (name) => {
    const atrule = atrules.find((candidate) => candidate.name === name);
    if (!atrule) {
      throw new Error(`webref css carries no ${name} at-rule`);
    }
    return Object.fromEntries(
      atrule.descriptors
        .filter((descriptor) => !descriptor.name.startsWith('-'))
        .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0))
        .map((descriptor) => [descriptor.name, feature(descriptor)]),
    );
  };
  return {
    webrefCss: require('@webref/css/package.json').version,
    media: features('@media'),
    container: features('@container'),
  };
};

const main = () => {
  const moduleRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
  const selectors = buildWideSelectors();
  const selectorsTarget = join(moduleRoot, 'data', 'wide-selectors.json');
  mkdirSync(dirname(selectorsTarget), { recursive: true });
  writeFileSync(selectorsTarget, `${JSON.stringify(selectors, null, 2)}\n`);
  console.log(`Wrote ${selectorsTarget}`);
  console.log(`mdn-data ${selectors.mdnData}: ${selectors.pseudoClasses.length} pseudo-classes, ${selectors.pseudoElements.length} pseudo-elements; html-tag-names ${selectors.htmlTagNames}: ${selectors.tags.length} tags`);
  const conditions = buildWideConditions();
  const conditionsTarget = join(moduleRoot, 'data', 'wide-conditions.json');
  writeFileSync(conditionsTarget, `${JSON.stringify(conditions, null, 2)}\n`);
  console.log(`Wrote ${conditionsTarget}`);
  console.log(`webref css ${conditions.webrefCss}: ${Object.keys(conditions.media).length} media features, ${Object.keys(conditions.container).length} container features`);
};

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main();
}
