<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * Corner radius presets plus the border and outline strokes.
 *
 * Corners writes the single border-radius property (a guide `allowed:`
 * list on border-radius IS the radius scale). Border and Outline are
 * each one stroke (a color, a width and a style) mapped as one idea:
 * any part set on an unset stroke completes it (style solid, width the
 * first width stop, color left unset so it paints with currentColor),
 * and unset clears the whole stroke, never a part. The widget owns the
 * stroke colors for that reason: with a swatch well in the row, color
 * is the point, and currentColor survives as the unset state.
 *
 * Border is per side: the widget owns the three shorthand families AND
 * their twelve side longhands so a per-side edit can expand all three
 * families to longhands in one op and uniform sides can collapse back
 * to the shorthands (the padding invariant, three families wide), with
 * every write declared by the bound target's Border group. Outline has
 * no sides; its four properties ride the same group, and outline-offset
 * is owned so the offset row can follow the stroke.
 *
 * The plugin id stays `corners_border` (bindings and tombstones name
 * it, the elevation precedent); only the label says outline.
 */
#[ControlWidget(
  id: 'corners_border',
  label: new TranslatableMarkup('Corners, border and outline'),
  section: ControlSection::Surface,
  properties: [
    'border-radius',
    'border-width',
    'border-top-width',
    'border-right-width',
    'border-bottom-width',
    'border-left-width',
    'border-style',
    'border-top-style',
    'border-right-style',
    'border-bottom-style',
    'border-left-style',
    'border-color',
    'border-top-color',
    'border-right-color',
    'border-bottom-color',
    'border-left-color',
    'outline-width',
    'outline-style',
    'outline-color',
    'outline-offset',
  ],
)]
final class CornersBorder extends ControlWidgetBase {
}
