<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lets subscribers veto Edit Mode for an entity.
 */
class ShouldNotEditModeEvent extends Event {

  public function __construct(protected ?EntityInterface $entity) {}

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
   * Gets the entity, if the route has one.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL.
   */
  public function getEntity(): ?EntityInterface {
    return $this->entity;
  }

}
