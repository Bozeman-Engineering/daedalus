<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Traits;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\TestFileCreationTrait;

/**
 * Creates image media entities from core's test image fixtures.
 *
 * The media library legs of the behavior tests need real, saved media
 * entities to select in the modal. Core ships a handful of image fixtures
 * (image-1.png, image-2.jpg, ...) through TestFileCreationTrait; this trait
 * turns one of them into a saved 'image' media entity, the bundle the
 * daedalus recipe provides. One copy for every media-driving test.
 */
trait ImageMediaCreationTrait {

  use TestFileCreationTrait;

  /**
   * Creates a saved image media entity from a core test image fixture.
   *
   * @param string $file_name
   *   The fixture file name (e.g. 'image-1.png').
   * @param string $label
   *   The media label.
   * @param string $alt
   *   The alt text (lives on the media's source field, not the block).
   *
   * @return \Drupal\media\Entity\Media
   *   The saved media entity.
   */
  protected function createImageMedia(string $file_name, string $label, string $alt): Media {
    $fixture = NULL;
    foreach ($this->getTestFiles('image') as $candidate) {
      if (str_ends_with((string) $candidate->uri, $file_name)) {
        $fixture = $candidate;
        break;
      }
    }
    $this->assertNotNull($fixture, "The '$file_name' test image fixture exists.");
    $file = File::create(['uri' => $fixture->uri]);
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $label,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
      ],
    ]);
    $media->save();
    return $media;
  }

}
