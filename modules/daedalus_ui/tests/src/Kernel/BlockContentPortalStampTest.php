<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Component\Utility\Html;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the portal stamp on a theme-region block_content placement.
 *
 * A page-builder block_content placed in a theme region (a footer) is inert
 * on every page but its own editing surface; the placement's markup root
 * carries a portal (the entity's label and its editing URL) so the canvas
 * can offer the way there. The stamp must appear exactly for an editor (edit
 * mode permission plus update access on the entity) on a foreign route: not
 * for a user without the permission, not without update access, not on a
 * placement wrapping a block_content with no layout of its own, and not on
 * the entity's own editing route (where the entity IS the canvas).
 */
#[RunTestsInSeparateProcesses]
class BlockContentPortalStampTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The module's hard dependencies (daedalus_blueprint + daedalus_blueprint_api)
   * are invisible to kernel environments and must be listed explicitly or
   * the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
    'block',
    'block_content',
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
   * A placement wrapping an LB-enabled block_content.
   */
  protected Block $footerPlacement;

  /**
   * A placement wrapping a block_content of a bundle without Layout Builder.
   */
  protected Block $plainPlacement;

  /**
   * The LB-enabled block_content the footer placement wraps.
   */
  protected BlockContent $footer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);

    // An LB-enabled bundle: enabling overrides on the display creates the
    // layout_builder__layout field, the fact the predicate gates on.
    BlockContentType::create([
      'id' => 'layout_block',
      'label' => 'Layout block',
    ])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'layout_block',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    // A bundle without Layout Builder.
    BlockContentType::create([
      'id' => 'basic',
      'label' => 'Basic',
    ])->save();

    // The block_content deriver only exposes reusable entities, so the
    // placements' plugin ids resolve.
    $this->footer = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Footer',
      'reusable' => TRUE,
    ]);
    $this->footer->save();
    $plain = BlockContent::create([
      'type' => 'basic',
      'info' => 'Plain',
      'reusable' => TRUE,
    ]);
    $plain->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    $this->footerPlacement = Block::create([
      'id' => 'stark_footer',
      'theme' => 'stark',
      'plugin' => 'block_content:' . $this->footer->uuid(),
      'region' => 'content',
    ]);
    $this->footerPlacement->save();
    $this->plainPlacement = Block::create([
      'id' => 'stark_plain',
      'theme' => 'stark',
      'plugin' => 'block_content:' . $plain->uuid(),
      'region' => 'content',
    ]);
    $this->plainPlacement->save();
  }

  /**
   * Renders a placement and returns its block template root element.
   *
   * @return array{\DOMElement, array}
   *   The block root element and the rendered build (for its cacheability).
   */
  protected function renderPlacement(Block $block): array {
    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('block')
      ->view($block);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $root = (new \DOMXPath(Html::load($html)))
      ->query('//*[@id="' . Html::getId('block-' . $block->id()) . '"]')
      ->item(0);
    $this->assertInstanceOf(\DOMElement::class, $root, 'The placement renders its block template root.');
    return [$root, $build];
  }

  /**
   * Asserts an element carries no portal attributes.
   */
  protected function assertNoPortal(\DOMElement $root): void {
    $this->assertFalse($root->hasAttribute('data-daedalus-ui-portal-url'));
    $this->assertFalse($root->hasAttribute('data-daedalus-ui-portal-label'));
  }

  /**
   * An editor's placement carries the label and the editing URL.
   */
  public function testEditorSeesPortal(): void {
    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'administer block content']);

    [$root, $build] = $this->renderPlacement($this->footerPlacement);
    $this->assertSame(
      '/block-content/' . $this->footer->id(),
      $root->getAttribute('data-daedalus-ui-portal-url'),
      'The portal URL is the standalone editing surface.'
    );
    $this->assertSame('Footer', $root->getAttribute('data-daedalus-ui-portal-label'), 'The portal label is the block description.');
    $this->assertContains('user.permissions', $build['#cache']['contexts'], 'The stamp varies the cached block by permissions.');

    // A block_content without a layout of its own is no portal.
    [$plain_root] = $this->renderPlacement($this->plainPlacement);
    $this->assertNoPortal($plain_root);
  }

  /**
   * No portal without the edit mode permission.
   */
  public function testNoPortalWithoutPermission(): void {
    $this->setUpCurrentUser([], ['administer block content']);
    [$root] = $this->renderPlacement($this->footerPlacement);
    $this->assertNoPortal($root);
  }

  /**
   * No portal without update access on the content entity.
   */
  public function testNoPortalWithoutUpdateAccess(): void {
    $this->setUpCurrentUser([], ['use daedalus ui edit mode']);
    [$root] = $this->renderPlacement($this->footerPlacement);
    $this->assertNoPortal($root);
  }

  /**
   * No portal on the entity's own editing route, where it is the canvas.
   */
  public function testNoPortalOnOwnEditingRoute(): void {
    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'administer block content']);

    $route_name = 'daedalus_ui.block_content.edit';
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/block-content/' . $this->footer->id());
    // The kernel's tearDown reads the session off the current request.
    $request->setSession($request_stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($route_name));
    $request->attributes->set('block_content', $this->footer);
    $request->attributes->set('view_mode', 'full');
    $request->attributes->set('_raw_variables', new ParameterBag([
      'block_content' => $this->footer->id(),
      'view_mode' => 'full',
    ]));
    $request_stack->push($request);
    $this->container->get('daedalus_ui.ui')->clearDerivedEntity();
    $this->assertSame($this->footer->id(), $this->container->get('daedalus_ui.ui')->deriveEntityFromRoute()?->id(), 'The route is the footer\'s own editing surface.');

    [$root] = $this->renderPlacement($this->footerPlacement);
    $this->assertNoPortal($root);
  }

}
