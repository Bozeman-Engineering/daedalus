<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;

/**
 * Locates elements by UUID in an entity's full structure.
 *
 * Delegates all Layout Builder logic to DaedalusLayoutAdapter, then searches
 * entity_reference_revisions fields on the root entity and on inline
 * block content.
 */
class ElementLocator {

  /**
   * Constructs a ElementLocator.
   *
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   */
  public function __construct(
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected FieldHandlerManager $fieldHandlerManager,
  ) {}

  /**
   * Finds an element by UUID in the entity's full structure.
   *
   * Searches Layout Builder components (via DaedalusLayoutAdapter), then paragraph
   * fields on the root entity, then paragraph fields on inline block content.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to search.
   * @param string $uuid
   *   The UUID to find.
   * @param object|null $sectionStorage
   *   An already-resolved section storage to search. Required when one
   *   operation locates multiple elements and mutates them: separate
   *   locates build separate storage wrappers whose nested instances are
   *   not shared, so cross-wrapper mutations lose each other on bubbling.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   Context object containing the target element, its parent, and the
   *   path to it. NULL if not found.
   */
  public function locate(FieldableEntityInterface $entity, string $uuid, ?object $sectionStorage = NULL): ?ElementContext {
    // Search Layout Builder components (O(1) with daedalus_layout, flat without).
    $result = $this->daedalusLayoutAdapter->locateElement($entity, $uuid, $sectionStorage);
    if ($result !== NULL) {
      return $result;
    }

    // Search Layout Builder sections by UUID (requires daedalus_layout).
    $result = $this->locateSection($entity, $uuid, $sectionStorage);
    if ($result !== NULL) {
      return $result;
    }

    // Search referenced entities on the root entity.
    $result = $this->searchReferencedEntities($entity, $uuid, '');
    if ($result !== NULL) {
      return $result;
    }

    // Search referenced entities on all inline block_content entities.
    foreach ($this->daedalusLayoutAdapter->getBlockContentEntities($entity) as $info) {
      $result = $this->searchReferencedEntities($info['entity'], $uuid, $info['path']);
      if ($result !== NULL) {
        return $result;
      }
    }

    return NULL;
  }

  /**
   * Finds a Layout Builder section by UUID.
   *
   * Iterates layout_builder__layout sections and matches by daedalus_layout section
   * UUID, then falls back to sections nested inside container components at
   * any depth. Only works when daedalus_layout is installed (sections don't have
   * UUIDs without it).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to search.
   * @param string $uuid
   *   The section UUID to find.
   * @param object|null $sectionStorage
   *   A nested-aware section storage the caller already holds, or NULL to
   *   resolve one from the entity.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The section context, or NULL if not found. Nested sections carry the
   *   sectionStorage needed to bubble changes back to the root entity.
   */
  public function locateSection(FieldableEntityInterface $entity, string $uuid, ?object $sectionStorage = NULL): ?ElementContext {
    if (!$entity->hasField('layout_builder__layout')) {
      return NULL;
    }

    $items = $entity->get('layout_builder__layout');
    foreach ($items as $delta => $item) {
      $section = $item->section;
      $section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
      if ($section_uuid === $uuid) {
        return new ElementContext(
          type: ElementContext::TYPE_SECTION,
          target: $section,
          parentEntity: $entity,
          path: "layout_builder__layout[{$delta}]",
          section: $section,
          sectionIndex: $delta,
        );
      }
    }

    $nested = $this->daedalusLayoutAdapter->locateNestedSection($entity, $uuid, $sectionStorage);
    if ($nested !== NULL) {
      return new ElementContext(
        type: ElementContext::TYPE_SECTION,
        target: $nested['section'],
        // Sections at every depth are values on the root entity: there is
        // no owner entity inside the tree.
        parentEntity: $entity,
        path: 'layout_builder__layout[nested]',
        section: $nested['section'],
        sectionStorage: $nested['storage'],
      );
    }

    return NULL;
  }

  /**
   * Whether a section sits nested inside another section's region.
   *
   * Depth is positional, not a kind: locateSection() already distinguishes
   * a root hit (a layout_builder__layout item, sectionIndex set) from a
   * nested hit (found inside a container component, sectionStorage set),
   * so this just names that distinction for depth-aware consumers (e.g.
   * root-only style axes that are offered and defaulted for top-level
   * sections only).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity holding the layout.
   * @param string $uuid
   *   The section UUID (daedalus_layout identity).
   *
   * @return bool
   *   TRUE when the section lives inside another section's region. FALSE
   *   for a top-level section, and also when the section cannot be located
   *   at all: unknown placement degrades to root behavior, so depth-gated
   *   consumers fall back to their ungated posture rather than inventing a
   *   depth.
   */
  public function isNestedSection(FieldableEntityInterface $entity, string $uuid): bool {
    $context = $this->locateSection($entity, $uuid);
    return $context !== NULL && $context->sectionIndex === NULL;
  }

  /**
   * Searches entity_reference_revisions fields for an entity by UUID.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to search.
   * @param string $uuid
   *   The UUID to find.
   * @param string $base_path
   *   The path prefix for nested contexts.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The context if found, NULL otherwise.
   */
  protected function searchReferencedEntities(FieldableEntityInterface $entity, string $uuid, string $base_path): ?ElementContext {
    $entity_type_definition = $entity->getEntityType();
    $skipped_fields = $this->fieldHandlerManager->getSkippedFieldNames($entity_type_definition);
    $prefix = $base_path !== '' ? $base_path . '.' : '';

    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if (in_array($field_name, $skipped_fields, TRUE)) {
        continue;
      }

      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }

      $items = $entity->get($field_name);
      if ($items->isEmpty()) {
        continue;
      }

      foreach ($items as $delta => $item) {
        $child_entity = $item->entity;
        if ($child_entity === NULL) {
          continue;
        }

        $item_path = "{$prefix}fields.{$field_name}[{$delta}]";

        // Check if this entity's UUID matches.
        if ($child_entity->uuid() === $uuid) {
          return new ElementContext(
            type: ElementContext::TYPE_PARAGRAPH,
            target: $child_entity,
            parentEntity: $entity,
            path: $item_path,
            fieldName: $field_name,
            delta: $delta,
          );
        }

        // Recurse into the child entity's paragraph fields.
        $nested_result = $this->searchReferencedEntities($child_entity, $uuid, $item_path);
        if ($nested_result !== NULL) {
          return $nested_result;
        }
      }
    }

    return NULL;
  }

}
