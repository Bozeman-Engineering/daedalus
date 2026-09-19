<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Render\Element;
use Drupal\daedalus_edit\Event\FieldAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Default field attributes.
 */
class FormInlineEditorFieldAttributes implements EventSubscriberInterface {

  use AttributesTrait;

  const INLINE_EDITOR_WIDGET_MAPPING = [
    'text_textarea',
    'text_textarea_with_summary',
  ];

  /**
   * Add form item attributes.
   *
   * Add attributes that associate the form element with the rendered
   * page markup.
   *
   * @param \Drupal\daedalus_edit\Event\FieldAttributes $event
   *   The event.
   */
  public function addFormItemAttributesAlter(FieldAttributes $event) {
    $field_name = $event->getFieldName();
    $form = $event->getForm();
    $form_item = &$form[$field_name];
    $widget = self::getWidget($event);

    if (in_array($widget, static::INLINE_EDITOR_WIDGET_MAPPING)) {
      foreach (Element::children($form_item['widget']) as $delta) {
        if (!empty($form_item['widget'][$delta]['#type'])) {
          $form_item['widget'][$delta]['#type'] = 'inline_textarea';
        }
      }
    }
    $event->setForm($form);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Set the inline_textarea before other alter subscribers.
      FieldAttributes::ALTER => ['addFormItemAttributesAlter', 200],
    ];
  }

}
