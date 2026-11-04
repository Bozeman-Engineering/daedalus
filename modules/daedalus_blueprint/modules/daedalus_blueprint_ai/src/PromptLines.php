<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsWeights;

/**
 * The page-language section's lines, each attributed as it is built.
 *
 * DialectCatalogPrompt composes its section through one of these: every
 * line is added under the record that put it there (its kind and id: a
 * material's own line), under a kind alone (id NULL: a line over several
 * records of that kind, a scale heading over its tokens, an axis heading
 * over the variants that are its values, a block heading that opens one
 * kind's lines alone) or under the system (the grammar, the doctrine,
 * the headings over mixed kinds, what no material toggle shrinks).
 * text() is the section the model is served, lines joined by a newline;
 * weights() is the same lines weighed (MaterialsWeights: a record's
 * lines its own, a kind's unowned lines its shared, the rest the
 * system's), each line counting its characters plus the newline that
 * follows it, the last line none, so the weights' total is exactly
 * strlen(text()). One build serves both: the gauge cannot drift from the
 * prompt because it is the prompt.
 */
final class PromptLines {

  /**
   * The lines in order, each with its kind and record id (NULL for none).
   *
   * @var list<array{string, \Drupal\daedalus_blueprint\Materials\MaterialKind|null, string|null}>
   */
  private array $lines = [];

  /**
   * Adds lines under a kind.
   *
   * A record's own (its id) or the kind's shared (NULL).
   */
  public function of(MaterialKind $kind, ?string $id, string ...$lines): static {
    foreach ($lines as $line) {
      $this->lines[] = [$line, $kind, $id];
    }
    return $this;
  }

  /**
   * Adds system lines: what no material toggle shrinks.
   */
  public function system(string ...$lines): static {
    foreach ($lines as $line) {
      $this->lines[] = [$line, NULL, NULL];
    }
    return $this;
  }

  /**
   * Appends another's lines, attribution kept.
   */
  public function append(PromptLines $other): static {
    array_push($this->lines, ...$other->lines);
    return $this;
  }

  /**
   * Whether no line was added.
   */
  public function isEmpty(): bool {
    return $this->lines === [];
  }

  /**
   * The line texts in order.
   *
   * @return string[]
   *   The lines.
   */
  public function texts(): array {
    return array_column($this->lines, 0);
  }

  /**
   * The kind each line is attributed to, in line order (NULL for the system).
   *
   * @return array<int, \Drupal\daedalus_blueprint\Materials\MaterialKind|null>
   *   The kinds, keyed like texts().
   */
  public function kinds(): array {
    return array_column($this->lines, 1);
  }

  /**
   * The record each line is attributed to, in line order.
   *
   * @return array<int, string|null>
   *   The record ids, keyed like texts(); NULL for a kind's shared line
   *   and for the system's.
   */
  public function ids(): array {
    return array_column($this->lines, 2);
  }

  /**
   * The section text: the lines joined by a newline.
   */
  public function text(): string {
    return implode("\n", $this->texts());
  }

  /**
   * The lines weighed: by record, a kind's shared apart, the system apart.
   *
   * A line weighs its characters plus the newline text() puts after it;
   * the last line has none, so the total is strlen(text()).
   */
  public function weights(): MaterialsWeights {
    $records = [];
    $shared = [];
    $system = 0;
    $last = count($this->lines) - 1;
    foreach ($this->lines as $i => [$line, $kind, $id]) {
      $weight = strlen($line) + ($i < $last ? 1 : 0);
      if ($kind === NULL) {
        $system += $weight;
      }
      elseif ($id === NULL) {
        $shared[$kind->value] = ($shared[$kind->value] ?? 0) + $weight;
      }
      else {
        $records[$kind->value][$id] = ($records[$kind->value][$id] ?? 0) + $weight;
      }
    }
    return new MaterialsWeights($records, $shared, $system);
  }

}
