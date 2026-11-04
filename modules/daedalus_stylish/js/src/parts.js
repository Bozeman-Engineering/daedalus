import {
  parseKey,
  declarationForKey,
  bundleForKey,
  flattenElements,
  findPatternElement,
  findInstanceDeclaration,
  tagWord,
  conditionForPseudoClass,
} from './style-guide.js';
import {
  ANCHOR_KIND,
  ATTRIBUTE,
  CLASS_QUALIFIER,
  HAS,
  IS,
  NEXT,
  NOT,
  NTH_POSITIONS,
  POSITIONS,
  SUBSEQUENT,
  TAG,
  TYPE,
  TYPE_BLOCK,
  TYPE_CONTAINER,
  UNIVERSAL,
  parseSelector,
  printQualifier,
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
 * the `_element.markup.instances` annotation on the effective blueprint
 * (stamped server-side from the declared tag vocabulary). daedalus_ui's
 * anatomy payload DOES ship a client copy of that vocabulary
 * (ClientAnatomy: drupalSettings.daedalusUi.anatomy.instances, pattern
 * selector => {label, tags}, read by markup-verify.js for its prefix
 * terms); this module does not consult it, because the instance FACTS
 * (which classes exist, what each rendered as) ride the annotation, and
 * a label is a fact's. Their styles never travel in variants
 * (captureSources excludes them; the server rejects them in variant
 * styles).
 */
export const INSTANCE_PART = /^\.s-[a-z0-9]+$/;

/**
 * The words for a pseudo-element's box, by name: the one vocabulary the
 * page-elements tree's rows (tree-parts.js generated()), the label seam
 * (selectorLabel, so the breadcrumb crumb), the address sentences, the
 * target chip's doorway (write-address.js, subject-model.js) and the
 * code view's box completion (selector-chrome.js) all speak, so a box
 * reads "Before" / "After" / "Marker" wherever it is named. The two
 * generated boxes and the pseudo-elements with a vocabulary of their own
 * (data/pseudo-vocabularies.json) carry translated words; any other the
 * browser knows reads its own name (boxWord()).
 */
export const BOX_LABELS = {
  before: () => Drupal.t('Before'),
  after: () => Drupal.t('After'),
  marker: () => Drupal.t('Marker'),
  placeholder: () => Drupal.t('Placeholder'),
  selection: () => Drupal.t('Selection'),
  'first-letter': () => Drupal.t('First letter'),
  'first-line': () => Drupal.t('First line'),
  backdrop: () => Drupal.t('Backdrop'),
};

/**
 * The word for a pseudo-element's name ('before' → "Before",
 * 'file-selector-button' → "File selector button").
 *
 * @param {string} pseudo
 *   The pseudo-element name, without its colons.
 *
 * @return {string}
 *   The human word for the box.
 */
export const boxWord = (pseudo) =>
  BOX_LABELS[pseudo]?.() ??
  `${pseudo.charAt(0).toUpperCase()}${pseudo.slice(1).replace(/-/g, ' ')}`;

/**
 * Finds an instance row (or nested carrier) in a markup annotation.
 *
 * @param {?object} markup
 *   A key's `_element.markup` annotation (or null).
 * @param {string} sub
 *   The instance-part selector ('.s-a3').
 *
 * @return {?{class: string, tag: string, label: string}}
 *   The instance row, or null when the annotation has none for it.
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
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {?object} declaration
 *   The key's owner declaration (declarationForKey), or null.
 * @param {string} sub
 *   The instance-part selector ('.s-a3').
 *
 * @return {?string}
 *   The covering declaration's label, or null.
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
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string} key
 *   The owning element's key.
 * @param {string} sub
 *   The part selector ('.quote', '.s-a3').
 * @param {object} blockTypes
 *   component uuid → bundle.
 * @param {object} sectionLayouts
 *   section uuid → layout id.
 * @param {?object} markup
 *   The key's `_element.markup` annotation, or null.
 *
 * @return {string}
 *   The part's label, the selector itself as the last resort.
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
        declarationForKey(
          guide,
          parsed,
          bundleForKey(parsed, blockTypes, sectionLayouts),
        ),
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
 * The two kind spellings first (`d-container` "Container", `d-block`
 * "Block": a kind compound matches at any depth, so it words the kind,
 * never an element's minted Section/Container type), then one `d-{bundle}` per
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
 * `d-text` (the bundle's label), "Containers" and "Blocks" for the two
 * kinds; an undeclared or unlabelled type reads as its spelling.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string} type
 *   The type compound's name (`d-text`).
 *
 * @return {string}
 *   The plural label.
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
 * `d-image` (the bundle's label), "container" and "block" for the two
 * kinds; an undeclared or unlabelled type reads as its spelling.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string} type
 *   The type compound's name (`d-image`).
 *
 * @return {string}
 *   The bare singular word.
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
 *
 * @param {string} noun
 *   The bare noun.
 *
 * @return {string}
 *   The noun with its article.
 */
const article = (noun) =>
  /^[aeiou]/i.test(noun)
    ? Drupal.t('an !noun', { '!noun': noun })
    : Drupal.t('a !noun', { '!noun': noun });

/**
 * The block-type declaration a type compound's parts label against, or
 * null for a kind or an undeclared type.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {string} type
 *   The type compound's name.
 *
 * @return {?object}
 *   The block-type declaration, or null.
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
 * A group is a compound with the sibling-combinator compounds that
 * follow it folded in: `> p + p` is ONE group (the second paragraph,
 * worded "right after a paragraph"), `> d-text .link` two ("Text
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
 *   images"; an implied subject "without a link", "that are not last",
 *   "not while hover"), then the `:is` phrases ("any of an image, a
 *   text block"; a member is worded as a `:not()` compound is, a
 *   subject member by its noun phrase, an implied one by its qualifier
 *   phrases: "any of while focus-within, while hover"), then the other
 *   qualifiers of the compound (a
 *   position "that are first" / "that are at :nth-child(2n+1)", a class
 *   "with the .active class", an attribute "with [aria-current="page"]",
 *   a pseudo-class "while hover" by the published condition's label,
 *   else "while :checked"), then the sibling phrase ("right after a
 *   paragraph" for `+`, "after a paragraph" for `~`, the predecessor
 *   worded with its own relations). The owner's anchor compound
 *   (`&:hover > ul`) is "This element".
 * A part after a type (by descent, `>`, or as the sibling of a part
 * under it) labels against that type's own anatomy
 * (guide.blockTypes[bundle].elements); a `:has()` argument's parts
 * against the qualified type's; a `:not()` class is an alternative
 * subject at the qualified compound's own position and labels against
 * the context before it. An INSTANCE class (INSTANCE_PART) in any
 * context never prints itself: it labels as partLabel() does, the
 * annotation row's word, else the covering declaration's (the type's
 * own pattern part under a type, else the site namespace's "Text
 * element"), so a hand-typed or AI-written `> d-formatted-text .s-2kxs`
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
 *   One group per compound of the chain, or null for the element itself.
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
    if (compound.kind === ANCHOR_KIND) {
      const word = Drupal.t('this element');
      return { label: Drupal.t('This element'), noun: word, plural: word };
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
  const nounPhrase = (group) =>
    [article(group.noun), ...group.phrases].join(' ');
  const hasPhrase = (lead, thing, negated) => {
    if (lead === NEXT) {
      return negated
        ? Drupal.t('not right before !thing', { '!thing': thing })
        : Drupal.t('right before !thing', { '!thing': thing });
    }
    if (lead === SUBSEQUENT) {
      return negated
        ? Drupal.t('not before !thing', { '!thing': thing })
        : Drupal.t('before !thing', { '!thing': thing });
    }
    return negated
      ? Drupal.t('without !thing', { '!thing': thing })
      : Drupal.t('containing !thing', { '!thing': thing });
  };
  // The place a position qualifier names, for "that are …".
  const positionPlace = (qualifier) => {
    const places = {
      'first-child': Drupal.t('first'),
      'last-child': Drupal.t('last'),
      'only-child': Drupal.t('an only child'),
      empty: Drupal.t('empty'),
      'first-of-type': Drupal.t('the first of their kind'),
      'last-of-type': Drupal.t('the last of their kind'),
      'only-of-type': Drupal.t('the only ones of their kind'),
    };
    return (
      places[qualifier.name] ??
      Drupal.t('at !spelling', { '!spelling': printQualifier(qualifier) })
    );
  };
  // The phrase of a qualifier that is neither `:has()` nor `:not()`: a
  // position, a class, an attribute or a pseudo-class, after the
  // subject; negated when it stands inside `:not()`.
  const qualifierPhrase = (qualifier, negated) => {
    if (qualifier.name === CLASS_QUALIFIER) {
      const className = `.${qualifier.argument}`;
      return negated
        ? Drupal.t('without the !class class', { '!class': className })
        : Drupal.t('with the !class class', { '!class': className });
    }
    if (qualifier.name === ATTRIBUTE) {
      const attribute = printQualifier(qualifier);
      return negated
        ? Drupal.t('without !attribute', { '!attribute': attribute })
        : Drupal.t('with !attribute', { '!attribute': attribute });
    }
    if (
      POSITIONS.includes(qualifier.name) ||
      NTH_POSITIONS.includes(qualifier.name)
    ) {
      const place = positionPlace(qualifier);
      return negated
        ? Drupal.t('that are not !place', { '!place': place })
        : Drupal.t('that are !place', { '!place': place });
    }
    const condition = conditionForPseudoClass(
      guide?.conditions,
      qualifier.name,
    );
    const state =
      condition === null
        ? printQualifier(qualifier)
        : lower(guide.conditions[condition]?.label ?? condition);
    return negated
      ? Drupal.t('not while !state', { '!state': state })
      : Drupal.t('while !state', { '!state': state });
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
            ? Drupal.t('right after !thing', { '!thing': nounPhrase(previous) })
            : Drupal.t('after !thing', { '!thing': nounPhrase(previous) }),
        );
      }
      list.push(words);
    });
    return list;
  };
  // `negated` is the implied subject's reading: inside `:not()` its
  // qualifier phrases negate, inside `:is()` they read plain.
  const compoundWords = (compound, context, negated = false) => {
    const implied = compound.kind === null;
    const owner =
      compound.kind === TYPE ? typeOwner(guide, compound.name) : context;
    if (implied) {
      // The implied subject inside `:not()`: `:not(:has(x))` reads the
      // negation on each `:has` phrase, `:not(:last-child)`,
      // `:not([x])`, `:not(:hover)` the negated qualifier phrase; inside
      // `:is()` the same phrases plain (`:is(:hover, :has(a))`: "while
      // hover", "containing a link").
      return {
        compound,
        implied,
        owner,
        phrases: compound.qualifiers.map((qualifier) => {
          if (qualifier.name !== HAS) {
            return qualifierPhrase(qualifier, negated);
          }
          const thing = groups(qualifier.argument, owner)
            .map(nounPhrase)
            .join(' › ');
          return hasPhrase(qualifier.argument[0].combinator, thing, negated);
        }),
      };
    }
    const has = compound.qualifiers
      .filter((qualifier) => qualifier.name === HAS)
      .map((qualifier) => {
        const thing = groups(qualifier.argument, owner)
          .map(nounPhrase)
          .join(' › ');
        return hasPhrase(qualifier.argument[0].combinator, thing, false);
      });
    const not = compound.qualifiers
      .filter((qualifier) => qualifier.name === NOT)
      .map((qualifier) => {
        const negated = compoundWords(qualifier.argument, context, true);
        if (negated.implied) {
          return negated.phrases.join(' ');
        }
        return Drupal.t('except !things', {
          '!things': [`${negated.noun}s`, ...negated.phrases].join(' '),
        });
      });
    // An `:is()` member is a predicate compound as a `:not()` argument
    // is: a subject member reads as its noun phrase ("an image", "a text
    // block containing a link"), an implied one as its qualifier phrases
    // ("while hover"), and the alternatives read "any of …".
    const is = compound.qualifiers
      .filter((qualifier) => qualifier.name === IS)
      .map((qualifier) =>
        Drupal.t('any of !members', {
          '!members': qualifier.argument
            .map((member) => {
              const words = compoundWords(member, context);
              return words.implied
                ? words.phrases.join(' ')
                : nounPhrase(words);
            })
            .join(', '),
        }),
      );
    const others = compound.qualifiers
      .filter((qualifier) => ![HAS, NOT, IS].includes(qualifier.name))
      .map((qualifier) => qualifierPhrase(qualifier, false));
    return {
      compound,
      implied,
      owner,
      ...subjectWords(compound, context),
      phrases: [...has, ...not, ...is, ...others],
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
 * so '> d-text .link' reads "Text blocks › Link", '> p + p' "Paragraph
 * right after a paragraph", '> d-text:has(a)' "Text blocks containing a
 * link", '> *:not(d-image)' "Children except images". A terminal
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
 *   The human label, the selector itself as the last resort.
 */
export const selectorLabel = (
  guide,
  key,
  selector,
  blockTypes = {},
  sectionLayouts = {},
  markup = null,
) => {
  const words = selectorWords(
    guide,
    key,
    selector,
    blockTypes,
    sectionLayouts,
    markup,
  );
  if (words === null) {
    return typeof selector === 'string' ? selector : '';
  }
  const pseudo = parseSelector(selector).pseudo;
  return [
    ...words.map((group) => [group.label, ...group.phrases].join(' ')),
    ...(pseudo === null ? [] : [boxWord(pseudo)]),
  ].join(' › ');
};
