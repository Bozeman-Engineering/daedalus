<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\daedalus_stylish\Attribute\ControlWidget;

/**
 * Control widget plugin manager.
 */
final class ControlWidgetPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ControlWidget', $namespaces, $module_handler, ControlWidgetInterface::class, ControlWidget::class);
    $this->alterInfo('daedalus_stylish_control_widget_info');
    $this->setCacheBackend($cache_backend, 'daedalus_stylish_control_widget_plugins');
  }

}
