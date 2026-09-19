<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Sidebar plugin manager.
 */
final class SidebarPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/Sidebar', $namespaces, $module_handler, SidebarInterface::class, Sidebar::class);
    $this->alterInfo('daedalus_ui_sidebar_info');
    $this->setCacheBackend($cache_backend, 'daedalus_ui_sidebar_plugins');
  }

  /**
   * Get the sidebar plugins that apply to the current request.
   *
   * @return \Drupal\daedalus_ui\SidebarInterface[]
   *   Applying sidebar plugins, keyed by plugin ID, sorted by weight.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function getSidebars(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, static fn ($a, $b) => $a['weight'] <=> $b['weight']);
    $sidebars = [];
    foreach ($definitions as $definition) {
      $sidebar = $this->createInstance($definition['id']);
      if ($sidebar->applies()) {
        $sidebars[$definition['id']] = $sidebar;
      }
    }
    return $sidebars;
  }

}
