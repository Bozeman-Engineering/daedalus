<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Entity;

/**
 * The "one hash, two comparisons" disk-lane identity for shipped content.
 *
 * Variants established the pattern and skins reuse it: a module ships
 * defaults as a YAML file, the importer stamps provider + source_hash, and
 * the single stored hash answers both sync questions — did the user modify
 * the entity (isModified()), and did the disk defaults change
 * (hasNewDefaults())? The fields are non-revisionable identity: the disk
 * relationship belongs to the entity, not to any one revision, so reverting
 * a revision never resurrects a stale shipped-source state.
 */
trait ShippedSourceTrait {

  /**
   * The entity's current shipped-lane payload.
   *
   * The data the disk file ships and the hash comparisons run over: styles
   * for variants, tokens for skins.
   */
  abstract protected function shippedData(): array;

  /**
   * Returns the module that ships this entity, or NULL.
   */
  public function getProvider(): ?string {
    $value = $this->get('provider')->value;
    return $value === NULL || $value === '' ? NULL : (string) $value;
  }

  /**
   * Returns the hash of the shipped data at the last import/sync, or NULL.
   */
  public function getSourceHash(): ?string {
    $value = $this->get('source_hash')->value;
    return $value === NULL || $value === '' ? NULL : (string) $value;
  }

  /**
   * Records the disk source this entity was imported or synced from.
   */
  public function setShippedSource(string $provider, array $shipped_data): static {
    $this->set('provider', $provider);
    $this->set('source_hash', static::hashData($shipped_data));
    return $this;
  }

  /**
   * Whether this entity has a disk source (was shipped by a module).
   */
  public function isShipped(): bool {
    return $this->getProvider() !== NULL;
  }

  /**
   * Whether the entity's data has diverged from the shipped defaults.
   */
  public function isModified(): bool {
    $hash = $this->getSourceHash();
    return $hash !== NULL && static::hashData($this->shippedData()) !== $hash;
  }

  /**
   * Whether the shipped defaults changed since the last import/sync.
   */
  public function hasNewDefaults(array $shipped_data): bool {
    $hash = $this->getSourceHash();
    return $hash !== NULL && static::hashData($shipped_data) !== $hash;
  }

  /**
   * Hashes a data map, content-canonically (key order never matters).
   *
   * The one hash function every shipped-source comparison shares: the
   * importer stores it, isModified() and hasNewDefaults() compare against
   * it.
   */
  public static function hashData(array $data): string {
    return hash('sha256', json_encode(static::canonicalizeData($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Sorts a data map recursively by key.
   *
   * The hash is over CONTENT: reordering keys in the YAML (or the UI writing
   * the same properties in a different order) must never read as a change,
   * or unmodified entities would stop auto-syncing and revert buttons would
   * appear for no-op differences.
   */
  protected static function canonicalizeData(array $data): array {
    ksort($data);
    foreach ($data as &$value) {
      if (is_array($value)) {
        $value = static::canonicalizeData($value);
      }
    }
    return $data;
  }

}
