<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_ui\ModeState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The frame canvas lands in Edit Mode on its first visit.
 *
 * The frame's canonical route IS its editing surface (FrameCanvas), and
 * every road there (the portal from any page of the theme, the AI's link,
 * the address bar) is a plain navigation, which the path-scoped
 * navigationMode cookie cannot arm. This subscriber does, through
 * daedalus_ui's one door (ModeState::enterModeOnFirstVisit), the pattern
 * the blueprint canvas has (daedalus_blueprint_library's
 * BlueprintCanvasMode): the first visit in a session renders in Edit Mode
 * and plants the cookie; later visits honour the cookie as the user leaves
 * it. Only for an actor who may update the frame (`configure the site
 * frame`): anyone else never reaches the route (its access requirement is
 * the same), so no session is started for them.
 */
final class FrameCanvasMode implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly ModeState $modeState,
  ) {}

  /**
   * Enters Edit Mode on the frame canvas's first visit.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->routeMatch->getRouteName() !== FrameInterface::CANVAS_ROUTE) {
      return;
    }
    $frame = $this->routeMatch->getParameter('daedalus_frame');
    if (!$frame instanceof FrameInterface || !$frame->access('update')) {
      return;
    }
    $this->modeState->enterModeOnFirstVisit($frame->toUrl()->toString());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (the router listener runs at 32, upcasting the frame)
    // and before the dynamic page cache looks a response up (27): the mode
    // cache context must see the mode this request enters.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

}
