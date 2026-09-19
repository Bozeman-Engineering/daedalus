<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Interface for field type handlers.
 *
 * Provides bidirectional transformation between Drupal field values and JSON.
 * Handlers are registered as tagged services and resolved by the
 * FieldHandlerManager based on field type, priority, and applicability.
 */
interface FieldHandlerInterface {

  /**
   * Whether this handler can process the given field definition.
   *
   * Called during handler resolution for every registered handler in priority
   * order. The first handler to return TRUE is used. Handlers should check
   * field type and any additional criteria (target entity type, settings, or
   * structural properties) to determine applicability.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition to check.
   *
   * @return bool
   *   TRUE if this handler should process the field.
   */
  public function applies(FieldDefinitionInterface $definition): bool;

  /**
   * Serializes a field's value to JSON-compatible format.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field item list to serialize.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return mixed
   *   The serialized value. Scalar for single-property fields, array for
   *   compound fields, array of values for multi-value fields.
   */
  public function serialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): mixed;

  /**
   * Deserializes a JSON value into a format suitable for $entity->set().
   *
   * @param mixed $value
   *   The incoming JSON value.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param array $options
   *   Options from the caller:
   *   - 'is_update' (bool): TRUE when updating an existing entity/element.
   *   - 'entity' (\Drupal\Core\Entity\FieldableEntityInterface): the entity
   *     being written to, when the caller has it. Context for handlers whose
   *     validation depends on the entity's current state; not guaranteed on
   *     every path.
   *   - 'fields' (array): the full submitted field payload (field name =>
   *     raw value), when the caller is writing several fields at once.
   *     Context for handlers whose validation depends on a SIBLING field's
   *     incoming value — on a create, the entity option carries no value for
   *     fields later in the payload than the one being deserialized, and
   *     handlers must not depend on payload key order. Not guaranteed on
   *     every path.
   *   - 'element_uuid' (string): the uuid addressing the element whose field
   *     is being written, in blueprint target terms: the layout component
   *     uuid for inline-block fields, the entity's own uuid for root fields
   *     and paragraphs. Context for handlers that record change targets of
   *     their own (e.g. pending-generation effects); not guaranteed on every
   *     path.
   *
   * @return \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult
   *   The result containing the processed value and any errors/warnings.
   */
  public function deserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult;

  /**
   * Returns guidance describing accepted input formats for this handler.
   *
   * Guidance is concise, LLM-facing text about what formats the handler's
   * deserialize() method accepts. It is type-level, not instance-specific.
   *
   * @return string|null
   *   A concise guidance string, or NULL if no special guidance is needed.
   */
  public function getGuidance(): ?string;

  /**
   * Extracts schema-relevant settings for this field type.
   *
   * Returns the subset of field settings that are meaningful for AI/schema
   * consumers. Each handler knows which settings matter for its field type.
   *
   * @param array $settings
   *   The full field settings from $definition->getSettings().
   *
   * @return array
   *   Flat associative array of relevant settings.
   */
  public function getRelevantSettings(array $settings): array;

  /**
   * Returns additional schema data for this field.
   *
   * Allows handlers to contribute instance-specific schema information beyond
   * guidance and settings. For example, the text handler returns available text
   * formats for the specific field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array
   *   Associative array merged into the field schema. Empty if none.
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array;

  /**
   * Whether this field is omitted from summary serializations.
   *
   * Summary mode is a table of contents: the entity tree without the content
   * itself. Handlers whose values are large content (long formatted text)
   * declare it here, so the serializer never keeps its own list of skippable
   * field types.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return bool
   *   TRUE to omit the field from summary output.
   */
  public function skipInSummary(FieldDefinitionInterface $definition): bool;

}
