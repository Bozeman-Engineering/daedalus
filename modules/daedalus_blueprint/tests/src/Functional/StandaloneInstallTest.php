<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Functional;

use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves daedalus_blueprint installs without layout_builder present.
 *
 * The module declares no dependency on layout_builder and treats it as
 * optional, but its services must not couple to layout_builder at class-load
 * time: the compiled service container reflects service classes when it is
 * built, so a load-time dependency (e.g. `use LayoutEntityHelperTrait`) fatals
 * with "Trait ... not found" when the module is enabled on a site that does not
 * have layout_builder. That regression once aborted the whole daedalus recipe
 * apply (exit 255), and it surfaced only downstream, in a consumer's CI.
 *
 * This guards the "installable standalone" contract from inside the module's
 * own suite. It MUST drive the real out-of-process installer: an in-process
 * Kernel or BrowserTestBase install does NOT reproduce the fatal, because the
 * PHPUnit bootstrap registers every module's namespace on the autoloader
 * regardless of which modules are enabled, so the layout_builder trait stays
 * loadable and the bug hides. The recipe apply runs `core/scripts/drupal` in a
 * subprocess whose kernel registers namespaces only for ENABLED modules, which
 * is the faithful reproduction of a real site and the only place the regression
 * shows. With the bug present the subprocess fatals and exits non-zero;
 * applyRecipe() asserts exit 0, so the test fails.
 *
 * The 'minimal' profile is used because it does not enable layout_builder, so
 * the recipe enables daedalus_blueprint onto a site that genuinely lacks it.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StandaloneInstallTest extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * 'minimal' does not enable layout_builder, which is the whole point: it
   * gives a site where daedalus_blueprint must install without that module.
   */
  protected $profile = 'minimal';

  /**
   * Enabling daedalus_blueprint with no layout_builder does not fatal.
   */
  public function testInstallsWithoutLayoutBuilder(): void {
    // Sanity-check the fixture: the bug only reproduces when layout_builder is
    // absent, so assert that precondition rather than assume the profile.
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('layout_builder'),
      'The fixture must not have layout_builder, or this test cannot catch the regression.',
    );

    // A throwaway recipe that just enables daedalus_blueprint. applyRecipe() runs
    // it in a subprocess and asserts the process exited 0; if a service couples
    // to layout_builder at class-load time the container build fatals there.
    $recipe = $this->createRecipe([
      'name' => 'Daedalus Blueprint standalone install',
      'type' => 'Site',
      'install' => ['daedalus_blueprint'],
    ], 'daedalus_blueprint_standalone');
    $this->applyRecipe($recipe->path);

    // The apply succeeded and the environment was rebuilt, so the module is on
    // and its central persistence service resolves on a layout_builder-free
    // site.
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('daedalus_blueprint'),
      'daedalus_blueprint enabled cleanly without layout_builder.',
    );
    $this->assertInstanceOf(
      EntityStorageHandler::class,
      $this->container->get('daedalus_blueprint.storage_handler'),
      'The storage handler resolves with no Layout Builder helper injected.',
    );
  }

}
