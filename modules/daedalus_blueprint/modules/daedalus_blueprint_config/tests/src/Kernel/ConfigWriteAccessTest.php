<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_config\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\daedalus_blueprint_config\ConfigOperationsInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests access enforcement on config entity write operations.
 *
 * Covers the CP6 guarantees:
 * - Access is fail-closed: a neutral access result (the common case for a user
 *   who lacks a config entity type's admin permission) denies the write. The
 *   denial tests pass only with the fail-closed check; under the old
 *   isForbidden() check a neutral result fell open and the write succeeded.
 * - Plugin-collection mutations enforce access in the service itself, not only
 *   in the AI tool layer that happens to call them.
 * - Create access for the ungated config types is reachable only when the type
 *   is allow-listed AND the user holds the create permission.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint_config')]
class ConfigWriteAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The blueprint create permission for ungated config types.
   */
  protected const CREATE_PERMISSION = 'create config entities via daedalus_blueprint';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'image',
    'daedalus_blueprint',
    'daedalus_blueprint_config',
  ];

  /**
   * The config operations service.
   *
   * @var \Drupal\daedalus_blueprint_config\ConfigOperationsInterface
   */
  protected ConfigOperationsInterface $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['image', 'system']);

    // Consume the uid-1 slot with a superuser so that users created per-test
    // are non-privileged and exercise real access handlers (uid 1 bypasses
    // every access check).
    $this->setUpCurrentUser();

    $this->operations = $this->container->get('daedalus_blueprint_config.operations');
  }

  /**
   * Tests updateProperties is allowed for a user with the admin permission.
   */
  public function testUpdatePropertiesAllowed(): void {
    $this->setUpCurrentUser([], ['administer image styles']);

    $style = ImageStyle::load('large');
    $result = $this->operations->updateProperties($style, ['label' => 'Large (Modified)']);

    $this->assertTrue($result->isSuccess(), 'Permitted user may update: ' . print_r($result->toArray(), TRUE));
    $this->assertSame('Large (Modified)', $result->getEntity()->label());
  }

  /**
   * Tests updateProperties is denied for a user without the admin permission.
   *
   * Regression guard for the fail-open bug: image_style access returns neutral
   * for an unprivileged user, which the old isForbidden() check let through.
   */
  public function testUpdatePropertiesDenied(): void {
    $this->setUpCurrentUser([], []);

    $style = ImageStyle::load('large');
    $result = $this->operations->updateProperties($style, ['label' => 'Hijacked']);

    $this->assertFalse($result->isSuccess(), 'Unprivileged user must be denied.');
    $this->assertSame('access_denied', $result->toArray()['errors'][0]['code']);
  }

  /**
   * Tests addPlugin is allowed for a user with the admin permission.
   */
  public function testAddPluginAllowed(): void {
    $this->setUpCurrentUser([], ['administer image styles']);

    $style = ImageStyle::load('large');
    $result = $this->operations->addPlugin($style, 'effects', [
      'id' => 'image_rotate',
      'configuration' => ['degrees' => 90, 'random' => FALSE, 'bgcolor' => ''],
    ]);

    $this->assertTrue($result->isSuccess(), 'Permitted user may add a plugin: ' . print_r($result->toArray(), TRUE));
  }

  /**
   * Tests addPlugin is denied in the service for an unprivileged user.
   *
   * Proves the access gate lives in ConfigOperations, not only in the AI tool
   * layer: the service is called directly here with no tool-level load check.
   */
  public function testAddPluginDenied(): void {
    $this->setUpCurrentUser([], []);

    $style = ImageStyle::load('large');
    $result = $this->operations->addPlugin($style, 'effects', [
      'id' => 'image_rotate',
      'configuration' => ['degrees' => 90, 'random' => FALSE, 'bgcolor' => ''],
    ]);

    $this->assertFalse($result->isSuccess(), 'Unprivileged user must be denied at the service.');
    $this->assertSame('access_denied', $result->toArray()['errors'][0]['code']);
  }

  /**
   * Tests create is denied when the ungated type is not allow-listed.
   *
   * The user holds the create permission, but with no allow-list entry the
   * type's neutral create access stays neutral and fail-closed denies.
   */
  public function testCreateDeniedWhenNotAllowlisted(): void {
    $this->setUpCurrentUser([], [self::CREATE_PERMISSION]);

    $errors = $this->validateCreate($this->makeUngatedEntity());

    $this->assertNotEmpty($errors, 'Create must be denied without an allow-list entry.');
    $this->assertSame('access_denied', $errors[0]->getCode());
  }

  /**
   * Tests create is denied for an allow-listed type without the permission.
   */
  public function testCreateDeniedWithoutPermission(): void {
    $this->allowlist(['entity_view_display']);
    $this->setUpCurrentUser([], []);

    $errors = $this->validateCreate($this->makeUngatedEntity());

    $this->assertNotEmpty($errors, 'Create must be denied without the permission.');
    $this->assertSame('access_denied', $errors[0]->getCode());
  }

  /**
   * Tests create is allowed for an allow-listed type with the permission.
   */
  public function testCreateAllowedWhenAllowlistedAndPermitted(): void {
    $this->allowlist(['entity_view_display']);
    $this->setUpCurrentUser([], [self::CREATE_PERMISSION]);

    $errors = $this->validateCreate($this->makeUngatedEntity());

    $this->assertSame([], $errors, 'Allow-listed type with the permission must be creatable.');
  }

  /**
   * Allow-lists config entity types for blueprint creation.
   *
   * @param string[] $types
   *   The config entity type IDs to allow-list.
   */
  protected function allowlist(array $types): void {
    $this->config('daedalus_blueprint_config.settings')
      ->set('allowed_create_types', $types)
      ->save();
  }

  /**
   * Builds an unsaved entity of an ungated config type (no admin permission).
   *
   * @return \Drupal\Core\Entity\Entity\EntityViewDisplay
   *   An unsaved view display, whose create access is neutral for everyone.
   */
  protected function makeUngatedEntity(): EntityViewDisplay {
    return EntityViewDisplay::create([
      'targetEntityType' => 'user',
      'bundle' => 'user',
      'mode' => 'default',
      'status' => TRUE,
    ]);
  }

  /**
   * Runs the create-access check the deserializer uses.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The unsaved entity to check.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Access errors, empty when creation is allowed.
   */
  protected function validateCreate($entity): array {
    return $this->container
      ->get('daedalus_blueprint_config.semantic_validator')
      ->validateAccess($entity, TRUE);
  }

}
