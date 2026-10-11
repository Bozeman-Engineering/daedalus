<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\FontFacesItem;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * The font store: how font bytes become files, and how fonts hold them.
 *
 * The one door for font files, an adapter over daedalus_ui's file lane
 * narrowed to the font kinds (FileLane::FONT_EXTENSIONS, woff2 and woff),
 * saving PERMANENT files under the fonts directory: a face's file is site
 * vocabulary from the moment it exists, not a temporary attachment. Two
 * entries, the lane's own: a request's upload (the tab's upload route) and
 * bytes (the disk lane's assets, a test). The declared type is what core
 * derives from the name, so the lane's type check never disagrees with
 * its own guess.
 *
 * A font HOLDS its files through core's file usage: the entity hooks sync
 * the usage rows to the faces on every save (hold()) and drop them on
 * delete (release()), so a file a face references is never garbage and a
 * file no face references any more is released to core's own rules.
 */
final class FontFiles {

  /**
   * Where the font store keeps its files.
   */
  public const DIRECTORY = 'public://daedalus_stylish/fonts';

  /**
   * The file usage module and type under which a font holds a file.
   */
  public const USAGE_MODULE = 'daedalus_stylish';
  public const USAGE_TYPE = 'daedalus_font';

  public function __construct(
    private readonly FileLane $lane,
    private readonly MimeTypeGuesserInterface $mimeTypeGuesser,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileUsageInterface $fileUsage,
  ) {}

  /**
   * Saves a request's uploaded font file, permanent, in the font store.
   *
   * @throws \Drupal\daedalus_ui\File\FileLaneException
   *   When the lane refuses the file (not a font kind, too large).
   */
  public function fromRequest(UploadedFile $upload): FileInterface {
    return $this->lane->fromRequest($upload, self::DIRECTORY, TRUE, FileLane::FONT_EXTENSIONS);
  }

  /**
   * Saves font bytes as a permanent file in the font store.
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
    return $this->lane->fromBytes($name, $this->mimeTypeGuesser->guessMimeType($name) ?? 'application/octet-stream', $content, self::DIRECTORY, TRUE, FileLane::FONT_EXTENSIONS);
  }

  /**
   * Loads a font file by uuid, or NULL when no file has it.
   */
  public function load(string $uuid): ?FileInterface {
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
    return $file instanceof FileInterface ? $file : NULL;
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
   * so the shipped payload (DaedalusStylishFont::shippedData(), the
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
   * Faces with each file named by its content hash: the shipped shape.
   *
   * @param array $faces
   *   Canonical faces, each source {file: uuid}.
   *
   * @return array<int, array{weight: string, style: string, source: array{hash: string|null}}>
   *   The same faces with the source map replaced by {hash: sha256}, NULL
   *   for a file the site no longer holds (a face that cannot print and
   *   can never match a shipped file).
   */
  public function contentAddressed(array $faces): array {
    $addressed = [];
    foreach ($faces as $face) {
      $uuid = is_array($face) ? ($face['source'][FontFacesItem::SOURCE_FILE] ?? NULL) : NULL;
      $file = is_string($uuid) ? $this->load($uuid) : NULL;
      $addressed[] = [
        'weight' => $face['weight'] ?? NULL,
        'style' => $face['style'] ?? NULL,
        'source' => ['hash' => $file === NULL ? NULL : self::contentHash($file)],
      ];
    }
    return $addressed;
  }

  /**
   * Syncs a font's file usage to its faces.
   *
   * Adds a usage row for every file the faces reference now and the
   * previous revision did not, and drops the rows of files the faces no
   * longer reference. Keyed by uuid set, so a save that leaves the files
   * alone touches nothing and no file is ever counted twice.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishFontInterface $font
   *   The saved font.
   * @param \Drupal\daedalus_stylish\DaedalusStylishFontInterface|null $previous
   *   The font as it was before the save, or NULL on insert.
   */
  public function hold(DaedalusStylishFontInterface $font, ?DaedalusStylishFontInterface $previous = NULL): void {
    $now = $font->fileUuids();
    $before = $previous?->fileUuids() ?? [];
    foreach (array_diff($now, $before) as $uuid) {
      $file = $this->load($uuid);
      if ($file !== NULL) {
        $this->fileUsage->add($file, self::USAGE_MODULE, self::USAGE_TYPE, (string) $font->id());
      }
    }
    foreach (array_diff($before, $now) as $uuid) {
      $file = $this->load($uuid);
      if ($file !== NULL) {
        $this->fileUsage->delete($file, self::USAGE_MODULE, self::USAGE_TYPE, (string) $font->id());
      }
    }
  }

  /**
   * Drops every usage row a deleted font held.
   */
  public function release(DaedalusStylishFontInterface $font): void {
    foreach ($font->fileUuids() as $uuid) {
      $file = $this->load($uuid);
      if ($file !== NULL) {
        $this->fileUsage->delete($file, self::USAGE_MODULE, self::USAGE_TYPE, (string) $font->id());
      }
    }
  }

}
