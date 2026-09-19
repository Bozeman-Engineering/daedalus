<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A swatch row over the text-bearing selection's color.
 *
 * One segmented row of swatches over the text-bearing selection: color
 * stops write the single color property (the Typography property group
 * owns it; Background owns surface colors), while gradient stops commit
 * the gradient-text recipe as one compound batch (background-image:
 * <gradient> + background-clip: text + color: transparent), so the widget
 * also owns the recipe's two background properties. The built-in neutral
 * swatches are the fallback vocabulary; a guide `allowed:` list on color
 * IS the palette, so a theme's strict-mode text colors reach basic mode
 * with no new concept. Free-form values no swatch can represent render as
 * the explicit "custom" state, a partial or foreign recipe included.
 */
#[ControlWidget(
  id: 'text_color',
  label: new TranslatableMarkup('Text color'),
  section: ControlSection::Text,
  properties: ['color', 'background-image', 'background-clip'],
)]
final class TextColor extends ControlWidgetBase {
}
