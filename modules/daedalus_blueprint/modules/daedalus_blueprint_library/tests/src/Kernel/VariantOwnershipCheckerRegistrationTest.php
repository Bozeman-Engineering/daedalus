<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint_library\DaedalusBlueprintLibraryServiceProvider;
use Drupal\daedalus_blueprint_library\BlueprintVariantOwnershipChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The ownership checker registers only when daedalus_stylish is installed.
 *
 * The checker's class cannot load without daedalus_stylish (it implements a daedalus_stylish
 * interface), and container compilation reflects every registered service
 * class, so registering it unconditionally fatals any container built while
 * daedalus_stylish is absent. In the field that killed fresh recipe installs:
 * daedalus_blueprint_library installs before daedalus_stylish, and the next container
 * compile died mid-recipe. The test suite cannot reproduce that fatal
 * (phpunit's bootstrap autoloads every extension's namespace regardless of
 * install state), so this locks the guard it forced: no definition without
 * daedalus_stylish, definition present once daedalus_stylish installs.
 */
#[CoversClass(DaedalusBlueprintLibraryServiceProvider::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class VariantOwnershipCheckerRegistrationTest extends BlueprintKernelTestBase {

  /**
   * Tests the checker's conditional registration around daedalus_stylish installs.
   */
  public function testCheckerRegistersOnlyWithDaedalusStylish(): void {
    // The base class enables daedalus_blueprint_library without daedalus_stylish: the
    // exact install-order window that fatalled in the field. The container
    // built, which is the fix's core claim, and the checker must be absent.
    $this->assertFalse($this->container->has('daedalus_blueprint_library.variant_ownership_checker'));

    // Installing daedalus_stylish rebuilds the container; the provider runs again and
    // the checker joins the seam.
    $this->enableModules(['daedalus_stylish']);
    $this->assertTrue($this->container->has('daedalus_blueprint_library.variant_ownership_checker'));
    $this->assertInstanceOf(BlueprintVariantOwnershipChecker::class, $this->container->get('daedalus_blueprint_library.variant_ownership_checker'));
  }

}
