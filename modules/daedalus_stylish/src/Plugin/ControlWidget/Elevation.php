<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * Shadow presets over the selection's box-shadow.
 *
 * One choice row, labelled Shadow (the plugin id keeps its elevation spelling:
 * bindings and tombstones name it), writing the single box-shadow property (the
 * Shadow property group owns it). Each preset is a whole shadow value however
 * long, so this is the background/text_color single-property pattern, not the
 * border pair pattern. The built-in None/Low/Medium/High presets are the
 * fallback vocabulary; a guide `allowed:` list on box-shadow IS the elevation
 * scale. Free-form shadows no preset can represent render as the explicit
 * "custom" state.
 */
#[ControlWidget(
  id: 'elevation',
  label: new TranslatableMarkup('Shadow'),
  section: ControlSection::Surface,
  properties: ['box-shadow'],
)]
final class Elevation extends ControlWidgetBase {
}
