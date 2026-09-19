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
 * contents; a child positions itself): two segmented rows over align-self
 * (vertical under grid and flex row) and justify-self (horizontal under
 * grid — the property that exists for grid items but not flex items, which
 * is why grid is the canonical container upgrade). Root-bound on the block
 * and field kinds, so writes land on the selected element's own style-map
 * entry. Under a plain block parent the JS half rides the compound
 * gesture — the child's property plus the parent's display: grid in one
 * atomic commit, one undo step — the single sanctioned exception to "write
 * locally, read contextually" (js/src/widgets/position/index.js).
 */
#[ControlWidget(
  id: 'position',
  label: new TranslatableMarkup('Position'),
  section: ControlSection::Layout,
  properties: [
    'align-self',
    'justify-self',
  ],
)]
final class Position extends ControlWidgetBase {
}
