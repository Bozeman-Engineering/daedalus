<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles the initial mode of newly created entities.
 *
 * When an entity is created with an initial_mode configured, this service
 * sets the appropriate cookies so the page is rendered in that mode.
 */
final class InitialMode implements EventSubscriberInterface {

  /**
   * Constructs an InitialMode object.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Prepares initial mode for a newly created entity.
   *
   * Checks the entity bundle's initial_mode setting and queues cookies
   * to be set on the redirect response.
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
        // Store cookie parameters to be set in the redirect response.
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $url = $entity->toUrl()->toString();
        $session->set('daedalus_ui_set_mode_cookie', [
          'path' => $url,
          'mode' => $initial_mode,
        ]);
      }
    }
  }

  /**
   * Sets the mode cookie on the response.
   *
   * Reads queued cookie parameters from session and adds them to the
   * response headers.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function setResponseCookies(ResponseEvent $event): void {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $response = $event->getResponse();

    // Set navigationMode cookie if requested (for initial Edit Mode).
    $cookie_params = $session->get('daedalus_ui_set_mode_cookie');
    if ($cookie_params) {
      $cookie = new Cookie(
        'navigationMode',
        $cookie_params['mode'],
        0,
        $cookie_params['path'],
        NULL,
        FALSE,
        FALSE
      );
      $response->headers->setCookie($cookie);
      $session->remove('daedalus_ui_set_mode_cookie');
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
