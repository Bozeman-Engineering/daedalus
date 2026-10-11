<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_layout\Plugin\Sidebar\PlaceBlock;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus_ui\Traits\CurrentRouteTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Add panel mounts by capability: where the actor may insert somewhere.
 *
 * The panel places into the route host's document, so it exists where
 * the host has one AND the capability spine's verdict set allows insert
 * on the root or on some element: the editor with core's override
 * permission on their own page, the admin on a template. An editor
 * without the permission (the role policy refuses insert everywhere), a
 * plain host (ownership refuses insert everywhere: the document is the
 * template's) and a template the actor may not open mount no panel.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(PlaceBlock::class)]
#[Group('daedalus_layout')]
class PlaceBlockAppliesTest extends KernelTestBase {

  use CurrentRouteTrait;
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
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
  ];

  /**
   * The non-overridable bundle: its entities are plain hosts.
   */
  protected const PLAIN_BUNDLE = 'template';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content', 'daedalus_ui']);
    $this->setUpCurrentUser([], [], TRUE);

    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => TRUE])->save();

    // The overridable bundle: entities host their own document.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->appendSection($this->templateSection())
      ->save();

    // The non-overridable bundle: its entities render the display's.
    EntityTestHelper::createBundle(self::PLAIN_BUNDLE, 'Template bundle', 'entity_test');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => self::PLAIN_BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->appendSection($this->templateSection())
      ->save();
  }

  /**
   * A template section holding one inline block.
   */
  protected function templateSection(): Section {
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->container->get('uuid')->generate());
    $block = BlockContent::create(['type' => 'basic', 'info' => 'Template text', 'reusable' => FALSE]);
    $section->appendComponent(new SectionComponent($this->container->get('uuid')->generate(), 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Template text',
      'label_display' => '0',
      'block_serialized' => serialize($block),
    ]));
    return $section;
  }

  /**
   * Whether the Add panel applies on the current route.
   */
  protected function applies(): bool {
    return $this->container->get('plugin.manager.daedalus_ui_sidebars')->createInstance('place_block')->applies();
  }

  /**
   * On a page of an overridable bundle, the override permission opens the panel.
   */
  public function testOwnDocumentFollowsTheOverridePermission(): void {
    $entity = EntityTest::create(['name' => 'Own page']);
    $entity->save();

    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertTrue($this->applies(), 'An editor who may configure the layout may insert: the panel mounts.');

    $this->setUpCurrentUser([], ['administer entity_test content']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertFalse($this->applies(), 'Without the override permission insert is refused everywhere: no panel.');
  }

  /**
   * Where it applies, the panel is a mount and the faces' settings.
   *
   * No item markup: the bundle draws every row from the attached answer
   * (the page's faces, the Container first) and the materials store.
   */
  public function testBuildMountsThePanel(): void {
    $entity = EntityTest::create(['name' => 'Own page']);
    $entity->save();
    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $build = $this->container->get('plugin.manager.daedalus_ui_sidebars')->createInstance('place_block')->build();

    $this->assertSame(PlaceBlock::MOUNT_ID, $build['mount']['#attributes']['id']);
    $this->assertArrayHasKey('data-daedalus-layout-add-panel-mount', $build['mount']['#attributes']);
    $this->assertContains('daedalus_layout/add_panel', $build['#attached']['library']);
    $faces = array_column($build['#attached']['drupalSettings']['daedalusLayout']['addPanel']['faces'], 'id');
    $this->assertSame('daedalus_layout_section', $faces[0], 'The Container leads the faces.');
    $this->assertContains('inline_block:basic', $faces, 'The page\'s placeable blocks ride the settings.');
  }

  /**
   * A plain host places nothing: the document is the template's.
   */
  public function testPlainHostMountsNoPanel(): void {
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Plain page']);
    $entity->save();
    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $this->assertFalse($this->applies(), 'Ownership refuses insert everywhere on a plain host, whatever the role.');
  }

  /**
   * On a template, the display's update access decides.
   */
  public function testTemplateFollowsDisplayAccess(): void {
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $storage = $this->container->get('plugin.manager.layout_builder.section_storage')->load('defaults', [
      'display' => EntityContext::fromEntity($display),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);

    $this->setUpCurrentUser([], [], TRUE);
    $this->setCurrentRoute('layout_builder.defaults.entity_test.view', ['section_storage' => $storage], 'edit');
    $this->assertTrue($this->applies(), 'The admin may insert into the template.');

    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $this->setCurrentRoute('layout_builder.defaults.entity_test.view', ['section_storage' => $storage], 'edit');
    $this->assertFalse($this->applies(), 'An actor who may not update the display may insert nowhere on it.');
  }

}
