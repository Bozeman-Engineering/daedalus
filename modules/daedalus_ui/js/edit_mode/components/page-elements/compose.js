/**
 * The markup composition: blueprint tree × declared shells → the DOM sketch.
 *
 * Every page builder declaration (kind, block type, layout — see
 * PageBuilderDeclarations) describes its type's MARKUP SHELL — the wrapper
 * tree between the element's root and where its model children mount — with
 * `contains:` slots naming which children mount where. This module is the
 * pure composition rule: each model node's children are substituted into
 * its shell's slots, recursively, with no DOM scraping — the same
 * composition an AI performs mentally from the anatomy schema (the two
 * projections read the same declarations, so they agree by construction).
 *
 * Everything here is data-in/data-out (node-testable; no Drupal, no DOM).
 * The element model rides the delivered blueprint mirror as the uniform
 * `_element` annotation on every owning node, root included — presentation,
 * collapse, and per-field existence/presentation facts are consumed from
 * it, never passed separately — while the third argument carries the VIEW
 * state: the root identity envelope and the two Show toggles, which are
 * orthogonal and compose. The anatomy (second argument) is the client
 * projection of the declarations' elements trees
 * (drupalSettings.daedalusUi.anatomy, built by ClientAnatomy).
 *
 * The rules (all from the nested-style-targets design):
 *
 * - Slots and the residual rule: a `contains:` slot is either a generic
 *   bucket (`fields`, `regions`, `value`, `sections`) or names specific
 *   children (`field:NAME`, `region:NAME`). Specific claims bind their
 *   named children; a generic bucket takes the residual of its kind;
 *   unclaimed children mount at the element root — which makes a
 *   declaration with no slots (all flat YAML) degenerate to exactly the
 *   data tree. The root entity's model children are its fields AND its
 *   layout field's section list: `sections` is the entity kind's bucket
 *   for the latter (one list, so no specific form), claimed by the part
 *   that renders the layout root (daedalus_layout's `.daedalus-layout-layout`); unclaimed,
 *   the sections mount at the entity root after the shells, the plain
 *   tree's order. Inline entities (paragraphs) carry no sections.
 * - Row identity: ONE row per addressable identity — a model key
 *   (selectable), a declared class (flat-key stylable), or an editable
 *   value — never per DOM occurrence. A multi-value field keeps one
 *   .field__item row and gets NO value row (a flat key styles every delta
 *   at once; per-delta rows would advertise addressing the grammar cannot
 *   express). Individually-addressable deltas (inline entities with their
 *   own keys) mount as model children at the value slot and compose
 *   recursively.
 * - Existence before everything: a field whose `_element.fields` entry says
 *   `element: false` never renders as an attributed element, so no row in
 *   ANY view.
 * - Compose first, absorb second: the full markup tree is composed (all
 *   identities + shells), THEN merged presentation runs on the composed
 *   tree exactly as it runs on the data tree today. Absorbed model rows
 *   vanish and the surviving merged row lands at the INNERMOST absorbed
 *   position — the merged row IS the content, so it sits where its value
 *   element lives, wearing all its verbs. With shells off there is nothing
 *   between the block row and its target, so the same rule reproduces
 *   today's merged row in the data tree. An absorbed field row whose
 *   wrapper element remains in the markup (shells on, merged landed deeper)
 *   keeps its key but presents under its markup class, flagged `absorbed`.
 *   The merge absorbs EVERY identity of the block but the target's own
 *   descendants (the data tree keeps a merged row's paragraph rows and
 *   nothing else): the target's sibling fields present as absorbed class
 *   rows the same way while their wrapper elements stand, and fold
 *   entirely where no wrapper stands (a collapsed block). The selection
 *   index derives its element tier from this composition, so an absorbed
 *   identity here is exactly an identity the data tree folds.
 * - The root twin: the root's `_element.presentation` carries the derived
 *   entity rule (the entity's label field presents AS the entity row). The
 *   root row cannot relocate, so its absorption folds toward the top: in
 *   the data tree the target field row vanishes (children hoisted, the
 *   projection's rule verbatim); in the markup view the field's WRAPPER
 *   element is still real, so its row stays — absorbed, presenting under
 *   its markup class — while its value row folds into the root row's live
 *   label.
 * - Collapsed = bare wrapper chain, not anatomy-free: a collapsed block
 *   (`_element.composed`, the block-TYPE declaration delivered per
 *   instance) renders its wrapper sandwich to a single tag, so the wrapper
 *   shells never apply: no field shells, no value rows (the block root IS
 *   the content element), and no block-kind fallback tree. The type's OWN
 *   declared elements are interior parts of that single tag (a linked text
 *   block's anchor, the image block's img) and mount as shell rows,
 *   placement-gated like every shell row. A merged collapsed block keeps
 *   them as the merged row's children: the block root is the content
 *   element, so the innermost absorbed position is the block position
 *   itself.
 * - Placement gates the rows (the phase-2 element-projection rule): the
 *   markup view projects the PAGE, so a declared part's shell row mounts
 *   only when the part resolved as its OWN element for the instance. The
 *   question is asked through the injected `placement` channel
 *   (view.placement, markup-index.js partPlacement over the
 *   `_element.markup` annotation; this module stays model-blind), and the
 *   gate speaks the channel's grammar: 'element' mounts the row; 'root'
 *   (the part hoisted onto the owning element's root: the unlinked image
 *   block's img IS the block root) mounts NO row, since it is not a
 *   distinct DOM node; `false` (the model positively says absent: the
 *   unlinked text block's anchor, svg media's img) likewise mounts no
 *   row, styled or not, no value-set exception. Either way the hidden
 *   part's mounted children hoist to its position: the gate hides
 *   wrapper rows only, never a model identity. Anything else (a null
 *   channel, a key the model does not speak for) degrades to mounting
 *   from declarations alone, never to an emptier tree than they promise.
 *
 * Composed node vocabulary (one `type` per identity class):
 * - model: {type, key, kind, label, elementType, icon?, bundle?,
 *   presentation, children} — a blueprint identity, carrying the same facts the built-in
 *   projection's row does (the selection index derives from THIS
 *   composition, so a bundle-bearing row's bundle and a form-only stub's
 *   `formOnly` ride here too). `label` and `elementType` are the
 *   annotation's `_element.name` and `_element.type`, the server-minted
 *   naming spine (projection.js documents the words), `icon` the
 *   annotation's `_element.icon` on the nodes it mints one for; the
 *   composed vocabulary's own `type` is why the element's type word
 *   travels as `elementType`. Field rows with shells on also carry
 *   `markupClass` (the field's own wrapper class: the selectable element
 *   and the .field--name-X wrapper are ONE element = ONE row, and an
 *   absorbed row presents AS it), merged rows carry `editTarget`,
 *   absorbed field rows carry `absorbed: true`.
 * - shell: {type, selector, label, parentKey, children, claims?} — a
 *   declared class row; parentKey is the owning MODEL key at any depth
 *   (flat sub-keys: `${parentKey}::${selector}` addresses it). A part with
 *   `contains:` slots carries the distinct slot kinds it claims as
 *   `claims` (['section'] for daedalus_layout's layout root), the row's structural
 *   meaning for whoever reads the row: a shell that claims the root's
 *   sections is the root flow's drop surface (gaps.js), because the
 *   section rows are its rows. A slot-less part carries no claims key. An
 *   unlabeled part's label is the selector without its leading dot — the
 *   design sketches' convention (class rows read as class names, not CSS
 *   syntax; markup-class model labels are dotless the same way). A part
 *   whose declaration authors a
 *   `tag` carries it as `declaredTag`: the tag-chip degrade path for rows
 *   the model does not speak for (the seam resolves the live tag,
 *   markup-view.js toViewTree). A MULTI-FORM part (`forms` in place of
 *   `tag`) carries no declaredTag, because only the annotation knows which
 *   form matched, and composes the part-level label as usual: the seam
 *   resolves the matched-form label (markup-index.js partLabel) and falls
 *   back to it.
 * - value: {type, key: null, label, children: []} — the editable content
 *   of a single-value scalar field (an daedalus_edit page element, not a
 *   selectable identity).
 * - shown: {type, shown: true, label, elementType, icon, showing,
 *   parentKey, children: []} — the document a door shows by reference
 *   (shown-rows.js), under the door after its fills. No key, no claimable
 *   slot kind: the shown document is another host's, so the row is no
 *   identity of this page and mounts at the door's root like any
 *   unclaimed child; the seam carries it as the same row minus `type`.
 *
 * Ordering convention: shell rows render in authored order first, then the
 * children mounting at that position (root-mounted model rows after their
 * owner's shells; claimed rows after their slot part's nested shells). The
 * markup's true source order between wrappers and loose children is not
 * declared, so the model picks one convention and keeps it.
 */

import { isFormOnlyStub } from '../../blueprint/form-only-stub.js';
import { pairSlotFills } from './slot-fills.js';
import { shownRows } from './shown-rows.js';

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * The one legal part selector shape: a single CSS class.
 *
 * The declaration grammar's rule (mirrored from the guide-build validation
 * server-side): a part selector prints into flat sub-element keys and
 * queries the live DOM, so anything else is filtered rather than rendered
 * as a row nothing can address.
 */
export const PART_SELECTOR = /^\.[A-Za-z0-9_-]+$/;

/**
 * The vocabulary for rows the annotator does not name: a field row's
 * machine name, a region's, an inline entity's bundle (paragraphs carry
 * no `_element`). Never an annotated node's word, which is delivered.
 */
const humanize = (machineName) => {
  const words = String(machineName)
    .replace(/^field_/, '')
    .replace(/[_-]+/g, ' ')
    .trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

/**
 * A content row's text: the value's first line, 80 chars (the row shows
 * content, not a preview). Non-string and empty values fall through to
 * null. This words VALUE rows (a scalar field's editable content) and, in
 * instance-rows.js, a carrier's name (its excerpt): content rows, never
 * elements. An element's name is minted server-side (`_element.name`),
 * where the same first-line rule names a merged block.
 */
export const textExcerpt = (value) => {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  const line = value.trim().split('\n')[0];
  return line.length > 80 ? `${line.slice(0, 79)}…` : line;
};

/**
 * The field element's own wrapper class in core markup.
 *
 * Core's field template stamps field--name-{name} (underscores hyphenated,
 * Html::getClass) on the field's root element — the same element the
 * field's selectable key addresses, so this is the row's markup identity,
 * not an extra row.
 */
export const fieldClass = (name) =>
  `field--name-${String(name).replace(/_/g, '-')}`;

/**
 * An inline-serialized entity (a paragraph or other owned reference):
 * distinguishable from shared-reference values ({target_id, _label}) by
 * carrying its own uuid.
 */
const isInlineEntity = (value) => isPlainObject(value) && !!value.uuid;

/**
 * A field row exists when the owner's annotation doesn't say otherwise —
 * the same rule the built-in projection applies (see projection.js).
 */
const fieldExists = (facts, name) => facts[name]?.element !== false;

const normalizeContains = (contains) => {
  if (contains == null) {
    return [];
  }
  return Array.isArray(contains) ? contains : [contains];
};

/**
 * The generic slot buckets and the child kind each one claims.
 */
const GENERIC = {
  fields: 'field',
  regions: 'region',
  value: 'value',
  sections: 'section',
};

/**
 * The slot kind a `contains:` token claims: the bucket's kind for a
 * generic token, the prefix for a specific claim (`field:NAME` claims a
 * field), null for anything else.
 */
const claimedKind = (token) => {
  if (typeof token !== 'string') {
    return null;
  }
  if (GENERIC[token]) {
    return GENERIC[token];
  }
  const kind = token.split(':')[0];
  return token.includes(':') && (kind === 'field' || kind === 'region')
    ? kind
    : null;
};

/**
 * The distinct slot kinds a part claims, in authored order (empty for a
 * part with no slots). Stamped on the part's shell row as `claims`: the
 * row's structural meaning, read where the row is used (the drop resolver
 * treats the shell that claims the root's sections as the root flow's
 * drop surface, because the sections are its rows).
 */
const partClaims = (part) =>
  Array.from(
    new Set(
      normalizeContains(part.contains)
        .map(claimedKind)
        .filter((kind) => kind !== null),
    ),
  );

/**
 * Collects a declaration tree's slot claims (declaration build validation
 * has vouched for the grammar: unique classes, one generic bucket per kind,
 * no child claimed twice — first-wins here only tolerates degraded
 * payloads).
 *
 * @return {{specific: Map<string, string>, generic: Map<string, string>}}
 *   Token → claiming part selector: specific keys are the full tokens
 *   ('field:field_title'), generic keys the child kind ('field', 'region',
 *   'value', 'section').
 */
const collectClaims = (declaration) => {
  const specific = new Map();
  const generic = new Map();
  const walk = (part) => {
    const elements = part?.elements;
    if (!isPlainObject(elements)) {
      return;
    }
    for (const [selector, child] of Object.entries(elements)) {
      if (!isPlainObject(child) || !PART_SELECTOR.test(selector)) {
        continue;
      }
      for (const token of normalizeContains(child.contains)) {
        if (typeof token !== 'string') {
          continue;
        }
        if (GENERIC[token] && !generic.has(GENERIC[token])) {
          generic.set(GENERIC[token], selector);
        } else if (
          (token.startsWith('field:') || token.startsWith('region:')) &&
          !specific.has(token)
        ) {
          specific.set(token, selector);
        }
      }
      walk(child);
    }
  };
  walk(declaration);
  return { specific, generic };
};

/**
 * Builds a declaration's shell rows, nested as authored, with each part's
 * claimed children mounted inside it (after its own nested shells).
 *
 * Placement gate: a part the model channel places anywhere but as its own
 * element (`placement(parentKey, selector)` answering 'root' or `false`)
 * mounts no row; its mounted children (nested shells, gated on their own
 * placement, and any claimed model rows) hoist to its position, so the
 * gate hides wrapper rows only, never model identities. Anything else
 * ('element', a null channel, a key the model does not speak for) mounts:
 * the sketch degrades to declarations alone, never to an emptier tree
 * than the declarations promise.
 */
const shellRows = (declaration, parentKey, assigned, placement) => {
  const rows = [];
  const elements = declaration?.elements;
  if (!isPlainObject(elements)) {
    return rows;
  }
  for (const [selector, part] of Object.entries(elements)) {
    if (!isPlainObject(part) || !PART_SELECTOR.test(selector)) {
      continue;
    }
    const children = [
      ...shellRows(part, parentKey, assigned, placement),
      ...(assigned.get(selector) ?? []),
    ];
    const placed = placement ? placement(parentKey, selector) : null;
    if (placed === 'root' || placed === false) {
      rows.push(...children);
      continue;
    }
    const row = {
      type: 'shell',
      selector,
      label: part.label ?? selector.replace(/^\./, ''),
      parentKey,
      children,
    };
    if (typeof part.tag === 'string') {
      row.declaredTag = part.tag;
    }
    const claims = partClaims(part);
    if (claims.length > 0) {
      row.claims = claims;
    }
    rows.push(row);
  }
  return rows;
};

/**
 * Substitutes an element's children into its shell's slots.
 *
 * @param {?object} declaration
 *   The element's effective anatomy declaration (its shell).
 * @param {?string} ownerKey
 *   The element's selectable key (shell rows' parentKey).
 * @param {Array<{slotKind: ?string, name: ?string, node: object}>} entries
 *   The model children in blueprint order: slotKind is the claimable kind
 *   ('field' | 'region' | 'value' | 'section', null for unclaimable rows),
 *   name the specific-claim discriminator (a field or region name; null
 *   for a section, which only the generic bucket claims).
 *
 * @param {?function} placement
 *   The part placement channel (view.placement), handed through to
 *   shellRows' gate. Null gates nothing.
 *
 * @return {object[]}
 *   The element's composed children: shell rows in authored order, then
 *   the unclaimed children (the residual-of-residual, mounting at root).
 */
const mount = (declaration, ownerKey, entries, placement = null) => {
  const { specific, generic } = collectClaims(declaration);
  const assigned = new Map();
  const unclaimed = [];
  for (const { slotKind, name, node } of entries) {
    let selector = null;
    if (slotKind) {
      if (name != null) {
        selector = specific.get(`${slotKind}:${name}`) ?? null;
      }
      selector ??= generic.get(slotKind) ?? null;
    }
    if (selector) {
      if (!assigned.has(selector)) {
        assigned.set(selector, []);
      }
      assigned.get(selector).push(node);
    } else {
      unclaimed.push(node);
    }
  }
  return [
    ...shellRows(declaration, ownerKey, assigned, placement),
    ...unclaimed,
  ];
};

/**
 * Depth-first search for one composed row, returning its position so the
 * absorption passes can replace it in place.
 */
const findRow = (nodes, predicate) => {
  for (let index = 0; index < (nodes?.length ?? 0); index++) {
    const node = nodes[index];
    if (predicate(node)) {
      return { parent: nodes, index, node };
    }
    const found = findRow(node.children, predicate);
    if (found) {
      return found;
    }
  }
  return null;
};

/**
 * A field row: the field's model identity, which with shells on is also the
 * .field--name-X wrapper element (one element, one row). Its content — one
 * value row for a single scalar, model rows for inline entities — mounts
 * into the field kind's shell at the `value` slot.
 */
const composeField = (
  key,
  name,
  value,
  context,
  presentation = 'open',
  shells = context.targets,
) => {
  if (isFormOnlyStub(value)) {
    // A form-only stub row presents under its definition label and
    // offers only the Edit verb, the built-in projection's rule verbatim;
    // with shells on, its field kind's sketch still mounts around the
    // (empty) value slot like any other field's. A field is what it is
    // called: its one word is both its label and its type.
    const label = value._label || humanize(name);
    const node = {
      type: 'model',
      key,
      kind: 'field',
      label,
      elementType: label,
      presentation,
      formOnly: true,
      children: [],
    };
    if (shells) {
      node.markupClass = fieldClass(name);
      node.children = mount(
        context.anatomy?.kinds?.field ?? {},
        key,
        [],
        context.placement,
      );
    }
    return node;
  }
  const values = Array.isArray(value) ? value : [value];
  const entityChildren = values
    .filter(isInlineEntity)
    .map((entity) => composeEntity(entity, context));
  const label = humanize(name);
  const node = {
    type: 'model',
    key,
    kind: 'field',
    label,
    elementType: label,
    presentation,
    children: entityChildren,
  };
  if (!shells) {
    return node;
  }
  node.markupClass = fieldClass(name);
  const entries = entityChildren.map((child) => ({
    slotKind: 'value',
    name: null,
    node: child,
  }));
  if (
    entityChildren.length === 0 &&
    values.length === 1 &&
    textExcerpt(values[0]) !== null
  ) {
    entries.push({
      slotKind: 'value',
      name: null,
      node: {
        type: 'value',
        key: null,
        label: textExcerpt(values[0]),
        children: [],
      },
    });
  }
  node.children = mount(
    context.anatomy?.kinds?.field ?? {},
    key,
    entries,
    context.placement,
  );
  return node;
};

/**
 * An inline entity (paragraph) row: a full model identity that composes
 * recursively; its fields mount into the entity kind's shell. Inline
 * entities carry no `_element` annotation (the projection reads none
 * either), so their fields exist and present open, and their words are
 * the entity's own label and its humanized bundle.
 */
const composeEntity = (entity, context) => {
  const key = `entity:${entity.uuid}`;
  const entries = Object.entries(entity.fields ?? {}).map(([name, value]) => ({
    slotKind: 'field',
    name,
    node: composeField(`field:${entity.uuid}:${name}`, name, value, context),
  }));
  const type = humanize(entity.type ?? 'entity');
  return {
    type: 'model',
    key,
    kind: 'entity',
    label: entity.label ?? type,
    elementType: type,
    bundle: entity.type ?? null,
    presentation: 'open',
    children: context.targets
      ? mount(
          context.anatomy?.kinds?.entity ?? {},
          key,
          entries,
          context.placement,
        )
      : entries.map((entry) => entry.node),
  };
};

/**
 * Absorbs the model identities a merge takes besides its target: every
 * keyed row in the subtree outside `spared` (the target row's subtree, or
 * the merged row standing in its place) presents under its markup class,
 * flagged `absorbed`, its own descendants included. Shells stay: the
 * wrapper elements are real markup, only their identities merged away.
 */
const absorbSiblings = (rows, spared) => {
  for (const row of rows) {
    if (row === spared) {
      continue;
    }
    if (row.type === 'model' && row.key && !row.absorbed) {
      row.absorbed = true;
      row.label = row.markupClass ?? row.label;
    }
    absorbSiblings(row.children, spared);
  }
};

/**
 * Folds the model identities a collapsed merge takes besides its target:
 * with no wrapper element of their own (the collapse merged the sandwich
 * into the single tag), sibling field rows vanish outright, the data
 * tree's rule; shells and the target's spared descendants stay.
 */
const foldSiblings = (rows, spared) =>
  rows.flatMap((row) => {
    if (spared.has(row)) {
      return [row];
    }
    if (row.type === 'model' && row.key) {
      return [];
    }
    row.children = foldSiblings(row.children, spared);
    return [row];
  });

/**
 * The merged-presentation absorption on one composed block subtree.
 *
 * @return {object[]}
 *   The rows replacing the block row at its position.
 */
const absorb = (blockNode, component, context) => {
  const element = component._element ?? {};
  const presentation = element.presentation ?? {};
  const target = presentation.edit_target ?? null;
  // The merged row wears the block's delivered words (the annotator's
  // excerpt of the absorbed content as its name), so the data view and the
  // markup view name the row alike by construction.
  const merged = {
    type: 'model',
    key: blockNode.key,
    kind: 'block',
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    bundle: component.block_type ?? null,
    presentation: 'merged',
    editTarget: target,
    // The stored configuration label, the rename input's seed: the merged
    // row names itself by its content, never by this (rename-commit.js).
    customLabel: component.label ?? '',
    can: element.can ?? null,
    children: [],
  };

  const targetKey = target ? `${blockNode.key}:${target}` : null;
  const targetRow =
    targetKey === null
      ? null
      : findRow(
          blockNode.children,
          (node) => node.type === 'model' && node.key === targetKey,
        );
  if (!targetRow) {
    // No absorbable target (edit_target null or missing): the merged row
    // takes the block position, keeping the composed markup (shells) when
    // shells are on — today's empty merged row when they are off — with
    // every field identity inside absorbed (the data tree keeps none).
    merged.children = context.targets ? blockNode.children : [];
    absorbSiblings(merged.children, null);
    return [merged];
  }

  if (!context.targets) {
    // The data tree: nothing stands between the block row and its target,
    // so the innermost absorbed position IS the block position — today's
    // merged row, keeping the absorbed node's children (paragraph rows
    // survive the merge).
    merged.children = targetRow.node.children;
    return [merged];
  }

  if (component._element?.composed === true) {
    // Collapsed: the block root IS the content element, so the innermost
    // absorbed position is the block position itself (the data-tree rule),
    // while the type's declared part rows, real interior elements of that
    // single tag, stay on as the merged row's children.
    const spared = new Set(targetRow.node.children);
    targetRow.parent.splice(targetRow.index, 1, ...targetRow.node.children);
    merged.children = foldSiblings(blockNode.children, spared);
    return [merged];
  }

  const valueRow = findRow(
    targetRow.node.children,
    (node) => node.type === 'value',
  );
  if (valueRow) {
    // The merged row sits where its value element lives. The target field's
    // wrapper element remains in the markup above it; its model identity
    // was absorbed, so the row presents under its markup class.
    valueRow.parent[valueRow.index] = merged;
    targetRow.node.absorbed = true;
    targetRow.node.label = targetRow.node.markupClass ?? targetRow.node.label;
    absorbSiblings(blockNode.children, targetRow.node);
  } else {
    // No distinct value element (entity-valued or multi-value target): the
    // innermost absorbed position is the target row itself.
    merged.children = targetRow.node.children;
    targetRow.parent[targetRow.index] = merged;
    absorbSiblings(blockNode.children, merged);
  }
  // The block row vanishes; its composed children (the shell chain) hoist
  // to its position.
  return blockNode.children;
};

/**
 * A component's rows: the block model identity with its fields mounted into
 * its type's shell — or, merged and collapsed cases aside, whatever the
 * presentation pass leaves at the block's position.
 *
 * @return {object[]}
 *   One block row normally; the hoisted shell chain after absorption.
 */
const composeComponent = (component, context) => {
  const key = `block:${component.uuid}`;
  const bundle = component.block_type ?? null;
  const element = component._element ?? {};
  const fieldFacts = element.fields ?? {};
  // A collapsed block renders its wrapper chain to a single tag (the
  // annotation delivers the block-type declaration per instance), so the
  // wrapper shells never apply: no field shells, no value rows. Its type's
  // own declared elements still mount below.
  const collapsed = element.composed === true;
  const fieldShells = context.targets && !collapsed;
  const presentation = element.presentation ?? { mode: 'open' };

  // Existence first: an `element: false` field never renders as an
  // attributed element, so no canvas element answers to its key — folded in
  // every view.
  const entries = Object.entries(component.block_fields ?? {})
    .filter(([name]) => fieldExists(fieldFacts, name))
    .map(([name, value]) => ({
      slotKind: 'field',
      name,
      node: composeField(
        `${key}:${name}`,
        name,
        value,
        context,
        fieldFacts[name]?.presentation,
        fieldShells,
      ),
    }));

  // A rendered label (heading-style blocks) is a real element with its own
  // selection key. It is not a field, so no slot can claim it: root mount.
  // label_rendered is the lean node's derived boolean, present only when
  // the label renders as page content (projection.js applies the same
  // rule to the data tree's row).
  if (component.label && component.label_rendered) {
    entries.unshift({
      slotKind: null,
      name: null,
      node: {
        type: 'model',
        key: `${key}:label`,
        kind: 'field',
        label: 'Label',
        presentation: 'open',
        children: [],
      },
    });
  }

  // A door's fills (slot-fills.js): the page's own sections filling the
  // slot this block declares, section rows under the door as the canvas
  // renders them. A `section` entry a block's declaration never claims,
  // so they mount at the door's root after its shells.
  for (const fill of context.fillsOf(component)) {
    entries.push({
      slotKind: 'section',
      name: null,
      node: composeSection(fill, context),
    });
  }

  // What the door shows by reference (shown-rows.js): one shown-document
  // row after the fills. Unclaimable (no slot kind), so it mounts at the
  // door's root after its shells, the same position a fill takes.
  for (const row of shownRows(component)) {
    entries.push({ slotKind: null, name: null, node: { type: 'shown', ...row } });
  }

  // Plugin blocks (site branding, menus) resolve by BASE plugin id: all
  // derivatives of a plugin share one template, so the payload keys on the
  // id with any :derivative suffix stripped. A field node names the page's
  // field, never a per-bundle plugin id; its base is field_block
  // (NodeTreeBuilder::pluginBase, the server's one derivation).
  const basePluginId = component.field
    ? 'field_block'
    : component.plugin_id
      ? component.plugin_id.split(':')[0]
      : null;
  // A collapsed type's declared elements are interior parts of its single
  // tag, so they mount even collapsed; only the block kind's fallback tree
  // stays out (it describes the wrapper sandwich collapse merges away).
  const declaration = context.targets
    ? (bundle && context.anatomy?.blockTypes?.[bundle]) ||
      (basePluginId && context.anatomy?.blockPlugins?.[basePluginId]) ||
      (collapsed ? null : context.anatomy?.kinds?.block) ||
      {}
    : {};
  const children = context.targets
    ? mount(declaration, key, entries, context.placement)
    : entries.map((entry) => entry.node);

  const node = {
    type: 'model',
    key,
    kind: 'block',
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    bundle,
    presentation: presentation.mode,
    // The slot a door declares (the lean node's `slot` on a slot block): the
    // tree stamps it as data-tree-door, the drop resolver's door fact.
    slot: component.slot ?? null,
    // The stored configuration label (the lean node's sparse `label`), the
    // rename input's seed.
    customLabel: component.label ?? '',
    // The spine's verdict on the element (`_element.can`), the fact the
    // client gate reads (selection/capable.js).
    can: element.can ?? null,
    children,
  };
  if (presentation.mode === 'merged') {
    return absorb(node, component, context);
  }
  return [node];
};

/**
 * A lean-tree child's rows: a container node recurses as a SECTION —
 * sections all the way down, one node shape at every depth — anything
 * else composes as a component. (Region nodes never reach here;
 * composeSection unwraps them.)
 */
const composeTreeChild = (child, context) =>
  child.kind === 'container'
    ? [composeSection(child, context)]
    : composeComponent(child, context);

/**
 * A section row — top-level or nested, one shape, from a lean container
 * node. A multi-region layout's children are region nodes: region rows
 * (more than one region) or the lone region's components themselves (a
 * one-region section's region level is pure noise — they occupy the
 * region position, so `regions` and `region:NAME` slots still claim
 * them) mount into the layout's shell. A container layout's ordered
 * `children` compose exactly like the one-region case: its entries
 * occupy the region position (a `regions` bucket claims them into
 * whichever part declares it) with no region name to bind a specific
 * claim. A section is ONE element at every depth — its declaration
 * mounts the same rows nested as at root (the root's own tag swap is the
 * annotation's business, not the sketch's). Its words are the
 * annotation's: `name` (the custom label when renamed, else the type
 * word) and `type` ("Section" at page depth, "Container" nested);
 * `customLabel` is the raw stored label the rename input seeds from.
 */
const composeSection = (section, context) => {
  const key = section.uuid ? `container:${section.uuid}` : null;
  const element = section._element ?? {};
  const list = section.children ?? [];
  const regions = list.filter((child) => child.kind === 'region');
  let entries;
  if (regions.length > 1) {
    entries = regions.map((region) => ({
      slotKind: 'region',
      name: region.region,
      node: {
        type: 'model',
        key: null,
        kind: 'region',
        label: humanize(region.region),
        presentation: 'open',
        children: (region.children ?? []).flatMap((child) =>
          composeTreeChild(child, context),
        ),
      },
    }));
  } else {
    const [region = null] = regions;
    entries = (region ? (region.children ?? []) : list)
      .flatMap((child) => composeTreeChild(child, context))
      .map((node) => ({
        slotKind: 'region',
        name: region?.region ?? null,
        node,
      }));
  }

  const declaration = context.targets
    ? (section.layout_id && context.anatomy?.layouts?.[section.layout_id]) ||
      context.anatomy?.kinds?.container ||
      {}
    : {};
  return {
    type: 'model',
    key,
    kind: 'container',
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    customLabel: section.label ?? '',
    presentation: 'open',
    can: element.can ?? null,
    children: context.targets
      ? mount(declaration, key, entries, context.placement)
      : entries.map((entry) => entry.node),
  };
};

/**
 * The root's derived absorption: the entity's label field presents AS the
 * entity row (the root `_element.presentation`'s edit_target — the same
 * rule the built-in projection applies). The root row cannot relocate the
 * way a merged block row lands at its innermost absorbed position, so the
 * fold runs toward the top: in the data tree the target field row vanishes
 * and its children hoist (the projection verbatim); in the markup view the
 * field's WRAPPER element is still real markup, so its row stays —
 * absorbed, presenting under its markup class — while its value row folds
 * (the root row already presents the content as its live label).
 */
const absorbRootTarget = (rootNode, targetKey, context) => {
  const targetRow = findRow(
    rootNode.children,
    (node) => node.type === 'model' && node.key === targetKey,
  );
  if (!targetRow) {
    return;
  }
  if (!context.targets) {
    targetRow.parent.splice(targetRow.index, 1, ...targetRow.node.children);
    return;
  }
  const valueRow = findRow(
    targetRow.node.children,
    (node) => node.type === 'value',
  );
  if (valueRow) {
    valueRow.parent.splice(valueRow.index, 1);
    targetRow.node.absorbed = true;
    targetRow.node.label = targetRow.node.markupClass ?? targetRow.node.label;
  } else {
    // No distinct value element (entity-valued target): nothing but the
    // field's own row stands in for the content, so it folds entirely,
    // children hoisted — the data-tree rule.
    targetRow.parent.splice(targetRow.index, 1, ...targetRow.node.children);
  }
};

/**
 * Composes the page's markup model from the blueprint mirror and the
 * anatomy payload.
 *
 * @param {object} blueprint
 *   The delivered blueprint mirror of the root entity, the `_element`
 *   annotations riding it.
 * @param {object} anatomy
 *   The anatomy payload (drupalSettings.daedalusUi.anatomy): the
 *   declarations' elements trees per kind, block type, and layout.
 * @param {object} view
 *   The view state:
 *   - root: the root identity envelope ({uuid}) — the mirror itself does
 *     not carry the root entity's uuid (its words ride the mirror's own
 *     `_element`).
 *   - targets: the markup-view toggle — shells, markup identities and
 *     value rows compose in. Off = the data tree.
 *   - placement: the part placement channel, `(ownerKey, selector) =>
 *     'element'|'root'|false|null` (markup-index.js partPlacement over
 *     the annotation). A shell row mounts only when its part is its own
 *     element: 'root' and `false` answers hide the row, its children
 *     hoisting (see the placement-gate rule above); anything else, and a
 *     null channel (node tests, data-only callers), mounts from
 *     declarations alone.
 *
 * @return {object}
 *   The root model row of the composed tree.
 */
export const compose = (blueprint, anatomy, view = {}) => {
  const { root = null, targets = false, placement = null } = view;
  // The root's field map rides the context: a field-block placement's
  // merged row labels itself from the root field it presents.
  // A locked page's slot fills leave the root list for their doors
  // (slot-fills.js); composeComponent mounts them under the door.
  const { roots, fillsOf } = pairSlotFills(blueprint?.layout);
  const context = {
    anatomy: anatomy ?? {},
    targets,
    placement,
    rootFields: blueprint?.fields ?? {},
    fillsOf,
  };
  const uuid = root?.uuid ?? null;
  const key = uuid ? `entity:${uuid}` : null;
  const element = blueprint?._element ?? {};
  const fieldFacts = element.fields ?? {};
  const presentation = element.presentation ?? { mode: 'open' };
  const editTarget = presentation.edit_target ?? null;
  const entries = Object.entries(blueprint?.fields ?? {})
    .filter(([name]) => fieldExists(fieldFacts, name))
    .map(([name, value]) => ({
      slotKind: 'field',
      name,
      node: composeField(
        uuid ? `field:${uuid}:${name}` : null,
        name,
        value,
        context,
        fieldFacts[name]?.presentation,
      ),
    }));
  // The root's section list mounts through the same slot machinery as its
  // fields: a `sections` bucket (daedalus_layout's layout root) claims every
  // section row; unclaimed, they mount at the entity root after the
  // fields, the plain tree's order. The structure is the lean node tree
  // (`layout`), the one shape projection.js reads too.
  for (const section of roots) {
    entries.push({
      slotKind: 'section',
      name: null,
      node: composeSection(section, context),
    });
  }
  const children = targets
    ? mount(
        context.anatomy?.kinds?.entity ?? {},
        key,
        entries,
        context.placement,
      )
    : entries.map((entry) => entry.node);
  const node = {
    type: 'model',
    key,
    kind: 'entity',
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    bundle: blueprint?.bundle ?? null,
    presentation: 'open',
    editTarget,
    can: element.can ?? null,
    children,
  };
  if (editTarget && uuid) {
    absorbRootTarget(node, `field:${uuid}:${editTarget}`, context);
  }
  return node;
};
