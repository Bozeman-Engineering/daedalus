<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The basic-mode sections a control widget files under.
 *
 * Basic mode renders one header per non-empty section, in this roster's
 * weight order (Layout, Text, Surface, Motion; the panel opens on where
 * a thing sits and Motion is always last), with the controls in
 * declaration order beneath it; the per-widget default rides the
 * ControlWidget attribute and a controls: binding may override it with
 * a `section:` key. The roster is closed on purpose: a section is a
 * reading aid over ten-odd controls, and a fifth heading a theme mints
 * for one control would fragment the panel it exists to group. An
 * unknown name in YAML is a guide-build error
 * (StyleGuide::validateControlSection()), the same fate as an unknown
 * widget id.
 */
enum ControlSection: string {

  case Layout = 'layout';
  case Text = 'text';
  case Surface = 'surface';
  case Motion = 'motion';

  /**
   * The section's human label, the header basic mode renders.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Layout => new TranslatableMarkup('Layout'),
      self::Text => new TranslatableMarkup('Text'),
      self::Surface => new TranslatableMarkup('Surface'),
      self::Motion => new TranslatableMarkup('Motion'),
    };
  }

  /**
   * The section's place in the roster: lower renders first.
   */
  public function weight(): int {
    return match ($this) {
      self::Layout => 0,
      self::Text => 10,
      self::Surface => 20,
      self::Motion => 30,
    };
  }

  /**
   * The roster as the client payload ships it, in weight order.
   *
   * @return array<string, array{label: string, weight: int}>
   *   Section id => {label, weight}, labels cast to plain strings for
   *   JSON.
   */
  public static function roster(): array {
    $cases = self::cases();
    usort($cases, static fn (self $a, self $b) => $a->weight() <=> $b->weight());
    $roster = [];
    foreach ($cases as $case) {
      $roster[$case->value] = [
        'label' => (string) $case->label(),
        'weight' => $case->weight(),
      ];
    }
    return $roster;
  }

}
