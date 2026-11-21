<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * The font store: how font bytes become files, and how skins hold them.
 *
 * The one door for font files, an adapter over daedalus_ui's file lane
 * narrowed to the font kinds (FileLane::FONT_EXTENSIONS, woff2 and woff),
 * saving TEMPORARY files under the fonts directory: a file becomes site
 * vocabulary when a font record names it, and until then it is an upload
 * core's cleanup may take. Two entries, the lane's own: a request's upload
 * (the skin tab's upload route) and bytes (the disk lane's assets, a
 * test). The declared type is what core derives from the name, so the
 * lane's type check never disagrees with its own guess.
 *
 * A SKIN holds its fonts' files through core's file usage: the skin's
 * entity hook adds a usage row for every file a face of either layer
 * names (hold()) and the skin's deletion drops them all (release()). The
 * first usage row is what makes the file permanent (core's file usage
 * does that for any used file); nothing here sets permanence by hand. A
 * hold is never released by a save: a skin's revisions are its
 * lifecycle, a restore may bring back a face the current revision
 * dropped, so every file a revision ever referenced stays held while the
 * skin lives, and the file is never garbage under a restorable face.
 */
final class FontFiles {

  /**
   * Where the font store keeps its files.
   */
  public const DIRECTORY = 'public://daedalus_stylish/fonts';

  /**
   * The file usage module and type under which a skin holds a file.
   */
  public const USAGE_MODULE = 'daedalus_stylish';
  public const USAGE_TYPE = 'daedalus_skin';

  public function __construct(
    private readonly FileLane $lane,
    private readonly MimeTypeGuesserInterface $mimeTypeGuesser,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileUsageInterface $fileUsage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Saves a request's uploaded font file, temporary, in the font store.
   *
   * @throws \Drupal\daedalus_ui\File\FileLaneException
   *   When the lane refuses the file (not a font kind, too large).
   */
  public function fromRequest(UploadedFile $upload): FileInterface {
    return $this->lane->fromRequest($upload, self::DIRECTORY, FALSE, FileLane::FONT_EXTENSIONS);
  }

  /**
   * Saves font bytes as a temporary file in the font store.
   *
   * @param string $name
   *   The filename; its extension is what the lane judges.
   * @param string $content
   *   The bytes.
   *
   * @throws \Drupal\daedalus_ui\File\FileLaneException
   *   When the lane refuses the file.
   */
  public function fromBytes(string $name, string $content): FileInterface {
    return $this->lane->fromBytes($name, $this->mimeTypeGuesser->guessMimeType($name) ?? 'application/octet-stream', $content, self::DIRECTORY, FALSE, FileLane::FONT_EXTENSIONS);
  }

  /**
   * Loads a font file by uuid, or NULL when no file has it.
   */
  public function load(string $uuid): ?FileInterface {
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Whether any skin holds the file as a face's file.
   */
  public function isHeld(FileInterface $file): bool {
    return !empty($this->fileUsage->listUsage($file)[self::USAGE_MODULE][self::USAGE_TYPE]);
  }

  /**
   * The @font-face format() a file delivers as, or NULL for a non-font.
   *
   * The format is the extension: woff2 and woff are the two kinds the
   * lane admits, and each spells its own format keyword.
   */
  public static function format(FileInterface $file): ?string {
    $extension = strtolower(pathinfo($file->getFilename() ?? '', PATHINFO_EXTENSION));
    return in_array($extension, FileLane::FONT_EXTENSIONS, TRUE) ? $extension : NULL;
  }

  /**
   * The content hash of a file's bytes, or NULL when they cannot be read.
   *
   * The disk lane's identity for a face's file: a site holds a face's file
   * by uuid and a module by basename, and neither name survives the trip,
   * so the shipped payload (DaedalusStylishSkin::shippedData(), the
   * importer's read of a module's assets) names each file by what it
   * contains. Same bytes, same face, whatever either side calls the file.
   */
  public static function contentHash(FileInterface $file): ?string {
    $uri = $file->getFileUri();
    if ($uri === NULL || !is_file($uri)) {
      return NULL;
    }
    $hash = hash_file('sha256', $uri);
    return $hash === FALSE ? NULL : $hash;
  }

  /**
   * A fonts map with each face's file named by its content hash.
   *
   * The shipped shape of the skin's fonts tier: every record as stored,
   * its faces' source maps replaced by {hash: sha256}, NULL for a file the
   * site no longer holds (a face that cannot print and can never match a
   * shipped file). A record whose faces are not a list passes through
   * verbatim (the validator refuses it).
   *
   * @param array $fonts
   *   A fonts layer, {name: {family, faces, description, offered}}.
   *
   * @return array
   *   The same map, faces content-addressed.
   */
  public function contentAddressed(array $fonts): array {
    foreach ($fonts as $name => $record) {
      if (!is_array($record) || !is_array($record['faces'] ?? NULL) || !array_is_list($record['faces'])) {
        continue;
      }
      $fonts[$name]['faces'] = $this->contentAddressedFaces($record['faces']);
    }
    return $fonts;
  }

  /**
   * Faces with each file named by its content hash.
   *
   * @param array $faces
   *   Canonical faces, each source {file: uuid}.
   *
   * @return array<int, array{weight: mixed, style: mixed, source: array{hash: string|null}}>
   *   The faces with {hash: sha256} in the source slot.
   */
  public function contentAddressedFaces(array $faces): array {
    $addressed = [];
    foreach ($faces as $face) {
      $uuid = is_array($face) ? ($face['source'][SkinFontsItem::SOURCE_FILE] ?? NULL) : NULL;
      $file = is_string($uuid) ? $this->load($uuid) : NULL;
      $addressed[] = [
        'weight' => is_array($face) ? ($face['weight'] ?? NULL) : NULL,
        'style' => is_array($face) ? ($face['style'] ?? NULL) : NULL,
        'source' => ['hash' => $file === NULL ? NULL : self::contentHash($file)],
      ];
    }
    return $addressed;
  }

  /**
   * Holds every file a skin's fonts name, in either layer.
   *
   * Adds a usage row for each file a stated face references that the skin
   * does not hold yet; a file already held is left at one row (a save that
   * leaves the files alone touches nothing, and no file is ever counted
   * twice). Nothing is released here: see the class docblock.
   */
  public function hold(DaedalusStylishSkinInterface $skin): void {
    $id = (string) $skin->id();
    $uuids = array_unique([
      ...SkinFontsItem::fileUuids($skin->packageFonts()),
      ...SkinFontsItem::fileUuids($skin->siteFonts()),
    ]);
    foreach ($uuids as $uuid) {
      $file = $this->load($uuid);
      if ($file === NULL) {
        continue;
      }
      $usage = $this->fileUsage->listUsage($file);
      if (!isset($usage[self::USAGE_MODULE][self::USAGE_TYPE][$id])) {
        $this->fileUsage->add($file, self::USAGE_MODULE, self::USAGE_TYPE, $id);
      }
    }
  }

  /**
   * Drops every usage row a deleted skin held, whichever revision named it.
   *
   * The files a skin may hold are exactly the files its revisions' faces
   * name (hold() runs on every save), so the release walks the skin's
   * revisions for their uuids and drops the skin's row from each file
   * that still carries one. A skin whose revisions never named a file
   * touches file usage not at all.
   */
  public function release(DaedalusStylishSkinInterface $skin): void {
    $id = (string) $skin->id();
    $uuids = [
      ...SkinFontsItem::fileUuids($skin->packageFonts()),
      ...SkinFontsItem::fileUuids($skin->siteFonts()),
    ];
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    assert($storage instanceof RevisionableStorageInterface);
    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $id)
      ->execute();
    foreach (array_keys($revision_ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if ($revision instanceof DaedalusStylishSkinInterface) {
        array_push($uuids, ...SkinFontsItem::fileUuids($revision->packageFonts()), ...SkinFontsItem::fileUuids($revision->siteFonts()));
      }
    }
    foreach (array_unique($uuids) as $uuid) {
      $file = $this->load($uuid);
      if ($file !== NULL && isset($this->fileUsage->listUsage($file)[self::USAGE_MODULE][self::USAGE_TYPE][$id])) {
        $this->fileUsage->delete($file, self::USAGE_MODULE, self::USAGE_TYPE, $id, 0);
      }
    }
  }

}
