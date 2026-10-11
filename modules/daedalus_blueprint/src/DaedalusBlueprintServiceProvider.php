<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutTempstoreHelper;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers Layout Builder integration only when layout_builder is installed.
 *
 * LayoutTempstoreHelper has a class-load-time dependency on layout_builder (it
 * uses LayoutEntityHelperTrait), so it must not be registered as a service when
 * layout_builder is absent: the container would try to load the class and fatal
 * with "Trait ... not found". Registering it conditionally here means
 * daedalus_blueprint.storage_handler's optional '@?' reference to it resolves to
 * the helper when layout_builder is present and to NULL otherwise, keeping
 * daedalus_blueprint installable on a site without layout_builder.
 */
class DaedalusBlueprintServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('layout_builder.tempstore_repository')) {
      return;
    }

    $container->register('daedalus_blueprint.layout_tempstore_helper', LayoutTempstoreHelper::class)
      ->addArgument(new Reference('layout_builder.tempstore_repository'))
      ->addArgument(new Reference('plugin.manager.layout_builder.section_storage'))
      ->addArgument(new Reference('daedalus_blueprint.host_resolver'))
      // The storage spine's shared defaults->overrides upgrade helper;
      // resolves to NULL when daedalus_tempstore is not installed.
      ->addArgument(new Reference('daedalus_tempstore.section_storage_helper', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE));
  }

}
