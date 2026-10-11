import { declarationLabel } from './provenance-model.js';
import { captureScope, familyKindOf } from './variant-scope.js';
import { parseKey } from './style-guide.js';

/**
 * The Law tab's pure model over the guide payload's `law` projection
 * (ClientStyleGuide::build(): what the style guide declares, every fact
 * with its origin, read under the active design system's enforcement
 * mode).
 *
 * The law is authored in files and SHOWN here: nothing in this module
 * writes, and the view over it (law.js) is read-only. The tab follows
 * the selection to the selected element's type (lawScopeFor: the
 * declaring-scope grammar the stack's floor layers speak, `block_type:
 * text`, `layout:layout_onecol`, `kind:container`, the family kind when
 * the refinement declares nothing), and a declared value's doorway
 * (provenance-model.js declaredDoorway, riding the one openDocument
 * road as a law document reference, code/layers.js lawDocument) opens
 * it at that scope with the property's rows marked as the focus.
 *
 * Everything here is data-in/data-out for node-test backpressure
 * (tests/js/law-model.test.mjs); the view renders what it answers.
 */

/**
 * The law node scope a style-map key reads its law from.
 *
 * The root's captured scope (variant-scope.js captureScope: the
 * refinement when the tree knows one, `block_type:text`, `layout:
 * layout_onecol`, `block_plugin:system_menu_block`, else the kind), a
 * part or rule key resolving to its root's; when the law serves no node
 * for a refinement (an undeclared bundle: the kind IS its effective
 * declaration, ClientStyleGuide serves nothing for it) the family kind
 * answers. Null for an unparsable key or a scope the law does not
 * carry at all.
 *
 * @param {object} args
 * @param {?string} args.key
 *   The selected key.
 * @param {object} [args.blockTypes]
 *   component uuid → inline block type (or 'plugin:{id}').
 * @param {object} [args.sectionLayouts]
 *   section uuid → layout id.
 * @param {?object} args.law
 *   The law projection.
 *
 * @return {?string}
 */
export const lawScopeFor = ({
  key,
  blockTypes = {},
  sectionLayouts = {},
  law = null,
}) => {
  if (typeof key !== 'string' || !parseKey(key)) {
    return null;
  }
  const pos = key.indexOf('::');
  const root = pos === -1 ? key : key.slice(0, pos);
  const scope = captureScope(root, blockTypes, sectionLayouts);
  if (scope === null) {
    return null;
  }
  const nodes = law?.nodes ?? {};
  if (nodes[scope]) {
    return scope;
  }
  const kind = familyKindOf(scope);
  return kind !== null && nodes[kind] ? kind : null;
};

/**
 * The family word a node scope files under, for the type picker.
 */
const familyWord = (scope) => {
  switch (scope.slice(0, scope.indexOf(':'))) {
    case 'kind':
      return Drupal.t('Kinds');
    case 'block_type':
      return Drupal.t('Block types');
    case 'block_plugin':
      return Drupal.t('Block plugins');
    case 'layout':
      return Drupal.t('Layouts');
    default:
      return scope;
  }
};

/**
 * A node's name: the label it carries (a block type's bundle label, a
 * part's authored label), else its scope in words (provenance-model.js
 * declarationLabel, the origin sentences' own name for it).
 */
export const nodeLabel = (scope, node) =>
  typeof node?.label === 'string' && node.label !== ''
    ? node.label
    : (declarationLabel(scope) ?? scope);

/**
 * The types the tab can show, in the payload's order, grouped by
 * family.
 *
 * @param {?object} law
 *   The law projection.
 *
 * @return {Array<{scope: string, label: string, family: string}>}
 */
export const lawTypes = (law) =>
  Object.entries(law?.nodes ?? {}).map(([scope, node]) => ({
    scope,
    label: nodeLabel(scope, node),
    family: familyWord(scope),
  }));

/**
 * The enforcement reading in sentences: the mode, what it closes, and
 * what the constraints do under it.
 *
 * @param {?object} law
 *   The law projection.
 *
 * @return {string[]}
 */
export const readingSentences = (law) => {
  const mode = law?.enforcement ?? 'guarded';
  const modeWords = {
    open: Drupal.t('Open enforcement.'),
    guarded: Drupal.t('Guarded enforcement.'),
    strict: Drupal.t('Strict enforcement.'),
  };
  return [
    modeWords[mode] ?? Drupal.t('!mode enforcement.', { '!mode': mode }),
    law?.properties === 'closed'
      ? Drupal.t(
          'Only the properties a declaration grants may be styled; a var() in a declared scale must name a minted token.',
        )
      : Drupal.t(
          'Undeclared properties stay open with recognition and grammar checks.',
        ),
    law?.constraints === 'law'
      ? Drupal.t('Authored constraints are law: a value outside one is refused.')
      : Drupal.t(
          'Authored constraints shape the offer only: nothing is refused.',
        ),
  ];
};

/**
 * An origin in words: the file's basename (the provider names where it
 * sits, originTitle), the alter hook for a value it wrote, "built in"
 * for a fact no layer wrote.
 *
 * @param {?{provider: ?string, file: ?string}} origin
 *
 * @return {string}
 */
export const originWords = (origin) => {
  if (!origin) {
    return Drupal.t('built in');
  }
  if (origin.file === null) {
    return Drupal.t('hook_page_builder_declarations_alter()');
  }
  return origin.file;
};

/**
 * An origin's title: which module or theme ships the file.
 *
 * @return {?string}
 */
export const originTitle = (origin) =>
  origin && typeof origin.provider === 'string'
    ? Drupal.t('Shipped by !provider', { '!provider': origin.provider })
    : null;

/**
 * An origin as the view renders it.
 */
const originEntry = (origin) => ({
  words: originWords(origin),
  title: originTitle(origin),
});

/**
 * A constraint fact in words: a list joins, a scalar prints.
 */
export const factWords = (fact, value) =>
  Array.isArray(value) ? value.map(String).join(', ') : String(value);

/**
 * The declaration tree of a node in render order: the root, then each
 * part depth-first with its children section and declared boxes.
 *
 * @param {object} node
 *   A law root node.
 *
 * @return {Array<{path: string, label: string, node: object, depth: number, root: boolean}>}
 *   `path` is the relative address in the key grammar ('' the root,
 *   '.part', '.part > *', '.part::before').
 */
export const lawTree = (node, scope = '') => {
  const rows = [];
  const walk = (current, path, label, depth, root) => {
    rows.push({ path, label, node: current, depth, root });
    for (const pseudo of Object.keys(current.pseudo ?? {})) {
      const boxPath = `${path}${path === '' ? '&' : ''}::${pseudo}`;
      walk(current.pseudo[pseudo], boxPath, boxPath, depth + 1, false);
    }
    if (current.children) {
      const childPath = `${path}${path === '' ? '' : ' '}> *`;
      walk(current.children, childPath, childPath, depth + 1, false);
    }
    for (const [selector, part] of Object.entries(current.elements ?? {})) {
      const partPath = `${path}${path === '' ? '' : ' '}${selector}`;
      walk(
        part,
        partPath,
        typeof part.label === 'string' && part.label !== ''
          ? `${part.label} (${selector})`
          : selector,
        depth + 1,
        false,
      );
    }
  };
  walk(node, '', nodeLabel(scope, node), 0, true);
  return rows;
};

/**
 * Whether a focus names a property of this node.
 */
const focused = (focus, property, root) =>
  root && Boolean(focus?.property) && focus.property === property;

/**
 * A node's law as sections of rows for the view.
 *
 * Grants (group => the properties it grants, the layers whose facts
 * survive under it), constraints (property => the effective block's
 * facts with each fact's origin, and whether the node's own block or
 * the site's binding supplies it), and on a root node the axes, the
 * birth defaults (the default variants an element is born wearing)
 * and the declared defaults (the declaration's own box facts per
 * relative key and condition). Empty sections are omitted. A row whose
 * property the focus names carries `focus: true`; the view marks and
 * reveals it.
 *
 * @param {object} node
 *   A law node (a root, a part, a children section, a declared box).
 * @param {?object} law
 *   The law projection, for the reading the section words follow.
 * @param {object} [options]
 * @param {?{scope: string, property: ?string}} [options.focus]
 * @param {boolean} [options.root]
 *   Whether the node is a root (axes, birth and defaults are the root's).
 *
 * @return {Array<{id: string, label: string, rows: Array<object>}>}
 */
export const nodeSections = (node, law, { focus = null, root = true } = {}) => {
  const sections = [];
  const origins = node?.origins ?? {};

  const grants = Object.entries(node?.grants ?? {}).map(([group, names]) => ({
    id: group,
    label: group,
    value: names.join(', '),
    origins: (origins.grants?.[group] ?? []).map(originEntry),
  }));
  if (grants.length) {
    sections.push({ id: 'grants', label: Drupal.t('Grants'), rows: grants });
  }

  const constraints = Object.entries(node?.constraints ?? {}).map(
    ([property, block]) => {
      const attribution = origins.constraints?.[property] ?? {};
      return {
        id: property,
        label: property,
        source: attribution.source ?? 'node',
        focus: focused(focus, property, root),
        facts: Object.entries(block).map(([fact, value]) => ({
          fact,
          value: factWords(fact, value),
          curated: fact === 'stops',
          origin: originEntry(attribution.facts?.[fact] ?? null),
        })),
      };
    },
  );
  if (constraints.length) {
    sections.push({
      id: 'constraints',
      label:
        law?.constraints === 'law'
          ? Drupal.t('Constraints (law)')
          : Drupal.t('Constraints (offer only)'),
      rows: constraints,
    });
  }

  if (root) {
    const axesOrigin = originEntry(origins.axes ?? null);
    const axes = Object.entries(node?.axes ?? {}).map(([axis, facts]) => ({
      id: axis,
      label: facts?.label ?? axis,
      value: [
        facts?.root_only ? Drupal.t('top-level sections only') : null,
        facts?.owns?.length
          ? Drupal.t('owns !properties', {
              '!properties': facts.owns.join(', '),
            })
          : null,
        facts?.description ?? null,
      ]
        .filter((part) => part !== null)
        .join(' · '),
      origin: axis === 'style' ? originEntry(null) : axesOrigin,
    }));
    if (axes.length) {
      sections.push({ id: 'axes', label: Drupal.t('Variant axes'), rows: axes });
    }

    const birth = Object.entries(node?.birth ?? {}).map(([axis, entry]) => ({
      id: axis,
      label: node?.axes?.[axis]?.label ?? axis,
      value:
        entry.depth === 'root'
          ? Drupal.t('!variant (top-level sections only)', {
              '!variant': entry.variant,
            })
          : entry.variant,
      origin: originEntry(origins.birth?.[axis] ?? null),
    }));
    if (birth.length) {
      sections.push({
        id: 'birth',
        label: Drupal.t('Born wearing'),
        rows: birth,
      });
    }

    const defaults = [];
    for (const [key, entry] of Object.entries(node?.defaults ?? {})) {
      for (const [condition, properties] of Object.entries(entry ?? {})) {
        for (const [property, value] of Object.entries(properties ?? {})) {
          defaults.push({
            id: `${key}|${condition}|${property}`,
            key,
            condition,
            property,
            label: `${key === '' ? '' : `${key} `}${property}`,
            value: String(value),
            focus: focused(focus, property, root),
            origin: originEntry(
              origins.defaults?.[key]?.[condition]?.[property] ?? null,
            ),
          });
        }
      }
    }
    if (defaults.length) {
      sections.push({
        id: 'defaults',
        label: Drupal.t('Declared defaults'),
        rows: defaults,
      });
    }
  }

  return sections;
};

/**
 * The scales with what binds to them.
 *
 * Per declared scale: its facts, the contract names the site promises,
 * and every binding naming the scale: the site's constraint defaults
 * (site-wide, `site: true`) and every node constraint across the law's
 * tree ({scope, path, property}).
 *
 * @param {?object} law
 *   The law projection.
 *
 * @return {Array<object>}
 */
export const scaleRows = (law) => {
  const bindings = {};
  const bind = (scale, entry) => {
    (bindings[scale] ??= []).push(entry);
  };
  for (const [property, entry] of Object.entries(law?.bindings ?? {})) {
    if (typeof entry?.block?.scale === 'string') {
      bind(entry.block.scale, { property, site: true, scope: null, path: '' });
    }
  }
  for (const [scope, node] of Object.entries(law?.nodes ?? {})) {
    for (const { path, node: current } of lawTree(node, scope)) {
      for (const [property, block] of Object.entries(current.constraints ?? {})) {
        if (
          typeof block?.scale === 'string' &&
          current.origins?.constraints?.[property]?.source === 'node'
        ) {
          bind(block.scale, { property, site: false, scope, path });
        }
      }
    }
  }
  return Object.entries(law?.scales ?? {}).map(([scale, entry]) => ({
    scale,
    label: entry.label ?? scale,
    shape: entry.shape,
    prefix: entry.prefix,
    contract: entry.contract ?? [],
    origins: (entry.origins?.scale ?? []).map(originEntry),
    contractOrigin:
      entry.contract?.length ? originEntry(entry.origins?.contract ?? null) : null,
    bindings: bindings[scale] ?? [],
  }));
};

/**
 * A binding in words for the scale rows: the property, site-wide or at
 * a node's path.
 */
export const bindingWords = (binding) =>
  binding.site
    ? Drupal.t('!property (site-wide)', { '!property': binding.property })
    : Drupal.t('!property on !where', {
        '!property': binding.property,
        '!where': `${declarationLabel(binding.scope) ?? binding.scope}${binding.path === '' ? '' : ` ${binding.path}`}`,
      });
