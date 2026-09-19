<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers services that depend on optional modules.
 */
class DaedalusStylishServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $modules = $container->getParameter('container.modules');
    // The media library opener for media-valued styles. media_library is not
    // a dependency of daedalus_stylish (the token spine works without it — values can
    // arrive via the AI or the code editor), so the opener only registers
    // when the seam it plugs into exists. daedalus_tempstore is optional the
    // same way it is everywhere else in daedalus_stylish: without it access checks
    // run against the saved host.
    if (isset($modules['media_library'])) {
      $container->register(DaedalusStylishMediaLibraryOpener::SERVICE_ID, DaedalusStylishMediaLibraryOpener::class)
        ->setArguments([
          new Reference('entity_type.manager'),
          new Reference('daedalus_stylish.media_value_resolver'),
          new Reference('daedalus_tempstore.entity_tempstore_repository', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ])
        ->addTag('media_library.opener');
    }
  }

}
