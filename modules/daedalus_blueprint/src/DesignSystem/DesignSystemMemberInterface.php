<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

/**
 * An entity that is a member of a design system, or global.
 *
 * Every member kind (daedalus_stylish's skins, variants, swatches and animations;
 * the stencil) carries one membership: the id of the design system it
 * belongs to, or NULL for a global member, one that belongs to no system
 * and is offered under every one. Membership is identity, not a
 * revisioned fact (a revert never moves a member between systems), and it
 * decides two things on the spine: whether the roster offers the member
 * (global, or the active system's: MaterialsRosterBuilder::build()) and,
 * for a styled member, which vocabulary its values validate against (its
 * own system's default skin, the active system's for a global one:
 * daedalus_stylish's StyleMapValidator design_system context).
 */
interface DesignSystemMemberInterface {

  /**
   * The id of the design system this member belongs to, or NULL (global).
   */
  public function getDesignSystem(): ?string;

  /**
   * Sets the membership: a design system id, or NULL for global.
   */
  public function setDesignSystem(?string $design_system): static;

}
