<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * One gap row over a box that lays out sibling children.
 *
 * The page-level Gap control: daedalus_edit binds it on the entity kind to
 * the layout root daedalus_layout renders (`element: '.daedalus-layout-layout'`), so the
 * page spaces its root bands the way a container spaces its children.
 * A dedicated widget rather than spacing_scale rebound to the part,
 * because the layout root has no inner spacing story (a Padding row
 * there would be meaningless air inside a box that only arranges).
 *
 * The widget owns the two longhands beside the shorthand so guide-build
 * validation holds every bound target to the whole gap family (the
 * spacing group's `gap` over row-gap and column-gap) and the client
 * model carries their definitions; the row itself writes `gap` alone.
 * Hidden by its gate unless the BOUND element lays out more than one
 * child box (a gap between one band is a no-op a lay user cannot see)
 * or a gap is already set.
 */
#[ControlWidget(
  id: 'gap',
  label: new TranslatableMarkup('Gap'),
  section: ControlSection::Layout,
  properties: [
    'gap',
    'row-gap',
    'column-gap',
  ],
)]
final class Gap extends ControlWidgetBase {
}
