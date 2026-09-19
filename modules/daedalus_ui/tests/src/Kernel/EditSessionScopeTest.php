<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\EditSessionScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the edit-session scope derivation.
 *
 * The scope names the BroadcastChannel that pairs secondary windows
 * (daedalus_stylish's popped-out inspector, the responsive preview) with the editing
 * page, so its shape is a cross-module contract: entity uuid + langcode +
 * workspace, ':'-joined, with 'live' standing in when no workspace is
 * active (or the workspaces module is absent).
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class EditSessionScopeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'entity_test',
    'block',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * Derives uuid:langcode:live from the container-wired service.
   *
   * Workspaces is absent here, which also proves the optional
   * '@?workspaces.manager' wiring compiles and degrades to 'live'.
   */
  public function testDeriveWithoutWorkspaces(): void {
    $this->installEntitySchema('entity_test');
    $entity = EntityTest::create(['name' => 'scoped']);
    $entity->save();

    $scope = $this->container->get(EditSessionScope::class)->derive($entity);
    $this->assertSame($entity->uuid() . ':' . $entity->language()->getId() . ':live', $scope);
  }

}
