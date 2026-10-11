<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\File;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\media\MediaTypeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The one lane through which any supplied bytes become a managed file.
 *
 * Every byte a user or the model hands the page builder (a dropped media
 * file, a chat attachment, a `<pb-file>` declaration, render_page's shot)
 * enters through core's file.upload_handler with this lane's validators and
 * the FileValidationEvent chain, where the upload-time SVG sanitizer lives.
 * Two entry points, one save step: a request's UploadedFile is wrapped as
 * core's FormUploadedFile (the secure move_uploaded_file() move), bytes are
 * spooled to a temporary file and wrapped as InputStreamUploadedFile, and
 * from there validators, the sanitizer, renaming on collision and the entity
 * save run identically. Nothing supplied from outside is ever written with
 * FileRepository::writeData(), which bypasses the validator.
 *
 * The accepted set is explicit: the union of every media type's source-field
 * extensions plus the textual kinds the AI reads and the self-hosted font
 * kinds a design system ships, never a media type's "any extension"
 * setting, and one size ceiling. An adapter that knows a narrower target (a
 * replace-drop on a media bundle, the font store) may narrow the set, never
 * widen it.
 *
 * Files are saved temporary unless the adapter asks for permanence; a
 * temporary file lives on file usage (a media entity's, a conversation's) or
 * falls to core's temporary-file garbage collection.
 */
final class FileLane {

  /**
   * The textual kinds accepted beside the media types' source extensions.
   */
  public const TEXTUAL_EXTENSIONS = ['html', 'css', 'csv', 'md', 'txt', 'json', 'svg', 'pdf'];

  /**
   * The self-hosted font kinds accepted beside them (the font material).
   *
   * The two formats every current browser loads; a font store narrows the
   * lane to exactly these, so no other door ever admits font bytes.
   */
  public const FONT_EXTENSIONS = ['woff2', 'woff'];

  /**
   * The one size ceiling, in bytes.
   */
  public const SIZE_LIMIT = 25 * 1024 * 1024;

  public function __construct(
    private readonly FileUploadHandlerInterface $uploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Saves a request's uploaded file.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The file from the request's files bag.
   * @param string $directory
   *   The destination directory URI (created and made writable on demand).
   * @param bool $permanent
   *   Whether the saved file is permanent; temporary otherwise.
   * @param list<string>|null $extensions
   *   A narrowing of the accepted extensions (intersected with the lane's
   *   set, so it can never widen it), or NULL for the full set.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file entity.
   *
   * @throws \Drupal\daedalus_ui\File\FileLaneException
   *   When a validator or the FileValidationEvent chain refuses the file.
   */
  public function fromRequest(UploadedFile $upload, string $directory, bool $permanent = FALSE, ?array $extensions = NULL): FileInterface {
    return $this->save(new FormUploadedFile($upload), $directory, $permanent, $extensions);
  }

  /**
   * Saves bytes as a file.
   *
   * The bytes are spooled to a temporary file so core's handler moves them
   * exactly as it moves a stream upload (JSON:API, REST), and the validators
   * and sanitizer see a real file at the entity's URI.
   *
   * @param string $name
   *   The filename; its extension is what the validators judge and what the
   *   stored MIME type derives from.
   * @param string $mime
   *   The declared MIME type. A claim, checked against the type core derives
   *   from the name: a declaration that disagrees with its own name is
   *   refused rather than stored under either type.
   * @param string $content
   *   The bytes.
   * @param string $directory
   *   The destination directory URI (created and made writable on demand).
   * @param bool $permanent
   *   Whether the saved file is permanent; temporary otherwise.
   * @param list<string>|null $extensions
   *   A narrowing of the accepted extensions, or NULL for the full set.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file entity.
   *
   * @throws \Drupal\daedalus_ui\File\FileLaneException
   *   When a validator, the FileValidationEvent chain, or the declared type
   *   refuses the file.
   */
  public function fromBytes(string $name, string $mime, string $content, string $directory, bool $permanent = FALSE, ?array $extensions = NULL): FileInterface {
    $spool = $this->fileSystem->tempnam('temporary://', 'file');
    if ($spool === FALSE || file_put_contents($spool, $content) === FALSE) {
      throw new \RuntimeException('The file lane could not spool the supplied bytes to a temporary file.');
    }
    try {
      $file = $this->save(new InputStreamUploadedFile($name, $name, $spool, strlen($content)), $directory, $permanent, $extensions);
    }
    catch (FileLaneException $e) {
      // The handler leaves a refused spool where it was; a refusal must not
      // leave bytes behind.
      $this->fileSystem->delete($spool);
      throw $e;
    }
    if ($file->getMimeType() !== $mime) {
      $file->delete();
      throw new FileLaneException([sprintf('The declared type %s does not match the type of %s (%s).', $mime, $name, $file->getMimeType())]);
    }
    return $file;
  }

  /**
   * The lane's accepted extensions.
   *
   * @return list<string>
   *   Lowercase extensions: the union of every media type's source-field
   *   extensions plus the textual and font kinds.
   */
  public function acceptedExtensions(): array {
    $extensions = array_fill_keys([...self::TEXTUAL_EXTENSIONS, ...self::FONT_EXTENSIONS], TRUE);
    if ($this->entityTypeManager->hasDefinition('media_type')) {
      foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $media_type) {
        foreach ($this->mediaTypeExtensions($media_type) ?? [] as $extension) {
          $extensions[$extension] = TRUE;
        }
      }
    }
    return array_keys($extensions);
  }

  /**
   * A media type's source-field extensions.
   *
   * @return list<string>|null
   *   Lowercase extensions, or NULL when the source has no file-backed field
   *   or accepts any extension (a site choice the lane never inherits).
   */
  public function mediaTypeExtensions(MediaTypeInterface $media_type): ?array {
    $source_field = $media_type->getSource()->getSourceFieldDefinition($media_type);
    if ($source_field === NULL) {
      return NULL;
    }
    $settings = $source_field->getSettings();
    if (empty($settings['file_extensions'])) {
      return NULL;
    }
    return self::parseExtensions($settings['file_extensions']);
  }

  /**
   * Prepares the destination, runs the handler and applies permanence.
   */
  private function save(UploadedFileInterface $upload, string $directory, bool $permanent, ?array $extensions): FileInterface {
    // Core's handler does not create the destination directory; it assumes
    // the caller prepared it, or moveUploadedFile() fails with "Could not
    // move uploaded file".
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $result = $this->uploadHandler->handleFileUpload($upload, $this->validators($extensions), $directory, FileExists::Rename);

    if ($result->hasViolations()) {
      $messages = [];
      foreach ($result->getViolations() as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      throw new FileLaneException($messages);
    }

    $file = $result->getFile();
    if ($permanent) {
      // The handler saves every file temporary (status 0).
      $file->setPermanent();
      $file->save();
    }
    return $file;
  }

  /**
   * The validators, keyed by plugin ID as the file.validator service expects.
   */
  private function validators(?array $extensions): array {
    $accepted = $this->acceptedExtensions();
    if ($extensions !== NULL) {
      $accepted = array_values(array_intersect($accepted, array_map('strtolower', $extensions)));
    }
    if ($accepted === []) {
      // Core reads an empty extension list as "allow any extension"; a
      // narrowing that leaves nothing must refuse, never widen.
      throw new FileLaneException(['No file extension is accepted here.']);
    }
    return [
      'FileExtension' => ['extensions' => implode(' ', $accepted)],
      'FileSizeLimit' => ['fileLimit' => self::SIZE_LIMIT],
    ];
  }

  /**
   * Splits a field's space-separated extension setting.
   *
   * @return list<string>
   *   Lowercase, trimmed, empty entries dropped.
   */
  private static function parseExtensions(string $setting): array {
    $extensions = [];
    foreach (explode(' ', strtolower($setting)) as $extension) {
      $extension = trim($extension);
      if ($extension !== '') {
        $extensions[$extension] = $extension;
      }
    }
    return array_values($extensions);
  }

}
