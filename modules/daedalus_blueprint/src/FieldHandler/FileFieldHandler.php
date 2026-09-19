<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Psr\Log\LoggerInterface;

/**
 * Handler for file and image field types.
 *
 * Serializes with target_id + _label (filename). Image fields additionally
 * include alt and title; file fields include description and (when the
 * storage enables display_field) the display flag. Deserializes by resolving
 * target_id or _label to a File entity.
 */
class FileFieldHandler extends FieldHandlerBase {

  use ReferenceResolverTrait;

  /**
   * Constructs a FileFieldHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to drop files the user cannot view.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return in_array($definition->getType(), ['file', 'image'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'Accepts {target_id: N} or {_label: "filename.ext"} to reference existing files. Image fields also accept alt and title properties; file fields accept description and display (0 hides the file when the field shows attached files).';
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevantSettings(array $settings): array {
    $relevant = [];

    if (!empty($settings['file_extensions'])) {
      $relevant['file_extensions'] = $settings['file_extensions'];
    }
    if (!empty($settings['max_filesize'])) {
      $relevant['max_filesize'] = $settings['max_filesize'];
    }

    return $relevant;
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $is_image = $definition->getType() === 'image';
    $has_display = !$is_image
      && $definition->getFieldStorageDefinition()->getSetting('display_field');
    $values = [];

    foreach ($items as $item) {
      $file = $item->entity;
      if ($file === NULL) {
        continue;
      }
      // Drop files the current user cannot view.
      if (!$this->accessChecker->entityAccess($file, 'view')) {
        continue;
      }

      $serialized = [
        'target_id' => is_numeric($item->target_id) ? (int) $item->target_id : $item->target_id,
        '_label' => $file->getFilename(),
      ];

      if ($is_image) {
        if (!empty($item->alt)) {
          $serialized['alt'] = $item->alt;
        }
        if (!empty($item->title)) {
          $serialized['title'] = $item->title;
        }
      }
      else {
        if (!empty($item->description)) {
          $serialized['description'] = $item->description;
        }
        // A display of 0 is the meaningful (unchecked) value, so isset-style
        // handling, never empty().
        if ($has_display && $item->display !== NULL) {
          $serialized['display'] = (int) $item->display;
        }
      }

      $values[] = $serialized;
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $is_image = $definition->getType() === 'image';
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
    [$items, $is_single_value] = static::normalizeItems($value, $definition->getName(), $cardinality);

    $results = [];
    $errors = [];
    $warnings = [];

    foreach ($items as $path => $item) {
      if (is_int($item)) {
        $item = ['target_id' => $item];
      }

      if (!is_array($item)) {
        $errors[] = new BlueprintError(
          path: $path,
          code: 'INVALID_REFERENCE_FORMAT',
          message: 'Expected object with target_id or _label.',
          hint: 'Provide {target_id: N} or {_label: "filename.ext"}.',
        );
        continue;
      }

      $resolved = $this->resolveFileItem($item, $path, $warnings);
      if ($resolved instanceof BlueprintError) {
        $errors[] = $resolved;
        continue;
      }

      // Carry over additional properties.
      if ($is_image) {
        if (isset($item['alt'])) {
          $resolved['alt'] = $item['alt'];
        }
        if (isset($item['title'])) {
          $resolved['title'] = $item['title'];
        }
      }
      else {
        if (isset($item['description'])) {
          $resolved['description'] = $item['description'];
        }
        if (isset($item['display'])) {
          $resolved['display'] = (int) $item['display'];
        }
      }

      $results[] = $resolved;
    }

    if (!empty($errors)) {
      return new FieldHandlerResult(NULL, $errors, $warnings);
    }

    if ($is_single_value) {
      return new FieldHandlerResult($results[0] ?? NULL, [], $warnings);
    }

    return new FieldHandlerResult($results, [], $warnings);
  }

  /**
   * Resolves a file reference by target_id or _label.
   *
   * Delegates to the shared ReferenceResolverTrait methods for the 'file'
   * entity type with no bundle constraints.
   *
   * @param array $item
   *   The input item with target_id or _label.
   * @param string $path
   *   The error path.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Collected warnings (passed by reference).
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The resolved ['target_id' => N] or an error.
   */
  protected function resolveFileItem(array $item, string $path, array &$warnings = []): array|BlueprintError {
    if (isset($item['target_id'])) {
      $provided_label = isset($item['_label']) ? (string) $item['_label'] : NULL;
      return $this->resolveByTargetId(
        (int) $item['target_id'], 'file', NULL, $path, $provided_label, $warnings,
      );
    }

    if (isset($item['_label'])) {
      return $this->resolveByLabel(
        (string) $item['_label'], 'file', NULL, $path,
      );
    }

    return new BlueprintError(
      path: $path,
      code: 'INVALID_REFERENCE_FORMAT',
      message: 'File reference must include target_id or _label.',
      hint: 'Provide {target_id: N} or {_label: "filename.ext"}.',
    );
  }

}
