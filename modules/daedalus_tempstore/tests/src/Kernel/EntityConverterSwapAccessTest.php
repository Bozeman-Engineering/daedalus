<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that the page swap asks update access on the stored entity.
 *
 * The activation voters answer whether an editing surface is active for
 * the request: the mode it names and the surface permissions. Whether the
 * current user may edit the entity a route names is the converter's own
 * question, asked of the entity as it is saved. The real voters of the two
 * editing surfaces are installed here, so both vote for the swap in every
 * leg and the answer is the converter's.
 */
#[Group('daedalus_tempstore')]
#[RunTestsInSeparateProcesses]
final class EntityConverterSwapAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The module chain of the two editing surfaces, whose voters this test
   * runs; a kernel environment resolves no dependencies of its own.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'node',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'image',
    'media',
    'options',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * The unsaved copy answers a route parameter only for an editor.
   *
   * Two users hold the same permissions, both surface permissions among
   * them, and the request names the mode. One wrote the page and may
   * update it; the other may only view it.
   */
  public function testSwapAsksUpdateAccessOnTheStoredEntity(): void {
    $permissions = [
      'access content',
      'edit own page content',
      'access inline editing',
      'use daedalus ui edit mode',
    ];
    // The first account created is the super user; neither actor is it.
    $this->createUser();
    $editor = $this->createUser($permissions);
    $viewer = $this->createUser($permissions);
    $node = Node::create([
      'type' => 'page',
      'title' => 'Saved title',
      'uid' => $editor->id(),
      'status' => 1,
    ]);
    $node->save();

    // The unsaved copy names the viewer as its author: who may have it is
    // a question for what is saved.
    $staged = clone $node;
    $staged->setTitle('Staged title');
    $staged->setOwnerId($viewer->id());
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);

    $converter = $this->container->get(EntityConverter::class);
    $voters = $this->container->get('daedalus_tempstore.activation_voters');
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.node.canonical');
    $convert = fn () => $this->container->get('paramconverter_manager')->convert([
      'node' => $node->id(),
      '_route_object' => $route,
    ])['node'];

    foreach (['edit', 'preview'] as $mode) {
      $request = Request::create('/node/' . $node->id(), 'GET', ['navigationMode' => $mode]);
      $request->setSession($this->container->get('request_stack')->getSession());
      $this->container->get('request_stack')->push($request);

      $this->setCurrentUser($viewer);
      $this->assertTrue($voters->isActive(), "$mode: the voters allow the swap for the viewer.");
      $converted = $convert();
      $this->assertSame('Saved title', $converted->label(), "$mode: a user who may not update the page gets the stored entity.");
      $this->assertNull($converter->storedOf($converted), "$mode: nothing was swapped in for the viewer.");
      $stored = Node::load($node->id());
      $this->assertSame('Saved title', $converter->loadEntityFromTempstore($stored)->label(), "$mode: a direct ask answers the viewer the same.");

      $this->setCurrentUser($editor);
      $this->assertTrue($voters->isActive(), "$mode: the voters allow the swap for the editor.");
      $converted = $convert();
      $this->assertSame('Staged title', $converted->label(), "$mode: a user who may update the page gets the unsaved copy.");
      $this->assertSame('Saved title', $converter->storedOf($converted)?->label(), "$mode: the copy stands for the stored entity.");
      // A copy already swapped in is still judged by what it stands for.
      $this->assertSame('Staged title', $converter->loadEntityFromTempstore($converted)->label());

      $this->container->get('request_stack')->pop();
    }
  }

}
