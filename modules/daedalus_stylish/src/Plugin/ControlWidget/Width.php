<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A width preset picker over a part's width.
 *
 * Curated stops (e.g. 25% / 50% / 75% / full) writing the single width
 * property, for parts where "how wide is this thing" is the whole story.
 * Arbitrary authored widths render as the explicit "custom" state.
 */
#[ControlWidget(
  id: 'width',
  label: new TranslatableMarkup('Width'),
  section: ControlSection::Layout,
  properties: ['width'],
)]
final class Width extends ControlWidgetBase {
}
