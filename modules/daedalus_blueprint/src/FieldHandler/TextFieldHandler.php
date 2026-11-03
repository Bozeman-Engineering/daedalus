<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Compat\FilterFormats;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\filter\FilterFormatInterface;

/**
 * Handler for text_long and text_with_summary field types.
 *
 * Serializes to {value, format} or {value, format, summary} objects.
 * Ensures format is always included for round-trip fidelity.
 *
 * On write the format follows core's text_format element: an item without
 * a format (a bare string, a NULL format) gets the actor's default, the first
 * format of the field's roster the actor may use; a format that does not
 * exist is replaced by that default with a warning; a format the actor may
 * not use is refused. The roster is the enabled formats intersected with
 * the field's allowed_formats, in weight order (the order the site admin
 * set, as core's own form offers them). The actor is the `actor` option,
 * else the current user; under the `system` option nothing is judged for an
 * actor (a load of stored values, the seed, an importer), so the default is
 * the roster's first and a named format is not asked for.
 */
class TextFieldHandler extends FieldHandlerBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FilterFormats $filterFormatRepository,
    protected AccountProxyInterface $currentUser,
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
    $formats = array_keys($this->roster($definition));
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
    // The deserializer and the operations resolve the actor the same way:
    // the option, else the current user, none under the system posture.
    $actor = !empty($options['system']) ? NULL : ($options['actor'] ?? $this->currentUser);

    $errors = [];
    $warnings = [];
    $value = $this->validateFormats($value, $definition, $errors, $warnings, $actor);

    return new FieldHandlerResult($value, $errors, $warnings);
  }

  /**
   * Validates text formats on one or more field items.
   *
   * A bare string, at the top level or as a list entry, is an item with no
   * format, so it gets the default like any other format-less item.
   *
   * @param mixed $value
   *   A bare string, a single item array, or an array of items.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] &$errors
   *   Errors collection to append to.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] &$warnings
   *   Warnings collection to append to.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The account the formats are judged for, or NULL under the system
   *   posture.
   *
   * @return mixed
   *   The value with every item carrying a valid format.
   */
  protected function validateFormats(mixed $value, FieldDefinitionInterface $definition, array &$errors, array &$warnings, ?AccountInterface $actor): mixed {
    if (is_string($value)) {
      $value = ['value' => $value, 'format' => NULL];
    }
    if (!is_array($value)) {
      return $value;
    }

    // Single item (has 'value' key).
    if (isset($value['value'])) {
      return $this->validateItemFormat($value, $definition, $errors, $warnings, $actor);
    }

    // Array of items.
    foreach ($value as $delta => $item) {
      if (is_string($item)) {
        $item = ['value' => $item, 'format' => NULL];
      }
      if (is_array($item)) {
        $value[$delta] = $this->validateItemFormat($item, $definition, $errors, $warnings, $actor);
      }
    }

    return $value;
  }

  /**
   * Validates and fills the text format on a single field item.
   *
   * @param array $item
   *   A field item with at least 'value' and optionally 'format'.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] &$errors
   *   Errors collection to append to.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] &$warnings
   *   Warnings collection to append to.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The account the format is judged for, or NULL under the system
   *   posture.
   *
   * @return array
   *   The item with a valid format.
   */
  protected function validateItemFormat(array $item, FieldDefinitionInterface $definition, array &$errors, array &$warnings, ?AccountInterface $actor): array {
    $field_name = $definition->getName();
    $format_id = $item['format'] ?? NULL;
    $named = is_string($format_id) && $format_id !== '';
    $format = $named ? $this->entityTypeManager->getStorage('filter_format')->load($format_id) : NULL;

    if ($format === NULL) {
      // No format, or one that does not exist: the actor's default, as
      // core's text_format element gives an empty item. The default is
      // already judged for the actor, so no further access check.
      $default = $this->defaultFormat($definition, $actor);
      if ($default === NULL) {
        $roster = array_keys($this->roster($definition));
        $errors[] = new BlueprintError(
          path: "fields.{$field_name}",
          code: 'NO_TEXT_FORMAT',
          message: $named
            ? "Text format '$format_id' does not exist, and no text format on this field is available."
            : 'No text format on this field is available.',
          hint: $roster === []
            ? 'The field allows no enabled text format. Enable a format the field allows, or allow one.'
            : 'The field allows these text formats: ' . implode(', ', $roster) . '. The user may use none of them.',
        );
        return $item;
      }
      if ($named) {
        $warnings[] = new BlueprintWarning(
          path: "fields.{$field_name}",
          code: 'INVALID_TEXT_FORMAT',
          message: "Text format '$format_id' does not exist. Using '{$default->id()}' instead.",
        );
      }
      $item['format'] = $default->id();
      return $item;
    }

    // Enforce the 'use text format' permission on write. In the form paradigm
    // this is enforced only by the text_format form element
    // (\Drupal\filter\Element\TextFormat::processFormat()); core has no
    // entity-level constraint, so programmatic writes like blueprint mutations
    // must check it themselves. If core ever moves format access to the entity
    // level (closest active issue: https://www.drupal.org/i/2874596), this
    // check becomes redundant and should defer to core. The system posture
    // names no actor to judge.
    if ($actor !== NULL && !$format->access('use', $actor)) {
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
   * The default text format of a field for an actor.
   *
   * Core's rule (\Drupal\filter\Element\TextFormat::processFormat(),
   * FilterFormatRepositoryInterface::getDefaultFormat()): the first format
   * of the roster the actor may use. Under the system posture there is no
   * actor, so it is the roster's first.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The account, or NULL under the system posture.
   *
   * @return \Drupal\filter\FilterFormatInterface|null
   *   The default format, or NULL when the actor may use none of the roster.
   */
  protected function defaultFormat(FieldDefinitionInterface $definition, ?AccountInterface $actor): ?FilterFormatInterface {
    foreach ($this->roster($definition) as $format) {
      if ($actor === NULL || $format->access('use', $actor)) {
        return $format;
      }
    }
    return NULL;
  }

  /**
   * The text formats a field offers, in weight order.
   *
   * The enabled formats intersected with the field's allowed_formats (all
   * of them when the field restricts none), in the order
   * FilterFormatRepositoryInterface::getAllFormats() gives them: by weight,
   * as core's own form offers them. Never filtered by actor: the schema and
   * the dialect catalog read it and are site-cached per bundle.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array<string, \Drupal\filter\FilterFormatInterface>
   *   The formats keyed by id, first by weight.
   */
  protected function roster(FieldDefinitionInterface $definition): array {
    $formats = $this->filterFormatRepository->getAllFormats();
    $allowed_formats = $definition->getSetting('allowed_formats') ?: [];
    if (!empty($allowed_formats)) {
      $formats = array_intersect_key($formats, array_flip($allowed_formats));
    }
    return $formats;
  }

}
