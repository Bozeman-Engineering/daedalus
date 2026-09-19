<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Font, text, and text color.
 *
 * Text color lives here rather than in a color group — it is set alongside
 * font choices in practice, and Background owns surface colors.
 */
#[PropertyGroup(
  id: 'typography',
  label: new TranslatableMarkup('Typography'),
  weight: 20,
)]
final class Typography extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'font-family' => ['label' => $this->t('Font family'), 'type' => 'family'],
      'font-size' => ['label' => $this->t('Font size'), 'type' => 'dimension'],
      'font-weight' => [
        'label' => $this->t('Font weight'),
        'type' => 'number',
        'keywords' => ['normal', 'bold', 'lighter', 'bolder'],
      ],
      'font-style' => [
        'label' => $this->t('Font style'),
        'type' => 'keyword',
        'keywords' => ['normal', 'italic', 'oblique'],
      ],
      'line-height' => [
        'label' => $this->t('Line height'),
        'type' => 'dimension',
        'number' => TRUE,
        'keywords' => ['normal'],
      ],
      'letter-spacing' => [
        'label' => $this->t('Letter spacing'),
        'type' => 'dimension',
        'negative' => TRUE,
        'keywords' => ['normal'],
      ],
      'text-align' => [
        'label' => $this->t('Text align'),
        'type' => 'keyword',
        'keywords' => ['left', 'center', 'right', 'justify', 'start', 'end'],
      ],
      'text-transform' => [
        'label' => $this->t('Text transform'),
        'type' => 'keyword',
        'keywords' => ['none', 'uppercase', 'lowercase', 'capitalize'],
      ],
      'text-decoration-line' => [
        'label' => $this->t('Text decoration'),
        'type' => 'keyword',
        'keywords' => ['none', 'underline', 'overline', 'line-through'],
      ],
      'color' => ['label' => $this->t('Text color'), 'type' => 'color'],
    ];
  }

}
