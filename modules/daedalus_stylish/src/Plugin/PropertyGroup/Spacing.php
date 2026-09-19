<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Padding, margin, and gap.
 *
 * Three shorthand families (getShorthands()): padding and margin over
 * their four physical sides, gap over row-gap and column-gap. A grant is
 * stated over the longhands and the roster grants the shorthand wherever
 * every one of them is granted; padding-inline is not a family, because
 * the logical longhands it sets are outside the vocabulary.
 */
#[PropertyGroup(
  id: 'spacing',
  label: new TranslatableMarkup('Spacing'),
  weight: 10,
)]
final class Spacing extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'padding' => ['label' => $this->t('Padding'), 'type' => 'dimension', 'repeat' => 4],
      'padding-top' => ['label' => $this->t('Padding top'), 'type' => 'dimension'],
      'padding-right' => ['label' => $this->t('Padding right'), 'type' => 'dimension'],
      'padding-bottom' => ['label' => $this->t('Padding bottom'), 'type' => 'dimension'],
      'padding-left' => ['label' => $this->t('Padding left'), 'type' => 'dimension'],
      // The logical inline pair, the one-box container's width spelling:
      // `padding-inline: max(gutter, calc((100% - W) / 2))` constrains a
      // full-bleed band's content on the band itself (the shipped width
      // variants and the skins' container defaults write it).
      'padding-inline' => ['label' => $this->t('Padding inline'), 'type' => 'dimension', 'repeat' => 2],
      'margin' => [
        'label' => $this->t('Margin'),
        'type' => 'dimension',
        'repeat' => 4,
        'negative' => TRUE,
        'keywords' => ['auto'],
      ],
      'margin-top' => [
        'label' => $this->t('Margin top'),
        'type' => 'dimension',
        'negative' => TRUE,
        'keywords' => ['auto'],
      ],
      'margin-right' => [
        'label' => $this->t('Margin right'),
        'type' => 'dimension',
        'negative' => TRUE,
        'keywords' => ['auto'],
      ],
      'margin-bottom' => [
        'label' => $this->t('Margin bottom'),
        'type' => 'dimension',
        'negative' => TRUE,
        'keywords' => ['auto'],
      ],
      'margin-left' => [
        'label' => $this->t('Margin left'),
        'type' => 'dimension',
        'negative' => TRUE,
        'keywords' => ['auto'],
      ],
      'gap' => ['label' => $this->t('Gap'), 'type' => 'dimension', 'repeat' => 2],
      'row-gap' => ['label' => $this->t('Row gap'), 'type' => 'dimension'],
      'column-gap' => ['label' => $this->t('Column gap'), 'type' => 'dimension'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getShorthands(): array {
    return [
      'padding' => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'],
      'margin' => ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'],
      'gap' => ['row-gap', 'column-gap'],
    ];
  }

}
