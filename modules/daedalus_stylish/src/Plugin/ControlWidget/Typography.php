<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A type scale over the text-bearing selection's font.
 *
 * Four independent stepped rows: font family, size, weight, and line
 * height, each writing only its own property. The built-in stops are
 * the fallback vocabulary; a guide `scale:` binding or `allowed:` list
 * on any of the four properties becomes that row's stops, so a theme's
 * type scale reaches basic mode with no new concept. font-family has no
 * built-in table: its row is the skin's font stacks through the site's
 * `font-family: {scale: font}` binding (daedalus_stylish.page_builder.yml), and
 * absent where nothing binds or mints it. Free-form values a stop cannot
 * represent render as the explicit "custom" state.
 */
#[ControlWidget(
  id: 'typography',
  label: new TranslatableMarkup('Typography'),
  section: ControlSection::Text,
  properties: ['font-family', 'font-size', 'font-weight', 'line-height'],
)]
final class Typography extends ControlWidgetBase {
}
