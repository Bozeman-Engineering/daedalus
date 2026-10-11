<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;

/**
 * Serializes Drupal entities to blueprint JSON format.
 *
 * Dispatches field serialization to registered FieldHandlers. The document
 * rides at the top level for every host, in its one shape: `layout` is
 * the lean node tree (LayoutSerializer over the host's sections, every
 * node's styles on the node) and `styles` the node-less remainder of the
 * host's style map (`entity:` and `field:` keys and their rule forms,
 * which no layout node carries). Neither is ever a field. A read, with
 * its `_root_entity` removed, is what the write grammar takes.
 *
 * Every read is stamped with what the current user may do to it
 * (VerdictStamper): `_verdict` on the root and on every layout node, the
 * same answer the op door enforces, so a consumer of the full read, the
 * summary or an element never interprets ownership, translation, the
 * workspace or the role on its own.
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
   *   The element locator, for the elements the tree does not hold
   *   (paragraphs).
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to filter fields the user cannot view.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver: a translation read is the translation's host.
   * @param \Drupal\daedalus_blueprint\Capability\VerdictStamper $verdictStamper
   *   The verdict stamp every read carries.
   */
  public function __construct(
    protected FieldHandlerManager $fieldHandlerManager,
    protected LayoutSerializer $layoutSerializer,
    protected ElementLocator $elementLocator,
    protected BlueprintAccessChecker $accessChecker,
    protected HostResolver $hostResolver,
    protected VerdictStamper $verdictStamper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function serialize(HostInterface $host, ?string $langcode = NULL): array {
    ['entity' => $entity, 'result' => $result, 'isNonDefaultTranslation' => $is_non_default_translation] = $this->prepareSerializationContext($host, $langcode);

    // Serialize fields (a host with no fields of its own, a template,
    // carries the document only).
    $processable_fields = $entity === NULL ? [] : $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
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
      // select; the stub says whether the field is empty, which is the
      // panel's one source for its "Fill with sample" verb (a visible
      // field's emptiness is the user's to know). Summary mode keeps its
      // sparse transport shape.
      $fields[$field_name] = $field_value ?? FormOnlyStub::forDefinition(
        $definition,
        empty: $entity->get($field_name)->isEmpty(),
      );
    }

    if (!empty($fields)) {
      $result['fields'] = $fields;
    }

    $result += $this->document($host, FALSE);

    return $this->verdictStamper->stampBlueprint($result, $this->readHost($host, $langcode));
  }

  /**
   * {@inheritdoc}
   */
  public function serializeSummary(HostInterface $host, ?string $langcode = NULL): array {
    ['entity' => $entity, 'result' => $result, 'isNonDefaultTranslation' => $is_non_default_translation] = $this->prepareSerializationContext($host, $langcode);

    // Serialize fields with summary filtering (none on a fieldless host).
    $processable_fields = $entity === NULL ? [] : $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
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

    $result += $this->document($host, TRUE);

    return $this->verdictStamper->stampBlueprint($result, $this->readHost($host, $langcode));
  }

  /**
   * The document keys of a read: the tree and the node-less style keys.
   *
   * The host's style map, when the user may view it, is distributed onto
   * the tree's nodes by the serializer; what no node carries (the root's
   * and its fields' keys, and any stale key addressing a node the
   * document no longer has) is the top-level `styles`. Either key is
   * absent when it would be empty.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param bool $summarize
   *   Whether the tree is the pared one.
   *
   * @return array
   *   {layout?: the tree, styles?: the node-less entries}.
   */
  protected function document(HostInterface $host, bool $summarize): array {
    $styles = $this->styles($host);
    $layout = $summarize
      ? $this->layoutSerializer->serializeSummary($host, $styles ?? [])
      : $this->layoutSerializer->serialize($host, $styles ?? []);
    $document = [];
    if ($layout !== []) {
      $document['layout'] = $layout;
    }
    if ($styles !== NULL) {
      $nodeless = NodeTreeBuilder::nodelessStyles($layout, $styles);
      if ($nodeless !== []) {
        $document['styles'] = $nodeless;
      }
    }
    return $document;
  }

  /**
   * The host a read is judged on: the translation's when one is read.
   *
   * The document is one structure shared by every translation, so the
   * translation policy reads which translation is being read off the host
   * (TranslationPolicy asks the host's fields entity); a langcode names
   * that translation's host.
   */
  protected function readHost(HostInterface $host, ?string $langcode): HostInterface {
    $fields = $host->fields();
    if ($langcode === NULL || !$fields instanceof TranslatableInterface || !$fields->hasTranslation($langcode)) {
      return $host;
    }
    return $this->hostResolver->fromEntity($fields->getTranslation($langcode));
  }

  /**
   * The host's style map for delivery.
   *
   * Access gates values: a map the user cannot view is not delivered, and
   * an empty map is not carried (the client reads a missing key as no
   * styles).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return array|null
   *   The map, or NULL when there is nothing to carry.
   */
  protected function styles(HostInterface $host): ?array {
    $definition = $host->styleMapDefinition();
    if ($definition === NULL) {
      return NULL;
    }
    $store = $host->fields()?->get($definition->getName());
    if ($store !== NULL && !$this->accessChecker->fieldVisible($store)) {
      return NULL;
    }
    $map = $host->styleMap();
    return $map === [] ? NULL : $map;
  }

  /**
   * Prepares the serialization context shared by serialize and summary.
   *
   * Resolves translation, detects non-default translation state, and builds
   * the base result array with entity_type, bundle, langcode, and _root_entity.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to serialize.
   * @param string|null $langcode
   *   Optional langcode to select a translation.
   *
   * @return array{entity: \Drupal\Core\Entity\FieldableEntityInterface, result: array, isNonDefaultTranslation: bool}
   *   Array with 'entity' (the host's fields entity, possibly translated),
   *   'result' (base output), and 'isNonDefaultTranslation' flag.
   */
  protected function prepareSerializationContext(HostInterface $host, ?string $langcode): array {
    // The fields entity, when the host has one; a template carries none
    // and its identity is the host's.
    $entity = $host->fields();
    if ($langcode !== NULL && $entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }

    $is_non_default_translation = FALSE;
    if ($entity instanceof TranslatableInterface) {
      $default_langcode = $entity->getUntranslated()->language()->getId();
      $active_langcode = $entity->language()->getId();
      $is_non_default_translation = $active_langcode !== $default_langcode;
    }

    $result = [
      'entity_type' => $host->entityTypeId(),
      'bundle' => $host->bundle(),
    ];

    if ($entity !== NULL && $entity->hasField('langcode') && !$entity->get('langcode')->isEmpty()) {
      $result['langcode'] = $entity->get('langcode')->value;
    }

    $id = $host->id();
    if ($id) {
      $result['_root_entity'] = [
        'field' => $host->entity()->getEntityType()->getKey('id'),
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
  public function serializeElement(HostInterface $host, string $uuid, ?string $langcode = NULL): ?array {
    // If langcode provided, locate in the translation.
    $host = $this->readHost($host, $langcode);

    // A layout element answers its node, the same object it is inside the
    // full read's `layout` (a container with its subtree), styles on it.
    // A plain host reads the document it renders, so an element of its
    // template is found in the host's own tree and judged on the host,
    // where every gesture at it is foreign.
    $node = NodeTreeBuilder::find($this->layoutSerializer->serialize($host, $this->styles($host) ?? []), $uuid);
    if ($node !== NULL) {
      return $this->verdictStamper->stampElement($node, $host);
    }

    // A paragraph is not a tree node: the locator finds it in the host's
    // fields.
    $context = $this->elementLocator->locate($host, $uuid);
    if ($context === NULL || !$context->isParagraph()) {
      return NULL;
    }
    return $this->verdictStamper->stampElement($this->serializeParagraphElement($context), $host);
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
