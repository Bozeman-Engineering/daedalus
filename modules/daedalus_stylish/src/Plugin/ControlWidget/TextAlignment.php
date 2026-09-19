<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * Segmented alignment buttons for text-bearing blocks.
 *
 * The first root-bindable widget: text blocks bind it with no element key,
 * so its writes land on the block's root style-map entry (the block kind's
 * typography group sanctions text-align there). One LOCAL write: aligning
 * inline content needs no container cooperation. The adaptive cross
 * axis (align-self) moved to the position widget, which owns both block
 * axes — this widget aligns the text inside the box; position moves the
 * box.
 */
#[ControlWidget(
  id: 'text_alignment',
  label: new TranslatableMarkup('Text alignment'),
  section: ControlSection::Text,
  properties: ['text-align'],
)]
final class TextAlignment extends ControlWidgetBase {
}
