<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\daedalus_blueprint_config\Result\ConfigBlueprintResult;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The one mutation tool: applies operations atomically to a single entity.
 *
 * All operations are validated upfront. If any operation fails, all changes
 * are rolled back. The built-in op grammar lives ONCE in the attribute's
 * operations description: the shared placement grammar and identity
 * (uuid-minting) contract are each stated a single time, then the per-op
 * lines reference them. Modules extend the grammar via the daedalus_blueprint
 * operation handler registry; their router lines (see
 * OperationHandlerInterface::getGuidance()) are appended to the operations
 * description, once, by daedalus_blueprint_ai_ai_function_call_info_alter().
 *
 * The operations description composes from the OPERATIONS_* constants so
 * the two site shapes share one source: the attribute default carries the
 * full grammar, and on a site where the page language speaks for pages
 * (the catalog declares a container layout) the alter hook recomposes it
 * without the layout grammar — pages are refused at execute time there,
 * so eb_edit keeps only the config ops and the root-field update_element.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:edit',
  function_name: 'eb_edit',
  name: 'Edit',
  description: 'Applies operations atomically to one NON-PAGE entity (config entities, skins, media); the current page and other pages are page_edit\'s job and materials update_material\'s, never this tool\'s. From a trivial single edit to a whole composition, every change is a batch. All changes are validated upfront and rolled back together on failure. Later operations see earlier operations\' results, so one batch can compose a nested structure with minted uuids and edit or style the newborns by those uuids in the same gesture. The "operations" parameter carries the full op grammar. Write text values literally, never HTML-escaped (write &, not &amp;); HTML entities belong only inside rich-text HTML values.',
  group: 'modification_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID (e.g. node, image_style).'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The entity ID. Numeric for content entities, machine name for config entities.'),
    ),
    'operations' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Operations'),
      required: TRUE,
      // The description is the tool's grammar, assembled from its constants.
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      description: new TranslatableMarkup(self::OPERATIONS_INTRO . self::OPERATIONS_LAYOUT_GRAMMAR . self::OPERATIONS_CONFIG_OPS . self::OPERATIONS_LAYOUT_EXAMPLE),
    ),
    'save' => new ContextDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Save'),
      required: FALSE,
      description: new TranslatableMarkup('TRUE to force saving directly to the database. When omitted or FALSE, content entities route to tempstore; config entities always save directly.'),
    ),
  ],
)]
class Edit extends DaedalusBlueprintToolBase {

  /**
   * The operations description's envelope sentence, common to both shapes.
   */
  public const OPERATIONS_INTRO = 'Array of operation objects; each has an "op" key, and every other key is that op\'s parameters. ';

  /**
   * The layout grammar: placement, identity, and the content-op roll-call.
   *
   * Composed into the description only where eb_edit still edits layouts;
   * on a page-language site the page language owns all of this and the
   * alter hook swaps in OPERATIONS_ROOT_GRAMMAR instead.
   */
  public const OPERATIONS_LAYOUT_GRAMMAR = 'Placement, stated once for every op that takes a "location" {anchor, placement: before|after|inside}: before/after land beside the anchor in its flow (any element, at any depth), "inside" nests into a section; anchor the root entity UUID with "inside" for a top-level section (the root flow holds only sections). A "region" key applies only to multi-region layouts. Identity, stated once for the creating ops: an optional "uuid" is a fresh UUID v4 you mint (real RFC 4122; human-readable identifiers are rejected), so later operations in the same batch and styling keys such as block:{uuid} reference the newborn; reusing an existing or already-minted uuid is refused as UUID_IN_USE. Content ops: update_element ("properties", optional "element" uuid; a section\'s one property is "label"), add_element ("block_type" for inline blocks OR "plugin_id" for plugin blocks, "location", optional "properties" and "uuid"; blocks never hold nested layouts), add_section ("location", optional "uuid"; a new section is a CONTAINER, one ordered flow of children, born with its layout for life; pass "layout" from available_layouts plus optional "layout_settings" only for a genuinely named-slot layout; an optional "stencil" records a hand-placed stencil root), remove_element ("element" uuid; a section\'s whole subtree goes with it), duplicate_element ("element" uuid; deep-clone with fresh identity, inserted after the original), move_element ("element" uuid and "location"), restructure_layout, reorder_field. ';

  /**
   * The root-field grammar: what a page-language site keeps for content.
   */
  public const OPERATIONS_ROOT_GRAMMAR = 'Content op: update_element ("properties" map patching the entity\'s own fields). ';

  /**
   * The config-op roll-call, identical on both site shapes.
   */
  public const OPERATIONS_CONFIG_OPS = 'Config ops: update_properties, add_plugin, update_plugin, remove_plugin, reorder_plugins.';

  /**
   * The trivial example for the full (layout) grammar.
   */
  public const OPERATIONS_LAYOUT_EXAMPLE = ' A trivial edit is a one-op batch, e.g. [{"op": "update_element", "element": "<uuid>", "properties": {"label": "Hero"}}].';

  /**
   * The trivial example for the page-language shape (root fields only).
   */
  public const OPERATIONS_ROOT_EXAMPLE = ' A trivial edit is a one-op batch, e.g. [{"op": "update_element", "properties": {"name": "Hero"}}].';

  /**
   * The dialect catalog builder: the page-language site predicate.
   */
  protected DialectCatalogBuilder $catalogBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->catalogBuilder = $container->get('daedalus_blueprint.dialect_catalog_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Shapes the operations parameter as an array of objects on the wire.
   * The generic map-to-object rewrite
   * (daedalus_blueprint_ai_ai_tools_property_alter()) fires during the parent
   * normalize; operations is the one map param whose value is a LIST of op
   * objects, and the reshape is plugin-local because the global alter must
   * never name-discriminate another module's parameters.
   */
  public function normalize(): ToolsFunctionInput {
    $function = parent::normalize();
    $operations = $function->getPropertyByName('operations');
    $operations->setType('array');
    $operations->deleteCustomValue('additionalProperties');
    $operations->setItems(['type' => 'object', 'additionalProperties' => TRUE]);
    return $function;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (!$this->requireValidParams()) {
      return;
    }

    $entity_type = $this->getContextValue('entity_type');
    $entity_id = $this->getContextValue('entity_id');
    $save = $this->getContextValue('save');

    if (!$this->requireWorkspaceFor($entity_type)) {
      return;
    }

    $operations = $this->getContextValue('operations');

    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return;
    }

    $entity = $this->loadAnyEntity($entity_type, $entity_id, 'update');
    if (!$entity) {
      return;
    }

    // eb_edit never edits pages: any entity the page language speaks for
    // (it carries a layout and the site's catalog declares a container
    // layout) is page_edit's, whole. One grammar per gesture — teaching a
    // JSON side door here is how a model learns to route around the page
    // language, so the refusal routes instead of accommodating.
    if ($entity instanceof FieldableEntityInterface
      && $entity->hasField('layout_builder__layout')
      && $this->catalogBuilder->build()->containerLayoutId() !== '') {
      $this->output = sprintf(
        'Error: %s %s is a page, and pages speak the page language: edit it with page_edit. Structure and content are HTML fragments (insert/replace), styling is CSS (style, or set\'s style/class attrs), and entity fields such as the title ride set with ref "root".',
        $entity_type,
        $entity_id,
      );
      return;
    }

    try {
      $result = $backend->batchOperations($entity, $operations, $this->blueprintOptions());
      if (!$result->isSuccess()) {
        $this->output = $this->formatResult($result);
        return;
      }

      if (!$this->persistViaBackend($backend, $result, force_save: (bool) $save)) {
        return;
      }

      $result_array = $result->toArray();
      $is_config = $result instanceof ConfigBlueprintResult;
      $result_array['persisted'] = ($save || $is_config) ? 'database' : 'tempstore';
      $this->output = $this->withMarkupEcho($this->formatArray($result_array), $result);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
