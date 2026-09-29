<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Sidebar;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\daedalus_ui\Event\SettingsSidebarEvent;
use Drupal\daedalus_ui\SidebarPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The Edit Mode settings panel.
 *
 * Aggregates the settings contributed via SettingsSidebarEvent into a
 * single right-rail panel, bound to Edit Mode.
 *
 * Slot bottom: the transient panels (settings, notifications, history) share
 * the right rail's bottom slot with the AI chat, leaving the top slot to the
 * persistent Styles inspector — mirroring the left rail, where the persistent
 * page-elements tree anchors the top and the transient palette opens beneath
 * it. Weight orders the toggle-button cluster in the top bar.
 */
#[Sidebar(
  id: 'edit_mode_settings',
  label: new TranslatableMarkup('Edit mode settings'),
  side: 'right',
  weight: 6,
  mode: 'edit',
  slot: 'bottom',
)]
final class SettingsSidebar extends SidebarPluginBase implements ContainerFactoryPluginInterface {

  use ButtonBuilderTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EventDispatcherInterface $eventDispatcher,
    protected EditorSettings $editorSettings,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('daedalus_ui.editor_settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $contents = [
      'header' => $this->buildPanelHeader($this->t('Edit mode settings')),
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['daedalus-ui-panel__body']],
      ],
    ];

    // Chrome density: daedalus_ui owns the spine (the preference, the
    // html attribute, the reveal engine), so its select lives here directly
    // rather than riding the contribution event. A select, not a checkbox,
    // so a middle tier can slot in later without changing the value's shape.
    $contents['body']['chrome_density'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'daedalus-ui-chrome-density-setting',
        'title' => $this->t('Show all editing chrome, or hide secondary chrome until it is relevant.'),
      ],
      'select' => [
        '#type' => 'select',
        '#id' => 'daedalus-ui-chrome-density',
        '#title' => $this->t('Editing chrome'),
        '#options' => [
          'full' => $this->t('Everything'),
          'minimal' => $this->t('Minimal'),
          'clean' => $this->t('Clean'),
        ],
        '#value' => $this->editorSettings->getChromeDensity(),
      ],
    ];

    // Aggregate the settings modules contribute via the event.
    $event = $this->eventDispatcher->dispatch(new SettingsSidebarEvent(), SettingsSidebarEvent::class);
    $contributed_settings = $event->getSettings();
    if (!empty($contributed_settings)) {
      $contents['body'] = array_merge($contents['body'], $contributed_settings);
    }

    return $contents;
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-ui-settings',
      $this->t('Settings'),
      $this->t('Configure Edit Mode'),
      'daedalus_ui',
      'gear',
      ['data-sidebar-button-for' => '#edit_mode_settings'],
    );
  }

}
