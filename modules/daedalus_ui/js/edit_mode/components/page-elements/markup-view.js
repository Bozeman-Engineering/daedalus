/**
 * The built-in markup view: the composed DOM sketch in the page-elements
 * tree.
 *
 * While the Show menu's "Markup" toggle is on, the tree renders compose()'s
 * blueprint × declared-shells composition instead of the data projection:
 * shell rows (declared class rows with fold state) interleave the model
 * rows, which keep their keys, verbs, and drags. Anatomy is daedalus_ui
 * vocabulary (the declarations' elements trees, delivered as
 * drupalSettings.daedalusUi.anatomy), so the view lives here; what a
 * shell row DOES beyond structure — drill targets, hover affordances — is
 * another module's meaning, delegated to the registered part behavior
 * (view-registry.js: daedalus_stylish drills the style inspector and runs the
 * two-way hover sync). With no behavior registered the shell rows are
 * structure only: they fold, they read, they do nothing.
 *
 * A second row source rides the same seam: INSTANCE rows
 * (instance-rows.js), rows for identity-carrying rich-text elements,
 * read from the `_element.markup.instances` annotation on the blueprint
 * mirror. They append to the model row whose annotation carries them, and
 * liveness rides the mirror itself: every mutation response re-annotates
 * and the tree re-renders from the replaced mirror, so no DOM observation
 * feeds the view (the canvas observer only VERIFIES, markup-verify.js).
 *
 * Instance rows are CONTENT, not markup: they exist because the user
 * authored paragraphs, headings, lists — the litmus is that a freshly
 * placed empty block would have none. So unlike shell rows they are not
 * gated behind this view's Show-menu toggle: the tree's DEFAULT model
 * projection carries them too (withInstanceRows below), minus the tag
 * chips, which are developer vocabulary and stay this view's own.
 */
// cspell:ignore Hieah

import { compose } from './compose.js';

/**
 * The provider's Show-menu toggle id (the view slice's `parts` key).
 */
export const MARKUP_VIEW_ID = 'markup';

/**
 * An instance row (an annotation-derived {selector, label, tag?, sublabel?,
 * children} from the instances source) as a seam pseudo row, owned by the
 * model key whose annotation carries it. The annotation already resolved
 * the carrier's tag server-side, so its chip rides the row as-is — but
 * only while `chips` is on: the tag chip is markup vocabulary, worn in
 * this view alongside the shell rows' chips and nowhere else.
 */
const toInstanceRow = (row, ownerKey, chips = true) => {
  const pseudoRow = {
    pseudo: true,
    id: row.selector,
    parentKey: ownerKey,
    label: row.label,
    children: row.children.map((child) =>
      toInstanceRow(child, ownerKey, chips),
    ),
  };
  if (chips && row.tag) {
    pseudoRow.tag = row.tag;
  }
  if (row.sublabel) {
    pseudoRow.sublabel = row.sublabel;
  }
  return pseudoRow;
};

/**
 * The model projection with instance rows appended — what the tree renders
 * with no active view provider (daedalus_stylish's basic mode over the simple
 * page-elements tree).
 *
 * Instance rows are content, so which view renders never decides whether
 * they exist: every keyed model row asks the same instance source the
 * markup view asks and appends the same seam pseudo rows — same ids, same
 * owner keys — so a carrier selected from the canvas (Cmd+click resolving
 * a part) highlights its row in either projection; the spine's
 * {selectedKey, selectedPart} pair matches the row identically in both.
 * Only the chips differ: here the rows are chip-less, because the tag
 * chip belongs to the markup view's developer vocabulary (shell rows,
 * resolved tags), which stays behind its Show-menu toggle.
 *
 * Display-only, like every view: the selection universe (the projection
 * index) composes both tiers view-independently, so a pseudo row here is
 * the same `key::selector` address it is in the markup view.
 *
 * @param {object} node
 *   A projected model node (projection.js projectTree output).
 * @param {function} instances
 *   The instance-row source (instance-rows.js instanceRows), keyed by
 *   model key.
 *
 * @return {object}
 *   The node with instance pseudo rows appended under every keyed row.
 */
export const withInstanceRows = (node, instances) => {
  const children = node.children.map((child) =>
    withInstanceRows(child, instances),
  );
  if (node.key) {
    children.push(
      ...instances(node.key).map((row) => toInstanceRow(row, node.key, false)),
    );
  }
  return { ...node, children };
};

/**
 * Adapts a composed tree (compose.js) to the tree view seam's vocabulary.
 *
 * Model rows pass through — they already wear the projection's shape (key,
 * kind, label, presentation, editTarget…). A field row's markup identity
 * (`markupClass` — the .field--name-X wrapper that IS the selectable
 * element) surfaces as the seam's `sublabel` so the row reads "Hieah ·
 * field--name-field-hieah"; absorbed rows already PRESENT as the class
 * (compose swapped their label), so no sublabel — it would repeat the
 * label. Shell rows become pseudo rows: id is the class selector (the
 * behavior's drill target and hover subject), parentKey the owning model
 * key compose stamped (at any depth), and `claims` (the slot kinds the
 * part claims) rides along when compose stamped it, so the tree can mark
 * the shell that claims the root's sections as the root flow's drop
 * surface. Value rows become keyless model rows
 * — display-only, the same unselectable rendering a uuid-less section row
 * gets, distinctly styled via kind 'value'.
 *
 * Every keyed MODEL row additionally asks the instance source for the
 * rows its annotation owns (appended after the composed children; shells
 * first is the composition's ordering convention). Ownership is decided
 * where the annotator stamped the instances, so exactly one model row
 * claims each carrier, the block row for a composed text block, the
 * field row for a core-rendered field, mirroring where the innermost
 * selectable element lands in the DOM.
 *
 * Tag chips ride the seam as `tag`, the element tag a row rendered as
 * (rendered by the tree as a dim monospace `<img>` / `<a>` / `<div>`
 * chip). Model rows wear the RESOLVED tag from the markup annotation
 * (tags.root), only when the model speaks: no chip is honest for a key
 * the annotation never covered (plugin blocks, fields). Shell rows
 * resolve first (tags.part, the same owner/selector pair the placement
 * gate asks with) and degrade to the declaration's authored tag
 * (compose's `declaredTag`, the anatomy payload): the sketch keeps its
 * chips when the model does not speak, never wears a wrong one when it
 * does. `declaredTag` itself is composed vocabulary and never leaks.
 *
 * A shell row's LABEL resolves the same way: the matched-form label from
 * the annotation (tags.label, markup-index.js partLabel, the form's own
 * name, 'Raster' for the image part's img form) when the model speaks,
 * the composed label (the declared part-level label, or the dotless
 * selector) otherwise. A multi-form part composes no declaredTag, because
 * only the annotation knows which form matched, so its chip exists
 * exactly when the model speaks.
 *
 * @param {object} node
 *   A composed node ({type: 'model'|'shell'|'value', ...}).
 * @param {?function} instances
 *   The instance-row source (instance-rows.js instanceRows), keyed by
 *   model key. Null composes declared anatomy only (node tests, data-only
 *   callers).
 * @param {?{root: function, part: function, label: function}} tags
 *   The resolved-markup accessors (markup-index.js rootTag, partTag, and
 *   partLabel). Null stamps declared tags and labels only (node tests,
 *   data-only callers).
 *
 * @return {object}
 *   The seam node.
 */
export const toViewTree = (node, instances = null, tags = null) => {
  if (node.type === 'shell') {
    const pseudoRow = {
      pseudo: true,
      id: node.selector,
      parentKey: node.parentKey,
      label: tags?.label?.(node.parentKey, node.selector) ?? node.label,
      children: node.children.map((child) =>
        toViewTree(child, instances, tags),
      ),
    };
    const tag =
      tags?.part?.(node.parentKey, node.selector) ?? node.declaredTag ?? null;
    if (tag) {
      pseudoRow.tag = tag;
    }
    if (node.claims) {
      pseudoRow.claims = node.claims;
    }
    return pseudoRow;
  }
  if (node.type === 'value') {
    return {
      key: null,
      kind: 'value',
      label: node.label,
      presentation: 'open',
      children: [],
    };
  }
  const { type, ...row } = node;
  if (node.markupClass && !node.absorbed) {
    row.sublabel = node.markupClass;
  }
  const tag = node.key ? (tags?.root?.(node.key) ?? null) : null;
  if (tag) {
    row.tag = tag;
  }
  const children = node.children.map((child) =>
    toViewTree(child, instances, tags),
  );
  if (instances && node.key) {
    children.push(
      ...instances(node.key).map((row) => toInstanceRow(row, node.key)),
    );
  }
  return { ...row, children };
};

/**
 * Creates the markup view provider (registered at tree init).
 *
 * The part-behavior reads delegate at interaction time — registration
 * order between the app bundle and a behavior module's bundle is not a
 * guarantee. The behavior's resolution seam (resolve/selectorFor,
 * view-registry.js contract) rides the delegation, so the provider
 * surfaces the full behavior contract. (Pseudo-row hover, click, and
 * glow are spine facets read and written by the tree itself, not
 * behavior callbacks.)
 *
 * @param {function} getPartBehavior
 *   The behavior lookup (view-registry.js getPartBehavior), injected so
 *   this module stays pure and node-testable.
 * @param {?{rows: function, placement: function, rootTag: function,
 *   partTag: function, partLabel: function}} instanceModel
 *   The annotation-backed instance model: `rows` (instance-rows.js
 *   instanceRows) feeds toViewTree's instances argument, `placement`
 *   (markup-index.js partPlacement) is compose's placement channel, a
 *   declared part's shell row mounts only when the markup annotation says
 *   the part resolved as its own element (a 'root'-hoisted part is real
 *   but not a distinct DOM node, so no row; link/unlink and media swaps
 *   flip the answer with the mutation response that re-delivers the
 *   mirror, which re-renders the tree; no subscribe seam is needed), and
 *   `rootTag`/`partTag`/`partLabel` (markup-index.js) feed toViewTree's
 *   tag chips and shell labels, the resolved tag a row rendered as and
 *   the matched-form label a multi-form part resolved under, riding the
 *   same annotation and the same liveness. Null (node tests) composes
 *   declared anatomy only: placement-blind, declared tags and labels
 *   only.
 */
export const createMarkupView = (
  getPartBehavior = () => null,
  instanceModel = null,
) => {
  const provider = {
    displayToggle: { id: MARKUP_VIEW_ID, label: Drupal.t('Markup') },

    projectTree: (root, blueprint) =>
      toViewTree(
        compose(blueprint, drupalSettings.daedalusUi?.anatomy, {
          root,
          targets: true,
          placement: instanceModel?.placement ?? null,
        }),
        instanceModel?.rows ?? null,
        instanceModel
          ? {
              root: instanceModel.rootTag,
              part: instanceModel.partTag,
              label: instanceModel.partLabel,
            }
          : null,
      ),

    resolve: (domTarget) => getPartBehavior()?.resolve?.(domTarget) ?? null,
    selectorFor: (parentKey, id, declarations) =>
      getPartBehavior()?.selectorFor?.(parentKey, id, declarations) ?? null,
  };
  return provider;
};
