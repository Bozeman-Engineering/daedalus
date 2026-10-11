<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the icon value grammar's one resolver.
 *
 * Locks the grammar's two spellings (`media:<uuid>` and a root-relative
 * path returned verbatim), the NULL degradations ("no icon" is the
 * consumer's default-icon state, never an exception), and the cache tag
 * contract: media tags merge whenever the media loads, including when
 * resolution then degrades, so the media's edits invalidate the render that
 * decided "default icon" from its state.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class IconResolverTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'svg_image_field',
    'daedalus_blueprint',
  ];

  /**
   * The resolver under test.
   */
  protected IconResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
    $this->resolver = $this->container->get('daedalus_blueprint.icon_resolver');
  }

  /**
   * Empty values resolve to NULL: unset icons are the consumer's fallback.
   */
  public function testEmptyValues(): void {
    $tags = [];
    $this->assertNull($this->resolver->resolve(NULL, $tags));
    $this->assertNull($this->resolver->resolve('', $tags));
    $this->assertSame([], $tags);
  }

  /**
   * Path values pass through verbatim and contribute no cache tags.
   */
  public function testPathLane(): void {
    $tags = [];
    $path = '/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg';
    $this->assertSame($path, $this->resolver->resolve($path, $tags));
    $this->assertSame([], $tags);
  }

  /**
   * Media references resolve to the source file's URL with the media's tags.
   */
  public function testMediaLane(): void {
    $media = $this->createImageMedia();
    $file_url = $this->container->get('file_url_generator')
      ->generateString('public://icon.png');

    $tags = [];
    $url = $this->resolver->resolve('media:' . $media->uuid(), $tags);
    $this->assertSame($file_url, $url);
    $this->assertContains('media:' . $media->id(), $tags);

    // The UUID payload is case-insensitive.
    $tags = [];
    $this->assertSame($file_url, $this->resolver->resolve('media:' . strtoupper($media->uuid()), $tags));
    $this->assertContains('media:' . $media->id(), $tags);
  }

  /**
   * Vector media (svg_image_field source) resolve like any file-backed media.
   */
  public function testVectorMediaLane(): void {
    $vector_type = $this->createMediaType('svg');
    $vector_source = $vector_type->getSource()->getSourceFieldDefinition($vector_type)->getName();
    file_put_contents('public://logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $svg_file = File::create(['uri' => 'public://logo.svg', 'filename' => 'logo.svg']);
    $svg_file->save();
    $media = Media::create([
      'bundle' => $vector_type->id(),
      'name' => 'Logo',
      $vector_source => ['target_id' => $svg_file->id()],
    ]);
    $media->save();

    $tags = [];
    $url = $this->resolver->resolve('media:' . $media->uuid(), $tags);
    $this->assertSame($this->container->get('file_url_generator')->generateString('public://logo.svg'), $url);
    $this->assertContains('media:' . $media->id(), $tags);
  }

  /**
   * Dangling references degrade to NULL, never to a broken URL.
   */
  public function testDanglingReference(): void {
    $tags = [];
    $this->assertNull($this->resolver->resolve('media:b5ad5cd1-c17f-42b0-8f5a-000000000000', $tags));
    $this->assertSame([], $tags);
  }

  /**
   * Media without a resolvable file degrade to NULL but still tag the render.
   */
  public function testMediaWithoutFile(): void {
    $file_type = $this->createMediaType('file');
    $media = Media::create(['bundle' => $file_type->id(), 'name' => 'Empty document']);
    $media->save();

    $tags = [];
    $this->assertNull($this->resolver->resolve('media:' . $media->uuid(), $tags));
    $this->assertContains('media:' . $media->id(), $tags);
  }

  /**
   * The source file comes back as a file, or NULL when the media has none.
   *
   * The media-to-file step on its own, for consumers that need the file
   * rather than its URL (the blueprint ship lane copies it into a module).
   */
  public function testSourceFile(): void {
    $media = $this->createImageMedia();
    $file = $this->resolver->sourceFile($media);
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertSame('public://icon.png', $file->getFileUri());

    $file_type = $this->createMediaType('file');
    $empty = Media::create(['bundle' => $file_type->id(), 'name' => 'Empty document']);
    $empty->save();
    $this->assertNull($this->resolver->sourceFile($empty));
  }

  /**
   * Creates image media whose source file is public://icon.png.
   */
  protected function createImageMedia(): Media {
    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://icon.png');
    $file = File::create(['uri' => 'public://icon.png', 'filename' => 'icon.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $image_type->id(),
      'name' => 'Icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

}
