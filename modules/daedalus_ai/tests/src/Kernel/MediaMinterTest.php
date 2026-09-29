<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\Core\Access\AccessException;
use Drupal\daedalus_ai\MediaMinter;
use Drupal\daedalus_ai\MediaMinterException;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The shared media minter: a file becomes the media its bytes are.
 *
 * The minter is constructed from the same container collaborators its
 * service definition names; the daedalus_ai module itself is not enabled
 * because its AI-stack dependencies are unrelated to minting (see
 * DaedalusAiKernelTestBase).
 */
#[CoversClass(MediaMinter::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class MediaMinterTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

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
    'block',
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
   * A small SVG.
   */
  private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

  /**
   * The minter under test.
   */
  private MediaMinter $minter;

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

    // The raster type (png gif jpg jpeg webp), the vector type (svg), and a
    // generic document type that also lists png: the raster must land in
    // the dedicated image type, never the generic one.
    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('svg', ['id' => 'vector_image']);
    $document = $this->createMediaType('file', ['id' => 'document']);
    FieldConfig::load('media.document.' . $document->getSource()->getSourceFieldDefinition($document)->getName())
      ->setSetting('file_extensions', 'png pdf')
      ->save();

    $this->setUpCurrentUser(
      ['name' => 'minting editor'],
      ['view media', 'access content', 'create image media', 'create vector_image media', 'create document media'],
    );

    $this->minter = new MediaMinter(
      $this->container->get('entity_type.manager'),
      $this->container->get('daedalus_ui.file_lane'),
      $this->container->get('file.mime_type.guesser'),
      $this->container->get('daedalus_blueprint.access_checker'),
    );
  }

  /**
   * A raster lands in the image type, named by the alt unless a name is given.
   */
  public function testRasterMintsImage(): void {
    $file = $this->saveFile('hero.png', 'image/png', $this->pngBytes());

    $media = $this->minter->mintFromFile($file, 'A hero image');
    $this->assertFalse($media->isNew());
    $this->assertSame('image', $media->bundle(), 'The dedicated image type outranks the generic document type that also lists png.');
    $this->assertSame('A hero image', $media->getName());
    $source_item = $this->sourceItem($media);
    $this->assertSame((int) $file->id(), (int) $source_item['target_id']);
    $this->assertSame('A hero image', $source_item['alt']);
    $this->assertTrue(File::load($file->id())->isPermanent(), 'The media usage makes the file permanent.');

    // An explicit name and uuid are honored.
    $uuid = 'ab2c58f0-6b6f-4c7a-9d3e-2f4a5b6c7d8e';
    $named = $this->minter->mintFromFile($file, 'A hero image', 'hero.png', $uuid);
    $this->assertSame('hero.png', $named->getName());
    $this->assertSame($uuid, $named->uuid());
    $this->assertSame('A hero image', $this->sourceItem($named)['alt']);
  }

  /**
   * An SVG lands in the vector type.
   */
  public function testSvgMintsVectorImage(): void {
    $file = $this->saveFile('logo.svg', 'image/svg+xml', self::SVG);

    $media = $this->minter->mintFromFile($file, 'The logo');
    $this->assertSame('vector_image', $media->bundle());
    $this->assertSame('The logo', $media->getName());
    $this->assertSame((int) $file->id(), (int) $this->sourceItem($media)['target_id']);
  }

  /**
   * A MIME no source field accepts raises; "any extension" contributes nothing.
   */
  public function testNoMatchRaises(): void {
    $file = $this->saveFile('notes.txt', 'text/plain', 'plain text');
    try {
      $this->minter->mintFromFile($file, 'Notes');
      $this->fail('A text file matches no media type.');
    }
    catch (MediaMinterException $e) {
      $this->assertSame('No media type accepts text/plain (notes.txt).', $e->getMessage());
    }

    // A pdf matched the document type only through its listed extensions:
    // once the type accepts any extension it no longer claims anything.
    $pdf = $this->saveFile('brief.pdf', 'application/pdf', '%PDF-1.4');
    $this->assertSame('document', $this->minter->mediaTypeFor($pdf)->id());
    FieldConfig::load('media.document.field_media_file')->setSetting('file_extensions', '')->save();
    $this->expectException(MediaMinterException::class);
    $this->minter->mintFromFile($pdf, 'Brief');
  }

  /**
   * Create access is checked on the resolved bundle.
   */
  public function testRequiresCreateAccess(): void {
    $this->setUpCurrentUser(
      ['name' => 'raster only'],
      ['view media', 'access content', 'create image media'],
    );
    $this->assertSame('image', $this->minter->mintFromFile($this->saveFile('hero.png', 'image/png', $this->pngBytes()), 'Hero')->bundle());

    $this->expectException(AccessException::class);
    $this->expectExceptionMessage('cannot create vector_image media');
    $this->minter->mintFromFile($this->saveFile('logo.svg', 'image/svg+xml', self::SVG), 'Logo');
  }

  /**
   * Saves a temporary managed file with the given bytes and MIME type.
   */
  private function saveFile(string $name, string $mime, string $bytes): FileInterface {
    $uri = 'public://' . $name;
    file_put_contents($uri, $bytes);
    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => $mime,
      'status' => 0,
    ]);
    $file->save();
    return $file;
  }

  /**
   * A 1x1 PNG.
   */
  private function pngBytes(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
  }

  /**
   * Returns the media source field's first item value.
   *
   * @return array
   *   The source field item's values (target_id, alt).
   */
  private function sourceItem(MediaInterface $media): array {
    $media_type = MediaType::load($media->bundle());
    $source_field = $media->getSource()->getSourceFieldDefinition($media_type)->getName();
    return $media->get($source_field)->first()->getValue();
  }

}
