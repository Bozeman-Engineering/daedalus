<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for swatch kind plugins.
 *
 * Kinds validate through the property machinery's scalar shapes
 * (PropertyGroupPluginManager::validateShape()) so value hygiene — the
 * ;{}<> ban, the media-reference rejection, the script-scheme scan, the
 * var()/calc()/CSS-wide-keyword pass-throughs — is the ONE set of rules
 * tokens and swatches share, never a per-kind reimplementation.
 */
abstract class SwatchKindBase extends PluginBase implements SwatchKindInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected PropertyGroupPluginManager $propertyGroups,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.daedalus_stylish_property_group'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->pluginDefinition['label'];
  }

}
