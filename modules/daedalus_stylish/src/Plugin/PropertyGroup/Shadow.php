<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Box and text shadows.
 *
 * Shadow grammar (offsets + blur + spread + color, comma-repeatable) is a
 * multi-part value: 'text' type — value hygiene plus the script-scheme scan
 * server-side, csstree client-side, "allowed" constraints as the closed-list
 * net where a guide wants one.
 */
#[PropertyGroup(
  id: 'shadow',
  label: new TranslatableMarkup('Shadow'),
  weight: 50,
)]
final class Shadow extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'box-shadow' => [
        'label' => $this->t('Box shadow'),
        'type' => 'text',
        'example' => '0 2px 8px rgb(0 0 0 / 0.25)',
        'keywords' => ['none'],
      ],
      'text-shadow' => [
        'label' => $this->t('Text shadow'),
        'type' => 'text',
        'example' => '0 1px 2px rgb(0 0 0 / 0.5)',
        'keywords' => ['none'],
      ],
    ];
  }

}
