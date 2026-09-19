<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Tool;

use Drupal\daedalus_ui\Attribute\Tool;
use Drupal\daedalus_ui\ToolPluginBase;
use Drupal\daedalus_ui\ToolPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the tool.
 */
#[Tool(
  id: 'selector',
  label: new TranslatableMarkup('Select'),
  hot_key: 'v',
  weight: 0,
)]
final class Selector extends ToolPluginBase {

  use StringTranslationTrait;

  public function __construct(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ModuleExtensionList $extensionList, protected ToolPluginManager $toolManager, protected AccountProxyInterface $account) {
    parent::__construct($container, $configuration, $plugin_id, $plugin_definition, $extensionList);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('extension.list.module'),
      $container->get('plugin.manager.tools'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIconsPath(): array {
    $path = $this->extensionList->getPath('daedalus_ui');
    return [
      'pack_id' => 'daedalus_ui',
      'icon_id' => 'cursor',
      'mouse_icon' => "url('/$path/assets/pointer-mouse.svg') 3 3",
    ];
  }

}
