<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * What a value in a slot IS: a material kind, an inline kind, or unknown.
 *
 * The answer the value grammar gives for any string a consumer holds in
 * a known slot. The material cases mirror MaterialKind by value (so
 * a reference's kind converts to the roster's kind without a table); the
 * four inline cases are the values a writer may spell without minting
 * anything (a literal CSS value, an existing media item by uuid, a
 * generation request, a file the conversation holds by its name); Unknown
 * is a reference-shaped value the roster
 * does not carry (a var() naming no material, a class no variant spells,
 * a stencil name nothing publishes), kept apart from Literal so a
 * consumer never lets an unrostered reference through as plain CSS.
 */
enum ValueKind: string {

  case Token = 'token';
  case Swatch = 'swatch';
  case Variant = 'variant';
  case Stencil = 'stencil';
  case Animation = 'animation';
  case Media = 'media';
  case Generate = 'generate';
  case File = 'file';
  case Literal = 'literal';
  case Unknown = 'unknown';

  /**
   * The value kind a material kind resolves as.
   */
  public static function ofMaterial(MaterialKind $kind): self {
    return self::from($kind->value);
  }

  /**
   * The material kind this value kind names, or NULL for inline/unknown.
   */
  public function material(): ?MaterialKind {
    return MaterialKind::tryFrom($this->value);
  }

  /**
   * Whether the value references a rostered material.
   */
  public function isMaterial(): bool {
    return $this->material() !== NULL;
  }

  /**
   * Whether the value is an inline value (spelled without a mint).
   */
  public function isInline(): bool {
    return in_array($this, [self::Media, self::Generate, self::File, self::Literal], TRUE);
  }

}
