<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityInterface;

/**
 * Tracks the root Layout Builder entity for nested inline-block renders.
 *
 * An inline block's content renders through the ordinary entity view
 * pipeline (InlineBlock → BlockContentViewBuilder), which only knows the
 * block_content entity; the root entity the page belongs to is not in scope
 * there. This registry bridges the gap: BEFORE an inline-block component
 * builds inside a host render (BlockComponentRenderArray subscriber, ahead
 * of core's builder, because BlockContentViewBuilder builds eagerly and the
 * nested render happens inside core's listener), the host's root entity is
 * recorded against the block_content UUID. Consumers read it back where the
 * root is otherwise out of scope: getContextsForEntity() on daedalus_layout's
 * LayoutBuilderEntityViewDisplay exposes it as
 * NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID when the content's
 * own display renders through Layout Builder, and daedalus_stylish's live-attribution
 * provider resolves the style-map host for any inline block's content.
 * Roots propagate through arbitrary nesting depth because each level
 * resolves its own root from the same registry before its children build.
 *
 * Keyed by entity UUID, deliberately NOT by object identity or a property on
 * the entity: inline-block content round-trips through block_serialized, so
 * every extraction is a fresh instance (identity breaks), and a dynamic
 * property would be serialized along with it and drag the whole root entity
 * graph into tempstore payloads. Request-scoped; entries are bounded by the
 * inline blocks a request renders.
 */
final class RootEntityRegistry {

  /**
   * Block content UUID → root entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   */
  private array $roots = [];

  /**
   * Records the root entity a block_content renders under.
   *
   * @param string $block_content_uuid
   *   The inline block's block_content UUID.
   * @param \Drupal\Core\Entity\EntityInterface $root
   *   The top-level Layout Builder entity.
   */
  public function setRoot(string $block_content_uuid, EntityInterface $root): void {
    $this->roots[$block_content_uuid] = $root;
  }

  /**
   * The root entity an entity renders under, if known.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose root to look up.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The root entity, or NULL when the entity is not a registered nested
   *   render (callers treat the entity itself as root).
   */
  public function getRoot(EntityInterface $entity): ?EntityInterface {
    return $this->roots[$entity->uuid()] ?? NULL;
  }

}
