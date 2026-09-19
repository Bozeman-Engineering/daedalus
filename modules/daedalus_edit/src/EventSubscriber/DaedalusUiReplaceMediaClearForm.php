<?php

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Drupal\daedalus_edit\DaedalusEditFormTrait;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Daedalus UI replace media.
 */
class DaedalusUiReplaceMediaClearForm implements EventSubscriberInterface {

  use DaedalusEditFormTrait;

  public function __construct(
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists(LayoutBuilderReplaceMedia::class)) {
      $events[LayoutBuilderReplaceMedia::class] = ['onReplace', -100];
    }
    return $events;
  }

  /**
   * On replace.
   *
   * A user has dragged a Media Type compatible file from their desktop to a
   * dropzone on an existing Media Block on the page. DaedalusUiReplaceMedia
   * has replaced the media, lets remove the now stale media form if it exists.
   *
   * @param \Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia $event
   *   The replace media event.
   */
  public function onReplace(LayoutBuilderReplaceMedia $event) {
    $media_block = $event->getMediaBlock();
    $response = $event->getResponse();
    $this->clearForm($response, $media_block);
    $event->setResponse($response);
  }

  /**
   * {@inheritdoc}
   *
   * Never called: this subscriber only uses the trait's AJAX helpers.
   *
   * @todo Move the ajax methods out of the form trait since they can be used
   *   outside of a form.
   */
  public function entityContent(array &$form, FormStateInterface $form_state, ?string $view_mode = NULL) {
    return [];
  }

}
