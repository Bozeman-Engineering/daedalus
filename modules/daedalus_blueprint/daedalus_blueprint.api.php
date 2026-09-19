<?php

/**
 * @file
 * Hooks provided by the Daedalus Blueprint module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Declaring managed keys and context metadata from YAML files.
 *
 * @section yaml_discovery YAML file discovery
 *
 * Daedalus Blueprint supports YAML file discovery for declaring managed keys
 * and context metadata without writing PHP. YAML files provide the base data
 * layer; hooks run on top as an alter mechanism (existing behavior preserved).
 *
 * Use YAML when your declaration is purely static data. Use hooks when you
 * need conditional logic, computed values, or access to the $entity_type
 * and $bundle parameters.
 *
 * @subsection yaml_managed_keys Managed keys: MODULE.daedalus_blueprint.managed_keys.yml
 *
 * Structure mirrors the PHP array passed to
 * hook_daedalus_blueprint_managed_keys_alter():
 *
 * @code
 * # mymodule.daedalus_blueprint.managed_keys.yml
 * inline_block_configuration:
 *   slideshow:
 *     label:
 *       label: 'Slideshow label'
 *       description: 'The administrative label for the slideshow.'
 *       schema:
 *         type: string
 *     label_display:
 *       label: 'Label display'
 *       description: 'Whether to display the label above the slideshow.'
 *       schema:
 *         type: string
 *         enum: ['0', 'visible']
 * @endcode
 *
 * @subsection yaml_context Context: MODULE.daedalus_blueprint.context.yml
 *
 * @code
 * # mymodule.daedalus_blueprint.context.yml
 * layouts:
 *   layout_twocol_section:
 *     guidance: 'Two named columns; use only when named slots are required.'
 *     defaults:
 *       layout_settings:
 *         column_widths: 50-50
 * block_types:
 *   text:
 *     guidance: 'Text block guidance text.'
 * @endcode
 *
 * The full key vocabulary each level recognizes (guidance, defaults,
 * anatomy, and the reserved cache_tags bookkeeping key) is documented on
 * hook_daedalus_blueprint_context_alter() below.
 *
 * @subsection yaml_precedence Precedence
 *
 * Three layers are merged in order (lowest to highest priority):
 * 1. Universal YAML (MODULE.daedalus_blueprint.{type}.yml)
 * 2. Scoped YAML (MODULE.daedalus_blueprint.{type}.ENTITY_TYPE.BUNDLE.yml)
 * 3. Hooks (existing alter behavior — always has final say)
 *
 * Each layer uses array_replace_recursive() rather than
 * NestedArray::mergeDeep() because mergeDeep converts conflicting scalar
 * values into arrays (e.g., two modules declaring the same 'label' string
 * would produce ['foo', 'bar'] instead of 'bar'). array_replace_recursive
 * replaces scalars at each leaf, giving clean "last module wins" behavior.
 *
 * @subsection yaml_scoping Entity_type/bundle scoping
 *
 * Universal YAML files apply to all entity types and bundles. For
 * bundle-specific declarations, use a scoped filename:
 *
 * @code
 * # Universal — applies to all entity types and bundles:
 * mymodule.daedalus_blueprint.context.yml
 * mymodule.daedalus_blueprint.managed_keys.yml
 *
 * # Scoped — applies only to node/landing_page:
 * mymodule.daedalus_blueprint.context.node.landing_page.yml
 * mymodule.daedalus_blueprint.managed_keys.node.landing_page.yml
 * @endcode
 *
 * Scoped YAML files use the same internal structure as universal files.
 * The entity_type and bundle are encoded in the filename, not in the YAML
 * content. Use scoped files for static, bundle-specific data. Use hooks
 * when you need conditional logic or computed values.
 */

/**
 * Alters the reference strategy for entity reference fields.
 *
 * By default, entity_reference fields use the "shared" strategy (lookup by
 * target_id or _label) and entity_reference_revisions fields use the "inline"
 * strategy (full entity serialized/deserialized in place). This hook allows
 * modules to override the strategy for specific fields.
 *
 * @param string &$strategy
 *   The reference strategy. One of 'shared' or 'inline'. Modify this value
 *   to change the strategy.
 * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
 *   The field definition being processed.
 * @param string $entity_type
 *   The entity type ID of the host entity.
 * @param string $bundle
 *   The bundle of the host entity.
 */
function hook_daedalus_blueprint_reference_strategy_alter(string &$strategy, \Drupal\Core\Field\FieldDefinitionInterface $field_definition, string $entity_type, string $bundle): void {
  // Example: treat a specific entity_reference field as inline.
  if ($field_definition->getName() === 'field_owned_items') {
    $strategy = 'inline';
  }
}

/**
 * Declares opaque Layout Builder settings keys as AI-managed.
 *
 * Keys declared here are:
 * - Included in serialized output (so the AI sees them).
 * - Included in schema output (so the AI knows what they are).
 * - Patched during deserialization with the same contract as fields: a
 *   declared value is used, an explicit empty value (NULL, '', []) clears
 *   the key, and an omitted key keeps its previous value.
 *
 * All undeclared opaque keys are preserved from the previous state
 * automatically and never exposed to the AI.
 *
 * Available scopes:
 * - section_layout_settings: Settings keys defined within layout_settings
 *   on Section objects, organized under their respective layout plugin ID.
 *   Each layout plugin defines the specific settings keys it manages (e.g.
 *   a two-column layout may expose 'column_widths'; these are structural
 *   settings, not styling). During serialization and
 *   deserialization, the correct sub-array is selected by the section's
 *   layout_id. In schema output, these are inlined into each layout entry
 *   under available_layouts.
 * - inline_block_configuration: Keys within the InlineBlock plugin
 *   configuration, nested by block content type. During serialization,
 *   managed keys are output in a "configuration" object on each inline block
 *   component. During deserialization, they are merged via OpaqueDataMerger.
 *   In schema output, these are inlined into each block type entry under
 *   available_block_types.
 *
 * Each managed key definition should include:
 * - label: Human-readable label for AI context.
 * - description: What the key controls.
 * - schema: JSON Schema-like type description for AI guidance.
 *
 * @param array &$managed_keys
 *   Keyed by scope, each containing an associative array of key name =>
 *   definition. Modify to add or remove managed keys.
 * @param string $entity_type
 *   The entity type being processed.
 * @param string $bundle
 *   The bundle being processed.
 */
function hook_daedalus_blueprint_managed_keys_alter(array &$managed_keys, string $entity_type, string $bundle): void {
  // Example: section layout settings nested by layout plugin ID. These are
  // structural settings the layout plugin owns; styling is not a
  // layout_settings concern.
  $managed_keys['section_layout_settings']['layout_twocol_section'] = [
    'column_widths' => [
      'label' => 'Column widths',
      'description' => 'Width ratio of the two columns.',
      'schema' => ['type' => 'string', 'enum' => ['50-50', '33-67', '67-33']],
    ],
  ];

  // Example: a slideshow block type exposes plugin configuration.
  // The inline_block_configuration scope is nested by block type.
  $managed_keys['inline_block_configuration']['slideshow'] = [
    'autoplay' => [
      'label' => 'Autoplay',
      'description' => 'Whether the slideshow advances automatically.',
      'schema' => ['type' => 'string', 'enum' => ['0', '1']],
    ],
    'label_display' => [
      'label' => 'Label display',
      'description' => 'Whether to display the label above the slideshow.',
      'schema' => ['type' => 'string', 'enum' => ['0', 'visible']],
    ],
  ];
}

/**
 * Attaches guidance and defaults to blueprint schema output.
 *
 * Allows modules to provide AI-facing guidance that is merged into the schema
 * returned by BlueprintSchemaBuilder::getSchema(). This helps LLM consumers
 * understand how to use entities, block types, layouts, and field types without
 * needing hardcoded examples in system prompts.
 *
 * The $context array supports four levels:
 * - Entity-level: top-level 'guidance' and 'defaults' keys are merged
 *   directly into the root schema array.
 * - Field types: keyed by field type machine name under 'field_types', each
 *   entry may contain 'guidance'. This guidance is applied inline to every
 *   field of the matching type in the schema, overriding handler-provided
 *   guidance. Use this to add guidance for custom field types or override
 *   defaults from handlers.
 * - Block types: keyed by block type machine name under 'block_types', each
 *   entry may contain 'guidance' and 'defaults'. These are merged into
 *   the matching available_block_types entry.
 * - Layouts: keyed by layout plugin ID under 'layouts', each entry may
 *   contain 'guidance' and 'defaults'. These are merged into the
 *   matching available_layouts entry.
 *
 * Only 'guidance' (string), 'defaults' (array), and 'anatomy' (array) are
 * recognized at each level. Unknown keys are ignored. 'anatomy' is the
 * element model's markup-shell fragment (daedalus_ui feeds it from its
 * layered page_builder declarations); most modules never set it;
 * declare shells in a PROVIDER.page_builder.yml instead and let the spine
 * deliver them.
 *
 * One reserved bookkeeping key exists: 'cache_tags' (string list). An
 * implementation feeding data that invalidates on its own domain declares
 * those tags here; the context and schema caches carry them, and the key
 * never reaches schema output.
 *
 * @param array &$context
 *   The context array to populate. Structure:
 *   @code
 *   $context['guidance'] = 'Entity-level guidance string.';
 *   $context['defaults'] = ['field_name' => 'default_value'];
 *   $context['field_types']['custom_field_type'] = [
 *     'guidance' => 'Accepts a bare string or {value: "x", option: "y"}.',
 *   ];
 *   $context['block_types']['block_type_id'] = [
 *     'guidance' => 'Block-level guidance.',
 *     'defaults' => ['configuration' => ['key' => 'value']],
 *   ];
 *   $context['layouts']['layout_plugin_id'] = [
 *     'guidance' => 'Layout-level guidance.',
 *     'defaults' => ['layout_settings' => ['column_widths' => '50-50']],
 *   ];
 *   @endcode
 * @param string $entity_type
 *   The entity type ID being processed.
 * @param string $bundle
 *   The bundle being processed.
 */
function hook_daedalus_blueprint_context_alter(array &$context, string $entity_type, string $bundle): void {
  // Entity-level guidance.
  $context['guidance'] = 'Main content type with Layout Builder.';
  $context['defaults'] = ['title' => 'Untitled'];

  // Field type guidance (override or supplement handler-provided guidance).
  $context['field_types']['custom_field_type'] = [
    'guidance' => 'Accepts a bare string or {value: "x", option: "y"}.',
  ];

  // Block type context.
  $context['block_types']['text'] = [
    'guidance' => 'field_text_tag chooses the markup tag (h1..h6, p, span); adding field_text_url turns the text into a link.',
  ];

  // Layout context.
  $context['layouts']['layout_twocol_section'] = [
    'guidance' => 'Two named columns; use only when named slots are required.',
    'defaults' => [
      'layout_settings' => ['column_widths' => '50-50'],
    ],
  ];
}

/**
 * Alters the built blueprint schema before it is cached.
 *
 * The seam for modules contributing whole capability sections to the schema
 * (the AI's machine-readable picture of what a bundle can do), as opposed to
 * hook_daedalus_blueprint_context_alter()'s per-element guidance prose. For
 * example, daedalus_blueprint_stencil advertises the site's stencil catalog
 * under layout_builder__layout so the AI can discover and place stencils.
 *
 * Runs after the schema is fully built and the context merge has been
 * applied; the result is cached, so implementations whose data has its own
 * invalidation domain MUST append the covering cache tags to
 * $context['cache_tags'].
 *
 * @param array &$schema
 *   The built schema for the bundle.
 * @param array &$context
 *   The alter context:
 *   - entity_type: The entity type ID.
 *   - bundle: The bundle.
 *   - view_mode: The view mode the schema was built for.
 *   - cache_tags: Cache tags to append to the schema cache entry.
 */
function hook_daedalus_blueprint_schema_alter(array &$schema, array &$context): void {
  if (isset($schema['layout_builder__layout'])) {
    $schema['layout_builder__layout']['available_widgets'] = ['example' => ['label' => 'Example']];
    $context['cache_tags'][] = 'my_module_widgets';
  }
}

/**
 * Alters the relevant settings exposed for a field in schema output.
 *
 * Field handlers determine which field settings are AI-relevant (e.g.,
 * max_length, allowed_values, file_extensions). This hook allows modules to
 * add or remove relevant settings for any field type without needing to
 * register a full field handler.
 *
 * @param array &$relevant_settings
 *   The relevant settings array as returned by the field handler. Modify to
 *   add or remove settings.
 * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
 *   The field definition being processed.
 * @param array $all_settings
 *   The complete unfiltered settings from the field definition.
 */
function hook_daedalus_blueprint_relevant_settings_alter(array &$relevant_settings, \Drupal\Core\Field\FieldDefinitionInterface $definition, array $all_settings): void {
  // Example: expose the 'pattern' setting for a custom field type.
  if ($definition->getType() === 'custom_regex_field' && !empty($all_settings['pattern'])) {
    $relevant_settings['pattern'] = $all_settings['pattern'];
  }
}

/**
 * Alters processable field definitions for blueprint operations.
 *
 * Called after built-in filters (entity keys, revision metadata, computed
 * fields, internal fields) have been applied. Allows modules to remove
 * fields that are not relevant for AI/blueprint consumers.
 *
 * Affects schema, serialization, deserialization, and validation uniformly.
 * Removed fields will not appear in output and will be silently ignored
 * during deserialization.
 *
 * @param \Drupal\Core\Field\FieldDefinitionInterface[] &$definitions
 *   Field definitions keyed by field name. Unset entries to exclude.
 * @param string $entity_type_id
 *   The entity type ID (e.g., 'node', 'block_content').
 * @param string $bundle
 *   The bundle machine name.
 */
function hook_daedalus_blueprint_processable_fields_alter(
  array &$definitions,
  string $entity_type_id,
  string $bundle,
): void {
  // Example: exclude node base fields the AI doesn't need to manage.
  // Remove or adjust these in your implementation to keep fields
  // the AI should be able to read and set (e.g., 'status' for
  // publish control, 'uid' for authorship).
  if ($entity_type_id === 'node') {
    unset(
      $definitions['created'],
      $definitions['changed'],
      $definitions['promote'],
      $definitions['sticky'],
      $definitions['status'],
      $definitions['uid'],
    );
  }
}

/**
 * Reacts to an entity being persisted by the storage handler.
 *
 * Called after EntityStorageHandler successfully saves an entity or writes it
 * to tempstore. Allows modules to perform lightweight side effects (e.g.,
 * change-detection timestamps) without coupling to specific consumers.
 *
 * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
 *   The entity that was persisted.
 * @param bool $save
 *   TRUE if the entity was saved to the database, FALSE if written to
 *   tempstore.
 * @param string[] $element_uuids
 *   UUIDs of elements affected by the operation. Empty when no
 *   element-level operations were performed (e.g., updateFields).
 */
function hook_daedalus_blueprint_entity_persisted(\Drupal\Core\Entity\FieldableEntityInterface $entity, bool $save, array $element_uuids): void {
}

/**
 * @} End of "addtogroup hooks".
 */
