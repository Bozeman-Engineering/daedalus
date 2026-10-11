<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\Host\HostInterface;
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
 * swatch, variant, blueprint, animation) this request changed, whichever
 * lane changed
 * it, so the client store learns a mint the moment it lands
 * (MaterialsChangeSet).
 *
 * Every payload is built FOR a canvas, the page the client applying it is
 * on: the delta is resolved against it, so a touched blueprint that canvas
 * does not offer (page scope, no root, a fit the write just ended) reaches
 * the client as a remove. The door that builds the payload names the
 * canvas, because the builder cannot know it: the browser doors' canvas is
 * their route host, and an AI turn's is the page the chat sits on, which
 * is not always the host its tools wrote. A door with no canvas (a
 * read-only error) passes none and the delta rides unfiltered.
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host the payload is for (the page the client is on), or
   *   NULL for a door with none: the delta then rides unfiltered.
   *
   * @return array
   *   The decoded payload: success, the empty channels, and `materials`.
   */
  public function materialsPayload(?HostInterface $canvas = NULL): array {
    return [
      'success' => TRUE,
      'targets' => [],
      'errors' => [],
      'warnings' => [],
      'markup' => new \stdClass(),
      'attachments' => [],
      'drupal_commands' => [],
      'materials' => $this->materialsChangeSet->flush($canvas),
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host the response is for (the page the client is on,
   *   which the mutated entity need not be), or NULL for a door with
   *   none: the delta then rides unfiltered.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response: 200 on success, 422 on validation failure.
   */
  public function build(Request $request, EntityInterface $entity, BlueprintResultInterface $result, ?string $langcode = NULL, array $extra = [], string $view_mode = 'full', ?HostInterface $canvas = NULL): JsonResponse {
    $payload = $this->payload($entity, $result, $langcode) + $extra;

    $result_entity = $result->getEntity();
    if ($result->isSuccess() && $result_entity !== NULL) {
      $backend = $this->backendResolver->resolveFor($result_entity);
      if ($backend !== NULL) {
        $payload['blueprint'] = $backend->serialize($result_entity, $langcode);
      }

      // The markup half is a page host's: a config result renders nothing.
      $host = $result instanceof BlueprintResult ? $result->getHost() : NULL;
      if ($host !== NULL) {
        try {
          $render = $this->subtreeRenderer->render($host, $result->getTargets(), $view_mode, $langcode);
          if ($render['markup'] !== []) {
            $payload['markup'] = $render['markup'];
          }
          $payload['attachments'] = $render['attachments'];
        }
        catch (\Throwable $e) {
          // The mutation already persisted; a render failure must not report
          // the whole request as failed. Degrade to no markup and say so. An
          // \Error (a placed block's build asserting) is a render failure
          // like any other: left to the door's catch it would answer
          // INTERNAL_ERROR for a mutation that landed.
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
    // changed it (a blueprint saved mid-batch, a swatch an AI tool minted
    // before this build): flushed here so one response carries it once.
    // A failed batch may still have minted (the blueprint gestures' documented
    // side-effect semantics), so the delta rides failures too.
    $payload['materials'] = $this->materialsChangeSet->flush($canvas);

    $event = new BlueprintMutationResponseEvent($request, $entity, $result, $payload);
    $this->eventDispatcher->dispatch($event);
    $payload = $event->getPayload();
    foreach ($event->getCommands() as $command) {
      $payload['drupal_commands'][] = $command->render();
    }

    return new JsonResponse($payload, $result->isSuccess() ? 200 : 422);
  }

  /**
   * The refusal of a batch that was never going to persist: a dry run's.
   *
   * The contract's shape for a failed result (the same errors, warnings,
   * phase and identity build() answers, 422) with nothing a persisting
   * door adds: no materials flush, since a dry run must leave the
   * request's delta where it found it, and no response event, since the
   * subscribers extend a payload a canvas will apply. A door that persists
   * nothing answers its refusals here and its successes in its own shape.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the batch targeted.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The failed result.
   * @param string|null $langcode
   *   The language code the client named, or NULL for the default.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response, 422.
   *
   * @throws \LogicException
   *   For a successful result: a success is the door's own answer.
   */
  public function refused(EntityInterface $entity, BlueprintResultInterface $result, ?string $langcode = NULL): JsonResponse {
    if ($result->isSuccess()) {
      throw new \LogicException('A refusal answers a failed result only.');
    }
    $payload = $this->payload($entity, $result, $langcode);
    $payload['materials'] = MaterialsChangeSet::none();
    return new JsonResponse($payload, 422);
  }

  /**
   * The contract's base shape for a result: identity, outcome, channels.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the mutation targeted.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The result.
   * @param string|null $langcode
   *   The language code the client is editing in, or NULL for the default.
   *
   * @return array
   *   The payload without markup, attachments beyond their empty channels,
   *   materials or the persisting door's extras.
   */
  private function payload(EntityInterface $entity, BlueprintResultInterface $result, ?string $langcode): array {
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
    ];
    if ($result instanceof BlueprintResult) {
      $payload['targets'] = $result->getTargets();
    }
    return $payload;
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host, as build() takes it; a door that failed before it
   *   wrote anything has an empty delta and may pass none.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON error response.
   */
  public function error(string $code, string $message, int $status, array $context = [], ?HostInterface $canvas = NULL): JsonResponse {
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
      'materials' => $this->materialsChangeSet->flush($canvas),
    ], $status);
  }

}
