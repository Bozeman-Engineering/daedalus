<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\daedalus_blueprint_api\Render\SubtreeRenderer;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Assembles the JSON contract every mutation path returns.
 *
 * The contract is the heartbeat of the editing client: `blueprint` re-renders
 * the page-elements panel, `markup` is swapped into the canvas by target
 * subtree, `targets` labels history entries and identifies what changed, and
 * `errors` confirms or rolls back queued operations. Additionally,
 * `attachments` carries asset commands to play before the markup swap and
 * `drupal_commands` carries piggybacked AJAX commands to play after it, and
 * `materials` carries the {upsert, remove} delta of every material (token,
 * swatch, variant, stencil, animation) this request changed, whichever
 * lane changed
 * it, so the client store learns a mint the moment it lands
 * (MaterialsChangeSet).
 *
 * The BlueprintMutationResponseEvent is dispatched during assembly so other
 * modules can alter the payload or append commands.
 */
final class MutationResponseBuilder {

  /**
   * Constructs a MutationResponseBuilder.
   *
   * @param \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver $backendResolver
   *   The blueprint backend resolver.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\daedalus_blueprint_api\Render\SubtreeRenderer $subtreeRenderer
   *   The subtree renderer producing the markup and attachments keys.
   * @param \Psr\Log\LoggerInterface $logger
   *   The daedalus_blueprint logger channel.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsChangeSet $materialsChangeSet
   *   The materials this request changed, flushed into every payload.
   */
  public function __construct(
    protected BlueprintBackendResolver $backendResolver,
    protected EventDispatcherInterface $eventDispatcher,
    protected SubtreeRenderer $subtreeRenderer,
    protected LoggerInterface $logger,
    protected MaterialsChangeSet $materialsChangeSet,
  ) {}

  /**
   * A payload carrying only this request's materials delta.
   *
   * The contract shape with no entity behind it: an AI tool that persisted
   * a NON-PAGE entity (a swatch, a variant) mutated nothing the blueprint
   * or markup channels describe, yet the client must still learn the mint.
   * The delta rides the same applyResponse pipeline as every other payload;
   * with no blueprint the mirror stays, with no markup nothing swaps.
   *
   * @return array
   *   The decoded payload: success, the empty channels, and `materials`.
   */
  public function materialsPayload(): array {
    return [
      'success' => TRUE,
      'targets' => [],
      'errors' => [],
      'warnings' => [],
      'markup' => new \stdClass(),
      'attachments' => [],
      'drupal_commands' => [],
      'materials' => $this->materialsChangeSet->flush(),
    ];
  }

  /**
   * Builds the mutation response for a blueprint result.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The mutation request.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the mutation targeted.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The mutation result.
   * @param string|null $langcode
   *   The language code the client is editing in, or NULL for the default.
   * @param array $extra
   *   Additional payload keys (e.g. 'persisted').
   * @param string $view_mode
   *   The view mode the client is displaying, for the markup re-render.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response: 200 on success, 422 on validation failure.
   */
  public function build(Request $request, EntityInterface $entity, BlueprintResultInterface $result, ?string $langcode = NULL, array $extra = [], string $view_mode = 'full'): JsonResponse {
    $payload = [
      'success' => $result->isSuccess(),
      'phase' => $result->getPhase(),
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'entity_uuid' => $entity->uuid(),
      'langcode' => $langcode,
      'targets' => [],
      'errors' => array_map(
        fn(BlueprintError $error) => $error->toArray(),
        $result->getErrors(),
      ),
      'warnings' => array_map(
        fn(BlueprintWarning $warning) => $warning->toArray(),
        $result->getWarnings(),
      ),
      // Re-rendered markup keyed by selectable key; empty for failed mutations.
      'markup' => new \stdClass(),
      'attachments' => [],
      'drupal_commands' => [],
    ] + $extra;

    if ($result instanceof BlueprintResult) {
      $payload['targets'] = $result->getTargets();
    }

    $result_entity = $result->getEntity();
    if ($result->isSuccess() && $result_entity !== NULL) {
      $backend = $this->backendResolver->resolve($result_entity->getEntityTypeId());
      if ($backend !== NULL) {
        $payload['blueprint'] = $backend->serialize($result_entity, $langcode);
      }

      if ($result_entity instanceof FieldableEntityInterface) {
        try {
          $targets = $result instanceof BlueprintResult ? $result->getTargets() : [];
          $render = $this->subtreeRenderer->render($result_entity, $targets, $view_mode, $langcode);
          if ($render['markup'] !== []) {
            $payload['markup'] = $render['markup'];
          }
          $payload['attachments'] = $render['attachments'];
        }
        catch (\Exception $e) {
          // The mutation already persisted; a render failure must not report
          // the whole request as failed. Degrade to no markup and say so.
          $this->logger->error('Subtree render failed for @type:@id: @message', [
            '@type' => $result_entity->getEntityTypeId(),
            '@id' => $result_entity->id(),
            '@message' => $e->getMessage(),
          ]);
          $payload['warnings'][] = [
            'path' => 'markup',
            'code' => 'RENDER_FAILED',
            'message' => 'The mutation was applied but re-rendered markup could not be produced.',
          ];
        }
      }
    }

    // Every material the request changed, whichever operation or hook
    // changed it (a stencil saved mid-batch, a swatch an AI tool minted
    // before this build): flushed here so one response carries it once.
    // A failed batch may still have minted (the stencil gestures' documented
    // side-effect semantics), so the delta rides failures too.
    $payload['materials'] = $this->materialsChangeSet->flush();

    $event = new BlueprintMutationResponseEvent($request, $entity, $result, $payload);
    $this->eventDispatcher->dispatch($event);
    $payload = $event->getPayload();
    foreach ($event->getCommands() as $command) {
      $payload['drupal_commands'][] = $command->render();
    }

    return new JsonResponse($payload, $result->isSuccess() ? 200 : 422);
  }

  /**
   * Builds an error response for failures outside the operations layer.
   *
   * Keeps the same top-level shape as build() so the client handles every
   * failure uniformly: bad request bodies, workspace requirements, and
   * unexpected exceptions.
   *
   * @param string $code
   *   A stable machine-readable error code (e.g. 'WORKSPACE_REQUIRED').
   * @param string $message
   *   The human-readable message.
   * @param int $status
   *   The HTTP status code.
   * @param array $context
   *   Additional structured context for the client.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON error response.
   */
  public function error(string $code, string $message, int $status, array $context = []): JsonResponse {
    return new JsonResponse([
      'success' => FALSE,
      'errors' => [
        [
          'path' => 'request',
          'code' => $code,
          'message' => $message,
          'context' => $context,
        ],
      ],
      'warnings' => [],
      'targets' => [],
      'markup' => new \stdClass(),
      'attachments' => [],
      'drupal_commands' => [],
      'materials' => $this->materialsChangeSet->flush(),
    ], $status);
  }

}
