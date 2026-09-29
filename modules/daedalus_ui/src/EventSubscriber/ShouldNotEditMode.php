<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\daedalus_ui\Event\ShouldNotEditModeEvent;
use Drupal\daedalus_ui\ModeState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Should not edit.
 */
final class ShouldNotEditMode implements EventSubscriberInterface {

  /**
   * Routes that never enter Edit Mode.
   */
  private array $ignoredRoutes = [
    'node.add',
    'entity.node.edit_form',
    'search.view_node_search',
  ];

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AdminContext $routerAdminContext,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModeState $modeState,
  ) {}

  /**
   * Kernel request event handler.
   */
  public function onShouldNotEditMode(ShouldNotEditModeEvent $event): void {
    // Preview mode (?navigationMode=preview, the responsive preview's
    // iframe and the same URL by hand) is the staged page as a visitor
    // sees it: the tempstore swap without the editor. Vetoing the mode
    // here is what keeps the editor out, by the machinery that already
    // omits an unavailable mode: no mode bars, no mode-bound sidebars and
    // their settings (the stylish inspector's pop-out pairing included,
    // which would otherwise make the iframe a second leader on the edit
    // session's scope), no edit bundle. The page_top chrome around it is
    // Chromeless's drop (DaedalusUiHooks::preprocessHtml).
    if ($this->modeState->getMode() === 'preview') {
      $event->setShouldNotEdit();
      return;
    }
    // Edit mode is enabled for this content type.
    $entity = $event->getEntity();
    if ($entity) {
      $bundle_entity_type = $entity->getEntityType()->getBundleEntityType();
      if ($bundle_entity_type) {
        $bundle = $this->entityTypeManager->getStorage($bundle_entity_type)->load($entity->bundle());
        if (!$bundle->getThirdPartySetting('daedalus_ui', 'status', [])) {
          $event->setShouldNotEdit();
          return;
        }
      }
    }
    // User doesn't have edit mode access.
    if (!$this->currentUser->hasPermission('use daedalus ui edit mode')) {
      $event->setShouldNotEdit();
      return;
    }
    $route_match = $this->routeMatch->getCurrentRouteMatch();
    if (in_array($route_match->getRouteName(), $this->ignoredRoutes, TRUE)) {
      $event->setShouldNotEdit();
      return;
    }

    // Don't edit admin pages.
    $route = $route_match->getRouteObject();
    if ($this->routerAdminContext->isAdminRoute($route)) {
      $event->setShouldNotEdit();
      return;
    }
    // Don't edit the home page. If we want to support home page editing we need
    // to revisit the edit cookie as that would set edit=enabled; path=/
    // which would be available on all routes.
    $path = $this->requestStack->getCurrentRequest()->getPathInfo();
    if ($path === '/') {
      $event->setShouldNotEdit();
      return;
    }

    // Ensure that the user can edit this node.
    $entity = $event->getEntity();
    if (empty($entity)) {
      $event->setShouldNotEdit();
    }
    if ($entity && $entity->access('edit', NULL, TRUE)->isForbidden()) {
      $event->setShouldNotEdit();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ShouldNotEditModeEvent::class => ['onShouldNotEditMode'],
    ];
  }

}
