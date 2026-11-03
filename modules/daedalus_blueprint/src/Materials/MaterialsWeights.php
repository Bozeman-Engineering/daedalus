<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * Characters a renderer shows the AI, attributed by record, kind and system.
 *
 * The answer of one MaterialsWeightSourceInterface, and (summed) the
 * materials half of a BudgetReading. Three levels: a record's own
 * characters (its line on the brief, its entry in the schema), a kind's
 * SHARED characters (what a source renders over several records of one
 * kind and no one record owns: a scale heading over its tokens, an axis
 * heading over the variants that are its values, a kind's braces and
 * the commas between its entries) and the system figure, what no
 * material toggle shrinks (the guidance paragraph, the grammar, the
 * fixed sections). A kind's characters are its shared plus its records',
 * so a consumer segmenting by kind reads of(); a consumer listing the
 * records reads records() and shows the kind's shared once beside them,
 * and the two views sum to the same bar. Every kind is present, [] and
 * 0 when the source rendered none of it, so every shape is stable.
 */
final class MaterialsWeights {

  /**
   * Characters by record: MaterialKind value => record id => characters.
   *
   * Every kind present, [] when the source rendered no record of it.
   *
   * @var array<string, array<string, int>>
   */
  public readonly array $records;

  /**
   * A kind's characters no one record owns, keyed by MaterialKind value.
   *
   * Every kind present, 0 when the source rendered none of it.
   *
   * @var array<string, int>
   */
  public readonly array $shared;

  /**
   * Characters by kind (its shared plus its records'), every kind present.
   *
   * @var array<string, int>
   */
  public readonly array $characters;

  /**
   * Constructs the weights.
   *
   * @param array<string, array<string, int>> $records
   *   Characters by MaterialKind value then record id; a kind left out
   *   has no records.
   * @param array<string, int> $shared
   *   A kind's shared characters by MaterialKind value; a kind left out
   *   counts 0.
   * @param int $system
   *   The characters no material toggle shrinks.
   */
  public function __construct(
    array $records,
    array $shared,
    public readonly int $system,
  ) {
    $all_records = [];
    $all_shared = [];
    $characters = [];
    foreach (MaterialKind::cases() as $kind) {
      $all_records[$kind->value] = $records[$kind->value] ?? [];
      $all_shared[$kind->value] = $shared[$kind->value] ?? 0;
      $characters[$kind->value] = $all_shared[$kind->value] + array_sum($all_records[$kind->value]);
    }
    $this->records = $all_records;
    $this->shared = $all_shared;
    $this->characters = $characters;
  }

  /**
   * The characters of one kind: its shared plus its records'.
   */
  public function of(MaterialKind $kind): int {
    return $this->characters[$kind->value];
  }

  /**
   * The characters of one record; 0 when the source rendered none of it.
   */
  public function record(MaterialKind $kind, string $id): int {
    return $this->records[$kind->value][$id] ?? 0;
  }

  /**
   * Every kind's characters plus the system figure.
   */
  public function total(): int {
    return array_sum($this->characters) + $this->system;
  }

  /**
   * These weights plus another's: record by record, kind by kind, system.
   */
  public function add(MaterialsWeights $other): self {
    $records = [];
    $shared = [];
    foreach (MaterialKind::cases() as $kind) {
      $records[$kind->value] = $this->records[$kind->value];
      foreach ($other->records[$kind->value] as $id => $characters) {
        $records[$kind->value][$id] = ($records[$kind->value][$id] ?? 0) + $characters;
      }
      $shared[$kind->value] = $this->shared[$kind->value] + $other->shared[$kind->value];
    }
    return new self($records, $shared, $this->system + $other->system);
  }

}
