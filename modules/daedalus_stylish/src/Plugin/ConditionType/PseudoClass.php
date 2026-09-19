<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ConditionType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ConditionType;
use Drupal\daedalus_stylish\ConditionTypeBase;

/**
 * Interaction states: hover, focus, active.
 */
#[ConditionType(
  id: 'pseudo_class',
  label: new TranslatableMarkup('Interaction state'),
  axis: 'state',
  weight: 10,
)]
final class PseudoClass extends ConditionTypeBase {

  /**
   * {@inheritdoc}
   */
  public function getConditions(): array {
    return [
      'hover' => ['label' => $this->t('Hover'), 'wrapper' => ':hover'],
      'focus' => ['label' => $this->t('Focus'), 'wrapper' => ':focus'],
      'focus-within' => ['label' => $this->t('Focus within'), 'wrapper' => ':focus-within'],
      'focus-visible' => ['label' => $this->t('Focus visible'), 'wrapper' => ':focus-visible'],
      'active' => ['label' => $this->t('Active'), 'wrapper' => ':active'],
    ];
  }

}
