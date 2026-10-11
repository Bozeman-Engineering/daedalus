<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_ui\ElementModel\ElementIconProviderInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests daedalus_layout's element icon provider.
 *
 * `_element.icon` is daedalus_ui's third minted word; a container and an
 * inline block are this module's concepts, so this provider answers them
 * through the `daedalus_ui.element_icon_provider` tag: the Container's
 * asset for a container, the block type's icon setting resolved through
 * the icon value grammar for an inline block (the Add panel's two
 * sources), the default block icon when the type has none or is gone,
 * NULL for every kind daedalus_ui words itself. What the answer read (the
 * block type, a media icon's tags) rides the cacheability it is handed.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class ElementIconProviderTest extends NestedStorageKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'image',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
  }

  /**
   * A container wears the Container asset, reached through the tag.
   */
  public function testContainerWearsTheContainerAsset(): void {
    $node = ['kind' => 'container', 'uuid' => 'section-uuid', 'layout_id' => 'layout_onecol', 'children' => []];
    $cacheability = new CacheableMetadata();
    $this->assertSame(
      $this->asset('container.svg'),
      $this->container->get('daedalus_layout.element_icon_provider')->icon($node, ElementIconProviderInterface::KIND_CONTAINER, $cacheability),
    );
    $this->assertSame([], $cacheability->getCacheTags(), 'A shipped asset depends on nothing.');
    // daedalus_ui's collector reaches this provider through the tag, so
    // the annotator's question gets this answer.
    $this->assertSame(
      $this->asset('container.svg'),
      $this->container->get('daedalus_ui.element_icon_provider')->icon($node, ElementIconProviderInterface::KIND_CONTAINER, $cacheability),
    );
  }

  /**
   * An inline block wears its block type's icon, the default without one.
   */
  public function testInlineBlockWearsItsBlockTypeIcon(): void {
    // The path spelling of the icon value grammar (today's shipped values).
    BlockContentType::load('basic')
      ->setThirdPartySetting('daedalus_layout', 'icon', '/modules/custom/theme/basic.svg')
      ->save();
    // The media spelling, which only the resolver can turn into a URL.
    $media = $this->createIconMedia();
    $this->createBlockContentType('pictured', 'Pictured');
    BlockContentType::load('pictured')
      ->setThirdPartySetting('daedalus_layout', 'icon', 'media:' . $media->uuid())
      ->save();
    // A type with no icon set.
    $this->createBlockContentType('plain', 'Plain');

    $provider = $this->container->get('daedalus_layout.element_icon_provider');
    $block = static fn (string $type): array => [
      'kind' => 'block',
      'uuid' => $type . '-uuid',
      'block_type' => $type,
      'block_fields' => [],
    ];

    $cacheability = new CacheableMetadata();
    $this->assertSame('/modules/custom/theme/basic.svg', $provider->icon($block('basic'), ElementIconProviderInterface::KIND_BLOCK, $cacheability));
    $this->assertContains('config:block_content.type.basic', $cacheability->getCacheTags(), 'The block type read rides the answer.');

    $cacheability = new CacheableMetadata();
    $this->assertSame(
      $this->container->get('file_url_generator')->generateString('public://block-type-icon.png'),
      $provider->icon($block('pictured'), ElementIconProviderInterface::KIND_BLOCK, $cacheability),
      'A media icon resolves to its source file, the original, never an image style.',
    );
    foreach ($media->getCacheTags() as $tag) {
      $this->assertContains($tag, $cacheability->getCacheTags(), "The resolver's media tag $tag rides the answer.");
    }
    $this->assertContains('config:block_content.type.pictured', $cacheability->getCacheTags());

    $cacheability = new CacheableMetadata();
    $this->assertSame($this->asset('default-block-icon.svg'), $provider->icon($block('plain'), ElementIconProviderInterface::KIND_BLOCK, $cacheability), 'No icon set: the default block icon, never NULL, since an inline block always has a face here.');
    $this->assertContains('config:block_content.type.plain', $cacheability->getCacheTags(), 'The type was read to decide, so an icon set later invalidates the render.');
    $this->assertSame($this->asset('default-block-icon.svg'), $provider->icon($block('gone'), ElementIconProviderInterface::KIND_BLOCK, $cacheability), 'A type deleted under a placed block wears the default too.');

    // daedalus_ui's own kinds: not this provider's to answer.
    $plugin = ['kind' => 'block', 'uuid' => 'plugin-uuid', 'plugin_id' => 'system_powered_by_block'];
    $this->assertNull($provider->icon($plugin, ElementIconProviderInterface::KIND_BLOCK, $cacheability));
    $this->assertNull($provider->icon(['kind' => 'block', 'uuid' => 'field-uuid', 'field' => 'name'], ElementIconProviderInterface::KIND_BLOCK, $cacheability));
  }

  /**
   * The annotator mints a container's and an inline block's icon through here.
   */
  public function testTheAnnotatorMintsThroughTheProvider(): void {
    $entity = EntityTest::create(['name' => 'Page']);
    $entity->save();
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity);
    $blueprint = [
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Page'],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                ['kind' => 'block', 'uuid' => 'basic-uuid', 'block_type' => 'basic', 'block_fields' => []],
                ['kind' => 'block', 'uuid' => 'plugin-uuid', 'plugin_id' => 'system_powered_by_block'],
              ],
            ],
          ],
        ],
      ],
    ];

    $cacheability = new CacheableMetadata();
    $annotated = $this->container->get('daedalus_ui.element_annotator')->annotate($blueprint, $host, 'default', $cacheability);
    $section = $annotated['layout'][0];
    $components = $section['children'][0]['children'];
    $daedalus_ui = $this->container->get('extension.list.module')->getPath('daedalus_ui');

    $this->assertSame($this->asset('container.svg'), $section['_element']['icon'], 'The container wears the Container asset, the Add panel\'s face for it.');
    $this->assertSame($this->asset('default-block-icon.svg'), $components[0]['_element']['icon'], 'An inline block of a type with no icon wears the default.');
    $this->assertSame('/' . $daedalus_ui . '/assets/outline/plugin-block.svg', $components[1]['_element']['icon'], 'A plugin block is daedalus_ui\'s own.');
    $this->assertSame('/' . $daedalus_ui . '/assets/outline/page.svg', $annotated['_element']['icon'], 'The root is daedalus_ui\'s own.');
    $this->assertContains('config:block_content.type.basic', $cacheability->getCacheTags(), 'What the provider read rides the annotation\'s cacheability.');
  }

  /**
   * The root-relative URL of one of this module's assets.
   */
  protected function asset(string $file): string {
    return '/' . $this->container->get('extension.list.module')->getPath('daedalus_layout') . '/assets/' . $file;
  }

  /**
   * A media entity whose source file is an icon.
   */
  protected function createIconMedia(): Media {
    $type = $this->createMediaType('image');
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    file_put_contents('public://block-type-icon.png', 'png');
    $file = File::create(['uri' => 'public://block-type-icon.png', 'filename' => 'block-type-icon.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Block type icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

}
