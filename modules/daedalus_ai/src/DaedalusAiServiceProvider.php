<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\daedalus_ai\StyleValue\FileStyleValueHandler;
use Drupal\daedalus_ai\StyleValue\GenerateStyleValueHandler;

/**
 * Registers services that depend on optional modules.
 */
class DaedalusAiServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $modules = $container->getParameter('container.modules');
    // The style grammar's `_generate` and `_file` handled values plug into
    // daedalus_stylish's style-value seam. daedalus_stylish is not a
    // dependency of daedalus_ai, so the handlers only register when the seam
    // exists: they implement its interface, and an autowired definition is
    // reflected while the container compiles, so a tag that is never
    // collected would not keep a missing interface from failing the build.
    // Both resolve to the canonical url('media:<uuid>') token: `_generate`
    // over the same mint+job capability the field grammar uses, `_file`
    // (where the dialect's url('file:<name>') lands) from the conversation's
    // held file through the shared minter.
    if (isset($modules['daedalus_stylish'])) {
      $container->register('daedalus_ai.style_value_handler.generate', GenerateStyleValueHandler::class)
        ->setAutowired(TRUE)
        ->addTag('daedalus_stylish.style_value_handler');
      $container->register('daedalus_ai.style_value_handler.file', FileStyleValueHandler::class)
        ->setAutowired(TRUE)
        ->addTag('daedalus_stylish.style_value_handler');
    }
  }

}
