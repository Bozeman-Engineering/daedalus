<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A blueprint's canvas lands in Edit Mode on its first visit.
 *
 * A blueprint is a page of its own with no public page: its canonical
 * route IS its editing surface, the same shape as block_content's
 * standalone canvas. Every road there ("Open blueprint" on a placement,
 * the listing's edit operation, the AI's link, the address bar) is a plain
 * navigation to that URL, and the navigationMode cookie is scoped to the
 * page it was set on, so nothing a navigation carries can arm the canvas.
 * This subscriber does, through daedalus_ui's one door
 * (ModeState::enterModeOnFirstVisit): the first visit in a session renders
 * in Edit Mode and plants the path-scoped cookie; later visits honour the
 * cookie as the user leaves it, so Edit Mode can still be left. Only for an
 * actor who may update the blueprint: a viewer gets the page as it is, no
 * session started for them.
 *
 * Without daedalus_ui there is no mode to enter, and the subscriber is
 * inert.
 */
final class BlueprintCanvasMode implements EventSubscriberInterface {

  /**
   * The mode state of daedalus_ui, or NULL without daedalus_ui.
   *
   * Untyped on purpose: this module does not depend on daedalus_ui.
   *
   * @var \Drupal\daedalus_ui\ModeState|null
   */
  private readonly ?object $modeState;

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    ?object $modeState = NULL,
  ) {
    $this->modeState = $modeState;
  }

  /**
   * Enters Edit Mode on a blueprint canvas's first visit.
   */
  public function onRequest(RequestEvent $event): void {
    if ($this->modeState === NULL || !$event->isMainRequest()) {
      return;
    }
    if ($this->routeMatch->getRouteName() !== 'entity.blueprint.canonical') {
      return;
    }
    $blueprint = $this->routeMatch->getParameter('blueprint');
    if (!$blueprint instanceof BlueprintInterface || !$blueprint->access('update')) {
      return;
    }
    $this->modeState->enterModeOnFirstVisit($blueprint->toUrl()->toString());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (the router listener runs at 32, upcasting the
    // blueprint) and before the dynamic page cache looks a response up
    // (27): the mode cache context must see the mode this request enters.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

}
