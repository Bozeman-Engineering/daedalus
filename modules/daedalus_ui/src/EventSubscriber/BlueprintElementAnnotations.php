<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Annotates the mutation response's blueprint with `_element`.
 *
 * The mutation response is one of the mirror's delivery seams (the others
 * ride drupalSettings through BlueprintMirror: boot render and form
 * pipeline): every response's blueprint replaces the client mirror
 * wholesale, so the panel's projection keeps its `_element` annotations
 * across mutations. Both seams call the one annotator, which calls the one
 * model, so the DOM attribution and the panel agree by construction.
 *
 * The body is the response's ONLY blueprint delivery. The whole-entity
 * render behind the response runs the boot seam's entity view build, and
 * BlueprintMirror::attach() stands down there under the mutation-render
 * flag; this subscriber is the belt to that: it strips
 * `daedalusUi.blueprint` from any settings command among the
 * response's asset commands, touching the key only. The command itself
 * stays (its ajaxPageState is what keeps the client's already-loaded
 * library list true; the materials roster rides beside it).
 */
final class BlueprintElementAnnotations implements EventSubscriberInterface {

  public function __construct(
    private readonly BlueprintElementAnnotator $annotator,
  ) {}

  /**
   * Annotates the payload's blueprint and drops the settings envelope.
   */
  public function onMutationResponse(BlueprintMutationResponseEvent $event): void {
    $payload = $event->getPayload();
    foreach ($payload['attachments'] ?? [] as $delta => $command) {
      if (($command['command'] ?? NULL) === 'settings') {
        unset($payload['attachments'][$delta]['settings']['daedalusUi']['blueprint']);
      }
    }
    $event->setPayload($payload);
    if (empty($payload['blueprint']) || !is_array($payload['blueprint'])) {
      return;
    }
    // The view mode the client is displaying, exactly as the mutation
    // endpoint reads it for the markup re-render
    // (BlueprintApiController::mutate) — the root existence feeders speak
    // for that display.
    $body = Json::decode($event->request->getContent()) ?? [];
    $view_mode = is_string($body['view_mode'] ?? NULL) && $body['view_mode'] !== '' ? $body['view_mode'] : 'full';
    // The blueprint was serialized from the result entity; on success it is
    // the event's entity (the mutation's root). Its type/bundle supply the
    // root field definitions.
    $payload['blueprint'] = $this->annotator->annotate($payload['blueprint'], $event->result->getEntity() ?? $event->entity, $view_mode);
    $event->setPayload($payload);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [BlueprintMutationResponseEvent::class => 'onMutationResponse'];
  }

}
