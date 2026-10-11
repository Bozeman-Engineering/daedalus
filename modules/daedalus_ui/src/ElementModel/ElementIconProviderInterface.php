<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Answers an element's icon, the third minted word beside type and name.
 *
 * `_element.icon` is minted server-side by BlueprintElementAnnotator and
 * delivered as data like `type` and `name`, so every outline (the page
 * elements tree, code mode's rows in the leader page and in the satellite)
 * reads one answer and no client derives what an element looks like.
 * daedalus_ui answers its own kinds (the root page, a template root, a
 * field block, a plugin block) from its own glyphs; a CONTAINER and an
 * INLINE BLOCK are daedalus_layout's concepts (the Container plugin, the
 * block type's icon setting), so for those the annotator asks the tagged
 * providers, which answer without a dependency cycle (the collector
 * pattern of LiveAttributionDemand and the existence feeders).
 *
 * Register with the `daedalus_ui.element_icon_provider` service tag. The
 * collector (`daedalus_ui.element_icon_provider`) asks providers in
 * service priority order and the first non-null answer wins; a provider
 * that does not speak for the node answers NULL and lets the next speak.
 * No provider answering leaves `icon` NULL, which the client draws as its
 * kind's own glyph.
 */
interface ElementIconProviderInterface {

  /**
   * The lean tree's kind word for a container node.
   */
  public const KIND_CONTAINER = 'container';

  /**
   * The lean tree's kind word for a block node.
   */
  public const KIND_BLOCK = 'block';

  /**
   * The icon URL of one element, or NULL when this provider has none.
   *
   * @param array $component
   *   The lean node (NodeTreeBuilder's shape): a container node carries
   *   `layout_id` and `label`, an inline block node `block_type`, a plugin
   *   block `plugin_id` or `field`. The spine's node, never the annotation.
   * @param string $kind
   *   The node's kind, self::KIND_CONTAINER or self::KIND_BLOCK.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Accumulates what the answer depends on (a block type read, a media
   *   icon's tags through IconResolver), so the render that delivers the
   *   annotation invalidates with them.
   *
   * @return string|null
   *   A root-relative URL (the icon value grammar's resolved spelling,
   *   never inline SVG), or NULL when this provider does not answer.
   */
  public function icon(array $component, string $kind, CacheableMetadata $cacheability): ?string;

}
