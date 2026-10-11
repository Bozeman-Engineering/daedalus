<?php

namespace Drupal\daedalus_layout;

use Symfony\Component\DependencyInjection\Reference;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus_layout\ContextProvider\NodeRouteContextOverride;
use Drupal\daedalus_layout\EventSubscriber\SetInlineBlockDependency;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorageManager;

/**
 * Service provider for daedalus_layout module.
 */
class DaedalusLayoutServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {

    // The media library opener for icon pickers. media_library is not a
    // dependency of daedalus_layout, so the opener only registers when the
    // seam it plugs into exists (the daedalus_stylish/daedalus_edit opener
    // precedent); without it the daedalus_layout_icon_picker element
    // degrades to a textfield.
    $modules = $container->getParameter('container.modules');
    if (isset($modules['media_library'])) {
      $container->register(IconPickerOpener::SERVICE_ID, IconPickerOpener::class)
        ->setArguments([
          new Reference('entity_type.manager'),
          new Reference('daedalus_blueprint.icon_resolver'),
        ])
        ->addTag('media_library.opener');
    }

    // The access verifier reads ownership through the host, so core's
    // definition gains the resolver after its own four arguments.
    if ($container->hasDefinition('layout_builder.get_block_dependency_subscriber')) {
      $definition = $container->getDefinition('layout_builder.get_block_dependency_subscriber');
      $definition->setClass(SetInlineBlockDependency::class);
      $definition->addArgument(new Reference('daedalus_blueprint.host_resolver'));
      $container->setDefinition('layout_builder.get_block_dependency_subscriber', $definition);
    }

    // Core's inline_block.usage service is used as-is: usage rows always
    // point at the ROOT host entity under the embedded-section model (see
    // InlineBlockEntityOperations), so removeByLayoutEntity(root) covers the
    // whole tree in one query and no recursive override is needed.
    // Add nested storage awareness to NodeRouteContext.
    if ($container->hasDefinition('node.node_route_context')) {
      $definition = $container->getDefinition('node.node_route_context');
      $definition->setClass(NodeRouteContextOverride::class);
      $container->setDefinition('node.node_route_context', $definition);
    }

    // Decorate the section storage manager.
    if ($container->hasDefinition('plugin.manager.layout_builder.section_storage')) {
      $container->register('daedalus_layout.section_storage_manager_decorator', NestedAwareSectionStorageManager::class)
        ->setDecoratedService('plugin.manager.layout_builder.section_storage')
        ->addArgument(new Reference('daedalus_layout.section_storage_manager_decorator.inner'));
    }

    // Collect the transform spine's tagged services and inject their IDs
    // into the registry in priority order (higher first). Only IDs are
    // injected: the registry lazy-loads each transform from the container,
    // the same pattern as daedalus_tempstore strategies.
    $collect = function (string $tag) use ($container): array {
      $by_priority = [];
      foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
        foreach ($tags as $attributes) {
          $by_priority[$attributes['priority'] ?? 0][] = $id;
        }
      }
      krsort($by_priority);
      return $by_priority ? array_merge(...array_values($by_priority)) : [];
    };
    $container->getDefinition('daedalus_layout.component_transform_registry')
      ->setArguments([
        new Reference('service_container'),
        $collect('daedalus_layout.component_transform'),
        $collect('daedalus_layout.section_transform'),
        $collect('daedalus_layout.host_transform'),
        new Reference('daedalus_blueprint.host_resolver'),
      ]);

  }

}
