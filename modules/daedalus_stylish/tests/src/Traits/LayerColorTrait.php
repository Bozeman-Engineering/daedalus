<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Traits;

/**
 * Spells layers.js layerColor() in PHP for browser assertions.
 *
 * The identity system's one hue rule for a worn variant's layer (djb2
 * over the layer id to a hue, shifted off the element blue's band),
 * spelled here so a row's `--daedalus-stylish-origin`, a mark's hue or
 * a wear bar dot is asserted against the FUNCTION every identity
 * surface shares (the style stack's marks, the wear bar, the code
 * view's run labels), never against a color copied from one run.
 */
trait LayerColorTrait {

  /**
   * A worn variant layer's stable hue: layers.js layerColor(variantLayerId()).
   *
   * @param string $reference
   *   The variant's record id (machine name).
   *
   * @return string
   *   The CSS color layerColor() returns for that variant's layer.
   */
  protected static function layerColor(string $reference): string {
    $hash = 5381;
    foreach (str_split("variant:$reference") as $char) {
      $hash = (($hash << 5) + $hash + ord($char)) & 0xFFFFFFFF;
    }
    $hue = $hash % 360;
    if (abs($hue - 213) < 24) {
      $hue = ($hue + 48) % 360;
    }
    return "hsl($hue 70% 60%)";
  }

}
