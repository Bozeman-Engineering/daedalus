<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;

/**
 * Resolves any addressable page element with full context.
 *
 * Unified resolution pipeline: locate → identify type → serialize data →
 * enrich with guidance/defaults → return ResolvedElement. A layout
 * element's data is its lean node as the element read answers it
 * (BlueprintSerializerInterface::serializeElement()); a region's data is
 * the region node of its container.
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
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to gate field values the user cannot view.
   */
  public function __construct(
    protected ElementLocator $elementLocator,
    protected BlueprintContextResolverInterface $contextResolver,
    protected FieldHandlerManager $fieldHandlerManager,
    protected BlueprintSerializerInterface $serializer,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(
    HostInterface $host,
    string $identifier,
    ?string $parentUuid = NULL,
  ): ?ResolvedElement {
    if (preg_match(self::UUID_PATTERN, $identifier)) {
      return $this->resolveByUuid($host, $identifier);
    }

    return $this->resolveByName($host, $identifier, $parentUuid);
  }

  /**
   * Resolves a UUID-based element (block, paragraph, or section).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param string $uuid
   *   The UUID to resolve.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if not found.
   */
  protected function resolveByUuid(HostInterface $host, string $uuid): ?ResolvedElement {
    $context = $this->elementLocator->locate($host, $uuid);
    if ($context === NULL) {
      return NULL;
    }

    if ($context->isSection()) {
      return $this->buildSectionElement($host, $context);
    }

    if ($context->isLbComponent()) {
      return $this->buildBlockElement($host, $context);
    }

    if ($context->isParagraph()) {
      return $this->buildParagraphElement($host, $context);
    }

    return NULL;
  }

  /**
   * Resolves a name-based element (field or region).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param string $name
   *   The field name or region name.
   * @param string|null $parentUuid
   *   Parent UUID: component UUID for fields on inline blocks, section
   *   UUID for regions.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if not found.
   */
  protected function resolveByName(HostInterface $host, string $name, ?string $parentUuid): ?ResolvedElement {
    if ($parentUuid !== NULL) {
      return $this->resolveNamedOnParent($host, $name, $parentUuid);
    }

    $fields = $host->fields();
    return $fields === NULL ? NULL : $this->resolveFieldOnEntity($host, $name, $fields);
  }

  /**
   * Resolves a named element on a parent (field on block, or region).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param string $name
   *   The field name or region name.
   * @param string $parentUuid
   *   The parent component or section UUID.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if not found.
   */
  protected function resolveNamedOnParent(HostInterface $host, string $name, string $parentUuid): ?ResolvedElement {
    $parentContext = $this->elementLocator->locate($host, $parentUuid);
    if ($parentContext === NULL) {
      return NULL;
    }

    // Region on a section.
    if ($parentContext->isSection()) {
      return $this->buildRegionElement($host, $name, $parentContext);
    }

    // Field on an inline block.
    if ($parentContext->isLbComponent()) {
      $blockContent = $this->daedalusLayoutAdapter->extractBlockContent($parentContext->target);
      if ($blockContent instanceof FieldableEntityInterface) {
        return $this->resolveFieldOnEntity($host, $name, $blockContent, $parentContext);
      }
    }

    // Field on a paragraph.
    if ($parentContext->isParagraph() && $parentContext->target instanceof FieldableEntityInterface) {
      return $this->resolveFieldOnEntity($host, $name, $parentContext->target, $parentContext);
    }

    return NULL;
  }

  /**
   * Resolves a field on an entity.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host (for context resolution).
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
    HostInterface $host,
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
      $host->entityTypeId(),
      $host->bundle(),
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
      entityType: $host->entityTypeId(),
      entityId: $host->id(),
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement
   *   The resolved element.
   */
  protected function buildBlockElement(HostInterface $host, ElementContext $context): ResolvedElement {
    $component = $context->target;
    $uuid = $component->getUuid();
    $config = $component->get('configuration');
    $pluginId = $config['id'] ?? $component->getPluginId();

    $blockType = $this->daedalusLayoutAdapter->getInlineBlockType($component);
    $typeId = $blockType ?? $pluginId;
    $label = $config['label'] ?? $typeId;

    $data = $this->serializer->serializeElement($host, $uuid);

    // Only inline blocks have block_type context YAML.
    $guidance = NULL;
    $defaults = NULL;
    if ($blockType !== NULL) {
      $blockTypeContext = $this->contextResolver->getBlockTypeContext(
        $host->entityTypeId(),
        $host->bundle(),
        $blockType,
      );
      $guidance = $blockTypeContext['guidance'] ?? NULL;
      $defaults = $blockTypeContext['defaults'] ?? NULL;
    }

    $parentUuid = NULL;
    $parentLabel = NULL;
    $parentTypeId = NULL;
    if ($context->parentEntity !== NULL && $context->parentEntity !== $host->fields()) {
      $parentUuid = $context->parentEntity->uuid();
      $parentLabel = $context->parentEntity->label();
      $parentTypeId = $context->parentEntity->bundle();
    }

    return new ResolvedElement(
      elementType: 'block',
      typeId: $typeId,
      label: $label,
      entityType: $host->entityTypeId(),
      entityId: $host->id(),
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The component context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement
   *   The resolved element.
   */
  protected function buildParagraphElement(HostInterface $host, ElementContext $context): ResolvedElement {
    $paragraph = $context->target;
    $uuid = $paragraph->uuid();

    $data = $this->serializer->serializeElement($host, $uuid);

    return new ResolvedElement(
      elementType: 'paragraph',
      typeId: $paragraph->bundle(),
      label: $paragraph->label() ?: $paragraph->bundle(),
      entityType: $host->entityTypeId(),
      entityId: $host->id(),
      uuid: $uuid,
      parentUuid: $context->parentEntity?->uuid(),
      parentLabel: $context->parentEntity?->label(),
      parentTypeId: $context->parentEntity?->bundle(),
      data: $data,
    );
  }

  /**
   * Builds a ResolvedElement for a Layout Builder section.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The section context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement
   *   The resolved element.
   */
  protected function buildSectionElement(HostInterface $host, ElementContext $context): ResolvedElement {
    /** @var \Drupal\layout_builder\Section $section */
    $section = $context->target;
    $layoutId = $section->getLayoutId();
    $uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);

    $layoutContext = $this->contextResolver->getLayoutContext(
      $host->entityTypeId(),
      $host->bundle(),
      $layoutId,
    );

    $data = $uuid === NULL ? NULL : $this->serializer->serializeElement($host, $uuid);

    // The user-facing name lives in layout_settings.label (uniform at every
    // depth); the layout id is the type. Nested and top-level sections
    // resolve identically: a nested section IS a section, no block facade.
    $label = $section->getLayoutSettings()['label'] ?? '';

    return new ResolvedElement(
      elementType: 'section',
      typeId: $layoutId,
      label: is_string($label) && $label !== '' ? $label : $layoutId,
      entityType: $host->entityTypeId(),
      entityId: $host->id(),
      uuid: $uuid,
      guidance: $layoutContext['guidance'] ?? NULL,
      defaults: $layoutContext['defaults'] ?? NULL,
      data: $data,
    );
  }

  /**
   * Builds a ResolvedElement for a region within a section.
   *
   * The region's data is its region node as the container's element read
   * carries it ({kind: 'region', region, children}); a container layout
   * has no region nodes, so no region of it resolves.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param string $regionName
   *   The region name.
   * @param \Drupal\daedalus_blueprint\ElementContext $sectionContext
   *   The parent section context.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element, or NULL if the region doesn't exist.
   */
  protected function buildRegionElement(HostInterface $host, string $regionName, ElementContext $sectionContext): ?ResolvedElement {
    /** @var \Drupal\layout_builder\Section $section */
    $section = $sectionContext->target;
    $layoutId = $section->getLayoutId();
    $sectionUuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    $container = $sectionUuid === NULL ? NULL : $this->serializer->serializeElement($host, $sectionUuid);

    $region = NULL;
    foreach ($container['children'] ?? [] as $child) {
      if (is_array($child) && ($child['kind'] ?? NULL) === 'region' && ($child['region'] ?? NULL) === $regionName) {
        $region = $child;
        break;
      }
    }
    if ($region === NULL) {
      return NULL;
    }

    return new ResolvedElement(
      elementType: 'region',
      typeId: $layoutId,
      label: $regionName,
      entityType: $host->entityTypeId(),
      entityId: $host->id(),
      regionName: $regionName,
      parentUuid: $sectionUuid,
      parentLabel: $layoutId,
      parentTypeId: $layoutId,
      data: $region,
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
