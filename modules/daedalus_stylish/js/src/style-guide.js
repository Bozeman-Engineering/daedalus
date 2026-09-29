/**
 * Pure style-guide logic: key parsing and the panel's section projection.
 *
 * Everything here is data-in/data-out so the same code serves both hosts
 * (in-page and satellite) and, later, the widget layer. The style guide
 * payload arrives as drupalSettings.daedalusStylish.guide (see ClientStyleGuide on
 * the PHP side for its shape); values arrive as the style map read from the
 * blueprint mirror. PHP empty arrays JSON-encode as [] rather than {}, so
 * lookups tolerate arrays wherever a map may be empty.
 *
 * The guide carries ROSTERS: every served node (a kind, block type, block
 * plugin, layout, base tag or instance namespace, and each part,
 * children section or declared generated box under one) is the
 * vocabulary roster's resolved answers at that address (PHP:
 * VocabularyRosterBuilder, the one derivation the validator reads too):
 * `grants` (group id => the granted property names),
 * `valueSpaces` (property => the effective constraint block) and, on a
 * root, `axes` (axis => its label, depth stamp, description and owned
 * properties). The reads below (declaredSets,
 * propertyGranted, constraintsFor, resolvedConstraintsFor,
 * propertiesClosed, constraintsAreLaw) are the roster's reads, and
 * declarationForKey() is the client twin of the builder's address
 * resolution; VocabularyRosterTest::testClientTwinEquivalence locks the
 * two through tests/js/vocabulary-cases.mjs. Beside the rosters ride the
 * property definitions (groups), the conditions, the token scale
 * vocabulary (label, shape, prefix, keywords) and the swatch kind labels.
 * The materials themselves (the skin's minted tokens, the published
 * swatches, variants, stencils) are the materials store's, read beside
 * the guide as a roster (materials.js); nothing here lists or mutates a
 * material.
 */

import {
  CLASS_PART,
  HAS,
  NEXT,
  SUBSEQUENT,
  TAG,
  TYPE,
  parseSelector,
  splitKey,
  typeAddress,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

const UUID =
  '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const NAME = '[a-z0-9_]+';

/**
 * Key forms, mirroring StyleMapValidator::parseKey() (PHP is authoritative).
 *
 * Each entry is [pattern, form, kind]: form preserves which grammar rule
 * matched (block:{uuid}:{field} is form "block_field"), kind is the style
 * guide kind the key validates against (that same form styles as a field).
 */
const FORMS = [
  [new RegExp(`^entity:(${UUID})$`), 'entity', 'entity'],
  [new RegExp(`^field:(${UUID}):${NAME}$`), 'field', 'field'],
  [new RegExp(`^container:(${UUID})$`), 'container', 'container'],
  [new RegExp(`^block:(${UUID}):${NAME}$`), 'block_field', 'field'],
  [new RegExp(`^block:(${UUID})$`), 'block', 'block'],
];

/**
 * Parses a selectable/style-map key.
 *
 * The ELEMENT key grammar (a kind qualified by a live uuid); the suffix
 * after the first "::" is the relative selector grammar's (PHP:
 * daedalus_blueprint's RelativeSelector, one suffix grammar for element
 * keys, slot keys and addresses alike; relative-selector.js is its
 * twin), admitted in its canonical spelling only.
 *
 * @return {?{form: string, kind: string, uuid: string, selector: ?string}}
 *   The parsed key, or null when it does not parse. `selector` is the
 *   canonical relative selector after the "::" ('.class' a part, 'p' the
 *   tag tier, '> *' the children, '> pb-text .link' a compound), null
 *   for the element itself. The grammar here is charset only; which
 *   tags, types and parts a selector may name is semantic and enforced
 *   at resolution (declarationForKey walks the declarations; the server
 *   rejects an undeclared tag in every enforcement mode).
 */
export const parseKey = (key) => {
  const split = splitKey(key);
  if (split === null) {
    return null;
  }
  for (const [pattern, form, kind] of FORMS) {
    const match = split.root.match(pattern);
    if (match) {
      return { form, kind, uuid: match[1], selector: split.selector };
    }
  }
  return null;
};

/**
 * The root entity's style map from the blueprint mirror, or {}.
 *
 * The field is cardinality 1, so the serializer's cardinality wrapping
 * delivers the map bare (an empty map serializes to no field at all).
 */
export const styleMapOf = (blueprint) =>
  blueprint?.fields?.daedalus_stylish_styles ?? {};

/**
 * Whether a layout-tree node is a nested-section (container) node.
 *
 * The serializer's one discriminator (containers all the way down): a child
 * entry carrying `layout_id` IS a nested section node — layout_id, uuid,
 * label, children (containers) or regions (multi-region layouts),
 * recursive — identical in shape to a top-level section. Everything else
 * in a child list is a component node (component nodes never carry any of
 * these keys).
 */
export const isSectionNode = (node) =>
  node !== null &&
  typeof node === 'object' &&
  ((typeof node.layout_id === 'string' && node.layout_id !== '') ||
    (node.regions !== null &&
      typeof node.regions === 'object' &&
      node.regions !== undefined) ||
    Array.isArray(node.children));

/**
 * A section node's ordered child nodes, both serializer shapes.
 *
 * The ONE accessor for "what does this section hold, in order": container
 * sections serialize an ordered `children` list; multi-region sections
 * keep the `regions` map, whose lists concatenate in stored region order.
 * Callers that need the CONTAINER flow (the positional slot grammar) check
 * Array.isArray(node.children) themselves — a multi-region section's
 * children are reachable, but not positionally addressable.
 *
 * @return {Array}
 *   The ordered child nodes ([] when none).
 */
export const sectionChildNodes = (section) => {
  if (Array.isArray(section?.children)) {
    return section.children;
  }
  const children = [];
  Object.values(section?.regions ?? {}).forEach((nodes) => {
    if (Array.isArray(nodes)) {
      children.push(...nodes);
    }
  });
  return children;
};

/**
 * Flattens the blueprint's layout to component uuid → block discriminator.
 *
 * Inline components map to their block type; non-inline (plugin)
 * components map to 'plugin:{base id}' (the :derivative suffix stripped —
 * derivatives share one template, so one declaration covers them), the
 * namespaced form declarationForKey() and captureScope() route on. A
 * component carrying neither maps to null — it styles against the
 * universal block kind, exactly as the server validator's fallback does.
 * Nested sections are walked so their components resolve at every depth.
 */
export const componentBlockTypes = (blueprint) => {
  const types = {};
  const walkSections = (sections) => {
    (Array.isArray(sections) ? sections : []).forEach((section) => {
      sectionChildNodes(section).forEach((node) => {
        if (isSectionNode(node)) {
          walkSections([node]);
          return;
        }
        if (node?.uuid) {
          types[node.uuid] =
            node.block_type ??
            (typeof node.plugin_id === 'string' && node.plugin_id !== ''
              ? `plugin:${node.plugin_id.split(':')[0]}`
              : null);
        }
      });
    });
  };
  walkSections(blueprint?.layout_builder__layout);
  return types;
};

/**
 * Flattens the blueprint's layout to section uuid → layout plugin id.
 *
 * The section counterpart of componentBlockTypes(): the layout plugin id is
 * to sections what the bundle is to inline blocks, and per-layout guide
 * declarations (guide.layouts) key by it. Nested sections register at every
 * depth — one resolution rule: a section's declaration IS its layout's
 * declaration, top-level or nested.
 */
export const sectionLayouts = (blueprint) => {
  const layouts = {};
  const walkSections = (sections) => {
    (Array.isArray(sections) ? sections : []).forEach((section) => {
      if (section?.uuid) {
        layouts[section.uuid] = section.layout_id ?? null;
      }
      sectionChildNodes(section).forEach((node) => {
        if (isSectionNode(node)) {
          walkSections([node]);
        }
      });
    });
  };
  walkSections(blueprint?.layout_builder__layout);
  return layouts;
};

/**
 * The blueprint's layout pared to its structural bones.
 *
 * The staged-subtree shape composition capture walks (variant-scope.js's
 * subtreeSlotTable) and the popout leader broadcasts: sections carry uuid,
 * layout_id and their ordered child nodes — a container section's
 * `children` list, a multi-region section's `regions` map, each shape
 * preserved so the positional slot grammar can tell them apart. A child
 * node is either a nested section (pared recursively, same shape) or a
 * component carrying uuid and block_type (null for non-inline placements,
 * the walk's transparency contract). Every node carries the naming
 * spine's `type` and `name` words. Sections and components keep their
 * read-only `stencil` provenance stamp when they carry one (the serializer's
 * placed-root annotation) — the offering filter for stencil-owned
 * variants reads it (variant-scope.js stencilProvenance). Field names are
 * the same as the raw blueprint's, so walkers accept either shape; paring
 * exists so the satellite broadcast ships structure, not embedded content.
 *
 * @param {?object} blueprint
 *   The blueprint mirror of the root entity.
 *
 * @return {Array}
 *   The pared sections tree.
 */
export const layoutTree = (blueprint) => {
  const stampOf = (node) =>
    typeof node?.stencil === 'string' && node.stencil !== ''
      ? { stencil: node.stencil }
      : {};
  // The element-naming spine's two words (the annotator's minted
  // `_element.type` and `_element.name`: "Section" / "Welcome", "Text" /
  // its content excerpt, an unrenamed element's name being its type word)
  // ride the pared node: the code view's page tree titles its rows with
  // them, and the satellite reads them off this broadcast alone.
  const typeOf = (node) => node?._element?.type ?? null;
  const nameOf = (node) => node?._element?.name ?? null;
  const pareNode = (node) =>
    isSectionNode(node)
      ? pareSection(node)
      : {
          uuid: node?.uuid ?? null,
          block_type: node?.block_type ?? null,
          type: typeOf(node),
          name: nameOf(node),
          ...stampOf(node),
        };
  const pareSection = (section) => {
    const pared = {
      uuid: section?.uuid ?? null,
      layout_id: section?.layout_id ?? null,
      type: typeOf(section),
      name: nameOf(section),
      ...stampOf(section),
    };
    if (Array.isArray(section?.children)) {
      pared.children = section.children.map(pareNode);
    } else {
      pared.regions = Object.fromEntries(
        Object.entries(section?.regions ?? {}).map(([name, nodes]) => [
          name,
          (Array.isArray(nodes) ? nodes : []).map(pareNode),
        ]),
      );
    }
    return pared;
  };
  const sections = blueprint?.layout_builder__layout;
  return (Array.isArray(sections) ? sections : []).map(pareSection);
};

/**
 * The bundle-like discriminator of a parsed key, from the uuid tables.
 *
 * Blocks resolve through the component → discriminator table (an inline
 * block type, or 'plugin:{base id}' for plugin placements), sections
 * through the section → layout id table; every other kind has no bundle.
 * This is the one lookup every per-bundle resolution (declarations, parts,
 * controls, variant scopes) shares.
 *
 * @return {?string}
 *   The inline block type, 'plugin:{base id}', or layout plugin id, or
 *   null (universal kind).
 */
export const bundleForKey = (parsed, blockTypes = {}, layouts = {}) => {
  if (parsed?.kind === 'block') {
    return blockTypes?.[parsed.uuid] ?? null;
  }
  if (parsed?.kind === 'container') {
    return layouts?.[parsed.uuid] ?? null;
  }
  return null;
};

/**
 * Finds a part declaration anywhere in a declaration's elements tree.
 *
 * The client twin of StyleGuide::findElement() (PHP is authoritative):
 * depth-first, first match wins; guide-build validation guarantees a class
 * is unique within a declaration tree, so the first match is the only match.
 *
 * @param {?object} declaration
 *   A declaration whose elements: tree to search (a whole kind/block
 *   type/layout declaration, or any part of one).
 * @param {string} selector
 *   The part's selector key, e.g. '.card__header'.
 *
 * @return {?object}
 *   The part declaration, or null when no part in the tree carries the
 *   selector.
 */
export const findElement = (declaration, selector) => {
  const elements = declaration?.elements;
  if (elements === null || typeof elements !== 'object') {
    return null;
  }
  for (const [key, part] of Object.entries(elements)) {
    if (part === null || typeof part !== 'object') {
      continue;
    }
    if (key === selector) {
      return part;
    }
    const found = findElement(part, selector);
    if (found !== null) {
      return found;
    }
  }
  return null;
};

/**
 * The shape of a pattern part key: a class-selector prefix plus '*'.
 *
 * The client twin of PageBuilderDeclarations::PART_PATTERN: a pattern key
 * declares the whole class namespace its prefix matches — an owner's own
 * pattern part in an elements tree, or a site instance namespace in the
 * guide payload's `instances` lane.
 */
export const PART_PATTERN = /^\.[A-Za-z0-9_-]*\*$/;

/**
 * Finds the pattern part declaration matching a concrete selector.
 *
 * The client twin of PageBuilderDeclarations::findPatternElement() (PHP is
 * authoritative): exact lookups stay findElement()'s, and callers that
 * honor patterns compose `findElement(...) ?? findPatternElement(...)`, so
 * an exact declaration always wins. Depth-first flatten order; the first
 * matching pattern is the match, and the selector must EXTEND the prefix
 * (the prefix alone is not an instance of the namespace).
 *
 * @return {?object}
 *   The pattern part's declaration, or null when the selector is not a
 *   single class or no pattern's prefix matches it.
 */
export const findPatternElement = (declaration, selector) => {
  if (typeof selector !== 'string' || !/^\.[A-Za-z0-9_-]+$/.test(selector)) {
    return null;
  }
  for (const [key, part] of Object.entries(flattenElements(declaration))) {
    if (!PART_PATTERN.test(key)) {
      continue;
    }
    const prefix = key.slice(0, -1);
    if (selector.startsWith(prefix) && selector.length > prefix.length) {
      return part;
    }
  }
  return null;
};

/**
 * Finds the instance-namespace declaration matching a concrete selector.
 *
 * The client twin of PageBuilderDeclarations::findInstanceDeclaration()
 * (PHP is authoritative), over the guide payload's `instances` lane
 * instead of an elements tree: a namespace ('.s-*') covers every concrete
 * single-class selector extending its prefix, and the selector must
 * EXTEND the prefix. The last step of the one part-resolution rule (exact
 * part -> owner pattern part -> site instance namespaces), so an owner's
 * own declaration always wins over the site vocabulary.
 *
 * @param {?object} instances
 *   The guide payload's instances map (guide.instances).
 * @param {string} selector
 *   A concrete part selector, e.g. '.s-a3'.
 *
 * @return {?object}
 *   The namespace's declaration, or null when the selector is not a
 *   single class or no declared namespace covers it.
 */
export const findInstanceDeclaration = (instances, selector) => {
  if (typeof selector !== 'string' || !/^\.[A-Za-z0-9_-]+$/.test(selector)) {
    return null;
  }
  for (const [pattern, declaration] of Object.entries(
    isPlainObject(instances) ? instances : {},
  )) {
    if (!PART_PATTERN.test(pattern) || !isPlainObject(declaration)) {
      continue;
    }
    const prefix = pattern.slice(0, -1);
    if (selector.startsWith(prefix) && selector.length > prefix.length) {
      return declaration;
    }
  }
  return null;
};

/**
 * Finds the base declaration a tag-tier tag resolves against.
 *
 * The client twin of StyleGuide::baseTagElement() (PHP is authoritative),
 * the tag half of sub-element resolution: a '{key}::p' tag-tier key
 * styles every one of the element's text elements of that tag (the
 * middle tier of the three-tier text model: skin base:{tag} -> element
 * tag tier -> minted part), and its vocabulary is the site's base section
 * (guide.base), the ONE declaration of a prose tag's styleability — the
 * same groups the skin's base:{tag} defaults validate against. Instance
 * namespaces declare identity only and are never consulted for a tag.
 *
 * @param {?object} base
 *   The guide payload's base map (guide.base), tag => declaration.
 * @param {string} tag
 *   A tag name, e.g. 'p'.
 *
 * @return {?object}
 *   The tag's base declaration, or null when the site does not declare
 *   the tag (no tag tier exists for it).
 */
export const findBaseTagDeclaration = (base, tag) => {
  if (
    !isPlainObject(base) ||
    !Object.hasOwn(base, tag) ||
    !isPlainObject(base[tag])
  ) {
    return null;
  }
  return base[tag];
};

/**
 * The tag-tier vocabulary: every tag the site's base section declares.
 *
 * The client twin of StyleGuide::baseTags(): the styleability read, in
 * authoring order. The code view's tag-row offer and every surface
 * enumerating legal tag-tier keys read from here; instanceTags() is the
 * identity read (labels).
 *
 * @param {?object} base
 *   The guide payload's base map (guide.base).
 *
 * @return {string[]}
 *   Tag names; [] when the site declares no base section.
 */
export const baseTags = (base) => Object.keys(isPlainObject(base) ? base : {});

/**
 * The declared identity vocabulary: every instance namespace's tags.
 *
 * The client twin of StyleGuide::instanceTags(): the merged {tag: label}
 * map across the guide's instance namespaces, in declaration order (first
 * declaration wins a duplicate tag). This is IDENTITY — the tags the
 * minter mints, with the labels the annotator shows on instance rows —
 * not the tag tier's vocabulary (baseTags() is): a tag may carry
 * identity without being styleable, or be styleable without ever
 * minting (links). A tag TIER's word is tagWord()'s: the base word wins
 * over the identity word for the same tag.
 *
 * @param {?object} instances
 *   The guide payload's instances map (guide.instances).
 *
 * @return {Object<string, string>}
 *   Tag names mapped to their declared labels; {} when no namespace is
 *   declared.
 */
export const instanceTags = (instances) => {
  const tags = {};
  Object.values(isPlainObject(instances) ? instances : {}).forEach(
    (declaration) => {
      if (!isPlainObject(declaration) || !isPlainObject(declaration.tags)) {
        return;
      }
      Object.entries(declaration.tags).forEach(([tag, label]) => {
        if (!(tag in tags)) {
          tags[tag] = String(label);
        }
      });
    },
  );
  return tags;
};

/**
 * The one word for a tag, as prose spells it ("Link", "Paragraph").
 *
 * The client read of StyleGuide::baseTagLabels(): the guide's tagLabels
 * word (every closed base tag, the site's declared `label:` applied)
 * wins, because the tag tier resolves to the base declaration for every
 * owner; a tag outside the closed set reads the instance lane's identity
 * word (instanceTags()), and a tag no lane words spells itself. Every
 * surface that speaks a tag tier in a sentence (the write address, the
 * selector labels, the code view's tag offer) reads here, so a tag never
 * has two words on one panel.
 *
 * @param {?object} guide
 *   The style guide payload.
 * @param {string} tag
 *   The tag name.
 *
 * @return {string}
 *   The word, capitalized as declared.
 */
export const tagWord = (guide, tag) => {
  const labels = guide?.tagLabels;
  if (isPlainObject(labels) && Object.hasOwn(labels, tag)) {
    return String(labels[tag]);
  }
  const identity = instanceTags(guide?.instances);
  return Object.hasOwn(identity, tag) ? identity[tag] : tag;
};

/**
 * Flattens a declaration's elements tree into one part-per-selector map.
 *
 * The client twin of StyleGuide::flattenElements(): depth-first, parents
 * before children; each part keeps its declaration as authored, nested
 * elements and contains slots included.
 *
 * @return {object}
 *   Part declarations keyed by selector, every depth of the tree.
 */
export const flattenElements = (declaration) => {
  const flat = {};
  const elements = declaration?.elements;
  if (elements === null || typeof elements !== 'object') {
    return flat;
  }
  for (const [key, part] of Object.entries(elements)) {
    if (part === null || typeof part !== 'object') {
      continue;
    }
    if (!(key in flat)) {
      flat[key] = part;
    }
    for (const [nestedKey, nestedPart] of Object.entries(
      flattenElements(part),
    )) {
      if (!(nestedKey in flat)) {
        flat[nestedKey] = nestedPart;
      }
    }
  }
  return flat;
};

/**
 * Resolves the served node (the roster) for a parsed key.
 *
 * The client twin of the roster builder's address resolution (PHP:
 * VocabularyRosterBuilder::resolve(), locked by the vocabulary cases):
 * the key plus its bundle name an address, and the node served at that
 * address is the roster's answers. Block keys resolve to their block
 * type's node when the type is known and declared, or, for a plugin
 * placement (the 'plugin:{base id}' discriminator), to the plugin's
 * guide.blockPlugins node; section keys to their layout's; otherwise the
 * universal kind (which is itself already the effective fallback).
 * `bundle` is the kind-appropriate discriminator (bundleForKey()): an
 * inline block type or 'plugin:{base id}' for blocks, a layout plugin id
 * for sections. Sub-element keys drill into the parent node's elements
 * tree at any depth (keys stay flat under declaration-only nesting;
 * findElement() does the search), then through the one resolution rule's
 * fallbacks, the builder's part rule: an owner pattern part covers its
 * class namespace, and the guide's site instance namespaces
 * (guide.instances) cover minted identity classes on ANY owner, so
 * composed carriers at the block key and open-render carriers at field
 * keys resolve identically. An undeclared part resolves to {} (a roster
 * that grants nothing): stylable grammar-only on open sites, so the panel
 * shows its values but no declared sections. A dotless sub is a TAG-TIER key ('{key}::p'): it
 * resolves through the site's base section ONLY (guide.base, the one
 * declaration of a prose tag's styleability) — owner exact/pattern parts
 * and instance namespaces are never consulted for tags (classes only,
 * the PHP rule) — and an undeclared tag resolves to {} (the server
 * rejects the write in every enforcement mode; the client resolver just
 * has nothing to say about it). A terminal pseudo-element (`&::before`,
 * `.quote::after`, `> pb-text::before`) resolves to its host's
 * generated box (pseudoNode(): the served declared box, else the
 * derivation from the host node), once the chain has landed.
 */
export const declarationForKey = (guide, parsed, bundle) =>
  landingForKey(guide, parsed, bundle) ?? {};

/**
 * The declaration the roster walk lands on, or null when a compound of
 * the key's selector lands nowhere.
 *
 * declarationForKey's honest half: the same walk, but an undeclared
 * landing answers null instead of the grammar-only `{}`, so a surface
 * that must know whether an address lands at all can ask without
 * mistaking a declared node that happens to grant nothing for an
 * undeclared one. The root itself always lands (the kind is the
 * fallback the server resolves to as well), and so does a
 * pseudo-element whose host lands (the twin of the builder's walk,
 * whose node is the derivation even when no section declares the box):
 * whether a landing is DECLARED is declaredForKey()'s question, the one
 * the write-address switcher's mintable addresses ask on a strict site.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {{kind: string, selector: ?string}} parsed
 *   A parsed key (parseKey).
 * @param {?string} bundle
 *   The kind-appropriate discriminator (bundleForKey).
 *
 * @return {?object}
 *   The landing declaration, or null.
 */
export const landingForKey = (guide, parsed, bundle) => {
  const { host, pseudo } = hostForKey(guide, parsed, bundle);
  if (host === null || pseudo === null) {
    return host;
  }
  return pseudoNode(guide, host, pseudo);
};

/**
 * Whether a parsed key names a DECLARED place (PHP: VocabularyRoster::
 * declared()).
 *
 * A root always does; a selector key when every compound lands
 * (landingForKey) and, with a terminal pseudo-element, when the host
 * node's served `pseudo` section carries the box. A derived box still
 * LANDS (landingForKey answers its derivation, since existence is
 * universal) but is not declared: the server refuses a write there on a
 * strict site (STYLE_PSEUDO_NOT_DECLARED), so the surfaces that gate a
 * mintable address on the closed world ask here, not landingForKey.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {{kind: string, selector: ?string}} parsed
 *   A parsed key (parseKey).
 * @param {?string} bundle
 *   The kind-appropriate discriminator (bundleForKey).
 *
 * @return {boolean}
 */
export const declaredForKey = (guide, parsed, bundle) => {
  const { host, pseudo } = hostForKey(guide, parsed, bundle);
  if (host === null) {
    return false;
  }
  return pseudo === null || declaredPseudo(host, pseudo) !== null;
};

/**
 * The roster walk's chain landing and the selector's terminal pseudo.
 *
 * PHP: VocabularyRosterBuilder::walk() before its pseudo step: the
 * selector's compounds resolve left to right, each landing on the
 * declaration it names; a class part by the one part rule against the
 * owner (the element, or the last type compound's declaration), a tag
 * by the base section, a type by its own root declaration, `*` by the
 * current landing's `children` section; a sibling compound resolves
 * where its predecessor did, and a compound's qualifiers are judged
 * (walkChain) without moving the landing. An undeclared compound
 * anywhere resolves the host to null (the box is never reached: the
 * host is corrected first). The anchor (`&::before`) has no compounds,
 * so its host is the root itself.
 *
 * @return {{host: ?object, pseudo: ?string}}
 */
const hostForKey = (guide, parsed, bundle) => {
  const kinds = guide?.kinds ?? {};
  let declaration;
  if (parsed.kind === 'block') {
    declaration =
      typeof bundle === 'string' && bundle.startsWith('plugin:')
        ? guide?.blockPlugins?.[bundle.slice('plugin:'.length)] ||
          kinds.block ||
          {}
        : (bundle && guide?.blockTypes?.[bundle]) || kinds.block || {};
  } else if (parsed.kind === 'container') {
    declaration = (bundle && guide?.layouts?.[bundle]) || kinds.container || {};
  } else {
    declaration = kinds[parsed.kind] || {};
  }
  const selector = parseSelector(parsed.selector);
  return {
    host: walkChain(guide, selector?.compounds ?? [], declaration, declaration, declaration),
    pseudo: selector?.pseudo ?? null,
  };
};

/**
 * The served node a pseudo-element lands on: its host's generated box.
 *
 * The client twin of VocabularyRosterBuilder::pseudoNode() over served
 * answers: the host's served `pseudo.before` / `pseudo.after` node when
 * the host declares the box (ClientStyleGuide serves a declared box as
 * the roster at its address); else the derivation, a node granting
 * exactly what the host grants (its value spaces with it: the same
 * groups, allowlists and constraints resolve to the same answers) plus
 * the pseudo-only groups (pseudoGroups()) in full, last, each of their
 * properties taking the site's authored constraint default when one
 * names it (the builder's own rule for a group declared without
 * constraints). Nothing is re-derived from authored declarations: the
 * host's grants are the roster's, and the box's are the host's plus the
 * existence switch. VocabularyRosterTest::testClientTwinEquivalence
 * locks both branches.
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object} host
 *   The served node the selector's chain landed on.
 * @param {string} pseudo
 *   The pseudo-element's name ('before', 'after').
 *
 * @return {object}
 *   The box's served node.
 */
export const pseudoNode = (guide, host, pseudo) => {
  const declared = declaredPseudo(host, pseudo);
  if (declared !== null) {
    return declared;
  }
  const grants = grantsOf(host);
  const valueSpaces = isPlainObject(host?.valueSpaces) ? { ...host.valueSpaces } : {};
  pseudoGroups(guide).forEach((id) => {
    if (Object.hasOwn(grants, id)) {
      return;
    }
    const names = Object.keys(guide.groups[id].properties ?? {});
    if (!names.length) {
      return;
    }
    grants[id] = names;
    names.forEach((property) => {
      const block = guide?.constraintDefaults?.[property];
      if (isPlainObject(block) && Object.keys(block).length) {
        valueSpaces[property] = block;
      }
    });
  });
  return { grants, valueSpaces };
};

/**
 * The host's served node for a declared pseudo-element, or null (derived).
 */
const declaredPseudo = (host, pseudo) => {
  const section = host?.pseudo;
  return isPlainObject(section) && isPlainObject(section[pseudo]) ? section[pseudo] : null;
};

/**
 * The group ids granted only at a generated box, in panel order.
 *
 * The payload's `pseudo: true` groups (PropertyGroupPluginManager::
 * getPseudoGroups(): generated_content, the existence switch), the fact
 * a derived box's grant is built from.
 *
 * @param {?object} guide
 *   The style guide payload.
 *
 * @return {string[]}
 */
export const pseudoGroups = (guide) =>
  Object.keys(isPlainObject(guide?.groups) ? guide.groups : {}).filter(
    (id) => guide.groups[id]?.pseudo === true,
  );

/**
 * The declaration a compound's subject lands on, or null when undeclared
 * (PHP: VocabularyRosterBuilder::land()).
 */
const land = (guide, compound, node, owner) => {
  if (compound.kind === CLASS_PART) {
    return (
      findElement(owner, compound.name) ??
      findPatternElement(owner, compound.name) ??
      findInstanceDeclaration(guide?.instances, compound.name)
    );
  }
  if (compound.kind === TAG) {
    return findBaseTagDeclaration(guide?.base, compound.name);
  }
  if (compound.kind === TYPE) {
    return declarationForAddress(guide, typeAddress(compound.name));
  }
  const children = node?.children;
  return children !== null &&
    typeof children === 'object' &&
    !Array.isArray(children)
    ? children
    : null;
};

/**
 * Walks one chain of compounds (the selector's, or a `:has()` argument)
 * to its last landing, or null when a compound anywhere lands nowhere
 * (PHP: VocabularyRosterBuilder::walkChain()).
 *
 * @param {object} guide
 *   The style guide payload.
 * @param {object[]} compounds
 *   The chain.
 * @param {object} node
 *   The declaration the first compound resolves in.
 * @param {object} owner
 *   The declaration a class part resolves against.
 * @param {object} previous
 *   The declaration a leading sibling compound resolves in (the context
 *   of the compound the chain qualifies).
 *
 * @return {?object}
 */
const walkChain = (guide, compounds, node, owner, previous) => {
  let current = node;
  let currentOwner = owner;
  let before = previous;
  for (const compound of compounds) {
    if ([NEXT, SUBSEQUENT].includes(compound.combinator)) {
      current = before;
    }
    const landing = land(guide, compound, current, currentOwner);
    if (landing === null) {
      return null;
    }
    const argumentOwner = compound.kind === TYPE ? landing : currentOwner;
    if (!judgeQualifiers(guide, compound.qualifiers, landing, argumentOwner, current, currentOwner)) {
      return null;
    }
    before = current;
    current = landing;
    if (compound.kind === TYPE) {
      currentOwner = landing;
    }
  }
  return current;
};

/**
 * Whether every compound in a compound's qualifiers lands (PHP:
 * VocabularyRosterBuilder::judgeQualifiers()): a `:has()` argument walked
 * from the landing, its parts resolving against the argument owner and a
 * sibling lead in the compound's context; a `:not()` compound judged
 * where the qualified compound stands, an implied subject judging its
 * qualifiers from the qualified compound's landing.
 */
const judgeQualifiers = (guide, qualifiers, landing, argumentOwner, node, owner) =>
  qualifiers.every((qualifier) => {
    if (qualifier.name === HAS) {
      return walkChain(guide, qualifier.argument, landing, argumentOwner, node) !== null;
    }
    const negated = qualifier.argument;
    if (negated.kind === null) {
      return judgeQualifiers(guide, negated.qualifiers, landing, argumentOwner, node, owner);
    }
    const negatedLanding = land(guide, negated, node, owner);
    return (
      negatedLanding !== null &&
      judgeQualifiers(
        guide,
        negated.qualifiers,
        negatedLanding,
        negated.kind === TYPE ? negatedLanding : owner,
        node,
        owner,
      )
    );
  });

/**
 * The served root declaration a printed root address names, or null.
 *
 * The type compounds' landing: `block_type:{bundle}` the bundle's node
 * (the universal block kind when undeclared: the server refuses an
 * unknown type on a strict site, and an open site is grammar-only
 * anyway), `kind:{kind}` the kind's node.
 */
const declarationForAddress = (guide, address) => {
  if (typeof address !== 'string') {
    return null;
  }
  const kinds = guide?.kinds ?? {};
  if (address.startsWith('block_type:')) {
    const bundle = address.slice('block_type:'.length);
    return guide?.blockTypes?.[bundle] ?? null;
  }
  if (address.startsWith('kind:')) {
    return kinds[address.slice('kind:'.length)] ?? null;
  }
  return null;
};

/**
 * Whether a parsed sub-element key targets an INSTANCE part.
 *
 * The one part-resolution rule (exact part -> owner pattern part -> site
 * instance namespaces) asked as a routing question: true only when the
 * owner's own declarations say nothing about the sub (no exact part, no
 * owner pattern part) and a declared instance namespace covers it — the
 * same precedence declarationForKey() resolves with, so the answer and
 * the resolved declaration always agree. Shell parts (either owner rung)
 * answer false, as do root keys and children pseudo-keys: an instance
 * part is CONTENT the user authored, and consumers route on that fact
 * (the basic panel targets a drilled instance part itself, while a
 * drilled shell part keeps its owner's lifted surface). Tag-tier keys
 * ('{key}::p', a dotless sub) also answer false: the tag tier is an
 * advanced-surface concept, not a selectable part, and the class-shape
 * gate in findInstanceDeclaration() enforces that here.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?object} parsed
 *   A parsed key (parseKey()).
 * @param {?string} bundle
 *   The owner's discriminator (bundleForKey()).
 *
 * @return {boolean}
 *   True when the key's sub resolves through the instances lane.
 */
export const isInstancePartKey = (guide, parsed, bundle) => {
  const selector = parseSelector(parsed?.selector);
  const compounds = selector?.compounds;
  // A box of an instance part (`.s-a3::before`) is a rule of its own,
  // not the part (relative-selector.js isPart() is false with a pseudo).
  if (selector?.pseudo || compounds?.length !== 1 || compounds[0].kind !== CLASS_PART) {
    return false;
  }
  const sub = compounds[0].name;
  const owner = declarationForKey(guide, { ...parsed, selector: null }, bundle);
  return (
    findElement(owner, sub) === null &&
    findPatternElement(owner, sub) === null &&
    findInstanceDeclaration(guide?.instances, sub) !== null
  );
};

export const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * A token value's display form: the stored string, or a condition-keyed
 * map's 'default' entry. Display only — the browser resolves the actual
 * var() reference, conditions included; this is what pickers and
 * completion details print beside the token name.
 *
 * @return {string}
 */
export const tokenDisplayValue = (value) => {
  if (isPlainObject(value)) {
    return value.default === undefined || value.default === null
      ? ''
      : String(value.default);
  }
  return value === undefined || value === null ? '' : String(value);
};

/**
 * A token value's scheme-paired entry beside the default, or null.
 *
 * tokenDisplayValue()'s scheme sibling, for faces that show a
 * scheme-paired stop as a split chip (default half / scheme half): a
 * condition-keyed value answers its entry under the first scheme-axis
 * condition (guide.conditions declaration order) that carries one. Which
 * names ride the scheme axis is the guide's to say (a light-default site
 * declares 'dark'); compound keys like 'narrow+dark' deliberately do NOT
 * count for face duty — only single scheme-axis condition names are
 * consulted, because a compound entry applies under a width/motion part
 * too, so painting it as "the other scheme" would lie. Display only, same
 * rule as tokenDisplayValue(): never a var() resolution.
 *
 * @param {object} guide
 *   The style guide payload (conditions: name -> {label, wrapper, axis}).
 * @param {*} value
 *   A token/swatch value: a plain string or a condition-keyed map.
 *
 * @return {?string}
 *   The scheme entry's display form, or null when the value is a plain
 *   string or carries no single scheme-axis entry.
 */
export const schemeDisplayValue = (guide, value) => {
  if (!isPlainObject(value)) {
    return null;
  }
  const conditions = guide?.conditions;
  for (const [name, definition] of Object.entries(
    isPlainObject(conditions) ? conditions : {},
  )) {
    if (definition?.axis !== 'scheme') {
      continue;
    }
    const entry = value[name];
    if (entry !== undefined && entry !== null && String(entry) !== '') {
      return String(entry);
    }
  }
  return null;
};

/**
 * The site's scheme condition name: where per-scheme authoring writes.
 *
 * The first scheme-axis condition in guide.conditions declaration order
 * (a light-default site declares 'dark'), or null when the vocabulary has
 * no scheme axis. The authoring twin of schemeDisplayValue()'s read rule:
 * the guide alone decides which names ride the scheme axis, and compound
 * keys never count — a pair author writes exactly one single-name entry.
 *
 * @param {object} guide
 *   The style guide payload (conditions: name -> {label, wrapper, axis}).
 *
 * @return {?string}
 */
export const schemeCondition = (guide) => {
  const conditions = guide?.conditions;
  for (const [name, definition] of Object.entries(
    isPlainObject(conditions) ? conditions : {},
  )) {
    if (definition?.axis === 'scheme') {
      return name;
    }
  }
  return null;
};

/**
 * The reserved swatch namespace's variable prefix (--swatch-{name}).
 *
 * The client twin of StyleGuide::SWATCH_PREFIX (PHP is authoritative):
 * validateTokenScales refuses scale prefixes equal to or extending it, so
 * a variable inside it is a swatch reference and nothing else.
 */
const SWATCH_VARIABLE_PREFIX = '--swatch-';

/**
 * Resolves a CSS custom property back to its swatch machine name.
 *
 * The client twin of StyleGuide::swatchReference() (PHP is authoritative):
 * prefix match against the reserved namespace; the bare prefix is not a
 * swatch (no name tail). A variable outside the namespace is NOT a swatch
 * reference. A NAMESPACE question only, for the lint's server-mirroring
 * rule (a var() inside the reserved namespace must name a rostered swatch);
 * whether a value IS a swatch is the value grammar's answer over the
 * materials roster (materials.js materialOf).
 *
 * @param {string} variable
 *   A custom property name, leading dashes included ('--swatch-brand_red').
 *
 * @return {?string}
 *   The swatch machine name, or null.
 */
export const swatchReference = (variable) =>
  typeof variable === 'string' &&
  variable.startsWith(SWATCH_VARIABLE_PREFIX) &&
  variable.length > SWATCH_VARIABLE_PREFIX.length
    ? variable.slice(SWATCH_VARIABLE_PREFIX.length)
    : null;

/**
 * The reserved motion namespace's keyframes-name prefix (motion-{name}).
 *
 * The client twin of StyleGuide::ANIMATION_PREFIX (PHP is authoritative):
 * a keyframes ident inside it is a site animation reference and nothing
 * else; the prefix is never an animation keyword and never a theme's
 * keyframes name, which is what partitions the two vocabularies.
 */
const ANIMATION_NAME_PREFIX = 'motion-';

/**
 * Resolves a keyframes name back to its animation machine name.
 *
 * The client twin of StyleGuide::animationReference() (PHP is
 * authoritative): prefix match against the reserved namespace; the bare
 * prefix is not an animation (no name tail). An ident outside the
 * namespace is NOT an animation reference: it is a theme's own keyframes
 * name (or an animation keyword the property grammar owns). A NAMESPACE
 * question only, swatchReference's twin for the lint's published-animation
 * rule; whether a name IS a rostered animation is the value grammar's
 * answer in the animation slot (materials.js animationOf).
 *
 * @param {string} ident
 *   A keyframes name as written ('motion-fade_in').
 *
 * @return {?string}
 *   The animation machine name, or null.
 */
export const animationReference = (ident) =>
  typeof ident === 'string' &&
  ident.startsWith(ANIMATION_NAME_PREFIX) &&
  ident.length > ANIMATION_NAME_PREFIX.length
    ? ident.slice(ANIMATION_NAME_PREFIX.length)
    : null;

/**
 * Resolves a CSS custom property back to its scale and token name.
 *
 * The client twin of StyleGuide::tokenReference() (PHP is authoritative):
 * prefix match against the declared scales, whose effective prefixes the
 * grammar guarantees unique and non-extending — at most one scale matches.
 * A variable outside every declared prefix is NOT a token reference (the
 * token spine owns exactly its declared namespaces; theme and chrome
 * custom properties are none of its business). A NAMESPACE question only,
 * swatchReference's twin for the lint's minted-token rule; what a value IS
 * resolves through the materials roster (materials.js).
 *
 * @param {string} variable
 *   A custom property name, leading dashes included ('--space-4').
 * @param {?object} tokenScales
 *   The guide payload's tokenScales (prefixes served resolved).
 *
 * @return {?{scale: string, name: string}}
 */
export const tokenReference = (variable, tokenScales) => {
  if (typeof variable !== 'string') {
    return null;
  }
  for (const [scale, declaration] of Object.entries(
    isPlainObject(tokenScales) ? tokenScales : {},
  )) {
    if (!isPlainObject(declaration) || typeof declaration.prefix !== 'string') {
      continue;
    }
    const prefix = `--${declaration.prefix}-`;
    if (variable.startsWith(prefix) && variable.length > prefix.length) {
      return { scale, name: variable.slice(prefix.length) };
    }
  }
  return null;
};

/**
 * The served node's grants: group id => the granted property names.
 *
 * The roster's grants() (PHP: VocabularyRoster::grants(), narrowed grants
 * resolved to names, in declaration order), tolerant of a PHP-empty [] and of a
 * node outside the payload's shape.
 *
 * @param {?object} declaration
 *   A served node (a root, a part's elements entry, a children section).
 *
 * @return {Object<string, string[]>}
 *   The grants, {} when the node grants nothing.
 */
export const grantsOf = (declaration) => {
  const grants = isPlainObject(declaration?.grants) ? declaration.grants : {};
  const map = {};
  Object.entries(grants).forEach(([group, names]) => {
    if (Array.isArray(names)) {
      map[group] = names;
    }
  });
  return map;
};

/**
 * The property groups a served node grants through.
 *
 * The roster's groups() (PHP: VocabularyRoster::groups()): the ids in
 * declaration order. Curated ids resolve against guide.groups; ids absent
 * from that catalog are the server-only generated wide tier, whose
 * granted names still ride the node's grants.
 *
 * @param {?object} declaration
 *   A served node.
 *
 * @return {string[]}
 *   The group ids, [] when none.
 */
export const declaredSets = (declaration) => Object.keys(grantsOf(declaration));

/**
 * Whether a served node grants one property.
 *
 * The roster's grantsProperty() (PHP: VocabularyRoster::grantsProperty(),
 * the validator's own grant read): a property is granted when one of the
 * node's groups lists it, a narrowed grant already resolved server-side,
 * a shorthand (padding, gap, border-radius) among the names exactly when
 * the server's family rule granted it over every longhand it sets, so no
 * reader derives one from the sides. A node
 * with no grants (an undeclared part, a missing payload) grants nothing.
 *
 * Depth-blind, like the served node (one layout, one node, any
 * placement): a grant is a function of the address alone, so a nested
 * container is granted exactly what the page band is. Placement curates
 * offers and birth (the axis vocabulary's root-only read, the birth
 * defaults' skip), never legality.
 *
 * Offer surfaces curate to it in every enforcement mode, exactly like
 * constraints shape offers; only strict makes it law (the server's grant
 * posture, propertiesClosed()).
 *
 * @param {?object} declaration
 *   The served node of the element being styled (the bound target's own;
 *   a part passes its elements entry).
 * @param {string} property
 *   The CSS property name.
 *
 * @return {boolean}
 *   True when a group of the node grants the property.
 */
export const propertyGranted = (declaration, property) =>
  Object.values(grantsOf(declaration)).some((names) =>
    names.includes(property),
  );

/**
 * Whether the guide's property and token worlds are closed (strict mode).
 *
 * The roster's propertiesClosed() (PHP: VocabularyRoster::propertiesClosed(),
 * one answer site-wide, so the payload carries the mode once as
 * `enforcement`: open | guarded | strict), and only strict closes the
 * world: undeclared properties are rejected and declared-namespace var()
 * references must name minted tokens. Ask this question, not the mode
 * string.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 *
 * @return {boolean}
 *   True on closed-world (strict enforcement) sites.
 */
export const propertiesClosed = (guide) => guide?.enforcement === 'strict';

/**
 * Whether the guide's authored constraints are law (guarded and strict).
 *
 * The roster's constraintsAreLaw() (PHP: VocabularyRoster::constraintsAreLaw(),
 * over the same site-wide `enforcement`): only the open enforcement mode
 * demotes constraints to offer-shaping; the server then accepts any
 * recognized property with a grammatical value, so no client surface may
 * close an input or block a commit on a constraint's say-so.
 * Constraints still SHAPE offers in every mode (stop rows derive from
 * scale bindings regardless); this predicate gates only the closing
 * behaviors: the pencil-reveal gate, the advanced rows' select-only
 * inputs, and the code view's constraint lint. Ask this question, not
 * the mode string.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 *
 * @return {boolean}
 *   True while constraints are enforced server-side (guarded/strict).
 */
export const constraintsAreLaw = (guide) => guide?.enforcement !== 'open';

/**
 * The served node's value space for one property: the LAW read.
 *
 * The roster's valueSpace() (PHP: VocabularyRoster::valueSpace(), what the
 * validator binds a granted property to): the node's own constraint
 * block, else the site's AUTHORED constraint default, resolved
 * server-side into the node's `valueSpaces` map, curated and wide-tier
 * properties alike. The node is the bound target's own; a part passes
 * its elements entry, not the parent. Law mirrors (the code view's lint)
 * read here; offer surfaces layer the payload's constraintDefaults map
 * beneath it through resolvedConstraintsFor(), the site's authored
 * bindings for a property this node leaves unconstrained.
 *
 * @param {?object} declaration
 *   The served node of the element being styled.
 * @param {string} property
 *   The CSS property name.
 *
 * @return {?object}
 *   The constraints map ({allowed, min, max, units, scale, stops}, any
 *   subset), or null when the property is unconstrained (or ungranted)
 *   here.
 */
export const constraintsFor = (declaration, property) => {
  const spaces = isPlainObject(declaration?.valueSpaces)
    ? declaration.valueSpaces[property]
    : null;
  return isPlainObject(spaces) ? spaces : null;
};

/**
 * Resolves the OFFER constraints for one property: the roster, else the site's bindings.
 *
 * The offer vocabulary's lookup layer (the client half of the authored
 * constraint_defaults story): the node's value space (constraintsFor(),
 * the roster's law read, the site's authored default already folded in)
 * wins wholesale; a property the roster leaves unconstrained (or does not
 * grant) falls back to the payload's site-wide constraintDefaults map
 * (the authored constraint_defaults map, StyleGuide::constraintDefaults();
 * nothing is derived into it). This is how a site-level binding
 * (font-size -> the type scale) shapes stop rows everywhere without
 * per-node authoring; stopsFor() already prefers a scale binding, so
 * the built-in stop tables are reached only with no binding and no
 * skin, the honest skinless fallback. Blocks resolve whole, never
 * key-merged, mirroring the validator's node-block-??-default rule.
 *
 * Offer shaping only: the widgets' stop rows, ticks, completions, and
 * companion seeds read here. Enforcement surfaces gate separately on
 * constraintsAreLaw() (and the code view's lint stays on the roster's
 * constraintsFor(), see there): an offer read on an ungranted property
 * shapes a row without ever being law.
 *
 * @param {?object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {?object} declaration
 *   The served node of the element being styled.
 * @param {string} property
 *   The CSS property name.
 *
 * @return {?object}
 *   The constraints map ({allowed, min, max, units, scale, stops}, any
 *   subset), or null when neither the node nor the site constrains the
 *   property.
 */
export const resolvedConstraintsFor = (guide, declaration, property) => {
  const own = constraintsFor(declaration, property);
  if (own !== null) {
    return own;
  }
  const defaults = isPlainObject(guide?.constraintDefaults)
    ? guide.constraintDefaults[property]
    : null;
  return isPlainObject(defaults) ? defaults : null;
};

/**
 * Resolves the guide's property definition for one property.
 *
 * The property-major spelling of the lookup buildSections does group-major:
 * find the property's owning group in the guide's group table and return
 * its definition there ({label, type, negative, number, keywords, repeat},
 * any subset). Unlike constraintsFor, definitions are guide-global facts —
 * a group defines its properties once, for every declaration.
 *
 * @param {object} guide
 *   The style guide payload (drupalSettings.daedalusStylish.guide).
 * @param {string} property
 *   The CSS property name.
 *
 * @return {?object}
 *   The property definition, or null when no group defines the property.
 */
export const definitionFor = (guide, property) => {
  const groups = guide?.groups ?? {};
  const owner = Object.keys(groups).find(
    (id) => groups[id]?.properties?.[property],
  );
  const definition =
    owner === undefined ? null : groups[owner].properties[property];
  return isPlainObject(definition) ? definition : null;
};

/**
 * Projects a served node + style entry into the panel's sections.
 *
 * Sections are the groups the node grants through (declaredSets, the
 * roster's groups) in the guide's panel order (the server sends groups
 * pre-sorted by weight), plus any group that owns a property the entry
 * sets without the node granting it: current values always show, granted
 * or not. A granted group's rows are the properties the node GRANTS
 * (propertyGranted, the roster's read the basic controls and the code
 * view share, the same at every placement depth); an ungranted property
 * rows only while the entry sets it. A set property no installed group
 * owns lands in a trailing catch-all section (label null; the component
 * names it), so a degraded map still displays truthfully.
 *
 * Each row also carries what the widget layer needs: the property group's
 * definition (type, keywords, flags) and the property's RESOLVED
 * constraints ({allowed, min, max, units, scale, stops} or null) — the
 * node's own block, else the site-wide constraint default
 * (resolvedConstraintsFor, the offer vocabulary's lookup layer), so a
 * site-level binding (font-size -> the type scale) shapes the advanced
 * rows exactly like the basic-mode stop rows — client-side input shaping
 * only; the server validates every commit regardless.
 *
 * @return {Array<{id: string, label: ?string, rows: Array<{property: string,
 *   label: string, definition: ?object, constraints: ?object,
 *   values: Array<{condition: string, value: string}>}>,
 *   hasValues: boolean}>}
 */
export const buildSections = (guide, declaration, entry) => {
  const groups = guide?.groups ?? {};
  const sets = declaredSets(declaration);
  const groupIds = Object.keys(groups);

  // Condition display order: default first, then the guide's declaration
  // order, unknown names last (inert data, still shown).
  const conditionOrder = ['default', ...Object.keys(guide?.conditions ?? {})];
  const rank = (condition) => {
    const index = conditionOrder.indexOf(condition);
    return index === -1 ? conditionOrder.length : index;
  };

  // Collect set values: property → [{condition, value}], conditions ordered.
  const values = {};
  if (isPlainObject(entry)) {
    Object.entries(entry)
      .filter(
        ([condition, properties]) =>
          condition !== 'variants' && isPlainObject(properties),
      )
      .sort(([a], [b]) => rank(a) - rank(b))
      .forEach(([condition, properties]) => {
        Object.entries(properties).forEach(([property, value]) => {
          (values[property] ||= []).push({ condition, value: String(value) });
        });
      });
  }

  const ownerOf = (property) =>
    groupIds.find((id) => groups[id]?.properties?.[property]) ?? null;
  const setProperties = Object.keys(values);

  const ids = groupIds.filter(
    (id) =>
      sets.includes(id) ||
      setProperties.some((property) => ownerOf(property) === id),
  );

  const sections = ids.map((id) => {
    const group = groups[id];
    const isDeclared = sets.includes(id);
    // A granted group offers exactly the names the node grants
    // (propertyGranted); a property the roster withholds never rows here.
    // A set value keeps its row regardless (a set value always stays
    // visible and unsettable), the same exception the hide-empty-closed
    // rule makes.
    const rows = Object.entries(group?.properties ?? {})
      .filter(
        ([property]) =>
          (isDeclared && propertyGranted(declaration, property)) ||
          values[property],
      )
      .map(([property, definition]) => ({
        property,
        label: definition?.label ?? property,
        definition: definition ?? null,
        constraints: resolvedConstraintsFor(guide, declaration, property),
        values: values[property] ?? [],
      }));
    return {
      id,
      label: group?.label ?? id,
      rows,
      hasValues: rows.some((row) => row.values.length > 0),
    };
  });

  const orphans = setProperties.filter(
    (property) => ownerOf(property) === null,
  );
  if (orphans.length) {
    sections.push({
      id: '_other',
      label: null,
      rows: orphans.map((property) => ({
        property,
        label: property,
        definition: null,
        constraints: null,
        values: values[property],
      })),
      hasValues: true,
    });
  }

  return sections;
};

/**
 * A section's arrival posture: the two auto signals.
 *
 * Open when the section's rows show a value (`hasValues` above, over the
 * stack's delivered entry: any layer's, the element's own, a worn
 * variant's, the active skin's or a declaration's, since a row shows
 * what the page shows) or when one of its visible rows carries a pending
 * line (a pending removal still needs its dismiss surface). `rows` is
 * the section's VISIBLE rows (after the inspector's hidden-row filter);
 * the pending signal is read through the caller's row-keyed lookup. The
 * inspector's Sections project it as the section's DEFAULT posture in
 * the collapse model (`folded: !autoOpen(...)`); the user's toggles are
 * the explicit postures over it.
 */
export const autoOpen = (section, rows, { pendingFor }) =>
  Boolean(
    section.hasValues || rows.some((row) => pendingFor(row) !== null),
  );
