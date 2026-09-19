<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ConditionType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ConditionType;
use Drupal\daedalus_stylish\ConditionTypeBase;

/**
 * The user's reduced motion preference.
 */
#[ConditionType(
  id: 'reduced_motion',
  label: new TranslatableMarkup('Reduced motion'),
  axis: 'motion',
  weight: 50,
)]
final class ReducedMotion extends ConditionTypeBase {

  /**
   * {@inheritdoc}
   */
  public function getConditions(): array {
    return [
      'reduced-motion' => [
        'label' => $this->t('Reduced motion'),
        'wrapper' => '@media (prefers-reduced-motion: reduce)',
        'preference' => ['feature' => 'prefers-reduced-motion', 'value' => 'reduce'],
      ],
    ];
  }

}
