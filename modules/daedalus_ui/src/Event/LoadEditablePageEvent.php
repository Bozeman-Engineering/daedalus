<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityInterface;

/**
 * Dispatched when LoadEditablePage builds its AJAX response.
 *
 * Subscribers can add AJAX commands to the response to perform UI changes
 * that accompany the edit mode toggle (e.g., swapping blocks in/out).
 */
class LoadEditablePageEvent extends Event {

  public function __construct(
    protected AjaxResponse $response,
    protected EntityInterface $entity,
    protected string $viewMode,
    protected string $mode,
  ) {}

  /**
   * Gets the AJAX response subscribers add commands to.
   */
  public function getResponse(): AjaxResponse {
    return $this->response;
  }

  /**
   * Gets the entity being loaded.
   */
  public function getEntity(): EntityInterface {
    return $this->entity;
  }

  /**
   * Gets the view mode.
   */
  public function getViewMode(): string {
    return $this->viewMode;
  }

  /**
   * The current navigation mode ('edit' or 'view').
   */
  public function getMode(): string {
    return $this->mode;
  }

}
