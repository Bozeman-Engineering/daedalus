<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for mode plugins.
 */
abstract class ModePluginBase extends PluginBase implements ModeInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

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
      $container->get('daedalus_ui.ui'),
    );
  }

  public function __construct(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ModuleExtensionList $extensionList,
    protected DaedalusUi $daedalusUi,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function buildModeButton(): array {
    $mode_state = $this->daedalusUi->getMode();
    $mode_id = $this->getPluginId();

    // Build icon data from the icon path.
    // Icons are stored as SVG files, derive the icon_id from the file name.
    $icon_path = $this->getIconPath();
    $icon_filename = basename($icon_path, '.svg');

    $toolbar_state = $mode_state === $mode_id ? ' active' : '';

    return [
      '#type' => 'inline_template',
      '#template' => "<a data-mode='{{mode_id}}' data-daedalus-ui-tooltip='{{mode}}' class='daedalus-ui-mode-button toolbar-button toolbar-button--primary{{toolbar_state}}' data-index-text='0' data-icon-text='{{icon_text}}' href='javascript:void(0)'>{{ icon('daedalus_ui', icon_id, { class: 'toolbar-button__icon', size: 20 }) }}<span class='toolbar-button__label'>{{label}}</span></a>",
      '#context' => [
        'icon_id' => $icon_filename,
        'mode_id' => $mode_id,
        'label' => $this->label(),
        'icon_text' => $this->label(),
        'toolbar_state' => $toolbar_state,
        'mode' => $this->t('@label', ['@label' => $this->label()]),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function addAttachments(array &$attachments): void {}

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildBars(array &$page_top, ModeInterface $mode): void {
    // Optionally add a top or sidebars.
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): string|TranslatableMarkup {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

}
