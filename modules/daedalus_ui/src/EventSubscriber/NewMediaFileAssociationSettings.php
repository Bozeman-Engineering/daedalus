<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\ToolPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Event\SettingsSidebarEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * New media file association settings.
 */
final class NewMediaFileAssociationSettings implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private ToolPluginManager $toolManager,
    private AccountProxyInterface $account,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Adds the file association settings form to the settings sidebar.
   */
  public function onBuildSettingsSidebar(SettingsSidebarEvent $event): void {

    $file_association_settings = [];
    if ($this->account->isAuthenticated()) {
      $user = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $daedalus_ui_settings = $user->daedalus_ui_settings->getValue();
      if (!empty($daedalus_ui_settings[0]['file_associations'])) {
        $file_association_settings = $daedalus_ui_settings[0]['file_associations'];
      }
    }
    $file_associations_list = [];
    foreach ($file_association_settings as $file_extension => $block_type) {
      $file_associations_list[$file_extension]['#markup'] = sprintf('<div class="setting-wrapper">%s <div class="configured-file-association setting-value">%s</div><div class="remove-association" data-file-extension="%s"></div></div>', $file_extension, $block_type, $file_extension);
    }

    $settings = [
      '#type' => 'details',
      '#title' => $this->t('Media File Associations'),
      // The panel's last section, after Hotkeys at 10.
      '#weight' => 20,
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['daedalus-ui-details', 'setting-details', 'file-association-details'],
      ],
      'description' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['setting-description']],
        'markup' => [
          '#markup' => $this->t('There are no file associations. Drag a file from your desktop and place it in a dropzone. Your chosen Media to Block Type association will be saved here.'),
        ],
      ],
      'current_file_associations' => [
        '#theme' => 'item_list',
        '#items' => $file_associations_list,
        '#attributes' => [
          'class' => ['setting-list'],
        ],
      ],
    ];
    if (!empty($file_association_settings)) {
      $settings['description']['#attributes']['class'][] = 'daedalus-ui-hidden';
    }

    $event->setSettings('media_file_associations', $settings);
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
