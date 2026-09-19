/**
 * Projects the blueprint mirror into the page-elements tree.
 *
 * The mirror is the model of record (one shape, consumers project);
 * this is the panel's projection: a tree of {key, label, kind, bundle?,
 * children, presentation, editTarget?, formOnly?} nodes whose keys are
 * selectable keys, so a row and its canvas element are the same identity
 * and selection sync is string equality. Bundle-bearing nodes (blocks,
 * entities) carry their bundle machine name as a fact: the type resolver
 * (selection/type-for-key.js) derives its wording from it.
 *
 * The element model is consumed HERE, from the delivered blueprint's
 * uniform `_element` annotation on every owning node, the root included,
 * never from the DOM: the canvas resolver reads the same model's
 * data-presentation attributes, so panel and canvas agree by construction.
 * The annotation mirrors the declaration grammar:
 * - `_element.presentation` ({mode, label?, edit_target?}): merged
 *   component nodes absorb their wrapper chain into ONE row keyed by the
 *   block placement (the outermost carrier) — the row keeps the absorbed
 *   content node's children, presents under the annotation's label, and
 *   routes the Edit verb to the annotation's edit_target. The ROOT node's
 *   edit_target is the derived entity rule (the entity's label field
 *   presents AS the entity row): the label field's row folds into the root
 *   row, which shows its text live and renames it (the Rename verb). The
 *   root's Edit is the entity-form panel, never a routed leaf.
 * - `_element.fields` ({name: {element: false} | {presentation: 'unit'}}):
 *   `element: false` is the EXISTENCE axis — nothing answers to the key in
 *   any view, so the row folds unconditionally; `presentation` is the
 *   field's own mode (absent = open).
 *
 * Keys follow the blueprint's addressing: inline-block fields are qualified
 * by the COMPONENT uuid (block:{component_uuid}:{name}), inline entities
 * (paragraphs) by their ENTITY uuid — the same rules attribution stamps
 * into the canvas.
 */

import { isFormOnlyStub } from '../../blueprint/form-only-stub.js';

const humanize = (machineName) => {
  const words = String(machineName)
    .replace(/^(field_|layout_|daedalus_stylish_)/, '')
    .replace(/[_-]+/g, ' ')
    .trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

/**
 * A region entry is a nested SECTION node when it carries the section node
 * shape (layout_id / regions) instead of a component's plugin identity —
 * the serializer's one grammar: a section nested in a region IS a section,
 * identical to a top-level row, recursively. CLASSIC-shape helper: the
 * projection itself reads the lean `_tree` (kind-discriminated nodes);
 * these stay exported for consumers still walking the classic
 * layout_builder__layout shape (compose.js).
 */
export const isSectionNode = (node) =>
  !!node &&
  typeof node === 'object' &&
  ((typeof node.layout_id === 'string' && node.layout_id !== '') ||
    (!!node.regions && typeof node.regions === 'object'));

/**
 * A container section's ordered component list, or null for the regions
 * shape. Container sections (single vestigial storage region — the
 * serializer's container shape) carry `children: []` in place of
 * `regions: {}`; the two never coexist on one node. Classic-shape helper,
 * like isSectionNode above.
 */
export const sectionChildren = (section) =>
  Array.isArray(section?.children) ? section.children : null;

/**
 * A section's display name: its user-facing label (layout_settings.label,
 * surfaced by the serializer as the node's top-level `label`) when named,
 * the humanized layout otherwise ("Twocol" until renamed).
 */
export const sectionLabel = (section) =>
  section.label ?? humanize(section.layout_id ?? 'section');

/**
 * A merged row's live label: the edit-target field's own text.
 *
 * A merged row IS its content (the heading precedent — there the displayed
 * label is the content and the row shows it), so when the edit target holds
 * plain text the panel shows what the canvas shows, live against the
 * mirror. Non-string values (link objects, references, multi-value arrays)
 * and empty strings fall through to the static label. Clamped to the first
 * line, 80 chars: a string_long can hold paragraphs, and the row is a
 * name, not a preview.
 */
const contentLabel = (value) => {
  if (typeof value !== 'string' || value.trim() === '') {
    return null;
  }
  const line = value.trim().split('\n')[0];
  return line.length > 80 ? `${line.slice(0, 79)}…` : line;
};

/**
 * An inline-serialized entity (a paragraph or other owned reference):
 * distinguishable from shared-reference values ({target_id, _label}) by
 * carrying its own uuid.
 */
const isInlineEntity = (value) =>
  !!value && typeof value === 'object' && !Array.isArray(value) && !!value.uuid;

const inlineEntityNode = (entity) => ({
  key: `entity:${entity.uuid}`,
  label: entity.label ?? humanize(entity.type ?? 'entity'),
  kind: 'entity',
  bundle: entity.type ?? null,
  presentation: 'open',
  children: Object.entries(entity.fields ?? {}).map(([name, value]) =>
    fieldNode(`field:${entity.uuid}:${name}`, name, value),
  ),
});

/**
 * A field row. Inline-entity values (single or multi) become child rows;
 * scalar and shared-reference values are leaves: their editing surface is
 * the canvas or the field's widget, not the tree. A form-only stub row
 * presents under its definition label and offers ONLY the Edit verb
 * (formOnly), since the entity form is its one editing surface. The
 * presentation mode arrives from the owning node's `_element.fields` map
 * (annotation absent = open); 'unit' rows start folded in the tree.
 */
const fieldNode = (key, name, value, presentation = 'open') => {
  if (isFormOnlyStub(value)) {
    return {
      key,
      label: value._label || humanize(name),
      kind: 'field',
      presentation,
      formOnly: true,
      children: [],
    };
  }
  const values = Array.isArray(value) ? value : [value];
  return {
    key,
    label: humanize(name),
    kind: 'field',
    presentation,
    children: values.filter(isInlineEntity).map(inlineEntityNode),
  };
};

/**
 * A field row exists when the owner's annotation doesn't say otherwise.
 *
 * A nonexistent field (`element: false` — declared metadata like
 * block_content's info, or inferred: a display-hidden block field, a
 * non-preserved root field on an LB display) never renders as an
 * attributed element, so no canvas element answers to its key — no
 * outline, no styling surface, no edit path. Its row would be a
 * mirror-only selectable in ANY view — the projection renders capability,
 * not data.
 */
const fieldExists = (facts, name) => facts[name]?.element !== false;

const componentNode = (component) => {
  const element = component._element ?? {};
  const fieldFacts = element.fields ?? {};
  const presentation = element.presentation ?? { mode: 'open' };

  // MERGED: a container whose meaningful content is exactly one thing
  // presents AS that one thing: one row, keyed by the block placement so
  // move/trash/duplicate/drag all arm, labeled from the annotation (the
  // absorbed content's definition label; null keeps the component's own
  // label, the heading case, where the displayed label IS the content),
  // with the absorbed node's children retained (a single multi-value
  // paragraph field's paragraph rows survive the merge).
  if (presentation.mode === 'merged') {
    const target = presentation.edit_target ?? null;
    let children = [];
    if (
      target &&
      target !== 'label' &&
      component.block_fields?.[target] !== undefined
    ) {
      children = fieldNode(
        `block:${component.uuid}:${target}`,
        target,
        component.block_fields[target],
        fieldFacts[target]?.presentation,
      ).children;
    }
    return {
      key: `block:${component.uuid}`,
      label:
        contentLabel(target ? component.block_fields?.[target] : null) ??
        presentation.label ??
        component.label ??
        humanize(component.block_type ?? 'block'),
      kind: 'block',
      bundle: component.block_type ?? null,
      presentation: 'merged',
      editTarget: target,
      children,
    };
  }

  const children = Object.entries(component.block_fields ?? {})
    .filter(([name]) => fieldExists(fieldFacts, name))
    .map(([name, value]) =>
      fieldNode(
        `block:${component.uuid}:${name}`,
        name,
        value,
        fieldFacts[name]?.presentation,
      ),
    );

  // A rendered label (heading-style blocks) is a selection node on canvas
  // (block:{uuid}:label — the `label` placement property), so it gets a row.
  // label_rendered is the lean node's derived boolean (present only when
  // the label renders as page content), replacing the classic shape's raw
  // 'visible'/'0' label_display spelling.
  if (component.label && component.label_rendered) {
    children.unshift({
      key: `block:${component.uuid}:label`,
      label: 'Label',
      kind: 'field',
      presentation: 'open',
      children: [],
    });
  }

  return {
    key: `block:${component.uuid}`,
    label: component.label ?? humanize(component.block_type ?? 'block'),
    kind: 'block',
    bundle: component.block_type ?? null,
    presentation: presentation.mode,
    children,
  };
};

/**
 * A lean-tree child's row: containers recurse as SECTIONS — one node shape
 * at every depth — anything else is a component. (Region nodes never reach
 * here; sectionNode unwraps them.)
 */
const treeChildNode = (child) =>
  child.kind === 'container' ? sectionNode(child) : componentNode(child);

/**
 * A section row — top-level or nested, one shape, from a lean container
 * node. A container layout's ordered `children` land directly under the
 * section row. A multi-region layout's children are region nodes; region
 * rows only appear when there is more than one — a lone region's grouping
 * is pure noise, so its components collapse into the section row's
 * children. Region rows carry NO key (regions are not addressable
 * elements — no selection, no styling, no drop identity); they remain
 * pure grouping chrome until the multi-region layouts retire. (Without
 * daedalus_layout sections have no uuid; such a row still renders, unselectable,
 * so its blocks stay reachable.) The row carries the raw custom label
 * (customLabel) so the tree's rename affordance can seed its input with
 * what is actually stored, not the layout-derived fallback.
 */
const sectionNode = (section) => {
  const list = section.children ?? [];
  const regions = list.filter((child) => child.kind === 'region');
  const children =
    regions.length > 1
      ? regions.map((region) => ({
          key: null,
          label: humanize(region.region),
          kind: 'region',
          presentation: 'open',
          children: (region.children ?? []).map(treeChildNode),
        }))
      : (regions.length === 1 ? (regions[0].children ?? []) : list).map(
          treeChildNode,
        );

  return {
    key: section.uuid ? `container:${section.uuid}` : null,
    label: sectionLabel(section),
    customLabel: section.label ?? '',
    kind: 'container',
    presentation: 'open',
    children,
  };
};

/**
 * Projects the mirror into the tree's root node.
 *
 * The root consumes its own `_element` annotation like any other owning
 * node: nonexistent fields fold in every view, and the derived edit target
 * (the entity's label field presents AS the entity row) absorbs the label
 * field's row into the root row — the root shows its text live and renames
 * it, the same absorption a merged block row performs (the root's Edit
 * verb opens the entity-form panel; no leaf route is involved).
 *
 * @param {Object} root
 *   The root identity envelope from the blueprint slice.
 * @param {Object} blueprint
 *   The full blueprint of the root entity.
 *
 * @return {Object}
 *   The root tree node.
 */
export const projectTree = (root, blueprint) => {
  const element = blueprint._element ?? {};
  const fieldFacts = element.fields ?? {};
  const presentation = element.presentation ?? { mode: 'open' };
  const editTarget = presentation.edit_target ?? null;

  const children = [];
  Object.entries(blueprint.fields ?? {})
    .filter(([name]) => fieldExists(fieldFacts, name))
    .forEach(([name, value]) => {
      const node = fieldNode(
        `field:${root.uuid}:${name}`,
        name,
        value,
        fieldFacts[name]?.presentation,
      );
      if (name === editTarget) {
        // The absorbed label field: its row folds into the root row, its
        // children (if any) survive the merge.
        children.push(...node.children);
        return;
      }
      children.push(node);
    });
  // Layout structure projects from the lean node tree (`_tree`), the
  // spine's canonical page representation delivered on every blueprint:
  // kind-discriminated container/region/block nodes, one child axis, styles
  // on the node, `_element` riding along.
  (blueprint._tree ?? []).forEach((section) => {
    children.push(sectionNode(section));
  });

  return {
    key: `entity:${root.uuid}`,
    label:
      (editTarget ? contentLabel(blueprint.fields?.[editTarget]) : null) ??
      (root.label || humanize(blueprint.bundle ?? 'page')),
    kind: 'entity',
    bundle: blueprint.bundle ?? null,
    presentation: 'open',
    editTarget,
    children,
  };
};
