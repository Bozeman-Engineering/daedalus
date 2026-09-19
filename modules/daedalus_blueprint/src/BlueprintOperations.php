<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Drupal\daedalus_blueprint\Event\SectionCreatedEvent;
use Drupal\daedalus_blueprint\Event\ElementDuplicatedEvent;
use Drupal\daedalus_blueprint\Event\ElementMovedEvent;
use Drupal\daedalus_blueprint\Event\LifecycleEventBase;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\AvailableLayouts;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\OpaqueDataMerger;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint\Utility\HtmlEntityDecoder;
use Drupal\daedalus_blueprint\Validator\SemanticValidator;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Targeted CRUD operations for AI-driven entity editing.
 *
 * Read methods delegate to BlueprintSerializer. Write methods manipulate
 * entities directly using the FieldHandler infrastructure. Each write
 * method returns the unsaved entity — the caller controls persistence.
 */
class BlueprintOperations implements BlueprintOperationsInterface {

  /**
   * Field types rejected by updateFields().
   *
   * Layout sections are handled via layout operations. Inline entities
   * are handled via addElement/removeElement/reorderField.
   *
   * @var string[]
   */
  protected const REJECTED_FIELD_TYPES = [
    'layout_section',
    'entity_reference_revisions',
  ];

  /**
   * Batch operation names dispatched directly by applyOperation().
   *
   * Modules add further operations via the operation handler registry
   * (services tagged daedalus_blueprint.operation_handler).
   *
   * @var string[]
   */
  protected const BUILTIN_OPERATIONS = [
    'update_element', 'add_element', 'add_section', 'remove_element',
    'duplicate_element', 'move_element',
    'restructure_layout', 'reorder_field',
  ];

  /**
   * Retired grammar spellings and their corrective hints.
   *
   * The ONE map of retired vocabulary, shared by every surface that meets
   * old spellings: op dispatch consults it for retired operation names, and
   * the stencil handler consults it for retired relative style forms. An
   * entry is not backwards compatibility (the spelling fails either way);
   * it is a teaching corrective, earned only when the MENTAL MODEL changed
   * and the replacement is non-obvious. Pure renames never get one: the
   * generic INVALID_OPERATION valid-ops list already teaches those.
   *
   * @var array<string, string>
   */
  public const RETIRED_GRAMMAR = [
    'change_section_layout' => 'Sections are born with their layout and keep it for life. add_section mints a container by default (arrangement is styling, not layout choice); pass "layout" (+ optional "layout_settings") on add_section for a named-slot layout. To rearrange existing content, use move_element or restructure_layout.',
    'region:NAME' => "Sections are containers with one flow of children. Style the section itself ('') or a child container node's '' instead.",
  ];

  /**
   * Constructs a BlueprintOperations service.
   *
   * @param \Drupal\daedalus_blueprint\BlueprintSerializerInterface $serializer
   *   The blueprint serializer.
   * @param \Drupal\daedalus_blueprint\BlueprintDeserializerInterface $deserializer
   *   The blueprint deserializer.
   * @param \Drupal\daedalus_blueprint\ElementLocator $elementLocator
   *   The element locator.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\daedalus_blueprint\Validator\SemanticValidator $semanticValidator
   *   The semantic validator.
   * @param \Drupal\daedalus_blueprint\ManagedKeysRegistryInterface $managedKeysRegistry
   *   The managed keys registry.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface $placementRestrictions
   *   The placement-restriction spine (blocks and layouts).
   * @param \Drupal\daedalus_blueprint\BlueprintContextResolverInterface $contextResolver
   *   The blueprint context resolver.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer $layoutDeserializer
   *   The layout deserializer.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to gate surgical field writes on edit access.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\OpaqueDataMerger $opaqueDataMerger
   *   The opaque data merger, used to patch managed configuration keys.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   The block plugin manager, used to create plugin block components.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher, used for the element lifecycle events.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $contextRepository
   *   The context repository, used to default plugin block context mappings.
   * @param \Drupal\Core\Plugin\Context\ContextHandlerInterface $contextHandler
   *   The context handler, used to match contexts to plugin block slots.
   * @param \Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry $operationHandlerRegistry
   *   The operation handler registry, the fall-through for batch operation
   *   names not built into applyOperation().
   * @param \Drupal\daedalus_blueprint\Dialect\DialectAliasMap $dialectAliasMap
   *   The dialect alias layer. Owned here only for settlement: alias
   *   recordings made by ops inside a batch persist to tempstore when the
   *   OUTERMOST batch succeeds and are discarded when it rolls back, so
   *   aliases never outlive identity that was never born.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutPluginManager
   *   The layout plugin manager (NULL when layout_discovery is not installed).
   * @param object|null $sampleValueGenerator
   *   The field_sample_value generator (NULL when that module is not
   *   installed; core's generateSampleItems() is the fallback).
   */
  public function __construct(
    protected BlueprintSerializerInterface $serializer,
    protected BlueprintDeserializerInterface $deserializer,
    protected ElementLocator $elementLocator,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected FieldHandlerManager $fieldHandlerManager,
    protected SemanticValidator $semanticValidator,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected PlacementRestrictionsInterface $placementRestrictions,
    protected BlueprintContextResolverInterface $contextResolver,
    protected LayoutDeserializer $layoutDeserializer,
    protected BlueprintAccessChecker $accessChecker,
    protected OpaqueDataMerger $opaqueDataMerger,
    protected BlockManagerInterface $blockManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected ContextRepositoryInterface $contextRepository,
    protected ContextHandlerInterface $contextHandler,
    protected OperationHandlerRegistry $operationHandlerRegistry,
    protected DialectAliasMap $dialectAliasMap,
    protected ?LayoutPluginManagerInterface $layoutPluginManager = NULL,
    protected ?object $sampleValueGenerator = NULL,
  ) {}

  /**
   * The current batch nesting depth.
   *
   * Compound operations (DOM ops, stencils) run nested batches; alias
   * settlement (persist/discard) belongs to the OUTERMOST batch only.
   */
  protected int $batchDepth = 0;

  /**
   * How many batches on the current stack declared themselves dressed.
   *
   * A batch declares `['dressed' => TRUE]` when its elements arrive with
   * their styles (a stencil expansion, a document dialect); the posture
   * rides every lifecycle event the batch dispatches (LifecycleEventBase::
   * dressed()) so birth subscribers stamp nothing under it. Any enclosing
   * dressed batch dresses its nested batches: the count is positive for
   * the whole span of the outermost dressed frame.
   */
  protected int $dressedDepth = 0;

  /**
   * {@inheritdoc}
   */
  public function createEntity(array $blueprint, array $options = []): BlueprintResult {
    return $this->deserializer->deserialize($blueprint, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprint(FieldableEntityInterface $entity, ?string $langcode = NULL): array {
    return $this->serializer->serialize($entity, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprintSummary(FieldableEntityInterface $entity, ?string $langcode = NULL): array {
    return $this->serializer->serializeSummary($entity, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(FieldableEntityInterface $entity, string $uuid, ?string $langcode = NULL): ?array {
    return $this->serializer->serializeElement($entity, $uuid, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function updateElement(FieldableEntityInterface $entity, ?string $uuid, array $properties, array $options = []): BlueprintResult {
    $uuid = $uuid ?: $entity->uuid();
    if ($uuid === $entity->uuid()) {
      return $this->updateElementRootFields($entity, $properties, $options);
    }

    $context = $this->locateElement($entity, $uuid, 'element', 'ELEMENT_NOT_FOUND');
    if ($context instanceof BlueprintResult) {
      return $context;
    }

    if ($context->isLbComponent()) {
      return $this->updateElementLbComponent($entity, $context, $uuid, $properties, $options);
    }

    if ($context->isSection()) {
      return $this->updateElementSection($entity, $context, $uuid, $properties, $options);
    }

    return $this->updateElementParagraph($entity, $context, $uuid, $properties, $options);
  }

  /**
   * Updates a section's properties (uniform at every depth).
   *
   * A section's one editable property is its label, stored in
   * layout_settings.label (core-native, the same slot at every depth).
   * Structure changes through its own operations (move_element,
   * add_section); a section is born with its layout and keeps it for
   * life — arrangement is styling, not a property write.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located section context.
   * @param string $uuid
   *   The section UUID.
   * @param array $properties
   *   Flat map of property names to values.
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function updateElementSection(FieldableEntityInterface $entity, ElementContext $context, string $uuid, array $properties, array $options = []): BlueprintResult {
    $errors = [];
    foreach (array_keys($properties) as $key) {
      if ($key !== 'label') {
        $errors[] = new BlueprintError(
          path: "properties.{$key}",
          code: 'UNKNOWN_PROPERTY',
          message: "Property '$key' does not exist on a section.",
          context: ['available' => ['label']],
          hint: 'Sections take label here. A section is born with its layout (add_section) and keeps it; arrangement is styling, not a section property.',
        );
      }
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    if (!array_key_exists('label', $properties)) {
      return BlueprintResult::success($entity);
    }

    /** @var \Drupal\layout_builder\Section $section */
    $section = $context->target;
    $label = $properties['label'];
    if (is_string($label) && !empty($options['decode_html_entities'])) {
      $label = HtmlEntityDecoder::decode($label);
    }
    $settings = $section->getLayoutSettings();
    if (is_string($label) && $label !== '') {
      $settings['label'] = $label;
    }
    else {
      unset($settings['label']);
    }
    $section->setLayoutSettings($settings);
    $this->bubbleNestedChanges($context);

    return BlueprintResult::success($entity)
      ->withTargetsFrom($uuid, ['label']);
  }

  /**
   * Updates root entity fields from a flat property bag.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to update.
   * @param array $properties
   *   Flat map of property names to values.
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function updateElementRootFields(FieldableEntityInterface $entity, array $properties, array $options = []): BlueprintResult {
    $result = $this->validateAndApplyFields($entity, $properties, 'properties', $options + [
      'rejectedTypes' => static::REJECTED_FIELD_TYPES,
      'is_update' => TRUE,
    ]);

    $failure = $this->fieldResultToFailure($result);
    if ($failure !== NULL) {
      return $failure;
    }

    return BlueprintResult::success($entity, $result['warnings'])
      ->withTargetsFrom($entity->uuid(), array_keys($properties));
  }

  /**
   * Updates an LB inline block from a flat property bag.
   *
   * Sorts properties into fields (on the block_content entity), managed keys
   * (component configuration), and label.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located element context.
   * @param string $uuid
   *   The component UUID.
   * @param array $properties
   *   Flat map of property names to values.
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function updateElementLbComponent(FieldableEntityInterface $entity, ElementContext $context, string $uuid, array $properties, array $options = []): BlueprintResult {
    $component = $context->target;

    $block = $this->requireInlineBlock($component, $context, 'element');
    if ($block instanceof BlueprintResult) {
      return $block;
    }

    // Sort properties into fields, configuration, and label.
    $block_type = $this->daedalusLayoutAdapter->getInlineBlockType($component);
    $sorted = $this->sortElementProperties($entity, $block_type, $block, $properties);
    if (!empty($sorted['errors'])) {
      return BlueprintResult::structuralFailure($sorted['errors']);
    }

    // Apply fields on the block_content entity.
    $result = $this->validateAndApplyFields($block, $sorted['fields'], 'properties', $options + [
      'is_update' => TRUE,
      'element_uuid' => $uuid,
    ]);

    $failure = $this->fieldResultToFailure($result);
    if ($failure !== NULL) {
      return $failure;
    }

    // `label` is the component display label only — it no longer writes the
    // block_content `info` field (full decouple; see RF2 decisions). `info`, if
    // sent as a property, was gated and applied by validateAndApplyFields
    // above.
    $label = $sorted['label'] ?? NULL;
    // The label is plain text (for heading-style blocks, the rendered text),
    // so it gets the same LLM entity-escape defense as plain-text fields.
    if (!empty($options['decode_html_entities']) && is_string($label)) {
      $label = HtmlEntityDecoder::decode($label);
    }

    // Serialize block back into the component's configuration.
    $config = $component->get('configuration');
    $config['block_serialized'] = serialize($block);
    unset($config['block_revision_id']);

    if (isset($label)) {
      $config['label'] = $label;
    }

    // Patch managed configuration keys (explicit empty clears).
    if (!empty($sorted['configuration'])) {
      $config = $this->opaqueDataMerger->patch($config, $sorted['configuration']);
    }

    $component->set('configuration', $config);
    $this->bubbleNestedChanges($context);

    // Build targets from all property keys that were applied.
    $applied_field_names = array_keys($sorted['fields']);
    if (isset($label)) {
      $applied_field_names[] = 'label';
    }
    $applied_field_names = array_merge($applied_field_names, array_keys($sorted['configuration'] ?? []));

    return BlueprintResult::success($entity, $result['warnings'])
      ->withTargetsFrom($uuid, $applied_field_names);
  }

  /**
   * Updates a paragraph entity from a flat property bag.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located paragraph context.
   * @param string $uuid
   *   The paragraph UUID.
   * @param array $properties
   *   Flat map of property names to values (all are fields on paragraphs).
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function updateElementParagraph(FieldableEntityInterface $entity, ElementContext $context, string $uuid, array $properties, array $options = []): BlueprintResult {
    $paragraph = $context->target;
    $result = $this->validateAndApplyFields($paragraph, $properties, 'properties', $options + [
      'is_update' => TRUE,
    ]);

    $failure = $this->fieldResultToFailure($result);
    if ($failure !== NULL) {
      return $failure;
    }

    return BlueprintResult::success($entity, $result['warnings'])
      ->withTargetsFrom($uuid, array_keys($properties));
  }

  /**
   * Sorts a flat property bag into fields, configuration, and label.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity (for managed keys lookup).
   * @param string $block_type
   *   The block content type machine name.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block_content entity.
   * @param array $properties
   *   The flat property bag from the caller.
   *
   * @return array
   *   Keys: 'fields', 'configuration', 'label', 'errors'.
   */
  protected function sortElementProperties(FieldableEntityInterface $entity, string $block_type, FieldableEntityInterface $block, array $properties): array {
    $managed_keys = $this->managedKeysRegistry->getManagedKeys(
      $entity->getEntityTypeId(),
      $entity->bundle(),
    );
    $inline_managed = $managed_keys['inline_block_configuration'][$block_type] ?? [];
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($block);

    $fields = [];
    $configuration = [];
    $label = NULL;
    $errors = [];

    foreach ($properties as $key => $value) {
      if ($key === 'label') {
        $label = $value;
      }
      elseif (isset($processable_fields[$key])) {
        $fields[$key] = $value;
      }
      elseif (isset($inline_managed[$key])) {
        $configuration[$key] = $value;
      }
      elseif ($key === 'layout_builder__layout') {
        // The layout field is deliberately not a processable property, so a
        // generic UNKNOWN_PROPERTY would mislead: blocks hold no nested
        // layouts under the one container model.
        $errors[] = new BlueprintError(
          path: "properties.{$key}",
          code: 'NO_LAYOUT',
          message: 'Blocks do not hold nested layouts.',
          hint: "Sections nest inside sections: use add_section with placement 'inside' anchored on a section, and address existing sections and elements directly by uuid.",
        );
      }
      else {
        $available = array_merge(
          ['label'],
          array_keys($processable_fields),
          array_keys($inline_managed),
        );
        $errors[] = new BlueprintError(
          path: "properties.{$key}",
          code: 'UNKNOWN_PROPERTY',
          message: "Property '$key' does not exist on this element.",
          context: ['available' => $available],
          hint: 'Check available properties via read_schema or read_element.',
        );
      }
    }

    return [
      'fields' => $fields,
      'configuration' => $configuration,
      'label' => $label,
      'errors' => $errors,
    ];
  }

  /**
   * Rejects unusable writer-minted identity before it becomes identity.
   *
   * A caller-supplied uuid is stored verbatim as the new element's identity
   * and referenced literally by sibling operations, style map keys, and
   * selectable keys. Two things have to hold for that to work, and both are
   * answered here:
   *
   * - The value matches the strict RFC 4122 hex grammar every addressing
   *   surface requires; otherwise the element renders but nothing can
   *   select or style it.
   * - The value is not already identity on this entity. Downstream key
   *   semantics make a collision destructive rather than loud: a component
   *   uuid equal to a sibling's replaces it in the section, and one across
   *   sections leaves two live elements sharing an identity that locates,
   *   styles, and undoes ambiguously.
   *
   * The check runs against the live entity, so inside a batch each
   * operation sees the elements its predecessors inserted: a uuid repeated
   * across two operations of one batch collides on the second.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity the minted identity will live on.
   * @param array<string, mixed> $uuids
   *   Submitted values keyed by their JSON error path. NULL values (the
   *   parameter was omitted, identity will be generated) are skipped.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult|null
   *   A structural failure naming every unusable value, or NULL when all
   *   supplied values are valid and free.
   */
  protected function validateMintedUuids(FieldableEntityInterface $entity, array $uuids): ?BlueprintResult {
    $errors = [];
    foreach ($uuids as $path => $value) {
      if ($value === NULL) {
        continue;
      }
      if (!is_string($value) || !Uuid::isValid($value)) {
        $errors[] = BlueprintError::invalidUuid($path, $value);
        continue;
      }
      if ($this->elementLocator->locate($entity, $value) !== NULL) {
        $errors[] = BlueprintError::uuidInUse($path, $value);
      }
    }
    return $errors === [] ? NULL : BlueprintResult::structuralFailure($errors);
  }

  /**
   * Seeds a fresh override field with the display's default sections.
   *
   * Until the first write lands, an overridable entity's layout field is
   * empty while the page still renders the display's sections — and the
   * serializer exposes those with their real daedalus_layout uuids. Materializing
   * them makes the entity's identity tree match what the writer was shown,
   * so anchors can name a default-seeded section and minted identity is
   * checked against it. Idempotent, and a no-op without Layout Builder.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to seed.
   */
  protected function materializeDefaultSections(FieldableEntityInterface $entity): void {
    if (!$entity->hasField('layout_builder__layout') || !$entity->get('layout_builder__layout')->isEmpty()) {
      return;
    }
    $sections = $this->daedalusLayoutAdapter->getDefaultSections($entity);
    if (!empty($sections)) {
      $entity->set('layout_builder__layout', $sections);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addElement(FieldableEntityInterface $entity, array $location, array $data, array $options = []): BlueprintResult {
    // The gate answers "is this uuid free" against the identity tree the
    // operation is about to write into, so a fresh override materializes
    // its default sections first: their uuids are already published by the
    // serializer and are exactly the ones a writer is tempted to copy.
    if (isset($location['anchor'])) {
      $this->materializeDefaultSections($entity);
    }
    if ($failure = $this->validateMintedUuids($entity, [
      'data.uuid' => $data['uuid'] ?? NULL,
    ])) {
      return $failure;
    }
    // ONE public placement grammar: {anchor, placement} resolves into the
    // internal positional location ('_section' for the LB flow, field +
    // position for the paragraph flow). The paragraph family's own
    // {field, position} spelling addresses reference fields directly.
    if (isset($location['anchor'])) {
      $resolved = $this->resolvePlacement($entity, $location);
      if ($resolved instanceof BlueprintResult) {
        return $resolved;
      }
      return isset($resolved['_section'])
        ? $this->addLbComponent($entity, $resolved, $data, $options)
        : $this->addParagraphElement($entity, $resolved, $data, $options);
    }
    return $this->addParagraphElement($entity, $location, $data, $options);
  }

  /**
   * Resolves an anchor-relative placement into a positional location.
   *
   * The placement grammar addresses an insertion point relative to existing
   * content: `{anchor: <uuid>, placement: before|after|inside}`. One mixed
   * flow per region: {anchor, placement} names a position in the flow the
   * anchor lives in, whatever the anchor's kind. Before/after place beside
   * any LB anchor (a block, or a nested section via its container
   * component) or a paragraph sibling; 'inside' names the end of a section
   * anchor's own region flow (optional region), a paragraph, or the root
   * entity (both require field naming the reference field to append into).
   * Legality is a flow-capability question after resolution: the root
   * section list holds only sections, so a block beside a ROOT section is
   * refused with the wrap composition (add_section, then place inside).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param array $location
   *   Location with anchor, placement, and optionally region or field.
   * @param object|null $storage
   *   An already-resolved section storage to resolve within. Required when
   *   the caller has located other elements it will mutate alongside the
   *   resolved destination (see ElementLocator::locate()).
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintResult
   *   A positional location array consumable by addLbComponent (keys
   *   _section, _storage, region, weight) or addParagraphElement (keys
   *   _parent_entity, field, position), or a failure result.
   */
  protected function resolvePlacement(FieldableEntityInterface $entity, array $location, ?object $storage = NULL): array|BlueprintResult {
    $this->materializeDefaultSections($entity);

    $anchor = $location['anchor'];
    $placement = $location['placement'] ?? NULL;
    if (!in_array($placement, ['before', 'after', 'inside'], TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.placement',
          code: 'INVALID_PLACEMENT',
          message: "Placement must be 'before', 'after', or 'inside'.",
          context: ['valid_placements' => ['before', 'after', 'inside']],
          hint: 'Specify how the new element relates to the anchor.',
        ),
      ]);
    }

    // "Inside" the root entity targets one of its reference fields.
    if ($anchor === $entity->uuid()) {
      if ($placement !== 'inside') {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'location.anchor',
            code: 'INVALID_PLACEMENT_ANCHOR',
            message: "The root entity only supports 'inside' placement.",
            hint: 'Anchor an element for before/after placement beside it.',
          ),
        ]);
      }
      return $this->requireField($location, ['field' => $location['field'] ?? NULL]);
    }

    $context = $this->locateElement($entity, $anchor, 'location.anchor', 'ANCHOR_NOT_FOUND', $storage);
    if ($context instanceof BlueprintResult) {
      return $context;
    }

    if ($context->isParagraph()) {
      return $this->resolveParagraphPlacement($context, $placement, $location);
    }

    if ($placement === 'inside') {
      if (!$context->isSection()) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'location.placement',
            code: 'INVALID_PLACEMENT_ANCHOR',
            message: "A block has no interior flow, so 'inside' cannot anchor on it.",
            hint: "Use before/after to place beside this block, or anchor a section with 'inside' (a region name is only needed on multi-region layouts).",
          ),
        ]);
      }
      /** @var \Drupal\layout_builder\Section $section */
      $section = $context->target;
      $region = $location['region'] ?? NULL;
      if ($region !== NULL) {
        $error = $this->validateRegionExists($section, $region);
        if ($error !== NULL) {
          return $error;
        }
      }
      else {
        $region = $this->getDefaultRegion($section);
      }

      // No weight: addLbComponent appends at the end of the region.
      return [
        '_section' => $section,
        '_storage' => $storage ?? $context->sectionStorage,
        '_parent_entity' => $context->parentEntity,
        'region' => $region,
      ];
    }

    // Before/after name the position beside the anchor in the region flow
    // it lives in — anchor kind is irrelevant to addressing.
    $flow = $this->resolveAnchorFlow($context, $anchor, $storage);
    if ($flow === NULL) {
      // A root section lives in the root section list, and that flow holds
      // only sections. The gesture is a composition instead.
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.placement',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: 'The root section list holds only sections, so a block cannot be placed beside a root section.',
          hint: "Wrap it: add_section before/after this anchor, then place the block 'inside' the new section.",
        ),
      ]);
    }

    return [
      '_section' => $flow['section'],
      '_storage' => $storage ?? $context->sectionStorage,
      '_parent_entity' => $context->parentEntity,
      'region' => $flow['region'],
      'weight' => $this->siblingWeight($flow['section'], $flow['region'], $anchor, $placement),
    ];
  }

  /**
   * Resolves the region flow a Layout Builder anchor lives in.
   *
   * Any anchor names a position in the flow it lives in: a block's flow is
   * its region; a nested section's flow is its parent section's region (it
   * sits there as a regular weighted container component). A root section
   * lives in the root section list — not a region flow — so NULL is
   * returned and the caller applies that flow's capability (sections only).
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located LB anchor context (component or section).
   * @param string $anchor
   *   The anchor UUID.
   * @param object|null $storage
   *   The shared section storage wrapper for this operation, if any.
   *
   * @return array{section: \Drupal\layout_builder\Section, region: string}|null
   *   The flow's holding section and region, or NULL for the root flow.
   */
  protected function resolveAnchorFlow(ElementContext $context, string $anchor, ?object $storage): ?array {
    if ($context->isLbComponent()) {
      /** @var \Drupal\layout_builder\SectionComponent $component */
      $component = $context->target;
      return [
        'section' => $context->section,
        'region' => $component->getRegion(),
      ];
    }

    $container = $this->daedalusLayoutAdapter->locateContainer($storage ?? $context->sectionStorage, $anchor);
    if ($container === NULL) {
      return NULL;
    }
    return [
      'section' => $container['parentSection'],
      'region' => $container['component']->getRegion(),
    ];
  }

  /**
   * Computes the insertion weight beside an anchor in a region flow.
   *
   * Region-relative index of the anchor among ALL components (blocks and
   * containers alike — one mixed flow); insertComponent() shifts weights.
   * Compute AFTER any removal the operation performs: the index reflects
   * the flow as it stands at call time.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section holding the flow.
   * @param string $region
   *   The region name.
   * @param string $anchor
   *   The anchor UUID expected in the flow.
   * @param string $placement
   *   Either 'before' or 'after'.
   *
   * @return int
   *   The insertion weight (end of flow when the anchor is absent).
   */
  protected function siblingWeight(Section $section, string $region, string $anchor, string $placement): int {
    $uuids = array_keys($section->getComponentsByRegion($region));
    $index = array_search($anchor, $uuids, TRUE);
    return $index === FALSE
      ? count($uuids)
      : ($placement === 'after' ? $index + 1 : $index);
  }

  /**
   * Resolves placement anchored on a paragraph (inline entity).
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located paragraph context.
   * @param string $placement
   *   The placement keyword.
   * @param array $location
   *   The original location (for the field required by inside placement).
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The resolved location or a failure result.
   */
  protected function resolveParagraphPlacement(ElementContext $context, string $placement, array $location): array|BlueprintResult {
    if ($placement === 'inside') {
      // The anchor paragraph becomes the parent; a field name is required.
      return $this->requireField($location, [
        '_parent_entity' => $context->target,
        'field' => $location['field'] ?? NULL,
      ]);
    }

    return [
      '_parent_entity' => $context->parentEntity,
      'field' => $context->fieldName,
      'position' => $placement === 'after' ? $context->delta + 1 : $context->delta,
    ];
  }

  /**
   * Guards that an inside placement names its target field.
   *
   * @param array $location
   *   The original location.
   * @param array $resolved
   *   The resolved location, with 'field' possibly NULL.
   *
   * @return array|\Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The resolved location, or a failure when the field is missing.
   */
  protected function requireField(array $location, array $resolved): array|BlueprintResult {
    if (empty($location['field'])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.field',
          code: 'MISSING_FIELD',
          message: "Placement 'inside' an entity requires the target field name.",
          hint: 'Set location.field to the reference field to place into.',
        ),
      ]);
    }
    return $resolved;
  }

  /**
   * Returns the default region for a section's layout plugin.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   *
   * @return string
   *   The layout's default region, or 'content' when it cannot be resolved.
   */
  protected function getDefaultRegion(Section $section): string {
    if ($this->layoutPluginManager !== NULL) {
      $definition = $this->layoutPluginManager->getDefinition($section->getLayoutId(), FALSE);
      if ($definition !== NULL) {
        return $definition->getDefaultRegion() ?: 'content';
      }
    }
    return 'content';
  }

  /**
   * Validates that a region exists on a section's layout.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   * @param string $region
   *   The region name to check.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult|null
   *   A failure result for an unknown region, or NULL when valid (or when
   *   layout definitions are unavailable to check against).
   */
  protected function validateRegionExists(Section $section, string $region): ?BlueprintResult {
    if ($this->layoutPluginManager === NULL) {
      return NULL;
    }
    $definition = $this->layoutPluginManager->getDefinition($section->getLayoutId(), FALSE);
    if ($definition === NULL) {
      return NULL;
    }
    $valid_regions = $definition->getRegionNames();
    if (!in_array($region, $valid_regions, TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.region',
          code: 'INVALID_REGION',
          message: "Region '$region' does not exist on layout '{$section->getLayoutId()}'.",
          context: ['available' => $valid_regions],
          hint: 'Use one of the valid regions for this layout.',
        ),
      ]);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function addSection(FieldableEntityInterface $entity, array $location, ?string $uuid = NULL, ?string $layout = NULL, array $layout_settings = [], ?string $stencil = NULL): BlueprintResult {
    if ($failure = $this->validateMintedUuids($entity, ['uuid' => $uuid])) {
      return $failure;
    }
    if ($stencil === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'stencil',
          code: 'INVALID_STENCIL',
          message: 'stencil is a non-empty stencil ID.',
          hint: 'Pass the source stencil machine name, or omit the key for a section that is not a stencil placement.',
        ),
      ]);
    }
    if (!$entity->hasField('layout_builder__layout')) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location',
          code: 'NO_LAYOUT',
          message: 'Entity does not have a Layout Builder layout.',
          hint: 'Enable Layout Builder on this entity type.',
        ),
      ]);
    }

    $anchor = $location['anchor'] ?? NULL;
    $placement = $location['placement'] ?? NULL;
    if (!in_array($placement, ['before', 'after', 'inside'], TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.placement',
          code: 'INVALID_PLACEMENT',
          message: "Placement must be 'before', 'after', or 'inside'.",
          context: ['valid_placements' => ['before', 'after', 'inside']],
          hint: 'before/after place beside the anchor element in its flow (any depth, block or section anchors alike); inside anchors a section (to nest into it) or the root entity.',
        ),
      ]);
    }
    if (empty($anchor)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'MISSING_ANCHOR',
          message: 'Adding a section requires an anchor element.',
          hint: 'Anchor a sibling element (section or block), a section to nest inside, or the root entity UUID.',
        ),
      ]);
    }

    // One storage wrapper for every locate in this operation (see
    // moveElement). A section is born with its real layout: an explicit
    // layout when named, otherwise the spine's default ladder (a container
    // layout from the allowed set when one exists, then the configured
    // default_section, then the first allowed layout).
    $storage = $this->daedalusLayoutAdapter->getSectionStorage($entity);
    if ($layout !== NULL) {
      $section = $this->createExplicitSection($layout, $layout_settings, $uuid);
      if ($section instanceof BlueprintResult) {
        return $section;
      }
    }
    else {
      if ($layout_settings !== []) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'layout_settings',
            code: 'INVALID_LAYOUT_SETTINGS',
            message: 'layout_settings requires an explicit layout.',
            hint: 'Name the layout the settings belong to, or omit layout_settings for a default container section.',
          ),
        ]);
      }
      $section = $this->daedalusLayoutAdapter->createDefaultSection($entity, $uuid, $storage);
      if ($section === NULL) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'location',
            code: 'NO_ALLOWED_LAYOUTS',
            message: 'No layout is allowed for this entity display, so no section can be created.',
            hint: "Relax the display's layout restrictions or configure an allowed default section.",
          ),
        ]);
      }
    }

    // The ladder can only mint an allowed layout, but a configured
    // default_section is trusted when no restrictions answer exists — keep
    // the explicit check as the invariant (the same allowed-layouts answer
    // governs every depth).
    if ($failure = $this->checkLayoutAllowed($section->getLayoutId(), $storage, $layout !== NULL ? 'layout' : 'location')) {
      return $failure;
    }

    // A placement's provenance is a birth fact: the root create op carries
    // the source stencil ID, exactly as uuid carries identity, so the stamp
    // exists the moment the section does — style operations trailing the
    // structural expansion (a stencil's own owned-variant applies) already
    // see it. Block roots do the same through add_element's additional
    // data; this is the section half of that one grammar.
    if ($stencil !== NULL) {
      $section->setThirdPartySetting(LayoutSerializer::STENCIL_PROVENANCE_KEY, 'stencil', $stencil);
    }

    if ($anchor === $entity->uuid()) {
      if ($placement !== 'inside') {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'location.anchor',
            code: 'INVALID_PLACEMENT_ANCHOR',
            message: "The root entity only supports 'inside' placement.",
            hint: 'Anchor a sibling section for before/after placement.',
          ),
        ]);
      }
      // Root sections live on the entity's field items, shared with any
      // storage wrapper — append directly.
      $entity->get('layout_builder__layout')->appendSection($section);
      if ($failure = $this->validateSectionListOwner($entity)) {
        return $failure;
      }
      return $this->addSectionResult($entity, $section);
    }

    $context = $this->locateElement($entity, $anchor, 'location.anchor', 'ANCHOR_NOT_FOUND', $storage);
    if ($context instanceof BlueprintResult) {
      return $context;
    }

    // ONE placement grammar over one mixed flow per region: before/after
    // name the position beside the anchor in the flow it lives in (block
    // and section anchors alike); 'inside' names the end of a section
    // anchor's own region flow. Kind only matters through what a flow can
    // hold, and every region flow holds sections (as container components).
    if ($context->isParagraph()) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: 'Sections live in the layout flow; a paragraph cannot anchor one.',
          hint: 'Anchor a section, a layout block, or the root entity.',
        ),
      ]);
    }
    if ($placement === 'inside' && !$context->isSection()) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.placement',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: "A block has no interior flow, so 'inside' cannot anchor on it.",
          hint: "Use before/after to place the section beside this block, or anchor a section with 'inside' to nest into it.",
        ),
      ]);
    }

    if ($placement === 'inside') {
      // Nest: wrap the new section in a container component placed in the
      // anchor section's region. Sections nest inside sections at any depth.
      if (!$this->daedalusLayoutAdapter->isDaedalusLayoutAvailable()) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'location.placement',
            code: 'NESTING_UNAVAILABLE',
            message: 'Nested sections require the daedalus_layout module.',
            hint: 'Place the section at the top level, or install daedalus_layout.',
          ),
        ]);
      }
      /** @var \Drupal\layout_builder\Section $anchor_section */
      $anchor_section = $context->target;
      $region = $location['region'] ?? NULL;
      if ($region !== NULL) {
        $error = $this->validateRegionExists($anchor_section, $region);
        if ($error !== NULL) {
          return $error;
        }
      }
      else {
        $region = $this->getDefaultRegion($anchor_section);
      }
      $component = $this->daedalusLayoutAdapter->createSectionComponent($section, $region);
      $anchor_section->appendComponent($component);
    }
    else {
      $flow = $this->resolveAnchorFlow($context, $anchor, $storage);
      if ($flow !== NULL) {
        // Region-flow anchor (a block, or a nested section's container):
        // the sibling is a new container component beside it in that flow.
        if (!$this->daedalusLayoutAdapter->isDaedalusLayoutAvailable()) {
          return BlueprintResult::structuralFailure([
            new BlueprintError(
              path: 'location.placement',
              code: 'NESTING_UNAVAILABLE',
              message: 'Sections inside region flows (container components) require the daedalus_layout module.',
              hint: 'Anchor the root entity for a top-level section, or install daedalus_layout.',
            ),
          ]);
        }
        $weight = $this->siblingWeight($flow['section'], $flow['region'], $anchor, $placement);
        $component = $this->daedalusLayoutAdapter->createSectionComponent($section, $flow['region'], $weight);
        $flow['section']->insertComponent($weight, $component);
      }
      else {
        // Root-flow anchor: the sibling is a new layout field row.
        $list = $this->getContainingSectionList($entity, $anchor, $storage);
        $delta = $list !== NULL ? $this->findSectionDelta($list, $anchor) : NULL;
        if ($delta === NULL) {
          return BlueprintResult::structuralFailure([
            new BlueprintError(
              path: 'location.anchor',
              code: 'SECTION_NOT_FOUND',
              message: "Could not resolve the containing list for section '$anchor'.",
              hint: 'Verify the section UUID exists in the entity structure.',
            ),
          ]);
        }
        $list->insertSection($placement === 'after' ? $delta + 1 : $delta, $section);
      }
    }

    if ($failure = $this->validateSectionListOwner($entity)) {
      return $failure;
    }
    $this->bubbleStorageChanges($storage);
    return $this->addSectionResult($entity, $section);
  }

  /**
   * Dispatches the created event and builds the result for an added section.
   *
   * Every successful addSection() branch funnels through here after
   * insertion, owner validation, and bubbling, so the section-created event
   * fires exactly once per add with the entity in its final post-add state.
   *
   * A section's position only exists in its parent container's markup, so
   * the whole entity re-renders (root RESTRUCTURED, matching section moves);
   * the ADDED entry carries the new section's identity for history and
   * optimistic-UI reconciliation.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param \Drupal\layout_builder\Section $section
   *   The inserted section.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The success result.
   */
  protected function addSectionResult(FieldableEntityInterface $entity, Section $section): BlueprintResult {
    $section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($section);
    $event_targets = $this->dispatchLifecycle(new SectionCreatedEvent($entity, $section, $section_uuid, $this->isDressed()));
    $targets = [];
    if ($section_uuid !== NULL) {
      $targets[] = ['uuid' => $section_uuid, 'field_name' => TargetType::ADDED];
    }
    $targets[] = ['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED];
    return BlueprintResult::success($entity)->withTargets(array_merge($targets, $event_targets));
  }

  /**
   * Dispatches a lifecycle event and returns the targets it collected.
   *
   * Subscribers that write fields on the entity during the event declare
   * them on the event (see LifecycleEventBase); every dispatch site merges
   * the returned targets into its result after its own, so the mutation
   * response re-renders what subscribers changed as well as what the
   * operation changed.
   *
   * @param \Drupal\daedalus_blueprint\Event\LifecycleEventBase $event
   *   The event to dispatch.
   *
   * @return array<int, array{uuid: string, field_name: string}>
   *   The targets subscribers added to the event.
   */
  private function dispatchLifecycle(LifecycleEventBase $event): array {
    $this->eventDispatcher->dispatch($event);
    return $event->getTargets();
  }

  /**
   * Whether the running batch (or any batch enclosing it) is dressed.
   *
   * An operation outside any batch is undressed: it is the op door, and
   * birth defaults are its business.
   */
  protected function isDressed(): bool {
    return $this->dressedDepth > 0;
  }

  /**
   * Builds a section around an explicitly named layout.
   *
   * The birth-layout door for everything the default container ladder does
   * not cover: genuinely named-slot layouts and pinned layout settings
   * (a stencil's column_widths). The section is born as its layout —
   * there is no later layout change.
   *
   * @param string $layout_id
   *   The layout plugin ID.
   * @param array $layout_settings
   *   Layout plugin settings patched over the plugin defaults (the
   *   OpaqueDataMerger PATCH contract: an explicit empty value clears the
   *   key).
   * @param string|null $uuid
   *   A client-provided daedalus_layout section UUID to honor, or NULL.
   *
   * @return \Drupal\layout_builder\Section|\Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The new, uninserted section, or a structural failure.
   */
  protected function createExplicitSection(string $layout_id, array $layout_settings, ?string $uuid): Section|BlueprintResult {
    if ($layout_settings !== [] && array_is_list($layout_settings)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'layout_settings',
          code: 'INVALID_LAYOUT_SETTINGS',
          message: 'layout_settings is a map of layout plugin settings.',
          hint: 'Provide settings the layout plugin understands (e.g. column_widths for two-column layouts); omitted keys use the plugin defaults.',
        ),
      ]);
    }
    if ($this->layoutPluginManager === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'layout',
          code: 'NO_LAYOUT',
          message: 'Layout plugins are not available.',
          hint: 'Enable Layout Builder on this site.',
        ),
      ]);
    }
    $definition = $this->layoutPluginManager->getDefinition($layout_id, FALSE);
    if ($layout_id === '' || $definition === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'layout',
          code: 'INVALID_LAYOUT',
          message: "Unknown layout '$layout_id'.",
          context: ['available' => AvailableLayouts::summarize($this->layoutPluginManager)],
          hint: 'Use a layout plugin ID from available_layouts, or omit layout for a container section.',
        ),
      ]);
    }

    $layout_plugin = $this->layoutPluginManager->createInstance($layout_id);
    $configuration = $this->opaqueDataMerger->patch($layout_plugin->getConfiguration(), $layout_settings);
    $section = new Section($layout_id, $configuration);
    $this->daedalusLayoutAdapter->setSectionUuid($section, $uuid ?? (new PhpUuid())->generate());
    return $section;
  }

  /**
   * Validates the layout field of an entity whose section list was mutated.
   *
   * Section-list operations (add/move/remove) bypass the field-write path,
   * so the layout field's own constraints would never run: a required
   * layout field would silently lose its last section. Running entity
   * validation on the root entity makes those constraints real; only
   * violations on the layout field are reported.
   *
   * Call after mutating a list and before bubbling: on failure the nested
   * mutation stays confined to the storage wrapper, and batch semantics
   * restore the root entity as with any semantic failure.
   *
   * Sections at every depth are values on the root entity, so the owner is
   * always the root entity; nested-section cardinality (one section per
   * container) is enforced structurally by the container itself.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $owner
   *   The root entity whose layout field was mutated.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult|null
   *   A semantic failure carrying the violations, or NULL when valid.
   */
  protected function validateSectionListOwner(FieldableEntityInterface $owner): ?BlueprintResult {
    $errors = $this->semanticValidator->validateConstraints($owner, ['layout_builder__layout'], []);
    return $errors ? BlueprintResult::semanticFailure($errors) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function removeElement(FieldableEntityInterface $entity, string $uuid): BlueprintResult {
    $context = $this->locateElement($entity, $uuid, 'element', 'ELEMENT_NOT_FOUND');
    if ($context instanceof BlueprintResult) {
      return $context;
    }

    if ($context->isLbComponent()) {
      $context->section->removeComponent($uuid);
      $this->bubbleNestedChanges($context);
      return BlueprintResult::success($entity)
        ->withTargets([['uuid' => $uuid, 'field_name' => TargetType::REMOVED]]);
    }

    if ($context->isSection()) {
      return $this->removeSection($entity, $uuid, $context);
    }

    // Paragraph: remove from parent's ERR field.
    $field_list = $context->parentEntity->get($context->fieldName);
    $items = [];
    foreach ($field_list as $delta => $item) {
      if ($delta !== $context->delta) {
        $items[] = ['entity' => $item->entity];
      }
    }
    $context->parentEntity->set($context->fieldName, $items);

    return BlueprintResult::success($entity)
      ->withTargets([['uuid' => $uuid, 'field_name' => TargetType::REMOVED]]);
  }

  /**
   * Removes a Layout Builder section by its daedalus_layout UUID.
   *
   * Root-level sections are removed from the entity's layout field; nested
   * sections from the containing list resolved through the nested-aware
   * section storage, with changes bubbled back to the root entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $uuid
   *   The section UUID.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located section context.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function removeSection(FieldableEntityInterface $entity, string $uuid, ElementContext $context): BlueprintResult {
    $storage = $context->sectionStorage;
    if ($storage !== NULL && method_exists($storage, 'getSectionsFor')) {
      $list = $storage->getSectionsFor($uuid);
    }
    else {
      $list = $context->parentEntity->get('layout_builder__layout');
    }

    if ($list !== NULL) {
      foreach ($list->getSections() as $delta => $section) {
        if ($this->daedalusLayoutAdapter->getSectionUuid($section) === $uuid) {
          $list->removeSection($delta);
          if ($failure = $this->validateSectionListOwner($entity)) {
            return $failure;
          }
          $this->bubbleNestedChanges($context);
          return BlueprintResult::success($entity)
            ->withTargets([['uuid' => $uuid, 'field_name' => TargetType::REMOVED]]);
        }
      }
    }

    return BlueprintResult::structuralFailure([
      new BlueprintError(
        path: 'element',
        code: 'SECTION_NOT_FOUND',
        message: "Could not resolve the containing list for section '$uuid'.",
        hint: 'Verify the section UUID exists in the entity structure.',
      ),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function moveElement(FieldableEntityInterface $entity, string $uuid, array $location): BlueprintResult {
    $anchor = $location['anchor'] ?? NULL;
    if (empty($anchor)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'MISSING_ANCHOR',
          message: 'Moves require an anchor element.',
          hint: 'Set location.anchor to the UUID the element moves relative to.',
        ),
      ]);
    }
    if ($anchor === $uuid) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: 'An element cannot anchor its own move.',
          hint: 'Anchor a sibling or container element instead.',
        ),
      ]);
    }
    // One storage wrapper for every locate in this operation: separate
    // wrappers do not share nested Section/entity instances, so mutations
    // spread across them lose each other on bubbling.
    $storage = $this->daedalusLayoutAdapter->getSectionStorage($entity);

    $context = $this->locateElement($entity, $uuid, 'element', 'ELEMENT_NOT_FOUND', $storage);
    if ($context instanceof BlueprintResult) {
      return $context;
    }
    if ($context->isParagraph()) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'element',
          code: 'NOT_MOVABLE',
          message: 'Paragraphs cannot be moved with move_element.',
          hint: 'Use reorder_field to reorder paragraphs within a field, or remove and re-add to move across fields.',
        ),
      ]);
    }

    // The root entity anchors a section's move to the end of the top-level
    // flow, the same location add_section appends to. The entity is not a
    // layout element: locateElement cannot answer for its uuid, and the
    // moved section's own descendants can never be this anchor, so the
    // branch decides here, before the anchor locate and the containment
    // check. The root flow holds only sections; a block wraps (clients
    // compose the wrap themselves, the hint says how).
    if ($anchor === $entity->uuid()) {
      if (!$context->isSection()) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'location.anchor',
            code: 'INVALID_PLACEMENT_ANCHOR',
            message: 'The root flow holds only sections, so a block cannot move relative to the root entity.',
            hint: 'Wrap it: add_section inside the root entity, then move the block inside the new section.',
          ),
        ]);
      }
      return $this->moveSectionElement($entity, $uuid, $location, NULL, $storage);
    }

    $anchor_context = $this->locateElement($entity, $anchor, 'location.anchor', 'ANCHOR_NOT_FOUND', $storage);
    if ($anchor_context instanceof BlueprintResult) {
      return $anchor_context;
    }
    if ($this->anchorInsideElement($storage, $uuid, $anchor, $context)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: 'The anchor is nested inside the element being moved.',
          hint: 'An element cannot be placed relative to its own nested content.',
        ),
      ]);
    }

    if ($context->isSection()) {
      return $this->moveSectionElement($entity, $uuid, $location, $anchor_context, $storage);
    }
    return $this->moveLbComponent($entity, $uuid, $location, $context, $storage);
  }

  /**
   * Moves a Layout Builder component to an anchor-relative destination.
   *
   * Moves are pure value moves at any depth: components keep their
   * configuration (including context mappings, which resolve to the root
   * entity everywhere) and only their position changes. The move is a
   * lifecycle fact: ElementMovedEvent fires once the component is in place,
   * carrying its depth before and after (its source and destination
   * sections' depth), and the targets subscribers declare merge into the
   * result after the operation's own.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $uuid
   *   The component UUID being moved.
   * @param array $location
   *   The anchor-relative location.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located element context.
   * @param object|null $storage
   *   The shared section storage wrapper for this operation.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function moveLbComponent(FieldableEntityInterface $entity, string $uuid, array $location, ElementContext $context, ?object $storage): BlueprintResult {
    $resolved = $this->resolvePlacement($entity, $location, $storage);
    if ($resolved instanceof BlueprintResult) {
      return $resolved;
    }
    if (!isset($resolved['_section'])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: 'Blocks move within layout region flows.',
          hint: "Anchor a layout element: before/after place beside it in its flow; 'inside' a section appends to its region.",
        ),
      ]);
    }

    /** @var \Drupal\layout_builder\SectionComponent $component */
    $component = $context->target;
    $source_region = $component->getRegion();
    $source_section = $context->section;
    $source_section_uuid = $source_section instanceof Section
      ? $this->daedalusLayoutAdapter->getSectionUuid($source_section)
      : NULL;

    /** @var \Drupal\layout_builder\Section $dest_section */
    $dest_section = $resolved['_section'];
    $region = $resolved['region'];
    $dest_section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($dest_section);

    // Restriction enforcement at the spine: the destination must allow this
    // block, per-region precise, BEFORE anything mutates.
    if ($failure = $this->checkBlockPlacement($component->getPluginId(), $storage ?? $context->sectionStorage, $dest_section, $region, 'location')) {
      return $failure;
    }

    // A component's depth is its section's depth, read BEFORE mutating so
    // both facts come off the same tree. A section without an daedalus_layout UUID
    // cannot be nested (nesting needs daedalus_layout), so it reads as root.
    $was_nested = $source_section_uuid !== NULL && $this->elementLocator->isNestedSection($entity, $source_section_uuid);
    $is_nested = $dest_section_uuid !== NULL && $this->elementLocator->isNestedSection($entity, $dest_section_uuid);

    // The resolved weight indexes the destination region as it stands with
    // the element still in place. Removing the element first shifts later
    // indices down by one; appends re-count after removal.
    $dest_uuids = array_keys($dest_section->getComponentsByRegion($region));
    $weight = $resolved['weight'] ?? count($dest_uuids);
    $current_index = array_search($uuid, $dest_uuids, TRUE);
    if ($current_index !== FALSE && $current_index < $weight) {
      $weight--;
    }

    $source_section->removeComponent($uuid);

    // No context remap on moves: there is no entity boundary inside the
    // tree, so a component's entity context resolves to the root entity at
    // every depth.
    $component->setRegion($region);
    $dest_section->insertComponent($weight, $component);
    $this->bubbleStorageChanges($storage ?? $context->sectionStorage);

    $event_targets = $this->dispatchLifecycle(new ElementMovedEvent($entity, $uuid, FALSE, $was_nested, $is_nested));

    $cross_section = $source_section_uuid !== NULL
      ? $source_section_uuid !== $dest_section_uuid
      : $source_section !== $dest_section;
    $targets = [['uuid' => $uuid, 'field_name' => TargetType::ADDED]];
    if ($cross_section || $source_region !== $region) {
      // The ADDED target only re-renders the destination region, so any move
      // that left its source region behind needs a source-side target too —
      // the source section when it has a UUID, otherwise the root-entity
      // fallback covers it.
      $targets[] = $source_section_uuid !== NULL
        ? ['uuid' => $source_section_uuid, 'field_name' => TargetType::RESTRUCTURED]
        : ['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED];
    }

    return BlueprintResult::success($entity)->withTargets(array_merge($targets, $event_targets));
  }

  /**
   * Moves a Layout Builder section to an anchor-relative destination.
   *
   * Sections move ANYWHERE as pure value moves: before/after any sibling
   * in a flow (top-level rows, and blocks or containers in a region flow,
   * uniformly), or inside another section's region (nesting). Depth
   * crossings need no conversion: a top-level row wraps into a container
   * component on the way in, a nested section unwraps into a row on the way
   * out, and the section value (identity, components, settings) rides along
   * unchanged. The root entity anchors 'inside' only: the section appends
   * to the end of the top-level list (a root row moves last, a nested
   * section unwraps and lands last), mirroring addSection()'s root branch;
   * before/after on the root are refused, the page has no outside. The
   * move is a lifecycle fact: ElementMovedEvent fires once
   * the section is in place, carrying its depth before (was it wrapped in
   * a container) and after (did it land inside a region flow or in the
   * root section list), and the targets subscribers declare merge into the
   * result after the operation's own.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $uuid
   *   The section UUID being moved.
   * @param array $location
   *   The anchor-relative location.
   * @param \Drupal\daedalus_blueprint\ElementContext|null $anchor_context
   *   The located anchor context, or NULL when the root entity anchors the
   *   move (the anchor is the entity itself, which no locate can answer).
   * @param object|null $storage
   *   The shared section storage wrapper for this operation.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function moveSectionElement(FieldableEntityInterface $entity, string $uuid, array $location, ?ElementContext $anchor_context, ?object $storage): BlueprintResult {
    $placement = $location['placement'] ?? NULL;
    if (!in_array($placement, ['before', 'after', 'inside'], TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.placement',
          code: 'INVALID_PLACEMENT',
          message: "Sections move 'before'/'after' any sibling element in a flow, or 'inside' a section (nesting).",
          context: ['valid_placements' => ['before', 'after', 'inside']],
          hint: 'before/after place beside the anchor at its depth (block or section anchors alike); inside nests into a section anchor.',
        ),
      ]);
    }
    if ($anchor_context === NULL && $placement !== 'inside') {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: "The root entity only supports 'inside' placement.",
          hint: 'Anchor a sibling section for before/after placement.',
        ),
      ]);
    }
    if ($anchor_context?->isParagraph()) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.anchor',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: 'Sections live in the layout flow; a paragraph cannot anchor one.',
          hint: 'Anchor a section, a layout block, or the root entity.',
        ),
      ]);
    }
    if ($anchor_context !== NULL && $placement === 'inside' && !$anchor_context->isSection()) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.placement',
          code: 'INVALID_PLACEMENT_ANCHOR',
          message: "A block has no interior flow, so 'inside' cannot anchor on it.",
          hint: "Use before/after to place the section beside this block, or anchor a section with 'inside' to nest into it.",
        ),
      ]);
    }

    $anchor = $location['anchor'];

    // Resolve everything positional BEFORE mutating: the nested lookups ride
    // a tree index built from the current structure.
    $source_container = $this->daedalusLayoutAdapter->locateContainer($storage, $uuid);
    $source_list = $source_container === NULL
      ? $this->getContainingSectionList($entity, $uuid, $storage)
      : NULL;
    $source_delta = $source_list !== NULL ? $this->findSectionDelta($source_list, $uuid) : NULL;
    if ($source_container === NULL && $source_delta === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'element',
          code: 'SECTION_NOT_FOUND',
          message: "Could not resolve the containing list for section '$uuid'.",
          hint: 'Verify the section UUID exists in the entity structure.',
        ),
      ]);
    }

    $dest_flow = NULL;
    $dest_list = NULL;
    if ($anchor_context !== NULL && $placement !== 'inside') {
      $dest_flow = $this->resolveAnchorFlow($anchor_context, $anchor, $storage);
      if ($dest_flow === NULL) {
        $dest_list = $this->getContainingSectionList($entity, $anchor, $storage);
        if ($dest_list === NULL) {
          return BlueprintResult::structuralFailure([
            new BlueprintError(
              path: 'location.anchor',
              code: 'SECTION_NOT_FOUND',
              message: "Could not resolve the containing list for section '$anchor'.",
              hint: 'Verify the section UUID exists in the entity structure.',
            ),
          ]);
        }
      }
    }

    // Extract the section value from its current position (identity-stable
    // hydration through the storage wrapper, so pending in-request mutations
    // ride along with the move).
    if ($source_container !== NULL) {
      $section = $storage !== NULL && method_exists($storage, 'getSectionByUuid')
        ? $storage->getSectionByUuid($uuid)
        : NULL;
      $section ??= $this->daedalusLayoutAdapter->embeddedSection($source_container['component']);
      $source_container['parentSection']->removeComponent($uuid);
    }
    else {
      $section = $source_list->getSection($source_delta);
      $source_list->removeSection($source_delta);
    }

    // Insert at the destination (deltas/weights computed AFTER removal so
    // same-list moves land correctly).
    if ($anchor_context === NULL) {
      // Root append: root sections live on the entity's field items, shared
      // with any storage wrapper (the same list addSection() appends to).
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $root_list */
      $root_list = $entity->get('layout_builder__layout');
      $root_list->appendSection($section);
    }
    elseif ($placement === 'inside') {
      /** @var \Drupal\layout_builder\Section $anchor_section */
      $anchor_section = $anchor_context->target;
      $region = $location['region'] ?? NULL;
      if ($region !== NULL) {
        $error = $this->validateRegionExists($anchor_section, $region);
        if ($error !== NULL) {
          return $error;
        }
      }
      else {
        $region = $this->getDefaultRegion($anchor_section);
      }
      $anchor_section->appendComponent($this->daedalusLayoutAdapter->createSectionComponent($section, $region));
    }
    elseif ($dest_flow !== NULL) {
      // Region-flow destination (a block or container anchor): wrap the
      // moved section in a container component beside the anchor.
      $region = $dest_flow['region'];
      $weight = $this->siblingWeight($dest_flow['section'], $region, $anchor, $placement);
      $dest_flow['section']->insertComponent($weight, $this->daedalusLayoutAdapter->createSectionComponent($section, $region, $weight));
    }
    else {
      $anchor_delta = $this->findSectionDelta($dest_list, $anchor);
      if ($anchor_delta === NULL) {
        // Unreachable in practice: the anchor was located above.
        $anchor_delta = count($dest_list->getSections());
      }
      $dest_list->insertSection($placement === 'after' ? $anchor_delta + 1 : $anchor_delta, $section);
    }

    if ($failure = $this->validateSectionListOwner($entity)) {
      return $failure;
    }
    $this->bubbleStorageChanges($storage);

    // Depth before: wrapped in a container component (nested) or a root
    // row. Depth after: the destination branch decided it, 'inside' a
    // section and a region flow both land inside a section, the root list
    // (a root-anchored append or a root-row sibling) is root.
    $event_targets = $this->dispatchLifecycle(new ElementMovedEvent(
      $entity,
      $uuid,
      TRUE,
      $source_container !== NULL,
      $anchor_context !== NULL && ($placement === 'inside' || $dest_flow !== NULL),
    ));

    // A section's position only exists in its parent container's markup, so
    // the whole entity re-renders (root fallback).
    return BlueprintResult::success($entity)
      ->withTargets([['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED], ...$event_targets]);
  }

  /**
   * Enforces the placement-restriction answer for a block destination.
   *
   * The spine check for add AND move: one filtered-definitions answer for
   * inline and plugin blocks, per-region precise (the scoped storage's
   * getSection(delta) hands per-region providers the governing layout at any
   * depth), governed by the ROOT display at every depth. The palette and AI
   * schema project the same answer, so anything they offer passes here and
   * anything they hide fails here.
   *
   * @param string $plugin_id
   *   The block plugin ID being placed (inline_block:* or plugin block).
   * @param object|null $storage
   *   The section storage governing the destination, or NULL when none is
   *   resolvable (provider-independent filtering still applies).
   * @param \Drupal\layout_builder\Section $section
   *   The destination section.
   * @param string $region
   *   The destination region.
   * @param string $path
   *   The error path for a failure.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult|null
   *   A structural failure when the placement is restricted, NULL otherwise.
   */
  protected function checkBlockPlacement(string $plugin_id, ?object $storage, Section $section, string $region, string $path): ?BlueprintResult {
    if ($this->placementRestrictions->blockAllowed($plugin_id, $storage, $section, $region)) {
      return NULL;
    }
    if (str_starts_with($plugin_id, 'inline_block:')) {
      $block_type = substr($plugin_id, strlen('inline_block:'));
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: $path,
          code: 'RESTRICTED_BLOCK_TYPE',
          message: "Block type '$block_type' is not allowed at this destination.",
          context: ['allowed_block_types' => $this->placementRestrictions->allowedInlineBlockBundles($storage, $section, $region)],
          hint: 'Pick an allowed block type, or update the display\'s block restrictions.',
        ),
      ]);
    }
    return BlueprintResult::structuralFailure([
      new BlueprintError(
        path: $path,
        code: 'RESTRICTED_PLUGIN_ID',
        message: "Block plugin '$plugin_id' is not allowed at this destination.",
        hint: 'Pick an allowed block plugin, or update the display\'s block restrictions.',
      ),
    ]);
  }

  /**
   * Enforces the allowed-layouts answer for a section's layout.
   *
   * Applies where a section acquires a layout: add_section, whether the
   * ladder minted it or an explicit layout named it — a section is born
   * with its layout for life. Moves need no check — a moved section keeps
   * a layout the same root display already allowed.
   *
   * @param string $layout_id
   *   The layout plugin ID.
   * @param object|null $storage
   *   The section storage governing the display, or NULL.
   * @param string $path
   *   The error path for a failure.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult|null
   *   A structural failure when the layout is restricted, NULL otherwise.
   */
  protected function checkLayoutAllowed(string $layout_id, ?object $storage, string $path): ?BlueprintResult {
    if ($this->placementRestrictions->layoutAllowed($layout_id, $storage)) {
      return NULL;
    }
    return BlueprintResult::structuralFailure([
      new BlueprintError(
        path: $path,
        code: 'RESTRICTED_LAYOUT',
        message: "Layout '$layout_id' is not allowed for this entity display.",
        context: ['allowed_layouts' => array_keys($this->placementRestrictions->allowedLayoutDefinitions($storage))],
        hint: 'Pick an allowed layout, or update the display\'s layout restrictions.',
      ),
    ]);
  }

  /**
   * Resolves the section list containing a section UUID.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $uuid
   *   The section UUID.
   * @param object|null $storage
   *   The shared section storage wrapper for this operation.
   *
   * @return object|null
   *   The containing section list, or NULL when it cannot be resolved.
   */
  protected function getContainingSectionList(FieldableEntityInterface $entity, string $uuid, ?object $storage): ?object {
    if ($storage !== NULL && method_exists($storage, 'getSectionsFor')) {
      return $storage->getSectionsFor($uuid);
    }
    return $entity->hasField('layout_builder__layout')
      ? $entity->get('layout_builder__layout')
      : NULL;
  }

  /**
   * Finds a section's delta within a section list by its daedalus_layout UUID.
   *
   * @param object $list
   *   The section list.
   * @param string $uuid
   *   The section UUID.
   *
   * @return int|null
   *   The delta, or NULL when the section is not in the list.
   */
  protected function findSectionDelta(object $list, string $uuid): ?int {
    foreach ($list->getSections() as $delta => $section) {
      if ($this->daedalusLayoutAdapter->getSectionUuid($section) === $uuid) {
        return $delta;
      }
    }
    return NULL;
  }

  /**
   * Whether an anchor lives inside the element being moved.
   *
   * Placing an element relative to its own nested content would insert it
   * into itself. Detected through the daedalus_layout tree index: a descendant's
   * tree path carries every ancestor's UUID (container components and their
   * embedded sections share one UUID, so the check is uniform for blocks
   * and sections).
   *
   * @param object|null $storage
   *   The shared section storage wrapper for this operation.
   * @param string $element_uuid
   *   The UUID of the element being moved.
   * @param string $anchor_uuid
   *   The anchor UUID.
   * @param \Drupal\daedalus_blueprint\ElementContext $element_context
   *   The located element context.
   *
   * @return bool
   *   TRUE when the anchor is nested inside the moved element.
   */
  protected function anchorInsideElement(?object $storage, string $element_uuid, string $anchor_uuid, ElementContext $element_context): bool {
    if ($storage === NULL || !method_exists($storage, 'getPath')) {
      // Without daedalus_layout there is no nesting, so containment is impossible.
      return FALSE;
    }
    $anchor_path = $storage->getPath($anchor_uuid) ?? [];
    return in_array($element_uuid, $anchor_path, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function duplicateElement(FieldableEntityInterface $entity, string $uuid, ?string $new_uuid = NULL): BlueprintResult {
    if ($failure = $this->validateMintedUuids($entity, ['uuid' => $new_uuid])) {
      return $failure;
    }
    $context = $this->locateElement($entity, $uuid, 'element', 'ELEMENT_NOT_FOUND');
    if ($context instanceof BlueprintResult) {
      return $context;
    }

    if ($context->isSection()) {
      return $this->duplicateSection($entity, $uuid, $new_uuid, $context);
    }

    if (!$context->isLbComponent()) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'element',
          code: 'NOT_DUPLICABLE',
          message: 'Only blocks and sections can be duplicated.',
          hint: 'Target a block placement or a section by its UUID.',
        ),
      ]);
    }

    /** @var \Drupal\layout_builder\SectionComponent $component */
    $component = $context->target;
    $uuid_map = [];
    $clone = $this->cloneComponent($component, $new_uuid, $uuid_map);
    $context->section->insertAfterComponent($uuid, $clone);
    $this->bubbleNestedChanges($context);
    // Modules keying data by placement/section UUIDs (style maps, per-
    // placement metadata) carry their entries to the clone's fresh identity.
    $event_targets = $this->dispatchLifecycle(new ElementDuplicatedEvent($entity, $clone, $uuid, $uuid_map));

    return BlueprintResult::success($entity)
      ->withTargets(array_merge(
        [['uuid' => $clone->getUuid(), 'field_name' => TargetType::ADDED]],
        $event_targets,
      ));
  }

  /**
   * Duplicates a section (any depth) as a sibling of the original.
   *
   * A uniform verb: top-level sections duplicate into a new layout field
   * row, nested sections into a new container component beside the original.
   * The whole subtree gets fresh identity (section, components, nested
   * sections, inline content), reported through the duplicated event so
   * UUID-keyed data (style maps) carries over.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string $uuid
   *   The section UUID to duplicate.
   * @param string|null $new_uuid
   *   Optional writer-minted UUID for the duplicate.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The located section context.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function duplicateSection(FieldableEntityInterface $entity, string $uuid, ?string $new_uuid, ElementContext $context): BlueprintResult {
    $storage = $context->sectionStorage ?? $this->daedalusLayoutAdapter->getSectionStorage($entity);

    $uuid_map = [];
    /** @var \Drupal\layout_builder\Section $section */
    $section = $context->target;
    $clone = $this->cloneSection($section, $uuid_map, $new_uuid);

    $container = $this->daedalusLayoutAdapter->locateContainer($storage, $uuid);
    if ($container !== NULL) {
      // Nested: a fresh container component right after the original's.
      $parent_section = $container['parentSection'];
      $region = $container['component']->getRegion();
      $uuids = array_keys($parent_section->getComponentsByRegion($region));
      $index = array_search($uuid, $uuids, TRUE);
      $weight = $index === FALSE ? count($uuids) : $index + 1;
      $parent_section->insertComponent($weight, $this->daedalusLayoutAdapter->createSectionComponent($clone, $region, $weight));
    }
    else {
      $list = $this->getContainingSectionList($entity, $uuid, $storage);
      $delta = $list !== NULL ? $this->findSectionDelta($list, $uuid) : NULL;
      if ($delta === NULL) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: 'element',
            code: 'SECTION_NOT_FOUND',
            message: "Could not resolve the containing list for section '$uuid'.",
            hint: 'Verify the section UUID exists in the entity structure.',
          ),
        ]);
      }
      $list->insertSection($delta + 1, $clone);
    }

    if ($failure = $this->validateSectionListOwner($entity)) {
      return $failure;
    }
    $this->bubbleStorageChanges($storage);
    $clone_uuid = $this->daedalusLayoutAdapter->getSectionUuid($clone);
    $event_targets = $this->dispatchLifecycle(new ElementDuplicatedEvent($entity, NULL, $uuid, $uuid_map));

    return BlueprintResult::success($entity)
      ->withTargets(array_merge(
        [
          ['uuid' => $clone_uuid ?? $entity->uuid(), 'field_name' => TargetType::ADDED],
          ['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED],
        ],
        $event_targets,
      ));
  }

  /**
   * Deep-clones a section component with fresh identity throughout.
   *
   * Container components clone their embedded section recursively: the
   * whole subtree gets fresh identity, and the clone's component uuid stays
   * locked to the cloned section's new uuid (one identity).
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to clone.
   * @param string|null $uuid
   *   Optional UUID for the clone; generated when NULL.
   * @param array $uuid_map
   *   Collects old UUID => new UUID for every regenerated identity, by
   *   reference (see ElementDuplicatedEvent).
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The cloned component.
   */
  protected function cloneComponent(SectionComponent $component, ?string $uuid = NULL, array &$uuid_map = []): SectionComponent {
    $values = $component->toArray();

    $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
    if ($embedded !== NULL) {
      $section_clone = $this->cloneSection($embedded, $uuid_map, $uuid);
      $clone = $this->daedalusLayoutAdapter->createSectionComponent(
        $section_clone,
        $component->getRegion(),
        $component->getWeight(),
        $values['additional'] ?? [],
      );
      // Carry opaque non-structural configuration to the clone.
      $fresh = $clone->get('configuration');
      $opaque = array_diff_key($values['configuration'], $fresh, ['uuid' => TRUE]);
      if (!empty($opaque)) {
        $clone->setConfiguration(array_merge($opaque, $fresh));
      }
      $uuid_map[$component->getUuid()] = $clone->getUuid();
      return $clone;
    }

    $configuration = $values['configuration'];
    $this->cloneInlineContent($component, $configuration, $uuid_map);

    $clone = new SectionComponent(
      $uuid ?? (new PhpUuid())->generate(),
      $component->getRegion(),
      $configuration,
      $values['additional'] ?? [],
    );
    $clone->setWeight($component->getWeight());
    $uuid_map[$component->getUuid()] = $clone->getUuid();
    return $clone;
  }

  /**
   * Points an inline block's configuration at a duplicated block_content.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The original component.
   * @param array $configuration
   *   The clone's configuration, modified by reference.
   * @param array $uuid_map
   *   Collects old UUID => new UUID for every regenerated identity, by
   *   reference (see ElementDuplicatedEvent).
   */
  protected function cloneInlineContent(SectionComponent $component, array &$configuration, array &$uuid_map = []): void {
    if (!$this->daedalusLayoutAdapter->isInlineBlock($component)) {
      return;
    }
    $block = $this->daedalusLayoutAdapter->extractBlockContent($component);
    if ($block === NULL) {
      return;
    }
    $duplicate = $block->createDuplicate();
    $uuid_map[$block->uuid()] = $duplicate->uuid();

    $configuration['block_serialized'] = serialize($duplicate);
    // The clone must not keep pointing at the original's saved revision.
    unset($configuration['block_revision_id']);
  }

  /**
   * Deep-clones a section: fresh section UUID, fresh component identities.
   *
   * Nested sections (container components) clone recursively via
   * cloneComponent(), so arbitrary depth gets fresh identity throughout.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to clone.
   * @param array $uuid_map
   *   Collects old UUID => new UUID for every regenerated identity, by
   *   reference (see ElementDuplicatedEvent).
   * @param string|null $new_uuid
   *   Optional writer-minted UUID for the cloned section; generated when
   *   NULL.
   *
   * @return \Drupal\layout_builder\Section
   *   The cloned section.
   */
  protected function cloneSection(Section $section, array &$uuid_map = [], ?string $new_uuid = NULL): Section {
    $values = $section->toArray();
    $values['components'] = [];
    foreach ($section->getComponents() as $component) {
      $clone = $this->cloneComponent($component, NULL, $uuid_map);
      $values['components'][$clone->getUuid()] = $clone->toArray();
    }
    $section_clone = Section::fromArray($values);
    $old_section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($section_clone);
    if ($old_section_uuid !== NULL) {
      $new_section_uuid = $new_uuid ?? (new PhpUuid())->generate();
      $this->daedalusLayoutAdapter->setSectionUuid($section_clone, $new_section_uuid);
      $uuid_map[$old_section_uuid] = $new_section_uuid;
    }
    return $section_clone;
  }

  /**
   * {@inheritdoc}
   */
  public function updateFields(FieldableEntityInterface $entity, array $fields, array $options = []): BlueprintResult {
    $result = $this->validateAndApplyFields($entity, $fields, 'fields', $options + [
      'rejectedTypes' => static::REJECTED_FIELD_TYPES,
      'is_update' => TRUE,
    ]);

    $failure = $this->fieldResultToFailure($result);
    if ($failure !== NULL) {
      return $failure;
    }

    return BlueprintResult::success($entity, $result['warnings'])
      ->withTargetsFrom($entity->uuid(), array_keys($fields));
  }

  /**
   * {@inheritdoc}
   */
  public function restructureLayout(FieldableEntityInterface $entity, array $structure, array $options = []): BlueprintResult {
    if (!$entity->hasField('layout_builder__layout')) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'layout_builder__layout',
          code: 'NO_LAYOUT',
          message: 'Entity does not have a Layout Builder layout.',
          hint: 'Enable Layout Builder on this entity type.',
        ),
      ]);
    }

    $layout_sections = $structure['layout_builder__layout'] ?? $structure;

    $layout_result = $this->layoutDeserializer->deserialize($layout_sections, $entity, 'full', $options);
    if ($layout_result->hasErrors()) {
      return BlueprintResult::structuralFailure($layout_result->getErrors());
    }

    // Collect all component UUIDs from the new layout for targeting.
    $targets = [];
    foreach ($layout_result->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $targets[] = ['uuid' => $component->getUuid(), 'field_name' => TargetType::RESTRUCTURED];
      }
    }

    return BlueprintResult::success($entity, $layout_result->getWarnings())
      ->withTargets($targets);
  }

  /**
   * {@inheritdoc}
   */
  public function reorderField(FieldableEntityInterface $entity, array $structure): BlueprintResult {
    // Determine target entity.
    $target_entity = $entity;
    if (isset($structure['parent_uuid'])) {
      $context = $this->locateElement($entity, $structure['parent_uuid'], 'parent_uuid', 'PARENT_NOT_FOUND');
      if ($context instanceof BlueprintResult) {
        return $context;
      }
      $target_entity = $context->target;
    }

    $field_name = $structure['field'] ?? NULL;
    if ($field_name === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'field',
          code: 'MISSING_FIELD',
          message: 'Field name is required for reorder.',
        ),
      ]);
    }

    $field_def = $target_entity->getFieldDefinition($field_name);
    if ($field_def === NULL || $field_def->getType() !== 'entity_reference_revisions') {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'field',
          code: 'INVALID_FIELD',
          message: "Field '$field_name' is not an entity reference revisions field.",
          hint: 'Only entity_reference_revisions fields can be reordered.',
        ),
      ]);
    }

    $items = $structure['items'] ?? [];

    // Build UUID map from current field.
    $field_list = $target_entity->get($field_name);
    $entity_map = [];
    foreach ($field_list as $item) {
      $child_entity = $item->entity;
      if ($child_entity !== NULL) {
        $entity_map[$child_entity->uuid()] = $child_entity;
      }
    }

    // Validate all UUIDs exist.
    $errors = [];
    foreach ($items as $index => $item_spec) {
      $item_uuid = $item_spec['uuid'] ?? NULL;
      if ($item_uuid === NULL) {
        $errors[] = new BlueprintError(
          path: "items[{$index}]",
          code: 'MISSING_UUID',
          message: 'Item UUID is required.',
        );
        continue;
      }
      if (!isset($entity_map[$item_uuid])) {
        $errors[] = new BlueprintError(
          path: "items[{$index}]",
          code: 'ITEM_NOT_FOUND',
          message: "No item with UUID '$item_uuid' found in field '$field_name'.",
          hint: 'Verify the UUID exists in the field.',
        );
      }
    }

    if (!empty($errors)) {
      return BlueprintResult::structuralFailure($errors);
    }

    // Reorder items to match the submitted UUID order.
    $new_items = [];
    foreach ($items as $item_spec) {
      $new_items[] = ['entity' => $entity_map[$item_spec['uuid']]];
    }
    $target_entity->set($field_name, $new_items);

    $element_uuids = array_column($items, 'uuid');
    return BlueprintResult::success($entity)->withTargets(
      array_map(fn(string $uuid) => ['uuid' => $uuid, 'field_name' => TargetType::REORDERED], $element_uuids),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function batchOperations(FieldableEntityInterface $entity, array $operations, array $options = []): BlueprintResult {
    // Envelope leniency: a bare op object is a one-element batch. Models
    // measurably drop the array when one op suffices; the semantics are
    // identical, so accept the spelling.
    if (isset($operations['op'])) {
      $operations = [$operations];
    }
    if (empty($operations)) {
      return BlueprintResult::success($entity);
    }

    $dressed = !empty($options['dressed']);
    $judge = !empty($options['judge']);
    // The judge posture is THIS batch's: a nested batch an op runs (a
    // stencil expansion, a translated fragment) lands on the doomed
    // entity like any other op, so later ops are judged against a world
    // where it happened; the one restore is the judged batch's own.
    unset($options['judge']);
    $this->batchDepth++;
    if ($dressed) {
      $this->dressedDepth++;
    }
    try {
      $result = $this->doBatchOperations($entity, $operations, $options + ['judge' => $judge]);
    }
    finally {
      $this->batchDepth--;
      if ($dressed) {
        $this->dressedDepth--;
      }
    }
    // Alias settlement is the outermost batch's: nested successes are
    // provisional until the whole gesture stands, and a judged batch
    // lands nothing, aliases included.
    if ($this->batchDepth === 0) {
      $result->isSuccess() && !$judge ? $this->dialectAliasMap->persist() : $this->dialectAliasMap->discard();
    }
    return $result;
  }

  /**
   * Applies a batch of operations with total-rollback semantics.
   *
   * @see ::batchOperations()
   */
  protected function doBatchOperations(FieldableEntityInterface $entity, array $operations, array $options = []): BlueprintResult {
    // Snapshot entity state for rollback.
    $snapshot = $this->snapshotEntity($entity);

    $batch_errors = [];
    $has_structural_errors = FALSE;
    $all_warnings = [];
    $all_targets = [];
    $all_aliases = [];
    $tainted_uuids = [];

    // Apply operations sequentially and evaluate EVERY operation, even after
    // a failure: the consumer is a writer that patches the whole batch per
    // round, so drip-feeding one failure at a time multiplies repair rounds.
    // After the first failure the entity is doomed (rollback below is total);
    // the remaining operations keep executing on it purely to produce
    // diagnostics.
    $judge = !empty($options['judge']);
    unset($options['judge']);
    foreach ($operations as $index => $operation) {
      $result = $this->applyOperation($entity, $operation, $options);

      if ($result->isSuccess()) {
        array_push($all_warnings, ...$result->getWarnings());
        array_push($all_targets, ...$result->getTargets());
        $all_aliases = array_merge($all_aliases, $result->getAliases());
        continue;
      }

      // An operation that references identity a FAILED operation was to mint
      // was evaluated against a world where that identity never came to be:
      // its errors (ELEMENT_NOT_FOUND and friends) are shadows of the
      // earlier failure, and reporting them would invite the writer to
      // "fix" an operation whose only problem is upstream. Suppress them
      // wholesale and propagate the taint through this operation's own
      // minted identity, so cascades stay silent at any depth. The
      // suppressed operation is re-evaluated in full on the next round —
      // rollback is total, so the corrected batch is always resubmitted
      // whole.
      $dependency_tainted = $this->referencesTaintedUuid($operation, $tainted_uuids);
      array_push($tainted_uuids, ...$this->mintedUuids($operation));
      if ($dependency_tainted) {
        continue;
      }

      // Every failing operation reports its OWN errors, in operation order,
      // whatever its phase. Ops are independent gestures: one op's shape
      // problem says nothing about another op's semantic findings, and the
      // shadow problem between DEPENDENT ops is the taint suppression's job
      // above. Withholding semantic errors whenever any structural error
      // exists would let a downstream shadow that happens to be
      // structural (UNKNOWN_ID_SELECTOR on an alias a failed insert never
      // minted) mask the upstream op's real semantic cause, steering the
      // writer's repair at the wrong operation.
      if ($result->getPhase() === 'structural') {
        $has_structural_errors = TRUE;
      }
      array_push($batch_errors, ...$this->tagErrorsWithIndex($result->getErrors(), $index));
    }

    if (!empty($batch_errors)) {
      $this->restoreEntity($entity, $snapshot);

      // Shared-hint deduplication is the OUTERMOST batch's: nested batches
      // (DOM ops expanding into spine ops) bubble their errors up marked,
      // and only the whole gesture's error list knows which roster is the
      // first spelling the writer will read.
      if ($this->batchDepth === 1) {
        $batch_errors = $this->dedupeSharedHints($batch_errors);
      }

      // The batch's phase is the earliest phase any op failed in: structural
      // when any op's shape was wrong, semantic otherwise. Errors of both
      // phases ride along either way; the phase is a label, not a filter.
      return $has_structural_errors
        ? BlueprintResult::structuralFailure($batch_errors)
        : BlueprintResult::semanticFailure($batch_errors);
    }

    if ($judge) {
      // A judged batch reports what WOULD have landed and lands nothing:
      // the same total rollback a failure takes, on a clean run.
      $this->restoreEntity($entity, $snapshot);
    }

    return BlueprintResult::success($entity, $all_warnings)
      ->withTargets($all_targets)
      ->withAliases($all_aliases);
  }

  /**
   * Bubbles nested Layout Builder changes to the root entity.
   *
   * When a nested section (or anything inside one) is modified, the
   * container components' embedded section values are stale. This calls
   * bubbleChangesToRoot() on the section storage wrapper, which rebuilds
   * its index and flushes every embedded section list deepest-first,
   * writing Section::toArray() back through the container components.
   *
   * Safe to call unconditionally: when sectionStorage is NULL (no daedalus_layout)
   * this is a no-op, and the flush is idempotent for unmutated wrappers.
   *
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The element context from the locate operation.
   */
  protected function bubbleNestedChanges(ElementContext $context): void {
    $this->bubbleStorageChanges($context->sectionStorage);
  }

  /**
   * Bubbles nested-layout changes to the root entity through a storage.
   *
   * @param object|null $storage
   *   The section storage, if any. Only daedalus_layout's nested-aware storage
   *   supports bubbling; anything else is a no-op.
   */
  protected function bubbleStorageChanges(?object $storage): void {
    if ($storage !== NULL && method_exists($storage, 'bubbleChangesToRoot')) {
      $storage->bubbleChangesToRoot();
    }
  }

  /**
   * Locates an element by UUID, returning a failure result if not found.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to search.
   * @param string $uuid
   *   The element UUID.
   * @param string $path
   *   The error path (e.g., 'element', 'location.anchor', 'parent_uuid').
   * @param string $code
   *   The error code (e.g., 'ELEMENT_NOT_FOUND', 'PARENT_NOT_FOUND').
   * @param object|null $storage
   *   An already-resolved section storage to search within. Required when
   *   one operation locates multiple elements and mutates them (see
   *   ElementLocator::locate()).
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|\Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The element context on success, or a structural failure result.
   */
  protected function locateElement(FieldableEntityInterface $entity, string $uuid, string $path, string $code, ?object $storage = NULL): ElementContext|BlueprintResult {
    $context = $this->elementLocator->locate($entity, $uuid, $storage);
    if ($context === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: $path,
          code: $code,
          message: "No element found with UUID '$uuid'.",
          hint: 'Verify the UUID exists in the entity structure.',
        ),
      ]);
    }
    return $context;
  }

  /**
   * Validates that a component is an inline block and extracts its entity.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   * @param \Drupal\daedalus_blueprint\ElementContext $context
   *   The element context (for section storage).
   * @param string $path
   *   The error path (e.g., 'element', 'uuid').
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|\Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The block_content entity on success, or a structural failure result.
   */
  protected function requireInlineBlock(SectionComponent $component, ElementContext $context, string $path): FieldableEntityInterface|BlueprintResult {
    if (!$this->daedalusLayoutAdapter->isInlineBlock($component)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: $path,
          code: 'NOT_INLINE_BLOCK',
          message: 'Element is not an inline block and has no editable properties.',
          hint: 'Only inline block elements have editable fields and configuration.',
        ),
      ]);
    }

    $block = $this->daedalusLayoutAdapter->extractBlockContent($component);
    if ($block === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: $path,
          code: 'BLOCK_CONTENT_NOT_FOUND',
          message: 'Could not load block_content for this element.',
        ),
      ]);
    }

    return $block;
  }

  /**
   * Converts a validateAndApplyFields result array to a failure result.
   *
   * @param array $result
   *   The array returned by validateAndApplyFields().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult|null
   *   A failure result if errors exist, or NULL if the result is clean.
   */
  protected function fieldResultToFailure(array $result): ?BlueprintResult {
    if (!empty($result['structuralErrors'])) {
      return BlueprintResult::structuralFailure($result['structuralErrors']);
    }
    if (!empty($result['errors'])) {
      return BlueprintResult::semanticFailure($result['errors'], $result['warnings']);
    }
    return NULL;
  }

  /**
   * Validates field names and applies values to an entity.
   *
   * Combines structural validation (field existence, type checks) and
   * semantic application (handler dispatch, constraint validation) into a
   * single method. Used by updateFields, updateElement, addLbComponent,
   * and addParagraphElement.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to apply fields to.
   * @param array $fields
   *   The field data keyed by field name.
   * @param string $path_prefix
   *   The error path prefix (e.g., 'fields' or 'block_fields').
   * @param array $options
   *   Options:
   *   - 'rejectedTypes' (string[]): Field types to reject with an error.
   *   - 'isFullCreate' (bool): Whether to validate required fields
   *     (default: FALSE).
   *   - 'is_update' (bool): Whether this is an update to an existing entity.
   *     Passed through to field handlers (default: FALSE).
   *   - 'element_uuid' (string): the blueprint-target uuid of the element
   *     whose fields are being written. Defaults to the entity's own uuid;
   *     LB-component callers pass the component uuid explicitly.
   *
   * @return array
   *   Array with keys:
   *   - 'structuralErrors': Errors from field name/type validation.
   *   - 'errors': Errors from handlers and constraint validation.
   *   - 'warnings': Warnings from handlers.
   */
  protected function validateAndApplyFields(
    FieldableEntityInterface $entity,
    array $fields,
    string $path_prefix,
    array $options = [],
  ): array {
    $rejected_types = $options['rejectedTypes'] ?? [];
    $is_full_create = $options['isFullCreate'] ?? FALSE;
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    $rewrite_paths = $path_prefix !== 'fields';

    // Phase 1: Structural validation.
    $structural_errors = [];
    $valid_field_names = [];

    foreach ($fields as $field_name => $value) {
      // A form-only stub (FULL-mode structure completeness) is identity,
      // not data: skip it entirely, neither validated as a value nor
      // applied. Same PATCH semantics as an omitted key.
      if (FormOnlyStub::isStub($value)) {
        continue;
      }

      $definition = $entity->getFieldDefinition($field_name);

      if ($definition === NULL) {
        $structural_errors[] = new BlueprintError(
          path: "{$path_prefix}.{$field_name}",
          code: 'UNKNOWN_FIELD',
          message: "Field '$field_name' does not exist on {$entity->getEntityTypeId()}:{$entity->bundle()}.",
          context: ['available' => array_keys($processable_fields)],
          hint: 'Check the field machine name spelling.',
        );
        continue;
      }

      $field_type = $definition->getType();
      if (!empty($rejected_types) && in_array($field_type, $rejected_types, TRUE)) {
        $structural_errors[] = new BlueprintError(
          path: "{$path_prefix}.{$field_name}",
          code: 'FIELD_TYPE_NOT_ALLOWED',
          message: "Field '$field_name' (type '$field_type') cannot be updated via updateFields.",
          hint: 'Use updateElement, addElement, or removeElement for layout and inline entity fields.',
        );
        continue;
      }

      if (!isset($processable_fields[$field_name])) {
        continue;
      }

      $valid_field_names[] = $field_name;
    }

    if (!empty($structural_errors)) {
      return ['structuralErrors' => $structural_errors, 'errors' => [], 'warnings' => []];
    }

    // Phase 2: Apply fields via handlers.
    // Handlers that validate against the surrounding entity or its sibling
    // submitted values (see the 'entity' and 'fields' options on
    // FieldHandlerInterface::deserialize()) get them on the surgical write
    // paths too, matching the root deserialize path.
    $options['entity'] = $entity;
    $options['fields'] = $fields;
    $options['element_uuid'] ??= $entity->uuid();
    $errors = [];
    $warnings = [];
    $error_field_names = [];

    foreach ($valid_field_names as $field_name) {
      $value = $fields[$field_name];
      $definition = $processable_fields[$field_name];

      // Per-field edit access on the surgical write path, mirroring the root
      // deserialize and nested (inline block / paragraph) paths. Without this,
      // updateElement/updateFields/addElement and batch
      // operations skip field-edit access entirely. Denial errors rather than
      // silently dropping the field. Field access defaults allowed in core, so
      // this only bites explicitly-restricted fields (forward correctness).
      if (!$this->accessChecker->fieldEditable($entity->get($field_name))) {
        $errors[] = new BlueprintError(
          path: "{$path_prefix}.{$field_name}",
          code: 'ACCESS_DENIED_FIELD',
          message: "Access denied: cannot edit field '$field_name'.",
          hint: 'The current user lacks permission to modify this field.',
        );
        $error_field_names[] = $field_name;
        continue;
      }

      $handler = $this->fieldHandlerManager->getHandler($definition);
      $handler_result = $handler->deserialize($value, $definition, $options);

      if ($handler_result->hasErrors()) {
        foreach ($handler_result->getErrors() as $error) {
          if ($rewrite_paths) {
            $original_path = $error->getPath();
            $rewritten_path = str_starts_with($original_path, 'fields.')
              ? $path_prefix . '.' . substr($original_path, 7)
              : "{$path_prefix}.{$field_name}";
            $errors[] = $error->withPath($rewritten_path);
          }
          else {
            $errors[] = $error;
          }
        }
        $error_field_names[] = $field_name;
      }
      else {
        $entity->set($field_name, $handler_result->getValue());
      }

      array_push($warnings, ...$handler_result->getWarnings());
    }

    // Constraint validation scoped to submitted fields.
    $constraint_errors = $this->semanticValidator->validateConstraints(
      $entity,
      $valid_field_names,
      $error_field_names,
      $is_full_create,
    );

    if ($rewrite_paths) {
      foreach ($constraint_errors as $error) {
        $original_path = $error->getPath();
        $rewritten_path = str_starts_with($original_path, 'fields.')
          ? $path_prefix . '.' . substr($original_path, 7)
          : $original_path;
        $errors[] = $error->withPath($rewritten_path);
      }
    }
    else {
      array_push($errors, ...$constraint_errors);
    }

    return ['structuralErrors' => [], 'errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Adds a block component to a Layout Builder section.
   *
   * Inline blocks (block_type): creates a new block_content entity, applies
   * fields via FieldHandlers, and serializes the block into the component.
   * Plugin blocks (plugin_id): builds the component from the block plugin's
   * default configuration with context mappings defaulted.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param array $location
   *   The resolved location: _section (and _storage for nested sections)
   *   from the placement grammar, plus region and optional weight.
   * @param array $data
   *   Component data with block_type (inline) or plugin_id (plugin block),
   *   and optionally block_fields, uuid, label, view_mode, populate, and
   *   additional (opaque per-placement metadata stored on the
   *   SectionComponent, keyed by owning module). Blocks hold no nested
   *   layouts; sections nest through add_section / section nodes.
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function addLbComponent(FieldableEntityInterface $entity, array $location, array $data, array $options = []): BlueprintResult {
    if (!$entity->hasField('layout_builder__layout')) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location',
          code: 'NO_LAYOUT',
          message: 'Entity does not have a Layout Builder layout.',
          hint: 'Enable Layout Builder on this entity type.',
        ),
      ]);
    }

    $populate = $data['populate'] ?? NULL;
    if ($populate !== NULL && $populate !== 'sample') {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.populate',
          code: 'INVALID_POPULATE',
          message: "Unknown populate mode '$populate'.",
          context: ['valid_modes' => ['sample']],
          hint: "Use populate: 'sample' or omit the option.",
        ),
      ]);
    }

    // Opaque per-placement metadata riding the SectionComponent's additional
    // slot (the third-party seam clone/move/restructure already preserve):
    // never rendered, never serialized to clients, never validated beyond
    // its map shape. The stencil provenance stamp is the canonical consumer.
    $additional = $data['additional'] ?? [];
    if (!is_array($additional) || (array_is_list($additional) && $additional !== [])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.additional',
          code: 'INVALID_ADDITIONAL',
          message: 'additional is a map of opaque placement metadata.',
          hint: 'Key the map by the module owning each entry, or omit it.',
        ),
      ]);
    }

    // The target section, resolved from the anchor grammar upstream. The
    // storage rides along for nested sections (changes must bubble to the
    // root through it).
    $section_info = [
      'section' => $location['_section'],
      'storage' => $location['_storage'] ?? NULL,
    ];
    $section = $section_info['section'];

    // Validate block type.
    $block_type = $data['block_type'] ?? NULL;
    $plugin_id = $data['plugin_id'] ?? NULL;
    if ($block_type !== NULL && $plugin_id !== NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data',
          code: 'AMBIGUOUS_BLOCK',
          message: 'Provide block_type (inline block) or plugin_id (plugin block), not both.',
          hint: 'Inline blocks hold their own content; plugin blocks render existing content.',
        ),
      ]);
    }
    if ($plugin_id !== NULL) {
      return $this->addPluginBlockComponent($entity, $section_info, $location, $data);
    }
    if ($block_type === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.block_type',
          code: 'MISSING_BLOCK_TYPE',
          message: 'Block type is required.',
        ),
      ]);
    }

    $block_type_entity = $this->entityTypeManager
      ->getStorage('block_content_type')
      ->load($block_type);
    if ($block_type_entity === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.block_type',
          code: 'INVALID_BLOCK_TYPE',
          message: "Block type '$block_type' does not exist.",
          hint: 'Check available block types in the schema.',
        ),
      ]);
    }

    // Restriction enforcement at the spine. The governing display rides the
    // section storage (the entity's actual render display, any depth), so
    // data.view_mode stays what it is: the BLOCK's render view mode.
    $region = $location['region'] ?? 'content';
    $op_storage = $section_info['storage'] ?? $this->daedalusLayoutAdapter->getSectionStorage($entity);
    if ($failure = $this->checkBlockPlacement('inline_block:' . $block_type, $op_storage, $section, $region, 'data.block_type')) {
      return $failure;
    }
    $view_mode = $data['view_mode'] ?? 'full';

    // Create block_content.
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => $block_type,
      'reusable' => FALSE,
    ]);

    // Sort the flat property bag into fields, configuration, and label.
    $block_fields = $data['block_fields'] ?? [];
    $sorted = $this->sortElementProperties($entity, $block_type, $block, $block_fields);
    if (!empty($sorted['errors'])) {
      return BlueprintResult::structuralFailure($sorted['errors']);
    }

    // Palette enrichment (ported from daedalus_layout's Dropzones): sample content
    // for fields the caller didn't provide, and a view mode that actually
    // exists for the block bundle (InlineBlock's 'full' default usually
    // doesn't). Runs before field validation so required fields are filled.
    if ($populate === 'sample') {
      $this->populateSampleValues($block, array_keys($sorted['fields']));
      $view_mode = $this->resolveExistingViewMode($block, $view_mode);
    }

    // Blocks never carry nested layouts: sections nest inside sections. The
    // old layout-capable-block grammar (data.layout_builder__layout subtree,
    // data.section_uuid) is refused loudly with a pointer to the one
    // placement grammar.
    foreach (['layout_builder__layout', 'section_uuid'] as $dead_key) {
      if (isset($data[$dead_key])) {
        return BlueprintResult::structuralFailure([
          new BlueprintError(
            path: "data.$dead_key",
            code: 'NO_LAYOUT',
            message: 'Blocks do not hold nested layouts.',
            hint: "Sections nest inside sections: use add_section with placement 'inside' anchored on a section (any depth), or place a section node in restructure_layout / create_entity regions.",
          ),
        ]);
      }
    }
    $nested_warnings = [];

    // The component display label (`configuration['label']`) is layout config —
    // for many block types (e.g. headings) it is the rendered text. It is NOT
    // the block_content `info` field. See RF2 decisions in
    // notes/entity-access.md.
    $label = $data['label'] ?? $sorted['label'] ?? $block_type_entity->label();
    // The label is plain text (for heading-style blocks, the rendered text),
    // so it gets the same LLM entity-escape defense as plain-text fields.
    if (!empty($options['decode_html_entities']) && is_string($label)) {
      $label = HtmlEntityDecoder::decode($label);
    }

    // `info` is required (NOT NULL). Seed it as a creation default on this
    // fresh block BEFORE field validation, unless the AI sets it through the
    // gated field pipeline (block_fields.info), which takes precedence and is
    // applied before constraint validation. Seeding a fresh required field is
    // not the label→info write the decouple removed (that was on update). See
    // RF2 decisions in notes/entity-access.md.
    if (!isset($sorted['fields']['info'])) {
      $block->setInfo($label);
    }

    // The component uuid is minted before field application so handlers that
    // record change targets (see the element_uuid option) address the
    // component the block will mount as.
    $uuid = $data['uuid'] ?? (new PhpUuid())->generate();

    // Apply block_content fields through the gated pipeline. `info`, when
    // provided, is gated and routed here like any other field.
    $result = $this->validateAndApplyFields($block, $sorted['fields'], 'block_fields', $options + [
      'isFullCreate' => TRUE,
      'element_uuid' => $uuid,
    ]);

    $failure = $this->fieldResultToFailure($result);
    if ($failure !== NULL) {
      return $failure;
    }

    // Build InlineBlock plugin configuration.
    $configuration = [
      'id' => 'inline_block:' . $block_type,
      'provider' => 'layout_builder',
      'label' => $label,
      'label_display' => '0',
      'view_mode' => $view_mode,
      'block_serialized' => serialize($block),
    ];

    // Apply block type defaults from context YAML, then explicit overrides.
    $block_context = $this->contextResolver->getBlockTypeContext(
      $entity->getEntityTypeId(), $entity->bundle(), $block_type,
    );
    $config_defaults = $block_context['defaults']['configuration'] ?? [];
    if (!empty($config_defaults)) {
      $configuration = array_merge($configuration, $config_defaults);
    }
    if (!empty($sorted['configuration'])) {
      $configuration = array_merge($configuration, $sorted['configuration']);
    }

    // Build SectionComponent and insert at the requested position.
    $component = new SectionComponent($uuid, $region, $configuration, $additional);
    $event_targets = $this->dispatchLifecycle(new ElementCreatedEvent($entity, $component, $block, $this->isDressed()));
    $region_count = count($section->getComponentsByRegion($region));
    $position = isset($location['weight']) ? min((int) $location['weight'], $region_count) : $region_count;
    $section->insertComponent($position, $component);
    $this->bubbleStorageChanges($section_info['storage']);

    $targets = [['uuid' => $uuid, 'field_name' => TargetType::ADDED], ...$event_targets];

    return BlueprintResult::success($entity, array_merge($result['warnings'], $nested_warnings))
      ->withTargets($targets);
  }

  /**
   * Adds a plugin block (non-inline) component to a Layout Builder section.
   *
   * Plugin blocks render existing content (views, entity fields, menus, …)
   * so no block_content entity is created; the component carries the block
   * plugin's configuration directly. Context mappings are defaulted the way
   * the daedalus_layout palette did (Dropzones::createBlockPlugin): unambiguous
   * required contexts are assigned and an unresolved 'entity' slot falls
   * back to the layout_builder.entity context, which FieldBlock expects.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param array $section_info
   *   The resolved section and storage.
   * @param array $location
   *   Location with region and weight.
   * @param array $data
   *   Component data with plugin_id and optionally uuid, label, populate
   *   (accepted for parity with inline blocks; a plugin block has no
   *   fields to sample), and additional (opaque per-placement metadata).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function addPluginBlockComponent(FieldableEntityInterface $entity, array $section_info, array $location, array $data): BlueprintResult {
    $plugin_id = $data['plugin_id'];

    if (!empty($data['block_fields'])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'properties',
          code: 'PROPERTIES_NOT_SUPPORTED',
          message: 'Plugin blocks do not take properties — they have no content fields.',
          hint: 'Properties apply to inline blocks (block_type). Configure a plugin block via update_element after placing it.',
        ),
      ]);
    }

    if (isset($data['layout_builder__layout'])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.layout_builder__layout',
          code: 'NO_LAYOUT',
          message: 'Blocks do not hold nested layouts.',
          hint: "Sections nest inside sections: use add_section with placement 'inside' anchored on a section.",
        ),
      ]);
    }

    if (str_starts_with($plugin_id, 'inline_block:')) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.plugin_id',
          code: 'INVALID_PLUGIN_ID',
          message: "Inline blocks are placed via block_type, not plugin_id.",
          hint: "Use block_type: '" . substr($plugin_id, strlen('inline_block:')) . "' instead.",
        ),
      ]);
    }
    $definitions = $this->blockManager->getDefinitions();
    if (!isset($definitions[$plugin_id])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.plugin_id',
          code: 'INVALID_PLUGIN_ID',
          message: "Block plugin '$plugin_id' does not exist.",
          hint: 'Check the block plugin ID.',
        ),
      ]);
    }

    // Restriction enforcement at the spine, same answer as inline blocks.
    // This also refuses plugins hidden from Layout Builder placement
    // entirely (core's non-functional blocks, the daedalus_layout nested-section
    // container — sections are placed via the section grammar, never as
    // blocks).
    $region = $location['region'] ?? 'content';
    $op_storage = $section_info['storage'] ?? $this->daedalusLayoutAdapter->getSectionStorage($entity);
    if ($failure = $this->checkBlockPlacement($plugin_id, $op_storage, $section_info['section'], $region, 'data.plugin_id')) {
      return $failure;
    }

    /** @var \Drupal\Core\Block\BlockPluginInterface $block_plugin */
    $block_plugin = $this->blockManager->createInstance($plugin_id);
    $configuration = $block_plugin->getConfiguration();
    if ($block_plugin instanceof ContextAwarePluginInterface) {
      $configuration['context_mapping'] = $this->defaultContextMapping($block_plugin, $entity, $section_info['storage'])
        + ($configuration['context_mapping'] ?? []);
    }
    $configuration['label_display'] = '0';
    if (isset($data['label'])) {
      $configuration['label'] = $data['label'];
    }

    $section = $section_info['section'];
    $uuid = $data['uuid'] ?? (new PhpUuid())->generate();

    // Shape-validated by addLbComponent before the plugin-block branch.
    // No context remap at depth: the entity context resolves to the root
    // entity everywhere (containers forward it).
    $component = new SectionComponent($uuid, $region, $configuration, $data['additional'] ?? []);

    $event_targets = $this->dispatchLifecycle(new ElementCreatedEvent($entity, $component, NULL, $this->isDressed()));
    $region_count = count($section->getComponentsByRegion($region));
    $position = isset($location['weight']) ? min((int) $location['weight'], $region_count) : $region_count;
    $section->insertComponent($position, $component);
    $this->bubbleStorageChanges($section_info['storage']);

    return BlueprintResult::success($entity)
      ->withTargets([['uuid' => $uuid, 'field_name' => TargetType::ADDED], ...$event_targets]);
  }

  /**
   * Computes default context mappings for a context-aware block plugin.
   *
   * Port of the resolution the daedalus_layout palette produced through core's
   * form-element defaulting (ContextAwarePluginAssignmentTrait): a slot
   * with exactly one matching required context is assigned it; ambiguous
   * or optional slots stay NULL; an unresolved 'entity' slot falls back to
   * 'layout_builder.entity'. Slots with no matching context get no entry.
   *
   * @param \Drupal\Core\Plugin\ContextAwarePluginInterface $block_plugin
   *   The block plugin.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity, used as the context source when no storage exists.
   * @param object|null $storage
   *   The section storage whose preview contexts to match against, if any.
   *
   * @return array
   *   The context mapping (slot => context ID or NULL).
   */
  protected function defaultContextMapping(ContextAwarePluginInterface $block_plugin, FieldableEntityInterface $entity, ?object $storage): array {
    $available_ids = array_keys($this->contextRepository->getAvailableContexts());
    $contexts = array_filter(
      $this->contextRepository->getRuntimeContexts($available_ids),
      fn($context) => $context->hasContextValue(),
    );
    if ($storage !== NULL && method_exists($storage, 'getContextsDuringPreview')) {
      $contexts += $storage->getContextsDuringPreview();
    }
    else {
      $contexts['layout_builder.entity'] = EntityContext::fromEntity($entity);
    }

    $mapping = [];
    foreach ($block_plugin->getContextDefinitions() as $slot => $definition) {
      $valid = $this->contextHandler->getMatchingContexts($contexts, $definition);
      if (empty($valid)) {
        continue;
      }
      $mapping[$slot] = (count($valid) === 1 && $definition->isRequired())
        ? array_key_first($valid)
        : NULL;
    }
    if (array_key_exists('entity', $mapping) && empty($mapping['entity'])) {
      $mapping['entity'] = 'layout_builder.entity';
    }
    return $mapping;
  }

  /**
   * Populates empty non-base fields of a fresh block with sample values.
   *
   * Parity with the daedalus_layout palette flow: field_sample_value generators
   * when that module is installed, core's generateSampleItems() otherwise.
   * Caller-provided fields are skipped — the field pipeline applies those.
   * Structural field types (REJECTED_FIELD_TYPES) are skipped too: sample
   * content never belongs in a layout field — core's layout_section sample
   * is a hardcoded layout_onecol section that would clobber anything a
   * bundle's own create/presave seed established.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The freshly created block_content entity.
   * @param string[] $provided_fields
   *   Field names the caller supplied explicitly.
   */
  protected function populateSampleValues(FieldableEntityInterface $block, array $provided_fields): void {
    foreach ($block as $field_name => $field) {
      if ($field->getFieldDefinition()->getFieldStorageDefinition()->isBaseField()
        || in_array($field_name, $provided_fields, TRUE)
        || in_array($field->getFieldDefinition()->getType(), self::REJECTED_FIELD_TYPES, TRUE)) {
        continue;
      }
      if ($this->sampleValueGenerator !== NULL) {
        $this->sampleValueGenerator->populateWithSampleValue($field);
      }
      else {
        $field->generateSampleItems();
      }
    }
  }

  /**
   * Resolves a view mode that actually exists for a block_content entity.
   *
   * Core's InlineBlock defaults to 'full', which most block_content bundles
   * don't define; falls back to the bundle's default display mode. Ported
   * from _daedalus_ui_get_view_mode().
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block_content entity.
   * @param string $view_mode
   *   The requested view mode.
   *
   * @return string
   *   The requested mode when its display exists, otherwise 'default'.
   */
  protected function resolveExistingViewMode(FieldableEntityInterface $block, string $view_mode): string {
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    $display = $storage->load("block_content.{$block->bundle()}.$view_mode")
      ?? $storage->load("block_content.{$block->bundle()}.default");
    return $display !== NULL ? $display->getMode() : 'default';
  }

  /**
   * Adds a paragraph entity to an entity_reference_revisions field.
   *
   * Creates a new paragraph entity, applies fields via FieldHandlers,
   * and inserts it at the specified position in the target field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param array $location
   *   Location with field name, optional position, and optional parent_uuid.
   * @param array $data
   *   Paragraph data with type, fields, and optionally uuid.
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result with the modified entity on success.
   */
  protected function addParagraphElement(FieldableEntityInterface $entity, array $location, array $data, array $options = []): BlueprintResult {
    // Find target entity (root, parent paragraph, or one already resolved
    // by the placement grammar).
    $target_entity = $entity;
    if (isset($location['_parent_entity'])) {
      $target_entity = $location['_parent_entity'];
    }
    elseif (isset($location['parent_uuid'])) {
      $context = $this->locateElement($entity, $location['parent_uuid'], 'location.parent_uuid', 'PARENT_NOT_FOUND');
      if ($context instanceof BlueprintResult) {
        return $context;
      }
      $target_entity = $context->target;
    }

    // Validate field exists and is ERR type.
    $target_field_name = $location['field'] ?? NULL;
    if ($target_field_name === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.field',
          code: 'MISSING_FIELD',
          message: 'Field name is required.',
        ),
      ]);
    }

    $target_field_def = $target_entity->getFieldDefinition($target_field_name);
    if ($target_field_def === NULL || $target_field_def->getType() !== 'entity_reference_revisions') {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'location.field',
          code: 'INVALID_FIELD',
          message: "Field '$target_field_name' does not exist or is not an entity reference revisions field.",
          hint: 'Check the field machine name.',
        ),
      ]);
    }

    // Validate paragraph type.
    $type = $data['type'] ?? NULL;
    if ($type === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.type',
          code: 'MISSING_TYPE',
          message: 'Paragraph type is required.',
        ),
      ]);
    }

    $target_type = $target_field_def->getSetting('target_type');
    $bundle_info_data = $this->bundleInfo->getBundleInfo($target_type);
    if (!isset($bundle_info_data[$type])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'data.type',
          code: 'INVALID_TYPE',
          message: "Type '$type' does not exist for entity type '$target_type'.",
          context: ['available_types' => array_keys($bundle_info_data)],
          hint: 'Check the available types in the schema.',
        ),
      ]);
    }

    // Create paragraph entity.
    $create_values = ['type' => $type];
    if (isset($data['uuid'])) {
      $create_values['uuid'] = $data['uuid'];
    }
    $paragraph = $this->entityTypeManager->getStorage($target_type)->create($create_values);

    $paragraph_fields = $data['fields'] ?? [];
    $result = $this->validateAndApplyFields($paragraph, $paragraph_fields, 'fields', $options + [
      'isFullCreate' => TRUE,
    ]);

    $failure = $this->fieldResultToFailure($result);
    if ($failure !== NULL) {
      return $failure;
    }

    // Insert at specified position in the target field.
    $position = $location['position'] ?? NULL;
    $field_list = $target_entity->get($target_field_name);
    $items = [];
    foreach ($field_list as $item) {
      $items[] = ['entity' => $item->entity];
    }

    if ($position !== NULL && (int) $position <= count($items)) {
      array_splice($items, (int) $position, 0, [['entity' => $paragraph]]);
    }
    else {
      $items[] = ['entity' => $paragraph];
    }

    $target_entity->set($target_field_name, $items);
    $uuid = $paragraph->uuid();

    $targets = [['uuid' => $uuid, 'field_name' => TargetType::ADDED]];

    return BlueprintResult::success($entity, $result['warnings'])
      ->withTargets($targets);
  }

  /**
   * Dispatches a single batch operation to the appropriate method.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to operate on.
   * @param array $operation
   *   The operation definition with an 'op' key.
   * @param array $options
   *   Options forwarded to the text-bearing operations (and their field
   *   handlers).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The operation result.
   */
  protected function applyOperation(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult {
    $op = $operation['op'] ?? '';

    // Normalize string-encoded JSON parameters. LLMs frequently double-encode
    // nested values when constructing batch operations.
    $json_parameter_keys = [
      'fields', 'data', 'structure', 'location', 'properties', 'regions',
      'layout_settings', 'layout_builder__layout', 'at', 'attrs', 'remove',
    ];
    foreach ($json_parameter_keys as $key) {
      if (isset($operation[$key]) && is_string($operation[$key])) {
        $decoded = json_decode($operation[$key], TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $operation[$key] = $decoded;
        }
      }
    }

    return match ($op) {
      'update_element' => $this->updateElement(
        $entity,
        $operation['element'] ?? NULL,
        $operation['properties'] ?? [],
        $options,
      ),
      'add_element' => $this->applyAddElement($entity, $operation, $options),
      'add_section' => $this->addSection(
        $entity,
        $operation['location'] ?? [],
        $operation['uuid'] ?? NULL,
        $operation['layout'] ?? NULL,
        $operation['layout_settings'] ?? [],
        $operation['stencil'] ?? NULL,
      ),
      'remove_element' => $this->removeElement(
        $entity,
        $operation['element'] ?? '',
      ),
      'duplicate_element' => $this->duplicateElement(
        $entity,
        $operation['element'] ?? '',
        $operation['uuid'] ?? NULL,
      ),
      'move_element' => $this->moveElement(
        $entity,
        $operation['element'] ?? '',
        $operation['location'] ?? [],
      ),
      'restructure_layout' => $this->restructureLayout(
        $entity,
        $operation['structure'] ?? [],
        $options,
      ),
      'reorder_field' => $this->reorderField(
        $entity,
        $operation['structure'] ?? [],
      ),
      default => $this->applyRegisteredOperation($entity, $op, $operation, $options),
    };
  }

  /**
   * Dispatches an operation to the registered operation handlers.
   *
   * Fallback for operation names not handled by applyOperation()'s built-in
   * match, checked before failing with INVALID_OPERATION.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to operate on.
   * @param string $op
   *   The operation name.
   * @param array $operation
   *   The full operation definition.
   * @param array $options
   *   The request-scoped options batchOperations() received, forwarded to
   *   the handler (OperationHandlerInterface::apply()).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The handler's result, or an INVALID_OPERATION structural failure when
   *   no handler provides the operation.
   */
  protected function applyRegisteredOperation(FieldableEntityInterface $entity, string $op, array $operation, array $options = []): BlueprintResult {
    $handler = $this->operationHandlerRegistry->getHandler($op);
    if ($handler !== NULL) {
      return $handler->apply($entity, $operation, $options);
    }

    if (isset(self::RETIRED_GRAMMAR[$op])) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: 'op',
          code: 'INVALID_OPERATION',
          message: "Operation '$op' is retired.",
          context: [
            'valid_operations' => [
              ...self::BUILTIN_OPERATIONS,
              ...$this->operationHandlerRegistry->getOperationNames(),
            ],
          ],
          hint: self::RETIRED_GRAMMAR[$op],
        ),
      ]);
    }

    return BlueprintResult::structuralFailure([
      new BlueprintError(
        path: 'op',
        code: 'INVALID_OPERATION',
        message: "Unknown operation type '$op'.",
        context: [
          'valid_operations' => [
            ...self::BUILTIN_OPERATIONS,
            ...$this->operationHandlerRegistry->getOperationNames(),
          ],
        ],
        hint: 'Check the operation type spelling.',
      ),
    ]);
  }

  /**
   * Applies an add_element batch operation.
   *
   * Maps the element format (block_type or plugin_id, the anchor-grammar
   * location, properties, populate, additional) to the internal
   * addElement format. The retired nested-layout keys (section_uuid,
   * layout_builder__layout) still pass through so addLbComponent can refuse
   * them with a pointer at the one placement grammar.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to operate on.
   * @param array $operation
   *   The operation definition.
   * @param array $options
   *   Deserialization options forwarded to field handlers and operation
   *   handlers.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The operation result.
   */
  protected function applyAddElement(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult {
    $data = [
      'block_type' => $operation['block_type'] ?? NULL,
      'block_fields' => $operation['properties'] ?? [],
    ];
    foreach (['plugin_id', 'uuid', 'populate', 'additional', 'section_uuid', 'layout_builder__layout'] as $key) {
      if (isset($operation[$key])) {
        $data[$key] = $operation[$key];
      }
    }

    return $this->addElement($entity, $operation['location'] ?? [], $data, $options);
  }

  /**
   * Emits each shared hint once per batch; later errors reference it.
   *
   * Errors marked with BlueprintError::SHARED_HINT carry a corrective
   * payload (e.g. a stylable-property roster) that is identical for every
   * error with the same key. Repeating it per error multiplies the repair
   * round's token cost for one fact, so the first error in batch order
   * keeps the payload and every later one is rewritten to point at it,
   * its duplicated context payload dropped (its own hint kept).
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The batch's errors, in operation order.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors with shared hints deduplicated.
   */
  protected function dedupeSharedHints(array $errors): array {
    $first_by_key = [];
    return array_map(
      static function (BlueprintError $error) use (&$first_by_key): BlueprintError {
        $key = $error->getSharedHintKey();
        if ($key === NULL) {
          return $error;
        }
        $first = $first_by_key[$key] ?? NULL;
        if ($first === NULL) {
          $first_by_key[$key] = $error;
          return $error;
        }
        // The reference names the code only: a translated batch (the
        // dialect door) rewrites indexes and paths into the author's terms
        // after this pass, so any location spelled here would go stale.
        // The error's own corrective (its did-you-mean) stays; only the
        // shared payload is dropped.
        return $error->withSharedHintReference(trim(sprintf(
          '%s Same as the first %s error above: the allowed list given there applies here too.',
          $error->getHint(),
          $first->getCode(),
        )));
      },
      $errors,
    );
  }

  /**
   * Tags error objects with a batch operation index.
   *
   * Creates new BlueprintError instances with the operationIndex set.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The original errors.
   * @param int $operation_index
   *   The operation index to tag with.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   New errors with operationIndex set.
   */
  protected function tagErrorsWithIndex(array $errors, int $operation_index): array {
    return array_map(
      fn(BlueprintError $error) => $error->withOperationIndex($operation_index),
      $errors,
    );
  }

  /**
   * Collects the identity a batch operation would mint.
   *
   * Every 'uuid' key anywhere in the operation is writer-minted identity
   * (component, section, clone, or nested subtree node); when the operation
   * fails, none of it comes to exist, and later operations referencing it
   * fail for that reason alone. reorder_field is the one grammar where
   * 'uuid' keys REFERENCE existing items instead of minting, so it is
   * excluded rather than tainting live identity.
   *
   * A restructure_layout definition can also carry the uuid of an existing
   * section it re-declares; a failed restructure taints it deliberately —
   * later results against a layout the writer meant to reshape are just as
   * untrustworthy, and a suppressed real error simply resurfaces on the
   * next round's full re-evaluation.
   *
   * @param array $operation
   *   The operation definition.
   *
   * @return string[]
   *   The UUIDs the operation would have minted.
   */
  protected function mintedUuids(array $operation): array {
    if (($operation['op'] ?? '') === 'reorder_field') {
      return [];
    }

    $minted = [];
    array_walk_recursive($operation, static function ($value, $key) use (&$minted): void {
      if ($key === 'uuid' && is_string($value) && $value !== '') {
        $minted[] = $value;
      }
    });
    return $minted;
  }

  /**
   * Determines whether an operation references tainted minted identity.
   *
   * Matches every string leaf against the tainted set by substring, because
   * references appear both as bare uuid values (element, anchors, region
   * orderings) and embedded in composite keys (a style key like
   * "block:<uuid>" or "block:<uuid>:field_name").
   *
   * @param array $operation
   *   The operation definition.
   * @param string[] $tainted_uuids
   *   Identity minted by already-failed operations in this batch.
   *
   * @return bool
   *   TRUE when any string value in the operation contains a tainted uuid.
   */
  protected function referencesTaintedUuid(array $operation, array $tainted_uuids): bool {
    if (empty($tainted_uuids)) {
      return FALSE;
    }

    $found = FALSE;
    array_walk_recursive($operation, static function ($value) use ($tainted_uuids, &$found): void {
      if ($found || !is_string($value)) {
        return;
      }
      foreach ($tainted_uuids as $uuid) {
        if (str_contains($value, $uuid)) {
          $found = TRUE;
          return;
        }
      }
    });
    return $found;
  }

  /**
   * Captures entity state for rollback.
   *
   * Snapshots layout sections, field values, and paragraph entity state
   * so the entity can be restored if a batch operation fails.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to snapshot.
   *
   * @return array
   *   The snapshot data.
   */
  protected function snapshotEntity(FieldableEntityInterface $entity): array {
    $snapshot = [
      'layout' => NULL,
      'fields' => [],
      'paragraphs' => [],
    ];

    // Snapshot layout sections via Section::toArray().
    if ($entity->hasField('layout_builder__layout')) {
      $snapshot['layout'] = [];
      foreach ($entity->get('layout_builder__layout') as $item) {
        $snapshot['layout'][] = $item->section->toArray();
      }
    }

    // Snapshot field values.
    foreach ($entity->getFieldDefinitions() as $name => $def) {
      if ($name === 'layout_builder__layout') {
        continue;
      }
      if ($def->getType() === 'entity_reference_revisions') {
        // Save ERR field items as entity references and snapshot
        // paragraph entity field values for rollback.
        $items = [];
        foreach ($entity->get($name) as $item) {
          if ($item->entity) {
            $items[] = ['entity' => $item->entity];
            $this->snapshotParagraphRecursive(
              $item->entity,
              $snapshot['paragraphs'],
            );
          }
        }
        $snapshot['fields'][$name] = ['type' => 'err', 'items' => $items];
      }
      else {
        $snapshot['fields'][$name] = [
          'type' => 'value',
          'value' => $entity->get($name)->getValue(),
        ];
      }
    }

    return $snapshot;
  }

  /**
   * Recursively snapshots paragraph entity field values.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $paragraph
   *   The paragraph entity.
   * @param array $store
   *   The paragraph snapshot store (UUID => data), passed by reference.
   */
  protected function snapshotParagraphRecursive(FieldableEntityInterface $paragraph, array &$store): void {
    $uuid = $paragraph->uuid();
    if (isset($store[$uuid])) {
      return;
    }

    $fields = [];
    foreach ($paragraph->getFieldDefinitions() as $name => $def) {
      $fields[$name] = $paragraph->get($name)->getValue();

      // Recurse into nested ERR fields.
      if ($def->getType() === 'entity_reference_revisions') {
        foreach ($paragraph->get($name) as $item) {
          if ($item->entity) {
            $this->snapshotParagraphRecursive($item->entity, $store);
          }
        }
      }
    }

    $store[$uuid] = ['entity' => $paragraph, 'fields' => $fields];
  }

  /**
   * Restores entity state from a snapshot.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to restore.
   * @param array $snapshot
   *   The snapshot data from snapshotEntity().
   */
  protected function restoreEntity(FieldableEntityInterface $entity, array $snapshot): void {
    // Restore layout sections.
    if ($snapshot['layout'] !== NULL && $entity->hasField('layout_builder__layout')) {
      $values = [];
      foreach ($snapshot['layout'] as $section_array) {
        $values[] = ['section' => Section::fromArray($section_array)];
      }
      $entity->set('layout_builder__layout', $values);

      // Reset block_content static cache so that block_revision_id loads
      // return the original (unmodified) entities from the database,
      // not the in-memory copies that were mutated during batch operations.
      if ($this->entityTypeManager->hasDefinition('block_content')) {
        $this->entityTypeManager->getStorage('block_content')->resetCache();
      }
    }

    // Restore field values.
    foreach ($snapshot['fields'] as $name => $field_data) {
      if ($field_data['type'] === 'err') {
        $entity->set($name, $field_data['items']);
      }
      else {
        $entity->set($name, $field_data['value']);
      }
    }

    // Restore paragraph entity field values (undo in-place modifications).
    foreach ($snapshot['paragraphs'] as $data) {
      $paragraph = $data['entity'];
      foreach ($data['fields'] as $name => $value) {
        $paragraph->set($name, $value);
      }
    }
  }

}
