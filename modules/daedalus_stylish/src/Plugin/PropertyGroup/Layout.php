<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Display, positioning, and flex/grid flow.
 *
 * Gaps live in Spacing (they are spacing whatever the display mode); grid
 * template grammars are 'text' type — beyond structured widgets, precise
 * checks are csstree client-side and "allowed" constraints.
 */
#[PropertyGroup(
  id: 'layout',
  label: new TranslatableMarkup('Layout'),
  weight: 70,
)]
final class Layout extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    $inset = ['type' => 'dimension', 'negative' => TRUE, 'keywords' => ['auto']];
    return [
      'display' => [
        'label' => $this->t('Display'),
        'type' => 'keyword',
        'keywords' => ['block', 'inline-block', 'inline', 'flex', 'inline-flex', 'grid', 'inline-grid', 'none'],
      ],
      'flex-direction' => [
        'label' => $this->t('Flex direction'),
        'type' => 'keyword',
        'keywords' => ['row', 'row-reverse', 'column', 'column-reverse'],
      ],
      'flex-wrap' => [
        'label' => $this->t('Flex wrap'),
        'type' => 'keyword',
        'keywords' => ['nowrap', 'wrap', 'wrap-reverse'],
      ],
      'justify-content' => [
        'label' => $this->t('Justify content'),
        'type' => 'keyword',
        'keywords' => [
          'flex-start',
          'flex-end',
          'center',
          'space-between',
          'space-around',
          'space-evenly',
          'start',
          'end',
        ],
      ],
      'align-items' => [
        'label' => $this->t('Align items'),
        'type' => 'keyword',
        'keywords' => ['stretch', 'flex-start', 'flex-end', 'center', 'baseline', 'start', 'end'],
      ],
      'justify-items' => [
        'label' => $this->t('Justify items'),
        'type' => 'keyword',
        'keywords' => ['stretch', 'start', 'end', 'center', 'left', 'right'],
      ],
      'align-content' => [
        'label' => $this->t('Align content'),
        'type' => 'keyword',
        'keywords' => [
          'stretch',
          'flex-start',
          'flex-end',
          'center',
          'space-between',
          'space-around',
          'space-evenly',
          'start',
          'end',
        ],
      ],
      'align-self' => [
        'label' => $this->t('Align self'),
        'type' => 'keyword',
        'keywords' => [
          'auto',
          'stretch',
          'flex-start',
          'flex-end',
          'center',
          'baseline',
          'start',
          'end',
          'self-start',
          'self-end',
        ],
      ],
      'justify-self' => [
        'label' => $this->t('Justify self'),
        'type' => 'keyword',
        'keywords' => ['auto', 'stretch', 'start', 'end', 'center', 'left', 'right', 'self-start', 'self-end'],
      ],
      'flex-grow' => ['label' => $this->t('Flex grow'), 'type' => 'number'],
      'flex-shrink' => ['label' => $this->t('Flex shrink'), 'type' => 'number'],
      'flex-basis' => [
        'label' => $this->t('Flex basis'),
        'type' => 'dimension',
        'keywords' => ['auto', 'content', 'fit-content', 'min-content', 'max-content'],
      ],
      'order' => ['label' => $this->t('Order'), 'type' => 'number', 'negative' => TRUE],
      'grid-template-columns' => [
        'label' => $this->t('Grid columns'),
        'type' => 'text',
        'example' => 'repeat(3, minmax(0, 1fr))',
        'keywords' => ['none'],
      ],
      'grid-template-rows' => [
        'label' => $this->t('Grid rows'),
        'type' => 'text',
        'example' => 'auto 1fr',
        'keywords' => ['none'],
      ],
      'position' => [
        'label' => $this->t('Position'),
        'type' => 'keyword',
        'keywords' => ['static', 'relative', 'absolute', 'fixed', 'sticky'],
      ],
      'top' => ['label' => $this->t('Top')] + $inset,
      'right' => ['label' => $this->t('Right')] + $inset,
      'bottom' => ['label' => $this->t('Bottom')] + $inset,
      'left' => ['label' => $this->t('Left')] + $inset,
      'z-index' => ['label' => $this->t('Z-index'), 'type' => 'number', 'negative' => TRUE, 'keywords' => ['auto']],
    ];
  }

}
