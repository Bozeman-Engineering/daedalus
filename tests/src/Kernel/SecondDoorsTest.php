<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel;

use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every daedalus settings page has two doors: its natural home and the door.
 *
 * The Daedalus config category (Configuration » Daedalus) lists each
 * settings page a second time; the page's first door (drupal/ai's own menu,
 * core's Development category, Appearance, the Content page's tabs) stays
 * exactly where it was. Both are menu links on the one route, so this test
 * reads the menu link plugin definitions with the owning modules enabled
 * and pins two rosters:
 *
 * - the AI settings forms (DOORS), keyed by route: exactly two links per
 *   route, one under `daedalus.admin_config_ai` and one under the first
 *   door's parent;
 * - the pointer links to Appearance and Content (POINTERS), keyed by link
 *   id because their routes are owned elsewhere (entity collections, the
 *   frame canvas): each exists under its Daedalus sub-category on the first
 *   door's route, and the first door still stands.
 *
 * A kernel test, not a functional one: the contract is the link
 * definitions, and the category pages' rendering is pinned by
 * ConfigCategoryTest.
 */
#[Group('daedalus')]
final class SecondDoorsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'key',
    'ai',
    'ai_assistant_api',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_config',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    'daedalus_ai',
    'daedalus_ai_ledger',
    'daedalus_ai_memory',
    'daedalus_ai_upstream',
    // The blueprint entity's chain: options, the media icon field, and the
    // library itself.
    'options',
    'image',
    'media',
    'daedalus_blueprint_library',
  ];

  /**
   * The theme whose frame the pointer roster derives a link for.
   */
  private const FRAMED = 'stark';

  /**
   * The AI settings routes, each with the parents of its two doors.
   *
   * The first door is the form's natural home (drupal/ai's Tools &
   * Automation or AI category, core's Development category); the second is
   * the Daedalus AI sub-category. daedalus_ai's own form is the one whose
   * first door is the sub-category and whose second is drupal/ai's.
   */
  private const DOORS = [
    'daedalus_ai.settings' => ['daedalus.admin_config_ai', 'ai.admin_config_tools'],
    'daedalus_ai_memory.settings' => ['ai.admin_config_tools', 'daedalus.admin_config_ai'],
    'daedalus_ai_ledger.settings' => ['ai.admin_config_tools', 'daedalus.admin_config_ai'],
    'daedalus_ai_upstream.settings' => ['ai.admin_config_tools', 'daedalus.admin_config_ai'],
    'daedalus_blueprint_ai.settings' => ['ai.admin_settings', 'daedalus.admin_config_ai'],
    'daedalus_blueprint_config.settings' => ['system.admin_config_development', 'daedalus.admin_config_ai'],
  ];

  /**
   * The pointer links, keyed by link id.
   *
   * Each names the route it points at, the Daedalus sub-category it hangs
   * under, and its first door: a menu link id under Appearance
   * (system.themes_page), or NULL for the blueprint library, whose first
   * door is a local task on the Content page rather than a menu link. The
   * frame's link is a derivative (one per frame, FrameMenuLink), so its id
   * carries the framed theme and its route the frame as a parameter.
   */
  private const POINTERS = [
    'entity.daedalus_skin.collection.daedalus' => [
      'route_name' => 'entity.daedalus_skin.collection',
      'parent' => 'daedalus.admin_config_styling',
      'first_door' => 'entity.daedalus_skin.collection',
    ],
    'entity.design_system.collection.daedalus' => [
      'route_name' => 'entity.design_system.collection',
      'parent' => 'daedalus.admin_config_styling',
      'first_door' => 'entity.design_system.collection',
    ],
    'daedalus_layout.frame.daedalus:' . self::FRAMED => [
      'route_name' => FrameInterface::CANVAS_ROUTE,
      'parent' => 'daedalus.admin_config_styling',
      'first_door' => 'daedalus_layout.frame:' . self::FRAMED,
    ],
    'entity.blueprint.collection.daedalus' => [
      'route_name' => 'entity.blueprint.collection',
      'parent' => 'daedalus.admin_config_ui',
      'first_door' => NULL,
    ],
  ];

  /**
   * Each AI settings route is linked under the AI sub-category and its home.
   */
  public function testEachRouteHasBothDoors(): void {
    $definitions = $this->container->get('plugin.manager.menu.link')->getDefinitions();

    foreach (self::DOORS as $route => $expected) {
      $parents = [];
      foreach ($definitions as $definition) {
        if ($definition['route_name'] === $route) {
          $parents[] = $definition['parent'];
        }
      }
      sort($parents);
      sort($expected);
      $this->assertSame($expected, $parents, "Route $route is linked once under daedalus.admin_config_ai and once under its first door, and nowhere else.");
    }
  }

  /**
   * Each Appearance and Content page is pointed at from its sub-category.
   */
  public function testPointerLinks(): void {
    // The frame's links derive per frame entity; give the deriver one.
    Frame::create(['id' => self::FRAMED])->save();
    $definitions = $this->container->get('plugin.manager.menu.link')->getDefinitions();

    foreach (self::POINTERS as $id => $expected) {
      $this->assertArrayHasKey($id, $definitions, "The pointer link $id is defined.");
      $this->assertSame($expected['route_name'], $definitions[$id]['route_name'], "$id points at the first door's route.");
      $this->assertSame($expected['parent'], $definitions[$id]['parent'], "$id hangs under its Daedalus sub-category.");

      if ($expected['first_door'] !== NULL) {
        $first = $expected['first_door'];
        $this->assertArrayHasKey($first, $definitions, "The first door $first still stands.");
        $this->assertSame('system.themes_page', $definitions[$first]['parent'], "$first still hangs under Appearance.");
        $this->assertSame($expected['route_name'], $definitions[$first]['route_name'], "$first and $id are two links on one route.");
        $this->assertSame($definitions[$first]['route_parameters'], $definitions[$id]['route_parameters'], "$first and $id carry the same route parameters.");
      }
    }

    // The frame's derivative names the frame, as the Appearance one does.
    $frame_link = $definitions['daedalus_layout.frame.daedalus:' . self::FRAMED];
    $this->assertSame(['daedalus_frame' => self::FRAMED], $frame_link['route_parameters']);
    $this->assertSame('Site frame: ' . self::FRAMED, (string) $frame_link['title'], 'The derived title names the theme (its id, the theme being uninstalled here).');

    // The blueprint library's first door is the Blueprints tab on the
    // Content page, a local task; the pointer is its only menu link.
    $tasks = $this->container->get('plugin.manager.menu.local_task')->getDefinitions();
    $this->assertArrayHasKey('entity.blueprint.collection', $tasks);
    $this->assertSame('system.admin_content', $tasks['entity.blueprint.collection']['base_route']);
    $blueprint_links = array_filter($definitions, static fn (array $definition): bool => $definition['route_name'] === 'entity.blueprint.collection');
    $this->assertSame(['entity.blueprint.collection.daedalus'], array_keys($blueprint_links), 'The blueprint collection has exactly one menu link, the pointer.');
  }

}
