<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The rewrite-then-delete Batch API job behind swatch deletion.
 *
 * ValueDeleteBatchBase's var() twin, staged by SwatchManager::deleteSwatch()
 * for a REFERENCED swatch: each var(--swatch-{name}), fallback included,
 * becomes the swatch's literal value (SwatchUsage::rewriteValue()), in the
 * host map, variant styles, and the skin's tokens AND defaults.
 *
 * The literal is the swatch's DEFAULT value. A condition-keyed swatch
 * (dark-mode gradients) loses its conditioned variance in the rewrite:
 * the host's own condition blocks cannot be synthesized safely (the value
 * may sit inside a compound condition the swatch's axes would conflict
 * with), so the rewrite preserves the default-state look and the
 * conditioned nuance goes with the swatch, deliberately, and only behind
 * the "delete swatches in use" grade.
 */
final class SwatchDeleteBatch extends ValueDeleteBatchBase {

  /**
   * Batch operation: sweeps any late references, then deletes the swatch.
   */
  public static function deleteSwatch(int $swatch_id, array &$context): void {
    static::deleteMaterial($swatch_id, $context);
  }

  /**
   * {@inheritdoc}
   */
  protected static function entityTypeId(): string {
    return 'daedalus_swatch';
  }

  /**
   * {@inheritdoc}
   */
  protected static function usage(): ValueUsageBase {
    return \Drupal::service('daedalus_stylish.swatch_usage');
  }

  /**
   * {@inheritdoc}
   */
  protected static function rewriter(object $material): \Closure {
    assert($material instanceof DaedalusStylishSwatchInterface);
    $name = $material->getMachineName();
    $value = $material->getSwatchValue();
    $literal = is_array($value) ? (string) ($value['default'] ?? '') : (string) $value;
    return static fn (string $property, string $value): string => SwatchUsage::rewriteValue($value, $name, $literal);
  }

  /**
   * {@inheritdoc}
   *
   * A var() lives in a token value as it does anywhere: the shared
   * property-blind walk.
   */
  protected static function rewriteTokens(array $tokens, \Closure $rewrite, bool &$changed): array {
    return static::rewriteTokenValues($tokens, $rewrite, $changed);
  }

  /**
   * {@inheritdoc}
   */
  protected static function revisionLogMessage(string $name): string {
    return sprintf('Rewrote "%s" swatch references to the literal value before its deletion.', $name);
  }

  /**
   * {@inheritdoc}
   */
  protected static function failureMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Deleting the swatch failed; its references keep resolving it. Re-run the delete to finish.');
  }

  /**
   * {@inheritdoc}
   */
  protected static function successMessage(string $label, int $rewritten): TranslatableMarkup {
    return new TranslatableMarkup('Rewrote %label to its literal value in @count places and deleted it.', [
      '%label' => $label,
      '@count' => $rewritten,
    ]);
  }

}
