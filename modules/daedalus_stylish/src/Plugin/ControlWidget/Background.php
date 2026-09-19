<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A swatch row over the selection's surface color.
 *
 * One segmented row of swatches over the element's surface: color stops
 * write background-color, gradient stops and the gradient builder write
 * background-image (the Background property group owns both; text color
 * lives in Typography). The built-in neutral swatches are the fallback
 * vocabulary; a guide `allowed:` list on background-color IS the palette,
 * so a theme's strict-mode surfaces reach basic mode with no new concept.
 * Free-form values no swatch can represent render as the explicit
 * "custom" state, including a background-image occupied by media, layered
 * values, or the gradient-text recipe.
 *
 * The basic-mode media pick seeds best-guess companions beside the image
 * token in the same one-op batch (background-size: cover,
 * background-repeat: no-repeat, background-position: center), and the
 * lit media stop's unpick clears every set member of that family, so the
 * widget owns the three companion properties too (the TextColor
 * precedent for owning a batch's members): guide-build validation holds
 * every binding's declared groups to the seed's writes, and the client
 * reads the companions through the widget's values. The state read
 * ignores them, so seeded or advanced-edited companions never flip the
 * well custom.
 */
#[ControlWidget(
  id: 'background',
  label: new TranslatableMarkup('Background'),
  section: ControlSection::Surface,
  properties: [
    'background-color',
    'background-image',
    'background-size',
    'background-repeat',
    'background-position',
  ],
)]
final class Background extends ControlWidgetBase {
}
