<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\daedalus\Migrate\ReportInterface;

/**
 * What the content phase did: components rewritten per host type, and drops.
 */
final class ContentReport implements ReportInterface {

  /**
   * Constructs the report.
   *
   * @param array<string, array{hosts: int, saved: int, components: int, items: list<string>}> $hosts
   *   Host entity type id to the hosts examined (those with a layout), the
   *   hosts re-saved, the components rewritten and one line per re-saved
   *   host.
   * @param array<string, array<string, int>> $dropped
   *   Old configuration key to value to the number of components that
   *   carried it and lost it.
   * @param list<string> $left
   *   The `<entity type>.<field>` layout fields the phase leaves alone.
   * @param string|null $refusal
   *   Why the phase cannot run, or NULL.
   * @param string|null $failure
   *   What stopped the walk, or NULL when it completed.
   */
  public function __construct(
    public readonly array $hosts = [],
    public readonly array $dropped = [],
    public readonly array $left = [],
    public readonly ?string $refusal = NULL,
    public readonly ?string $failure = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function ok(): bool {
    return $this->refusal === NULL && $this->failure === NULL;
  }

  /**
   * The components rewritten over every host type.
   */
  public function components(): int {
    return array_sum(array_column($this->hosts, 'components'));
  }

  /**
   * The hosts re-saved over every host type.
   */
  public function saved(): int {
    return array_sum(array_column($this->hosts, 'saved'));
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    if ($this->refusal !== NULL) {
      return 'content: REFUSED. ' . str_replace("\n", ' ', $this->refusal);
    }
    $hosts = $this->hosts
      ? implode(', ', array_map(static fn(string $type, array $entry): string => sprintf('%d of %d %s', $entry['saved'], $entry['hosts'], $type), array_keys($this->hosts), $this->hosts))
      : 'no layout host';
    $line = sprintf('content: %d heading components became text blocks on %s hosts', $this->components(), $hosts);
    if ($this->dropped) {
      $line .= '; dropped ' . implode(', ', array_map(fn(string $key): string => $this->droppedLine($key), array_keys($this->dropped)));
    }
    if ($this->left) {
      $line .= '; left alone: ' . implode(', ', $this->left);
    }
    if ($this->failure !== NULL) {
      return $line . '; FAILED: ' . str_replace("\n", ' ', $this->failure);
    }
    return $line . '.';
  }

  /**
   * {@inheritdoc}
   */
  public function render(): string {
    $lines = ['Content: old heading components to text blocks', ''];
    if ($this->refusal !== NULL) {
      $lines[] = 'REFUSED: ' . $this->refusal;
      $lines[] = '';
      return implode("\n", $lines) . "\n";
    }
    $lines[] = 'Hosts (the default revisions carrying a layout):';
    foreach ($this->hosts as $type => $entry) {
      $lines[] = sprintf('  %s: %d components rewritten on %d of %d hosts', $type, $entry['components'], $entry['saved'], $entry['hosts']);
      foreach ($entry['items'] as $item) {
        $lines[] = "    $item";
      }
    }
    $lines[] = $this->hosts ? '' : '  none';

    $lines[] = 'Dropped (no field on the text block; re-author where it matters):';
    foreach (array_keys($this->dropped) as $key) {
      $lines[] = '  ' . $this->droppedLine($key);
    }
    $lines[] = $this->dropped ? '' : '  none';

    $lines[] = 'Layout fields left alone (not the overrides field the inline-block machinery persists; the closing audit counts what they hold):';
    foreach ($this->left as $field) {
      $lines[] = "  $field";
    }
    $lines[] = $this->left ? '' : '  none';

    if ($this->failure !== NULL) {
      $lines[] = 'FAILED. The hosts above were re-saved; the walk stopped and the phase is not recorded as done. Fix the cause and run it again: a rewritten component is never rewritten twice.';
      $lines[] = $this->failure;
      $lines[] = '';
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * One dropped key with its total and the count per value.
   */
  private function droppedLine(string $key): string {
    $values = $this->dropped[$key];
    return sprintf('%s %d (%s)', $key, array_sum($values), implode(', ', array_map(static fn(string $value, int $count): string => "$value $count", array_keys($values), $values)));
  }

}
