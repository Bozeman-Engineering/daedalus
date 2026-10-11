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
 *     autoplay:
 *       label: 'Autoplay'
 *       description: 'Whether the slideshow advances automatically.'
 *       schema:
 *         type: string
 *         enum: ['0', '1']
 *     transition:
 *       label: 'Transition'
 *       description: 'How one slide gives way to the next.'
 *       schema:
 *         type: string
 *         enum: ['fade', 'slide']
 * @endcode
 *
 * A placement's own facts (`label`, `label_display`, `view_mode`) are not
 * managed keys: the ops take them on every block without a declaration,
 * and the lean tree spells the display fact as `label_rendered`.
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
 * 3. Hooks (existing alter behavior; always has final say)
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
 * # Universal: applies to all entity types and bundles:
 * mymodule.daedalus_blueprint.context.yml
 * mymodule.daedalus_blueprint.managed_keys.yml
 *
 * # Scoped: applies only to node/landing_page:
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
 * The capability resolver: what an actor may do to an element.
 *
 * @section capability_vocabulary Five capabilities, one verdict
 *
 * Every operation declares what it requires of each of its targets in a
 * closed vocabulary (\Drupal\daedalus_blueprint\Capability\Capability:
 * content, configure, style, arrange, insert), and ONE resolver
 * (daedalus_blueprint.capability_resolver,
 * \Drupal\daedalus_blueprint\Capability\CapabilityResolver::verdict())
 * answers whether an actor may exercise a capability on a target of a
 * host (an element uuid, or the host's uuid for the root). The op door
 * (BlueprintOperations::applyOperation()) asks it before dispatching any
 * operation, built-in or registered, in any batch; the deserializer asks
 * it at the root; every read publishes the same answers under `_verdict`
 * (VerdictStamper). No surface interprets a cause such as ownership, a
 * translation or a workspace itself.
 *
 * @section capability_policies Adding a reason to refuse: a policy
 *
 * A policy is a service tagged `daedalus_blueprint.capability_policy`
 * implementing
 * \Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface. The
 * resolver intersects every policy: a capability is allowed when no
 * policy refuses it, and the first refusal in priority order (highest
 * first) is the explanation the verdict carries. A policy answers one
 * question and reads one kind of fact; the suite's are host access (100,
 * ACCESS_DENIED_ENTITY), workspace (90, WORKSPACE_REQUIRED), ownership
 * (50, FOREIGN_DOCUMENT), translation (40, LAYOUT_NOT_TRANSLATABLE), the
 * role grammar (30, ACCESS_DENIED_PERMISSION: core's Layout Builder
 * override permissions for structure, `create and edit custom blocks`
 * for an inline block's fields, `configure any layout` on a template)
 * and daedalus_stylish's style permission (30, the same code). A refusal
 * is a \Drupal\daedalus_blueprint\Capability\Refusal: a stable code every
 * surface keys on, a sentence, a hint, the cause as context, and a
 * \Drupal\daedalus_blueprint\Capability\Portal only when the actor may
 * make the gesture on another host (never a door to a locked room). A
 * policy that asks a permission spells its refusal with
 * Refusal::permission(), so `context.permissions` always names the grants
 * that would open the gesture.
 *
 * @code
 * # mymodule.services.yml
 * mymodule.capability_policy.locks:
 *   class: Drupal\mymodule\Capability\LockPolicy
 *   tags:
 *     - { name: daedalus_blueprint.capability_policy, priority: 20 }
 * @endcode
 *
 * @code
 * final class LockPolicy implements CapabilityPolicyInterface {
 *
 *   public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
 *     if ($capability === Capability::Content || !$this->locked($host, $target)) {
 *       return NULL;
 *     }
 *     return new Refusal('ELEMENT_LOCKED', 'This element is locked by its author.', 'Unlock it before changing its structure or styles.');
 *   }
 *
 * }
 * @endcode
 *
 * @section capability_requirements Declaring what an operation needs
 *
 * A registered operation handler
 * (\Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface)
 * declares its requirements through getRequirements(): one entry per
 * operation name it provides, each a list of
 * \Drupal\daedalus_blueprint\Capability\Requirement shapes the op door
 * resolves against the actual operation: Requirement::root($capability)
 * for the host itself, ::element($capability, $parameter) for the element
 * whose uuid a parameter carries ('element' by default), ::styleKey()
 * for style on the element a style map key addresses ('key' by default),
 * ::destination() for insert on the container a placement location
 * resolves to ('location' by default: the anchor for 'inside', the
 * anchor's parent for before/after, the host for the root). An empty
 * list declares an operation with nothing to judge on an element (it
 * writes a material or expands into spine operations that are judged
 * themselves). The registry refuses a handler whose declaration misses an
 * operation, with a message naming the five capabilities: a default
 * would keep a handler running past the gate and hide the contract. A
 * requirement that resolves to no live target is not asked; the
 * operation reports the missing element itself.
 *
 * @code
 * public function getRequirements(): array {
 *   return [
 *     'retitle' => [Requirement::element(Capability::Configure)],
 *     'wear' => [Requirement::styleKey()],
 *     'drop_in' => [Requirement::destination()],
 *     'sync_catalog' => [],
 *   ];
 * }
 * @endcode
 *
 * @section capability_actor The actor and the system posture
 *
 * The actor is a batch fact, not a parameter on every spine signature:
 * BlueprintOperations::batchOperations() takes an AccountInterface in its
 * `actor` option, defaulting to the current user, and nested batches
 * inherit it. A batch run for the site rather than for an actor declares
 * `system => TRUE` and is not judged (the template seed a read can
 * trigger, the shipped material importers); nested batches inherit the
 * posture, like `admitted` for placement restrictions and `dressed` for
 * birth defaults. Not judged covers every check that asks about the
 * current user: the capability gate, field edit access, a field handler's
 * own checks (FieldHandlerInterface::deserialize() receives the `system`
 * option; a handler that judges the current user must not under it) and
 * core's allowed-values constraint where it holds a value to what the
 * current user may set. A refusal lands as a BlueprintError at the operation's
 * parameter path with the refusal's code, message, hint and context.
 *
 * @section capability_reads The verdict on every read
 *
 * daedalus_blueprint.verdict_stamper (VerdictStamper) stamps `_verdict`
 * on the blueprint's root and every container and block node of the lean
 * tree a read carries as `layout` (the full and summary reads; the
 * element read is that node itself, stamped): `can`, the five
 * capabilities to booleans in vocabulary order, and `refused`, one entry
 * per distinct refusal as {code, capabilities}; the root's entries also
 * carry message, hint and context (the owner, the portal), since
 * host-level refusals are what a surface explains. A delivery
 * annotation: never stored, ignored on echo (the write grammar ignores
 * every key beginning with an underscore). The host's fields carry no
 * stamp (the root's content verdict is theirs; field edit access stays
 * with the field handlers). NodeTreeBuilder::build() answers an
 * unstamped tree; a consumer printing one for an actor stamps it
 * (stampTree()), and allowsAnywhere($host, $capability) answers whether
 * anything on the host is open to the actor, the question a surface asks
 * before it mounts.
 */

/**
 * The page document's one shape.
 *
 * @section document_shape The lean node tree
 *
 * A blueprint's `layout` is the lean node tree, produced by ONE class,
 * \Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer (its class
 * docblock is the shape's reference; nothing else converts sections into
 * nodes): a list of container nodes in display order, every node
 * declaring its `kind`. A container is {kind: 'container', layout_id,
 * uuid, label?, blueprint?, layout_settings?, children, styles?}, the same
 * shape at every depth; a container layout's flow is its `children`
 * directly, a multi-region layout's named slots are region nodes {kind:
 * 'region', region, children}, every region of the layout, empty ones
 * included (structure, never an element: no uuid, no styles). A block is
 * {kind: 'block', uuid, blueprint?, block_type | plugin_id, label?,
 * label_rendered?, view_mode?, block_fields?, configuration?, styles?}.
 * ONE child axis at every depth, so an error path reads
 * `layout[0].children[1]`. The tree is sparse (a key rides when it
 * carries a fact; `view_mode` only when not 'full'), `label_rendered` is
 * the one spelling of whether a block's label shows
 * (NodeTreeBuilder::labelRendered(); core's `label_display` stays inside
 * storage), and storage residue (a section's stored index, a block's
 * revision id, region names and weights per component) never appears.
 *
 * @section document_styles Styles ride the node
 *
 * The host stores one flat style map and the style ops address it by
 * absolute selectable key; neither changes. The serializer distributes
 * the map onto the nodes as it builds them: a node's `styles` is keyed by
 * the local remainder after `block:{uuid}` / `container:{uuid}` ('' the
 * element's own entry, ':{field}', '::{selector}' and their compounds),
 * and the top-level `styles` keeps only the keys no node owns (`entity:`,
 * `field:` and their rule forms). The flat map is a derived index of the
 * tree with ONE canonical order, the document's
 * (NodeTreeBuilder::styleMapOf(): node-less keys first in stored order,
 * then every node in document order, each node's own keys as stored);
 * NodeTreeBuilder::orderedStyleMap() orders a map a caller is about to
 * deliver, and daedalus_stylish's CssStyleBackend and the client's
 * styleMapOf() in style-guide.js print exactly that order. A consumer
 * holding a host and no blueprint reads the tree through the
 * NodeTreeBuilder service (build(), find(), labelRendered()).
 *
 * @section document_write A read is a write; a whole layout is written once
 *
 * The summary answers the same nodes pared of block_fields, configuration
 * and layout_settings, and the element read answers the element's node as
 * it sits inside `layout` (a container with its subtree), so every read
 * is written back with the one grammar, DocumentInstantiator's, which
 * ignores what a read carries that no write can mean (keys beginning with
 * an underscore, the storage residue, `blueprint` provenance below the
 * root). BlueprintDeserializer writes a `layout` at creation only: the
 * tree expands through the instantiator with the author's uuids kept and
 * runs as one dressed batch of the ordinary ops on the unsaved host; node
 * styles and the top-level `styles` merge into one map
 * (DUPLICATE_STYLE_KEY when a key is stated twice) validated through the
 * style store's one handler once the structure stands; a created entity
 * carrying a layout is not seeded from its template
 * (HostInterface::declineSeed()). A `layout` arriving with `_root_entity`
 * is refused with LAYOUT_ON_EXISTING_ENTITY, whose hint names the ops
 * road; a fields-only update is unaffected. The same expansion seeds a
 * page from its template, captures a saved blueprint from a page's
 * container, pushes a placement back into its blueprint, places a
 * blueprint, starts a page from a page blueprint (PageStart: a page's
 * first document has one source, picked once at creation, the template,
 * a page blueprint or blank; a start on a saved entity is refused with
 * START_ON_EXISTING_ENTITY) and lands a page_create document, so there is
 * one write road for a whole tree and one grammar error, INVALID_NODE.
 *
 * @section document_hosts The host decides what a tree means
 *
 * A tree carries no host facts: a field block spells the FIELD it renders
 * ({kind: 'block', field: 'body'}), never core's per-bundle
 * field_block:{type}:{bundle}:{field} derivative, and DocumentInstantiator
 * expands it against the destination host's subject()
 * (\Drupal\daedalus_blueprint\LayoutBuilder\FieldBlock is the one
 * spelling of the derivative), refusing FIELD_NOT_ON_HOST with the
 * subject's placeable fields. So one tree instantiates onto a page, a
 * template or a saved blueprint of any bundle that has the field. The
 * saved blueprint is the fourth host
 * (\Drupal\daedalus_blueprint\Host\BlueprintHost, a ContentHost whose
 * subject is core's sample entity of its subject bundle and whose storage
 * is the `blueprint` section storage plugin), known to the spine by its
 * entity type id and field-name constants alone: the entity, discovery,
 * the gestures and the ship lane are daedalus_blueprint_library's, and
 * what a blueprint needs of a host is judged once, in the spine
 * (\Drupal\daedalus_blueprint\Materials\BlueprintRequirements:
 * derive() from a tree, fits() against a host, the Add panel's filtered
 * delivery and place_blueprint's BLUEPRINT_DOES_NOT_FIT read it).
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
 * Plugin block configuration (a field block's formatter, a view's display,
 * a menu's depth, any non-inline block plugin) is NOT declared here: its
 * property bag is derived from the plugin's own config schema
 * (block.settings.{plugin_id} through typed config, see
 * \Drupal\daedalus_blueprint\LayoutBuilder\PluginBlockConfiguration), and a
 * plugin without schema is not configurable through the spine. Managed keys
 * cover the opaque extras of inline block configuration and section layout
 * settings only.
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
    'transition' => [
      'label' => 'Transition',
      'description' => 'How one slide gives way to the next.',
      'schema' => ['type' => 'string', 'enum' => ['fade', 'slide']],
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
 * Alter an inner document's render before it lands inside the outer one.
 *
 * The one render primitive (\Drupal\daedalus_blueprint\Host\InnerRender)
 * renders an inner host's sections inside an outer render under the outer
 * host's contexts: a page's slot section inside its template, a linked
 * blueprint inside the page that links it, the site frame around the
 * route's output. This hook hands the finished render (one child per
 * section, stamped with the inner host's identity; for a document root
 * such as the frame, the layout root holding them) to the modules that
 * deliver on a document root, so what belongs to the inner document lands
 * inside it: daedalus_stylish delivers the inner host's style map here
 * when the inner is not the subject's own document (a slot filled by the
 * page's own section is the page's, and the page's own view delivers it).
 *
 * @param array &$build
 *   The inner render: the sections keyed by delta, with the inner host's
 *   cacheability applied; children and attributes added here ride the
 *   render (a linked placement moves the root attributes onto its own
 *   wrapper).
 * @param \Drupal\daedalus_blueprint\Host\HostInterface $inner
 *   The host whose document the sections belong to.
 * @param \Drupal\Core\Entity\FieldableEntityInterface|null $subject
 *   The outer render's subject: the entity every block in the render
 *   reads (the page), or NULL when the render has none (the frame on a
 *   view page or a form).
 */
function hook_daedalus_blueprint_inner_render_alter(array &$build, \Drupal\daedalus_blueprint\Host\HostInterface $inner, ?\Drupal\Core\Entity\FieldableEntityInterface $subject): void {
  if ($inner->entity()->uuid() !== $subject?->uuid() && $inner->hasStyleStore()) {
    $build['my_module_css'] = ['#markup' => '<style>/* the inner host\'s styles */</style>'];
  }
}

/**
 * Alters the built blueprint schema before it is cached.
 *
 * The seam for modules contributing whole capability sections to the schema
 * (the AI's machine-readable picture of what a bundle can do), as opposed to
 * hook_daedalus_blueprint_context_alter()'s per-element guidance prose. For
 * example, daedalus_blueprint_library advertises the site's blueprint catalog
 * under layout so the AI can discover and place blueprints.
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
  if (isset($schema['layout'])) {
    $schema['layout']['available_widgets'] = ['example' => ['label' => 'Example']];
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
 * Called after EntityStorageHandler successfully stages an entity (persist()
 * wrote it to tempstore) or commits it (commit() saved it to the database and
 * cleared its stage). Allows modules to perform lightweight side effects (e.g.,
 * change-detection timestamps) without coupling to specific consumers.
 *
 * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
 *   The host that was persisted: a content host (its entity is the page's
 *   entity), a blueprint host (its entity is the saved blueprint, whose
 *   every commit is a new revision) or a display host (its entity is the
 *   template's entity view display, whose persistence is a config save).
 * @param bool $save
 *   TRUE if the entity was saved to the database, FALSE if written to
 *   tempstore.
 * @param string[] $element_uuids
 *   UUIDs of elements affected by the operation. Empty when no
 *   element-level operations were performed (e.g., updateFields).
 */
function hook_daedalus_blueprint_entity_persisted(\Drupal\daedalus_blueprint\Host\HostInterface $host, bool $save, array $element_uuids): void {
}

/**
 * @} End of "addtogroup hooks".
 */
