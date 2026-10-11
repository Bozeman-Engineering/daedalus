<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The kinds of material a site's roster can carry.
 *
 * A material is a named, reusable thing a style or markup value can
 * reference: a skin token, a palette swatch, a saved variant, a blueprint,
 * a named keyframes animation, a self-hosted font family. Anything else
 * a value can be (a literal, a
 * media reference, a generation request) is an inline value, not a
 * material, and never appears in the roster. The cases are closed on
 * purpose: a new kind is a new provider AND a new case, so every
 * kind-dispatched projection (prompt section, schema group, delivery
 * payload, gesture family) grows by data instead of by a hand-wired
 * branch nobody remembers to add. A new case lands LAST: roster order and
 * the mutation delta's order follow cases(), and their consumers pin it.
 */
enum MaterialKind: string {

  case Token = 'token';
  case Swatch = 'swatch';
  case Variant = 'variant';
  case Blueprint = 'blueprint';
  case Animation = 'animation';
  case Font = 'font';

  /**
   * The singular noun prose uses for this kind ("no variant named x").
   */
  public function noun(): string {
    return $this->value;
  }

  /**
   * The plural noun prose uses for this kind ("the site's swatches").
   */
  public function plural(): string {
    return match ($this) {
      self::Token => 'tokens',
      self::Swatch => 'swatches',
      self::Variant => 'variants',
      self::Blueprint => 'blueprints',
      self::Animation => 'animations',
      self::Font => 'fonts',
    };
  }

}
