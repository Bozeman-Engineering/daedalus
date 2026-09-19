<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_control_test\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A test widget owning a property no property group provides.
 */
#[ControlWidget(
  id: 'test_orphan',
  label: new TranslatableMarkup('Test orphan'),
  section: ControlSection::Layout,
  properties: ['sparkle-intensity'],
)]
final class TestOrphan extends ControlWidgetBase {
}
