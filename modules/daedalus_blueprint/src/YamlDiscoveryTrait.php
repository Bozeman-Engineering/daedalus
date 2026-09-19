<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides reusable YAML discovery with universal + scoped merge.
 *
 * Consumers declare a YAML prefix and module handler. The trait handles lazy
 * initialization of YamlDiscovery instances and the three-layer merge:
 * universal files → scoped files → hook alter.
 */
trait YamlDiscoveryTrait {

  /**
   * The discovery over the universal PREFIX.yml files, built on first use.
   */
  protected ?YamlDiscovery $universalDiscovery = NULL;

  /**
   * The discoveries over PREFIX.SCOPE.yml files, keyed by scope.
   */
  protected array $scopedDiscoveries = [];

  /**
   * Returns the YAML file prefix (e.g. 'daedalus_blueprint.context').
   */
  abstract protected function getYamlPrefix(): string;

  /**
   * Returns the module handler for directory listing and alter hooks.
   */
  abstract protected function getModuleHandler(): ModuleHandlerInterface;

  /**
   * Gets the universal YAML discovery instance.
   */
  protected function getUniversalDiscovery(): YamlDiscovery {
    if (!isset($this->universalDiscovery)) {
      $this->universalDiscovery = new YamlDiscovery(
        $this->getYamlPrefix(),
        $this->getModuleHandler()->getModuleDirectories(),
      );
    }
    return $this->universalDiscovery;
  }

  /**
   * Gets a scoped YAML discovery instance.
   *
   * @param string $scope
   *   The scope suffix (e.g. 'node.page').
   */
  protected function getScopedDiscovery(string $scope): YamlDiscovery {
    if (!isset($this->scopedDiscoveries[$scope])) {
      $this->scopedDiscoveries[$scope] = new YamlDiscovery(
        $this->getYamlPrefix() . '.' . $scope,
        $this->getModuleHandler()->getModuleDirectories(),
      );
    }
    return $this->scopedDiscoveries[$scope];
  }

  /**
   * Discovers and merges YAML data with three-layer precedence.
   *
   * Merges universal → scoped → hook alter using array_replace_recursive
   * for clean "last module wins" scalar replacement.
   *
   * @param string $alter_hook
   *   The alter hook name (without 'alter' suffix).
   * @param string|null $scope
   *   Optional scope suffix (e.g. 'node.page') for scoped discovery.
   * @param mixed ...$alter_args
   *   Additional arguments passed to the alter hook.
   *
   * @return array
   *   The merged data.
   */
  protected function discoverAndMerge(string $alter_hook, ?string $scope = NULL, mixed ...$alter_args): array {
    $data = [];
    foreach ($this->getUniversalDiscovery()->findAll() as $module_data) {
      $data = array_replace_recursive($data, $module_data);
    }

    if ($scope !== NULL) {
      foreach ($this->getScopedDiscovery($scope)->findAll() as $module_data) {
        $data = array_replace_recursive($data, $module_data);
      }
    }

    $this->getModuleHandler()->alter($alter_hook, $data, ...$alter_args);

    return $data;
  }

}
