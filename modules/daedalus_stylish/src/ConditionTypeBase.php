<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Base class for condition type plugins.
 */
abstract class ConditionTypeBase extends PluginBase implements ConditionTypeInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->pluginDefinition['label'];
  }

}
