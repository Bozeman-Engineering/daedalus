<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Exception\BlueprintException;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\AvailableLayouts;
use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface;

/**
 * Builds descriptive schemas for entity type + bundle combinations.
 *
 * Introspects Drupal's entity/field type system using
 * FieldHandlerManager::inspectFieldDefinition() and transforms the result
 * into a clean, AI-friendly schema format. Results are cached per entity
 * type + bundle.
 */
class BlueprintSchemaBuilder implements BlueprintSchemaBuilderInterface {

  /**
   * Field types that use inline entity serialization.
   *
   * @var string[]
   */
  protected const INLINE_ENTITY_FIELD_TYPES = [
    'entity_reference_revisions',
  ];

  /**
   * Constructs a BlueprintSchemaBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\daedalus_blueprint\ManagedKeysRegistryInterface $managedKeysRegistry
   *   The managed keys registry.
   * @param \Drupal\daedalus_blueprint\BlueprintContextResolverInterface $contextResolver
   *   The context resolver.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface $placementRestrictions
   *   The placement-restriction spine: the schema's available_layouts and
   *   available_block_types are projections of the same answer the ops
   *   enforce.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The access checker, used to drop fields the user cannot access from the
   *   advertised schema.
   * @param \Drupal\Core\Cache\Context\CacheContextsManager $cacheContextsManager
   *   The cache contexts manager, used to vary the schema cache by the current
   *   user's permissions (the schema's field set is access-filtered per user).
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, for the schema alter seam (see
   *   hook_daedalus_blueprint_schema_alter()).
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutPluginManager
   *   The layout plugin manager (NULL when layout_discovery is not installed).
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FieldHandlerManager $fieldHandlerManager,
    protected CacheBackendInterface $cache,
    protected ManagedKeysRegistryInterface $managedKeysRegistry,
    protected BlueprintContextResolverInterface $contextResolver,
    protected AccountProxyInterface $currentUser,
    protected PlacementRestrictionsInterface $placementRestrictions,
    protected BlueprintAccessChecker $accessChecker,
    protected CacheContextsManager $cacheContextsManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?LayoutPluginManagerInterface $layoutPluginManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $entity_type, string $bundle, ?string $view_mode = 'full'): array {
    $view_mode = $view_mode ?: 'full';

    $this->validateEntityType($entity_type);
    $bundle_label = $this->validateBundle($entity_type, $bundle);
    $this->checkAccess($entity_type, $bundle);

    // The schema is access-filtered per user (fields the user cannot edit are
    // dropped), so vary the cache by the current user's permissions hash —
    // users with identical permissions share an entry, and any permission
    // change re-derives it. Reuses core's user.permissions cache context.
    $permission_keys = $this->cacheContextsManager
      ->convertTokensToKeys(['user.permissions'])
      ->getKeys();
    $cache_id = 'daedalus_blueprint:schema:' . implode(':', array_merge(
      [$entity_type, $bundle, $view_mode],
      $permission_keys,
    ));
    $cached = $this->cache->get($cache_id);
    if ($cached) {
      return $cached->data;
    }

    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    $processable_fields = $this->fieldHandlerManager->filterFieldDefinitions($entity_type_definition, $field_definitions, $bundle);
    $processable_fields = $this->filterAccessibleFields($processable_fields);

    $fields = [];
    foreach ($processable_fields as $field_name => $definition) {
      $fields[$field_name] = $this->buildFieldSchema($definition);
    }

    $schema = [
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $bundle_label,
      '_structure' => 'Place all entity field values inside the "fields" object — never at the top level. "layout_builder__layout" is the one exception: a separate top-level key, not inside "fields".',
      'fields' => $fields,
    ];

    // Add Layout Builder schema if LB is enabled for this bundle.
    $layout_builder_schema = $this->buildLayoutBuilderSchema($entity_type, $bundle, $view_mode);
    if ($layout_builder_schema !== NULL) {
      $schema['layout_builder__layout'] = $layout_builder_schema;
    }

    $context = $this->contextResolver->getContext($entity_type, $bundle);
    $this->applyContext($schema, $context);

    $cache_tags = [
      'entity_field_info',
      "config:core.entity_view_display.{$entity_type}.{$bundle}.default",
    ];
    if ($view_mode !== 'default') {
      $cache_tags[] = "config:core.entity_view_display.{$entity_type}.{$bundle}.{$view_mode}";
    }
    // Context feeders whose data derives from their own invalidation domain
    // (e.g. daedalus_ui's layered declarations) declare it via the
    // reserved cache_tags key, so this cache rebuilds when their source does.
    $cache_tags = array_merge($cache_tags, $context['cache_tags'] ?? []);

    // The schema alter seam: contributors of whole capability sections
    // (the materials fragment from the roster, Hook\MaterialsSchemaHooks;
    // daedalus_stylish's minting-gate advertisement on it) extend the schema here,
    // declaring their own invalidation domain through the alter context's
    // cache_tags. Runs after the context merge so alters see the final
    // built schema.
    $alter_context = [
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'view_mode' => $view_mode,
      'cache_tags' => [],
    ];
    $this->moduleHandler->alter('daedalus_blueprint_schema', $schema, $alter_context);
    $cache_tags = array_merge($cache_tags, $alter_context['cache_tags']);
    $this->cache->set($cache_id, $schema, CacheBackendInterface::CACHE_PERMANENT, $cache_tags);

    return $schema;
  }

  /**
   * Applies context (guidance, defaults, anatomy) to the built schema.
   *
   * @param array &$schema
   *   The schema array to modify.
   * @param array $context
   *   The context array populated by hook_daedalus_blueprint_context_alter().
   */
  protected function applyContext(array &$schema, array $context): void {
    if (empty($context)) {
      return;
    }

    // Entity-level context.
    $this->mergeContextKeys($schema, $context);

    // Field type guidance (override or supplement handler-provided guidance).
    // Distributes to each field of the matching type inline, wherever a
    // field schema appears: the root fields and every block type's fields.
    if (!empty($context['field_types'])) {
      if (!empty($schema['fields'])) {
        $this->applyFieldTypeGuidance($schema['fields'], $context['field_types']);
      }
      if (!empty($schema['layout_builder__layout']['available_block_types'])) {
        foreach ($schema['layout_builder__layout']['available_block_types'] as &$block_type_schema) {
          if (!empty($block_type_schema['block_fields'])) {
            $this->applyFieldTypeGuidance($block_type_schema['block_fields'], $context['field_types']);
          }
        }
        unset($block_type_schema);
      }
    }

    // Block type context.
    if (!empty($context['block_types']) && isset($schema['layout_builder__layout']['available_block_types'])) {
      foreach ($context['block_types'] as $block_type => $block_context) {
        if (isset($schema['layout_builder__layout']['available_block_types'][$block_type])) {
          $this->mergeContextKeys($schema['layout_builder__layout']['available_block_types'][$block_type], $block_context);
        }
      }
    }

    // Layout context.
    if (!empty($context['layouts']) && isset($schema['layout_builder__layout']['available_layouts'])) {
      foreach ($schema['layout_builder__layout']['available_layouts'] as &$layout) {
        $layout_id = $layout['id'] ?? NULL;
        if ($layout_id && isset($context['layouts'][$layout_id])) {
          $this->mergeContextKeys($layout, $context['layouts'][$layout_id]);
        }
      }
    }
  }

  /**
   * Applies context field-type guidance inline to a map of field schemas.
   *
   * @param array &$fields
   *   Field schemas keyed by field name, each carrying a 'type'.
   * @param array $field_types
   *   The context's field_types map: field type => ['guidance' => string].
   */
  private function applyFieldTypeGuidance(array &$fields, array $field_types): void {
    foreach ($fields as &$field_schema) {
      $field_type = $field_schema['type'] ?? NULL;
      if ($field_type && isset($field_types[$field_type]['guidance'])) {
        $field_schema['guidance'] = $field_types[$field_type]['guidance'];
      }
    }
  }

  /**
   * Merges the schema-facing context keys from a source into a schema target.
   *
   * The mergeable keys are guidance, defaults, and anatomy (the element
   * model's markup-shell fragment, fed by daedalus_ui when it is
   * installed). Reserved bookkeeping keys (cache_tags) never reach the
   * schema.
   *
   * @param array &$target
   *   The schema element to merge into.
   * @param array $source
   *   The context source containing optional 'guidance', 'defaults', and
   *   'anatomy' keys.
   */
  private function mergeContextKeys(array &$target, array $source): void {
    if (isset($source['guidance'])) {
      $target['guidance'] = $source['guidance'];
    }
    if (isset($source['defaults']) && is_array($source['defaults'])) {
      $target['defaults'] = $source['defaults'];
    }
    if (isset($source['anatomy']) && is_array($source['anatomy'])) {
      $target['anatomy'] = $source['anatomy'];
    }
  }

  /**
   * Filters field definitions to those the current user may edit.
   *
   * Drops a field only when its edit access is definitively forbidden at the
   * definition level, so the advertised schema never invites the AI to write a
   * field the current user cannot edit (which would only earn a rejected
   * write). The schema is write-guidance; read-side view filtering happens
   * during serialization, not here. Fail-open semantics: a field is kept unless
   * access is forbidden, because definition-level field access (no entity
   * instance) is frequently neutral.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $definitions
   *   The processable field definitions, keyed by field name.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   The subset the current user may edit.
   */
  protected function filterAccessibleFields(array $definitions): array {
    return array_filter(
      $definitions,
      fn(FieldDefinitionInterface $definition) => $this->accessChecker->fieldDefinitionAccess($definition, 'edit'),
    );
  }

  /**
   * Builds the schema for a single field definition.
   *
   * Transforms the raw inspectFieldDefinition() output into a clean,
   * AI-friendly format with flattened settings.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array
   *   The field schema.
   */
  protected function buildFieldSchema(FieldDefinitionInterface $definition): array {
    $info = $this->fieldHandlerManager->inspectFieldDefinition($definition);

    $schema = [
      'type' => $info['type'],
      'label' => $info['label'],
      'required' => $info['required'],
    ];

    // Only include cardinality when not single-value.
    if ($info['cardinality'] !== 1) {
      $schema['cardinality'] = $info['cardinality'];
    }

    // Inline entity fields get recursive bundle schemas.
    if (in_array($info['type'], static::INLINE_ENTITY_FIELD_TYPES, TRUE)) {
      return $this->buildInlineEntityFieldSchema($schema, $info);
    }

    // Flatten relevant settings to top level.
    if (!empty($info['settings'])) {
      $schema += $info['settings'];
    }

    // Include properties for compound fields.
    if (!empty($info['properties'])) {
      $schema['properties'] = $info['properties'];
    }

    // Include handler-provided schema additions (e.g., available_formats).
    $handler = $this->fieldHandlerManager->getHandler($definition);
    $additions = $handler->getSchemaAdditions($definition);
    if (!empty($additions)) {
      $schema += $additions;
    }

    // Include handler-provided guidance inline with the field, and the
    // generation vocabulary a media-minting handler states (the page
    // language projects its generate and sizing attributes from it).
    if (!empty($info['guidance'])) {
      $schema['guidance'] = $info['guidance'];
    }
    if (!empty($info['generation'])) {
      $schema['generation'] = $info['generation'];
    }

    return $schema;
  }

  /**
   * Builds schema for an inline entity field with recursive bundle schemas.
   *
   * For each allowed bundle, recursively generates full field schemas so the
   * AI knows exactly what fields each inline entity type has.
   *
   * @param array $schema
   *   The base schema with type, label, required, cardinality.
   * @param array $info
   *   Field introspection info from inspectFieldDefinition().
   *
   * @return array
   *   The complete inline entity field schema.
   */
  protected function buildInlineEntityFieldSchema(array $schema, array $info): array {
    $schema['strategy'] = 'inline';

    $target_type = $info['settings']['target_type'] ?? NULL;
    if ($target_type) {
      $schema['target_type'] = $target_type;
    }

    $target_bundles = $info['settings']['target_bundles'] ?? NULL;
    if (!$target_type) {
      return $schema;
    }

    // Get all bundles for the target type.
    $all_bundles = $this->bundleInfo->getBundleInfo($target_type);
    $bundles_to_describe = $target_bundles ?: array_keys($all_bundles);

    $entity_type_definition = $this->entityTypeManager->getDefinition($target_type);

    $bundle_schemas = [];
    foreach ($bundles_to_describe as $bundle_name) {
      if (!isset($all_bundles[$bundle_name])) {
        continue;
      }

      $bundle_label = (string) $all_bundles[$bundle_name]['label'];
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($target_type, $bundle_name);
      $processable_fields = $this->fieldHandlerManager->filterFieldDefinitions($entity_type_definition, $field_definitions, $bundle_name);
      $processable_fields = $this->filterAccessibleFields($processable_fields);

      $fields = [];
      foreach ($processable_fields as $field_name => $field_definition) {
        $fields[$field_name] = $this->buildFieldSchema($field_definition);
      }

      $bundle_schemas[$bundle_name] = [
        'label' => $bundle_label,
        'fields' => $fields,
      ];
    }

    if (!empty($bundle_schemas)) {
      $schema['target_bundles'] = $bundle_schemas;
    }

    return $schema;
  }

  /**
   * Validates that the entity type exists and is fieldable.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\BlueprintException
   *   If the entity type does not exist or is not fieldable.
   */
  protected function validateEntityType(string $entity_type): void {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      throw new BlueprintException("Entity type '$entity_type' does not exist.");
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type);
    if (!$definition->entityClassImplements(FieldableEntityInterface::class)) {
      throw new BlueprintException("Entity type '$entity_type' is not fieldable.");
    }
  }

  /**
   * Validates that the bundle exists for the entity type.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return string
   *   The bundle label.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\BlueprintException
   *   If the bundle does not exist.
   */
  protected function validateBundle(string $entity_type, string $bundle): string {
    $bundles = $this->bundleInfo->getBundleInfo($entity_type);
    if (!isset($bundles[$bundle])) {
      $available = implode(', ', array_keys($bundles));
      throw new BlueprintException("Bundle '$bundle' does not exist on entity type '$entity_type'. Available: $available.");
    }

    return (string) $bundles[$bundle]['label'];
  }

  /**
   * Checks that the current user has create access for the bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\BlueprintException
   *   If the current user does not have create access.
   */
  protected function checkAccess(string $entity_type, string $bundle): void {
    $access_handler = $this->entityTypeManager->getAccessControlHandler($entity_type);
    if (!$access_handler->createAccess($bundle, $this->currentUser)) {
      throw new BlueprintException("Access denied: you do not have permission to create '$bundle' entities of type '$entity_type'.");
    }
  }

  /**
   * Builds the Layout Builder schema section.
   *
   * Returns NULL if Layout Builder is not enabled for this bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $view_mode
   *   The view mode for block type restriction filtering.
   *
   * @return array|null
   *   The layout_builder schema or NULL.
   */
  protected function buildLayoutBuilderSchema(string $entity_type, string $bundle, string $view_mode = 'full'): ?array {
    // Check if this entity type + bundle has a layout_builder__layout field.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    if (!isset($field_definitions['layout_builder__layout'])) {
      return NULL;
    }

    $managed_keys = $this->managedKeysRegistry->getManagedKeys($entity_type, $bundle);

    // Node-shape FACTS only (schema = facts, skills = mechanics): the
    // container doctrine (default to the container layout, arrangement is
    // styling, grouping is nesting) lives in the create_entity skill for
    // the blueprint door and in the eb_edit op grammar for the op door;
    // nesting capability is carried by the children/regions facts
    // themselves (an entry with layout_id is a nested section, recursive;
    // blocks never hold layouts, stated on add_element).
    $schema = [
      'guidance' => 'Array of sections in display order.',
      'section_structure' => [
        'layout_id' => 'Layout plugin ID from available_layouts.',
        'uuid' => 'Optional. Section UUID for targeting in operations.',
        'label' => 'Optional. The section\'s user-facing name (defaults from the layout until renamed).',
        'layout_settings' => 'Optional. Only managed keys for this layout plugin (structural settings, not styling). Omitted keys keep their current values; an explicit null clears a key.',
        'children' => 'Required for container layouts (available_layouts entries marked container: true): ONE ordered array of blocks AND nested sections — container sections have no addressable regions. Never combine with regions.',
        'regions' => 'Required for multi-region layouts only. Object keyed by region name. Each value is an array of blocks AND nested sections in display order (an entry with layout_id is a nested section, same structure, recursive).',
      ],
      'block_structure' => [
        'uuid' => 'Optional for new blocks, required for updates.',
        'block_type' => 'Required. Block content type from available_block_types.',
        'label' => 'Optional. Block display label (e.g. heading text).',
        'view_mode' => 'Optional. Defaults to "full".',
        'block_fields' => 'Required. Field values for this block type.',
        'configuration' => 'Optional. Only managed keys for this block type. Omitted keys keep their current values; an explicit null clears a key.',
      ],
    ];

    // Both advertised vocabularies are filtered projections of the
    // placement-restriction spine, so the schema can never offer what the
    // ops would refuse. The defaults storage carries the governing display
    // (per-region restrictions stay op-time answers — the schema is
    // region-agnostic).
    $storage = $this->placementRestrictions->defaultsStorage($entity_type, $bundle, $view_mode);

    if ($this->layoutPluginManager !== NULL) {
      $schema['available_layouts'] = $this->buildAvailableLayouts($managed_keys['section_layout_settings'] ?? [], $storage);
    }

    $schema['available_block_types'] = $this->buildAvailableBlockTypes(
      $managed_keys['inline_block_configuration'] ?? [],
      $storage,
    );

    return $schema;
  }

  /**
   * Builds the available layouts schema.
   *
   * @param array $layout_settings_keys
   *   Managed layout settings keys, keyed by layout plugin ID.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return array
   *   Array of layout definitions with id and label; a container layout
   *   carries `container: true` and nothing about regions, a multi-region
   *   layout carries regions, default_region, and its spatial
   *   columns/arrangement when it declares an icon map. Either kind carries
   *   layout_settings (managed keys for this layout) when it has any.
   */
  protected function buildAvailableLayouts(array $layout_settings_keys, ?object $storage): array {
    $layouts = [];
    foreach ($this->placementRestrictions->allowedLayoutDefinitions($storage) as $id => $definition) {
      $layout = [
        'id' => $id,
        'label' => (string) $definition->getLabel(),
      ];

      if (ContainerLayout::isContainer($definition)) {
        // A container is one ordered flow of children with no addressable
        // regions (its sole region is a storage vestige), so its entry
        // states the container fact alone: region names, a default region,
        // and a one-column arrangement would each invite a writer to reach
        // for the regions node shape the section_structure forbids.
        $layout['container'] = TRUE;
      }
      else {
        $layout['default_region'] = $definition->getDefaultRegion();
        $layout['regions'] = $definition->getRegionNames();

        // Surface the layout's own spatial sketch so a writer can choose a
        // layout by shape ("two columns") instead of decoding machine
        // names. The icon map is rows of region names: rows stack
        // top-to-bottom, regions within a row sit side by side, and a
        // region repeated within a row spans those columns. Column count is
        // the widest row.
        $columns = AvailableLayouts::columns($definition);
        if ($columns !== NULL) {
          $layout['columns'] = $columns;
          $layout['arrangement'] = $definition->getIconMap();
        }
      }

      $settings = $layout_settings_keys[$id] ?? [];
      if (!empty($settings)) {
        $layout['layout_settings'] = $settings;
      }

      $layouts[] = $layout;
    }
    return $layouts;
  }

  /**
   * Builds the available block content types schema with field definitions.
   *
   * @param array $inline_block_config
   *   Managed inline block configuration keys, keyed by block type.
   * @param object|null $storage
   *   The defaults section storage governing restriction answers, or NULL.
   *
   * @return array
   *   Block types keyed by machine name with label and field schemas.
   */
  protected function buildAvailableBlockTypes(array $inline_block_config, ?object $storage): array {
    $bundles = $this->bundleInfo->getBundleInfo('block_content');
    if (empty($bundles)) {
      return [];
    }

    // Filter by the placement-restriction spine before building schemas to
    // avoid expensive field introspection for block types that will be
    // excluded.
    $allowed_bundles = $this->placementRestrictions->allowedInlineBlockBundles($storage);
    $bundles = array_intersect_key($bundles, array_flip($allowed_bundles));

    $entity_type_definition = $this->entityTypeManager->getDefinition('block_content');

    $block_types = [];
    foreach ($bundles as $bundle_name => $bundle_info) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('block_content', $bundle_name);
      $processable_fields = $this->fieldHandlerManager->filterFieldDefinitions($entity_type_definition, $field_definitions, $bundle_name);
      $processable_fields = $this->filterAccessibleFields($processable_fields);

      $fields = [];
      foreach ($processable_fields as $field_name => $field_definition) {
        $fields[$field_name] = $this->buildFieldSchema($field_definition);
      }

      $block_type_schema = [
        'label' => (string) $bundle_info['label'],
        'block_fields' => $fields,
      ];

      // Include managed configuration keys for this block type.
      $type_config = $inline_block_config[$bundle_name] ?? [];
      if (!empty($type_config)) {
        $block_type_schema['configuration'] = $type_config;
      }

      $block_types[$bundle_name] = $block_type_schema;
    }

    return $block_types;
  }

}
