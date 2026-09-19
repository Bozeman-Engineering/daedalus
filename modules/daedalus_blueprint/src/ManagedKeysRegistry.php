<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Collects managed keys from YAML discovery and hook alter.
 *
 * Results are cached per entity type + bundle to avoid repeated discovery.
 */
class ManagedKeysRegistry implements ManagedKeysRegistryInterface {

  use YamlDiscoveryTrait;

  /**
   * Valid scope names for managed keys.
   *
   * @var string[]
   */
  protected const VALID_SCOPES = [
    'section_layout_settings',
    'inline_block_configuration',
  ];

  /**
   * Constructs a ManagedKeysRegistry.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler for invoking alter hooks.
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
    return 'daedalus_blueprint.managed_keys';
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
  public function getManagedKeys(string $entity_type, string $bundle): array {
    $cache_id = "daedalus_blueprint:managed_keys:{$entity_type}:{$bundle}";
    $cached = $this->cache->get($cache_id);

    if ($cached !== FALSE) {
      return $cached->data;
    }

    $managed_keys = $this->discoverAndMerge(
      'daedalus_blueprint_managed_keys',
      "{$entity_type}.{$bundle}",
      $entity_type,
      $bundle,
    );

    // Ensure only valid scopes are present.
    $managed_keys = array_intersect_key(
      $managed_keys,
      array_flip(static::VALID_SCOPES),
    );

    $this->cache->set(
      $cache_id,
      $managed_keys,
      CacheBackendInterface::CACHE_PERMANENT,
      ['daedalus_blueprint_managed_keys'],
    );

    return $managed_keys;
  }

  /**
   * {@inheritdoc}
   */
  public function getManagedKeysForScope(string $scope, string $entity_type, string $bundle): array {
    $all_keys = $this->getManagedKeys($entity_type, $bundle);
    return $all_keys[$scope] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function isManaged(string $scope, string $key, string $entity_type, string $bundle): bool {
    $scope_keys = $this->getManagedKeysForScope($scope, $entity_type, $bundle);
    return isset($scope_keys[$key]);
  }

}
