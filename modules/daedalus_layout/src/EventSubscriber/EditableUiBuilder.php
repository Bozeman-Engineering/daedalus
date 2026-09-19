<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\daedalus_layout\DaedalusLayoutEntityHelperTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\daedalus_ui\Event\EditableUiBuilder as EditableUiBuilderEvent;

/**
 * Swaps a Layout Builder entity build for the Daedalus Layout editor.
 */
final class EditableUiBuilder implements EventSubscriberInterface {

  use DaedalusLayoutEntityHelperTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * Replaces the layout fields with the editor in edit mode.
   *
   * @param \Drupal\daedalus_ui\Event\EditableUiBuilder $event
   *   The editable UI build event.
   */
  public function onUiBuild(EditableUiBuilderEvent $event): void {
    if ($event->getMode() !== 'edit') {
      return;
    }
    $entity = $event->getEntity();
    $view_mode = $event->getViewMode();
    $display = _daedalus_ui_get_entity_view_display($entity, $view_mode);
    if ($display && $display->getThirdPartySetting('layout_builder', 'enabled', FALSE)) {
      $section_storage = $this->getSectionStorageForEntity($entity, $display->getMode());
      if ($section_storage) {
        $build = $event->getBuild();
        $preserved_fields = $display->getThirdPartySetting('daedalus_layout', 'preserved_fields', []);

        // Override the node build with the layout builder UI.
        $field_names = array_keys($entity->getFieldDefinitions());
        $field_names = array_diff($field_names, $preserved_fields);
        $build = array_diff_key($build, array_flip($field_names));
        unset($build['_layout_builder']);
        $build['layout'] = [
          '#type' => 'layout_builder_plus',
          '#section_storage' => $section_storage,
        ];
        $event->setBuild($build);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EditableUiBuilderEvent::class => ['onUiBuild'],
    ];
  }

}
