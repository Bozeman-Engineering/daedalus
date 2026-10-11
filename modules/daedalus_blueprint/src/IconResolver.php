<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Resolves stored icon values to URLs: the one icon value seam.
 *
 * THE ICON VALUE GRAMMAR. Everything page-builder chrome stores as "an
 * icon" (block-type icons in `block_content.type.*.third_party.daedalus_layout.icon`,
 * blueprint icons on the blueprint entity and in the blueprint catalog) is one
 * string in exactly one of two spellings, and every consumer resolves it
 * here so the spellings can never fork per surface. It lives on the spine
 * because the spine both produces the grammar (blueprint discovery spells
 * content icons as media references) and projects it (a blueprint material
 * record carries its icon RESOLVED, the client palette paints from it):
 *
 * - `media:<uuid>`: a media entity reference by UUID (lowercase `media:`
 *   prefix; the UUID payload is case-insensitive). The user lane: values
 *   written by media library pickers. Resolves to the media source file's
 *   root-relative URL, always the original file, never an image style:
 *   icons render as inert chrome (background-image / <img>) at icon size
 *   and the shipped values are SVGs no image style can process.
 * - anything else: a root-relative URL path (e.g.
 *   `/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg`). The shipped lane: values
 *   from configs and recipes, returned verbatim. Producers that accept
 *   friendlier spellings (the blueprint YAML lane's module-relative `icon`
 *   key) resolve them to root-relative BEFORE storage; the resolver never
 *   sees a relative path.
 *
 * An empty value and a dangling reference (media deleted, media without a
 * file-backed source) both resolve to NULL: "no icon" is a legitimate
 * state, and each consumer renders its own default icon for it rather than
 * this service imposing one fallback for every surface.
 *
 * Media resolution deliberately gates on capability, not on a media-type
 * roster: any media whose source field references a file resolves (the
 * pickers constrain WHICH bundles are offered; mirroring their offer here
 * would be a second gate that could drift). Cache tags: the media's tags
 * merge into the accumulator whenever the media loads, so replacing the
 * media's file or deleting the media invalidates every render that printed
 * the icon; path values are static assets and contribute nothing.
 */
class IconResolver {

  /**
   * The grammar's media reference prefix.
   */
  public const MEDIA_PREFIX = 'media:';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Resolves an icon value to the URL rendering should print.
   *
   * @param string|null $value
   *   A stored icon value in the grammar above, or NULL/'' for unset.
   * @param array $cache_tags
   *   Accumulator the resolved media's tags merge into; attach these to
   *   whatever render printed the icon.
   *
   * @return string|null
   *   A root-relative URL, or NULL when the value is empty or its media
   *   reference no longer resolves to a file (consumer renders its default
   *   icon).
   */
  public function resolve(?string $value, array &$cache_tags): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!str_starts_with($value, self::MEDIA_PREFIX)) {
      return $value;
    }
    if (!$this->entityTypeManager->hasDefinition('media')) {
      return NULL;
    }
    // Lowercased for cross-database determinism: stored media UUIDs are
    // lowercase, and SQL string comparison is not case-insensitive on every
    // backend.
    $uuid = strtolower(substr($value, strlen(self::MEDIA_PREFIX)));
    $media = $this->entityRepository->loadEntityByUuid('media', $uuid);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    // Merged even when no file resolves below: the render decided "default
    // icon" based on this media's current state, so its edits must
    // invalidate that render too.
    $cache_tags = Cache::mergeTags($cache_tags, $media->getCacheTags());
    $file = $this->sourceFile($media);
    if ($file === NULL) {
      return NULL;
    }
    return $this->fileUrlGenerator->generateString((string) $file->getFileUri());
  }

  /**
   * Resolves a media entity to the file its source field references.
   *
   * The media-to-file step of resolve(), public on its own because the
   * blueprint ship lane needs the FILE (to copy it into a module's assets)
   * where rendering needs its URL; both go through this one step so the
   * capability gate above (any media whose source field references a file)
   * can never fork. Contributes no cache tags: the caller decides whether
   * the media's state shaped a render.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The source file, or NULL when the media's source declares no field,
   *   the field is empty, or its first item does not reference a file.
   */
  public function sourceFile(MediaInterface $media): ?FileInterface {
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
    if ($source_field === NULL) {
      return NULL;
    }
    $item = $media->get($source_field->getName())->first();
    if (!$item instanceof EntityReferenceItem) {
      return NULL;
    }
    $file = $item->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

}
