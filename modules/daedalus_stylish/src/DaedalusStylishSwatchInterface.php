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
 * A swatch: a named, reusable CSS VALUE — the palette's unit.
 *
 * Swatches are the value-reuse spine's user half: skins mint curated tokens
 * per declared scale, swatches are minted by editors (permission-gated)
 * outside every scale, in the reserved namespace
 * --swatch-{machine_name} (StyleGuide::SWATCH_PREFIX). A swatch is
 * property-agnostic exactly like a token: the same gradient swatch works in
 * a div's background-image, a heading's text-clip recipe, a mask. Values
 * are LIVE links — editing a swatch restyles every element referencing it,
 * because it IS changing a custom property's value.
 *
 * The variant pattern at value scope: revisionable and publishable, so
 * workspace staging, revision reverts, and the disk lane all come free, and
 * ALL published swatches are live (no active pointer — the natural model
 * for a namespace, where names never compete). Kinds (color, gradient) are
 * plugins owning shape validation; the entity refuses to save under a kind
 * no plugin provides.
 *
 * Swatches deliberately stay OUTSIDE scale constraints: a {scale: color}
 * constrained property accepts only skin-minted tokens, so minting a swatch
 * never extends a scale-locked option set — strict-guide curation survives
 * user minting, and swatch references are allowed exactly where a property
 * permits free values.
 */
interface DaedalusStylishSwatchInterface extends ShippedSourceInterface, ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, MachineNameInterface, DesignSystemMemberInterface {

  /**
   * Returns the stable reference name.
   *
   * The name IS the custom property tail: the swatch delivers as
   * --swatch-{machine_name} (StyleGuide::swatchVariable(), the one
   * derivation rule), and style values reference it as
   * var(--swatch-{machine_name}). Auto-generated from the label when
   * omitted; an authored name must fit the CSS-safe shape
   * (DaedalusStylishSwatch::MACHINE_NAME) because it prints into the compiled
   * stylesheet.
   */
  public function getMachineName(): string;

  /**
   * Returns the swatch kind: a SwatchKind plugin id ("color", "gradient").
   *
   * Non-revisionable identity, like a variant's scope: the kind decides
   * where the swatch is OFFERED (color slots and gradient stops vs <image>
   * slots), so a swatch never changes what it is.
   */
  public function getKind(): string;

  /**
   * Returns the swatch's description: what it is for and when to reach for it.
   *
   * The swatch's AI-facing face (the variant's twin): served in the
   * design system brief beside the value, written as advice to a future
   * designer. '' when undescribed.
   */
  public function getDescription(): string;

  /**
   * Sets the swatch's description.
   */
  public function setDescription(string $description): static;

  /**
   * Whether the swatch is offered to the AI.
   *
   * TRUE by default; off, the roster's offered view (the brief and the
   * schema) omits it while var(--swatch-*) still validates and delivers
   * (MaterialRecord::$offered).
   */
  public function isOffered(): bool;

  /**
   * Sets whether the swatch is offered to the AI.
   */
  public function setOffered(bool $offered): static;

  /**
   * Returns the swatch's CSS value.
   *
   * @return string|array
   *   A plain CSS string, or a condition-keyed map with a 'default' entry
   *   whose conditions ride the width/scheme/motion axes
   *   (StyleMapValidator::TOKEN_CONDITION_AXES) — the same value grammar
   *   skin tokens speak, so a dark-mode gradient is data, not machinery.
   */
  public function getSwatchValue(): string|array;

  /**
   * Sets the swatch's CSS value.
   *
   * @param string|array $value
   *   The value; see getSwatchValue(). Write paths validate through
   *   StyleMapValidator::validateSwatchValue() before saving.
   */
  public function setSwatchValue(string|array $value): static;

  /**
   * Who minted the swatch: shipped, user or AI (MaterialRecord::ORIGIN_*).
   *
   * The mint gate's provenance, carried on the roster record: an AI mint
   * stays visible as one for the life of the site.
   */
  public function getOrigin(): string;

  /**
   * Stamps who minted the swatch (the lane's fact, set once at mint).
   */
  public function setOrigin(string $origin): static;

  /**
   * Returns the module that ships this swatch, or NULL.
   *
   * NULL means the swatch was minted in the UI or by AI: it has no disk
   * source, so no revert-to-defaults is ever offered for it.
   */
  public function getProvider(): ?string;

  /**
   * Returns the hash of the shipped data at the last import/sync, or NULL.
   */
  public function getSourceHash(): ?string;

  /**
   * Records the disk source this swatch was imported or synced from.
   *
   * @param string $provider
   *   The module whose MODULE.daedalus_stylish.swatches.yml ships the swatch.
   * @param array $shipped_data
   *   The shipped payload ({kind, value}); its hash becomes the stored
   *   source_hash both comparisons run against.
   */
  public function setShippedSource(string $provider, array $shipped_data): static;

  /**
   * Whether this swatch has a disk source (was shipped by a module).
   */
  public function isShipped(): bool;

  /**
   * Whether the swatch's data has diverged from the shipped defaults.
   */
  public function isModified(): bool;

  /**
   * Whether the shipped defaults changed since the last import/sync.
   *
   * @param array $shipped_data
   *   The payload as currently shipped on disk ({kind, value}).
   */
  public function hasNewDefaults(array $shipped_data): bool;

}
