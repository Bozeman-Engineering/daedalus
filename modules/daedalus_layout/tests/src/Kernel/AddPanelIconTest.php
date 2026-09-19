<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\file\Entity\File;
use Drupal\daedalus_layout\Plugin\Sidebar\PlaceBlock;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the Add panel's icon rendering and its stencil contract.
 *
 * Promoted items resolve the BLOCK TYPE's icon TPS through the icon value
 * grammar's one seam and fall back to the default icon on NULL; the
 * panel's list stamps that default for the client-rendered stencil items.
 * Stencils themselves are materials: the panel renders no stencil item,
 * attaches no stencil roster, and the records the client paints from
 * (the materials roster, js/add-panel/stencil-items.js) carry RESOLVED
 * icon URLs with the media's tags on the roster, so an icon edit
 * invalidates every projection of it. The panel's one stencil
 * contribution is the "save stencils" grant.
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
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('stencil');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
  }

  /**
   * Block icons reach the panel; stencils reach it only through the roster.
   */
  public function testAddPanelIcons(): void {
    // The B lane: a promoted block's icon lives on the block TYPE's TPS,
    // here in the grammar's path spelling (today's shipped values).
    $display = $this->container->get('entity_type.manager')
      ->getStorage('entity_view_display')
      ->load('entity_test.entity_test.default');
    $display->setThirdPartySetting('daedalus_layout', 'promoted_blocks', ['inline_block:basic'])->save();
    BlockContentType::load('basic')
      ->setThirdPartySetting('daedalus_layout', 'icon', '/modules/custom/theme/basic.svg')
      ->save();

    // The C lane, content spelling: a stencil whose icon is a media
    // reference the client cannot resolve, so the roster must.
    $media = $this->createIconMedia();
    $iconed = Stencil::create([
      'label' => 'Promo',
      'description' => 'A promo section.',
      'icon' => $media->id(),
    ]);
    $iconed->setElement(['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]);
    $iconed->save();

    // A stencil without an icon: the uniform NULL, the default icon's cue.
    $plain = Stencil::create([
      'label' => 'Plain',
      'description' => 'No icon set.',
    ]);
    $plain->setElement(['block_type' => 'basic']);
    $plain->save();

    // The C lane, file spelling: the fixture module ships hero with a
    // module-relative icon discovery already resolved to root-relative.
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['daedalus_blueprint_stencils']);

    $this->setUpCurrentUser([], ['save stencils']);
    $build = $this->buildPanel();
    $default_icon = '/' . $this->container->get('module_handler')->getModule('daedalus_layout')->getPath() . '/assets/default-block-icon.svg';
    $media_icon_url = $this->container->get('file_url_generator')->generateString('public://stencil-icon.png');
    $hero_icon = '/' . $this->container->get('extension.list.module')->getPath('daedalus_blueprint_stencil_test') . '/assets/hero.svg';

    // The server-rendered promoted items wear the block type's answer; the
    // list stamps the default the client paints for icon-less stencils.
    $items = $this->itemStyles($build);
    $this->assertStringContainsString('/modules/custom/theme/basic.svg', $items['inline_block:basic']);
    $this->assertSame($default_icon, $build['promoted_blocks']['#attributes']['data-default-icon']);

    // No stencil item is server-rendered and no stencil roster is attached:
    // the client renders the items from the materials store.
    $this->assertSame(['empty-section', 'inline_block:basic'], array_keys($items));
    $this->assertArrayNotHasKey('stencils', $build['#attached']['drupalSettings']['daedalusLayout']);
    // The panel's one stencil contribution: the grant the gestures gate on,
    // the account's own answer (this build ran as a user holding it).
    $this->assertTrue($build['#attached']['drupalSettings']['daedalusLayout']['canSaveStencils']);
    $this->assertContains('user.permissions', $build['#cache']['contexts']);

    // What the client paints from: the roster's stencil records carry
    // RESOLVED urls (never grammar values). NULL means the default icon.
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame($media_icon_url, $roster->get(MaterialKind::Stencil, 'promo')->icon);
    $this->assertNull($roster->get(MaterialKind::Stencil, 'plain')->icon);
    $this->assertSame($hero_icon, $roster->get(MaterialKind::Stencil, 'hero')->icon);
    $this->assertNull($roster->get(MaterialKind::Stencil, 'call_to_action')->icon);
    $this->assertSame('section', $roster->get(MaterialKind::Stencil, 'promo')->rootKind);
    $this->assertSame('block', $roster->get(MaterialKind::Stencil, 'plain')->rootKind);

    // Invalidation contract: the block type's config tag rides the panel
    // build (an icon edit must repaint the panel); the icon media's tag
    // rides the roster, whose metadata every projection of it attaches.
    $this->assertContains('config:block_content.type.basic', $build['#cache']['tags']);
    $this->assertNotContains('daedalus_blueprint_stencils', $build['#cache']['tags']);
    $roster_tags = $roster->getCacheableMetadata()->getCacheTags();
    $this->assertContains('media:' . $media->id(), $roster_tags);
    $this->assertContains('daedalus_blueprint_stencils', $roster_tags);
  }

  /**
   * A dangling stencil icon reference degrades to the default icon.
   *
   * Site media comes and goes; a stencil whose icon media was deleted
   * later must paint the default icon, never a broken image: its record
   * carries NULL, and the media's absence contributes no tags (there is
   * nothing to invalidate).
   */
  public function testDanglingMediaDegrades(): void {
    $media = $this->createIconMedia();
    $stencil = Stencil::create([
      'label' => 'Promo',
      'description' => 'A promo section.',
      'icon' => $media->id(),
    ]);
    $stencil->setElement(['block_type' => 'basic']);
    $stencil->save();
    $media->delete();

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertNull($roster->get(MaterialKind::Stencil, 'promo')->icon);
    $this->assertNotContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Without promoted blocks the list still offers the Container.
   *
   * The Container is placeable whatever is promoted, and the client's
   * stencil items join the same list, so the scaffold always renders; the
   * promote hint rides beneath it.
   */
  public function testEmptyPromotedList(): void {
    $build = $this->buildPanel();
    $this->assertSame(['empty-section'], array_keys($this->itemStyles($build)));
    $this->assertArrayHasKey('filter', $build['promoted_blocks']);
    $this->assertStringContainsString('No blocks have been promoted', (string) $build['promoted_blocks']['promote']['#markup']);
    // An account without the grant gets none (the verbs and the item menu
    // stay away); the setting is present so the client reads one shape.
    $this->assertFalse($build['#attached']['drupalSettings']['daedalusLayout']['canSaveStencils']);
  }

  /**
   * Builds the Add panel the way the sidebar rail does, routed at a layout.
   */
  protected function buildPanel(): array {
    $entity = $this->createTestEntity();
    $storage = $this->getNestedStorage($entity);

    // Seat the section storage on the current route the way the Layout
    // Builder routes do: the panel reads it from the route parameters.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'daedalus_layout.test');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/daedalus-layout-test/{section_storage}'));
    $request->attributes->set('section_storage', $storage);

    $place_block = $this->container->get('plugin.manager.daedalus_ui_sidebars')->createInstance('place_block');
    return $place_block->build();
  }

  /**
   * Maps promoted-tab panel item ids to their icon background styles.
   */
  protected function itemStyles(array $build): array {
    $styles = [];
    foreach ($build['promoted_blocks']['blocks'] as $item) {
      if (is_array($item) && isset($item['#attributes']['id'], $item['icon'])) {
        $styles[$item['#attributes']['id']] = implode(' ', $item['icon']['#attributes']['style']);
      }
    }
    return $styles;
  }

  /**
   * Creates media whose source file is public://stencil-icon.png.
   */
  protected function createIconMedia(): Media {
    $type = $this->createMediaType('image');
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    file_put_contents('public://stencil-icon.png', 'png');
    $file = File::create(['uri' => 'public://stencil-icon.png', 'filename' => 'stencil-icon.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Stencil icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

}
