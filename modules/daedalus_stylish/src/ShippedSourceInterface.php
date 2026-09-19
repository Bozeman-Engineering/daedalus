<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * A content entity with a disk source: the shipped lane's identity facts.
 *
 * Variants, skins, swatches and animations all carry the "one hash, two
 * comparisons" identity ShippedSourceTrait implements: a provider module and
 * the hash of the payload that module shipped. The lane's two directions
 * (ShippedDefinitionsImporter brings files in, ShippedDefinitionsShipper
 * takes entities out) are written once over this interface, so every kind
 * with a disk source is adopted, stamped, and compared the same way. The
 * per-kind interfaces extend this one and document what their payload is.
 */
interface ShippedSourceInterface extends ContentEntityInterface, EntityPublishedInterface {

  /**
   * Returns the module that ships this entity, or NULL.
   *
   * NULL means the entity was created on the site (UI or AI): it has no
   * disk source, so no revert-to-defaults is ever offered for it.
   */
  public function getProvider(): ?string;

  /**
   * Returns the hash of the shipped payload at the last import/sync, or NULL.
   */
  public function getSourceHash(): ?string;

  /**
   * Records the disk source this entity was imported or synced from.
   *
   * @param string $provider
   *   The module whose shipped file carries the entity.
   * @param array $shipped_data
   *   The payload exactly as shipped; its hash becomes the stored
   *   source_hash both comparisons run against.
   */
  public function setShippedSource(string $provider, array $shipped_data): static;

  /**
   * Whether this entity has a disk source (was shipped by a module).
   */
  public function isShipped(): bool;

  /**
   * Whether the entity's payload has diverged from the shipped defaults.
   */
  public function isModified(): bool;

  /**
   * Whether the shipped defaults changed since the last import/sync.
   *
   * @param array $shipped_data
   *   The payload as currently shipped on disk.
   */
  public function hasNewDefaults(array $shipped_data): bool;

}
