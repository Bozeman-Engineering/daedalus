<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The rewrite-then-delete Batch API job behind animation deletion.
 *
 * ValueDeleteBatchBase's ident twin, staged by
 * AnimationManager::deleteAnimation() for a WORN animation: each
 * motion-{name} component, in animation-name or the animation shorthand,
 * becomes `none` (AnimationUsage::rewriteValue()), in the host map,
 * variant styles, and skin defaults (tokens carry no property, so no
 * keyframes name).
 *
 * `none` rather than a literal: a keyframes block has no literal form
 * inside a value, and inlining the steps elsewhere would be the
 * element-scoped-keyframes dead end. The wearer keeps its timing
 * declarations and animates nothing, which is exactly what its stale
 * reference would have rendered; only behind the "delete animations in
 * use" grade.
 */
final class AnimationDeleteBatch extends ValueDeleteBatchBase {

  /**
   * Batch operation: sweeps any late references, then deletes the animation.
   */
  public static function deleteAnimation(int $animation_id, array &$context): void {
    static::deleteMaterial($animation_id, $context);
  }

  /**
   * {@inheritdoc}
   */
  protected static function entityTypeId(): string {
    return 'daedalus_animation';
  }

  /**
   * {@inheritdoc}
   */
  protected static function usage(): ValueUsageBase {
    return \Drupal::service('daedalus_stylish.animation_usage');
  }

  /**
   * {@inheritdoc}
   */
  protected static function rewriter(object $material): \Closure {
    assert($material instanceof DaedalusStylishAnimationInterface);
    $name = $material->getMachineName();
    return static fn (string $property, string $value): string => AnimationUsage::rewriteValue($property, $value, $name);
  }

  /**
   * {@inheritdoc}
   */
  protected static function revisionLogMessage(string $name): string {
    return sprintf('Rewrote "%s" animation references to "none" before its deletion.', $name);
  }

  /**
   * {@inheritdoc}
   */
  protected static function failureMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Deleting the animation failed; its references keep naming it. Re-run the delete to finish.');
  }

  /**
   * {@inheritdoc}
   */
  protected static function successMessage(string $label, int $rewritten): TranslatableMarkup {
    return new TranslatableMarkup('Rewrote %label references to "none" in @count places and deleted it.', [
      '%label' => $label,
      '@count' => $rewritten,
    ]);
  }

}
