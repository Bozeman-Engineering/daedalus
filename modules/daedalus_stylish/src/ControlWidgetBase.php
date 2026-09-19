<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Base class for control widget plugins.
 *
 * A control widget plugin is declaration-only on the server (the attribute
 * carries everything), so a concrete widget is normally an empty class:
 * the attribute plus this base.
 */
abstract class ControlWidgetBase extends PluginBase implements ControlWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSection(): ControlSection {
    return $this->pluginDefinition['section'];
  }

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return $this->pluginDefinition['properties'];
  }

}
