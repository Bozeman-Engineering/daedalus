<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lets subscribers alter the editable UI build of an entity.
 */
class EditableUiBuilder extends Event {

  public function __construct(
    private $build,
    readonly mixed $mode,
    readonly EntityInterface $entity,
    readonly string $view_mode,
  ) {
  }

  /**
   * Gets the view mode.
   */
  public function getViewMode(): string {
    return $this->view_mode;
  }

  /**
   * Gets the entity being built.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity.
   */
  public function getEntity(): EntityInterface {
    return $this->entity;
  }

  /**
   * Gets the current mode.
   */
  public function getMode(): mixed {
    return $this->mode;
  }

  /**
   * Gets the render array.
   *
   * @return mixed
   *   The render array.
   */
  public function getBuild() {
    return $this->build;
  }

  /**
   * Replaces the render array.
   *
   * @param mixed $build
   *   The render array.
   */
  public function setBuild($build): void {
    $this->build = $build;
  }

}
