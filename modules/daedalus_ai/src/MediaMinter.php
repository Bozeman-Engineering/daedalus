<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Mints media from a managed file: the one road from a file to content.
 *
 * Every surface that turns a file into media funnels through here: the
 * pending-media minter (a placeholder or seed file now, generated pixels
 * later) and the file handles the model places by name. The bundle is never
 * a caller's choice: it is read off the file's MIME type against every media
 * type's source-field extensions (a raster lands in the image type, an SVG in
 * the vector type), so a file can only ever become the media its bytes are.
 * A MIME no source field accepts raises; a media type whose source accepts
 * any extension contributes nothing (the same reading as the file lane).
 *
 * Create access on the resolved bundle is checked here, at the choke point,
 * so no caller can mint what its user may not create.
 */
class MediaMinter {

  /**
   * The generic file source's field type: ranked below a dedicated source.
   */
  const GENERIC_SOURCE_FIELD_TYPE = 'file';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileLane $fileLane,
    protected MimeTypeGuesserInterface $mimeTypeGuesser,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * Creates and saves a media entity carrying the file in its source field.
   *
   * @param \Drupal\file\FileInterface $file
   *   The managed file. Referencing it from the media's source field makes
   *   it permanent through the media's own file usage.
   * @param string $alt
   *   Alt text for the source field item.
   * @param string|null $name
   *   The media name, or NULL to name it by the alt text.
   * @param string|null $uuid
   *   A uuid to assign to the media entity, or NULL to let the entity system
   *   mint one.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media entity.
   *
   * @throws \Drupal\daedalus_ai\MediaMinterException
   *   When no media type's source field accepts the file's MIME type.
   * @throws \Drupal\Core\Access\AccessException
   *   When the current user may not create media of the resolved bundle.
   */
  public function mintFromFile(FileInterface $file, string $alt, ?string $name = NULL, ?string $uuid = NULL): MediaInterface {
    $media_type = $this->mediaTypeFor($file);
    $bundle = (string) $media_type->id();
    if (!$this->accessChecker->createAccess('media', $bundle)) {
      throw new AccessException(sprintf('Access denied: cannot create %s media.', $bundle));
    }
    $source_field_name = $media_type->getSource()
      ->getSourceFieldDefinition($media_type)
      ->getName();

    // The alt text is often a generation prompt (CSS backgrounds have no alt
    // slot, so the prompt rides in as library metadata) and can exceed the
    // storage columns: media name is varchar 255, image alt varchar 512. The
    // minter saves without entity validation, so clamp here, at the one choke
    // point every mint surface funnels through.
    $values = [
      'bundle' => $bundle,
      'name' => Unicode::truncate($name ?? $alt, 255, TRUE, TRUE),
      'status' => 1,
      $source_field_name => [
        'target_id' => $file->id(),
        'alt' => Unicode::truncate($alt, 512, TRUE, TRUE),
      ],
    ];
    if ($uuid !== NULL) {
      $values['uuid'] = $uuid;
    }

    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager->getStorage('media')->create($values);
    $media->save();
    return $media;
  }

  /**
   * The media type whose source field accepts the file's MIME type.
   *
   * Each media type's source-field extensions are mapped to MIME types
   * through core's guesser and matched against the file's. When several
   * types accept the type, a dedicated source (image, vector) outranks the
   * generic file source, and ids break the remaining tie, so the answer is
   * the same on every call.
   *
   * @throws \Drupal\daedalus_ai\MediaMinterException
   *   When no media type's source field accepts the file's MIME type.
   */
  public function mediaTypeFor(FileInterface $file): MediaTypeInterface {
    $mime = (string) $file->getMimeType();
    $candidates = [];
    /** @var \Drupal\media\MediaTypeInterface $media_type */
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $media_type) {
      foreach ($this->fileLane->mediaTypeExtensions($media_type) ?? [] as $extension) {
        if ($this->mimeTypeGuesser->guessMimeType('file.' . $extension) === $mime) {
          $candidates[] = $media_type;
          break;
        }
      }
    }
    if (!$candidates) {
      throw new MediaMinterException(sprintf('No media type accepts %s (%s).', $mime === '' ? 'a file without a type' : $mime, $file->getFilename()));
    }
    usort($candidates, function (MediaTypeInterface $a, MediaTypeInterface $b): int {
      $generic = fn (MediaTypeInterface $type): int => $type->getSource()->getSourceFieldDefinition($type)->getType() === self::GENERIC_SOURCE_FIELD_TYPE ? 1 : 0;
      return [$generic($a), (string) $a->id()] <=> [$generic($b), (string) $b->id()];
    });
    return $candidates[0];
  }

}
