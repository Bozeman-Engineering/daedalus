<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\daedalus_stylish\Attribute\SwatchKind;

/**
 * Swatch kind plugin manager.
 */
final class SwatchKindPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/SwatchKind', $namespaces, $module_handler, SwatchKindInterface::class, SwatchKind::class);
    $this->alterInfo('daedalus_swatch_kind_info');
    $this->setCacheBackend($cache_backend, 'daedalus_swatch_kind_plugins');
  }

  /**
   * The declared kind ids, the swatch entity's kind vocabulary.
   *
   * @return string[]
   *   Plugin ids in discovery order.
   */
  public function kinds(): array {
    return array_keys($this->getDefinitions());
  }

}
