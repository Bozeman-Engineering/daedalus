<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_stylish\InspectorGates;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the inspector's law gate (InspectorGates::law()).
 *
 * The Law tab and every road to it (the provenance row's "See the
 * declaration" doorway, the code view's declared run) render only for an
 * account holding "view style law", a permission granted to no role by
 * default. The gate is honest chrome the two hosts (InspectorSidebar,
 * InspectorPopout) attach as drupalSettings.daedalusStylish.law; the
 * payload is what the client reads, so the payload is what is locked:
 * {canView: FALSE} without the permission, {canView: TRUE} with it, and
 * TRUE for an administrator account, which holds every permission (the
 * accepted caveat). The permission itself is restricted, so the role form
 * warns before anyone grants it.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
#[CoversClass(InspectorGates::class)]
class InspectorGatesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_blueprint',
    'filter',
    'user',
    'field',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Importing daedalus_stylish's config saves its settings, a law change
    // that recompiles the variants file over these storages.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * The gates service, reading the account set up as the current user.
   */
  protected function gates(): InspectorGates {
    return $this->container->get('daedalus_stylish.inspector_gates');
  }

  /**
   * Without the permission the payload closes the tab and its doorways.
   */
  public function testLawClosedWithoutThePermission(): void {
    $this->setUpCurrentUser();
    $this->assertSame(['canView' => FALSE], $this->gates()->law());
  }

  /**
   * An account granted "view style law" gets the tab and the doorways.
   */
  public function testLawOpenWithThePermission(): void {
    $this->setUpCurrentUser([], [InspectorGates::LAW_PERMISSION]);
    $this->assertSame(['canView' => TRUE], $this->gates()->law());
  }

  /**
   * An administrator holds every permission, the law gate included.
   */
  public function testAdministratorSeesTheLaw(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $this->assertSame(['canView' => TRUE], $this->gates()->law());
  }

  /**
   * The permission is defined restricted and is the gate's own name.
   */
  public function testPermissionIsDefinedAndRestricted(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey(InspectorGates::LAW_PERMISSION, $permissions);
    $this->assertSame('view style law', InspectorGates::LAW_PERMISSION);
    $this->assertTrue($permissions[InspectorGates::LAW_PERMISSION]['restrict access'] ?? FALSE, 'the role form warns before granting the law');
    $this->assertSame('daedalus_stylish', $permissions[InspectorGates::LAW_PERMISSION]['provider']);
  }

}
