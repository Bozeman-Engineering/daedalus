<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\Dialect\CssFragmentDoor;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint_api\Event\BlueprintReadEvent;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * HTTP API for entity blueprint operations.
 *
 * The browser-facing mutation path of the page builder: the editing client
 * POSTs operation batches (the same operation grammar the AI tools use) and
 * receives the mutation-response contract back. Reads serve the staged
 * (tempstore) state so the client always sees the working copy.
 *
 * Route access is entity access (view for reads, update for mutations) plus
 * the CSRF request header check on POST. Field-level access is enforced
 * inside the operations layer per user.
 */
final class BlueprintApiController extends ControllerBase {

  use AjaxPageStateRequestTrait;

  public function __construct(
    protected readonly BlueprintBackendResolver $backendResolver,
    protected readonly BlueprintOperationsInterface $blueprintOperations,
    protected readonly EntityStorageHandler $storageHandler,
    protected readonly MutationResponseBuilder $responseBuilder,
    protected readonly LoggerInterface $logger,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly NodeTreeBuilder $treeBuilder,
    protected readonly DialectCatalogBuilder $catalogBuilder,
    protected readonly DialectPrinter $dialectPrinter,
    protected readonly CssFragmentDoor $fragmentDoor,
    protected readonly ?HistoryRecorder $historyRecorder = NULL,
    protected readonly ?HistoryManager $historyManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_blueprint.backend_resolver'),
      $container->get('daedalus_blueprint.operations'),
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_blueprint_api.mutation_response_builder'),
      $container->get('logger.channel.daedalus_blueprint'),
      $container->get('event_dispatcher'),
      $container->get('daedalus_blueprint.node_tree_builder'),
      $container->get('daedalus_blueprint.dialect_catalog_builder'),
      $container->get('daedalus_blueprint.dialect_printer'),
      $container->get('daedalus_blueprint.css_fragment_door'),
      $container->has('daedalus_tempstore.history_recorder')
        ? $container->get('daedalus_tempstore.history_recorder')
        : NULL,
      $container->has('daedalus_tempstore.history_manager')
        ? $container->get('daedalus_tempstore.history_manager')
        : NULL,
    );
  }

  /**
   * Applies a batch of operations to an entity.
   *
   * Request body: {"operations": [{"op": "...", ...}, ...], "langcode"?: "x",
   * "view_mode"?: "full", "ajax_page_state"?: {...}, "thread_id"?: "x"}. The
   * view mode drives the markup re-render; ajax_page_state (as drupal.ajax
   * sends it) lets the response omit already-loaded assets; thread_id names
   * the chat conversation whose aliases the ops resolve against (the op
   * spine's 'conversation' option: the element ids an author chose and the
   * files the conversation holds, so {_file: name} places an attachment),
   * absent when the client has no conversation.
   * Operations use the batch grammar from BlueprintOperations and are applied
   * atomically — all succeed or all roll back, and a request is one unit of
   * change. Successful mutations persist through the storage handler
   * (tempstore when available, database otherwise).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The mutation-response contract.
   */
  public function mutate(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST_BODY',
        'The request body must be a JSON object.',
        400,
      );
    }

    $operations = $body['operations'] ?? NULL;
    if (!is_array($operations) || $operations === [] || !array_is_list($operations)) {
      return $this->responseBuilder->error(
        'MISSING_OPERATIONS',
        'The request body must contain a non-empty "operations" array.',
        400,
      );
    }

    $langcode = is_string($body['langcode'] ?? NULL) ? $body['langcode'] : NULL;
    $view_mode = is_string($body['view_mode'] ?? NULL) && $body['view_mode'] !== '' ? $body['view_mode'] : 'full';
    $conversation = is_string($body['thread_id'] ?? NULL) ? $body['thread_id'] : '';
    $this->applyAjaxPageState($request, $body);

    $backend = $this->backendResolver->resolve($entity_type);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports the '$entity_type' entity type.",
        400,
      );
    }

    if ($backend->requiresWorkspace()) {
      return $this->responseBuilder->error(
        'WORKSPACE_REQUIRED',
        'Content changes require an active workspace.',
        409,
      );
    }

    // Reload through the backend so operations apply to the staged
    // (tempstore) working copy rather than the saved entity.
    $working = $backend->load($entity_type, $entity->id());
    if ($working === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded.",
        404,
      );
    }

    try {
      // The request's langcode names the translation the ops mutate and
      // the one the response renders: one language for the whole round.
      // The thread id is the conversation the alias layer resolves for,
      // exactly as the page tools pass it.
      $result = $backend->batchOperations($working, $operations, [
        'langcode' => $langcode,
        'conversation' => $conversation,
      ]);

      $extra = [];
      if ($result->isSuccess()) {
        // Annotate the history entry this persist will produce: the client
        // names the gesture ("Moved Heading"), the result supplies targets.
        if ($result instanceof BlueprintResult) {
          $label = is_string($body['label'] ?? NULL) ? $body['label'] : NULL;
          $this->historyRecorder?->annotate($label, $result->getTargets());
        }
        $extra['persisted'] = $this->persistResult($backend, $result);
        // Flush now (instead of the end-of-request fallback) so the response
        // carries the timeline the mutation just extended.
        if ($this->historyRecorder !== NULL && $this->historyManager !== NULL) {
          $this->historyRecorder->flush();
          $extra['history'] = $this->historyManager->metadata($result->getEntity() ?? $working);
        }
      }

      return $this->responseBuilder->build($request, $entity, $result, $langcode, $extra, $view_mode);
    }
    catch (WorkspaceRequiredException $e) {
      return $this->responseBuilder->error('WORKSPACE_REQUIRED', $e->getMessage(), 409);
    }
    catch (\Exception $e) {
      $this->logger->error('Blueprint mutation failed for @type:@id: @message', [
        '@type' => $entity_type,
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->responseBuilder->error(
        'INTERNAL_ERROR',
        'An unexpected error occurred while applying the operations.',
        500,
      );
    }
  }

  /**
   * Returns the blueprint for an entity, reflecting staged edits.
   *
   * Query parameters:
   * - mode: 'full' (default), 'summary', 'element', or 'css'.
   * - uuid: the element UUID, required when mode is 'element' or 'css'.
   * - selector: css mode only, optional; a rule's relative selector under
   *   the element (`> pb-text`, `.quote`), narrowing the print to that
   *   rule's entry and its continuations by descent.
   * - langcode: optional translation language.
   *
   * The css mode answers text/css (printCss()); the others JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The blueprint payload, or the element's CSS.
   */
  public function read(Request $request, string $entity_type, EntityInterface $entity): Response {
    $mode = $request->query->get('mode', 'full');
    $langcode = $request->query->get('langcode') ?: NULL;

    $backend = $this->backendResolver->resolve($entity_type);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports the '$entity_type' entity type.",
        400,
      );
    }

    $working = $backend->load($entity_type, $entity->id());
    if ($working === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded.",
        404,
      );
    }

    switch ($mode) {
      case 'full':
        $blueprint = $backend->serialize($working, $langcode);
        break;

      case 'summary':
        $blueprint = $backend->serializeSummary($working, $langcode);
        break;

      case 'element':
        $uuid = (string) $request->query->get('uuid', '');
        if ($uuid === '' || !$working instanceof FieldableEntityInterface) {
          return $this->responseBuilder->error(
            'INVALID_REQUEST',
            'Element mode requires a "uuid" query parameter on a content entity.',
            400,
          );
        }
        $blueprint = $this->blueprintOperations->getElement($working, $uuid, $langcode);
        if ($blueprint === NULL) {
          return $this->responseBuilder->error(
            'ELEMENT_NOT_FOUND',
            "No element with UUID '$uuid' was found on $entity_type:{$entity->id()}.",
            404,
          );
        }
        break;

      case 'css':
        if (!$working instanceof FieldableEntityInterface) {
          return $this->responseBuilder->error(
            'INVALID_REQUEST',
            'CSS mode requires a "uuid" query parameter on a content entity.',
            400,
          );
        }
        return $this->printCss($request, $entity_type, $working, $backend, $langcode);

      default:
        return $this->responseBuilder->error(
          'INVALID_MODE',
          "Unknown mode '$mode'. Valid modes: full, summary, element, css.",
          400,
        );
    }

    return new JsonResponse([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
      'entity_uuid' => $working->uuid(),
      'mode' => $mode,
      'langcode' => $langcode,
      'blueprint' => $blueprint,
    ]);
  }

  /**
   * Prints one element's styles as CSS (read mode css).
   *
   * The server print behind the panel's "Copy CSS": the element the uuid
   * names, pruned to itself (no descendants), printed by
   * DialectPrinter::printStyles() over the staged tree, so the text a
   * designer copies is the dialect the AI reads and the parse door takes
   * back. With `selector`, the print narrows to that rule's entry and its
   * continuations by descent (RelativeSelector::continues()), the
   * clipboard's read at a rule.
   *
   * The tree is built over the serialized blueprint after the
   * BlueprintReadEvent, so a subscriber (daedalus_ui's annotator) can put
   * the element's words on the nodes the way the mutation seam does; the
   * print heads each element with them when present.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request (uuid, selector, langcode on the query).
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $working
   *   The entity in its staged state.
   * @param \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface $backend
   *   The entity type's blueprint backend.
   * @param string|null $langcode
   *   The translation language, or NULL.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   text/css on success; the shared JSON error shape otherwise.
   */
  protected function printCss(Request $request, string $entity_type, FieldableEntityInterface $working, BlueprintBackendInterface $backend, ?string $langcode): Response {
    $uuid = (string) $request->query->get('uuid', '');
    if ($uuid === '') {
      return $this->responseBuilder->error(
        'INVALID_REQUEST',
        'CSS mode requires a "uuid" query parameter on a content entity.',
        400,
      );
    }
    $selector = $request->query->get('selector');
    $rule = NULL;
    if (is_string($selector) && $selector !== '') {
      $rule = RelativeSelector::parse($selector);
      if ($rule === NULL) {
        return $this->responseBuilder->error(
          'INVALID_REQUEST',
          "The selector '$selector' is outside the relative selector grammar.",
          400,
        );
      }
    }

    $event = new BlueprintReadEvent($request, $working, 'css', $backend->serialize($working, $langcode));
    $this->eventDispatcher->dispatch($event);
    $blueprint = $event->getBlueprint();
    $tree = $this->treeBuilder->fromSections(
      $blueprint['layout_builder__layout'] ?? [],
      $this->treeBuilder->styleMap($working),
    );
    $node = NodeTreeBuilder::find($tree, $uuid);
    if ($node === NULL) {
      return $this->responseBuilder->error(
        'ELEMENT_NOT_FOUND',
        "No element with UUID '$uuid' was found on $entity_type:{$working->id()}.",
        404,
      );
    }
    // The element alone: its own entry and its rules, never its
    // descendants' (a subtree print is the printer's, not this mode's).
    unset($node['children']);
    if ($rule !== NULL) {
      // The rule's entry and its continuations; the element's own entry
      // (its wear included) is the element's, not the rule's.
      $node['styles'] = array_filter(
        $node['styles'] ?? [],
        static function ($local) use ($rule): bool {
          $local = (string) $local;
          if (!str_starts_with($local, RelativeSelector::SUFFIX)) {
            return FALSE;
          }
          $candidate = RelativeSelector::parse(substr($local, strlen(RelativeSelector::SUFFIX)));
          return $candidate !== NULL && ($candidate->equals($rule) || RelativeSelector::continues($rule, $candidate));
        },
        ARRAY_FILTER_USE_KEY,
      );
    }

    $css = $this->dialectPrinter->printStyles([$node], $this->catalogBuilder->build());
    return new Response($css, 200, ['Content-Type' => 'text/css; charset=UTF-8']);
  }

  /**
   * Parses a CSS fragment under one style key: the panel's paste door.
   *
   * Request body: {"key": "container:<uuid>[::selector]", "css": "..."}.
   * A read that writes nothing: the fragment parses through
   * CssFragmentDoor over the staged working copy, and the response carries
   * the flat entries, the rule wear and the correctives for the client to
   * turn into its own batch (the element document) or stage (the variant
   * document). Update access and the CSRF header gate it as a mutation is
   * gated, because the answer feeds a write of the editor's own text.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {key, entries, wear, errors}; the shared error shape for a request
   *   outside the contract.
   */
  public function parseCss(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST_BODY',
        'The request body must be a JSON object.',
        400,
      );
    }
    $key = $body['key'] ?? NULL;
    $css = $body['css'] ?? NULL;
    if (!is_string($key) || $key === '' || !is_string($css)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST',
        'The request body must carry "key" (the style key the text belongs to) and "css" (the fragment).',
        400,
      );
    }

    $backend = $this->backendResolver->resolve($entity_type);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports the '$entity_type' entity type.",
        400,
      );
    }
    // The staged working copy: the tree the keys and the ownership answers
    // come from is the one the client is editing.
    $working = $backend->load($entity_type, $entity->id());
    if (!$working instanceof FieldableEntityInterface) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded as a content entity.",
        404,
      );
    }

    return new JsonResponse(['key' => $key] + $this->fragmentDoor->parse($working, $key, $css));
  }

  /**
   * Persists a successful mutation result.
   *
   * Content entities route through the storage handler (tempstore-first, with
   * the persist hook). Other backends persist directly.
   *
   * @param \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface $backend
   *   The backend that produced the result.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The successful result.
   *
   * @return string
   *   Where the entity was persisted: 'database' or 'tempstore'.
   */
  protected function persistResult(BlueprintBackendInterface $backend, BlueprintResultInterface $result): string {
    if ($result instanceof BlueprintResult) {
      return $this->storageHandler->persist(
        $result->getEntity(),
        element_uuids: $result->getElementUuids(),
      );
    }
    $backend->persist($result->getEntity());
    return 'database';
  }

}
