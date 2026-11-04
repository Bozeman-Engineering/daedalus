<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * One token of a skin, resolved: the usage lane's handle on it.
 *
 * A token is not an entity (its record is a fact of the skin's tokens map), so
 * the name-keyed spines that address a material by entity (the delete batch,
 * the usage counts) address a token through this handle: the skin it lives on,
 * its scale and name, its record as worn (the merge of both layers) and the
 * custom property it delivers as, resolved once through the guide
 * (SkinMaterialManager::resolve()). A rename carries its target name too, so
 * one batch shape serves the rewrite-then-delete and the rewrite-then-rename
 * jobs. SkinAnimation is its twin for the skin's motion section.
 *
 * The batch id (batchId()) is what the Batch API serializes between
 * requests: the skin's entity id (pinned, so a design system switch
 * mid-batch cannot redirect the job), the section, the token's roster id
 * and, for a rename, the new name (SkinMaterialDeleteBatch::parseBatchId()
 * reads it back).
 */
final class SkinToken {

  public function __construct(
    public readonly DaedalusStylishSkinInterface $skin,
    public readonly string $scale,
    public readonly string $name,
    public readonly array $record,
    public readonly string $variable,
    public readonly ?string $renameTo = NULL,
  ) {}

  /**
   * The token's roster id: "{scale}/{name}".
   */
  public function id(): string {
    return $this->scale . '/' . $this->name;
  }

  /**
   * The token's literal value: the default entry of a conditioned value.
   *
   * What a reference becomes when the token is deleted from under it. A
   * condition-keyed token loses its conditioned variance in the rewrite:
   * the host's own condition blocks cannot be synthesized safely (the
   * value may sit inside a compound condition the token's axes would
   * conflict with), so the rewrite preserves the default-state look and
   * the conditioned nuance goes with the token, deliberately, and only
   * behind the "delete tokens in use" grade.
   */
  public function literal(): string {
    $value = $this->record['value'] ?? '';
    return is_array($value) ? (string) ($value['default'] ?? '') : (string) $value;
  }

  /**
   * The Batch API's serialized address of this token.
   */
  public function batchId(): string {
    $id = $this->skin->id() . ':' . SkinMaterialManager::TOKENS . ':' . $this->id();
    return $this->renameTo === NULL ? $id : $id . ':' . $this->renameTo;
  }

}
