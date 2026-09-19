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
 * A style variant: a named, reusable style set.
 *
 * A variant is a full condition-nested style block saved from one element
 * and applicable to other elements of the same scope. Applied, it is
 * a REFERENCE in the host entity's style map (one item in the entry's
 * "variants" list, at most one per axis); the entry's own properties layer
 * over it as overrides, detaching is an explicit gesture (detach_variant),
 * and pushing an element's styles back into the variant ("update variant")
 * is the one deliberate propagation gesture.
 *
 * Variants are content, not config: shipped defaults and user-saved
 * variants are the same entity type, and the reversibility nets are content
 * nets — a bad push is one REVISION revert away, and on workspace sites a
 * push stages workspace-wide and publishes with the workspace.
 */
interface DaedalusStylishVariantInterface extends ShippedSourceInterface, ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, MachineNameInterface, DesignSystemMemberInterface {

  /**
   * Returns the stable reference name.
   *
   * Style maps ({"variants": [...]}) and style-guide YAML (default_variants)
   * reference variants by this name — content ids are environment-local and
   * uuids are opaque to YAML authors and AI alike.
   */
  public function getMachineName(): string;

  /**
   * Returns the variant's description: when to reach for this look.
   *
   * The AI-facing payload of the style guide's variants roster (the schema's
   * materials.variants): what teaches a model (or a colleague) when this
   * variant expresses the intent. Empty when the variant has none; the roster
   * then serves identity only.
   */
  public function getDescription(): string;

  /**
   * Sets the description.
   */
  public function setDescription(string $description): static;

  /**
   * Whether the variant is offered to the AI.
   *
   * TRUE by default: every material is shared with the AI unless its
   * author opts it out for UI-only use. Off, the roster's offered view
   * (what the brief and the schema project) omits it, so the description
   * has no audience; legality never reads it, the variant still applies
   * wherever its scope fits (MaterialRecord::$offered).
   */
  public function isOffered(): bool;

  /**
   * Sets whether the variant is offered to the AI.
   */
  public function setOffered(bool $offered): static;

  /**
   * Returns the variant's sort weight: rosters order weight-then-label.
   *
   * Ordering is a real fact about a variant family (the width scale reads
   * widest-first, not alphabetically), so it lives on the entity where
   * user-minted variants participate and the importer can ship it. Default
   * 0 keeps unweighted variants alphabetical among themselves.
   */
  public function getWeight(): int;

  /**
   * Sets the sort weight.
   */
  public function setWeight(int $weight): static;

  /**
   * Returns the scope the variant was saved under: its address, printed.
   *
   * A root styling address (daedalus_blueprint's Address, the one grammar):
   * "block_type:{bundle}" for variants saved from a block,
   * "block_plugin:{plugin_id}" from a plugin placement,
   * "layout:{layout_id}" for variants saved from a section,
   * "kind:{entity|container|field|block}" for elements the universal
   * style-guide YAML speaks to, or "base:{tag}" for a bare-markup tag
   * treatment (an anchor variant is a link style). A variant is
   * applicable exactly where the target element's own address pairs
   * (Address::pairsWith(); captured by StyleMapValidator::captureScope());
   * base-scoped variants pair with every element and are offered by
   * rendered tag (soft enforcement).
   */
  public function getScope(): string;

  /**
   * Sets the scope.
   */
  public function setScope(string $scope): static;

  /**
   * Returns the variant axis this variant competes on within its scope.
   *
   * An element wears at most one variant per axis (applying another
   * replaces it) and composes variants across axes. Every scope has the
   * built-in "style" axis (StyleGuide::STYLE_AXIS), the default for
   * variants saved without an explicit axis; a scope's style guide
   * declaration may add more under variant_axes. Saving validates the axis
   * against the scope's vocabulary (StyleMapValidator::scopeAxes()).
   */
  public function getAxis(): string;

  /**
   * Sets the axis.
   */
  public function setAxis(string $axis): static;

  /**
   * Returns the variant's style data.
   *
   * @return array
   *   Element-RELATIVE keys mapped to style entries (condition-keyed
   *   property maps — the same entry shape the style map stores). The root
   *   element's entry lives under the empty
   *   string key; sub-elements under their "::.class-name" suffix. The
   *   relative form makes detach a pure concatenation: applied to element
   *   key E, relative key K's entry lands at style map key E . K.
   */
  public function getStyles(): array;

  /**
   * Sets the variant's style data.
   *
   * @param array $styles
   *   Element-relative keys mapped to style entries; see getStyles().
   */
  public function setStyles(array $styles): static;

  /**
   * Who minted the variant: shipped, user or AI (MaterialRecord::ORIGIN_*).
   *
   * The mint gate's provenance, carried on the roster record: an AI mint
   * stays visible as one for the life of the site.
   */
  public function getOrigin(): string;

  /**
   * Stamps who minted the variant (the lane's fact, set once at mint).
   */
  public function setOrigin(string $origin): static;

  /**
   * Returns the module that ships this variant, or NULL.
   *
   * NULL means the variant was created in the UI or by AI: it has no disk
   * source, so no revert-to-defaults is ever offered for it.
   */
  public function getProvider(): ?string;

  /**
   * Returns the hash of the shipped styles at the last import/sync, or NULL.
   */
  public function getSourceHash(): ?string;

  /**
   * Records the disk source this variant was imported or synced from.
   *
   * @param string $provider
   *   The module whose MODULE.daedalus_stylish.variants.yml ships the variant.
   * @param array $shipped_styles
   *   The styles exactly as shipped; their hash becomes the stored
   *   source_hash both comparisons run against.
   */
  public function setShippedSource(string $provider, array $shipped_styles): static;

  /**
   * Whether this variant has a disk source (was shipped by a module).
   */
  public function isShipped(): bool;

  /**
   * Whether the variant's styles have diverged from the shipped defaults.
   *
   * Comparison 1 of 2: current entity styles vs the stored source hash.
   * FALSE for variants without a disk source. A modified variant keeps the
   * user's styles on sync; the UI offers revert instead ("the revert button
   * IS the ask").
   */
  public function isModified(): bool;

  /**
   * Whether the shipped defaults have changed since the last import/sync.
   *
   * Comparison 2 of 2: the current disk styles vs the stored source hash.
   * FALSE for variants without a disk source.
   *
   * @param array $shipped_styles
   *   The styles as currently shipped on disk.
   */
  public function hasNewDefaults(array $shipped_styles): bool;

  /**
   * Hashes a styles map, content-canonically (key order never matters).
   *
   * The one hash function every shipped-variant comparison shares: the
   * importer stores it, isModified() and hasNewDefaults() compare against it.
   */
  public static function hashStyles(array $styles): string;

}
