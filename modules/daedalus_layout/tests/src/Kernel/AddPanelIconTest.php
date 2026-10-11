<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\file\Entity\File;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\Plugin\Sidebar\PlaceBlock;
use Drupal\layout_builder\Section;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the Add panel's mount, its settings and its icon contract.
 *
 * The panel renders a mount and no item markup: the server attaches the
 * faces service's answer as drupalSettings (one face per placeable
 * block with the BLOCK TYPE's icon resolved through the icon value
 * grammar's one seam, NULL for "the default icon", plus the default
 * icon's URL for the client to paint) and the bundle draws every row.
 * Blueprints are materials: the panel attaches no blueprint roster, and
 * the records the client paints from (the materials roster) carry
 * RESOLVED icon URLs with the media's tags on the roster, so an icon
 * edit invalidates every projection of it. The panel's one blueprint
 * contribution is the create grant of some blueprint type.
 */
#[CoversClass(PlaceBlock::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class AddPanelIconTest extends NestedStorageKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'media',
    'options',
    'daedalus_blueprint_library',
  ];

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
  }

  /**
   * Block icons reach the panel's settings; blueprints reach it only through the roster.
   */
  public function testAddPanelIcons(): void {
    // The B lane: a block's icon lives on the block TYPE's settings, here
    // in the grammar's path spelling (today's shipped values).
    BlockContentType::load('basic')
      ->setThirdPartySetting('daedalus_layout', 'icon', '/modules/custom/theme/basic.svg')
      ->save();

    // The C lane, content spelling: a blueprint whose icon is a media
    // reference the client cannot resolve, so the roster must.
    $media = $this->createIconMedia();
    $this->createBlueprint([
      'label' => 'Promo',
      'description' => 'A promo section.',
      'icon' => $media->id(),
    ]);

    // A blueprint without an icon: the uniform NULL, the default icon's cue.
    $this->createBlueprint([
      'label' => 'Plain',
      'description' => 'No icon set.',
    ]);

    // The C lane, file spelling: the fixture module ships hero with a
    // module-relative icon discovery already resolved to root-relative.
    $this->enableModules(['daedalus_blueprint_library_test']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['daedalus_blueprint_library']);

    $this->setUpCurrentUser([], ['create section blueprint']);
    $build = $this->buildPanel();
    $default_icon = '/' . $this->container->get('module_handler')->getModule('daedalus_layout')->getPath() . '/assets/default-block-icon.svg';
    $media_icon_url = $this->container->get('file_url_generator')->generateString('public://blueprint-icon.png');
    $hero_icon = '/' . $this->container->get('extension.list.module')->getPath('daedalus_blueprint_library_test') . '/assets/hero.svg';

    // The block type's answer rides its face; the settings carry the
    // default the client paints for an icon-less face or record.
    $settings = $build['#attached']['drupalSettings']['daedalusLayout']['addPanel'];
    $faces = $this->facesById($settings);
    $this->assertSame('/modules/custom/theme/basic.svg', $faces['inline_block:basic']['icon']);
    $this->assertSame($default_icon, $settings['defaultIcon']);

    // No blueprint face is attached and no blueprint roster is attached:
    // the client draws the rows from the materials store.
    $this->assertSame([], preg_grep('/^blueprint:/', array_keys($faces)));
    $this->assertArrayNotHasKey('blueprints', $build['#attached']['drupalSettings']['daedalusLayout']);
    // The panel's one blueprint contribution: the grant the gestures gate on,
    // the account's own answer (this build ran as a user holding it).
    $this->assertTrue($build['#attached']['drupalSettings']['daedalusLayout']['canSaveBlueprints']);
    $this->assertContains('user.permissions', $build['#cache']['contexts']);

    // What the client paints from: the roster's blueprint records carry
    // RESOLVED urls (never grammar values). NULL means the default icon.
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame($media_icon_url, $roster->get(MaterialKind::Blueprint, 'promo')->icon);
    $this->assertNull($roster->get(MaterialKind::Blueprint, 'plain')->icon);
    $this->assertSame($hero_icon, $roster->get(MaterialKind::Blueprint, 'hero')->icon);
    $this->assertNull($roster->get(MaterialKind::Blueprint, 'call_to_action')->icon);
    $this->assertSame('block', $roster->get(MaterialKind::Blueprint, 'promo')->blueprintScope);
    $this->assertSame('block', $roster->get(MaterialKind::Blueprint, 'plain')->blueprintScope);

    // Invalidation contract: the block type's config tag rides the panel
    // build (an icon edit must repaint the panel); the icon media's tag
    // rides the roster, whose metadata every projection of it attaches.
    $this->assertContains('config:block_content.type.basic', $build['#cache']['tags']);
    $this->assertNotContains('daedalus_blueprint_library', $build['#cache']['tags']);
    $roster_tags = $roster->getCacheableMetadata()->getCacheTags();
    $this->assertContains('media:' . $media->id(), $roster_tags);
    $this->assertContains('daedalus_blueprint_library', $roster_tags);
  }

  /**
   * A dangling blueprint icon reference degrades to the default icon.
   *
   * Site media comes and goes; a blueprint whose icon media was deleted
   * later must paint the default icon, never a broken image: its record
   * carries NULL, and the media's absence contributes no tags (there is
   * nothing to invalidate).
   */
  public function testDanglingMediaDegrades(): void {
    $media = $this->createIconMedia();
    $this->createBlueprint([
      'label' => 'Promo',
      'description' => 'A promo section.',
      'icon' => $media->id(),
    ]);
    $media->delete();

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertNull($roster->get(MaterialKind::Blueprint, 'promo')->icon);
    $this->assertNotContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());
  }

  /**
   * A block type's face carries favorite and groups beside its icon.
   *
   * The three keys of `block_content.type.*.third_party.daedalus_layout`
   * validate against the module's schema (this kernel saves config under
   * the strict schema checker, so an unnamed key would throw) and read
   * back as stored: the favorite a boolean, the groups a list of ids.
   */
  public function testBlockTypeFaceValidatesAgainstSchema(): void {
    BlockContentType::load('basic')
      ->setThirdPartySetting('daedalus_layout', 'icon', '/modules/custom/theme/basic.svg')
      ->setThirdPartySetting('daedalus_layout', 'favorite', TRUE)
      ->setThirdPartySetting('daedalus_layout', 'groups', ['heroes', 'bands'])
      ->save();

    $block_type = BlockContentType::load('basic');
    $this->assertTrue($block_type->getThirdPartySetting('daedalus_layout', 'favorite'));
    $this->assertSame(['heroes', 'bands'], $block_type->getThirdPartySetting('daedalus_layout', 'groups'));

    $typed = $this->container->get('config.typed')->get('block_content.type.basic');
    $this->assertInstanceOf(BooleanData::class, $typed->get('third_party_settings.daedalus_layout.favorite'));
    $this->assertInstanceOf(Sequence::class, $typed->get('third_party_settings.daedalus_layout.groups'));
    $this->assertInstanceOf(StringData::class, $typed->get('third_party_settings.daedalus_layout.groups.0'));
  }

  /**
   * The panel is a mount and its settings: no item markup is rendered.
   *
   * The header wears the shared grammar with one action, the slot the
   * bundle seats the collapse pair in; the mount carries the hook the
   * bundle mounts into; the settings are the faces' answer (the Container
   * leading, its word the section block plugin's admin label, one source
   * with core's Configure form) plus the default icon; and the rendered
   * markup holds no row. An account without the grant gets none (the
   * verbs and the row menu stay away); the setting is present so the
   * client reads one shape.
   */
  public function testMountAndSettings(): void {
    $build = $this->buildPanel();

    $this->assertContains('daedalus_layout/add_panel', $build['#attached']['library']);
    $this->assertSame(PlaceBlock::TOOLS_ID, $build['header']['actions']['tools']['#attributes']['id']);
    $this->assertContains('daedalus-ui-panel__slot', $build['header']['actions']['tools']['#attributes']['class']);
    $this->assertArrayHasKey('data-daedalus-layout-add-panel-tools', $build['header']['actions']['tools']['#attributes']);
    $this->assertSame(PlaceBlock::MOUNT_ID, $build['mount']['#attributes']['id']);
    $this->assertArrayHasKey('data-daedalus-layout-add-panel-mount', $build['mount']['#attributes']);

    $settings = $build['#attached']['drupalSettings']['daedalusLayout']['addPanel'];
    $this->assertSame(['faces', 'groups', 'favorites', 'defaultIcon'], array_keys($settings));
    $definition = $this->container->get('plugin.manager.block')->getDefinition(SectionBlock::PLUGIN_ID);
    $this->assertSame(SectionBlock::PLUGIN_ID, $settings['faces'][0]['id']);
    $this->assertSame((string) $definition['admin_label'], $settings['faces'][0]['label']);
    $this->assertSame([], $settings['groups']);
    $this->assertEquals(new \stdClass(), $settings['favorites'], 'The marks encode as an object, so the client reads one shape.');
    // The answer's own cacheability rides the build.
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('config:block_content_type_list', $build['#cache']['tags']);
    $this->assertFalse($build['#attached']['drupalSettings']['daedalusLayout']['canSaveBlueprints']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('data-daedalus-layout-add-panel-mount', $html);
    $this->assertStringNotContainsString('daedalus-ui-panel-item', $html, 'No row is server-rendered: the bundle draws every one.');
    $this->assertStringNotContainsString('daedalus-ui-segment', $html, 'No tabs: the panel is one list of sets.');
  }

  /**
   * Builds the Add panel the way the sidebar rail does, routed at a layout.
   */
  protected function buildPanel(): array {
    $entity = $this->createTestEntity();
    $storage = $this->getNestedStorage($entity);

    // Seat the section storage on the current route the way the Layout
    // Builder routes do: the panel derives its host from the route.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'daedalus_layout.test');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/daedalus-layout-test/{section_storage}'));
    $request->attributes->set('section_storage', $storage);

    $place_block = $this->container->get('plugin.manager.daedalus_ui_sidebars')->createInstance('place_block');
    return $place_block->build();
  }

  /**
   * The attached faces keyed by id.
   */
  protected function facesById(array $settings): array {
    return array_column($settings['faces'], NULL, 'id');
  }

  /**
   * Creates and saves a published block blueprint with one empty container.
   *
   * The content lane of the roster: a blueprint entity for entity_test
   * hosts, its document one onecol section, as a capture leaves it.
   */
  protected function createBlueprint(array $values): Blueprint {
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->container->get('uuid')->generate());
    $blueprint = Blueprint::create($values + [
      'type' => 'section',
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
   * Creates media whose source file is public://blueprint-icon.png.
   */
  protected function createIconMedia(): Media {
    $type = $this->createMediaType('image');
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    file_put_contents('public://blueprint-icon.png', 'png');
    $file = File::create(['uri' => 'public://blueprint-icon.png', 'filename' => 'blueprint-icon.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Blueprint icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

}
