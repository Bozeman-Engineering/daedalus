<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_test\Controller;

use Drupal\daedalus_ai\EventSubscriber\DeepChatResponseListener;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint_ai\Event\AiToolActionEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Simulates an AI chat turn whose tools persisted a mutation.
 *
 * The real pipeline is: a tool persists → dispatches AiToolActionEvent
 * ('entity_persisted') → AiToolActionSubscriber collects the mutation into
 * the MutatedTurn → DeepChatResponseListener injects the built
 * mutation-response payloads into the ai_chatbot.api JSON response → the
 * client's deep-chat responseInterceptor applies them via the app's
 * applyResponse. The only pieces a test cannot drive are the LLM and the
 * tool run itself, so this route stands in for exactly that boundary: it
 * dispatches the REAL event (through the real dispatcher, into the real
 * subscriber and collector) for targets the test names, and returns the
 * REAL response injection: everything an actual chat turn's response
 * carries, minus the LLM prose.
 *
 * The request body mirrors a chat POST where it matters: `contexts`
 * (view_mode, ajax_page_state) is read by the payload builder exactly as it
 * reads a real chat request's contexts.
 */
class SimulateChatTurn extends ControllerBase {

  public function __construct(
    protected EventDispatcherInterface $eventDispatcher,
    protected EntityStorageHandler $entityStorageHandler,
    protected DeepChatResponseListener $responseListener,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('event_dispatcher'),
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_ai.deepchat_response_listener'),
    );
  }

  /**
   * Dispatches a persisted-mutation event and returns the injected response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. Body JSON: {"targets": [...], "contexts": {...}}.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The upcast entity the simulated tool run mutated.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The chat-shaped JSON body after the real listener injection
   *   (blueprint_responses and/or drupal_commands added).
   */
  public function simulate(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    $body = Json::decode($request->getContent()) ?? [];
    $targets = is_array($body['targets'] ?? NULL) ? $body['targets'] : [];

    // A persisted tool hands the event its working copy; load the same
    // tempstore-aware copy the storage handler would have persisted.
    $working = $this->entityStorageHandler->load($entity->getEntityTypeId(), $entity->id()) ?? $entity;

    $this->eventDispatcher->dispatch(new AiToolActionEvent(
      'entity_persisted',
      'daedalus_ai_test:simulated_tool',
      [
        'entity' => $working,
        'is_new' => FALSE,
        'targets' => $targets,
      ],
    ));

    return new JsonResponse($this->responseListener->injectInto(['text' => 'Simulated turn.'], $request));
  }

}
