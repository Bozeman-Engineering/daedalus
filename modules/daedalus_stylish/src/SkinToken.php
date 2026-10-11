<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * One token of a skin, resolved: the usage lane's handle on it.
 *
 * A token is not an entity (its record is a fact of the skin's tokens
 * map), so the name-keyed spines that address a material by entity for
 * swatches and animations (the delete batch, the usage counts) address a
 * token through this handle: the skin it lives on, its scale and name,
 * its record as worn (the merge of both layers) and the custom property
 * it delivers as, resolved once through the guide (TokenManager::
 * resolve()). A rename carries its target name too, so one batch shape
 * serves the rewrite-then-delete and the rewrite-then-rename jobs.
 *
 * The batch id (batchId()) is what the Batch API serializes between
 * requests: the skin's entity id (pinned, so a design system switch
 * mid-batch cannot redirect the job), the token's roster id and, for a
 * rename, the new name.
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
    $id = $this->skin->id() . ':' . $this->id();
    return $this->renameTo === NULL ? $id : $id . ':' . $this->renameTo;
  }

  /**
   * Splits a batch id into the skin's entity id, scale, name and rename target.
   *
   * @return array{0: int, 1: string, 2: string, 3: string|null}|null
   *   [skin id, scale, name, new name or NULL], or NULL when the id is
   *   not one batchId() produced.
   */
  public static function parseBatchId(string $id): ?array {
    $parts = explode(':', $id);
    if (count($parts) < 2 || count($parts) > 3 || !ctype_digit($parts[0])) {
      return NULL;
    }
    $address = TokenManager::parseId($parts[1]);
    if ($address === NULL) {
      return NULL;
    }
    return [(int) $parts[0], $address[0], $address[1], $parts[2] ?? NULL];
  }

}
