<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Frame;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * What a route produced, handed to the site frame's render.
 *
 * The frame is rendered around every route of its theme by the page
 * display variant, and the route's output (an entity page, a view, a
 * form, a blueprint's canvas) lands in one slot of the frame. The variant
 * hands the output here, the primitive carries it into the frame render
 * as one context (self::CONTEXT_ID), and the consumers read it: the slot
 * hosting the route output (InnerRender::routeSlot(): the frame's main
 * slot, or the door whose default is the blueprint the route edits, so a
 * blueprint's canvas renders in situ) prints the main content, the main
 * slot prints the stand-in when another slot hosts the output, and a
 * placed title block is given the title (a TitleBlockPluginInterface
 * block shows the title the route resolved, core's contract for the block
 * that shows a page's title).
 */
final class RouteOutput {

  /**
   * The render context the frame render carries the route's output under.
   */
  public const CONTEXT_ID = 'daedalus_blueprint.route_output';

  /**
   * Constructs the route's output.
   *
   * @param array $mainContent
   *   The main content render array (what the controller returned, as the
   *   page display variant receives it).
   * @param string|array|\Stringable $title
   *   The page title: a string, or a render array for a formatted title.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The route's host (the document the route shows: a page, a
   *   blueprint), or NULL on a host-less route (a view page, a form, the
   *   frame's own canvas, where the output is a stand-in). The identity
   *   InnerRender::routeSlot() reads to place the output in the frame.
   * @param array $standIn
   *   What the frame's main slot prints when another slot hosts the route
   *   output: the sentence saying each page's content shows there, in
   *   daedalus_ui's stand-in dress (daedalus_layout's MainContentStandIn).
   *   Empty when the caller has none to offer.
   */
  public function __construct(
    public readonly array $mainContent,
    public readonly string|array|\Stringable $title,
    public readonly ?HostInterface $host = NULL,
    public readonly array $standIn = [],
  ) {}

}
