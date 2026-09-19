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
 * An animation: a named, site-scoped @keyframes block, the motion material.
 *
 * The materials spine's fifth kind (MaterialKind::Animation). Where a swatch
 * names a VALUE and delivers as a custom property, an animation names a
 * STRUCTURE (an offset-keyed map of declarations) and delivers as an
 * at-rule: `@keyframes motion-{machine_name} { 0% {…} 100% {…} }`. Style
 * values reference it by the prefixed ident StyleGuide::animationName()
 * spells (`animation-name: motion-fade_in`, or a name component of the
 * `animation` shorthand), never through var(): a keyframes name is a
 * custom-ident, and the prefix is what partitions site animations from a
 * theme's own keyframes and from every animation keyword.
 *
 * The swatch pattern otherwise: revisionable and publishable, so workspace
 * staging, revision reverts, and the disk lane come free; ALL published
 * animations are live (a namespace, names never compete); five-grade
 * permissions with the site-wide blast radius (editing steps restyles
 * every wearer). Steps are validated through
 * StyleMapValidator::validateAnimationSteps() on every write path.
 */
interface DaedalusStylishAnimationInterface extends ShippedSourceInterface, ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, MachineNameInterface, DesignSystemMemberInterface {

  /**
   * Returns the stable reference name.
   *
   * The name IS the keyframes ident tail: the animation delivers as
   * `@keyframes motion-{machine_name}` (StyleGuide::animationName(), the
   * one derivation rule) and style values reference that spelling.
   * Auto-generated from the label when omitted; an authored name must fit
   * the CSS-safe shape (DaedalusStylishAnimation::MACHINE_NAME) because it prints
   * into the compiled stylesheet.
   */
  public function getMachineName(): string;

  /**
   * Returns the AI-facing description: when to reach for this motion.
   */
  public function getDescription(): string;

  /**
   * Sets the description.
   */
  public function setDescription(string $description): static;

  /**
   * Whether the animation is offered to the AI.
   *
   * TRUE by default; off, the roster's offered view (the brief and the
   * schema) omits it while motion-{name} still validates and compiles
   * (MaterialRecord::$offered).
   */
  public function isOffered(): bool;

  /**
   * Sets whether the animation is offered to the AI.
   */
  public function setOffered(bool $offered): static;

  /**
   * Returns the keyframe steps: canonical percentage offsets to declarations.
   *
   * @return array<string, array<string, string>>
   *   Offsets ("0%", "50%", "100%") in ascending order, each mapping CSS
   *   property names to values. Offsets are stored canonical: the setter
   *   folds `from`/`to` into `0%`/`100%` and sorts, so every reader (the
   *   printer, the roster record's value, the editor) sees one shape.
   */
  public function getSteps(): array;

  /**
   * Sets the keyframe steps.
   *
   * @param array $steps
   *   Offsets (`from`, `to`, or `N%`) mapping property names to values.
   *   Write paths validate through
   *   StyleMapValidator::validateAnimationSteps() before saving; the setter
   *   canonicalizes offsets (AnimationStepsItem::canonicalizeSteps()).
   */
  public function setSteps(array $steps): static;

  /**
   * Who minted the animation: shipped, user or AI (MaterialRecord::ORIGIN_*).
   */
  public function getOrigin(): string;

  /**
   * Stamps who minted the animation (the lane's fact, set once at mint).
   */
  public function setOrigin(string $origin): static;

  /**
   * Returns the module that ships this animation, or NULL.
   *
   * NULL means the animation was minted in the UI or by AI: it has no disk
   * source, so no revert-to-defaults is ever offered for it.
   */
  public function getProvider(): ?string;

  /**
   * Returns the hash of the shipped data at the last import/sync, or NULL.
   */
  public function getSourceHash(): ?string;

  /**
   * Records the disk source this animation was imported or synced from.
   *
   * @param string $provider
   *   The module whose MODULE.daedalus_stylish.animations.yml ships the animation.
   * @param array $shipped_data
   *   The shipped payload the hash pins: the steps map, exactly what
   *   DaedalusStylishAnimation::shippedData() returns (the variant precedent: the
   *   structure is the shipped fact; label and description are metadata).
   */
  public function setShippedSource(string $provider, array $shipped_data): static;

  /**
   * Whether this animation has a disk source (was shipped by a module).
   */
  public function isShipped(): bool;

  /**
   * Whether the animation's steps have diverged from the shipped defaults.
   */
  public function isModified(): bool;

  /**
   * Whether the shipped defaults changed since the last import/sync.
   *
   * @param array $shipped_data
   *   The steps as currently shipped on disk.
   */
  public function hasNewDefaults(array $shipped_data): bool;

}
