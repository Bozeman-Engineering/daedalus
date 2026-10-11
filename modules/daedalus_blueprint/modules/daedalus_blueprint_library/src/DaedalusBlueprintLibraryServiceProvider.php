<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers daedalus_stylish integration only when daedalus_stylish is installed.
 *
 * BlueprintVariantOwnershipChecker has a class-load-time dependency on daedalus_stylish
 * (it implements Drupal\daedalus_stylish\VariantApplicabilityCheckerInterface), so it
 * must not be registered as a service when daedalus_stylish is absent: container
 * compilation reflects every registered service class (for example
 * RegisterAutoconfigureAttributesPass), reflection autoloads the class, and
 * the load fatals because the interface's namespace only exists once daedalus_stylish
 * is installed. Lazy instantiation does not save a services.yml entry here;
 * the compile-time reflection is unconditional. This bites hardest during a
 * fresh recipe install, where daedalus_blueprint_library installs before
 * daedalus_stylish and the very next container compile dies mid-recipe. The container
 * rebuilds when daedalus_stylish installs, this provider runs again, and the checker
 * joins daedalus_stylish's seam then. Same posture as daedalus_blueprint's own
 * DaedalusBlueprintServiceProvider for layout_builder.
 */
class DaedalusBlueprintLibraryServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (!isset($modules['daedalus_stylish'])) {
      return;
    }

    // The ownership half of daedalus_stylish's variant applicability seam: owned
    // variants apply only inside their blueprint's placements, answered from
    // the server-readable provenance stamps.
    $container->register('daedalus_blueprint_library.variant_ownership_checker', BlueprintVariantOwnershipChecker::class)
      ->addArgument(new Reference('daedalus_blueprint.layout_serializer'))
      ->addArgument(new Reference('daedalus_blueprint.daedalus_layout_adapter'))
      ->addArgument(new Reference('daedalus_blueprint_library.discovery'))
      ->addArgument(new Reference('entity_type.manager'))
      ->addTag('daedalus_stylish.variant_applicability_checker');
  }

}
