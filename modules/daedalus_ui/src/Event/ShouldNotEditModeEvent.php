<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Lets subscribers veto Edit Mode for a route host.
 */
class ShouldNotEditModeEvent extends Event {

  public function __construct(protected ?HostInterface $host) {}

  /**
   * Whether a subscriber vetoed Edit Mode.
   *
   * @var bool
   */
  private $shouldNotEdit = FALSE;

  /**
   * Whether a subscriber vetoed Edit Mode.
   */
  public function shouldNotEdit(): bool {
    return $this->shouldNotEdit;
  }

  /**
   * Vetoes Edit Mode.
   */
  public function setShouldNotEdit(): void {
    $this->shouldNotEdit = TRUE;
  }

  /**
   * The route host, if the route has one.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host (a content entity, or a Layout Builder display on its
   *   manage-layout route), or NULL.
   */
  public function getHost(): ?HostInterface {
    return $this->host;
  }

}
