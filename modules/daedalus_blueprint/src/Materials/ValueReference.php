<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * One resolved value: what it is, which one, and how it is spelled.
 *
 * The value grammar's answer for a string in a slot. Three facts every
 * consumer used to re-derive by sniffing, held once:
 * - kind: a ValueKind (material, inline, or unknown).
 * - id: the kind-local identity. A material's record id ("lime_green",
 *   "color/accent", "container_full", "hero"); a media reference's uuid;
 *   a generation request's prompt; for Unknown the reference as the
 *   writer named it (the custom property "--swatch-limegreen", the class
 *   token, the attribute value, the blueprint name), so a corrective can
 *   search for what was meant; NULL for a literal, which is only its
 *   spelling.
 * - spelling: the reference as it is written in its slot, canonical
 *   ("var(--swatch-lime_green)", "url('media:<uuid>')", "full"); for a
 *   literal the value itself, trimmed.
 * - record: the roster record for a material kind (so a consumer that
 *   resolved a value never looks it up again); NULL otherwise.
 *
 * Immutable and shape-locked with the JS twin: toArray() is what the
 * twin returns, and the equivalence test compares exactly that.
 */
final class ValueReference {

  public function __construct(
    public readonly ValueKind $kind,
    public readonly ?string $id,
    public readonly string $spelling,
    public readonly ?MaterialRecord $record = NULL,
  ) {
    if ($kind->isMaterial()) {
      if ($record === NULL || $record->kind->value !== $kind->value || $record->id !== $id) {
        throw new \InvalidArgumentException(sprintf('A %s reference must carry the %s record it resolved to.', $kind->value, $kind->value));
      }
    }
    elseif ($record !== NULL) {
      throw new \InvalidArgumentException(sprintf('A %s reference carries no material record.', $kind->value));
    }
    if ($kind === ValueKind::Literal ? $id !== NULL : $id === NULL) {
      throw new \InvalidArgumentException($kind === ValueKind::Literal ? 'A literal has no id; it is its spelling.' : sprintf('A %s reference needs an id.', $kind->value));
    }
  }

  /**
   * A literal: the value is only what it says.
   */
  public static function literal(string $spelling): self {
    return new self(ValueKind::Literal, NULL, $spelling);
  }

  /**
   * Whether the value references a rostered material.
   */
  public function isMaterial(): bool {
    return $this->kind->isMaterial();
  }

  /**
   * The reference as the JS twin returns it: kind, id, spelling.
   */
  public function toArray(): array {
    return [
      'kind' => $this->kind->value,
      'id' => $this->id,
      'spelling' => $this->spelling,
    ];
  }

}
