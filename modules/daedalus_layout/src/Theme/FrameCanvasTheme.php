<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Theme;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;

/**
 * The frame canvas renders in the frame's own theme.
 *
 * A frame is a theme's: its document is composed of that theme's layouts
 * and seen in that theme's skin on every page of it. Its canvas (the
 * frame's canonical route) is therefore rendered in the frame's theme,
 * whatever the site's default front theme is, so a site builder edits the
 * frame as it is seen. Only an installed theme can render; a frame whose
 * theme is gone leaves the negotiation to the default.
 */
final class FrameCanvasTheme implements ThemeNegotiatorInterface {

  public function __construct(
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    return $route_match->getRouteName() === FrameInterface::CANVAS_ROUTE
      && $this->frameOf($route_match) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return $this->frameOf($route_match)?->theme();
  }

  /**
   * The route's frame, when its theme is installed.
   */
  private function frameOf(RouteMatchInterface $route_match): ?FrameInterface {
    $frame = $route_match->getParameter('daedalus_frame');
    return $frame instanceof FrameInterface && $this->themeHandler->themeExists($frame->theme()) ? $frame : NULL;
  }

}
