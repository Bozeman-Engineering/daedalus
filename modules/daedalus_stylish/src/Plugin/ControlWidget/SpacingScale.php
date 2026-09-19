<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * A stepped spacing scale (none / S / M / L / XL) over a part's spacing.
 *
 * One scale position drives the part's inner spacing, the padding
 * shorthand, and gap where the part lays out children, instead of eight
 * raw dimension rows. Free-form values a step cannot represent render as
 * the explicit "custom" state.
 *
 * The widget also owns the four padding longhands: the box-model diagram
 * behind the pencil edits per side, expanding the shorthand into longhands
 * on the first per-side edit. Owning them here makes guide-build validation
 * hold every bound target's property groups to per-side writes too, and
 * puts each longhand's definition and constraints into the client control
 * model the same way as the shorthand's.
 */
#[ControlWidget(
  id: 'spacing_scale',
  label: new TranslatableMarkup('Spacing'),
  section: ControlSection::Layout,
  properties: [
    'padding',
    'padding-top',
    'padding-right',
    'padding-bottom',
    'padding-left',
    'gap',
  ],
)]
final class SpacingScale extends ControlWidgetBase {
}
