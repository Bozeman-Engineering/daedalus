<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Resolves context YAML guidance and defaults.
 *
 * Merges universal YAML, scoped YAML, and hook alter into a single context
 * array. Provides point queries for individual block types, layouts, and
 * field types without requiring a full schema build.
 */
class BlueprintContextResolver implements BlueprintContextResolverInterface {

  use YamlDiscoveryTrait;

  /**
   * Constructs a BlueprintContextResolver.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function getYamlPrefix(): string {
    return 'daedalus_blueprint.context';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleHandler(): ModuleHandlerInterface {
    return $this->moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(string $entity_type, string $bundle): array {
    $cache_id = "daedalus_blueprint:context:{$entity_type}:{$bundle}";
    $cached = $this->cache->get($cache_id);
    if ($cached) {
      return $cached->data;
    }

    $context = $this->discoverAndMerge(
      'daedalus_blueprint_context',
      "{$entity_type}.{$bundle}",
      $entity_type,
      $bundle,
    );

    $cache_tags = [
      'entity_field_info',
      "config:core.entity_view_display.{$entity_type}.{$bundle}.default",
    ];
    // Alter implementations feeding data with its own invalidation domain
    // declare it through the reserved cache_tags key; it stays in the
    // context array (the schema builder inherits it) but never reaches
    // schema output.
    $cache_tags = array_merge($cache_tags, $context['cache_tags'] ?? []);
    $this->cache->set($cache_id, $context, CacheBackendInterface::CACHE_PERMANENT, $cache_tags);

    return $context;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityGuidance(string $entity_type, string $bundle): ?string {
    $context = $this->getContext($entity_type, $bundle);
    return $context['guidance'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getBlockTypeContext(string $entity_type, string $bundle, string $block_type): array {
    $context = $this->getContext($entity_type, $bundle);
    return $context['block_types'][$block_type] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getLayoutContext(string $entity_type, string $bundle, string $layout_id): array {
    $context = $this->getContext($entity_type, $bundle);
    return $context['layouts'][$layout_id] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldTypeGuidance(string $entity_type, string $bundle, string $field_type): ?string {
    $context = $this->getContext($entity_type, $bundle);
    return $context['field_types'][$field_type]['guidance'] ?? NULL;
  }

}
