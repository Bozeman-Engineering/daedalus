<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ConditionType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ConditionType;
use Drupal\daedalus_stylish\ConditionTypeBase;

/**
 * The user's color scheme preference.
 */
#[ConditionType(
  id: 'color_scheme',
  label: new TranslatableMarkup('Color scheme'),
  axis: 'scheme',
  weight: 40,
)]
final class ColorScheme extends ConditionTypeBase {

  /**
   * {@inheritdoc}
   */
  public function getConditions(): array {
    return [
      'dark' => [
        'label' => $this->t('Dark mode'),
        'wrapper' => '@media (prefers-color-scheme: dark)',
        'preference' => ['feature' => 'prefers-color-scheme', 'value' => 'dark'],
      ],
    ];
  }

}
