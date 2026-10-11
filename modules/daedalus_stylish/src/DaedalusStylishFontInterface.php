<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\daedalus_stylish\Entity\MachineNameInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A font: a self-hosted family, one @font-face block per face.
 *
 * The materials spine's sixth kind (MaterialKind::Font), on the animation
 * pattern: where an animation names a STRUCTURE (keyframe steps) and
 * delivers as a named at-rule, a font names a FAMILY and delivers as one
 * `@font-face` at-rule per face (weight, style, source), every face
 * declaring `font-display: swap`. Style values reference the family by its
 * quoted name in font-family or the font shorthand
 * (StyleGuide::fontFamily(), the one derivation rule), never through
 * var(): a family name is a string component of a font stack.
 *
 * A face's source is a TYPED map, `{file: uuid}` today: the managed file
 * the daedalus_ui file lane admitted (FontFiles, woff2 and woff only). A
 * hosted source is a later kind beside `file`, an addition and not a
 * reshape, which is why the source is never a bare file field.
 *
 * The swatch pattern otherwise: revisionable and publishable, so workspace
 * staging, revision reverts and the disk lane come free; ALL published
 * fonts are live (a namespace, families never compete); five-grade
 * permissions with the site-wide blast radius (editing a face restyles
 * every element set in the family). Faces validate through
 * FontValidator on every write path.
 */
interface DaedalusStylishFontInterface extends ShippedSourceInterface, ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, MachineNameInterface, DesignSystemMemberInterface {

  /**
   * Returns the stable reference name (the roster id, the disk file's key).
   *
   * Auto-generated from the label when omitted; an authored name must fit
   * the CSS-safe shape (DaedalusStylishFont::MACHINE_NAME).
   */
  public function getMachineName(): string;

  /**
   * Returns the family name a font-family value references ("Inter").
   *
   * Stored bare; StyleGuide::fontFamily() spells it quoted for the
   * compiled at-rules and the roster record's spelling.
   */
  public function getFamily(): string;

  /**
   * Sets the family name.
   */
  public function setFamily(string $family): static;

  /**
   * Returns the AI-facing description: when to reach for this family.
   */
  public function getDescription(): string;

  /**
   * Sets the description.
   */
  public function setDescription(string $description): static;

  /**
   * Whether the font is offered to the AI (MaterialRecord::$offered).
   */
  public function isOffered(): bool;

  /**
   * Sets whether the font is offered to the AI.
   */
  public function setOffered(bool $offered): static;

  /**
   * Returns the faces: canonical, ordered by weight then style.
   *
   * @return array<int, array{weight: string, style: string, source: array}>
   *   One face per weight and style: weight a canonical number ("400") or
   *   range ("100 900", a variable font), style normal, italic or oblique,
   *   source the typed map ({file: uuid}). Stored canonical
   *   (FontFacesItem::canonicalizeFaces()), so every reader (the printer,
   *   the roster record's value, the Fonts tab) sees one shape.
   */
  public function getFaces(): array;

  /**
   * Sets the faces.
   *
   * @param array $faces
   *   A list of faces ({weight?, style?, source: {file: uuid}}). Write
   *   paths validate through FontValidator::validateFaces() before saving;
   *   the setter canonicalizes (weights, styles, order, defaults).
   */
  public function setFaces(array $faces): static;

  /**
   * The distinct managed file uuids the faces reference, in face order.
   *
   * @return string[]
   *   The uuids.
   */
  public function fileUuids(): array;

  /**
   * Who minted the font: shipped, user or AI (MaterialRecord::ORIGIN_*).
   */
  public function getOrigin(): string;

  /**
   * Stamps who minted the font (the lane's fact, set once at mint).
   */
  public function setOrigin(string $origin): static;

}
