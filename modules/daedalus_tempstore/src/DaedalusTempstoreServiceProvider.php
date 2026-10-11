<?php

namespace Drupal\daedalus_tempstore;

use Symfony\Component\DependencyInjection\Reference;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\daedalus_tempstore\Strategy\LayoutTempstoreStrategy;

/**
 * Modifies services for daedalus_tempstore module.
 *
 * This service provider:
 * - Collects tagged tempstore_strategy services and injects them in priority
 *   order.
 * - Conditionally overrides layout_builder.tempstore_repository (if Layout
 *   Builder is enabled).
 */
class DaedalusTempstoreServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   *
   * Note: This module's weight is set to 1 (see daedalus_tempstore_install()) to
   * ensure this alter() method runs after workspaces module's service provider.
   * This allows us to detect and extend workspaces' modifications to
   * layout_builder.tempstore_repository when both modules are enabled.
   */
  public function alter(ContainerBuilder $container) {
    // Conditionally register LayoutTempstoreStrategy if layout_builder is
    // enabled.
    if ($container->hasDefinition('layout_builder.tempstore_repository')) {
      $container->register('daedalus_tempstore.strategy.layout', LayoutTempstoreStrategy::class)
        ->addArgument(new Reference('tempstore.shared'))
        ->addArgument(new Reference('plugin.manager.layout_builder.section_storage'))
        ->addArgument(new Reference('workspaces.manager', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
        ->addArgument(new Reference('daedalus_tempstore.history_recorder'))
        ->addArgument(new Reference('daedalus_tempstore.section_storage_helper'))
        // The host of a staged storage: what a get() by entity answers.
        ->addArgument(new Reference('daedalus_blueprint.host_resolver'))
        // The load-time half of daedalus_layout's deprecation-window safety net
        // (NULL when daedalus_layout is not enabled): staged sessions holding
        // retired shapes converge on deserialization, before their first
        // render.
        ->addArgument(new Reference(
          'daedalus_layout.presave_safety_net',
          ContainerBuilder::IGNORE_ON_INVALID_REFERENCE,
        ))
        ->addTag('tempstore_strategy', ['priority' => 10]);

      // Override layout_builder.tempstore_repository with our strategy-based
      // version.
      $definition = $container->getDefinition('layout_builder.tempstore_repository');

      // Only modify the service if:
      // 1. Our class is available.
      // 2. We're not breaking the service (e.g., if workspaces will run after
      //    us). Check if workspaces has already modified this service by
      //    checking the class.
      $currentClass = $definition->getClass();
      $isWorkspacesClass = ($currentClass === 'Drupal\workspaces\WorkspacesLayoutTempstoreRepository');

      // Only proceed if our class exists AND (workspaces already ran OR
      // workspaces isn't installed).
      if (class_exists('Drupal\daedalus_tempstore\LayoutTempstoreRepository') &&
          ($isWorkspacesClass || !$container->hasDefinition('workspaces.manager'))) {
        $definition->setClass('Drupal\daedalus_tempstore\LayoutTempstoreRepository');
        $definition->setArguments([new Reference('daedalus_tempstore.strategy_selector')]);
        // Clear any method calls (e.g., setWorkspacesManager from workspaces
        // module). Workspace awareness is handled by WorkspaceKeyTrait in our
        // strategies.
        $definition->setMethodCalls([]);
      }
    }

    // Collect all tagged tempstore_strategy services.
    $tagged_services = $container->findTaggedServiceIds('tempstore_strategy');

    // Organize strategies by priority (descending - highest first).
    $strategies_by_priority = [];
    foreach ($tagged_services as $id => $tags) {
      foreach ($tags as $attributes) {
        $priority = $attributes['priority'] ?? 0;
        $strategies_by_priority[$priority][] = $id;
      }
    }
    krsort($strategies_by_priority);

    // Flatten into single array of service IDs in priority order.
    $strategy_ids = [];
    foreach ($strategies_by_priority as $priority_strategies) {
      foreach ($priority_strategies as $strategy_id) {
        $strategy_ids[] = $strategy_id;
      }
    }

    // Inject container and strategy IDs into selector.
    // Strategies will be lazy-loaded when needed.
    $selector = $container->getDefinition('daedalus_tempstore.strategy_selector');
    $selector->setArguments([
      new Reference('service_container'),
      $strategy_ids,
    ]);
  }

}
