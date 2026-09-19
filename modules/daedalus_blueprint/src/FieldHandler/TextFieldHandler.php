<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;

/**
 * Handler for text_long and text_with_summary field types.
 *
 * Serializes to {value, format} or {value, format, summary} objects.
 * Ensures format is always included for round-trip fidelity.
 * Validates text formats during deserialization: falls back to a valid
 * format when the provided one does not exist, and rejects formats the
 * current user is not permitted to use.
 */
class TextFieldHandler extends FieldHandlerBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    $type = $definition->getType();
    if (in_array($type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
      return TRUE;
    }
    $properties = $definition->getFieldStorageDefinition()->getPropertyDefinitions();
    return isset($properties['format']) && $properties['format']->getDataType() === 'filter_format';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'Accepts {value: "content", format: "format_id"} or a bare string. Check available_formats in the field schema for valid format IDs. text_with_summary fields also accept a summary key.';
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    $formats = $this->getAvailableFormats($definition);
    if (!empty($formats)) {
      return ['available_formats' => $formats];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Long formatted text is the body content a summary exists to omit. Plain
   * 'text' (single-line with a format) stays: it is title-sized and part of
   * the table of contents.
   */
  public function skipInSummary(FieldDefinitionInterface $definition): bool {
    return in_array($definition->getType(), ['text_long', 'text_with_summary'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $has_summary = $definition->getType() === 'text_with_summary';
    $values = [];

    foreach ($items as $item) {
      $serialized = [
        'value' => $item->value,
        'format' => $item->format,
      ];
      if ($has_summary) {
        $serialized['summary'] = $item->summary ?? '';
      }
      $values[] = $serialized;
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    // Normalize single value to expected format.
    if (is_string($value)) {
      $value = ['value' => $value, 'format' => NULL];
    }

    $errors = [];
    $warnings = [];
    $value = $this->validateFormats($value, $definition, $errors, $warnings);

    return new FieldHandlerResult($value, $errors, $warnings);
  }

  /**
   * Validates text formats on one or more field items.
   *
   * @param mixed $value
   *   A single item array or an array of items.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] &$errors
   *   Errors collection to append to.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] &$warnings
   *   Warnings collection to append to.
   *
   * @return mixed
   *   The value with invalid formats replaced.
   */
  protected function validateFormats(mixed $value, FieldDefinitionInterface $definition, array &$errors, array &$warnings): mixed {
    if (!is_array($value)) {
      return $value;
    }

    // Single item (has 'value' key).
    if (isset($value['value'])) {
      return $this->validateItemFormat($value, $definition, $errors, $warnings);
    }

    // Array of items.
    foreach ($value as $delta => $item) {
      if (is_array($item) && isset($item['format'])) {
        $value[$delta] = $this->validateItemFormat($item, $definition, $errors, $warnings);
      }
    }

    return $value;
  }

  /**
   * Validates and corrects the text format on a single field item.
   *
   * @param array $item
   *   A field item with at least 'value' and optionally 'format'.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] &$errors
   *   Errors collection to append to.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] &$warnings
   *   Warnings collection to append to.
   *
   * @return array
   *   The item with a valid format.
   */
  protected function validateItemFormat(array $item, FieldDefinitionInterface $definition, array &$errors, array &$warnings): array {
    if (empty($item['format'])) {
      return $item;
    }

    $format_id = $item['format'];
    $format_storage = $this->entityTypeManager->getStorage('filter_format');
    $format = $format_storage->load($format_id);

    if ($format === NULL) {
      // Format doesn't exist: find a fallback.
      $fallback = $this->getFallbackFormat($definition);
      if ($fallback === NULL) {
        return $item;
      }
      $field_name = $definition->getName();
      $warnings[] = new BlueprintWarning(
        path: "fields.{$field_name}",
        code: 'INVALID_TEXT_FORMAT',
        message: "Text format '$format_id' does not exist. Using '$fallback' instead.",
      );
      $item['format'] = $fallback;
      $format = $format_storage->load($fallback);
    }

    // Enforce the 'use text format' permission on write. In the form paradigm
    // this is enforced only by the text_format form element
    // (\Drupal\filter\Element\TextFormat::processFormat()); core has no
    // entity-level constraint, so programmatic writes like blueprint mutations
    // must check it themselves. If core ever moves format access to the entity
    // level (closest active issue: https://www.drupal.org/i/2874596), this
    // check becomes redundant and should defer to core.
    if (!$format->access('use')) {
      $field_name = $definition->getName();
      $errors[] = new BlueprintError(
        path: "fields.{$field_name}",
        code: 'ACCESS_DENIED_TEXT_FORMAT',
        message: "Access denied: cannot use text format '{$format->id()}'.",
        hint: 'The current user lacks permission to use this text format. Choose a format the user is permitted to use.',
      );
    }

    return $item;
  }

  /**
   * Finds a valid fallback text format for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return string|null
   *   A valid format ID, or NULL if none available.
   */
  protected function getFallbackFormat(FieldDefinitionInterface $definition): ?string {
    $formats = $this->getAvailableFormats($definition);
    return $formats[0] ?? NULL;
  }

  /**
   * Gets available text format IDs for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return string[]
   *   Sorted list of available format machine names.
   */
  protected function getAvailableFormats(FieldDefinitionInterface $definition): array {
    $allowed_formats = $definition->getSetting('allowed_formats') ?: [];
    $format_storage = $this->entityTypeManager->getStorage('filter_format');
    $formats = $format_storage->loadMultiple();

    $enabled_ids = [];
    foreach ($formats as $format) {
      if ($format->status()) {
        $enabled_ids[] = $format->id();
      }
    }

    if (!empty($allowed_formats)) {
      $enabled_ids = array_values(array_intersect($enabled_ids, $allowed_formats));
    }

    sort($enabled_ids);
    return $enabled_ids;
  }

}
