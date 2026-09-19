<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;

/**
 * Layout Builder new media.
 *
 * A file was dragged from the desktop and dropped at the insertion caret.
 * A subscriber resolves which block type carries the file: when it can (a
 * stored file association), it creates the media entity and sets a
 * resolution — the client places the block itself through the blueprint
 * mutation endpoint. When it can't, it sets an AJAX response (the
 * block-type chooser dialog) whose eventual submit hands the same
 * resolution shape back to the client.
 */
class LayoutBuilderNewMedia extends Event {

  /**
   * The dialog response, when a subscriber needs more input.
   */
  protected ?AjaxResponse $response = NULL;

  /**
   * The resolved placement info, or NULL when a dialog response is set.
   *
   * @var array|null
   *   Keys: block_type (the block_content bundle), field_name (the media
   *   reference field on that bundle), media_target_id (the created media
   *   entity's id).
   */
  protected ?array $resolution = NULL;

  public function __construct(
    protected Request $request,
    protected EntityInterface $entity,
    protected string $view_mode,
  ) {}

  /**
   * Gets the entity the media is placed on.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity.
   */
  public function getEntity(): EntityInterface {
    return $this->entity;
  }

  /**
   * Gets the view mode.
   */
  public function getViewMode(): string {
    return $this->view_mode;
  }

  /**
   * Gets the dialog response, if a subscriber set one.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|null
   *   The response, or NULL.
   */
  public function getResponse(): ?AjaxResponse {
    return $this->response;
  }

  /**
   * Sets a dialog response instead of a placement.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response.
   */
  public function setResponse(AjaxResponse $response): void {
    $this->response = $response;
  }

  /**
   * Gets the resolved placement info, or NULL when a dialog response is set.
   */
  public function getResolution(): ?array {
    return $this->resolution;
  }

  /**
   * Sets the resolved placement info.
   */
  public function setResolution(array $resolution): void {
    $this->resolution = $resolution;
  }

  /**
   * Gets the upload request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  public function getRequest(): Request {
    return $this->request;
  }

}
