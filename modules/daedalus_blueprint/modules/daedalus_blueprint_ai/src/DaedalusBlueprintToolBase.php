<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint\Markup\MarkupProjection;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Markup\StructuralAuditor;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint_ai\Event\AiToolActionEvent;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Base class for daedalus_blueprint AI function call tools.
 *
 * Provides shared infrastructure: entity loading, access checks, persistence
 * routing (save vs tempstore), result formatting, and the malformed
 * structured-parameter guard.
 */
abstract class DaedalusBlueprintToolBase extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The output string for readable output.
   *
   * @var string
   */
  protected string $output = '';

  /**
   * Correctives for parameters that arrived in an unusable shape.
   *
   * Keyed by parameter name; see setContextValue().
   *
   * @var string[]
   */
  protected array $parameterCorrectives = [];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected BlueprintOperationsInterface $blueprintOperations;

  /**
   * The blueprint schema builder service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface
   */
  protected BlueprintSchemaBuilderInterface $schemaBuilder;

  /**
   * The blueprint deserializer service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializerInterface
   */
  protected BlueprintDeserializerInterface $deserializer;

  /**
   * The entity storage handler.
   *
   * @var \Drupal\daedalus_blueprint\EntityStorageHandler
   */
  protected EntityStorageHandler $storageHandler;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The blueprint backend resolver.
   *
   * @var \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver
   */
  protected BlueprintBackendResolver $backendResolver;

  /**
   * The bundle list builder: the AI-visibility authority.
   *
   * @var \Drupal\daedalus_blueprint\BundleListBuilder
   */
  protected BundleListBuilder $bundleListBuilder;

  /**
   * The markup-projection socket (the element model's HTML print format).
   *
   * @var \Drupal\daedalus_blueprint\Markup\MarkupProjection
   */
  protected MarkupProjection $markupProjection;

  /**
   * The structural auditor over the markup projection.
   *
   * @var \Drupal\daedalus_blueprint\Markup\StructuralAuditor
   */
  protected StructuralAuditor $structuralAuditor;

  /**
   * The host resolver.
   *
   * @var \Drupal\daedalus_blueprint\Host\HostResolver
   */
  protected HostResolver $hostResolver;

  /**
   * The dialect catalog builder: the page-language site predicate.
   *
   * @var \Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder
   */
  protected DialectCatalogBuilder $catalogBuilder;

  /**
   * The one meaning of "page" for every AI door.
   *
   * @var \Drupal\daedalus_blueprint\Host\PageHosts
   */
  protected PageHosts $pageHosts;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->blueprintOperations = $container->get('daedalus_blueprint.operations');
    $instance->schemaBuilder = $container->get('daedalus_blueprint.schema_builder');
    $instance->deserializer = $container->get('daedalus_blueprint.deserializer');
    $instance->storageHandler = $container->get('daedalus_blueprint.storage_handler');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->backendResolver = $container->get('daedalus_blueprint.backend_resolver');
    $instance->bundleListBuilder = $container->get('daedalus_blueprint.bundle_list_builder');
    $instance->markupProjection = $container->get('daedalus_blueprint.markup_projection');
    $instance->structuralAuditor = $container->get('daedalus_blueprint.structural_auditor');
    $instance->hostResolver = $container->get('daedalus_blueprint.host_resolver');
    $instance->catalogBuilder = $container->get('daedalus_blueprint.dialect_catalog_builder');
    $instance->pageHosts = $container->get('daedalus_blueprint.page_hosts');
    return $instance;
  }

  /**
   * The corrective a JSON read door answers for a page.
   *
   * The two JSON read doors (eb_read_entity, eb_read_element) refuse a
   * page (PageHosts::isPage()) toward page_read, whole, in every mode: a page's own
   * fields ride before its document print and the print carries every
   * element by id, so nothing a page knows needs the JSON road (its markup
   * projection included; the per-turn print projects the current page
   * itself, not through a tool). The refusal sits before any read of the
   * document, so a never-edited page's template seed is no longer a side
   * effect of a read. A template has no fields, so its corrective names
   * the print alone. One spelling for both doors, so the model learns one
   * route.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The page host.
   * @param string $entity_type
   *   The entity type ID as the model passed it.
   * @param int|string $entity_id
   *   The entity ID as the model passed it.
   *
   * @return string
   *   The tool output.
   */
  protected function pageReadCorrective(HostInterface $host, string $entity_type, int|string $entity_id): string {
    $content = $host->entity() instanceof FieldableEntityInterface;
    return sprintf(
      'Error: %s %s is a %s, and pages speak the page language: read it with page_read%s.',
      $entity_type,
      $entity_id,
      $content ? 'page' : 'template (a page document)',
      $content ? ' (its fields ride before the print, and the print carries every element by id)' : ' (the print carries every element by id)',
    );
  }

  /**
   * The corrective a JSON create door answers for a page bundle.
   *
   * The two JSON create doors (eb_create_entity, eb_validate) refuse a
   * page bundle (PageHosts::isPageBundle()) toward page_create before the deserializer
   * runs: a page is born through the page language, its fields and its
   * document in one gesture, and page_create judges the whole page before
   * anything is built, so the judged create (eb_validate) has nothing to
   * add either. One spelling for both doors, so the model learns one
   * route.
   *
   * @param string $entity_type
   *   The entity type ID as the model passed it.
   * @param string $bundle
   *   The bundle as the model passed it.
   *
   * @return string
   *   The tool output.
   */
  protected function pageCreateCorrective(string $entity_type, string $bundle): string {
    return sprintf(
      'Error: %s/%s is a page bundle (its entities render a page document), and pages speak the page language: create it with page_create, which judges the whole page before anything is built.',
      $entity_type,
      $bundle,
    );
  }

  /**
   * Checks if a workspace is required before proceeding.
   *
   * Call at the top of execute() in all tools. When a workspace is required
   * but not active, sets the output to a descriptive message listing available
   * workspaces and returns FALSE.
   *
   * @return bool
   *   TRUE if the tool may proceed. FALSE if a workspace is required — the
   *   output property is set with workspace options for the AI to present.
   */
  protected function requireWorkspace(): bool {
    if (!$this->storageHandler->isWorkspaceRequired()) {
      return TRUE;
    }

    $lines = ['An active workspace is required.'];

    try {
      $ids = $this->entityTypeManager->getStorage('workspace')
        ->getQuery()
        ->accessCheck(TRUE)
        ->sort('changed', 'DESC')
        ->range(0, 3)
        ->execute();

      if (!empty($ids)) {
        $workspaces = $this->entityTypeManager->getStorage('workspace')
          ->loadMultiple($ids);
        $lines[] = '';
        $lines[] = 'Available workspaces:';
        foreach ($workspaces as $workspace) {
          $lines[] = '- ' . $workspace->id() . ': "' . $workspace->label() . '"';
        }
      }
      else {
        $lines[] = 'No workspaces exist yet.';
      }
    }
    catch (\Exception $e) {
      $lines[] = 'No workspaces exist yet.';
    }

    $lines[] = '';
    $lines[] = 'To switch to an existing workspace, use'
      . ' tool__workspace_switch with its workspace_id.'
      . ' To create a new one, use tool__workspace_create_and_switch'
      . ' with a label.';
    $lines[] = '';
    $lines[] = 'IMPORTANT: Do NOT pick a workspace yourself. You MUST ask the'
      . ' user which workspace they want to use or whether to create a new'
      . ' one. Present the options above and wait for their answer.';

    $this->output = implode("\n", $lines);
    return FALSE;
  }

  /**
   * Resolves the backend for an entity type.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface|null
   *   The backend, or NULL if unsupported. When NULL is returned, the output
   *   property is set with an error message.
   */
  protected function getBackend(string $entity_type): ?BlueprintBackendInterface {
    $backend = $this->backendResolver->resolve($entity_type);
    if ($backend === NULL) {
      $this->output = "Unsupported entity type: $entity_type.";
    }
    return $backend;
  }

  /**
   * Loads any entity (content or config) with access checking.
   *
   * Uses the backend resolver to route loading to the correct backend.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   * @param string $access_op
   *   The access operation ('view', 'update', 'delete').
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The loaded entity, or NULL if not found or access denied. When NULL
   *   is returned, the output property is set with an error message.
   */
  protected function loadAnyEntity(string $entity_type, int|string $entity_id, string $access_op): ?EntityInterface {
    if (!$this->requireVisibleEntityType($entity_type)) {
      return NULL;
    }

    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return NULL;
    }

    $entity = $backend->load($entity_type, $entity_id);
    if (!$entity) {
      $this->output = "Entity $entity_type:$entity_id not found.";
      return NULL;
    }

    if (!$this->requireVisibleEntityType($entity_type, $entity->bundle())) {
      return NULL;
    }
    // A hosted entity is visible by its document's kind (a template by
    // the bundle it renders), the same rule loadHost() applies.
    if ($this->hostResolver->hosts($entity) && !$this->requireVisibleHost($this->hostResolver->fromEntity($entity))) {
      return NULL;
    }

    if (!$entity->access($access_op)) {
      $this->output = "Access denied for '$access_op' on $entity_type:$entity_id.";
      return NULL;
    }

    return $entity;
  }

  /**
   * Loads a page host with access checking.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   * @param string $access_op
   *   The access operation ('view', 'update', 'delete').
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The loaded host (its staged copy when one exists), or NULL if not
   *   found or access denied. When NULL is returned, the output property
   *   is set with an error message.
   */
  protected function loadHost(string $entity_type, int|string $entity_id, string $access_op): ?HostInterface {
    if (!$this->requireVisibleEntityType($entity_type)) {
      return NULL;
    }

    $host = $this->storageHandler->load($entity_type, $entity_id);

    if ($host === NULL) {
      $entity = $this->entityTypeManager->hasDefinition($entity_type)
        ? $this->entityTypeManager->getStorage($entity_type)->load($entity_id)
        : NULL;
      // The entity exists but nothing hosts it: a display without Layout
      // Builder is config, not a template.
      $this->output = $entity === NULL
        ? "Entity $entity_type:$entity_id not found."
        : "$entity_type:$entity_id is not a template: Layout Builder is not enabled on that display, so it hosts no page document.";
      return NULL;
    }

    if (!$this->requireVisibleEntityType($entity_type, $host->bundle()) || !$this->requireVisibleHost($host)) {
      return NULL;
    }

    if (!$host->entity()->access($access_op)) {
      $this->output = "Access denied for '$access_op' on $entity_type:$entity_id.";
      return NULL;
    }

    return $host;
  }

  /**
   * Guards that AI visibility filtering does not hide a host's document.
   *
   * The document's kind decides (BundleListBuilder::isHiddenHost()): a
   * template of a hidden bundle is as unreachable as the bundle's pages,
   * and so is a saved blueprint whose subject is a hidden bundle (its
   * document is that bundle's material; the blueprint type's own
   * visibility is loadHost()'s earlier question). The refusal names what
   * is hidden: the bundle for a page or a template, the subject for a
   * blueprint, since a blueprint's own bundle is never the hidden one.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The loaded host.
   *
   * @return bool
   *   TRUE if the tool may proceed. FALSE if hidden; the output property
   *   is set with the refusal message.
   */
  protected function requireVisibleHost(HostInterface $host): bool {
    if (!$this->bundleListBuilder->isHiddenHost($host)) {
      return TRUE;
    }
    // A hidden host has a display: only a display's kind can be hidden
    // (BundleListBuilder::isHiddenHost()).
    $display = $host->display();
    assert($display !== NULL);
    $this->output = $host instanceof BlueprintHost
      ? sprintf('Unknown or hidden bundle: %s/%s (the subject of blueprint %s).', $display->getTargetEntityTypeId(), $display->getTargetBundle(), $host->id())
      : sprintf('Unknown or hidden bundle: %s/%s.', $display->getTargetEntityTypeId(), $display->getTargetBundle());
    return FALSE;
  }

  /**
   * Persists a result's host via the storage handler.
   *
   * Routes to the correct storage backend (database or tempstore) based on
   * module configuration. New entities always save to the database. Edit
   * operations auto-route to tempstore when available, unless force_save is
   * TRUE.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The blueprint result containing the host and element UUIDs.
   * @param bool $is_new
   *   TRUE if this is a newly created entity.
   * @param bool $force_save
   *   TRUE to force saving directly to the database, bypassing tempstore.
   *
   * @return bool
   *   TRUE if persistence succeeded. When FALSE is returned, the output
   *   property is set with an error message.
   */
  protected function persistEntity(BlueprintResult $result, bool $is_new = FALSE, bool $force_save = FALSE): bool {
    try {
      $this->storageHandler->persist(
        $result->getHost(),
        $is_new,
        $force_save,
        $result->getElementUuids(),
      );
      $this->moduleHandler->invokeAll('daedalus_blueprint_ai_tool_persisted', [
        $result->getHost(),
        $is_new,
        $result->getElementUuids(),
      ]);
      $this->eventDispatcher->dispatch(new AiToolActionEvent(
        'entity_persisted',
        $this->getPluginId(),
        [
          'host' => $result->getHost(),
          'is_new' => $is_new,
          'targets' => $result->getTargets(),
        ],
      ));
      return TRUE;
    }
    catch (\Exception $e) {
      $this->output = 'Persistence error: ' . $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Persists an entity via the backend resolver.
   *
   * For content entities, delegates to the full persistence path with tempstore
   * routing and events. For config entities, saves directly.
   *
   * @param \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface $backend
   *   The backend that produced the result.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The blueprint result containing the entity.
   * @param bool $is_new
   *   TRUE if this is a newly created entity.
   * @param bool $force_save
   *   TRUE to force saving directly to the database (content entities only).
   *
   * @return bool
   *   TRUE if persistence succeeded. When FALSE is returned, the output
   *   property is set with an error message.
   */
  protected function persistViaBackend(BlueprintBackendInterface $backend, BlueprintResultInterface $result, bool $is_new = FALSE, bool $force_save = FALSE): bool {
    if ($result instanceof BlueprintResult) {
      return $this->persistEntity($result, $is_new, $force_save);
    }

    try {
      $backend->persist($result->getEntity(), $is_new);
      $this->eventDispatcher->dispatch(new AiToolActionEvent(
        'entity_persisted',
        $this->getPluginId(),
        ['entity' => $result->getEntity(), 'is_new' => $is_new],
      ));
      return TRUE;
    }
    catch (\Exception $e) {
      $this->output = 'Persistence error: ' . $e->getMessage();
      return FALSE;
    }
  }

  /**
   * Checks if workspace is required for an entity type and sets output if so.
   *
   * Config entities never require workspaces. Content entities defer to the
   * existing requireWorkspace() logic.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if the tool may proceed. FALSE if workspace is required — the output
   *   property is set with workspace options.
   */
  protected function requireWorkspaceFor(string $entity_type): bool {
    $backend = $this->backendResolver->resolve($entity_type);
    if ($backend === NULL || !$backend->requiresWorkspace()) {
      return TRUE;
    }
    return $this->requireWorkspace();
  }

  /**
   * Guards that the entity type can host a page document.
   *
   * A document host is a fieldable entity (a content host), a Layout
   * Builder display (a template host, type entity_view_display; whether a
   * given display is Layout Builder enabled is loadHost()'s question) or a
   * site frame (a frame host, type daedalus_frame, addressed by its theme).
   * Call at the top of execute() in every tool that addresses a host
   * (page_read, page_edit, eb_read_entity, eb_read_element) to give a
   * clear corrective when another config entity type is passed.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if the entity type hosts documents. FALSE if not; the output
   *   property is set with the corrective.
   */
  protected function requireDocumentHostType(string $entity_type): bool {
    $entity_type_def = $this->entityTypeManager->getDefinition($entity_type, FALSE);
    if ($entity_type_def === NULL) {
      $this->output = "Unknown entity type: $entity_type.";
      return FALSE;
    }
    if (!$entity_type_def->entityClassImplements(FieldableEntityInterface::class)
      && !$entity_type_def->entityClassImplements(LayoutEntityDisplayInterface::class)
      && !$entity_type_def->entityClassImplements(FrameInterface::class)) {
      $this->output = "This tool works with hosts of a page document: content entities (node, block_content, paragraph), "
        . "templates (entity_view_display, a bundle's default layout, addressed by its display id such as node.page.default) "
        . "and the site frame (daedalus_frame, addressed by its theme's machine name). "
        . "For other config entities like '$entity_type', use eb_edit: an update_properties operation "
        . "for properties, and the plugin-collection operations (add_plugin, update_plugin, "
        . "remove_plugin, reorder_plugins) for plugin collections.";
      return FALSE;
    }
    return $this->requireVisibleEntityType($entity_type);
  }

  /**
   * Guards that the entity type is a content (fieldable) entity.
   *
   * The doors with no host in hand (a birth, a bundle's grammar) are
   * content doors: a template is born by enabling Layout Builder on a
   * bundle's display (config, never created through a blueprint), and its
   * grammar is its target bundle's. Call after requireDocumentHostType()
   * in eb_create_entity, page_create and eb_read_schema.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if the entity type is fieldable. FALSE if not; the output
   *   property is set with the corrective.
   */
  protected function requireFieldableType(string $entity_type): bool {
    $entity_type_def = $this->entityTypeManager->getDefinition($entity_type, FALSE);
    if ($entity_type_def !== NULL && $entity_type_def->entityClassImplements(FieldableEntityInterface::class)) {
      return TRUE;
    }
    $this->output = "'$entity_type' is a template type, not a content type. A template (a bundle's default layout) "
      . "is born by enabling Layout Builder on the bundle's display, never through this tool; read and edit an "
      . "existing template with page_read and page_edit (entity_type entity_view_display, entity_id its display id), "
      . "and read the grammar of what it renders with eb_read_schema for the target type and bundle.";
    return FALSE;
  }

  /**
   * Guards that AI visibility filtering does not hide the entity type.
   *
   * Types and bundles hidden from the AI (daedalus_blueprint_ai.settings or
   * hook_daedalus_blueprint_ai_bundle_list_alter()) are refused by every
   * tool, not merely dropped from eb_list_bundles: unlisted means
   * unreachable, otherwise hiding a type would only be a suggestion the AI
   * could route around by naming the type directly. The refusal wording
   * matches eb_list_bundles', so a hidden type is indistinguishable from an
   * unknown one.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string|null $bundle
   *   A bundle to check within the type, or NULL for the type itself.
   *
   * @return bool
   *   TRUE if the tool may proceed. FALSE if hidden; the output property
   *   is set with the refusal message.
   */
  protected function requireVisibleEntityType(string $entity_type, ?string $bundle = NULL): bool {
    if (!$this->bundleListBuilder->isHidden($entity_type, $bundle)) {
      return TRUE;
    }
    $this->output = $this->bundleListBuilder->isHidden($entity_type)
      ? "Unknown or hidden entity type: $entity_type."
      : "Unknown or hidden bundle: $entity_type/$bundle.";
    return FALSE;
  }

  /**
   * Formats a BlueprintResult as readable YAML output.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The blueprint result to format.
   *
   * @return string
   *   The formatted output string.
   */
  protected function formatResult(BlueprintResultInterface $result): string {
    return $this->formatArray($result->toArray());
  }

  /**
   * Deserialization options every AI tool passes to blueprint operations.
   *
   * LLMs sometimes HTML-escape plain-text tool arguments (&amp; emitted
   * inside function-call JSON), which would be stored verbatim and render
   * double-escaped. decode_html_entities normalizes strings headed for
   * plain-text storage; rich-text (formatted) values are never touched.
   * This is AI-path-only by design: a literal &amp; from a human API
   * consumer must survive, so the base module never sets it.
   *
   * @return array
   *   The options to pass to operations/backend mutation methods.
   */
  protected function blueprintOptions(): array {
    return [
      'decode_html_entities' => TRUE,
      // The lane's origin stamp: a material the AI mints through any tool
      // carries origin "ai" for the life of the site (the mint gate's
      // provenance).
      MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Guards structured ('map') parameters against unusable values. A value
   * the data layer cannot hold (a plain string that is not JSON, a scalar)
   * makes Map::setValue() throw, and the agent loop hydrates tools
   * (populateValues() during response conversion) with no try/catch around
   * it, so the throw would crash the whole loop instead of producing a tool
   * result the model can correct from. The corrective is stashed here and
   * emitted by requireValidParams() at the top of execute().
   */
  public function setContextValue($name, $value) {
    try {
      return parent::setContextValue($name, $value);
    }
    catch (\InvalidArgumentException $e) {
      $this->parameterCorrectives[$name] = sprintf(
        "Invalid value for the '%s' parameter: expected structured data (an object or array matching the parameter description), got a value that could not be interpreted as one. Re-send the call with '%s' as a native JSON structure, not an encoded string.",
        $name,
        $name,
      );
      return $this;
    }
  }

  /**
   * Guards that no parameter arrived in an unusable shape.
   *
   * Call first in execute() of every tool with a structured ('map')
   * parameter. When a corrective was stashed by setContextValue(), sets it
   * as the tool output so the model can re-send the call correctly.
   *
   * @return bool
   *   TRUE if the tool may proceed. FALSE if a parameter was malformed; the
   *   output property carries the corrective.
   */
  protected function requireValidParams(): bool {
    if ($this->parameterCorrectives === []) {
      return TRUE;
    }
    $this->output = implode("\n", $this->parameterCorrectives);
    return FALSE;
  }

  /**
   * Formats an array as YAML.
   *
   * @param array $data
   *   The data to format.
   *
   * @return string
   *   The YAML-formatted string.
   */
  protected function formatArray(array $data): string {
    return Yaml::dump($data, 10, 2);
  }

  /**
   * Formats a markup projection for tool output, legend included.
   *
   * The projection is the element model's HTML print format (see
   * \Drupal\daedalus_blueprint\Markup\MarkupProjectorInterface); the legend
   * spells its reading conventions concretely so the projection stands on
   * its own inside a tool result. A NULL projection with no projector
   * installed is the socket standing empty — the corrective names the
   * modes that do work instead of inventing markup.
   *
   * @param string|null $html
   *   The projected HTML, or NULL when nothing answered.
   *
   * @return string
   *   The tool output.
   */
  protected function formatMarkupProjection(?string $html): string {
    if ($html === NULL) {
      return $this->markupProjection->hasProjector()
        ? 'No markup projection answered for this target.'
        : 'Markup mode is unavailable: no markup projector is installed (the page builder supplies one). Use the data modes instead.';
    }
    return implode("\n", [
      'Key-annotated markup (the element model\'s print format, not rendered output):',
      '- data-key is the element\'s selectable key; data-type is its type.',
      '- an inner element\'s class composes a sub-element key: KEY::.class-name (the "::" suffix is a relative selector; KEY::> * is every direct child, KEY::p every paragraph).',
      '- wrappers with no declared tag print as <div>; text is excerpted, not complete.',
      '',
      $html,
    ]);
  }

  /**
   * Appends the write echo to a formatted mutation result.
   *
   * Mutating tool results carry the resolved markup of the elements they
   * changed, projected through the markup-projection socket, so the model
   * verifies its write in the same turn it made it instead of opening a
   * read-back-then-style loop (intent decays across turns; tool results
   * are same-turn). The echo reuses formatMarkupProjection()'s framing so
   * write results and markup-mode reads speak one print format.
   *
   * With no projector installed the echo is silently absent: a write
   * result is not the place to advertise a missing markup mode, and the
   * result YAML already says what happened.
   *
   * The echo also carries the structural audit: heading-ladder and
   * dead-link advisories over the projected fragments, corrective-shaped
   * and NON-blocking (the result's success is already sealed; advisories
   * never fail a save). Each fragment is audited on its own, because
   * heading order across unrelated fragments is not document order.
   *
   * @param string $output
   *   The formatted result output (the YAML the tool already emits).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The successful mutation result carrying the staged host and its
   *   change targets.
   * @param bool $whole_entity
   *   TRUE to echo the whole entity's projection regardless of targets
   *   (entity creation: the whole page is the affected target).
   *
   * @return string
   *   The output with the markup echo appended, or unchanged when nothing
   *   projects.
   */
  protected function withMarkupEcho(string $output, BlueprintResultInterface $result, bool $whole_entity = FALSE): string {
    $projections = $this->projectAffectedTargets($result, $whole_entity);
    if ($projections === []) {
      return $output;
    }
    $output .= "\n" . $this->formatMarkupProjection(implode("\n", $projections));

    $advisories = [];
    foreach ($projections as $html) {
      $advisories[] = $this->structuralAuditor->audit($html);
    }
    $advisories = array_values(array_unique(array_merge(...$advisories)));
    if ($advisories !== []) {
      $output .= "\n\nStructural advisories (non-blocking; the write is saved):\n- "
        . implode("\n- ", $advisories);
    }
    return $output;
  }

  /**
   * Projects the result's affected targets as key-annotated markup.
   *
   * One projection per affected element: the root entity target projects
   * the whole page (a restructure or root-field write changes the page's
   * arrangement), element targets project individually, and a removed
   * target prints an HTML comment naming the UUID, because gone-ness is
   * the fact to verify and the model owns no markup for it. Projections
   * nested inside another target's projection are dropped, so a batch
   * that composes a section and its children echoes the section once
   * with the children where they mounted.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The successful mutation result.
   * @param bool $whole_entity
   *   TRUE to project the whole entity regardless of targets.
   *
   * @return string[]
   *   The projected fragments, one per surviving target, or empty when
   *   nothing projects (no projector installed, a config-entity result,
   *   or no target the projector answers for).
   */
  private function projectAffectedTargets(BlueprintResultInterface $result, bool $whole_entity): array {
    if (!$this->markupProjection->hasProjector() || !$result instanceof BlueprintResult) {
      return [];
    }
    $host = $result->getHost();
    if ($host === NULL) {
      return [];
    }

    if ($whole_entity) {
      $html = $this->markupProjection->projectHost($host);
      return $html === NULL ? [] : [$html];
    }

    $targets = [];
    foreach ($result->getTargets() as $target) {
      $targets[(string) $target['uuid']][] = (string) $target['field_name'];
    }

    $projections = [];
    foreach ($targets as $uuid => $field_names) {
      if (in_array(TargetType::REMOVED, $field_names, TRUE)) {
        $projections[$uuid] = sprintf('<!-- %s: removed, no longer on the page -->', $uuid);
        continue;
      }
      $projections[$uuid] = $uuid === $host->uuid()
        ? $this->markupProjection->projectHost($host)
        : $this->markupProjection->projectElement($host, $uuid);
    }
    $projections = array_filter($projections, static fn (?string $html) => $html !== NULL);

    // Drop projections nested inside another target's projection: keyed
    // boxes print their UUID in data-key, so containment is literal.
    foreach ($projections as $uuid => $html) {
      foreach ($projections as $other_uuid => $other_html) {
        if ($other_uuid !== $uuid && str_contains($other_html, $uuid)) {
          unset($projections[$uuid]);
          break;
        }
      }
    }

    return array_values($projections);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->output;
  }

}
