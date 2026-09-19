<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\daedalus_edit\Event\SuppressInlineEditing as SuppressInlineEditingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Suppresses inline editing for view modes that should never be editable.
 */
class SuppressInlineEditing implements EventSubscriberInterface {

  /**
   * View modes whose renders never become inline editable.
   *
   * @var string[]
   */
  protected $ignoreViewModes = [
    'media_library',
  ];

  /**
   * Suppresses inline editing for renders in an ignored view mode.
   *
   * @param \Drupal\daedalus_edit\Event\SuppressInlineEditing $event
   *   The suppression event.
   */
  public function onSuppressInlineEditing(SuppressInlineEditingEvent $event) {
    $variables = $event->getEditableFieldAttributes()->getVariables();
    $element = &$variables['element'];
    $view_mode = $element['#view_mode'];
    if (in_array($view_mode, $this->ignoreViewModes, TRUE)) {
      $event->suppress();
      $event->stopPropagation();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SuppressInlineEditingEvent::class => ['onSuppressInlineEditing'],
    ];
  }

}
