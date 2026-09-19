<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Collects field handler services and resolves field types to handlers.
 *
 * Also provides shared field definition introspection used by the
 * StructuralValidator, BlueprintSchemaBuilder, and DefaultFieldHandler.
 */
class FieldHandlerManager {

  /**
   * Base field names to always exclude from serialization and schema output.
   *
   * These are internal fields that don't belong in the fields map.
   *
   * @var string[]
   */
  protected const ALWAYS_SKIP_FIELDS = [
    'sticky',
    'created',
    'changed',
    'promote',
    'reusable',
    'revision_default',
    'default_langcode',
    'entity_workflow_content',
    'content_translation_source',
    'content_translation_changed',
    'content_translation_outdated',
    'revision_translation_affected',
  ];

  /**
   * Handlers sorted by priority (highest first).
   *
   * @var array<array{priority: int, handler: \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerInterface}>
   */
  protected array $handlers = [];

  /**
   * The default handler for unregistered field types.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\DefaultFieldHandler|null
   */
  protected ?DefaultFieldHandler $defaultHandler = NULL;

  /**
   * Constructs a new FieldHandlerManager.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Adds a field handler.
   *
   * Called by the service_collector compiler pass for each tagged service.
   *
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerInterface $handler
   *   The field handler service.
   * @param string $field_types
   *   Comma-separated list of field types this handler supports
   *   (informational).
   * @param int $priority
   *   Handler priority. Higher values are checked first.
   */
  public function addHandler(FieldHandlerInterface $handler, string $field_types = '', int $priority = 0): void {
    $this->handlers[] = [
      'priority' => $priority,
      'handler' => $handler,
    ];
    usort($this->handlers, fn($a, $b) => $b['priority'] <=> $a['priority']);
  }

  /**
   * Gets the appropriate handler for a field definition.
   *
   * Iterates all registered handlers in priority order and returns the first
   * where applies() returns TRUE. Falls back to DefaultFieldHandler.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerInterface
   *   The handler for the given field.
   */
  public function getHandler(FieldDefinitionInterface $definition): FieldHandlerInterface {
    foreach ($this->handlers as $candidate) {
      if ($candidate['handler']->applies($definition)) {
        return $candidate['handler'];
      }
    }

    return $this->getDefaultHandler();
  }

  /**
   * Gets the default field handler.
   *
   * @return \Drupal\daedalus_blueprint\FieldHandler\DefaultFieldHandler
   *   The default handler.
   */
  protected function getDefaultHandler(): DefaultFieldHandler {
    if ($this->defaultHandler === NULL) {
      $this->defaultHandler = new DefaultFieldHandler($this);
    }
    return $this->defaultHandler;
  }

  /**
   * Checks whether a specific field type should be skipped.
   *
   * The layout_section field type is handled separately by LayoutSerializer.
   *
   * @param string $field_type
   *   The field type machine name.
   *
   * @return bool
   *   TRUE if the field type should be skipped.
   */
  public function isSkippedFieldType(string $field_type): bool {
    return $field_type === 'layout_section';
  }

  /**
   * Gets field names to skip during serialization and schema generation.
   *
   * Excludes entity key fields (id, uuid, bundle, langcode, revision) and
   * known internal base fields. The label field is intentionally NOT skipped.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type_definition
   *   The entity type definition.
   *
   * @return string[]
   *   Field names to skip.
   */
  public function getSkippedFieldNames(EntityTypeInterface $entity_type_definition): array {
    $skipped = static::ALWAYS_SKIP_FIELDS;

    $skip_keys = ['id', 'uuid', 'bundle', 'langcode', 'revision', 'owner', 'published'];
    foreach ($skip_keys as $key) {
      $field_name = $entity_type_definition->getKey($key);
      if ($field_name) {
        $skipped[] = $field_name;
      }
    }

    if ($entity_type_definition instanceof ContentEntityTypeInterface) {
      foreach ($entity_type_definition->getRevisionMetadataKeys() as $field_name) {
        $skipped[] = $field_name;
      }
    }

    return $skipped;
  }

  /**
   * Filters field definitions to those the blueprint system can process.
   *
   * Excludes entity key fields, known internal fields, revision metadata keys,
   * computed fields, internal fields, and skipped field types (e.g.,
   * layout_section). After filtering, invokes
   * hook_daedalus_blueprint_processable_fields_alter() for extensibility.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param array $field_definitions
   *   Field definitions keyed by field name.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Filtered field definitions keyed by field name.
   */
  public function filterFieldDefinitions(EntityTypeInterface $entity_type, array $field_definitions, string $bundle): array {
    $skipped = $this->getSkippedFieldNames($entity_type);
    $result = [];

    foreach ($field_definitions as $name => $definition) {
      if (in_array($name, $skipped, TRUE)) {
        continue;
      }
      if ($definition->isComputed()) {
        continue;
      }
      if ($definition->isInternal()) {
        continue;
      }
      if ($this->isSkippedFieldType($definition->getType())) {
        continue;
      }
      $result[$name] = $definition;
    }

    $entity_type_id = $entity_type->id();
    $this->moduleHandler->alter('daedalus_blueprint_processable_fields', $result, $entity_type_id, $bundle);

    return $result;
  }

  /**
   * Gets processable field definitions from an entity instance.
   *
   * Convenience wrapper around filterFieldDefinitions() for callers that
   * have a loaded entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Filtered field definitions keyed by field name.
   */
  public function getProcessableFieldDefinitions(FieldableEntityInterface $entity): array {
    return $this->filterFieldDefinitions(
      $entity->getEntityType(),
      $entity->getFieldDefinitions(),
      $entity->bundle(),
    );
  }

  /**
   * Introspects a field definition's properties and metadata.
   *
   * Returns a normalized description used by the schema builder (to describe
   * what's possible) and the structural validator (to check submitted data).
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition to introspect.
   *
   * @return array
   *   An associative array with keys:
   *   - 'type': The field type.
   *   - 'label': The field label.
   *   - 'required': Whether the field is required.
   *   - 'cardinality': The field cardinality (-1 = unlimited).
   *   - 'properties': Array of property names for compound fields.
   *   - 'main_property': The main property name (if any).
   *   - 'settings': Relevant field settings.
   *   - 'guidance': The handler's guidance for the JSON door, if any.
   *   - 'generation': For a handler that mints media from a description
   *     (MediaGenerationInterface): its door-neutral 'guidance', and the
   *     active provider's 'dimension_key' + 'dimension_values' when it
   *     takes sizing.
   */
  public function inspectFieldDefinition(FieldDefinitionInterface $definition): array {
    $storage_definition = $definition->getFieldStorageDefinition();
    $field_type = $storage_definition->getType();

    $info = [
      'type' => $field_type,
      'label' => (string) $definition->getLabel(),
      'required' => $definition->isRequired(),
      'cardinality' => $storage_definition->getCardinality(),
    ];

    $main_property = $storage_definition->getMainPropertyName();

    if ($main_property !== NULL) {
      $info['main_property'] = $main_property;
    }

    // Collect meaningful property names (excluding computed properties).
    $properties = $this->getNonComputedProperties($storage_definition);
    if (count($properties) > 1 || ($main_property === NULL && !empty($properties))) {
      $info['properties'] = $properties;
    }

    // Add relevant settings based on field type.
    $handler = $this->getHandler($definition);
    $settings = $definition->getSettings();
    $relevant_settings = $handler->getRelevantSettings($settings);
    $this->moduleHandler->alter('daedalus_blueprint_relevant_settings', $relevant_settings, $definition, $settings);
    if (!empty($relevant_settings)) {
      $info['settings'] = $relevant_settings;
    }

    $guidance = $handler->getGuidance();
    if ($guidance !== NULL) {
      $info['guidance'] = $guidance;
    }
    if ($handler instanceof MediaGenerationInterface) {
      $info['generation'] = ['guidance' => $handler->getGenerationGuidance()];
      $key = $handler->getDimensionKey();
      if ($key !== NULL) {
        $info['generation']['dimension_key'] = $key;
        $info['generation']['dimension_values'] = $handler->getDimensionValues();
      }
    }

    return $info;
  }

  /**
   * Gets the non-computed property names from a field storage definition.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
   *   The field storage definition.
   *
   * @return string[]
   *   Non-computed property names.
   */
  public function getNonComputedProperties(FieldStorageDefinitionInterface $storage_definition): array {
    $properties = [];
    foreach ($storage_definition->getPropertyDefinitions() as $name => $property_definition) {
      if (!$property_definition->isComputed()) {
        $properties[] = $name;
      }
    }
    return $properties;
  }

}
