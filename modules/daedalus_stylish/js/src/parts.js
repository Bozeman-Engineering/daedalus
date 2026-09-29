import {
  parseKey,
  declarationForKey,
  bundleForKey,
  flattenElements,
  findPatternElement,
  findInstanceDeclaration,
  tagWord,
} from './style-guide.js';
import {
  CLASS_PART,
  HAS,
  NEXT,
  NOT,
  SUBSEQUENT,
  TAG,
  TYPE,
  TYPE_BLOCK,
  TYPE_CONTAINER,
  UNIVERSAL,
  parseSelector,
  typeAddress,
  typeName,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * The type-YAML → instance join for presentation parts.
 *
 * A part is a sub-element the style guide declares for an element's TYPE
 * (elements: '.testimonial__quote' in the kind, block-type, or layout
 * YAML); an instance's parts are just its type's declared elements keyed
 * under the instance's selectable key. This module is the pure half — the
 * join the inspector's drill target and the tree's part rows both resolve
 * through. Instance facts (which minted classes exist, what carries them)
 * come from the blueprint mirror's `_element.markup` annotation, handed in
 * by the caller, this module reads no DOM and no store.
 */

/**
 * The one legal part selector shape: a single CSS class.
 *
 * Locked to match the sub-element key rule ('::.foo'): the selector
 * prints into CSS output and queries the live DOM, so its charset is an
 * injection surface. A declared element with any other selector shape is
 * unreachable as a style-map key anyway — filtered here so no consumer
 * renders a row it could never style.
 */
export const PART_SELECTOR = /^\.[A-Za-z0-9_-]+$/;

/**
 * The instance-part namespace: minted rich-text identity classes.
 *
 * The client twin of this module's own server constant
 * (StyleMapValidator::INSTANCE_PART, the CssPrinter/printer.js twin
 * pattern), a part selector's leading dot over the minted class shape.
 * Instance parts are never DECLARED concretely; they resolve through the
 * guide's site instance namespaces (guide.instances, the spine's
 * `instances:` lane) and exist only in content, so their labels come from
 * the `_element.markup.instances` annotation on the blueprint mirror
 * (stamped server-side from the declared tag vocabulary, there is no
 * client tag-vocabulary copy) and their styles never travel in variants
 * (captureSources excludes them; the server rejects them in variant
 * styles).
 */
export const INSTANCE_PART = /^\.s-[a-z0-9]+$/;

/**
 * The words for a generated box, by pseudo name: the one vocabulary the
 * page-elements tree's rows (tree-parts.js generated()), the label seam
 * (selectorLabel, so the breadcrumb crumb), the address sentences and
 * the target chip's doorway (write-address.js, subject-model.js) all
 * speak, so a box reads "Before" / "After" wherever it is named.
 */
export const BOX_LABELS = {
  before: () => Drupal.t('Before'),
  after: () => Drupal.t('After'),
};

/**
 * The word for a generated box's pseudo name ('before' → "Before").
 */
export const boxWord = (pseudo) => BOX_LABELS[pseudo]();

/**
 * Finds an instance row (or nested carrier) in a markup annotation.
 *
 * @param {?object} markup
 *   A key's `_element.markup` annotation (or null).
 * @param {string} sub
 *   The instance-part selector ('.s-a3').
 *
 * @return {?{class: string, tag: string, label: string}}
 */
export const findInstancePart = (markup, sub) => {
  const search = (rows) => {
    for (const row of Array.isArray(rows) ? rows : []) {
      if (`.${row.class}` === sub) {
        return row;
      }
      const nested = search(row.children);
      if (nested) {
        return nested;
      }
    }
    return null;
  };
  return search(markup?.instances);
};

/**
 * The declared parts for an element key.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?string} key
 *   A selectable key, or a selector key, whose parts are the declaration
 *   the roster walk lands on (declarationForKey: a children target's
 *   landing type declares the parts its rows may bind, an instance
 *   namespace or an element node declares none). Unparseable keys have
 *   no parts.
 * @param {object} blockTypes
 *   Component uuid → inline block type (source.getBlockTypes()).
 * @param {object} sectionLayouts
 *   Section uuid → layout plugin id (source.getSectionLayouts()).
 *
 * @return {Array<{id: string, label: string}>}
 *   Parts in flattened tree order (depth-first, parents before children,
 *   per flattenElements()); id is the class selector ('.quote'), label the
 *   YAML's human label (falls back to the selector).
 */
export const partsForKey = (
  guide,
  key,
  blockTypes = {},
  sectionLayouts = {},
) => {
  const parsed = parseKey(key);
  if (!parsed) {
    return [];
  }
  const bundle = bundleForKey(parsed, blockTypes, sectionLayouts);
  const elements = flattenElements(declarationForKey(guide, parsed, bundle));
  return Object.entries(elements)
    .filter(([selector]) => PART_SELECTOR.test(selector))
    .map(([selector, declaration]) => ({
      id: selector,
      label: declaration?.label ?? selector,
    }));
};

/**
 * The label of the declaration covering an instance class: the owner
 * declaration's own pattern part (`.s-*` in a type's anatomy), else the
 * site instance namespace's (guide.instances); null when neither speaks
 * for it. An identity class is worn by one element in the world, so the
 * one thing that names its kind without the annotation is the namespace
 * that minted it, never the class itself.
 */
const coveringLabel = (guide, declaration, sub) => {
  const covering =
    findPatternElement(declaration, sub) ??
    findInstanceDeclaration(guide?.instances, sub);
  return covering?.label ? covering.label : null;
};

/**
 * The human label for one part class under a key.
 *
 * Declared parts label as authored. An INSTANCE part (a minted identity
 * class, never declared concretely) labels from the key's markup
 * annotation ("Paragraph", "Heading 2", stamped server-side from the
 * declared tag vocabulary, the same name its markup-view row wears),
 * falling back to the covering declaration's own label (coveringLabel: an
 * owner pattern part's, else the site instance namespace's) where the
 * annotation is out of reach (the satellite window, a key the model does
 * not speak for). The selector itself is the last resort, unchanged.
 */
const partLabel = (guide, key, sub, blockTypes, sectionLayouts, markup) => {
  const declared = partsForKey(guide, key, blockTypes, sectionLayouts).find(
    (part) => part.id === sub,
  )?.label;
  if (declared !== undefined) {
    return declared;
  }
  if (INSTANCE_PART.test(sub)) {
    const instance = findInstancePart(markup, sub);
    if (instance?.label) {
      return instance.label;
    }
    const parsed = parseKey(key);
    if (parsed && !parsed.selector) {
      const covering = coveringLabel(
        guide,
        declarationForKey(guide, parsed, bundleForKey(parsed, blockTypes, sectionLayouts)),
        sub,
      );
      if (covering !== null) {
        return covering;
      }
    }
  }
  return sub;
};

/**
 * The type compounds the guide declares, each with its human label.
 *
 * The two kind spellings first (`pb-container` "Container", `pb-block`
 * "Block": a kind compound matches at any depth, so it words the kind,
 * never an element's minted Section/Container type), then one `pb-{bundle}` per
 * served block type labelled by its bundle's name (the block_content
 * type's label, served on guide.blockTypes[bundle].label); a bundle
 * the site has no type entity for (a dormant declaration) labels by
 * its spelling. The one table every type surface reads: the code
 * view's selector completion and its row labels.
 *
 * @param {object} guide
 *   The style guide payload.
 *
 * @return {Object<string, string>}
 *   Type compound → label, kinds first, then bundles in served order.
 */
export const typeLabels = (guide) => {
  const table = {
    [TYPE_CONTAINER]: 'Container',
    [TYPE_BLOCK]: 'Block',
  };
  Object.entries(guide?.blockTypes ?? {}).forEach(([bundle, node]) => {
    const type = typeName(`block_type:${bundle}`);
    const label = node?.label;
    table[type] = typeof label === 'string' && label !== '' ? label : type;
  });
  return table;
};

/**
 * The human label of a type compound, plural: "Text blocks" for
 * `pb-text` (the bundle's label), "Containers" and "Blocks" for the two
 * kinds; an undeclared or unlabelled type reads as its spelling.
 */
const typeLabel = (guide, type) => {
  if (type === TYPE_CONTAINER) {
    return 'Containers';
  }
  if (type === TYPE_BLOCK) {
    return 'Blocks';
  }
  const label = typeLabels(guide)[type];
  return typeof label === 'string' && label !== type ? `${label} blocks` : type;
};

const lower = (text) =>
  typeof text === 'string' && text !== ''
    ? text.charAt(0).toLowerCase() + text.slice(1)
    : text;

/**
 * The bare word of a type for the middle of a sentence: "image" for
 * `pb-image` (the bundle's label), "container" and "block" for the two
 * kinds; an undeclared or unlabelled type reads as its spelling.
 */
const typeWord = (guide, type) => {
  if (type === TYPE_CONTAINER) {
    return 'container';
  }
  if (type === TYPE_BLOCK) {
    return 'block';
  }
  const label = typeLabels(guide)[type];
  return typeof label === 'string' && label !== type ? lower(label) : type;
};

/**
 * A noun behind its indefinite article ("a link", "an image").
 */
const article = (noun) =>
  /^[aeiou]/i.test(noun)
    ? Drupal.t('an @noun', { '@noun': noun })
    : Drupal.t('a @noun', { '@noun': noun });

/**
 * The block-type declaration a type compound's parts label against, or
 * null for a kind or an undeclared type.
 */
const typeOwner = (guide, type) => {
  const address = typeAddress(type);
  return address?.startsWith('block_type:')
    ? (guide?.blockTypes?.[address.slice('block_type:'.length)] ?? null)
    : null;
};

/**
 * The words of one relative selector, one group per descent step.
 *
 * A group is a compound with the sibling-combinated compounds that
 * follow it folded in: `> p + p` is ONE group (the second paragraph,
 * worded "right after a paragraph"), `> pb-text .link` two ("Text
 * blocks", "Link"). Each group carries its subject's words and its
 * relation phrases, so a row header (selectorLabel) and an address
 * sentence (write-address.js addressLabel) read the same relations with
 * their own subject forms:
 * - `compound`: the group's last compound (the subject the words name);
 * - `combinator`: what reaches the group, its head compound's
 *   (`descendant` or `child`; a `:has()` argument's head may carry a
 *   sibling lead);
 * - `label`: the row-header word, a part class as partLabel() does
 *   (declared, instance, or the class itself), a tag by the guide's one
 *   tag word (style-guide.js tagWord(): the base word with the site's
 *   declared labels applied, the identity word for a tag outside the
 *   closed set, the bare tag otherwise), a type by typeLabel() ("Text
 *   blocks"), the children as "Children";
 * - `noun`: the singular lower-cased word ("paragraph", "image",
 *   "link", "child");
 * - `plural`: the plural lower-cased subject ("paragraphs", "text
 *   blocks", "children");
 * - `phrases`: the relation phrases in composition order, the `:has`
 *   phrases ("containing a link"; a sibling-led argument "right before
 *   an image" / "before an image"), then the `:not` phrases ("except
 *   images"; an implied subject "without a link"), then the sibling
 *   phrase ("right after a paragraph" for `+`, "after a paragraph" for
 *   `~`, the predecessor worded with its own relations).
 * A part after a type (by descent, `>`, or as the sibling of a part
 * under it) labels against that type's own anatomy
 * (guide.blockTypes[bundle].elements); a `:has()` argument's parts
 * against the qualified type's; a `:not()` class is an alternative
 * subject at the qualified compound's own position and labels against
 * the context before it. An INSTANCE class (INSTANCE_PART) in any
 * context never prints itself: it labels as partLabel() does, the
 * annotation row's word, else the covering declaration's (the type's
 * own pattern part under a type, else the site namespace's "Text
 * element"), so a hand-typed or AI-written `> pb-formatted-text .s-2kxs`
 * still reads as words. Null outside the grammar.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string} key
 *   The owning element's key.
 * @param {?string} selector
 *   The canonical relative selector.
 * @param {object} blockTypes
 *   component uuid → bundle.
 * @param {object} sectionLayouts
 *   section uuid → layout id.
 * @param {?object} markup
 *   The key's `_element.markup` annotation (source.getMarkup(key)), or
 *   null where no annotation is reachable.
 *
 * @return {?Array<{compound: object, combinator: string, label: string,
 *   noun: string, plural: string, phrases: string[]}>}
 */
export const selectorWords = (
  guide,
  key,
  selector,
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
) => {
  const compounds = parseSelector(selector)?.compounds ?? null;
  if (!compounds) {
    return null;
  }
  const partWords = (name, owner) => {
    if (owner === null) {
      return partLabel(guide, key, name, blockTypes, sectionLayouts, markup);
    }
    const declared = flattenElements(owner)[name]?.label;
    if (typeof declared === 'string') {
      return declared;
    }
    if (INSTANCE_PART.test(name)) {
      // An identity class under a type: the same instance path as
      // partLabel(), the type's own declaration as the covering owner.
      return (
        findInstancePart(markup, name)?.label ||
        coveringLabel(guide, owner, name) ||
        name
      );
    }
    return name;
  };
  const subjectWords = (compound, owner) => {
    if (compound.kind === UNIVERSAL) {
      return { label: 'Children', noun: 'child', plural: 'children' };
    }
    if (compound.kind === TAG) {
      const word = tagWord(guide, compound.name);
      return { label: word, noun: lower(word), plural: `${lower(word)}s` };
    }
    if (compound.kind === TYPE) {
      return {
        label: typeLabel(guide, compound.name),
        noun: typeWord(guide, compound.name),
        plural: lower(typeLabel(guide, compound.name)),
      };
    }
    const label = partWords(compound.name, owner);
    return { label, noun: lower(label), plural: `${lower(label)}s` };
  };
  const nounPhrase = (group) => [article(group.noun), ...group.phrases].join(' ');
  const hasPhrase = (lead, thing, negated) => {
    if (lead === NEXT) {
      return negated
        ? Drupal.t('not right before @thing', { '@thing': thing })
        : Drupal.t('right before @thing', { '@thing': thing });
    }
    if (lead === SUBSEQUENT) {
      return negated
        ? Drupal.t('not before @thing', { '@thing': thing })
        : Drupal.t('before @thing', { '@thing': thing });
    }
    return negated
      ? Drupal.t('without @thing', { '@thing': thing })
      : Drupal.t('containing @thing', { '@thing': thing });
  };
  // The words of a chain in an owner context; groups() re-enters for a
  // `:has()` argument, so a relation inside a relation reads the same.
  const groups = (chain, context) => {
    let owner = context;
    const list = [];
    chain.forEach((compound) => {
      const words = compoundWords(compound, owner);
      owner = words.owner;
      words.combinator = compound.combinator;
      if ([NEXT, SUBSEQUENT].includes(compound.combinator) && list.length) {
        const previous = list.pop();
        words.combinator = previous.combinator;
        words.phrases.push(
          compound.combinator === NEXT
            ? Drupal.t('right after @thing', { '@thing': nounPhrase(previous) })
            : Drupal.t('after @thing', { '@thing': nounPhrase(previous) }),
        );
      }
      list.push(words);
    });
    return list;
  };
  const compoundWords = (compound, context) => {
    const implied = compound.kind === null;
    const owner = compound.kind === TYPE ? typeOwner(guide, compound.name) : context;
    if (implied) {
      // `:not(:has(x))`: the negation reads on each `:has` phrase.
      return {
        compound,
        implied,
        owner,
        phrases: compound.qualifiers.map((qualifier) => {
          const thing = groups(qualifier.argument, owner).map(nounPhrase).join(' › ');
          return hasPhrase(qualifier.argument[0].combinator, thing, true);
        }),
      };
    }
    const has = compound.qualifiers
      .filter((qualifier) => qualifier.name === HAS)
      .map((qualifier) => {
        const thing = groups(qualifier.argument, owner).map(nounPhrase).join(' › ');
        return hasPhrase(qualifier.argument[0].combinator, thing, false);
      });
    const not = compound.qualifiers
      .filter((qualifier) => qualifier.name === NOT)
      .map((qualifier) => {
        const negated = compoundWords(qualifier.argument, context);
        if (negated.implied) {
          return negated.phrases.join(' ');
        }
        return Drupal.t('except @things', {
          '@things': [`${negated.noun}s`, ...negated.phrases].join(' '),
        });
      });
    return {
      compound,
      implied,
      owner,
      ...subjectWords(compound, context),
      phrases: [...has, ...not],
    };
  };
  return groups(compounds, null).map(
    ({ compound, combinator, label, noun, plural, phrases }) => ({
      compound,
      combinator,
      label,
      noun,
      plural,
      phrases,
    }),
  );
};

/**
 * The human label for one relative selector under a key: its groups'
 * (selectorWords) labels with their relation phrases, joined by " › ",
 * so '> pb-text .link' reads "Text blocks › Link", '> p + p' "Paragraph
 * right after a paragraph", '> pb-text:has(a)' "Text blocks containing a
 * link", '> *:not(pb-image)' "Children except images". A terminal
 * pseudo-element is one more step, its box word (BOX_LABELS) after the
 * host: '.quote::after' reads "Quote › After", the owner's own box
 * '&::before' (no host compound) "Before" alone, the word the
 * page-elements tree gives the box's row. The selector itself is the
 * last resort, unchanged.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string} key
 *   The owning element's key.
 * @param {?string} selector
 *   The canonical relative selector ('' or null reads as the element).
 * @param {object} blockTypes
 *   component uuid → bundle.
 * @param {object} sectionLayouts
 *   section uuid → layout id.
 * @param {?object} markup
 *   The key's `_element.markup` annotation (source.getMarkup(key)), or
 *   null where no annotation is reachable.
 *
 * @return {string}
 */
export const selectorLabel = (
  guide,
  key,
  selector,
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
) => {
  const words = selectorWords(guide, key, selector, blockTypes, sectionLayouts, markup);
  if (words === null) {
    return typeof selector === 'string' ? selector : '';
  }
  const pseudo = parseSelector(selector).pseudo;
  return [
    ...words.map((group) => [group.label, ...group.phrases].join(' ')),
    ...(pseudo === null ? [] : [boxWord(pseudo)]),
  ].join(' › ');
};
