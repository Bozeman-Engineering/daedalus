<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
   * "view_mode"?: "full", "ajax_page_state"?: {...}}. The view mode drives the
   * markup re-render; ajax_page_state (as drupal.ajax sends it) lets the
   * response omit already-loaded assets.
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
      $result = $backend->batchOperations($working, $operations);

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
   * - mode: 'full' (default), 'summary', or 'element'.
   * - uuid: the element UUID, required when mode is 'element'.
   * - langcode: optional translation language.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The blueprint payload.
   */
  public function read(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
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

      default:
        return $this->responseBuilder->error(
          'INVALID_MODE',
          "Unknown mode '$mode'. Valid modes: full, summary, element.",
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
