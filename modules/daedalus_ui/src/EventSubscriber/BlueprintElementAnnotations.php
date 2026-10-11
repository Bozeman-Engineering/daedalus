<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\daedalus_blueprint_api\Event\BlueprintReadEvent;
use Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Annotates the mutation response's blueprint, the verdict beside it.
 *
 * The blueprint gains `_element`; the payload gains the host-level
 * `verdict` (BlueprintElementAnnotator::hostVerdict), as the mirror
 * envelope carries it.
 *
 * The read endpoint's derived modes ride the same annotator (onRead()):
 * the css print heads each element with its words, which are this
 * annotation's `type` and `name`, so the lean tree a read mode projects
 * is annotated exactly as a delivered one (annotateTree()).
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
    private readonly HostResolver $hostResolver,
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
    // The blueprint was serialized from the result's host; on success that
    // is the mutation's root, else the event's entity hosts it. Its
    // type/bundle supply the root field definitions.
    $host = $event->result instanceof BlueprintResult ? $event->result->getHost() : NULL;
    $host ??= $this->hostOf($event->entity);
    if ($host === NULL) {
      return;
    }
    $payload['blueprint'] = $this->annotator->annotate($payload['blueprint'], $host, $view_mode);
    // The host-level verdict rides beside the blueprint, as it does on the
    // mirror envelope, so the document notice follows every mutation.
    $payload['verdict'] = $this->annotator->hostVerdict($payload['blueprint']);
    $event->setPayload($payload);
  }

  /**
   * Annotates the tree a derived read mode projects.
   *
   * The tree's nodes gain `_element` exactly as a delivered blueprint's
   * `layout` wears it; the root is not a tree node and a derived mode
   * prints none.
   */
  public function onRead(BlueprintReadEvent $event): void {
    $host = $this->hostOf($event->entity);
    if ($host === NULL) {
      return;
    }
    $event->setTree($this->annotator->annotateTree($event->getTree(), $host));
  }

  /**
   * The host of an event's entity, or NULL when nothing hosts it.
   */
  private function hostOf(EntityInterface $entity): ?HostInterface {
    return $this->hostResolver->hosts($entity) ? $this->hostResolver->fromEntity($entity) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BlueprintMutationResponseEvent::class => 'onMutationResponse',
      BlueprintReadEvent::class => 'onRead',
    ];
  }

}
