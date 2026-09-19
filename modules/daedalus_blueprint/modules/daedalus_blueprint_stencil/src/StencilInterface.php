<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\media\MediaInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A user-saved stencil: a reusable composed subtree, saved as content.
 *
 * The content twin of a shipped stencil file: same label/description face,
 * same element grammar (the blueprint element subtree the instantiate
 * operation stamps), but persisted by site users instead of shipped by a
 * module. Stencils are instantiate-and-forget — placement stamps fresh
 * UUIDs and no back-reference — so editing a stencil only affects FUTURE
 * placements.
 *
 * Stencils are content, not config (the daedalus_variant precedent):
 * revisionable and publishable, which is exactly what makes them
 * workspace-aware, and a bad push-update is one revision revert away.
 *
 * A stencil is a member of a design system, or global
 * (DesignSystemMemberInterface): compositions belong to the design
 * system whose vocabulary they wear, so the roster offers a member only
 * while its system is active, and the variants a stencil owns carry its
 * membership.
 */
interface StencilInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, DesignSystemMemberInterface {

  /**
   * Returns the stable stencil ID.
   *
   * The machine name IS the stencil's ID in discovery: one flat, site-wide
   * namespace shared with file-shipped stencil IDs, where any collision is
   * a loud error, never a merge. Content ids are environment-local and
   * uuids are opaque, so references use this name.
   */
  public function getMachineName(): string;

  /**
   * Returns the palette description.
   */
  public function getDescription(): string;

  /**
   * Sets the palette description.
   */
  public function setDescription(string $description): static;

  /**
   * Whether the stencil is offered to the AI.
   *
   * TRUE (the default every material has) shares it: the roster's offered
   * view carries it, so the brief and the schema advertise it. FALSE keeps
   * it for the palette only; it still places and a placement still
   * validates, since legality never reads the flag.
   */
  public function isOffered(): bool;

  /**
   * Sets whether the stencil is offered to the AI.
   */
  public function setOffered(bool $offered): static;

  /**
   * Who minted the stencil: MaterialRecord::ORIGIN_USER or ORIGIN_AI.
   */
  public function getOrigin(): string;

  /**
   * Stamps who minted the stencil (the lane's fact, set once at save).
   */
  public function setOrigin(string $origin): static;

  /**
   * Returns the palette icon media, when one is set.
   *
   * The catalog serves it as `media:<uuid>` in the icon value grammar
   * (daedalus_blueprint IconResolver); NULL is the legitimate unset state,
   * for which consumers render their default stencil icon.
   */
  public function getIcon(): ?MediaInterface;

  /**
   * Sets or clears the palette icon media.
   */
  public function setIcon(?MediaInterface $media): static;

  /**
   * Returns the element subtree this stencil stamps.
   *
   * @return array
   *   The blueprint element subtree in the SAME grammar as a stencil
   *   file's element key (block_type, block_fields, layout sections with
   *   element-relative style keys and variant references; no UUIDs).
   */
  public function getElement(): array;

  /**
   * Sets the element subtree.
   *
   * @param array $element
   *   The blueprint element subtree; see getElement().
   */
  public function setElement(array $element): static;

}
