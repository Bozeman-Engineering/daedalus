<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\ModeState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lands a newly created entity in its bundle's initial mode.
 *
 * The response half serves every door that queues a mode cookie
 * (ModeState::queueModeCookie): the cookie waits in the session until this
 * subscriber sets it on the response, path-scoped to the page it is for.
 * The entity half is this subscriber's own door: a bundle with an
 * initial_mode configured queues the cookie for the created entity's page
 * on hook_entity_insert, so the redirect after the add form lands there in
 * that mode.
 */
final class InitialMode implements EventSubscriberInterface {

  /**
   * Constructs an InitialMode object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModeState $modeState,
  ) {}

  /**
   * Prepares initial mode for a newly created entity.
   *
   * Checks the entity bundle's initial_mode setting and queues the mode
   * cookie to be set on the redirect response.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was just created.
   */
  public function prepare(EntityInterface $entity): void {
    // Check if this bundle should automatically enter a Mode.
    if ($bundle_entity_type = $entity->getEntityType()->getBundleEntityType()) {
      $bundle_entity = $this->entityTypeManager->getStorage($bundle_entity_type)->load($entity->bundle());
      if ($bundle_entity === NULL) {
        // No bundle config entity, no initial-mode setting to read. This
        // runs inside hook_entity_insert() for EVERY entity, so it must not
        // fatal someone else's save of an entity whose bundle config is
        // absent (e.g. a test entity of an uninstalled bundle).
        return;
      }
      $initial_mode = $bundle_entity->getThirdPartySetting('daedalus_ui', 'initial_mode', 'none');

      if ($initial_mode !== 'none') {
        $this->modeState->queueModeCookie($entity->toUrl()->toString(), $initial_mode);
      }
    }
  }

  /**
   * Sets the queued mode cookie on the response.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function setResponseCookies(ResponseEvent $event): void {
    $cookie = $this->modeState->takeQueuedModeCookie();
    if ($cookie !== NULL) {
      $event->getResponse()->headers->setCookie($cookie);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['setResponseCookies'],
    ];
  }

}
