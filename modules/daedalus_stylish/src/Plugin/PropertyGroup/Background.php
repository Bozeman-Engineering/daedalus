<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Surface color and background imagery.
 *
 * Background owns surface colors; TEXT color lives in Typography (Webflow
 * convention — it is set alongside font choices in practice).
 */
#[PropertyGroup(
  id: 'background',
  label: new TranslatableMarkup('Background'),
  weight: 30,
)]
final class Background extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'background-color' => ['label' => $this->t('Background color'), 'type' => 'color'],
      'background-image' => [
        'label' => $this->t('Background image'),
        'type' => 'text',
        'example' => 'linear-gradient(120deg, #1a1a2e, #16213e)',
        'keywords' => ['none'],
        'media' => TRUE,
      ],
      'background-position' => [
        'label' => $this->t('Background position'),
        'type' => 'dimension',
        'repeat' => 4,
        'negative' => TRUE,
        'keywords' => ['center', 'top', 'bottom', 'left', 'right'],
      ],
      'background-size' => [
        'label' => $this->t('Background size'),
        'type' => 'dimension',
        'repeat' => 2,
        'keywords' => ['auto', 'cover', 'contain'],
      ],
      'background-repeat' => [
        'label' => $this->t('Background repeat'),
        'type' => 'keyword',
        'keywords' => ['repeat', 'repeat-x', 'repeat-y', 'no-repeat', 'space', 'round'],
      ],
      'background-attachment' => [
        'label' => $this->t('Background attachment'),
        'type' => 'keyword',
        'keywords' => ['scroll', 'fixed', 'local'],
      ],
      // Curated (not left to the wide tier) so the gradient-text recipe's
      // clip write is legal wherever Background is declared, strict sites
      // included. The curated definition wins over the wide tier's
      // "Backgrounds and Borders" derivative where they overlap.
      'background-clip' => [
        'label' => $this->t('Background clip'),
        'type' => 'keyword',
        'keywords' => ['border-box', 'padding-box', 'content-box', 'text'],
      ],
    ];
  }

}
