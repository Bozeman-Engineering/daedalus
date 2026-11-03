<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Traits;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus_ai\Provider\UsageReader;

/**
 * What a Kernel test takes from daedalus_ai without enabling it.
 *
 * The parent module cannot be enabled in a Kernel test (its info.yml
 * drags the full AI stack through daedalus_blueprint_ai and ai_chatbot,
 * none of it needed to count a leg), so a ledger test registers the one
 * service it takes from it, the usage reader, from its real class, and
 * declares the module's namespace as available: the stub provider
 * (daedalus_ai_test) implements the spine's capability interfaces from
 * Drupal\daedalus_ai\Provider, and core's attribute discovery skips a
 * plugin class whose dependency's two-level namespace is not among the
 * container's, as a plugin depending on an optional module would be.
 */
trait DaedalusAiServicesTrait {

  /**
   * Registers daedalus_ai's reader and namespace on the container.
   *
   * Called from the test's register().
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container being built.
   */
  protected function registerDaedalusAiServices(ContainerBuilder $container): void {
    if (!$container->hasDefinition('daedalus_ai.usage_reader')) {
      // The id and the class alias, as daedalus_ai.services.yml declares
      // them; the ledger autowires the reader by class.
      $container->register('daedalus_ai.usage_reader', UsageReader::class);
      $container->setAlias(UsageReader::class, 'daedalus_ai.usage_reader');
    }
    $namespaces = $container->getParameter('container.namespaces');
    if (!isset($namespaces['Drupal\daedalus_ai'])) {
      // The ledger is a sub-module of daedalus_ai: its src directory is
      // three levels under the parent's, and the map's paths are relative
      // to the app root like the parent's would be.
      $namespaces['Drupal\daedalus_ai'] = dirname($namespaces['Drupal\daedalus_ai_ledger'], 3) . '/src';
      $container->setParameter('container.namespaces', $namespaces);
    }
  }

}
