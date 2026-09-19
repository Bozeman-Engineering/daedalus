<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Event\SettingsSidebarEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Contributes the layout display toggles to the Edit Mode settings panel.
 *
 * Container outlines is a canvas-wide display preference, not a tool
 * function — it lived on the Layout tool only because it existed. The tool
 * died with the nested-layout mode, so it rides the non-tool settings seam.
 */
final class LayoutSettings implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Adds the container outlines toggle to the settings sidebar.
   *
   * @param \Drupal\daedalus_ui\Event\SettingsSidebarEvent $event
   *   The settings sidebar event.
   */
  public function onBuildSettingsSidebar(SettingsSidebarEvent $event): void {
    $event->setSettings('daedalus_layout_layout', [
      '#attached' => ['library' => ['daedalus_layout/layout_settings']],
      'container_outlines' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'daedalus-layout-toggle-container-outlines',
          'title' => $this->t('Toggle the container outlines'),
          'class' => [],
        ],
        'label' => [
          '#id' => 'daedalus-layout-container-outlines',
          '#title' => $this->t('Container outlines'),
          '#type' => 'checkbox',
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SettingsSidebarEvent::class => ['onBuildSettingsSidebar'],
    ];
  }

}
