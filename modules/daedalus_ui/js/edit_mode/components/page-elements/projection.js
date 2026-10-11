/**
 * Projects the blueprint mirror into the page-elements tree.
 *
 * The mirror is the model of record (one shape, consumers project);
 * this is the panel's projection: a tree of {key, label, elementType,
 * icon, kind, bundle?, children, presentation, editTarget?, formOnly?} nodes
 * whose keys are selectable keys, so a row and its canvas element are the
 * same identity and selection sync is string equality. Bundle-bearing
 * nodes (blocks, entities) carry their bundle machine name as a fact.
 *
 * The element model is consumed HERE, from the delivered blueprint's
 * uniform `_element` annotation on every owning node, the root included,
 * never from the DOM: the canvas resolver reads the same model's
 * data-presentation attributes, so panel and canvas agree by construction.
 * The annotation mirrors the declaration grammar:
 * - `_element.name` and `_element.type`: the element-naming spine, two
 *   translated words minted by the server (BlueprintElementAnnotator) on
 *   the root, every section at every depth and every component. A row's
 *   `label` IS `name` (what the element says: a section's custom label
 *   else its type word, a merged block's content excerpt, the root's
 *   entity label) and its `elementType` IS `type` (what it is: "Section"
 *   at page depth, "Container" nested, a block type's label, the root's
 *   bundle label). Nothing here derives a word for an annotated node: the
 *   label resolver and the type resolver (selection/label-for-key.js,
 *   type-for-key.js) read these two row facts through the projection
 *   index, so the tree, the chips, the breadcrumb and the AI's chips can
 *   never word one element two ways. Rows the annotator does not name
 *   (field rows, region rows, inline entities) humanize their own machine
 *   name, the spine key's vocabulary.
 * - `_element.icon`: the third minted word, what the element is as a
 *   glyph (a URL, or null when nothing answered the icon collector). A
 *   row's `icon` IS it; the tree draws it in the row's icon zone and
 *   falls back to the row's kind glyph (row-glyphs.js) for a null and for
 *   the rows the annotator does not name.
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
 *   the entity's own render, so the field's row folds unconditionally;
 *   `presentation` is the field's own mode (absent = open).
 * - `_element.can` ({content, configure, style, arrange, insert} to
 *   booleans): the spine's verdict on the node for the actor the read was
 *   made for, copied from the serializer's `_verdict` stamp. The row
 *   carries it as `can` and the projection index on every address (field
 *   rows and parts inheriting their element's), the one fact the client
 *   gate reads (selection/capable.js) for verbs, drags, drops, rename,
 *   paste and the inspector. A root with no fields (a template host's
 *   canvas) projects its root row from `_element` alone, with the
 *   sections as its only children.
 * - `_element.presented_field` (a `field:{root}:{name}` key): a Layout
 *   Builder FIELD BLOCK placement presents that root field (its
 *   presentation is MERGED into it, edit_target the field name). The
 *   placement row and the field row fold as ONE row keyed by the
 *   placement, labeled live from the root field's value, exactly as a
 *   merged inline block folds into its content; the field's own row under
 *   the root, when the root display also renders it (core's own display
 *   rendering the field beside a field block placing it), stays, two rows
 *   for two renders.
 *
 * Keys follow the blueprint's addressing: inline-block fields are qualified
 * by the COMPONENT uuid (block:{component_uuid}:{name}), inline entities
 * (paragraphs) by their ENTITY uuid — the same rules attribution stamps
 * into the canvas.
 */

import { isFormOnlyStub } from '../../blueprint/form-only-stub.js';
import { pairSlotFills } from './slot-fills.js';
import { shownRows } from './shown-rows.js';

/**
 * The vocabulary for rows the annotator does not name: a field row's
 * machine name, a region's, an inline entity's bundle (paragraphs carry
 * no `_element`). Never an annotated node's word.
 */
const humanize = (machineName) => {
  const words = String(machineName)
    .replace(/^field_/, '')
    .replace(/[_-]+/g, ' ')
    .trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

/**
 * An inline-serialized entity (a paragraph or other owned reference):
 * distinguishable from shared-reference values ({target_id, _label}) by
 * carrying its own uuid.
 */
const isInlineEntity = (value) =>
  !!value && typeof value === 'object' && !Array.isArray(value) && !!value.uuid;

/**
 * An inline entity row. Inline entities carry no `_element` (the
 * annotator walks owning nodes of the root's layout, not paragraphs), so
 * their words are the entity's own label and its humanized bundle.
 */
const inlineEntityNode = (entity) => {
  const type = humanize(entity.type ?? 'entity');
  return {
    key: `entity:${entity.uuid}`,
    label: entity.label ?? type,
    elementType: type,
    kind: 'entity',
    bundle: entity.type ?? null,
    presentation: 'open',
    children: Object.entries(entity.fields ?? {}).map(([name, value]) =>
      fieldNode(`field:${entity.uuid}:${name}`, name, value),
    ),
  };
};

/**
 * A field row. Inline-entity values (single or multi) become child rows;
 * scalar and shared-reference values are leaves: their editing surface is
 * the canvas or the field's widget, not the tree. A form-only stub row
 * presents under its definition label and offers ONLY the Edit verb
 * (formOnly), since the entity form is its one editing surface. The
 * presentation mode arrives from the owning node's `_element.fields` map
 * (annotation absent = open); 'unit' rows start collapsed in the tree. A
 * field is what it is called: its one word is both its label and its
 * type.
 */
const fieldNode = (key, name, value, presentation = 'open') => {
  if (isFormOnlyStub(value)) {
    const label = value._label || humanize(name);
    return {
      key,
      label,
      elementType: label,
      kind: 'field',
      presentation,
      formOnly: true,
      children: [],
    };
  }
  const values = Array.isArray(value) ? value : [value];
  const label = humanize(name);
  return {
    key,
    label,
    elementType: label,
    kind: 'field',
    presentation,
    children: values.filter(isInlineEntity).map(inlineEntityNode),
  };
};

/**
 * The root field a field-block placement presents, from its annotation.
 *
 * @param {Object} component
 *   A block node.
 *
 * @return {?{key: string, name: string}}
 *   The presented field's selectable key and machine name, or null for
 *   any other component.
 */
export const presentedField = (component) => {
  const key = component?._element?.presented_field;
  if (typeof key !== 'string') {
    return null;
  }
  const name = key.split(':')[2];
  return name ? { key, name } : null;
};

/**
 * The value a merged row presents: the presented root field's value for a
 * field block, else the edit target's own block field. Undefined when the
 * target is the placement label or nothing routable.
 *
 * @param {Object} component
 *   A block node with a merged presentation.
 * @param {Object} rootFields
 *   The root blueprint's `fields` map.
 *
 * @return {{key: ?string, name: ?string, value: *}}
 *   The absorbed field's key and name (null when none) and its value.
 */
export const mergedTarget = (component, rootFields) => {
  const presented = presentedField(component);
  if (presented) {
    return { ...presented, value: rootFields?.[presented.name] };
  }
  const target = component._element?.presentation?.edit_target ?? null;
  if (!target || target === 'label') {
    return { key: null, name: target, value: undefined };
  }
  return {
    key: `block:${component.uuid}:${target}`,
    name: target,
    value: component.block_fields?.[target],
  };
};

/**
 * A field row exists when the owner's annotation doesn't say otherwise.
 *
 * A nonexistent field (`element: false` — declared metadata like
 * block_content's info, or inferred: a display-hidden block field, a
 * root field on an LB display) never renders as an
 * attributed element, so no canvas element answers to its key — no
 * outline, no styling surface, no edit path. Its row would be a
 * mirror-only selectable in ANY view — the projection renders capability,
 * not data.
 */
const fieldExists = (facts, name) => facts[name]?.element !== false;

const componentNode = (component, rootFields = {}, fillsOf = () => []) => {
  const element = component._element ?? {};
  const fieldFacts = element.fields ?? {};
  const presentation = element.presentation ?? { mode: 'open' };
  // A door's fills (slot-fills.js): the page's own sections filling the
  // slot this block declares, nested under it as the canvas renders them;
  // nothing for any other block.
  const fills = fillsOf(component).map((fill) =>
    sectionNode(fill, rootFields, fillsOf),
  );
  // What the door shows by reference (shown-rows.js): the tree follows
  // the render under every door, so a door with a default grows one
  // shown-document row after its fills; a row of its own kind, no key.
  const under = [...fills, ...shownRows(component)];

  // MERGED: a container whose meaningful content is exactly one thing
  // presents AS that one thing: one row, keyed by the block placement so
  // move/trash/duplicate/drag all arm, named by the annotation (the
  // annotator's excerpt of the absorbed content, live against the mirror
  // since every mutation response re-annotates), with the absorbed node's
  // children retained (a single multi-value paragraph field's paragraph
  // rows survive the merge).
  if (presentation.mode === 'merged') {
    const target = presentation.edit_target ?? null;
    const absorbed = mergedTarget(component, rootFields);
    let children = [];
    if (absorbed.key && absorbed.value !== undefined) {
      children = fieldNode(
        absorbed.key,
        absorbed.name,
        absorbed.value,
        fieldFacts[absorbed.name]?.presentation,
      ).children;
    }
    return {
      key: `block:${component.uuid}`,
      label: element.name,
      elementType: element.type,
      icon: element.icon ?? null,
      kind: 'block',
      bundle: component.block_type ?? null,
      presentation: 'merged',
      editTarget: target,
      slot: component.slot ?? null,
      // The stored configuration label (the lean node's sparse `label`),
      // the rename input's seed: a merged row NAMES itself by its content,
      // so the seed is never the shown name (rename-commit.js).
      customLabel: component.label ?? '',
      can: element.can ?? null,
      children: [...children, ...under],
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
  // the label renders as page content); core's raw 'visible'/'0'
  // label_display spelling never leaves storage.
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
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    kind: 'block',
    bundle: component.block_type ?? null,
    presentation: presentation.mode,
    // The slot a door declares (the lean node's `slot` on a slot block): the
    // tree stamps it as data-tree-door, the drop resolver's door fact.
    slot: component.slot ?? null,
    // The stored configuration label, the rename input's seed (empty when
    // the lean node carries none; the sparse tree spells only a non-empty
    // label).
    customLabel: component.label ?? '',
    can: element.can ?? null,
    children: [...children, ...under],
  };
};

/**
 * A lean-tree child's row: containers recurse as SECTIONS — one node shape
 * at every depth — anything else is a component. (Region nodes never reach
 * here; sectionNode unwraps them.)
 */
const treeChildNode = (child, rootFields, fillsOf) =>
  child.kind === 'container'
    ? sectionNode(child, rootFields, fillsOf)
    : componentNode(child, rootFields, fillsOf);

/**
 * A section row — top-level or nested, one shape, from a lean container
 * node. A container layout's ordered `children` land directly under the
 * section row. A multi-region layout's children are region nodes; region
 * rows only appear when there is more than one — a lone region's grouping
 * is pure noise, so its components collapse into the section row's
 * children. Region rows carry NO key (regions are not addressable
 * elements — no selection, no styling) but they ARE a drop surface: the
 * node carries its owner (the section's key) and its region name, the
 * tree stamps them as data-tree-owner and data-tree-region, and the drop
 * resolver (drag/tree-gaps.js) reads the pair so the row's middle appends
 * to the region (`inside` carrying the region) and its lower edge is the
 * region's start. Otherwise they are grouping chrome. (Without
 * daedalus_layout sections have no uuid; such a row still renders, unselectable,
 * so its blocks stay reachable.) The row's words are the annotation's:
 * `name` (the custom label when renamed, else the type word) and `type`
 * ("Section" at page depth, "Container" nested). The row also carries the
 * raw custom label (customLabel) so the tree's rename affordance can seed
 * its input with what is actually stored, not the minted fallback.
 */
const sectionNode = (section, rootFields = {}, fillsOf = () => []) => {
  const element = section._element ?? {};
  const key = section.uuid ? `container:${section.uuid}` : null;
  const list = section.children ?? [];
  const regions = list.filter((child) => child.kind === 'region');
  const childNode = (child) => treeChildNode(child, rootFields, fillsOf);
  const children =
    regions.length > 1
      ? regions.map((region) => ({
          key: null,
          owner: key,
          region: region.region,
          label: humanize(region.region),
          kind: 'region',
          presentation: 'open',
          children: (region.children ?? []).map(childNode),
        }))
      : (regions.length === 1 ? (regions[0].children ?? []) : list).map(
          childNode,
        );

  return {
    key,
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    customLabel: section.label ?? '',
    kind: 'container',
    presentation: 'open',
    can: element.can ?? null,
    children,
  };
};

/**
 * Projects the mirror into the tree's root node.
 *
 * The root consumes its own `_element` annotation like any other owning
 * node: nonexistent fields fold in every view, and the derived edit target
 * (the entity's label field presents AS the entity row) absorbs the label
 * field's row into the root row — the root shows its entity label (the
 * annotation's `name`, re-minted with every mutation response, so a
 * title edit shows live) and renames it, the same absorption a merged
 * block row performs (the root's Edit verb opens the entity-form panel;
 * no leaf route is involved).
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
  // Layout structure projects from the lean node tree (`layout`), the
  // spine's canonical page representation delivered on every blueprint:
  // kind-discriminated container/region/block nodes, one child axis, styles
  // on the node, `_element` riding along. A locked page's slot fills leave
  // the root list for their doors (slot-fills.js).
  const { roots, fillsOf } = pairSlotFills(blueprint.layout);
  roots.forEach((section) => {
    children.push(sectionNode(section, blueprint.fields ?? {}, fillsOf));
  });

  return {
    key: `entity:${root.uuid}`,
    label: element.name,
    elementType: element.type,
    icon: element.icon ?? null,
    kind: 'entity',
    bundle: blueprint.bundle ?? null,
    presentation: 'open',
    editTarget,
    can: element.can ?? null,
    children,
  };
};
