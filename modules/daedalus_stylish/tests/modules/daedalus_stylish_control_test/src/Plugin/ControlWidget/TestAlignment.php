<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_control_test\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A test widget owning flex alignment properties (the layout group).
 */
#[ControlWidget(
  id: 'test_alignment',
  label: new TranslatableMarkup('Test alignment'),
  section: ControlSection::Layout,
  properties: ['justify-content', 'align-items'],
)]
final class TestAlignment extends ControlWidgetBase {
}
