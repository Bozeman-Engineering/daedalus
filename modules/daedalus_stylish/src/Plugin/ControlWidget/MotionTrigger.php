<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ControlWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ControlWidget;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\ControlWidgetBase;

/**
 * The WHEN of the selection's animation: the trigger pair.
 *
 * Two standard text-shape rows over the Motion group's two Animation
 * Triggers shorthands: Start over timeline-trigger (how far into view
 * the element must scroll: on load, when visible, half, fully) and Play
 * over animation-trigger (once, every time, forwards and back, off). The
 * built-in stops are the fallback vocabulary (widget-values.js
 * FALLBACKS), finer ranges are typed in advanced or code. The two
 * properties are one binding (each stop names the one `--in-view`
 * trigger), so the widget completes or clears the pair as one gesture
 * where a lone write would leave the binding half-formed. A Replay
 * action re-arms the trigger on the canvas through the motion-triggers
 * runtime. No dynamic gate: sanction (the motion grant) alone decides
 * where the control appears.
 */
#[ControlWidget(
  id: 'motion_trigger',
  label: new TranslatableMarkup('Motion trigger'),
  section: ControlSection::Motion,
  properties: ['timeline-trigger', 'animation-trigger'],
)]
final class MotionTrigger extends ControlWidgetBase {
}
