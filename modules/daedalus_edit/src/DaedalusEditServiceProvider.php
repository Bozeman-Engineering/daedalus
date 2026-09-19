<?php

namespace Drupal\daedalus_edit;

use Drupal\daedalus_edit\Routing\DaedalusLayoutRouteSubscriber;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus_edit\Form\LayoutBuilderBlockFormAlter;
use Drupal\daedalus_edit\EventSubscriber\BlockFormEndpoint;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the services that depend on optional modules.
 *
 * The Layout Builder block form services need daedalus_layout and the media library
 * opener needs media_library; neither is a hard dependency of daedalus_edit.
 */
class DaedalusEditServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $modules = $container->getParameter('container.modules');
    if (isset($modules['daedalus_layout'])) {
      $container->register('daedalus_edit.form_alter.lb_block', LayoutBuilderBlockFormAlter::class)
        ->setAutoconfigured(TRUE)
        ->setAutowired(TRUE);

      // Route subscriber to add the Edit tool route for Layout Builder
      // blocks when daedalus_layout is available.
      $container->register('daedalus_edit.daedalus_layout_route_subscriber', DaedalusLayoutRouteSubscriber::class)
        ->addTag('event_subscriber');

      // Declares the block-form endpoint kind on inline block placements
      // (data-daedalus-edit-form-endpoint), the attribution entity-form.js
      // routes its form fetch on.
      $container->register('daedalus_edit.event_subscriber.block_form_endpoint', BlockFormEndpoint::class)
        ->setArguments([new Reference('current_user')])
        ->addTag('event_subscriber');
    }

    // The media library opener for blueprint-edited media references.
    // media_library is not a dependency of daedalus_edit (the form-swap path
    // works with any widget), so the opener only registers when the seam it
    // plugs into exists.
    if (isset($modules['media_library'])) {
      $container->register(DaedalusEditMediaLibraryOpener::SERVICE_ID, DaedalusEditMediaLibraryOpener::class)
        ->setArguments([
          new Reference('entity_type.manager'),
          new Reference('daedalus_tempstore.entity_tempstore_repository'),
        ])
        ->addTag('media_library.opener');
    }
  }

}
