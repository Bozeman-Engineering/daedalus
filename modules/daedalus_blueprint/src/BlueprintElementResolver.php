<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;

/**
 * Resolves any addressable page element with full context.
 *
 * Unified resolution pipeline: locate → identify type → serialize data →
 * enrich with guidance/defaults → return ResolvedElement.
 */
class BlueprintElementResolver implements BlueprintElementResolverInterface {

  /**
   * UUID format pattern.
   */
  protected const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-/i';

  /**
   * Constructs a BlueprintElementResolver.
   *
   * @param \Drupal\daedalus_blueprint\ElementLocator $elementLocator
   *   The component locator.
   * @param \Drupal\daedalus_blueprint\BlueprintContextResolverInterface $contextResolver
   *   The context resolver.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\daedalus_blueprint\BlueprintSerializerInterface $serializer
   *   The blueprint serializer.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer $layoutSerializer
   *   The layout serializer.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to gate field values the user cannot view.
   */
  public function __construct(
    protected ElementLocator $elementLocator,
    protected BlueprintContextResolverInterface $contextResolver,
    protected FieldHandlerManager $fieldHandlerManager,
    protected BlueprintSerializerInterface $serializer,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected LayoutSerializer $layoutSerializer,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(
    FieldableEntityInterface $entity,
    string $identifier,
    ?string $parentUuid = NULL,
  ): ?ResolvedElement {
    if (preg_match(self::UUID_PATTERN, $identifier)) {
      return $this->resolveByUuid($entity, $identifier);
    }

    return $this->resolveByName($entity, $identifier, $parentUuid);
  }

  /**
   * Resolves a UUID-based element (block, paragraph, or section).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $uuid
   *   The UUID to resolve.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if not found.
   */
  protected function resolveByUuid(FieldableEntityInterface $entity, string $uuid): ?ResolvedElement {
    $context = $this->elementLocator->locate($entity, $uuid);
    if ($context === NULL) {
      return NULL;
    }

    if ($context->isSection()) {
      return $this->buildSectionElement($entity, $context);
    }

    if ($context->isLbComponent()) {
      return $this->buildBlockElement($entity, $context);
    }

    if ($context->isParagraph()) {
      return $this->buildParagraphElement($entity, $context);
    }

    return NULL;
  }

  /**
   * Resolves a name-based element (field or region).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $name
   *   The field name or region name.
   * @param string|null $parentUuid
   *   Parent UUID: component UUID for fields on inline blocks, section
   *   UUID for regions.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if not found.
   */
  protected function resolveByName(FieldableEntityInterface $entity, string $name, ?string $parentUuid): ?ResolvedElement {
    if ($parentUuid !== NULL) {
      return $this->resolveNamedOnParent($entity, $name, $parentUuid);
    }

    return $this->resolveFieldOnEntity($entity, $name, $entity);
  }

  /**
   * Resolves a named element on a parent (field on block, or region).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $name
   *   The field name or region name.
   * @param string $parentUuid
   *   The parent component or section UUID.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if not found.
   */
  protected function resolveNamedOnParent(FieldableEntityInterface $entity, string $name, string $parentUuid): ?ResolvedElement {
    $parentContext = $this->elementLocator->locate($entity, $parentUuid);
    if ($parentContext === NULL) {
      return NULL;
    }

    // Region on a section.
    if ($parentContext->isSection()) {
      return $this->buildRegionElement($entity, $name, $parentContext);
    }

    // Field on an inline block.
    if ($parentContext->isLbComponent()) {
      $blockContent = $this->daedalusLayoutAdapter->extractBlockContent($parentContext->target);
      if ($blockContent instanceof FieldableEntityInterface) {
        return $this->resolveFieldOnEntity($entity, $name, $blockContent, $parentContext);
      }
    }

    // Field on a paragraph.
    if ($parentContext->isParagraph() && $parentContext->target instanceof FieldableEntityInterface) {
      return $this->resolveFieldOnEntity($entity, $name, $parentContext->target, $parentContext);
    }

    return NULL;
  }

  /**
   * Resolves a field on an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $rootEntity
   *   The root entity (for context resolution).
   * @param string $fieldName
   *   The field name.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $fieldEntity
   *   The entity that owns the field.
   * @param \Drupal\daedalus_blueprint\ElementContext|null $parentContext
   *   Parent component context, if the field is on an inline block or
   *   paragraph.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if the field doesn't exist.
   */
  protected function resolveFieldOnEntity(
    FieldableEntityInterface $rootEntity,
    string $fieldName,
    FieldableEntityInterface $fieldEntity,
    ?ElementContext $parentContext = NULL,
  ): ?ResolvedElement {
    $definition = $fieldEntity->getFieldDefinition($fieldName);
    if ($definition === NULL) {
      return NULL;
    }

    $info = $this->fieldHandlerManager->inspectFieldDefinition($definition);
    $handler = $this->fieldHandlerManager->getHandler($definition);

    $data = NULL;
    if ($fieldEntity->hasField($fieldName)
      && !$fieldEntity->get($fieldName)->isEmpty()
      && $this->accessChecker->fieldVisible($fieldEntity->get($fieldName))) {
      $data = $handler->serialize($fieldEntity->get($fieldName), $definition);
    }

    // Merge guidance: YAML field_type override wins over handler guidance.
    $handlerGuidance = $info['guidance'] ?? NULL;
    $yamlGuidance = $this->contextResolver->getFieldTypeGuidance(
      $rootEntity->getEntityTypeId(),
      $rootEntity->bundle(),
      $definition->getType(),
    );
    $guidance = $yamlGuidance ?? $handlerGuidance;

    $parentUuid = NULL;
    $parentLabel = NULL;
    $parentTypeId = NULL;
    if ($parentContext !== NULL) {
      $parentUuid = $this->getContextUuid($parentContext);
      $parentLabel = $this->getContextLabel($parentContext);
      $parentTypeId = $this->getContextTypeId($parentContext);
    }

    return new ResolvedElement(
      elementType: 'field',
      typeId: $definition->getType(),
      label: (string) $info['label'],
      entityType: $rootEntity->getEntityTypeId(),
      entityId: $rootEntity->id(),
      fieldName: $fieldName,
      parentUuid: $parentUuid,
      parentLabel: $parentLabel,
      parentTypeId: $parentTypeId,
      guidance: $guidance,
      data: $data,
    );
  }

  /**
   * Builds a ResolvedElement for a Layout Builder component.
   *
   * Handles any SectionComponent: inline blocks, custom block plugins,
   * views blocks, menu blocks, etc.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement
   *   The resolved element.
   */
  protected function buildBlockElement(FieldableEntityInterface $entity, ElementContext $context): ResolvedElement {
    $component = $context->target;
    $uuid = $component->getUuid();
    $config = $component->get('configuration');
    $pluginId = $config['id'] ?? $component->getPluginId();

    $blockType = $this->daedalusLayoutAdapter->getInlineBlockType($component);
    $typeId = $blockType ?? $pluginId;
    $label = $config['label'] ?? $typeId;

    $data = $this->serializer->serializeElement($entity, $uuid);

    // Only inline blocks have block_type context YAML.
    $guidance = NULL;
    $defaults = NULL;
    if ($blockType !== NULL) {
      $blockTypeContext = $this->contextResolver->getBlockTypeContext(
        $entity->getEntityTypeId(),
        $entity->bundle(),
        $blockType,
      );
      $guidance = $blockTypeContext['guidance'] ?? NULL;
      $defaults = $blockTypeContext['defaults'] ?? NULL;
    }

    $parentUuid = NULL;
    $parentLabel = NULL;
    $parentTypeId = NULL;
    if ($context->parentEntity !== $entity) {
      $parentUuid = $context->parentEntity->uuid();
      $parentLabel = $context->parentEntity->label();
      $parentTypeId = $context->parentEntity->bundle();
    }

    return new ResolvedElement(
      elementType: 'block',
      typeId: $typeId,
      label: $label,
      entityType: $entity->getEntityTypeId(),
      entityId: $entity->id(),
      uuid: $uuid,
      parentUuid: $parentUuid,
      parentLabel: $parentLabel,
      parentTypeId: $parentTypeId,
      guidance: $guidance,
      defaults: $defaults,
      data: $data,
    );
  }

  /**
   * Builds a ResolvedElement for a paragraph.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement
   *   The resolved element.
   */
  protected function buildParagraphElement(FieldableEntityInterface $entity, ElementContext $context): ResolvedElement {
    $paragraph = $context->target;
    $uuid = $paragraph->uuid();

    $data = $this->serializer->serializeElement($entity, $uuid);

    return new ResolvedElement(
      elementType: 'paragraph',
      typeId: $paragraph->bundle(),
      label: $paragraph->label() ?: $paragraph->bundle(),
      entityType: $entity->getEntityTypeId(),
      entityId: $entity->id(),
      uuid: $uuid,
      parentUuid: $context->parentEntity->uuid(),
      parentLabel: $context->parentEntity->label(),
      parentTypeId: $context->parentEntity->bundle(),
      data: $data,
    );
  }

  /**
   * Builds a ResolvedElement for a Layout Builder section.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The section context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement
   *   The resolved element.
   */
  protected function buildSectionElement(FieldableEntityInterface $entity, ElementContext $context): ResolvedElement {
    /** @var \Drupal\layout_builder\Section $section */
    $section = $context->target;
    $layoutId = $section->getLayoutId();
    $uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);

    $layoutContext = $this->contextResolver->getLayoutContext(
      $entity->getEntityTypeId(),
      $entity->bundle(),
      $layoutId,
    );

    $data = $this->layoutSerializer->serializeSection($section, [], summarize_blocks: TRUE);

    // The user-facing name lives in layout_settings.label (uniform at every
    // depth); the layout id is the type. Nested and top-level sections
    // resolve identically: a nested section IS a section, no block facade.
    $label = $section->getLayoutSettings()['label'] ?? '';

    return new ResolvedElement(
      elementType: 'section',
      typeId: $layoutId,
      label: is_string($label) && $label !== '' ? $label : $layoutId,
      entityType: $entity->getEntityTypeId(),
      entityId: $entity->id(),
      uuid: $uuid,
      guidance: $layoutContext['guidance'] ?? NULL,
      defaults: $layoutContext['defaults'] ?? NULL,
      data: $data,
    );
  }

  /**
   * Builds a ResolvedElement for a region within a section.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $regionName
   *   The region name.
   * @param \Drupal\daedalus_blueprint\ElementContext $sectionContext
   *   The parent section context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if the region doesn't exist.
   */
  protected function buildRegionElement(FieldableEntityInterface $entity, string $regionName, ElementContext $sectionContext): ?ResolvedElement {
    /** @var \Drupal\layout_builder\Section $section */
    $section = $sectionContext->target;
    $layoutId = $section->getLayoutId();
    $regionNames = $section->getLayout()->getPluginDefinition()->getRegionNames();

    if (!in_array($regionName, $regionNames, TRUE)) {
      return NULL;
    }

    $elements = [];
    foreach ($section->getComponentsByRegion($regionName) as $component) {
      // A nested section presents as a section, never as a block.
      $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
      if ($embedded !== NULL) {
        $embeddedLabel = $embedded->getLayoutSettings()['label'] ?? '';
        $elements[] = [
          'uuid' => $component->getUuid(),
          'layout_id' => $embedded->getLayoutId(),
          'label' => is_string($embeddedLabel) && $embeddedLabel !== '' ? $embeddedLabel : $embedded->getLayoutId(),
        ];
        continue;
      }
      $blockType = $this->daedalusLayoutAdapter->getInlineBlockType($component);
      $config = $component->get('configuration');
      $elements[] = [
        'uuid' => $component->getUuid(),
        'block_type' => $blockType,
        'label' => $config['label'] ?? $blockType,
      ];
    }

    $sectionUuid = $this->daedalusLayoutAdapter->getSectionUuid($section);

    return new ResolvedElement(
      elementType: 'region',
      typeId: $layoutId,
      label: $regionName,
      entityType: $entity->getEntityTypeId(),
      entityId: $entity->id(),
      regionName: $regionName,
      parentUuid: $sectionUuid,
      parentLabel: $layoutId,
      parentTypeId: $layoutId,
      data: ['elements' => $elements],
    );
  }

  /**
   * Gets the UUID from a ElementContext.
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return string|null
   *   The UUID.
   */
  protected function getContextUuid(ElementContext $context): ?string {
    if ($context->isLbComponent()) {
      return $context->target->getUuid();
    }
    if ($context->isParagraph() && $context->target instanceof FieldableEntityInterface) {
      return $context->target->uuid();
    }
    if ($context->isSection()) {
      return $this->daedalusLayoutAdapter->getSectionUuid($context->target);
    }
    return NULL;
  }

  /**
   * Gets the label from a ElementContext.
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return string|null
   *   The label.
   */
  protected function getContextLabel(ElementContext $context): ?string {
    if ($context->isLbComponent()) {
      $config = $context->target->get('configuration');
      return $config['label'] ?: $this->daedalusLayoutAdapter->getInlineBlockType($context->target);
    }
    if ($context->isParagraph() && $context->target instanceof FieldableEntityInterface) {
      return $context->target->label();
    }
    if ($context->isSection()) {
      return $context->target->getLayoutId();
    }
    return NULL;
  }

  /**
   * Gets the type ID from a ElementContext.
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return string|null
   *   The type ID.
   */
  protected function getContextTypeId(ElementContext $context): ?string {
    if ($context->isLbComponent()) {
      return $this->daedalusLayoutAdapter->getInlineBlockType($context->target);
    }
    if ($context->isParagraph() && $context->target instanceof FieldableEntityInterface) {
      return $context->target->bundle();
    }
    if ($context->isSection()) {
      return $context->target->getLayoutId();
    }
    return NULL;
  }

}
