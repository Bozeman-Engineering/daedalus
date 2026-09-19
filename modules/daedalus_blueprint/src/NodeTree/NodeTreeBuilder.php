<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\NodeTree;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;

/**
 * Builds the lean node tree — the spine's canonical page representation.
 *
 * One kind-discriminated tree of containers and blocks, derived from
 * LayoutSerializer's section output plus the entity's style map. It is the
 * representation the model-facing dialect prints from and the shape the
 * browser consumers (the blueprint mirror's `_tree`) read, so the two
 * surfaces project the same nodes by construction.
 *
 * Node shapes:
 * - Container (every section, top-level or nested):
 *   {kind: 'container', uuid?, layout_id, label?, layout_settings?,
 *   stencil?, children: [...], styles?}. A container LAYOUT's ordered flow
 *   is its `children` directly; a multi-region layout's genuinely named
 *   slots appear as region nodes.
 * - Region (multi-region layouts only, until they retire):
 *   {kind: 'region', region: name, children: [...]}. Regions are not
 *   addressable elements — no uuid, no styles — but their grouping is real
 *   structure while multi-region layouts exist, so it is a node, never a
 *   map: the tree has ONE child axis at every depth.
 * - Block: {kind: 'block', uuid, block_type|plugin_id, label?,
 *   label_rendered?, block_fields?, configuration?, stencil?, styles?}.
 *
 * Leanness is structural, not lossy-by-default: unknown keys on a
 * serialized node (delivery annotations like `_element`, future serializer
 * keys) are carried verbatim; only the storage residue named below is
 * removed. Removed residue:
 * - the `regions` map and per-component region names (structure is the one
 *   `children` axis, region nodes where slots are real),
 * - `section_index` (order IS the array order),
 * - `view_mode` and `block_revision_id` (render/revision plumbing no
 *   consumer of the tree reads and no author writes),
 * - `label_display` (core's 'visible'/'0' storage spelling), replaced by
 *   the derived boolean `label_rendered`, present only when TRUE: whether
 *   the label renders as page content is the fact consumers need.
 *
 * Styles live ON the node (the ruled spine shape): the entity's flat style
 * map — selectable keys to condition-keyed entries — is distributed onto
 * the nodes its keys address, and becomes a derived delivery index for the
 * CSS printers rather than the canonical shape. A node's `styles` is keyed
 * by the LOCAL remainder of the selectable key after the node's own
 * identity (`block:{uuid}` / `container:{uuid}`) is stripped:
 * - '' — the element's own entry,
 * - ':{field_name}' — a block field's entry,
 * - '::.class' — a sub-element (part) entry,
 * - '::>' — the all-direct-children entry,
 * and compounds of those, exactly as the map spells them. Entries are
 * carried verbatim (condition-keyed property maps plus any `variants`
 * reference list), so the flat map is exactly reconstructible from the
 * tree. Keys addressing the root entity or root fields (`entity:`,
 * `field:`) have no layout node and stay in the flat map only.
 */
class NodeTreeBuilder {

  /**
   * The field type carrying an entity's style map.
   *
   * The type id is daedalus_stylish's, referenced as a string so the spine stays
   * page-builder-agnostic (the entity_reference_revisions precedent): with
   * no styling module installed no field of this type exists and the tree
   * simply carries no styles.
   */
  public const STYLE_MAP_FIELD_TYPE = 'daedalus_stylish_style_map';

  public function __construct(
    protected LayoutSerializer $layoutSerializer,
  ) {}

  /**
   * Builds the lean node tree for an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The blueprint root entity (staged state included — the serializer
   *   reads whatever sections the entity carries).
   *
   * @return array
   *   The ordered list of top-level container nodes.
   */
  public function build(FieldableEntityInterface $entity): array {
    return $this->fromSections($this->layoutSerializer->serialize($entity), $this->styleMap($entity));
  }

  /**
   * Transforms serialized section nodes into the lean node tree.
   *
   * Pure: annotated input (the delivered blueprint's section nodes) yields
   * an annotated tree, because unknown keys ride along.
   *
   * @param array $sections
   *   Section nodes as LayoutSerializer emits them ({layout_id, uuid?,
   *   regions|children, ...}), annotated or not.
   * @param array $style_map
   *   The entity's flat style map (selectable keys to entries).
   *
   * @return array
   *   The ordered list of top-level container nodes.
   */
  public function fromSections(array $sections, array $style_map = []): array {
    $styles = $this->indexStyles($style_map);
    return array_map(
      fn (array $section) => $this->containerNode($section, $styles),
      array_values($sections),
    );
  }

  /**
   * Reads an entity's flat style map.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return array
   *   The decoded style map; empty when the entity has no style map field
   *   or the field is empty.
   */
  public function styleMap(FieldableEntityInterface $entity): array {
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== self::STYLE_MAP_FIELD_TYPE) {
        continue;
      }
      $value = $entity->get($field_name)->value;
      if (!is_string($value) || $value === '') {
        return [];
      }
      $map = json_decode($value, TRUE);
      return is_array($map) ? $map : [];
    }
    return [];
  }

  /**
   * Builds a container node from a serialized section node.
   */
  protected function containerNode(array $section, array $styles): array {
    $node = ['kind' => 'container'] + $section;
    unset($node['regions'], $node['children'], $node['section_index']);

    if (array_key_exists('children', $section)) {
      // A container layout: one ordered flow.
      $children = array_map(
        fn (array $child) => $this->childNode($child, $styles),
        array_values($section['children']),
      );
    }
    else {
      // A multi-region layout: the named slots are region nodes.
      $children = [];
      foreach ($section['regions'] ?? [] as $region_name => $components) {
        $children[] = [
          'kind' => 'region',
          'region' => $region_name,
          'children' => array_map(
            fn (array $child) => $this->childNode($child, $styles),
            array_values($components),
          ),
        ];
      }
    }
    $node['children'] = $children;

    if (isset($section['uuid'])) {
      $this->attachStyles($node, $styles['container'][$section['uuid']] ?? []);
    }
    return $node;
  }

  /**
   * Builds the node for one component-list entry.
   *
   * A nested section carries the section node shape (`layout_id`) and
   * recurses as a container; anything else is a block.
   */
  protected function childNode(array $child, array $styles): array {
    if (array_key_exists('layout_id', $child)) {
      return $this->containerNode($child, $styles);
    }
    return $this->blockNode($child, $styles);
  }

  /**
   * Builds a block node from a serialized component.
   */
  protected function blockNode(array $component, array $styles): array {
    $node = ['kind' => 'block'] + $component;
    unset($node['label_display'], $node['view_mode'], $node['block_revision_id']);

    // label_display's absent-key default is 'visible' (core's block plugin
    // default), same as the serializer's uniform emission.
    if (($component['label'] ?? '') !== '' && ($component['label_display'] ?? 'visible') === 'visible') {
      $node['label_rendered'] = TRUE;
    }

    if (isset($component['uuid'])) {
      $this->attachStyles($node, $styles['block'][$component['uuid']] ?? []);
    }
    return $node;
  }

  /**
   * Attaches a node's style entries, keyed by local remainder.
   */
  protected function attachStyles(array &$node, array $entries): void {
    if (!empty($entries)) {
      $node['styles'] = $entries;
    }
  }

  /**
   * Indexes a flat style map by addressed node.
   *
   * @param array $style_map
   *   The flat map.
   *
   * @return array
   *   ['container' => [uuid => [local => entry]], 'block' => [...]] where
   *   local is the selectable key with the node identity stripped ('' for
   *   the element's own entry; every sub-address keeps its leading ':').
   */
  protected function indexStyles(array $style_map): array {
    $index = [];
    foreach ($style_map as $key => $entry) {
      if (!is_string($key)) {
        continue;
      }
      [$prefix, $rest] = array_pad(explode(':', $key, 2), 2, '');
      if (($prefix !== 'container' && $prefix !== 'block') || $rest === '') {
        continue;
      }
      $colon = strpos($rest, ':');
      $uuid = $colon === FALSE ? $rest : substr($rest, 0, $colon);
      $local = $colon === FALSE ? '' : substr($rest, $colon);
      if ($uuid !== '') {
        $index[$prefix][$uuid][$local] = $entry;
      }
    }
    return $index;
  }

}
