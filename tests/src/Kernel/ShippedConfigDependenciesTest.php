<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Dependency;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins that every sub-module declares the providers of the config it ships.
 *
 * A `config/install` yml names the modules its config needs under
 * `dependencies.module`; core only installs that config after the module's
 * own info.yml dependencies, so a provider that is missing from the info.yml
 * (directly or through a declared dependency) is a bundle that crashes on
 * any site where the provider is not already installed ("The block_content
 * entity type does not exist", issue 3586635). The fresh-install tests never
 * see it because the site recipe installs the provider first; this test
 * reads the shipped files and refuses the drift for every sub-module at once.
 *
 * @group daedalus
 */
#[RunTestsInSeparateProcesses]
class ShippedConfigDependenciesTest extends KernelTestBase {

  /**
   * Every module discovered in the codebase, keyed by machine name.
   *
   * @var \Drupal\Core\Extension\Extension[]
   */
  private array $extensions;

  /**
   * A shipped yml's providers are the module or its declared dependencies.
   */
  public function testShippedConfigProvidersAreDeclared(): void {
    $module_list = $this->container->get('extension.list.module');
    assert($module_list instanceof ModuleExtensionList);
    $this->extensions = $module_list->getList();
    $daedalus_root = $this->extensions['daedalus']->getPath();

    $checked = [];
    $undeclared = [];
    foreach ($this->extensions as $name => $extension) {
      $path = $extension->getPath();
      if ($path !== $daedalus_root && !str_starts_with($path, $daedalus_root . '/')) {
        continue;
      }
      $install_dir = $this->root . '/' . $path . '/config/install';
      if (!is_dir($install_dir)) {
        continue;
      }
      $checked[] = $name;
      $declared = $this->transitiveDependencies($name);
      foreach (glob($install_dir . '/*.yml') as $file) {
        $config = Yaml::decode((string) file_get_contents($file));
        $providers = [
          ...($config['dependencies']['module'] ?? []),
          ...($config['dependencies']['enforced']['module'] ?? []),
        ];
        foreach (array_unique($providers) as $provider) {
          if (!in_array($provider, $declared, TRUE)) {
            $undeclared[] = sprintf('%s ships %s needing %s, which %s.info.yml neither declares nor pulls in', $name, basename($file), $provider, $name);
          }
        }
      }
    }

    $this->assertNotEmpty($checked, 'No daedalus sub-module with config/install was discovered; the walk is broken.');
    $this->assertContains('daedalus_edit_text_block', $checked);
    $this->assertSame([], $undeclared, implode("\n", $undeclared));
  }

  /**
   * The module itself plus every module its info.yml pulls in, at any depth.
   *
   * Walks `info['dependencies']` through the discovered extensions rather
   * than reading the `requires` property core stamps on each Extension, so
   * the test stays typed and works on the raw discovery list.
   *
   * @return string[]
   *   Module machine names, the module first.
   */
  private function transitiveDependencies(string $name): array {
    $resolved = [$name];
    $queue = [$name];
    while ($queue) {
      $current = array_shift($queue);
      $extension = $this->extensions[$current] ?? NULL;
      if (!$extension instanceof Extension) {
        continue;
      }
      foreach ($extension->info['dependencies'] ?? [] as $dependency) {
        $dependency_name = Dependency::createFromString($dependency)->getName();
        if (!in_array($dependency_name, $resolved, TRUE)) {
          $resolved[] = $dependency_name;
          $queue[] = $dependency_name;
        }
      }
    }
    return $resolved;
  }

}
