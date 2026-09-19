<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;

/**
 * Serializes Drupal entities to blueprint JSON format.
 *
 * Dispatches field serialization to registered FieldHandlers. Layout Builder
 * serialization is delegated to LayoutSerializer.
 */
class BlueprintSerializer implements BlueprintSerializerInterface {

  /**
   * Constructs a BlueprintSerializer.
   *
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer $layoutSerializer
   *   The layout serializer.
   * @param \Drupal\daedalus_blueprint\ElementLocator $elementLocator
   *   The element locator.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter.
   * @param \Drupal\daedalus_blueprint\ManagedKeysRegistryInterface $managedKeysRegistry
   *   The managed keys registry.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to filter fields the user cannot view.
   */
  public function __construct(
    protected FieldHandlerManager $fieldHandlerManager,
    protected LayoutSerializer $layoutSerializer,
    protected ElementLocator $elementLocator,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function serialize(FieldableEntityInterface $entity, ?string $langcode = NULL): array {
    ['entity' => $entity, 'result' => $result, 'isNonDefaultTranslation' => $is_non_default_translation] = $this->prepareSerializationContext($entity, $langcode);

    // Serialize fields.
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    $fields = [];

    foreach ($processable_fields as $field_name => $definition) {
      // For non-default translations, only include translatable fields.
      if ($is_non_default_translation && !$definition->isTranslatable()) {
        continue;
      }

      // Fields the current user cannot view carry identity only, never a
      // value: access gates values (and verbs at use time), not structure.
      if (!$this->accessChecker->fieldVisible($entity->get($field_name))) {
        $fields[$field_name] = FormOnlyStub::forDefinition($definition);
        continue;
      }

      $handler = $this->fieldHandlerManager->getHandler($definition);
      $field_value = $handler->serialize($entity->get($field_name), $definition);

      // Structure completeness: FULL mode gives every processable
      // definition a node. A field without a serialized value (empty, or no
      // serializable representation) gets an identity-only stub so the
      // panel and the presentation policy cover the same set the canvas can
      // select. Summary mode keeps its sparse transport shape.
      $fields[$field_name] = $field_value ?? FormOnlyStub::forDefinition($definition);
    }

    if (!empty($fields)) {
      $result['fields'] = $fields;
    }

    // Serialize Layout Builder layout if present.
    $layout = $this->layoutSerializer->serialize($entity);
    if (!empty($layout)) {
      $result['layout_builder__layout'] = $layout;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function serializeSummary(FieldableEntityInterface $entity, ?string $langcode = NULL): array {
    ['entity' => $entity, 'result' => $result, 'isNonDefaultTranslation' => $is_non_default_translation] = $this->prepareSerializationContext($entity, $langcode);

    // Serialize fields with summary filtering.
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    $fields = [];

    foreach ($processable_fields as $field_name => $definition) {
      if ($is_non_default_translation && !$definition->isTranslatable()) {
        continue;
      }

      // Skip fields the current user cannot view.
      if (!$this->accessChecker->fieldVisible($entity->get($field_name))) {
        continue;
      }

      $field_type = $definition->getType();
      $handler = $this->fieldHandlerManager->getHandler($definition);

      // Skip large content fields in summary mode: the handler owning the
      // field type declares it.
      if ($handler->skipInSummary($definition)) {
        continue;
      }

      // Inline entities: show as summary (uuid, target_id, type, label).
      if ($field_type === 'entity_reference_revisions') {
        $summary_value = $this->layoutSerializer->serializeInlineEntitySummary(
          $entity->get($field_name),
          $definition,
        );
        if ($summary_value !== NULL) {
          $fields[$field_name] = $summary_value;
        }
        continue;
      }

      // All other fields: serialize normally via handlers.
      $field_value = $handler->serialize($entity->get($field_name), $definition);

      if ($field_value !== NULL) {
        $fields[$field_name] = $field_value;
      }
    }

    if (!empty($fields)) {
      $result['fields'] = $fields;
    }

    // Serialize Layout Builder layout as summary.
    $layout = $this->layoutSerializer->serializeSummary($entity);
    if (!empty($layout)) {
      $result['layout_builder__layout'] = $layout;
    }

    return $result;
  }

  /**
   * Prepares the serialization context shared by serialize and summary.
   *
   * Resolves translation, detects non-default translation state, and builds
   * the base result array with entity_type, bundle, langcode, and _root_entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to serialize.
   * @param string|null $langcode
   *   Optional langcode to select a translation.
   *
   * @return array{entity: \Drupal\Core\Entity\FieldableEntityInterface, result: array, isNonDefaultTranslation: bool}
   *   Array with 'entity' (possibly translated), 'result' (base output), and
   *   'isNonDefaultTranslation' flag.
   */
  protected function prepareSerializationContext(FieldableEntityInterface $entity, ?string $langcode): array {
    if ($langcode !== NULL && $entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }

    $entity_type_definition = $entity->getEntityType();

    $is_non_default_translation = FALSE;
    if ($entity instanceof TranslatableInterface) {
      $default_langcode = $entity->getUntranslated()->language()->getId();
      $active_langcode = $entity->language()->getId();
      $is_non_default_translation = $active_langcode !== $default_langcode;
    }

    $result = [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
    ];

    if ($entity->hasField('langcode') && !$entity->get('langcode')->isEmpty()) {
      $result['langcode'] = $entity->get('langcode')->value;
    }

    if ($entity->id()) {
      $id_key = $entity_type_definition->getKey('id');
      $id = $entity->id();
      $result['_root_entity'] = [
        'field' => $id_key,
        'value' => is_numeric($id) ? (int) $id : $id,
      ];
    }

    return [
      'entity' => $entity,
      'result' => $result,
      'isNonDefaultTranslation' => $is_non_default_translation,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function serializeElement(FieldableEntityInterface $entity, string $uuid, ?string $langcode = NULL): ?array {
    // If langcode provided, get translation.
    if ($langcode !== NULL && $entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }

    $context = $this->elementLocator->locate($entity, $uuid);
    if ($context === NULL) {
      return NULL;
    }

    if ($context->isLbComponent()) {
      $managed_keys = $this->managedKeysRegistry->getManagedKeys(
        $entity->getEntityTypeId(),
        $entity->bundle(),
      );
      return $this->serializeLbComponent($context, $managed_keys);
    }

    if ($context->isSection()) {
      $managed_keys = $this->managedKeysRegistry->getManagedKeys(
        $entity->getEntityTypeId(),
        $entity->bundle(),
      );
      return $this->layoutSerializer->serializeSection($context->target, $managed_keys, summarize_blocks: TRUE);
    }

    return $this->serializeParagraphElement($context);
  }

  /**
   * Serializes an LB component at element depth.
   *
   * Returns full block_fields and metadata. Nested layouts and ERR fields
   * within block_fields are shown as summaries.
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The element context from the locator.
   * @param array $managed_keys
   *   The managed keys for the root entity type + bundle.
   *
   * @return array
   *   The serialized element.
   */
  protected function serializeLbComponent(ElementContext $context, array $managed_keys): array {
    $component = $context->target;
    $result = [
      'uuid' => $component->getUuid(),
      'region' => $component->getRegion(),
      'weight' => $component->getWeight(),
    ];

    if (!$this->daedalusLayoutAdapter->isInlineBlock($component)) {
      $configuration = $component->get('configuration');
      $result['plugin_id'] = $configuration['id'] ?? $component->getPluginId();
      return $result;
    }

    $this->layoutSerializer->serializeInlineBlockComponent(
      $result,
      $component,
      $managed_keys,
      rich_managed_keys: TRUE,
      summarize_err: TRUE,
    );

    return $result;
  }

  /**
   * Serializes a paragraph entity at element depth.
   *
   * Returns full field values. Nested ERR fields within the paragraph are
   * shown as summaries.
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The element context from the locator.
   *
   * @return array
   *   The serialized paragraph element.
   */
  protected function serializeParagraphElement(ElementContext $context): array {
    $entity = $context->target;

    $result = [
      'uuid' => $entity->uuid(),
      'target_id' => is_numeric($entity->id()) ? (int) $entity->id() : $entity->id(),
      'type' => $entity->bundle(),
    ];

    $fields = $this->serializeElementFields($entity);
    if (!empty($fields)) {
      $result['fields'] = $fields;
    }

    return $result;
  }

  /**
   * Serializes an entity's fields at element depth.
   *
   * All fields are serialized fully via handlers, except ERR fields which
   * are shown as summaries (uuid, target_id, type, label).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose fields to serialize.
   *
   * @return array
   *   The serialized fields.
   */
  protected function serializeElementFields(FieldableEntityInterface $entity): array {
    return $this->layoutSerializer->serializeBlockFields($entity, summarize_err: TRUE);
  }

}
