<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_stylish\Capability\StylePolicy;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The style permission: one per styled bundle, asked at the op door.
 *
 * The module mints `style {bundle} {type} elements` for every bundle
 * carrying a style store (the field's existence is the enablement) beside
 * the umbrella `style any elements`; its capability policy refuses a
 * style write to an actor holding neither and admits one holding either.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(StylePolicy::class)]
#[Group('daedalus_stylish')]
class StylePermissionTest extends KernelTestBase {

  use HostTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_unbound_test',
  ];

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * The site's permissions, read fresh.
   */
  protected function permissions(): array {
    return $this->container->get('user.permissions')->getPermissions();
  }

  /**
   * Runs one update_style as a batch on a host for an actor.
   */
  protected function style(EntityTest $entity, string $key, AccountInterface $actor): BlueprintResult {
    return $this->operations->batchOperations($this->host($entity), [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['padding' => '1rem']],
    ], ['actor' => $actor]);
  }

  /**
   * A styled bundle mints its permission; a bundle without a store has none.
   */
  public function testDynamicPermissionPerStyledBundle(): void {
    $permission = StylePolicy::permission('entity_test', 'entity_test');
    $this->assertArrayHasKey(StylePolicy::ANY, $this->permissions());
    $this->assertArrayNotHasKey($permission, $this->permissions(), 'No style store, no permission.');

    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $permissions = $this->permissions();
    $this->assertArrayHasKey($permission, $permissions);
    $this->assertSame(
      ['field.field.entity_test.entity_test.' . DaedalusStylishFieldManager::FIELD_NAME],
      $permissions[$permission]['dependencies']['config'],
      'The permission goes with the style store.',
    );
    $this->assertArrayNotHasKey(StylePolicy::permission('entity_test_rev', 'entity_test_rev'), $permissions);
  }

  /**
   * A style write is refused without the permission and lands with it or the umbrella.
   */
  public function testStyleWriteAsksThePermission(): void {
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->save();
    $key = 'container:' . $this->container->get('uuid')->generate();
    $permission = StylePolicy::permission('entity_test', 'entity_test');

    $result = $this->style($entity, $key, $this->createUser(['administer entity_test content']));
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame(['ACCESS_DENIED_PERMISSION', 'key'], [$error->getCode(), $error->getPath()]);
    $this->assertSame([$permission, StylePolicy::ANY], $error->getContext()['permissions']);
    $this->assertTrue($entity->get(DaedalusStylishFieldManager::FIELD_NAME)->isEmpty(), 'Nothing was written.');

    $result = $this->style($entity, $key, $this->createUser(['administer entity_test content', $permission]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['padding' => '1rem'], $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap()[$key]['default']);

    $result = $this->style($entity, $key, $this->createUser(['administer entity_test content', StylePolicy::ANY]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

}
