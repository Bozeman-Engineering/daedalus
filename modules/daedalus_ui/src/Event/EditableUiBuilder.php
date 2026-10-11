<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Lets subscribers build the editable canvas of a host.
 *
 * Dispatched over the render the canvas replaces: the route entity's own
 * view build on a canonical page (the entity view is the canvas root
 * there, and the build already wears the main-entity wrapper), or an
 * empty build for a host with no entity view (a template host on core's
 * manage-display route, LoadEditablePage and the mutation re-render for
 * it), where the subscriber's element supplies the root wrapper itself.
 * daedalus_layout answers with the layout_builder_plus element over the
 * host's section storage.
 */
class EditableUiBuilder extends Event {

  public function __construct(
    private array $build,
    private readonly string $mode,
    private readonly HostInterface $host,
    private readonly string $viewMode,
  ) {
  }

  /**
   * Gets the view mode.
   */
  public function getViewMode(): string {
    return $this->viewMode;
  }

  /**
   * The host whose canvas is being built.
   */
  public function getHost(): HostInterface {
    return $this->host;
  }

  /**
   * Gets the current mode.
   */
  public function getMode(): string {
    return $this->mode;
  }

  /**
   * Gets the render array.
   */
  public function getBuild(): array {
    return $this->build;
  }

  /**
   * Replaces the render array.
   */
  public function setBuild(array $build): void {
    $this->build = $build;
  }

}
