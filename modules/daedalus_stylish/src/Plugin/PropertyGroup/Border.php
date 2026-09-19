<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Border edges, corner radii and the outline.
 *
 * Longhands per side (the Spacing pattern): the per-side shorthands
 * ("border-top: 1px solid red") are multi-part values no widget can honestly
 * represent, while the longhand set maps one property to one control.
 *
 * Four shorthand families (getShorthands()): border-width, border-style
 * and border-color over their four sides, border-radius over its four
 * corners. A grant is stated over the longhands and the roster grants
 * the shorthand wherever every one of them is granted.
 *
 * The outline is the border's twin in grammar (a width, a style, a
 * color) with no sides and an offset, so its four longhands live here
 * and every address granting the group gets them with no grant edits.
 * The `outline` shorthand stays in the wide tier like `border` does: a
 * multi-part value no row represents. outline-style adds `auto` and drops
 * `hidden` (a border-only keyword); outline-offset is signed.
 */
#[PropertyGroup(
  id: 'border',
  label: new TranslatableMarkup('Border'),
  weight: 40,
)]
final class Border extends PropertyGroupBase {

  private const STYLES = ['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'];

  private const OUTLINE_STYLES = [
    'auto',
    'none',
    'solid',
    'dashed',
    'dotted',
    'double',
    'groove',
    'ridge',
    'inset',
    'outset',
  ];

  private const WIDTHS = ['thin', 'medium', 'thick'];

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'border-width' => [
        'label' => $this->t('Border width'),
        'type' => 'dimension',
        'repeat' => 4,
        'keywords' => self::WIDTHS,
      ],
      'border-style' => ['label' => $this->t('Border style'), 'type' => 'keyword', 'keywords' => self::STYLES],
      'border-color' => ['label' => $this->t('Border color'), 'type' => 'color'],
      'border-top-width' => ['label' => $this->t('Border top width'), 'type' => 'dimension', 'keywords' => self::WIDTHS],
      'border-top-style' => ['label' => $this->t('Border top style'), 'type' => 'keyword', 'keywords' => self::STYLES],
      'border-top-color' => ['label' => $this->t('Border top color'), 'type' => 'color'],
      'border-right-width' => [
        'label' => $this->t('Border right width'),
        'type' => 'dimension',
        'keywords' => self::WIDTHS,
      ],
      'border-right-style' => [
        'label' => $this->t('Border right style'),
        'type' => 'keyword',
        'keywords' => self::STYLES,
      ],
      'border-right-color' => ['label' => $this->t('Border right color'), 'type' => 'color'],
      'border-bottom-width' => [
        'label' => $this->t('Border bottom width'),
        'type' => 'dimension',
        'keywords' => self::WIDTHS,
      ],
      'border-bottom-style' => [
        'label' => $this->t('Border bottom style'),
        'type' => 'keyword',
        'keywords' => self::STYLES,
      ],
      'border-bottom-color' => ['label' => $this->t('Border bottom color'), 'type' => 'color'],
      'border-left-width' => [
        'label' => $this->t('Border left width'),
        'type' => 'dimension',
        'keywords' => self::WIDTHS,
      ],
      'border-left-style' => ['label' => $this->t('Border left style'), 'type' => 'keyword', 'keywords' => self::STYLES],
      'border-left-color' => ['label' => $this->t('Border left color'), 'type' => 'color'],
      'border-radius' => ['label' => $this->t('Border radius'), 'type' => 'dimension', 'repeat' => 4],
      'border-top-left-radius' => ['label' => $this->t('Top left radius'), 'type' => 'dimension'],
      'border-top-right-radius' => ['label' => $this->t('Top right radius'), 'type' => 'dimension'],
      'border-bottom-right-radius' => ['label' => $this->t('Bottom right radius'), 'type' => 'dimension'],
      'border-bottom-left-radius' => ['label' => $this->t('Bottom left radius'), 'type' => 'dimension'],
      'outline-width' => ['label' => $this->t('Outline width'), 'type' => 'dimension', 'keywords' => self::WIDTHS],
      'outline-style' => ['label' => $this->t('Outline style'), 'type' => 'keyword', 'keywords' => self::OUTLINE_STYLES],
      'outline-color' => ['label' => $this->t('Outline color'), 'type' => 'color'],
      'outline-offset' => ['label' => $this->t('Outline offset'), 'type' => 'dimension', 'negative' => TRUE],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getShorthands(): array {
    return [
      'border-width' => ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width'],
      'border-style' => ['border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style'],
      'border-color' => ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'],
      'border-radius' => [
        'border-top-left-radius',
        'border-top-right-radius',
        'border-bottom-right-radius',
        'border-bottom-left-radius',
      ],
    ];
  }

}
