<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Builds the canvas of a host: the render the client edits.
 *
 * The mutation-response contract re-renders the mutated host to swap the
 * client's markup by selectable key, and the AJAX way into Edit Mode
 * re-renders the host with its editing attributes. Both need "this
 * host's canvas as a render array": for a content host that is its entity
 * view (the entity view build is the canvas root on a canonical page); a
 * host with no entity view (a Layout Builder display, whose document is
 * its template) has its canvas built by the editing UI. The spine owns
 * the seam (CanvasBuilder collects tagged builders in priority order);
 * the UI owner plugs its builder in via the
 * daedalus_blueprint_api.canvas_builder tag.
 */
interface CanvasBuilderInterface {

  /**
   * Whether this builder renders the host's canvas.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return bool
   *   TRUE when build() answers for the host.
   */
  public function applies(HostInterface $host): bool;

  /**
   * Builds the host's canvas.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host, in its working (staged) state.
   * @param string $view_mode
   *   The view mode the client displays the host in.
   *
   * @return array
   *   The render array of the whole canvas: the root wrapper carrying the
   *   host's identity and everything inside it.
   */
  public function build(HostInterface $host, string $view_mode): array;

}
