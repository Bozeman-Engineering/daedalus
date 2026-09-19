<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Box dimensions and overflow.
 */
#[PropertyGroup(
  id: 'sizing',
  label: new TranslatableMarkup('Sizing'),
  weight: 60,
)]
final class Sizing extends PropertyGroupBase {

  private const SIZE_KEYWORDS = ['auto', 'fit-content', 'min-content', 'max-content'];

  private const MAX_KEYWORDS = ['none', 'fit-content', 'min-content', 'max-content'];

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'width' => ['label' => $this->t('Width'), 'type' => 'dimension', 'keywords' => self::SIZE_KEYWORDS],
      'min-width' => ['label' => $this->t('Min width'), 'type' => 'dimension', 'keywords' => self::SIZE_KEYWORDS],
      'max-width' => ['label' => $this->t('Max width'), 'type' => 'dimension', 'keywords' => self::MAX_KEYWORDS],
      'height' => ['label' => $this->t('Height'), 'type' => 'dimension', 'keywords' => self::SIZE_KEYWORDS],
      'min-height' => ['label' => $this->t('Min height'), 'type' => 'dimension', 'keywords' => self::SIZE_KEYWORDS],
      'max-height' => ['label' => $this->t('Max height'), 'type' => 'dimension', 'keywords' => self::MAX_KEYWORDS],
      'aspect-ratio' => [
        'label' => $this->t('Aspect ratio'),
        'type' => 'text',
        'example' => '16 / 9',
        'keywords' => ['auto'],
      ],
      'overflow' => [
        'label' => $this->t('Overflow'),
        'type' => 'keyword',
        'keywords' => ['visible', 'hidden', 'clip', 'scroll', 'auto'],
      ],
    ];
  }

}
