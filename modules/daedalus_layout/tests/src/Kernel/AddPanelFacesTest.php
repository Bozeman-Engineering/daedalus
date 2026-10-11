<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_layout\AddPanel\AddPanelAnswer;
use Drupal\daedalus_layout\AddPanel\AddPanelFaces;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The face: what a canvas can place, as one list.
 *
 * The Add panel's server answer for a canvas host is one face per
 * placeable block (the Container and every block the placement
 * restrictions admit on the host's own storage), the site's group
 * vocabulary in declaration order, and the editor's own marks. A face is
 * `{id, kind, label, description, icon, groups, favorite, editable}`: the
 * site's facts on the thing, with a block type's icon, groups and favorite
 * read from its settings and nothing per display, and whether the actor
 * may write its favorite for everyone. Blueprints are not in the
 * answer (the materials store carries them), and a saved blueprint's
 * canvas offers its SUBJECT bundle's blocks, since its storage answers the
 * subject as the entity being laid out.
 */
#[CoversClass(AddPanelFaces::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class AddPanelFacesTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'media',
    'options',
    'daedalus_blueprint_library',
    // Declares the site groups heroes and bands.
    'daedalus_layout_site_groups_test',
  ];

  /**
   * The faces service under test.
   */
  protected AddPanelFaces $faces;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('blueprint');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->faces = $this->container->get('daedalus_layout.add_panel_faces');
  }

  /**
   * A page's faces: the Container first, then what the restrictions admit.
   */
  public function testFacesOnPage(): void {
    $host = $this->hostOf($this->createTestEntity());
    $answer = $this->faces->answer($host);
    $faces = $this->facesById($answer);

    // The Container leads: the panel's own piece, a basic, a favorite by
    // the panel's word, wearing the panel's own icon.
    $this->assertSame(SectionBlock::PLUGIN_ID, $answer->faces[0]['id']);
    $container_definition = $this->container->get('plugin.manager.block')->getDefinition(SectionBlock::PLUGIN_ID);
    $this->assertSame([
      'id' => SectionBlock::PLUGIN_ID,
      'kind' => AddPanelFaces::KIND_BASIC,
      'label' => (string) $container_definition['admin_label'],
      'description' => '',
      'icon' => '/' . $this->container->get('extension.list.module')->getPath('daedalus_layout') . '/assets/container.svg',
      'groups' => [],
      'favorite' => TRUE,
      'editable' => FALSE,
    ], $answer->faces[0]);
    $this->assertCount(1, array_keys(array_column($answer->faces, 'id'), SectionBlock::PLUGIN_ID, TRUE), 'The Container is one face, not the panel\'s plus the plugin\'s.');

    // Every face the restrictions admit, each with the eight keys.
    $admitted = $this->container->get('daedalus_blueprint.placement_restrictions')->allowedBlockDefinitions($host->sectionStorage());
    unset($admitted[SectionBlock::PLUGIN_ID]);
    $this->assertSame(array_keys($admitted), array_slice(array_keys($faces), 1), 'One face per admitted block, in the restrictions\' order.');
    foreach ($answer->faces as $face) {
      $this->assertSame(['id', 'kind', 'label', 'description', 'icon', 'groups', 'favorite', 'editable'], array_keys($face), $face['id']);
    }

    // The page's own field blocks are its bundle's; a block type is a basic.
    $this->assertArrayHasKey('field_block:entity_test:entity_test:name', $faces);
    $this->assertSame((string) $admitted['field_block:entity_test:entity_test:name']['category'], $faces['field_block:entity_test:entity_test:name']['kind'], 'A field block files under core\'s category.');
    $this->assertSame(AddPanelFaces::KIND_BASIC, $faces['inline_block:basic']['kind']);
    $this->assertSame('Basic Block', $faces['inline_block:basic']['label']);

    // No blueprint rides the answer: the materials store carries them.
    $this->createBlueprint('Promo');
    $ids = array_column($this->faces->answer($host)->faces, 'id');
    $this->assertSame([], preg_grep('/^blueprint:/', $ids));

    // What the answer depends on: the actor, the block types, the host's
    // display and the declarations.
    $this->assertContains('user', $answer->cacheMetadata()->getCacheContexts());
    $tags = $answer->cacheMetadata()->getCacheTags();
    $this->assertContains('config:block_content_type_list', $tags);
    $this->assertContains('config:block_content.type.basic', $tags);
    $this->assertContains('config:core.entity_view_display.entity_test.entity_test.default', $tags);
    $this->assertContains(PageBuilderDeclarations::CACHE_TAG, $tags);
  }

  /**
   * A template's faces are the bundle's, like a page's of that bundle.
   */
  public function testFacesOnTemplate(): void {
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $template = $this->hostOf($display);
    $page = $this->hostOf($this->createTestEntity());
    $this->assertSame(
      array_column($this->faces->answer($page)->faces, 'id'),
      array_column($this->faces->answer($template)->faces, 'id'),
      'The template offers exactly what a page of its bundle offers.',
    );
    $this->assertContains('field_block:entity_test:entity_test:name', array_column($this->faces->answer($template)->faces, 'id'));
  }

  /**
   * A saved blueprint's canvas offers its subject bundle's blocks.
   *
   * The blueprint host's storage answers the subject (core's sample of the
   * subject bundle) as the entity being laid out and resolves the
   * subject's display, so the field blocks and the restrictions are the
   * bundle's the material is placed on, never the blueprint type's.
   */
  public function testFacesOnBlueprintCanvas(): void {
    $blueprint = $this->createBlueprint('Promo');
    $canvas = $this->hostOf($blueprint);
    $page = $this->hostOf($this->createTestEntity());

    $ids = array_column($this->faces->answer($canvas)->faces, 'id');
    $this->assertContains('field_block:entity_test:entity_test:name', $ids, 'The subject bundle\'s field block is offered.');
    $this->assertSame([], preg_grep('/^field_block:blueprint:/', $ids), 'None of the blueprint type\'s own fields is offered.');
    $this->assertSame(array_column($this->faces->answer($page)->faces, 'id'), $ids, 'The blueprint\'s canvas offers what a page of its subject bundle offers.');

    // A favorite is a fact on the thing, so the blueprint's canvas sees
    // the same default as a page: nothing is read per display.
    BlockContentType::load('basic')->setThirdPartySetting('daedalus_layout', 'favorite', TRUE)->save();
    $this->assertTrue($this->facesById($this->faces->answer($canvas))['inline_block:basic']['favorite']);
    $this->assertTrue($this->facesById($this->faces->answer($page))['inline_block:basic']['favorite']);
  }

  /**
   * A locked page offers its bundle's blocks for its slots, never a slot.
   *
   * A page of a locked bundle places into the slot sections of its own
   * document (through the template's slot doors), so the panel offers the
   * bundle's blocks as on any page of it; the slot block itself is the
   * template's to place (a page fills slots, it never declares them).
   */
  public function testFacesOnLockedPage(): void {
    $this->installEntitySchema('entity_test_rev');
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $template = new Section('layout_onecol');
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $this->uuid->generate());
    $template->appendComponent(new SectionComponent($this->uuid->generate(), 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':hero',
      'label' => 'Hero',
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ]));
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->appendSection($template)->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    $page = EntityTestRev::create(['name' => 'Locked']);
    $page->save();
    $host = $this->hostOf($page);
    $this->assertNotNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($host), 'The page renders its template.');

    $ids = array_column($this->faces->answer($host)->faces, 'id');
    $this->assertSame(SectionBlock::PLUGIN_ID, $ids[0], 'The Container leads, as on any page.');
    $this->assertContains('field_block:entity_test_rev:entity_test_rev:name', $ids, 'The bundle\'s own field block is offered for its slots.');
    $this->assertContains('inline_block:basic', $ids);
    $this->assertSame([], preg_grep('/^' . SlotBlock::PLUGIN_ID . ':/', $ids), 'No slot block: a page fills slots, the template declares them.');
    $this->assertContains(SlotBlock::PLUGIN_ID . ':hero', array_column($this->faces->answer($this->hostOf($display))->faces, 'id'), 'The template\'s own canvas offers the slot.');
  }

  /**
   * A block type's face carries its icon, groups and favorite.
   *
   * The icon in the icon value grammar resolved to a URL; the groups the
   * site declares, in the type's own order, an undeclared id dropped (the
   * form keeps it, the surface ignores it); the favorite as stored.
   */
  public function testBlockTypeFace(): void {
    BlockContentType::load('basic')
      ->set('description', 'One paragraph of text.')
      ->setThirdPartySetting('daedalus_layout', 'icon', '/modules/custom/theme/basic.svg')
      ->setThirdPartySetting('daedalus_layout', 'favorite', TRUE)
      ->setThirdPartySetting('daedalus_layout', 'groups', ['bands', 'openers', 'heroes'])
      ->save();

    $face = $this->facesById($this->faces->answer($this->hostOf($this->createTestEntity())))['inline_block:basic'];
    $this->assertSame([
      'id' => 'inline_block:basic',
      'kind' => AddPanelFaces::KIND_BASIC,
      'label' => 'Basic Block',
      'description' => 'One paragraph of text.',
      'icon' => '/modules/custom/theme/basic.svg',
      'groups' => ['bands', 'heroes'],
      'favorite' => TRUE,
      // The base's user holds no bundle admin permission.
      'editable' => FALSE,
    ], $face);

    // The defaults: each key's absence is no icon, no group, no favorite.
    $this->createBlockContentType('plain', 'Plain Block');
    $plain = $this->facesById($this->faces->answer($this->hostOf($this->createTestEntity())))['inline_block:plain'];
    $this->assertNull($plain['icon']);
    $this->assertSame([], $plain['groups']);
    $this->assertFalse($plain['favorite']);
  }

  /**
   * A plain plugin's face: core's category, no groups, never a site default.
   */
  public function testPlainPluginFace(): void {
    $faces = $this->facesById($this->faces->answer($this->hostOf($this->createTestEntity())));
    $definition = $this->container->get('plugin.manager.block')->getDefinition('system_powered_by_block');
    $this->assertSame([
      'id' => 'system_powered_by_block',
      'kind' => (string) $definition['category'],
      'label' => (string) $definition['admin_label'],
      'description' => '',
      'icon' => NULL,
      'groups' => [],
      'favorite' => FALSE,
      'editable' => FALSE,
    ], $faces['system_powered_by_block']);
    $this->assertNotSame(AddPanelFaces::KIND_BASIC, $faces['system_powered_by_block']['kind']);
  }

  /**
   * The bundle's admin may write a block type's face, nobody else's.
   *
   * The row menu's "Favorite for everyone" is offered where the face says
   * the actor may write it, never by trying: a block type's update access
   * (the bundle's own admin permission) answers TRUE for the admin and
   * FALSE for an editor; the Container and a plain plugin have no face to
   * write and answer FALSE for everyone. The permission rides the
   * answer's cacheability.
   */
  public function testEditable(): void {
    $this->createBlockContentType('plain', 'Plain Block');
    $host = $this->hostOf($this->createTestEntity());

    // The base's user: may edit layouts, no bundle admin permission.
    $faces = $this->facesById($this->faces->answer($host));
    $this->assertFalse($faces['inline_block:basic']['editable']);
    $this->assertFalse($faces[SectionBlock::PLUGIN_ID]['editable']);
    $this->assertFalse($faces['system_powered_by_block']['editable']);

    // The bundle's admin: the block types' faces, and only those.
    $this->setUpCurrentUser([], ['configure any layout', 'administer block types']);
    $answer = $this->faces->answer($host);
    $faces = $this->facesById($answer);
    $this->assertTrue($faces['inline_block:basic']['editable']);
    $this->assertTrue($faces['inline_block:plain']['editable']);
    $this->assertFalse($faces[SectionBlock::PLUGIN_ID]['editable'], 'The Container has no face to write.');
    $this->assertFalse($faces['system_powered_by_block']['editable'], 'A plain plugin has no face to write.');
    $this->assertFalse($faces['field_block:entity_test:entity_test:name']['editable']);
    $this->assertContains('user.permissions', $answer->cacheMetadata()->getCacheContexts());

    // Back to an editor: FALSE again, the answer is the actor's.
    $this->setUpCurrentUser([], ['configure any layout']);
    $this->assertFalse($this->facesById($this->faces->answer($host))['inline_block:basic']['editable']);
  }

  /**
   * The vocabulary rides the answer in declaration order, as a list.
   */
  public function testGroups(): void {
    $expected = [
      ['id' => 'heroes', 'label' => 'Heroes', 'description' => 'Full-width openers for the top of a page.'],
      ['id' => 'bands', 'label' => 'Bands', 'description' => ''],
    ];
    $this->assertSame($expected, $this->faces->groups());
    $this->assertSame($expected, $this->faces->answer($this->hostOf($this->createTestEntity()))->groups);
  }

  /**
   * The marks written through EditorSettings read back, the actor's alone.
   *
   * The client writes the whole map through the generic settings route
   * (EditorSettings::set() is what the route calls); the service
   * normalizes on read, and another user's marks are never in the answer.
   */
  public function testMarks(): void {
    $settings = $this->container->get('daedalus_ui.editor_settings');
    $host = $this->hostOf($this->createTestEntity());

    $this->assertSame([], $this->faces->favorites(), 'Nothing stored: no marks.');

    $settings->set(AddPanelFaces::SETTING_NAMESPACE, AddPanelFaces::FAVORITES_SETTING, [
      'inline_block:basic' => TRUE,
      'blueprint:promo' => TRUE,
      SectionBlock::PLUGIN_ID => FALSE,
      'system_powered_by_block' => 1,
      '' => TRUE,
    ]);
    $expected = [
      'inline_block:basic' => TRUE,
      'blueprint:promo' => TRUE,
      SectionBlock::PLUGIN_ID => FALSE,
      'system_powered_by_block' => TRUE,
    ];
    $this->assertSame($expected, $this->faces->favorites(), 'Each value a boolean, an empty id dropped.');
    $answer = $this->faces->answer($host);
    $this->assertSame($expected, $answer->favorites);
    $this->assertSame($expected, (array) $answer->toArray()['favorites']);
    $this->assertFalse($this->facesById($answer)['inline_block:basic']['favorite'], 'The marks never rewrite the face: the site default stays what it is.');

    // A value that is not a map (the scalar route's spelling) is no marks.
    $settings->set(AddPanelFaces::SETTING_NAMESPACE, AddPanelFaces::FAVORITES_SETTING, '1');
    $this->assertSame([], $this->faces->favorites());

    // Another user's marks never reach the answer: the marks are the
    // actor's, and an empty map encodes as an object for the client.
    $settings->set(AddPanelFaces::SETTING_NAMESPACE, AddPanelFaces::FAVORITES_SETTING, $expected);
    $first_user = $this->container->get('current_user')->id();
    $this->setUpCurrentUser([], ['configure any layout']);
    $answer = $this->faces->answer($host);
    $this->assertSame([], $answer->favorites);
    $this->assertSame('{}', json_encode($answer->toArray()['favorites']));
    $this->assertSame($expected, $this->container->get('entity_type.manager')->getStorage('user')->load($first_user)->get('daedalus_ui_settings')->getValue()[0][AddPanelFaces::SETTING_NAMESPACE][AddPanelFaces::FAVORITES_SETTING], 'The first user\'s marks are still theirs.');
  }

  /**
   * A host with no document places nothing and answers no faces.
   */
  public function testHostWithoutDocument(): void {
    $this->createBlockContentType('plain_host', 'Plain host');
    $block = $this->container->get('entity_type.manager')->getStorage('block_content')->create([
      'type' => 'plain_host',
      'info' => 'Plain',
    ]);
    $block->save();
    $host = $this->hostOf($block);
    $this->assertFalse($host->hasDocument());
    $this->assertSame([], $this->faces->answer($host)->faces);
  }

  /**
   * The faces keyed by id.
   */
  protected function facesById(AddPanelAnswer $answer): array {
    return array_column($answer->faces, NULL, 'id');
  }

  /**
   * Creates a published block blueprint for entity_test hosts.
   */
  protected function createBlueprint(string $label): Blueprint {
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->uuid->generate());
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => $label,
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
      'layout_builder__layout' => [$section],
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * The host of a page, a template or a saved blueprint.
   */
  protected function hostOf(EntityInterface $entity): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity);
  }

}
