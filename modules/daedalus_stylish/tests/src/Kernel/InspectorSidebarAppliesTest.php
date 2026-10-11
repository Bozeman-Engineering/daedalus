<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_stylish\Capability\StylePolicy;
use Drupal\daedalus_stylish\Plugin\Sidebar\InspectorSidebar;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\daedalus_ui\Traits\CurrentRouteTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The style inspector mounts by capability: where the actor may style something.
 *
 * The inspector exists where the route host keeps a style map AND the
 * capability spine's verdict set allows style on the root or on some
 * element: an editor holding the bundle's style permission on a styled
 * page, including a plain host (the page's own root key stays the
 * page's, whoever owns the layout). An editor without the permission
 * (the style policy refuses everywhere) and a bundle without a store
 * (nothing to write) mount no inspector.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(InspectorSidebar::class)]
#[Group('daedalus_stylish')]
class InspectorSidebarAppliesTest extends KernelTestBase {

  use CurrentRouteTrait;
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
   * The non-overridable bundle: its entities are plain hosts.
   */
  protected const PLAIN_BUNDLE = 'template';

  /**
   * The bundle with no style store.
   */
  protected const BARE_BUNDLE = 'bare';

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
    $this->installConfig(['daedalus_stylish', 'daedalus_ui']);
    $this->setUpCurrentUser([], [], TRUE);

    // A styled bundle hosting its own fields, a styled non-overridable
    // bundle rendering its display's template, and a bare bundle. The
    // style stores exist before any user is created: the per-bundle
    // permission exists only once the field does.
    EntityTestHelper::createBundle(self::PLAIN_BUNDLE, 'Template bundle', 'entity_test');
    EntityTestHelper::createBundle(self::BARE_BUNDLE, 'Bare bundle', 'entity_test');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => self::PLAIN_BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->appendSection(new Section('layout_onecol'))
      ->save();
    $field_manager = $this->container->get('daedalus_stylish.field_manager');
    $field_manager->enable('entity_test', 'entity_test');
    $field_manager->enable('entity_test', self::PLAIN_BUNDLE);
  }

  /**
   * Whether the inspector applies on the current route.
   */
  protected function applies(): bool {
    return $this->container->get('plugin.manager.daedalus_ui_sidebars')->createInstance('daedalus_stylish_inspector')->applies();
  }

  /**
   * On a styled page, the bundle's style permission opens the inspector.
   */
  public function testStyledPageFollowsTheStylePermission(): void {
    $entity = EntityTest::create(['name' => 'Styled page']);
    $entity->save();
    $permission = StylePolicy::permission('entity_test', 'entity_test');

    $this->setUpCurrentUser([], ['administer entity_test content', $permission]);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertTrue($this->applies(), 'An editor holding the bundle\'s style permission may style: the inspector mounts.');

    $this->setUpCurrentUser([], ['administer entity_test content']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertFalse($this->applies(), 'Without the permission style is refused everywhere: no inspector.');

    $this->setUpCurrentUser([], ['administer entity_test content', StylePolicy::ANY]);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertTrue($this->applies(), 'The umbrella permission opens it too.');
  }

  /**
   * A bundle without a style store mounts no inspector, whatever the role.
   */
  public function testBareBundleMountsNoInspector(): void {
    $entity = EntityTest::create(['type' => self::BARE_BUNDLE, 'name' => 'Bare page']);
    $entity->save();
    $this->setUpCurrentUser([], [], TRUE);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertFalse($this->applies(), 'No store, nothing to style, no inspector.');
  }

  /**
   * A plain host keeps its own root styles: the inspector mounts for a styler.
   */
  public function testPlainHostKeepsItsRootStyles(): void {
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Plain page']);
    $entity->save();
    $permission = StylePolicy::permission('entity_test', self::PLAIN_BUNDLE);

    $this->setUpCurrentUser([], ['administer entity_test content', $permission]);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $host = $this->container->get('daedalus_ui.ui')->deriveHostFromRoute();
    $this->assertFalse($host->hasDocument(), 'The page renders its template.');
    $this->assertTrue($this->applies(), 'The template\'s elements are refused, the page\'s own root key is not: the inspector mounts.');

    $this->setUpCurrentUser([], ['administer entity_test content']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertFalse($this->applies(), 'Without the permission even the root is refused: no inspector.');
  }

}
