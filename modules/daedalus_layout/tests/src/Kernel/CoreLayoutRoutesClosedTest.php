<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Core's Layout Builder write routes are closed for a storage Daedalus hosts.
 *
 * Every write to a hosted document goes through the op spine, so core's
 * own routes beside it (add, choose, remove and move a block; add, choose
 * and remove a section; revert an override) are forbidden for an entity's
 * overrides storage and a display's defaults storage, for an actor core
 * itself would let through; the two dialog routes daedalus_edit's
 * Configure dialog rides stay open; the navigation layout, a storage no
 * host owns, keeps core's routes. The overrides and defaults forms lose
 * their Save and Revert for a hosted storage in every mode, Discard
 * staying.
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class CoreLayoutRoutesClosedTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
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
    'daedalus_ui',
    'daedalus_layout',
  ];

  /**
   * The identities of the override's section and block.
   */
  protected const SECTION_UUID = '7d000000-0000-4000-8000-000000000001';
  protected const BLOCK_UUID = '7d000000-0000-4000-8000-000000000002';

  /**
   * The template's id.
   */
  protected const TEMPLATE_ID = 'node.page.default';

  /**
   * The overridden page node.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['system', 'navigation']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', self::SECTION_UUID);
    $section->appendComponent(new SectionComponent(self::BLOCK_UUID, 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]));
    $this->node = Node::create([
      'type' => 'page',
      'title' => 'Overridden',
      OverridesSectionStorage::FIELD_NAME => [$section],
    ]);
    $this->node->save();

    // An administrator passes every door core itself keeps on these
    // routes, so what forbids them is the hosted-storage check alone.
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The access result of a named route for the current user.
   */
  protected function routeAccess(string $route_name, array $parameters): AccessResultInterface {
    return $this->container->get('access_manager')->checkNamedRoute($route_name, $parameters, $this->container->get('current_user'), TRUE);
  }

  /**
   * Core's element routes, with the parameters beyond the storage's.
   *
   * @return array<string, array>
   *   Route name to parameters.
   */
  protected function closedRoutes(): array {
    return [
      'layout_builder.choose_section' => ['delta' => 0],
      'layout_builder.add_section' => ['delta' => 0, 'plugin_id' => 'layout_onecol'],
      'layout_builder.remove_section' => ['delta' => 0],
      'layout_builder.choose_block' => ['delta' => 0, 'region' => 'content'],
      'layout_builder.add_block' => ['delta' => 0, 'region' => 'content', 'plugin_id' => 'system_powered_by_block'],
      'layout_builder.choose_inline_block' => ['delta' => 0, 'region' => 'content'],
      'layout_builder.move_block_form' => ['delta' => 0, 'region' => 'content', 'uuid' => self::BLOCK_UUID],
      'layout_builder.remove_block' => ['delta' => 0, 'region' => 'content', 'uuid' => self::BLOCK_UUID],
      'layout_builder.move_block' => [
        'delta_from' => 0,
        'delta_to' => 0,
        'region_to' => 'content',
        'block_uuid' => self::BLOCK_UUID,
        'preceding_block_uuid' => '',
      ],
    ];
  }

  /**
   * The dialog routes the Configure verb opens, with their parameters.
   *
   * @return array<string, array>
   *   Route name to parameters.
   */
  protected function dialogRoutes(): array {
    return [
      'layout_builder.update_block' => ['delta' => 0, 'region' => 'content', 'uuid' => self::BLOCK_UUID],
      'layout_builder.configure_section' => ['delta' => 0],
    ];
  }

  /**
   * Asserts every closed route forbidden and every dialog route open.
   */
  protected function assertStorageClosed(string $storage_type, string $storage_id, string $label): void {
    $storage = ['section_storage_type' => $storage_type, 'section_storage' => $storage_id];
    foreach ($this->closedRoutes() as $route_name => $parameters) {
      $access = $this->routeAccess($route_name, $storage + $parameters);
      $this->assertTrue($access->isForbidden(), "$route_name is forbidden for $label.");
      $this->assertStringContainsString('hosted by Daedalus', (string) $access->getReason(), "$route_name is forbidden by the hosted-storage check for $label.");
    }
    foreach ($this->dialogRoutes() as $route_name => $parameters) {
      $this->assertTrue($this->routeAccess($route_name, $storage + $parameters)->isAllowed(), "$route_name stays open for $label: the Configure dialog rides it.");
    }
  }

  /**
   * An entity's overrides storage: the element routes and the revert are closed.
   */
  public function testOverridesStorageIsClosed(): void {
    $this->assertStorageClosed('overrides', 'node.' . $this->node->id(), 'the overrides storage');
    $access = $this->routeAccess('layout_builder.overrides.node.revert', ['node' => $this->node->id()]);
    $this->assertTrue($access->isForbidden(), 'The revert route is forbidden for the overrides storage.');
    $this->assertStringContainsString('hosted by Daedalus', (string) $access->getReason());
    $this->assertTrue($this->routeAccess('layout_builder.overrides.node.view', ['node' => $this->node->id()])->isAllowed(), 'The layout route itself, the canvas, stays open.');
  }

  /**
   * A display's defaults storage: the element routes are closed.
   */
  public function testDefaultsStorageIsClosed(): void {
    $this->assertStorageClosed('defaults', self::TEMPLATE_ID, 'the defaults storage');
  }

  /**
   * The navigation layout, a storage no host owns, keeps core's routes.
   */
  public function testHostlessStorageKeepsCoreRoutes(): void {
    $storage = ['section_storage_type' => 'navigation', 'section_storage' => 'navigation'];
    foreach ($this->closedRoutes() as $route_name => $parameters) {
      $this->assertTrue($this->routeAccess($route_name, $storage + $parameters)->isAllowed(), "$route_name stays open for the navigation layout.");
    }
  }

  /**
   * The overrides form loses Save and Revert for its hosted storage; Discard stays.
   */
  public function testOverridesFormLosesSaveAndRevert(): void {
    $storage = $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($this->node),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $form = $this->buildForm('node', $this->node, $storage);
    $this->assertFalse($form['actions']['submit']['#access'], 'Save layout is denied.');
    $this->assertFalse($form['actions']['revert']['#access'], 'Revert to defaults is denied.');
    $this->assertArrayHasKey('discard_changes', $form['actions']);
    $this->assertFalse(isset($form['actions']['discard_changes']['#access']) && $form['actions']['discard_changes']['#access'] === FALSE, 'Discard changes stays.');
    $this->assertFalse(isset($form['actions']['#access']) && $form['actions']['#access'] === FALSE, 'Outside Edit Mode the actions container itself stays.');
  }

  /**
   * The defaults form loses Save for its hosted storage.
   */
  public function testDefaultsFormLosesSave(): void {
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->load(self::TEMPLATE_ID);
    $storage = $this->container->get('plugin.manager.layout_builder.section_storage')->load('defaults', [
      'display' => EntityContext::fromEntity($display),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $form = $this->buildForm('entity_view_display', $display, $storage);
    $this->assertFalse($form['actions']['submit']['#access'], 'Save layout is denied.');
    $this->assertArrayHasKey('discard_changes', $form['actions']);
    $this->assertFalse(isset($form['actions']['discard_changes']['#access']) && $form['actions']['discard_changes']['#access'] === FALSE, 'Discard changes stays.');
  }

  /**
   * Builds core's layout_builder entity form on a storage, alters included.
   */
  protected function buildForm(string $entity_type_id, object $entity, SectionStorageInterface $storage): array {
    $storage = $this->container->get('layout_builder.tempstore_repository')->get($storage);
    $form_object = $this->container->get('entity_type.manager')->getFormObject($entity_type_id, 'layout_builder');
    $form_object->setEntity($entity);
    return $this->container->get('form_builder')->getForm($form_object, $storage);
  }

}
