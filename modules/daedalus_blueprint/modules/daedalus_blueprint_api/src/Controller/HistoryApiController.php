<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP API for the edit-history timeline: read it, undo/redo/jump.
 *
 * The read side feeds the history sidebar (entry metadata only — snapshot
 * payloads never leave the server). The restore side moves the timeline
 * pointer through daedalus_tempstore's HistoryManager and answers with the
 * standard mutation-response contract, so the client applies an undo
 * exactly like any other mutation: blueprint replaces the mirror, markup
 * swaps by target subtree, drupal_commands play after.
 *
 * History requires daedalus_tempstore; without it the routes answer 501. Access
 * is entity update access (history is editing state) plus CSRF on POST, and
 * requests must carry navigationMode as a query parameter like every other
 * edit-mode path (the cookie is path-scoped to the edited page).
 */
final class HistoryApiController extends ControllerBase {

  use AjaxPageStateRequestTrait;

  public function __construct(
    protected readonly EntityStorageHandler $storageHandler,
    protected readonly MutationResponseBuilder $responseBuilder,
    protected readonly ?HistoryManager $historyManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_blueprint_api.mutation_response_builder'),
      $container->has('daedalus_tempstore.history_manager')
        ? $container->get('daedalus_tempstore.history_manager')
        : NULL,
    );
  }

  /**
   * Returns the current user's history timeline for an entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   ['entity_type', 'entity_id', 'pointer', 'can_undo', 'can_redo',
   *   'entries'].
   */
  public function timeline(string $entity_type, EntityInterface $entity): JsonResponse {
    $working = $this->workingHost($entity_type, $entity);
    if ($working instanceof JsonResponse) {
      return $working;
    }

    return new JsonResponse([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ] + $this->historyManager->metadata($working));
  }

  /**
   * Restores the staged state to another point on the timeline.
   *
   * Request body: {"direction": "undo"|"redo"} or {"to": seq}, plus the
   * optional "langcode", "view_mode", and "ajax_page_state" the mutate
   * endpoint accepts (they drive the re-render half of the response).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The mutation-response contract, extended with 'history' (the moved
   *   timeline) and 'restored_label'.
   */
  public function restore(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    $working = $this->workingHost($entity_type, $entity);
    if ($working instanceof JsonResponse) {
      return $working;
    }

    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST_BODY',
        'The request body must be a JSON object.',
        400,
      );
    }
    $this->applyAjaxPageState($request, $body);
    $langcode = is_string($body['langcode'] ?? NULL) ? $body['langcode'] : NULL;
    $view_mode = is_string($body['view_mode'] ?? NULL) && $body['view_mode'] !== '' ? $body['view_mode'] : 'full';

    $direction = $body['direction'] ?? NULL;
    if ($direction === 'undo') {
      $restored = $this->historyManager->undo($working);
    }
    elseif ($direction === 'redo') {
      $restored = $this->historyManager->redo($working);
    }
    elseif (is_int($body['to'] ?? NULL)) {
      $restored = $this->historyManager->restoreTo($working, $body['to']);
    }
    else {
      return $this->responseBuilder->error(
        'INVALID_RESTORE_TARGET',
        'The request body must contain "direction": "undo"|"redo" or an integer "to".',
        400,
        canvas: $working,
      );
    }

    if ($restored === NULL) {
      return $this->responseBuilder->error(
        'NOTHING_TO_RESTORE',
        'There is no history entry in that direction.',
        409,
        canvas: $working,
      );
    }

    // Everything after the restore runs with history capture suppressed:
    // the persist hooks and the response render may write tempstore
    // themselves (core's PrepareLayout subscriber re-seeds a key a baseline
    // restore just deleted), and a recorded write here would truncate the
    // timeline the restore just moved when the fallback flush fires.
    return $this->historyManager->suppress(function () use ($request, $entity_type, $entity, $restored, $langcode, $view_mode): JsonResponse {
      // The staged state changed outside EntityStorageHandler::persist — fire
      // the persist hook it would have fired, so listeners (daedalus_edit's cache
      // tag invalidation) see the restore like any other staged write.
      foreach ($restored['hosts'] as $restored_host) {
        $this->moduleHandler()->invokeAll('daedalus_blueprint_entity_persisted', [
          $restored_host,
          FALSE,
          array_values(array_unique(array_column($restored['targets'], 'uuid'))),
        ]);
      }

      // Reload so the response reflects the restored staged state, and answer
      // with the standard contract: the union of the traversed entries'
      // targets identifies the subtrees that differ between the two states.
      // The client restores the page it is on, so the host is the canvas.
      $reloaded = $this->storageHandler->load($entity_type, $entity->id());
      $result = BlueprintResult::success($reloaded)->withTargets($restored['targets']);

      return $this->responseBuilder->build($request, $entity, $result, $langcode, [
        'persisted' => 'tempstore',
        'restored_label' => $restored['label'],
        'history' => $this->historyManager->metadata($reloaded),
      ], $view_mode, $reloaded);
    });
  }

  /**
   * Loads the staged working host, or an error response.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|\Symfony\Component\HttpFoundation\JsonResponse
   *   The working host, or the error to return.
   */
  protected function workingHost(string $entity_type, EntityInterface $entity): HostInterface|JsonResponse {
    if ($this->historyManager === NULL) {
      return $this->responseBuilder->error(
        'HISTORY_UNAVAILABLE',
        'Edit history requires the daedalus_tempstore module.',
        501,
      );
    }
    $working = $this->storageHandler->load($entity_type, $entity->id());
    if ($working === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded.",
        404,
      );
    }
    return $working;
  }

}
