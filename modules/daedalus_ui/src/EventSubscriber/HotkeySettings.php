<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\ToolPluginManager;
use Drupal\daedalus_ui\SidebarPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_ui\Event\SettingsSidebarEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hotkey Settings.
 */
final class HotkeySettings implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private ToolPluginManager $toolManager,
    private SidebarPluginManager $sidebarManager,
    private AccountProxyInterface $account,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Adds the hotkey settings form to the settings sidebar.
   */
  public function onBuildSettingsSidebar(SettingsSidebarEvent $event): void {

    $tools = $this->toolManager->getTools();
    $user_hotkey_settings = [];
    if ($this->account->isAuthenticated()) {
      $user = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $daedalus_ui_settings = $user->daedalus_ui_settings->getValue();
      if (!empty($daedalus_ui_settings[0]['hotkeys'])) {
        $user_hotkey_settings = $daedalus_ui_settings[0]['hotkeys'];
      }
    }
    // The binding map the JS hotkey registry boots from: per-tool switch
    // bindings, each merged with the user's remap. Values are binding
    // strings ('v', 'mod+e', 'alt').
    $hot_keys_settings = [];
    foreach ($tools as $id => $tool) {
      $hot_keys_settings[$id] = [
        'key' => $user_hotkey_settings[$id] ?? $tool->hotKey(),
        'label' => (string) $tool->label(),
        'tool' => TRUE,
      ];
    }
    // Panel toggle bindings, one per sidebar plugin under "sidebar.{id}".
    // Panels without a declared hot_key still register (unbound) so the
    // settings tray lists them and the user can assign a key. Definitions,
    // not applying instances: the map is boot data, and a hotkey for a
    // panel absent from this page simply finds no toggle button.
    foreach ($this->sidebarManager->getDefinitions() as $id => $definition) {
      $binding_id = "sidebar.$id";
      $hot_keys_settings[$binding_id] = [
        'key' => $user_hotkey_settings[$binding_id] ?? $definition['hot_key'] ?? '',
        'label' => (string) $this->t('Toggle the @label panel', ['@label' => $definition['label']]),
        'sidebar' => $id,
      ];
    }
    // Remaps saved for bindings that register client-side (verbs, tool
    // grammar keys): passed through key-only, so the JS registry can apply
    // them at register() time. No label — the registering code owns that.
    foreach ($user_hotkey_settings as $id => $key) {
      if (!isset($hot_keys_settings[$id])) {
        $hot_keys_settings[$id] = ['key' => $key];
      }
    }

    $event->setSettings('hotkeys', [
      '#type' => 'details',
      '#title' => $this->t('Hotkeys'),
      // Below the canvas-wide toggles (daedalus_layout's Container outlines at
      // the default 0), above Media File Associations.
      '#weight' => 10,
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['daedalus-ui-details', 'setting-details', 'hotkey-details'],
      ],
      '#attached' => ['drupalSettings' => ['daedalusUi' => ['hotKeys' => $hot_keys_settings]]],
      // The list itself renders client-side from the hotkey registry
      // (hotkey-settings.js), so JS-registered remappable bindings appear
      // without the server needing to know them.
      'current_hotkeys' => [
        '#markup' => '<div class="hotkeys-list daedalus-ui-details__body"></div>',
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SettingsSidebarEvent::class => ['onBuildSettingsSidebar', 100],
    ];
  }

}
