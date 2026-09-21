<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\daedalus\Migrate\ReportInterface;

/**
 * What the rename phase did, step by step, or where it stopped.
 */
final class RenameReport implements ReportInterface {

  /**
   * Constructs the report.
   *
   * @param array<string, array{line: string, items: list<string>}> $steps
   *   The steps that ran, in order: the step's label to its one-line count
   *   and the items it touched.
   * @param string|null $failedStep
   *   The label of the step that threw, or NULL when every step ran.
   * @param string|null $failure
   *   What the failing step threw, or NULL.
   */
  public function __construct(
    public readonly array $steps,
    public readonly ?string $failedStep = NULL,
    public readonly ?string $failure = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function ok(): bool {
    return $this->failure === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    if ($this->failure !== NULL) {
      return sprintf('rename: FAILED at %s: %s', $this->failedStep, str_replace("\n", ' ', $this->failure));
    }
    $lines = [];
    foreach ($this->steps as $label => $step) {
      $lines[] = "$label: {$step['line']}";
    }
    return 'rename: ' . implode('; ', $lines) . '.';
  }

  /**
   * {@inheritdoc}
   */
  public function render(): string {
    $lines = ["Rename: the old suite's vocabulary to daedalus's", ''];
    foreach ($this->steps as $label => $step) {
      $lines[] = "$label: {$step['line']}";
      foreach ($step['items'] as $item) {
        $lines[] = "  $item";
      }
    }
    if ($this->failure !== NULL) {
      $lines[] = '';
      $lines[] = sprintf('FAILED at %s. The steps above ran; the ones after it did not, and the phase is not recorded as done. Fix the cause and run it again: every step is idempotent.', $this->failedStep);
      $lines[] = $this->failure;
    }
    $lines[] = '';
    return implode("\n", $lines) . "\n";
  }

}
