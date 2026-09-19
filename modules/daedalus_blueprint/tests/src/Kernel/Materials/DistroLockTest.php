<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the distro lock's one fact and the settings flag that lifts it.
 *
 * The lock holds on a consuming site (no flag, or the flag FALSE) and
 * opens on a development site that sets
 * `$settings['daedalus_blueprint_developer'] = TRUE`. The service reads the
 * setting on every call, so the same instance follows a flip without a
 * container rebuild; only the cached roster records (not this service)
 * need a cache rebuild.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class DistroLockTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
  ];

  /**
   * The lock holds by default and opens under the developer flag.
   */
  public function testHoldsUnlessTheDeveloperFlagIsSet(): void {
    $lock = $this->container->get('daedalus_blueprint.distro_lock');
    $this->assertInstanceOf(DistroLock::class, $lock);
    $this->assertSame('daedalus_blueprint_developer', DistroLock::SETTING);

    // A consuming site: the flag is unset.
    $this->assertTrue($lock->holds(), 'The lock holds when the setting is absent.');

    // A development site: the package author lifts the lock.
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $this->assertFalse($lock->holds(), 'The developer flag lifts the lock.');

    // The flag set but FALSE is a consuming site again: the lock reads the
    // value, not the key's presence.
    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $this->assertTrue($lock->holds(), 'A FALSE flag keeps the lock.');
  }

  /**
   * The class-name alias resolves to the same service.
   */
  public function testAutowireAlias(): void {
    $this->assertSame(
      $this->container->get('daedalus_blueprint.distro_lock'),
      $this->container->get(DistroLock::class),
    );
  }

}
