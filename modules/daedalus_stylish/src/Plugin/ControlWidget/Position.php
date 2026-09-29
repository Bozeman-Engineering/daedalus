<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * Where a child sits inside its parent: both screen axes, child-local.
 *
 * The child half of the basic-mode Figma split (a container arranges its
 * contents; a child positions itself): two segmented rows over the
 * child's placement vocabulary. A container parent is always a flex box
 * by the declared tier (a flex column stacked, a flex row under Columns),
 * so the cross axis is align-self and the main axis of a stack is the
 * auto-margin pair: `margin-top: auto` pins a child to a tall band's
 * bottom, `margin-bottom: auto` to its top, both center it (free space
 * goes to auto margins before justify-content, CSS Flexbox 8.1). A
 * hand-made grid parent keeps align-self + justify-self. Root-bound on
 * the block and field kinds, so writes land on the selected element's own
 * style-map entry; the margins ride the kinds' spacing grant. No parent
 * upgrade exists: every write is the child's own (js/src/widgets/position/
 * index.js).
 */
#[ControlWidget(
  id: 'position',
  label: new TranslatableMarkup('Position'),
  section: ControlSection::Layout,
  properties: [
    'align-self',
    'justify-self',
    'margin-top',
    'margin-bottom',
  ],
)]
final class Position extends ControlWidgetBase {
}
