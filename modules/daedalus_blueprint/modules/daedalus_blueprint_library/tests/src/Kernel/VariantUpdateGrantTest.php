<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\daedalus_blueprint_library\Entity\BlueprintType;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the variant update grant an installed site receives.
 *
 * Adopting, releasing and transferring a blueprint's variants newly ask
 * "update variants", so
 * daedalus_blueprint_library_post_update_grant_variant_update() grants
 * it to each role that could already save or edit a blueprint: one
 * holding the create or the edit any permission of a blueprint type of
 * the site. A role holding neither gains nothing (the delete permission
 * alone is not enough), an administrator role is untouched, a second run
 * changes nothing, and nothing happens without daedalus_stylish.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class VariantUpdateGrantTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A type the site added beside the two the module ships.
    BlueprintType::create(['id' => 'promo', 'label' => 'Promo'])->save();
    require_once $this->container->get('extension.list.module')->getPath('daedalus_blueprint_library') . '/daedalus_blueprint_library.post_update.php';
  }

  /**
   * Creates a role holding the given permissions.
   *
   * @param string $id
   *   The role id.
   * @param string[] $permissions
   *   The permissions the role holds.
   * @param bool $is_admin
   *   Whether the role is an administrator role.
   */
  private function createGrantRole(string $id, array $permissions, bool $is_admin = FALSE): void {
    Role::create([
      'id' => $id,
      'label' => ucfirst($id),
      'permissions' => $permissions,
      'is_admin' => $is_admin,
    ])->save();
  }

  /**
   * The permissions a stored role holds, sorted.
   *
   * @param string $id
   *   The role id.
   *
   * @return string[]
   *   The permissions.
   */
  private function permissionsOf(string $id): array {
    $permissions = Role::load($id)->getPermissions();
    sort($permissions);
    return $permissions;
  }

  /**
   * Each role that could save or edit a blueprint gains update variants.
   */
  public function testGrant(): void {
    $this->createGrantRole('saver', ['create section blueprint']);
    $this->createGrantRole('curator', ['edit any site_chrome blueprint']);
    $this->createGrantRole('promoter', ['create promo blueprint', 'edit any promo blueprint']);
    $this->createGrantRole('holder', ['edit any section blueprint', 'update variants']);
    $this->createGrantRole('remover', ['delete any section blueprint']);
    $this->createGrantRole('reader', ['view test entity']);
    $this->createGrantRole('boss', ['create section blueprint'], TRUE);

    $message = PlainTextOutput::renderFromHtml(daedalus_blueprint_library_post_update_grant_variant_update());

    $this->assertSame(['create section blueprint', 'update variants'], $this->permissionsOf('saver'));
    $this->assertSame(['edit any site_chrome blueprint', 'update variants'], $this->permissionsOf('curator'));
    $this->assertSame(['create promo blueprint', 'edit any promo blueprint', 'update variants'], $this->permissionsOf('promoter'));
    $this->assertSame(['edit any section blueprint', 'update variants'], $this->permissionsOf('holder'));
    $this->assertSame(['delete any section blueprint'], $this->permissionsOf('remover'));
    $this->assertSame(['view test entity'], $this->permissionsOf('reader'));
    // Core empties an administrator role's permission list on save, so
    // it holds none before and none after; the message not naming the
    // role (below) is what shows it was not granted to.
    $this->assertSame([], $this->permissionsOf('boss'));

    $this->assertStringContainsString('The "Saver" role (saver) gained: update variants.', $message);
    $this->assertStringContainsString('The "Curator" role (curator) gained: update variants.', $message);
    $this->assertStringContainsString('The "Promoter" role (promoter) gained: update variants.', $message);
    $this->assertStringNotContainsString('holder', $message);
    $this->assertStringNotContainsString('remover', $message);
    $this->assertStringNotContainsString('reader', $message);
    $this->assertStringNotContainsString('boss', $message);

    // A second run changes nothing.
    $before = [];
    foreach (array_keys(Role::loadMultiple()) as $id) {
      $before[$id] = $this->permissionsOf((string) $id);
    }
    $second = (string) daedalus_blueprint_library_post_update_grant_variant_update();
    $this->assertSame('No role needed the variant update grant.', $second);
    foreach ($before as $id => $permissions) {
      $this->assertSame($permissions, $this->permissionsOf((string) $id));
    }
  }

  /**
   * Without daedalus_stylish the update does nothing.
   */
  public function testWithoutStylish(): void {
    $this->createGrantRole('saver', ['create section blueprint']);
    $this->disableModules(['daedalus_stylish']);

    $this->assertNull(daedalus_blueprint_library_post_update_grant_variant_update());
    $this->assertSame(['create section blueprint'], $this->permissionsOf('saver'));
  }

}
