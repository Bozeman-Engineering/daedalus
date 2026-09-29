<?php

namespace Drupal\daedalus_ui\Event;

use Drupal\media\Entity\Media;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Layout Builder replace media.
 *
 * A user has dragged a Media Type compatible file from their browser to a
 * dropzone on an existing Media Block on the page. The subscriber (the Layout
 * Builder integration) commits the replacement as an op on the blueprint
 * spine and answers the mutation-response payload.
 */
class LayoutBuilderReplaceMedia extends Event {

  /**
   * The response a subscriber built.
   */
  protected AjaxResponse $response;

  public function __construct(
    protected Media $media,
    protected EntityInterface $entity,
    protected string $viewMode,
    protected string $mediaReference,
  ) {}

  /**
   * Gets the entity the media is replaced on.
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
    return $this->viewMode;
  }

  /**
   * Gets the media reference field name.
   */
  public function getMediaReference(): string {
    return $this->mediaReference;
  }

  /**
   * Gets the response.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function getResponse(): AjaxResponse {
    return $this->response;
  }

  /**
   * Sets the response.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response.
   */
  public function setResponse(AjaxResponse $response): void {
    $this->response = $response;
  }

  /**
   * Gets the new media entity.
   *
   * @return \Drupal\media\Entity\Media
   *   The media.
   */
  public function getMedia(): Media {
    return $this->media;
  }

}
