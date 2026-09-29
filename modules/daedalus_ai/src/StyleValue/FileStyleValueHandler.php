<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\StyleValue;

use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\MediaMinter;
use Drupal\daedalus_ai\MediaMinterException;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\StyleValueHandlerInterface;
use Drupal\file\FileInterface;

/**
 * The `_file` handled value on the style grammar's input tier.
 *
 * Registers the file handle on daedalus_stylish's style-value seam, so a
 * media-capable style property (e.g. background-image) accepts
 * {"_file": "name"} exactly the way media-reference fields do, and the
 * page language's url('file:<name>') lands here through the dialect's
 * CSS translation: one word, one meaning, in both host grammars.
 *
 * The name is the handle the conversation holds the file under (the
 * alias layer's files namespace); it resolves through the attachment
 * store for the write's conversation ($context['conversation'], the op
 * spine's carrier) and the current user, never for another owner. CHECK
 * resolves the name and the media type the file mints into (both
 * side-effect-free reads) so an unknown name, a file no media type
 * accepts and a missing create right are validation errors and COMMIT
 * never fails mid-write; COMMIT mints the media through the shared
 * minter and resolves to the canonical stored token url('media:<uuid>'),
 * final at once (nothing is deferred: the pixels are the file's).
 *
 * Soft wiring: the service is tagged daedalus_stylish.style_value_handler;
 * without daedalus_stylish the tag is never collected and this class never
 * loads, so daedalus_ai takes no hard dependency on daedalus_stylish.
 */
class FileStyleValueHandler implements StyleValueHandlerInterface {

  public function __construct(
    protected Attachments $attachments,
    protected MediaMinter $mediaMinter,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getMarkerKey(): string {
    return '_file';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return '{"_file": "name", "alt_text": "accessible image description"} (alt_text optional) places a file this conversation holds (by the name the ATTACHMENTS block or a tool result gave it) on a media-capable property: it becomes a media item at once and the stored value is its final url(\'media:<uuid>\') token. Size the background with CSS (background-size and friends).';
  }

  /**
   * {@inheritdoc}
   *
   * Side-effect-free: shape-checks the object, resolves the name for the
   * write's conversation and the current user, reads the media type the
   * file would mint into and the user's right to create it; nothing is
   * minted here.
   */
  public function check(array $value, array $context): array {
    $errors = [];

    $name = $value['_file'];
    if (!is_string($name) || trim($name) === '') {
      $errors[] = new BlueprintError('_file', 'INVALID_STYLE_VALUE', 'The _file name must be a non-empty string.', [], 'Provide {"_file": "name"} with the name the ATTACHMENTS block or a tool result gave the file.');
    }

    $alt = $value['alt_text'] ?? NULL;
    if ($alt !== NULL && (!is_string($alt) || trim($alt) === '')) {
      $errors[] = new BlueprintError('alt_text', 'INVALID_STYLE_VALUE', 'alt_text must be a non-empty string when provided.', [], 'Omit alt_text to name the media by the file.');
    }

    if ($extra = array_diff(array_keys($value), ['_file', 'alt_text'])) {
      $errors[] = new BlueprintError('', 'INVALID_STYLE_VALUE', sprintf('Unsupported key(s) on the _file value: %s.', implode(', ', $extra)), [], 'A file style value takes only _file (the name) and optional alt_text. Size the background with CSS (background-size and friends).');
    }

    if ($errors !== []) {
      return $errors;
    }

    $file = $this->resolveFile($context, trim($name));
    if ($file === NULL) {
      return [
        new BlueprintError('_file', 'UNKNOWN_FILE', sprintf('No file named "%s" is held in this conversation.', trim($name)), ['name' => trim($name)], 'The name is the handle the ATTACHMENTS block or a tool result gave the file, spelled exactly (names are case-sensitive); a file from another conversation or another user cannot be placed.'),
      ];
    }
    try {
      $bundle = (string) $this->mediaMinter->mediaTypeFor($file)->id();
    }
    catch (MediaMinterException $e) {
      return [
        new BlueprintError('_file', 'UNSUPPORTED_FILE', sprintf('"%s" cannot be placed as media: %s', trim($name), $e->getMessage()), ['name' => trim($name)], 'Only files a media type accepts (images, SVG) can be placed; provide the value as plain CSS instead.'),
      ];
    }
    if (!$this->accessChecker->createAccess('media', $bundle)) {
      $errors[] = new BlueprintError('', 'ACCESS_DENIED_ENTITY', sprintf('The current user cannot create %s media, so the file cannot be placed.', $bundle), [], 'Provide the value as plain CSS (e.g. url(\'media:<uuid>\') referencing existing media) instead.');
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   *
   * "none" is sound in any <image> slot and, unlike a media token, loads
   * nothing at validation time.
   */
  public function getStandIn(array $value, array $context): string {
    return 'none';
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(array $value, array $context, array &$warnings): string {
    $name = trim($value['_file']);
    // check() resolved the same name for the same conversation and user a
    // moment ago; a NULL here is a race (the file released between the
    // phases), and a loud failure beats a silently unplaced value.
    $file = $this->resolveFile($context, $name);
    if ($file === NULL) {
      throw new \RuntimeException(sprintf('The file "%s" checked for this write is no longer held in the conversation.', $name));
    }
    $alt = trim((string) ($value['alt_text'] ?? '')) ?: $file->getFilename();
    $media = $this->mediaMinter->mintFromFile($file, $alt, $file->getFilename());
    return MediaToken::build($media->uuid());
  }

  /**
   * The held file a name resolves to for the write's conversation.
   */
  private function resolveFile(array $context, string $name): ?FileInterface {
    $conversation = (string) ($context['conversation'] ?? '');
    return $conversation === '' ? NULL : $this->attachments->resolve($conversation, $name);
  }

}
