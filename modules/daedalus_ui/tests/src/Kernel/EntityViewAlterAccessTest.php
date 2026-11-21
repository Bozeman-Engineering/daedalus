<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * The editing canvas is built for an actor who may edit the host.
 *
 * The entity view alter replaces the route entity's build with the canvas
 * (the layout_builder_plus element) in Edit Mode. The mode is the
 * client's word (a cookie or a query parameter), so the alter asks
 * CanvasAccess, the question the Edit mode's own availability asks, before
 * it builds anything: a user who may only view the entity gets the
 * ordinary render in every mode, with nothing staged and each block
 * behind its own access check, and an editor still gets the canvas.
 *
 * @see \Drupal\daedalus_ui\Hooks\EntityViewAlter::alter()
 * @see \Drupal\daedalus_ui\CanvasAccess
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class EntityViewAlterAccessTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Node is the content type with an owner: two users of one role, one of
   * whom may edit the page.
   */
  protected static $modules = ['node'];

  /**
   * The entity rendered on its canonical route.
   */
  private EntityTest $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // One section holding a block every visitor sees and a block only an
    // anonymous visitor does (the login block's own access check).
    $this->entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [
        new SectionComponent($this->uuid->generate(), 'content', [
          'id' => 'system_powered_by_block',
          'label' => 'Powered by',
          'label_display' => '0',
        ]),
        new SectionComponent($this->uuid->generate(), 'content', [
          'id' => 'user_login_block',
          'label' => 'Log in',
          'label_display' => '0',
        ]),
      ]),
    ]);
  }

  /**
   * A view-only user in Edit Mode gets the ordinary render.
   */
  public function testViewOnlyUserGetsTheOrdinaryRender(): void {
    // Both surface permissions a canvas needs, and view access: everything
    // but update access to the entity.
    $this->setUpCurrentUser([], [
      'use daedalus ui edit mode',
      'configure any layout',
      'view test entity',
    ]);
    $this->assertTrue($this->entity->access('view'));
    $this->assertFalse($this->entity->access('update'));
    $this->assertFalse($this->container->get('daedalus_ui.canvas_access')->allows($this->host($this->entity)));

    [$build, $html] = $this->renderOnCanonicalRoute();
    $xpath = $this->htmlXpath($html);

    $this->assertArrayNotHasKey('layout', $build, 'The build carries no canvas element.');
    $this->assertArrayHasKey('_layout_builder', $build, 'The view render\'s own section list stays.');
    $this->assertSame(0, $xpath->query('//*[@id="layout-builder"]')->length, 'No canvas renders.');
    $this->assertStringContainsString('Powered by', $html, 'A block every visitor sees renders.');
    $this->assertSame(0, $xpath->query('//form[contains(@class, "user-login-form")]')->length, 'A block the user may not see stays behind its own access check.');
    $this->assertSame(0, $build['#cache']['max-age'] ?? NULL, 'The render is not a shared cache entry: the answer was this user\'s access.');
    $this->assertNothingStaged();
  }

  /**
   * An editor in Edit Mode still gets the canvas.
   */
  public function testEditorGetsTheCanvas(): void {
    $this->setUpCurrentUser([], [
      'use daedalus ui edit mode',
      'configure any layout',
      'view test entity',
      'administer entity_test content',
    ]);
    $this->assertTrue($this->entity->access('update'));
    $this->assertTrue($this->container->get('daedalus_ui.canvas_access')->allows($this->host($this->entity)));

    [$build, $html] = $this->renderOnCanonicalRoute();
    $xpath = $this->htmlXpath($html);

    $this->assertSame('layout_builder_plus', $build['layout']['#type'] ?? NULL, 'The canvas took the fields\' place in the entity build.');
    $this->assertArrayNotHasKey('_layout_builder', $build, 'The view render\'s section list left with the fields.');
    $this->assertSame(1, $xpath->query('//*[@id="layout-builder"]')->length, 'The canvas renders.');
  }

  /**
   * A view-only user out of Edit Mode is untouched by the alter.
   */
  public function testViewOnlyUserOutOfEditModeIsCacheable(): void {
    $this->setUpCurrentUser([], [
      'use daedalus ui edit mode',
      'configure any layout',
      'view test entity',
    ]);
    [$build] = $this->renderOnCanonicalRoute('none');
    $this->assertArrayNotHasKey('layout', $build);
    $this->assertNotSame(0, $build['#cache']['max-age'] ?? NULL, 'Out of Edit Mode the ordinary render keeps its cacheability.');
  }

  /**
   * The Edit Mode render of an unsaved copy is never a shared cache entry.
   *
   * On a display without Layout Builder the canvas is the entity's own
   * field render. Two users hold one role, which may edit its own pages;
   * one wrote the page and has an unsaved change to a field of it. Each
   * asks for the page in Edit Mode through the renderer and its cache, the
   * author first: the author reads the unsaved copy, the other user reads
   * what is saved.
   */
  public function testEditModeRenderOfAnUnsavedCopyIsNotShared(): void {
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    // The date formats the page's submitted line is written with.
    $this->installConfig(['system']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // One text field, shown by a stored display that Layout Builder is not
    // enabled on.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_note',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'page',
      'field_name' => 'field_note',
      'label' => 'Note',
    ])->save();
    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'page');
    $display->setComponent('field_note', ['type' => 'string'])->save();
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $this->assertFalse($display->isLayoutBuilderEnabled(), 'The page type\'s display is not Layout Builder enabled.');

    $role = $this->createRole([
      'access content',
      'edit own page content',
      'use daedalus ui edit mode',
    ]);
    $author = $this->createUser([], NULL, FALSE, ['roles' => [$role]]);
    $reader = $this->createUser([], NULL, FALSE, ['roles' => [$role]]);
    $node = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'field_note' => 'Saved note',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    $this->setCurrentUser($author);
    $staged = clone $node;
    $staged->set('field_note', 'Staged note');
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);

    $html = $this->renderNodeThroughTheRenderCache($node);
    $this->assertTrue($node->access('update'));
    $this->assertStringContainsString('Staged note', $html, 'The author reads the unsaved copy in Edit Mode.');

    $this->setCurrentUser($reader);
    $html = $this->renderNodeThroughTheRenderCache($node);
    $this->assertFalse($node->access('update'));
    $this->assertStringContainsString('Saved note', $html, 'A user of the same role who may not edit the page reads what is saved.');
    $this->assertStringNotContainsString('Staged note', $html, 'The author\'s Edit Mode render was not a shared cache entry.');

    // The author's own next read is the copy as it is now, not an entry
    // kept from the first read.
    $this->setCurrentUser($author);
    $staged->set('field_note', 'Staged again');
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);
    $html = $this->renderNodeThroughTheRenderCache($node);
    $this->assertStringContainsString('Staged again', $html, 'The author reads the unsaved copy as it now is.');
  }

  /**
   * Renders a node on its canonical route in Edit Mode, cache included.
   *
   * The route's entity is the one the param converter hands the current
   * user (the unsaved copy for an editor, the stored entity for anyone
   * else), and the view builder's build is rendered whole: its cache keys
   * and its #pre_render callback, which runs the entity view alter on a
   * cache miss only.
   */
  private function renderNodeThroughTheRenderCache(NodeInterface $node): string {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/node/' . $node->id(), 'GET', [], ['navigationMode' => 'edit']);
    $request->setSession($request_stack->getSession());
    $request_stack->push($request);
    // Asked once the request names the mode, as the router asks it.
    $route_entity = $this->container->get(EntityConverter::class)->loadEntityFromTempstore(Node::load($node->id()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.node.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/node/{node}'));
    $request->attributes->set('node', $route_entity);
    $request->attributes->set('_raw_variables', new InputBag(['node' => $node->id()]));
    $this->container->get('daedalus_ui.ui')->clearDerivedHost();

    // The default view mode: the one the view builder gives cache keys
    // with no view mode configuration installed.
    $build = $this->entityTypeManager->getViewBuilder('node')->view($route_entity, 'default');
    $this->assertNotEmpty($build['#cache']['keys'] ?? [], 'The page\'s render is one the render cache would keep.');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $request_stack->pop();
    return $html;
  }

  /**
   * Builds and renders the entity on its canonical route.
   *
   * The view builder's #pre_render callback is run by hand so the altered
   * build can be read; a copy renders, since the build is already built.
   *
   * @return array{0: array, 1: string}
   *   The altered build and its rendered HTML.
   */
  private function renderOnCanonicalRoute(string $mode = 'edit'): array {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/entity_test/' . $this->entity->id(), 'GET', [], ['navigationMode' => $mode]);
    $request->setSession($request_stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.entity_test.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/entity_test/{entity_test}'));
    $request->attributes->set('entity_test', $this->entity);
    // The raw route variables: a block that links to the current page (the
    // login form's action) generates its URL from them.
    $request->attributes->set('_raw_variables', new InputBag(['entity_test' => $this->entity->id()]));
    $request_stack->push($request);
    $this->container->get('daedalus_ui.ui')->clearDerivedHost();

    $view_builder = $this->entityTypeManager->getViewBuilder('entity_test');
    assert($view_builder instanceof EntityViewBuilder);
    $build = $view_builder->build($view_builder->view($this->entity, 'full'));
    $render = $build;
    unset($render['#pre_render']);
    $html = (string) $this->container->get('renderer')->renderRoot($render);
    return [$build, $html];
  }

  /**
   * Asserts the render left nothing in the Layout Builder tempstore.
   */
  private function assertNothingStaged(): void {
    $this->assertFalse(
      $this->container->get('layout_builder.tempstore_repository')->has($this->getNestedStorage($this->entity)),
      'The entity\'s section storage has no Layout Builder tempstore entry.',
    );
    $this->assertSame(
      [],
      $this->container->get('keyvalue.expirable')->get('tempstore.shared.layout_builder.section_storage.overrides')->getAll(),
      'The Layout Builder tempstore is empty.',
    );
  }

}
