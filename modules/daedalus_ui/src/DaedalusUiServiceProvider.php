<?php

namespace Drupal\daedalus_ui;

use Symfony\Component\DependencyInjection\Reference;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\daedalus_ui\Routing\AfterEntityWorkflowEnhancer;
use Drupal\daedalus_ui\Routing\BeforeEntityWorkflowEnhancer;
use Drupal\daedalus_ui\EventSubscriber\ComposedBlockRender;
use Drupal\daedalus_ui\EventSubscriber\ReplaceMediaFieldAttributes;
use Drupal\daedalus_ui\EventSubscriber\LayoutBuilderBlockAttributes;

/**
 * Registers the services that depend on optional modules.
 */
class DaedalusUiServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $modules = $container->getParameter('container.modules');
    if (isset($modules['layout_builder'])) {
      $container->register('daedalus_ui.event_subscriber.lb_block_attributes', LayoutBuilderBlockAttributes::class)
        ->addTag('event_subscriber')
        ->addArgument(new Reference('current_user'))
        ->addArgument(new Reference('entity_type.manager'))
        ->addArgument(new Reference('daedalus_ui.live_attribution_demand'))
        ->addArgument(new Reference('daedalus_ui.element_model'));
      $container->register('daedalus_ui.event_subscriber.composed_block_render', ComposedBlockRender::class)
        ->addTag('event_subscriber')
        ->addArgument(new Reference('daedalus_ui.element_model'));
    }
    $container->register('daedalus_ui.event_subscriber.replace_media', ReplaceMediaFieldAttributes::class)
      ->addTag('event_subscriber')
      ->addArgument(new Reference('entity_type.manager'));
    if (isset($modules['entity_workflow'])) {
      $container->register('daedalus_ui.before_entity_workflow_enhancer', BeforeEntityWorkflowEnhancer::class)
        ->addTag('route_enhancer', ['priority' => 10])
        ->addArgument(new Reference('daedalus_ui.ui'));
      $container->register('daedalus_ui.after_entity_workflow_enhancer', AfterEntityWorkflowEnhancer::class)
        ->addTag('route_enhancer', ['priority' => -10])
        ->addArgument(new Reference('daedalus_ui.ui'));
    }
  }

}
