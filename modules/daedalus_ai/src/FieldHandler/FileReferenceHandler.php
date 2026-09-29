<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\FieldHandler;

use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager;
use Drupal\daedalus_ai\MediaMinter;
use Drupal\daedalus_ai\MediaMinterException;
use Drupal\daedalus_ai\PendingMedia\PendingMediaMinter;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\FieldHandler\MediaFromFileInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Psr\Log\LoggerInterface;

/**
 * Handles entity_reference fields targeting media with file placement.
 *
 * The `_file` handled value on the field grammar: a media reference item
 * carrying {_file: <name>} names a file the conversation holds (an
 * attachment the user sent, a render shot the page tools kept) by the
 * handle the alias layer records for it, never by uuid or path. The
 * handler resolves the name through the attachment store for the
 * conversation the write belongs to ($options['conversation'], the op
 * spine's carrier: the page tools fill it from ConversationContext, the
 * HTTP op endpoint from the request's thread_id) and for the current user
 * (the store never answers another owner's file), mints media through the
 * shared minter (bundle by the file's MIME: raster to image, SVG to
 * vector), and sets the reference at once. The media's own file usage
 * makes the file permanent; the conversation keeps holding it under its
 * name, so the same file can be placed again.
 *
 * Sits above ImageGenerationHandler in the handler order and falls through
 * to it for everything else: an item without `_file` is generation's or
 * the plain reference's, so one media field keeps one handler chain with
 * one order (file, generate, reference).
 */
class FileReferenceHandler extends ImageGenerationHandler implements MediaFromFileInterface {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    PendingMediaMinter $minter,
    PendingTargetCollector $pendingTargets,
    ImageDimensionAdapterManager $dimensionManager,
    BlueprintAccessChecker $accessChecker,
    protected Attachments $attachments,
    protected MediaMinter $mediaMinter,
  ) {
    parent::__construct($entityTypeManager, $logger, $minter, $pendingTargets, $dimensionManager, $accessChecker);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return parent::getGuidance() . ' To place a file this conversation holds: {_file: "name", alt_text: "accessible image description"}. ' . $this->getFileGuidance();
  }

  /**
   * {@inheritdoc}
   */
  public function getFileGuidance(): string {
    return 'The name a file this conversation holds is known under (the ATTACHMENTS block and tool results name them; never a path or a uuid). The file becomes a media item at once and the reference is final on this write.';
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    // LLMs commonly send [{...}] instead of {...} for single-value fields.
    if ($cardinality === 1 && is_array($value) && array_is_list($value) && count($value) === 1) {
      $value = $value[0];
    }

    if ($cardinality === 1 || !is_array($value) || !array_is_list($value)) {
      $items = [$value];
      $is_single = TRUE;
    }
    else {
      $items = $value;
      $is_single = FALSE;
    }

    $has_file = FALSE;
    foreach ($items as $item) {
      if (is_array($item) && array_key_exists('_file', $item)) {
        $has_file = TRUE;
        break;
      }
    }

    if (!$has_file) {
      return parent::doDeserialize($value, $definition, $options);
    }

    $conversation = (string) ($options['conversation'] ?? '');
    $results = [];
    foreach ($items as $item) {
      if (!is_array($item) || !array_key_exists('_file', $item)) {
        $parent_result = parent::doDeserialize($item, $definition, $options);
        if ($parent_result->hasErrors()) {
          return $parent_result;
        }
        $results[] = $parent_result->getValue();
        continue;
      }
      $placed = $this->place($item, $field_name, $conversation);
      if ($placed instanceof BlueprintError) {
        return new FieldHandlerResult(NULL, [$placed]);
      }
      $results[] = $placed;
    }

    return new FieldHandlerResult($is_single ? ($results[0] ?? NULL) : $results);
  }

  /**
   * Places one {_file} item: the name resolved, the media minted.
   *
   * @param array $item
   *   The item, `_file` present; `alt_text` optional.
   * @param string $field_name
   *   The field being written, for the error path.
   * @param string $conversation
   *   The conversation the write belongs to ('' when the caller has none).
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The {target_id} item, or the error that refused it.
   */
  protected function place(array $item, string $field_name, string $conversation): array|BlueprintError {
    $path = 'fields.' . $field_name;
    $name = $item['_file'];
    if (!is_string($name) || trim($name) === '') {
      return new BlueprintError($path, 'INVALID_FILE_REFERENCE', 'The _file value must be the non-empty name of a file this conversation holds.', [], 'Write {_file: "name"} with the name the ATTACHMENTS block or a tool result gave the file.');
    }
    $name = trim($name);
    $file = $conversation === '' ? NULL : $this->attachments->resolve($conversation, $name);
    if ($file === NULL) {
      return new BlueprintError($path, 'UNKNOWN_FILE', sprintf('No file named "%s" is held in this conversation.', $name), ['name' => $name], 'The name is the handle the ATTACHMENTS block or a tool result gave the file, spelled exactly (names are case-sensitive); a file from another conversation or another user cannot be placed.');
    }
    $alt = trim((string) ($item['alt_text'] ?? '')) ?: $file->getFilename();
    try {
      $media = $this->mediaMinter->mintFromFile($file, $alt, $file->getFilename());
    }
    catch (MediaMinterException $e) {
      return new BlueprintError($path, 'UNSUPPORTED_FILE', sprintf('"%s" cannot be placed as media: %s', $name, $e->getMessage()), ['name' => $name], 'Only files a media type accepts (images, SVG) can be placed on the page; read a textual file with view_file and write its content instead.');
    }
    catch (AccessException $e) {
      return new BlueprintError($path, 'ACCESS_DENIED_FIELD', $e->getMessage(), [], 'The current user lacks permission to create media.');
    }
    return ['target_id' => (int) $media->id()];
  }

}
