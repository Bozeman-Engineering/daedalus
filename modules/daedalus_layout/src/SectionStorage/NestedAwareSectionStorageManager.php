<?php

namespace Drupal\daedalus_layout\SectionStorage;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorage\SupportAwareSectionStorageManagerInterface;

/**
 * Decorator for section storage manager.
 *
 * This decorator passes through all calls to the inner manager. Wrapping
 * with NestedAwareSectionStorage happens at the tempstore level when Layout
 * Builder is being actively edited.
 */
class NestedAwareSectionStorageManager implements SupportAwareSectionStorageManagerInterface, CachedDiscoveryInterface {

  /**
   * Constructs a NestedAwareSectionStorageManager.
   *
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $inner
   *   The inner section storage manager being decorated.
   */
  public function __construct(
    protected SectionStorageManagerInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load($type, array $contexts = []) {
    // Pass through without wrapping. Wrapping happens at tempstore level.
    return $this->inner->load($type, $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function loadEmpty($type) {
    return $this->inner->loadEmpty($type);
  }

  /**
   * {@inheritdoc}
   */
  public function findByContext(array $contexts, RefinableCacheableDependencyInterface $cacheability) {
    // Pass through without wrapping. Wrapping happens at tempstore level.
    return $this->inner->findByContext($contexts, $cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $this->inner->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return $this->inner->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function notSupported(string $entity_type_id, string $bundle, string $view_mode): bool {
    if ($this->inner instanceof SupportAwareSectionStorageManagerInterface) {
      return $this->inner->notSupported($entity_type_id, $bundle, $view_mode);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions() {
    if ($this->inner instanceof CachedDiscoveryInterface) {
      $this->inner->clearCachedDefinitions();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE) {
    if ($this->inner instanceof CachedDiscoveryInterface) {
      $this->inner->useCaches($use_caches);
    }
  }

}
